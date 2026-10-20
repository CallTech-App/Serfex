//go:build realhost

// This test serves a real site with Nginx and PHP-FPM, sends it visitors,
// crawlers and broken links from several addresses, and checks what the
// monitor hands the statistics: requests, bandwidth, visitors, pages,
// referrers, countries (from a registry file served locally) and bots.
// It also measures the site's folder for the disk breakdown and keeps
// uncollected counts over a restart. It needs root, and changes the
// machine, so it only builds with -tags realhost:
//
//	sudo go test -tags realhost -run TestRealHost ./internal/stats
package stats_test

import (
	"context"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/monitor"
	"github.com/winchester37/servik/agent/internal/site"
	"github.com/winchester37/servik/agent/internal/stats"
)

const (
	domain = "istatistik-test.example"
	user   = "istatt"
	server = "192.0.2.70" // added to lo for the test
)

// visitors come from two "countries" of the test registry.
var visitors = []string{"192.0.2.71", "192.0.2.72", "198.51.100.73"}

const registry = `test|TR|ipv4|192.0.2.0|256|20260101|allocated|x
test|DE|ipv4|198.51.100.0|256|20260101|assigned|y
`

const firefox = "Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:140.0) Gecko/20100101 Firefox/140.0"

func TestRealHost(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("needs root")
	}
	ctx := context.Background()
	h := host.System{}
	sites := site.NewManager(h, "www-data")
	versions, err := sites.PHPVersions()
	if err != nil || len(versions) == 0 {
		t.Fatalf("no PHP-FPM: %v", err)
	}
	php := versions[len(versions)-1]
	for _, ip := range append([]string{server}, visitors...) {
		_ = exec.Command("ip", "addr", "add", ip+"/32", "dev", "lo").Run()
		t.Cleanup(func() { _ = exec.Command("ip", "addr", "del", ip+"/32", "dev", "lo").Run() })
	}
	res, err := sites.Apply(ctx, site.Spec{Domain: domain, User: user, PHPVersion: php, Aliases: []string{"www." + domain}})
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() {
		_, _ = sites.Delete(context.Background(), site.DeleteSpec{Domain: domain, User: user, RemoveFiles: true, RemoveUser: true})
	})
	write := func(name string, body []byte) {
		t.Helper()
		c := host.AsUser(user, host.Command{Name: "tee", Args: []string{res.DocumentRoot + "/" + name}, Stdin: body})
		if _, err := h.Run(ctx, c); err != nil {
			t.Fatal(err)
		}
	}
	write("index.php", []byte(`<?php echo str_repeat("a", 1000);`))
	write("urunler.php", []byte(`<?php echo str_repeat("b", 2000);`))
	write("app.css", []byte(strings.Repeat("c", 300)))
	write("video.bin", make([]byte, 3<<20))

	// The country table, downloaded from a registry served here.
	reg := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) { io.WriteString(w, registry) }))
	defer reg.Close()
	geoFile := filepath.Join(t.TempDir(), "countries.bin")
	g, err := stats.DownloadGeo(ctx, reg.Client(), []string{reg.URL + "/ripe", reg.URL + "/arin"}, geoFile)
	if err != nil || g.Country("192.0.2.71") != "TR" {
		t.Fatalf("country table: %v", err)
	}

	m := monitor.New(h, sites, nil)
	agg := stats.New()
	agg.SetGeo(g)
	m.Stats = agg
	waitFor(t, 10*time.Second, func() bool { _, err := os.Stat(res.PHPSocket); return err == nil })
	waitFor(t, 10*time.Second, func() bool { return get(visitors[0], "/index.php", "", firefox) == 200 })
	m.Sample(ctx) // starts reading the log from here
	agg.Collect(stats.CollectSpec{})

	type req struct{ from, path, ref, agent string }
	for _, r := range []req{
		{visitors[0], "/index.php", "https://www.google.com/search?q=x", firefox},
		{visitors[0], "/app.css", "http://" + domain + "/index.php", firefox},
		{visitors[0], "/urunler.php", "http://www." + domain + "/index.php", firefox},
		{visitors[1], "/urunler.php", "https://instagram.com/p/1", firefox},
		{visitors[2], "/index.php", "", firefox},
		{visitors[2], "/yok.php", "", firefox},
		{visitors[1], "/index.php", "", "Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)"},
		{visitors[1], "/video.bin", "", "curl/8.5.0"},
	} {
		get(r.from, r.path, r.ref, r.agent)
	}
	time.Sleep(300 * time.Millisecond)
	m.Sample(ctx)

	b := agg.Collect(stats.CollectSpec{})
	if len(b.Sites) != 1 || b.Sites[0].Domain != domain {
		t.Fatalf("batch = %+v", b)
	}
	var c stats.Counts
	items := map[string]map[string]stats.Item{}
	for _, hr := range b.Sites[0].Hours {
		c.Requests += hr.Requests
		c.Bytes += hr.Bytes
		c.Visitors += hr.Visitors
		c.Pages += hr.Pages
		c.BotRequests += hr.BotRequests
		c.BotBytes += hr.BotBytes
		c.S4 += hr.S4
		for kind, m := range hr.Items {
			if items[kind] == nil {
				items[kind] = map[string]stats.Item{}
			}
			for v, it := range m {
				x := items[kind][v]
				x.Requests += it.Requests
				x.Bytes += it.Bytes
				x.Visitors += it.Visitors
				items[kind][v] = x
			}
		}
	}
	t.Logf("counts %+v", c)
	if c.Requests != 8 || c.Visitors != 3 || c.Pages != 4 || c.BotRequests != 2 || c.S4 != 1 {
		t.Errorf("counts = %+v", c)
	}
	// The 3 MB file went to curl; the pages are a few hundred bytes.
	if c.BotBytes < 3<<20 || c.Bytes <= c.BotBytes {
		t.Errorf("bandwidth %d, by bots %d", c.Bytes, c.BotBytes)
	}
	if items[stats.KindPage]["/urunler.php"].Requests != 2 || items[stats.KindPage]["/index.php"].Requests != 2 {
		t.Errorf("pages = %+v", items[stats.KindPage])
	}
	if len(items[stats.KindReferrer]) != 2 || items[stats.KindReferrer]["google.com"].Requests != 1 || items[stats.KindReferrer]["instagram.com"].Requests != 1 {
		t.Errorf("referrers = %+v", items[stats.KindReferrer])
	}
	if items[stats.KindCountry]["TR"].Visitors != 2 || items[stats.KindCountry]["DE"].Visitors != 1 {
		t.Errorf("countries = %+v", items[stats.KindCountry])
	}
	if items[stats.KindBot]["Googlebot"].Requests != 1 || items[stats.KindBot]["curl"].Bytes < 3<<20 {
		t.Errorf("bots = %+v", items[stats.KindBot])
	}

	// Counts not collected yet survive a restart of the agent.
	get(visitors[0], "/index.php", "", firefox)
	time.Sleep(300 * time.Millisecond)
	m.Sample(ctx)
	state := filepath.Join(t.TempDir(), "state.gob")
	if err := agg.Save(state); err != nil {
		t.Fatal(err)
	}
	again := stats.New()
	if err := again.Load(state); err != nil {
		t.Fatal(err)
	}
	if pending := again.Collect(stats.CollectSpec{}); pending.ID != b.ID {
		t.Fatalf("pending batch %q became %q", b.ID, pending.ID)
	}
	if next := again.Collect(stats.CollectSpec{Ack: b.ID}); len(next.Sites) != 1 || next.Sites[0].Hours[len(next.Sites[0].Hours)-1].Requests != 1 {
		t.Fatalf("after restart = %+v", next)
	}

	// The disk breakdown shows the site's folder.
	usage, err := sites.DiskUsage(ctx, site.DiskSpec{Users: []string{user}})
	if err != nil {
		t.Fatal(err)
	}
	if got := usage[user].Sites[domain]; got < 3<<20 || got > usage[user].Bytes {
		t.Errorf("site folder = %d of %d bytes", got, usage[user].Bytes)
	}
}

func get(source, path, ref, agent string) int {
	d := &net.Dialer{LocalAddr: &net.TCPAddr{IP: net.ParseIP(source)}, Timeout: 5 * time.Second}
	c := &http.Client{Timeout: 10 * time.Second, Transport: &http.Transport{DialContext: d.DialContext, DisableKeepAlives: true}}
	r, _ := http.NewRequest("GET", "http://"+server+path, nil)
	r.Host = domain
	if ref != "" {
		r.Header.Set("Referer", ref)
	}
	r.Header.Set("User-Agent", agent)
	resp, err := c.Do(r)
	if err != nil {
		return 0
	}
	defer resp.Body.Close()
	_, _ = io.Copy(io.Discard, resp.Body)
	return resp.StatusCode
}

func waitFor(t *testing.T, d time.Duration, ok func() bool) {
	t.Helper()
	for end := time.Now().Add(d); time.Now().Before(end); time.Sleep(200 * time.Millisecond) {
		if ok() {
			return
		}
	}
	t.Fatal("timed out")
}
