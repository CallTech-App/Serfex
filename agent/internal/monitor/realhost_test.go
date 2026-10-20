//go:build realhost

// This test watches a real site served by Nginx and PHP-FPM under
// systemd: it measures a busy PHP request, spots a request flood, blocks
// the flooding address with nftables, caps the site's CPU through a
// systemd scope and stops its PHP workers. It needs root and systemd, and
// changes the machine, so it only builds with -tags realhost:
//
//	sudo go test -tags realhost -run TestRealHost ./internal/monitor
//
// CI runs it on a throwaway runner with Nginx and PHP-FPM installed.
package monitor

import (
	"context"
	"fmt"
	"io"
	"net"
	"net/http"
	"os"
	"os/exec"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/winchester37/servik/agent/internal/firewall"
	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/site"
)

const (
	domain   = "izleme-test.example"
	user     = "izlemet"
	server   = "192.0.2.50" // added to lo for the test
	attacker = "192.0.2.51"
	visitor  = "192.0.2.52"
)

func TestRealHost(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("needs root")
	}
	if b, _ := os.ReadFile("/proc/1/comm"); strings.TrimSpace(string(b)) != "systemd" {
		t.Skip("needs systemd")
	}
	ctx := context.Background()
	h := host.System{}
	sites := site.NewManager(h, "www-data")
	versions, err := sites.PHPVersions()
	if err != nil || len(versions) == 0 {
		t.Fatalf("no PHP-FPM: %v", err)
	}
	php := versions[len(versions)-1]
	for _, unit := range []string{"nginx", "php" + php + "-fpm"} {
		run(t, "systemctl", "start", unit)
	}
	for _, ip := range []string{server, attacker, visitor} {
		_ = exec.Command("ip", "addr", "add", ip+"/32", "dev", "lo").Run()
		t.Cleanup(func() { _ = exec.Command("ip", "addr", "del", ip+"/32", "dev", "lo").Run() })
	}

	res, err := sites.Apply(ctx, site.Spec{Domain: domain, User: user, PHPVersion: php})
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() {
		_, _ = sites.Delete(context.Background(), site.DeleteSpec{Domain: domain, User: user, RemoveFiles: true, RemoveUser: true})
	})
	write := func(name, body string) {
		t.Helper()
		c := host.AsUser(user, host.Command{Name: "tee", Args: []string{res.DocumentRoot + "/" + name}, Stdin: []byte(body)})
		if _, err := h.Run(ctx, c); err != nil {
			t.Fatal(err)
		}
	}
	write("busy.php", `<?php $t = microtime(true); $n = 0; while (microtime(true) - $t < (float) ($_GET['s'] ?? 3)) { $n++; } echo "busy $n";`)
	write("sleep.php", `<?php sleep(30); echo "slept";`)
	write("index.php", `<?php echo "ok";`)

	m := New(h, sites, nil)
	sites.MaxChildren = m.PHPWorkers
	waitFor(t, 10*time.Second, func() bool { _, err := os.Stat(res.PHPSocket); return err == nil })
	if code, _ := get(visitor, "/index.php", 5*time.Second); code != 200 {
		t.Fatalf("site not served: %d", code)
	}
	m.Sample(ctx)

	// A busy request: the site uses about one core, and its pool reports
	// a busy worker through the status socket.
	done := make(chan string, 1)
	go func() { _, body := get(visitor, "/busy.php?s=4", 20*time.Second); done <- body }()
	time.Sleep(1500 * time.Millisecond)
	m.Sample(ctx)
	time.Sleep(2 * time.Second)
	m.Sample(ctx)
	snap := m.Snapshot(SnapshotSpec{Window: 2})
	s := siteOf(snap, domain)
	t.Logf("busy site: %+v php=%+v", s.Usage, s.PHP)
	if s.CPU < 50 || s.Memory == 0 {
		t.Errorf("busy site uses %.1f%% CPU, %d bytes", s.CPU, s.Memory)
	}
	if !s.PHP.Known || s.PHP.Busy < 1 || s.PHP.Max != site.DefaultMaxChildren {
		t.Errorf("pool status = %+v", s.PHP)
	}
	if body := <-done; !strings.HasPrefix(body, "busy") {
		t.Fatalf("busy.php answered %q", body)
	}
	if len(snap.Services) == 0 || snap.Server.CPUs == 0 {
		t.Errorf("services = %+v, server = %+v", snap.Services, snap.Server)
	}

	// A flood from one address makes it a suspect.
	var wg sync.WaitGroup
	for w := 0; w < 10; w++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for i := 0; i < FloodPerMinute/10+5; i++ {
				get(attacker, "/", 5*time.Second)
			}
		}()
	}
	wg.Wait()
	time.Sleep(500 * time.Millisecond)
	m.Sample(ctx)
	snap = m.Snapshot(SnapshotSpec{Window: 60})
	found := false
	for _, sp := range snap.Suspects {
		found = found || (sp.IP == attacker && sp.Kind == "flood" && sp.Target == domain)
	}
	if !found {
		t.Fatalf("flood not detected: %+v (top: %+v)", snap.Suspects, siteOf(snap, domain).TopIPs)
	}
	if top := siteOf(snap, domain).TopIPs; len(top) == 0 || top[0].IP != attacker {
		t.Errorf("top addresses = %+v", top)
	}

	// Blocking it drops its packets; others still get in.
	fw := firewall.NewManager(h)
	t.Cleanup(func() { _ = exec.Command("nft", "delete", "table", "inet", firewall.Table).Run() })
	if _, err := fw.Block(ctx, firewall.Spec{Address: attacker, Seconds: 120, Reason: "test"}); err != nil {
		t.Fatal(err)
	}
	if code, _ := get(attacker, "/index.php", 2*time.Second); code != 0 {
		t.Fatalf("blocked address got %d", code)
	}
	if code, _ := get(visitor, "/index.php", 5*time.Second); code != 200 {
		t.Fatalf("visitor got %d", code)
	}
	if removed, err := fw.Unblock(ctx, attacker); err != nil || !removed {
		t.Fatalf("unblock = %v, %v", removed, err)
	}
	if code, _ := get(attacker, "/index.php", 5*time.Second); code != 200 {
		t.Fatalf("unblocked address got %d", code)
	}

	// A CPU cap: the busy worker moves into the limit scope and slows
	// down to a fifth of a core.
	go func() { _, body := get(visitor, "/busy.php?s=8", 30*time.Second); done <- body }()
	time.Sleep(time.Second)
	if _, err := m.ApplyLimit(ctx, LimitSpec{Domain: domain, CPU: 20, MemoryMB: 256, Seconds: 300}); err != nil {
		t.Fatal(err)
	}
	scope := scopeName(domain)
	cg := strings.TrimSpace(run(t, "systemctl", "show", "--property=ControlGroup", "--value", scope))
	if b, err := os.ReadFile("/sys/fs/cgroup" + cg + "/cpu.max"); err != nil || strings.TrimSpace(string(b)) != "20000 100000" {
		t.Fatalf("cpu.max of %s = %q, %v", cg, b, err)
	}
	if b, _ := os.ReadFile("/sys/fs/cgroup" + cg + "/memory.max"); strings.TrimSpace(string(b)) != fmt.Sprint(256<<20) {
		t.Errorf("memory.max = %q", b)
	}
	// StartTransientUnit only queues the job; systemd moves the workers
	// when it runs.
	workers := func() (in, out []proc) {
		for _, p := range readProcs(h) {
			if p.Cmd == "php-fpm: pool "+domain {
				if p.Unit == scope {
					in = append(in, p)
				} else {
					out = append(out, p)
				}
			}
		}
		return in, out
	}
	in, out := workers()
	for end := time.Now().Add(5 * time.Second); (len(in) == 0 || len(out) > 0) && time.Now().Before(end); in, out = workers() {
		time.Sleep(200 * time.Millisecond)
	}
	for _, p := range out {
		t.Errorf("worker %d is in %s", p.PID, p.Unit)
	}
	if len(in) == 0 {
		t.Fatal("no worker in the scope")
	}
	m.Sample(ctx)
	time.Sleep(3 * time.Second)
	m.Sample(ctx)
	if s := siteOf(m.Snapshot(SnapshotSpec{Window: 3}), domain); s.CPU > 35 || s.Limit == nil {
		t.Errorf("capped site uses %.1f%% CPU, limit %+v", s.CPU, s.Limit)
	}
	<-done
	// New workers join the scope at the next sample.
	go func() { _, body := get(visitor, "/busy.php?s=2", 30*time.Second); done <- body }()
	time.Sleep(500 * time.Millisecond)
	m.Sample(ctx)
	for _, p := range readProcs(h) {
		if p.Cmd == "php-fpm: pool "+domain && p.Unit != scope {
			t.Errorf("new worker %d is in %s", p.PID, p.Unit)
		}
	}
	<-done
	if _, err := m.RemoveLimit(ctx, domain); err != nil {
		t.Fatal(err)
	}
	if b, _ := os.ReadFile("/sys/fs/cgroup" + cg + "/cpu.max"); strings.TrimSpace(string(b)) != "max 100000" {
		t.Errorf("cpu.max after removal = %q", b)
	}

	// A PHP worker limit rewrites the pool and survives site.apply.
	if _, err := m.ApplyLimit(ctx, LimitSpec{Domain: domain, PHPWorkers: 2, Seconds: 300}); err != nil {
		t.Fatal(err)
	}
	if _, err := sites.Apply(ctx, site.Spec{Domain: domain, User: user, PHPVersion: php}); err != nil {
		t.Fatal(err)
	}
	if b, _ := os.ReadFile(res.FPMConfig); !strings.Contains(string(b), "pm.max_children = 2\n") {
		t.Fatalf("pool:\n%s", b)
	}
	if _, err := m.RemoveLimit(ctx, domain); err != nil {
		t.Fatal(err)
	}
	waitFor(t, 10*time.Second, func() bool { code, _ := get(visitor, "/index.php", 5*time.Second); return code == 200 })

	// Restarting the pool stops a hanging request's worker.
	start := time.Now()
	go func() { code, _ := get(visitor, "/sleep.php", 40*time.Second); done <- fmt.Sprint(code) }()
	time.Sleep(time.Second)
	n, err := m.RestartPool(ctx, domain)
	if err != nil || n < 1 {
		t.Fatalf("restart = %d, %v", n, err)
	}
	if code := <-done; code == "200" || time.Since(start) > 20*time.Second {
		t.Fatalf("hanging request ended with %s after %s", code, time.Since(start))
	}
	if code, _ := get(visitor, "/index.php", 5*time.Second); code != 200 {
		t.Fatalf("site after restart: %d", code)
	}
}

// get requests path from Nginx on the test address, from source; code 0
// means no answer.
func get(source, path string, timeout time.Duration) (int, string) {
	d := &net.Dialer{LocalAddr: &net.TCPAddr{IP: net.ParseIP(source)}, Timeout: timeout}
	c := &http.Client{Timeout: timeout, Transport: &http.Transport{DialContext: d.DialContext, DisableKeepAlives: true}}
	req, _ := http.NewRequest("GET", "http://"+server+path, nil)
	req.Host = domain
	resp, err := c.Do(req)
	if err != nil {
		return 0, ""
	}
	defer resp.Body.Close()
	b, _ := io.ReadAll(resp.Body)
	return resp.StatusCode, string(b)
}

func run(t *testing.T, name string, args ...string) string {
	t.Helper()
	out, err := exec.Command(name, args...).CombinedOutput()
	if err != nil {
		t.Fatalf("%s %v: %v: %s", name, args, err, out)
	}
	return string(out)
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
