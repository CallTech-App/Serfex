//go:build realhost

package site

import (
	"context"
	"errors"
	"io"
	"net/http"
	"os"
	"strings"
	"testing"
	"time"

	"github.com/calltech-app/serfex/agent/internal/host"
)

// TestDomainsRealHost serves a site's subdomains, aliases, redirects,
// error pages and directives from real Nginx and PHP-FPM:
//
//	sudo go test -tags realhost -run TestDomainsRealHost ./internal/site
func TestDomainsRealHost(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("needs root")
	}
	h := serviceHost{}
	m := NewManager(h, "www-data")
	versions, err := m.PHPVersions()
	if err != nil || len(versions) == 0 {
		t.Fatalf("no PHP-FPM installed: %v", err)
	}
	php := versions[len(versions)-1]
	startIfStopped(t, "/run/nginx.pid", "nginx")
	startIfStopped(t, "/run/php/php"+php+"-fpm.pid", "php-fpm"+php)

	ctx := context.Background()
	base := Spec{Domain: "serfex-alan.example", User: "serfexa", PHPVersion: php, Aliases: []string{"www.serfex-alan.example", "serfex-ek.example"}}
	t.Cleanup(func() {
		_, _ = m.Delete(ctx, DeleteSpec{Domain: base.Domain, User: base.User, RemoveFiles: true, RemoveUser: true})
	})
	full := base
	full.Subdomains = []SubdomainSpec{
		{Name: "blog.serfex-alan.example", WebRoot: "subdomains/blog"},
		{Name: "magaza.serfex-alan.example", WebRoot: "public_html/magaza"},
	}
	full.Redirects = []RedirectSpec{
		{Path: "/eski", Target: "/yeni", Code: 301, KeepPath: true},
		{Path: "/kampanya", Target: "https://example.org/indirim", Code: 302},
		{Hosts: []string{"serfex-eski.example"}, Target: "https://serfex-alan.example/", Code: 301, KeepPath: true},
	}
	full.ErrorPages = map[string]string{"404": "<h1>Serfex: sayfa yok</h1>", "50x": "<h1>Serfex: arıza</h1>"}
	full.NginxDirectives = "add_header X-Serfex-Alan evet always;"
	res, err := m.Apply(ctx, full)
	if err != nil {
		t.Fatal(err)
	}

	// The alias serves the main site; each subdomain its own root, one of
	// them inside the main site.
	get(t, "serfex-ek.example", "/", "<h1>serfex-alan.example</h1>")
	get(t, "blog.serfex-alan.example", "/", "<h1>blog.serfex-alan.example</h1>")
	get(t, "magaza.serfex-alan.example", "/", "<h1>magaza.serfex-alan.example</h1>")
	get(t, "serfex-alan.example", "/magaza/", "<h1>magaza.serfex-alan.example</h1>")

	// PHP in a subdomain runs as the site's user.
	script := host.AsUser("serfexa", host.Command{Name: "tee", Args: []string{"/home/serfexa/web/serfex-alan.example/subdomains/blog/who.php"},
		Stdin: []byte(`<?php echo 'blog:', posix_getpwuid(posix_geteuid())['name'];`)})
	if _, err := h.Run(ctx, script); err != nil {
		t.Fatal(err)
	}
	get(t, "blog.serfex-alan.example", "/who.php", "blog:serfexa")

	// Redirects: a path keeping the rest of it, a path to a fixed
	// address, a whole host name.
	// Nginx sends a path target as an address on the requested host.
	expectRedirect(t, "serfex-alan.example", "/eski/menu?x=1", 301, "http://serfex-alan.example/yeni/menu?x=1")
	expectRedirect(t, "serfex-alan.example", "/eski", 301, "http://serfex-alan.example/yeni")
	expectRedirect(t, "www.serfex-alan.example", "/kampanya/a", 302, "https://example.org/indirim")
	expectRedirect(t, "serfex-eski.example", "/urun/1?r=2", 301, "https://serfex-alan.example/urun/1?r=2")
	// Let's Encrypt still reaches the redirected host.
	if err := os.WriteFile("/var/lib/serfex/acme/.well-known/acme-challenge/serfex-alan-test", []byte("token"), 0o644); err != nil {
		t.Fatal(err)
	}
	defer os.Remove("/var/lib/serfex/acme/.well-known/acme-challenge/serfex-alan-test")
	get(t, "serfex-eski.example", "/.well-known/acme-challenge/serfex-alan-test", "token")

	// The site's own 404 page and directives.
	getStatus(t, "serfex-alan.example", "/yok.php", 404, "Serfex: sayfa yok")
	if got := header(t, "serfex-alan.example", "/", "X-Serfex-Alan"); got != "evet" {
		t.Fatalf("directive header = %q", got)
	}

	// Directives Nginx refuses are not used; the site keeps working.
	bad := full
	bad.NginxDirectives = "serfex_bilinmeyen_yonerge on;"
	_, err = m.Apply(ctx, bad)
	var ce *ConfigError
	if !errors.As(err, &ce) || !strings.Contains(ce.Output, "serfex_bilinmeyen_yonerge") {
		t.Fatalf("err = %v, want nginx's refusal", err)
	}
	if vhost, _ := os.ReadFile(res.NginxConfig); strings.Contains(string(vhost), "serfex_bilinmeyen_yonerge") {
		t.Fatal("refused directive left in the vhost")
	}
	if got := header(t, "serfex-alan.example", "/", "X-Serfex-Alan"); got != "evet" {
		t.Fatalf("site changed after a refused apply: %q", got)
	}

	// The log viewer sees the requests, subdomains included.
	log, err := m.Logs(LogsSpec{Domain: base.Domain, User: base.User, Kind: "access"})
	if err != nil || !strings.Contains(log.Content, "/who.php") || !strings.Contains(log.Content, "/eski/menu") {
		t.Fatalf("access log: %v\n%s", err, log.Content)
	}

	// Taking everything away again.
	if _, err := m.Apply(ctx, base); err != nil {
		t.Fatal(err)
	}
	getStatus(t, "serfex-alan.example", "/yok.php", 404, "nginx")
	if _, err := os.Stat("/var/lib/serfex/errors/serfex-alan.example/serfex-error-404.html"); !errors.Is(err, os.ErrNotExist) {
		t.Fatal("error page left behind")
	}
}

// fresh is a client without keep-alive (a reload leaves old workers
// serving open connections) that does not follow redirects.
var fresh = &http.Client{
	Transport:     &http.Transport{DisableKeepAlives: true},
	CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
}

func expectRedirect(t *testing.T, hostname, path string, code int, location string) {
	t.Helper()
	var got string
	for i := 0; i < 50; i++ {
		req, _ := http.NewRequest("GET", "http://127.0.0.1"+path, nil)
		req.Host = hostname
		resp, err := fresh.Do(req)
		if err != nil {
			got = err.Error()
		} else {
			_, _ = io.Copy(io.Discard, resp.Body)
			resp.Body.Close()
			got = resp.Status + " " + resp.Header.Get("Location")
			if resp.StatusCode == code && resp.Header.Get("Location") == location {
				return
			}
		}
		time.Sleep(100 * time.Millisecond)
	}
	t.Fatalf("%s%s: got %s, want %d %s", hostname, path, got, code, location)
}

func header(t *testing.T, hostname, path, name string) string {
	t.Helper()
	req, _ := http.NewRequest("GET", "http://127.0.0.1"+path, nil)
	req.Host = hostname
	resp, err := fresh.Do(req)
	if err != nil {
		t.Fatal(err)
	}
	defer resp.Body.Close()
	_, _ = io.Copy(io.Discard, resp.Body)
	return resp.Header.Get(name)
}
