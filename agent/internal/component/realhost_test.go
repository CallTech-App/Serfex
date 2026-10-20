//go:build realhost

// This test installs and removes real packages: Apache behind Nginx with a
// site served through it, Redis with a site's own instance, and Memcached
// with a failure injected to check the rollback. It needs root, systemd
// and apt, so it only builds with -tags realhost:
//
//	sudo go test -tags realhost -run TestRealHost ./internal/component
//
// CI runs it on a throwaway runner with Nginx and PHP-FPM installed.
package component

import (
	"context"
	"errors"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/winchester37/servik/agent/internal/database"
	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/laravel"
	"github.com/winchester37/servik/agent/internal/site"
)

func TestRealHost(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("needs root")
	}
	h := host.System{}
	sites := site.NewManager(h, "www-data")
	m := NewManager(h, sites, database.NewManager(h), laravel.NewManager(h))
	ctx := context.Background()
	versions, err := sites.PHPVersions()
	if err != nil || len(versions) == 0 {
		t.Fatalf("no PHP-FPM installed: %v", err)
	}
	php := versions[len(versions)-1]

	spec := site.Spec{Domain: "servik-comp.example", User: "servikc", PHPVersion: php}
	t.Cleanup(func() {
		_, _ = m.RemoveRedis(ctx, spec.Domain)
		_, _ = sites.Delete(ctx, site.DeleteSpec{Domain: spec.Domain, User: spec.User, RemoveFiles: true, RemoveUser: true})
	})

	// Apache: installed behind Nginx, on 127.0.0.1 only.
	if s := status(t, m, "apache"); s.Installed {
		t.Fatal("apache2 is already installed; the job removes it first")
	}
	if _, err := m.Install(ctx, OpSpec{ID: "apache", Run: "rh-apache"}); err != nil {
		t.Fatalf("install apache: %v\n%s", err, runLog("rh-apache"))
	}
	if s := status(t, m, "apache"); !s.Installed || !s.Running || s.Version == "" {
		t.Fatalf("apache after install: %+v", s)
	}
	if !strings.Contains(runLog("rh-apache"), "✓ Kuruldu") {
		t.Fatalf("run log does not end with success:\n%s", runLog("rh-apache"))
	}

	// A site behind Apache: .htaccess rewrites work and PHP runs in the
	// site's own pool as its user.
	spec.WebServer = site.WebApache
	res, err := sites.Apply(ctx, spec)
	if err != nil {
		t.Fatal(err)
	}
	writeAs(t, h, "servikc", res.DocumentRoot+"/.htaccess", "RewriteEngine On\nRewriteRule ^pretty$ info.php [L]\n")
	writeAs(t, h, "servikc", res.DocumentRoot+"/info.php", `<?php echo $_SERVER['SERVER_SOFTWARE'], '|', get_current_user(), '|', $_SERVER['REMOTE_ADDR'];`)
	writeAs(t, h, "servikc", res.DocumentRoot+"/style.css", "body{}\n")
	body := get(t, spec.Domain, "/pretty", "Apache")
	if !strings.Contains(body, "|servikc|127.0.0.1") {
		t.Fatalf("PHP through Apache: %q", body)
	}
	// Static files come from Nginx.
	get(t, spec.Domain, "/style.css", "body{}")

	// A subdomain goes through Apache too, in the same pool; the site's
	// own Apache directives are tested and used.
	withSub := spec
	withSub.Subdomains = []site.SubdomainSpec{{Name: "blog." + spec.Domain, WebRoot: "subdomains/blog"}}
	withSub.ApacheDirectives = "SetEnv SERVIK_ALAN evet"
	if _, err := sites.Apply(ctx, withSub); err != nil {
		t.Fatal(err)
	}
	writeAs(t, h, "servikc", filepath.Dir(res.DocumentRoot)+"/subdomains/blog/who.php", `<?php echo $_SERVER['SERVER_SOFTWARE'], '|', get_current_user();`)
	writeAs(t, h, "servikc", res.DocumentRoot+"/env.php", `<?php echo 'alan=', $_SERVER['SERVIK_ALAN'] ?? '';`)
	get(t, "blog."+spec.Domain, "/who.php", "Apache")
	get(t, spec.Domain, "/env.php", "alan=evet")
	bad := withSub
	bad.ApacheDirectives = "ServikBilinmeyen on"
	var ce *site.ConfigError
	if _, err := sites.Apply(ctx, bad); !errors.As(err, &ce) || ce.Server != "apache" {
		t.Fatalf("apply with a directive Apache refuses: %v", err)
	}
	get(t, spec.Domain, "/env.php", "alan=evet")

	// Apache cannot go while the site uses it.
	var inUse *InUseError
	if _, err := m.Remove(ctx, OpSpec{ID: "apache", Run: "rh-apache-used"}); !errors.As(err, &inUse) || inUse.UsedBy[0] != spec.Domain {
		t.Fatalf("remove apache in use: %v", err)
	}

	// Back to Nginx + PHP-FPM; then Apache is removed with a backup.
	spec.WebServer = site.WebNginx
	if _, err := sites.Apply(ctx, spec); err != nil {
		t.Fatal(err)
	}
	get(t, spec.Domain, "/info.php", "|servikc|")
	if _, err := m.Remove(ctx, OpSpec{ID: "apache", Run: "rh-apache-rm"}); err != nil {
		t.Fatalf("remove apache: %v\n%s", err, runLog("rh-apache-rm"))
	}
	if m.packageInstalled(ctx, "apache2") {
		t.Fatal("apache2 still installed")
	}
	if backups, _ := filepath.Glob(BackupDir + "/apache-*.tar.gz"); len(backups) == 0 {
		t.Fatal("no backup of the Apache configuration")
	}
	get(t, spec.Domain, "/info.php", "|servikc|")

	// A failed install puts the server back as it was.
	m.Fail = func(id, step string) error {
		if id == "memcached" && step == "configure" {
			return errors.New("injected failure")
		}
		return nil
	}
	if _, err := m.Install(ctx, OpSpec{ID: "memcached", Run: "rh-memcached"}); err == nil {
		t.Fatal("memcached install did not fail")
	}
	m.Fail = nil
	if m.packageInstalled(ctx, "memcached") {
		t.Fatalf("memcached was not rolled back:\n%s", runLog("rh-memcached"))
	}
	if log := runLog("rh-memcached"); !strings.Contains(log, "↺ ") || !strings.Contains(log, "✗ ") {
		t.Fatalf("run log does not show the rollback:\n%s", log)
	}

	// Redis: the site's own instance, with a password, as its user.
	if _, err := m.Install(ctx, OpSpec{ID: "redis", Run: "rh-redis"}); err != nil {
		t.Fatalf("install redis: %v\n%s", err, runLog("rh-redis"))
	}
	password := "Rh" + strings.Repeat("x7", 12)
	if _, err := m.ApplyRedis(ctx, RedisSpec{Domain: spec.Domain, User: "servikc", Port: 26379, Password: password}); err != nil {
		t.Fatal(err)
	}
	out, err := h.Run(ctx, host.Command{Name: "redis-cli", Args: []string{"-p", "26379", "--no-auth-warning", "set", "k", "v"}, Env: []string{"REDISCLI_AUTH=" + password}})
	if err != nil || strings.TrimSpace(out) != "OK" {
		t.Fatalf("redis set: %q %v", out, err)
	}
	if out, _ := h.Run(ctx, host.Command{Name: "redis-cli", Args: []string{"-p", "26379", "ping"}}); strings.Contains(out, "PONG") {
		t.Fatal("redis answered without a password")
	}
	if out, _ := h.Run(ctx, host.Command{Name: "ps", Args: []string{"-o", "user=", "-C", "redis-server"}}); !strings.Contains(out, "servikc") {
		t.Fatalf("redis does not run as the site user: %q", out)
	}
	var redisUsed *InUseError
	if _, err := m.Remove(ctx, OpSpec{ID: "redis", Run: "rh-redis-used"}); !errors.As(err, &redisUsed) {
		t.Fatalf("remove redis in use: %v", err)
	}
	if removed, err := m.RemoveRedis(ctx, spec.Domain); err != nil || !removed {
		t.Fatalf("remove instance: %v %v", removed, err)
	}
	if _, err := m.Remove(ctx, OpSpec{ID: "redis", Run: "rh-redis-rm"}); err != nil {
		t.Fatalf("remove redis: %v\n%s", err, runLog("rh-redis-rm"))
	}
}

func runLog(run string) string {
	b, _ := os.ReadFile(runDir + "/" + run + ".log")
	return string(b)
}

func writeAs(t *testing.T, h host.Host, user, path, content string) {
	t.Helper()
	if _, err := h.Run(context.Background(), host.AsUser(user, host.Command{Name: "tee", Args: []string{path}, Stdin: []byte(content)})); err != nil {
		t.Fatal(err)
	}
}

// get fetches path from the local Nginx until the answer contains want.
func get(t *testing.T, hostname, path, want string) string {
	t.Helper()
	var body string
	var code int
	for i := 0; i < 100; i++ {
		req, _ := http.NewRequest("GET", "http://127.0.0.1"+path, nil)
		req.Host = hostname
		if resp, err := http.DefaultClient.Do(req); err == nil {
			b, _ := io.ReadAll(resp.Body)
			resp.Body.Close()
			body, code = string(b), resp.StatusCode
			if code == 200 && strings.Contains(body, want) {
				return body
			}
		}
		time.Sleep(100 * time.Millisecond)
	}
	t.Fatalf("GET %s%s: %d %q, want %q", hostname, path, code, body, want)
	return ""
}
