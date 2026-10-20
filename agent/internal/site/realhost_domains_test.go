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

	"github.com/winchester37/servik/agent/internal/host"
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
	base := Spec{Domain: "servik-alan.example", User: "servika", PHPVersion: php, Aliases: []string{"www.servik-alan.example", "servik-ek.example"}}
	t.Cleanup(func() {
		_, _ = m.Delete(ctx, DeleteSpec{Domain: base.Domain, User: base.User, RemoveFiles: true, RemoveUser: true})
	})
	full := base
	full.Subdomains = []SubdomainSpec{
		{Name: "blog.servik-alan.example", WebRoot: "subdomains/blog"},
		{Name: "magaza.servik-alan.example", WebRoot: "public_html/magaza"},
	}
	full.Redirects = []RedirectSpec{
		{Path: "/eski", Target: "/yeni", Code: 301, KeepPath: true},
		{Path: "/kampanya", Target: "https://example.org/indirim", Code: 302},
		{Hosts: []string{"servik-eski.example"}, Target: "https://servik-alan.example/", Code: 301, KeepPath: true},
	}
	full.ErrorPages = map[string]string{"404": "<h1>Servik: sayfa yok</h1>", "50x": "<h1>Servik: arıza</h1>"}
	full.NginxDirectives = "add_header X-Servik-Alan evet always;"
	res, err := m.Apply(ctx, full)
	if err != nil {
		t.Fatal(err)
	}

	// The alias serves the main site; each subdomain its own root, one of
	// them inside the main site.
	get(t, "servik-ek.example", "/", "<h1>servik-alan.example</h1>")
	get(t, "blog.servik-alan.example", "/", "<h1>blog.servik-alan.example</h1>")
	get(t, "magaza.servik-alan.example", "/", "<h1>magaza.servik-alan.example</h1>")
	get(t, "servik-alan.example", "/magaza/", "<h1>magaza.servik-alan.example</h1>")

	// PHP in a subdomain runs as the site's user.
	script := host.AsUser("servika", host.Command{Name: "tee", Args: []string{"/home/servika/web/servik-alan.example/subdomains/blog/who.php"},
		Stdin: []byte(`<?php echo 'blog:', posix_getpwuid(posix_geteuid())['name'];`)})
	if _, err := h.Run(ctx, script); err != nil {
		t.Fatal(err)
	}
	get(t, "blog.servik-alan.example", "/who.php", "blog:servika")

	// Redirects: a path keeping the rest of it, a path to a fixed
	// address, a whole host name.
	// Nginx sends a path target as an address on the requested host.
	expectRedirect(t, "servik-alan.example", "/eski/menu?x=1", 301, "http://servik-alan.example/yeni/menu?x=1")
	expectRedirect(t, "servik-alan.example", "/eski", 301, "http://servik-alan.example/yeni")
	expectRedirect(t, "www.servik-alan.example", "/kampanya/a", 302, "https://example.org/indirim")
	expectRedirect(t, "servik-eski.example", "/urun/1?r=2", 301, "https://servik-alan.example/urun/1?r=2")
	// Let's Encrypt still reaches the redirected host.
	if err := os.WriteFile("/var/lib/servik/acme/.well-known/acme-challenge/servik-alan-test", []byte("token"), 0o644); err != nil {
		t.Fatal(err)
	}
	defer os.Remove("/var/lib/servik/acme/.well-known/acme-challenge/servik-alan-test")
	get(t, "servik-eski.example", "/.well-known/acme-challenge/servik-alan-test", "token")

	// The site's own 404 page and directives.
	getStatus(t, "servik-alan.example", "/yok.php", 404, "Servik: sayfa yok")
	if got := header(t, "servik-alan.example", "/", "X-Servik-Alan"); got != "evet" {
		t.Fatalf("directive header = %q", got)
	}

	// Directives Nginx refuses are not used; the site keeps working.
	bad := full
	bad.NginxDirectives = "servik_bilinmeyen_yonerge on;"
	_, err = m.Apply(ctx, bad)
	var ce *ConfigError
	if !errors.As(err, &ce) || !strings.Contains(ce.Output, "servik_bilinmeyen_yonerge") {
		t.Fatalf("err = %v, want nginx's refusal", err)
	}
	if vhost, _ := os.ReadFile(res.NginxConfig); strings.Contains(string(vhost), "servik_bilinmeyen_yonerge") {
		t.Fatal("refused directive left in the vhost")
	}
	if got := header(t, "servik-alan.example", "/", "X-Servik-Alan"); got != "evet" {
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
	getStatus(t, "servik-alan.example", "/yok.php", 404, "nginx")
	if _, err := os.Stat("/var/lib/servik/errors/servik-alan.example/servik-error-404.html"); !errors.Is(err, os.ErrNotExist) {
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
