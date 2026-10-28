//go:build realhost

// This test installs a real WordPress with WP-CLI behind real Nginx,
// PHP-FPM and MySQL or MariaDB, then signs in with a one-time link,
// hardens it, switches maintenance mode and puts a restore point back.
// It needs root, a running database server reachable as root over its
// socket, and changes the machine:
//
//	sudo go test -tags realhost -run TestRealHostWordPress -timeout 10m ./internal/laravel
//
// Where wordpress.org is not reachable, SERFEX_TEST_WP_TARBALL names a
// WordPress tarball (wordpress/ at its top) and SERFEX_TEST_WP_VERSION its
// version; it is put in WP-CLI's cache so "core download" uses it.
package laravel

import (
	"context"
	"io"
	"net/http"
	"os"
	"os/exec"
	"strings"
	"testing"
	"time"

	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/site"
)

const wpRealDomain = "serfex-wp.example"

func TestRealHostWordPress(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("needs root")
	}
	h := serviceHost{}
	sites := site.NewManager(h, "www-data")
	versions, err := sites.PHPVersions()
	if err != nil || len(versions) == 0 {
		t.Fatalf("no PHP-FPM installed: %v", err)
	}
	php := versions[len(versions)-1]
	if !hasSystemd() {
		startIfStopped(t, "/run/nginx.pid", "nginx")
		startIfStopped(t, "/run/php/php"+php+"-fpm.pid", "php-fpm"+php)
	}
	ctx := context.Background()
	user := "serfexwp"
	tg := Target{Domain: wpRealDomain, User: user, PHPVersion: php}
	if _, err := sites.Apply(ctx, site.Spec{Domain: wpRealDomain, User: user, PHPVersion: php}); err != nil {
		t.Fatal(err)
	}
	m := NewManager(h)
	// security.Manager.WriteSnippet in the agent; the package imports this
	// one, so the test writes the snippet itself.
	m.Snippet = func(ctx context.Context, domain, name string, data []byte) error {
		p := site.ExtraDir(domain) + "/" + name
		if data == nil {
			_ = os.Remove(p)
		} else {
			if err := os.MkdirAll(site.ExtraDir(domain), 0o755); err != nil {
				return err
			}
			if err := os.WriteFile(p, data, 0o644); err != nil {
				return err
			}
		}
		if _, err := h.Run(ctx, host.Command{Name: "nginx", Args: []string{"-t"}}); err != nil {
			return err
		}
		_, err := h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"reload", "nginx"}})
		return err
	}
	db := "serfexwp_test"
	mysql(t, "CREATE DATABASE IF NOT EXISTS "+db+"; CREATE USER IF NOT EXISTS 'serfexwp'@'localhost' IDENTIFIED BY 'wp-test-Parola1'; GRANT ALL ON "+db+".* TO 'serfexwp'@'localhost';")
	t.Cleanup(func() {
		_, _ = sites.Delete(context.Background(), site.DeleteSpec{Domain: wpRealDomain, User: user, RemoveFiles: true, RemoveUser: true})
		_ = os.RemoveAll(wpStateDir + "/" + wpRealDomain)
		mysql(t, "DROP DATABASE IF EXISTS "+db+"; DROP USER IF EXISTS 'serfexwp'@'localhost';")
	})

	spec := WPInstallSpec{
		App: App{Target: tg, Path: "public_html"}, Run: "install", URL: "http://" + wpRealDomain, Title: "Serfex WordPress",
		AdminUser: "aylin", AdminEmail: "aylin@example.com", AdminPassword: "Yonetici-parola-42", Locale: "en_US", Harden: true,
		DB: WPDatabase{Name: db, User: "serfexwp", Password: "wp-test-Parola1", Host: "localhost"},
	}
	if tarball := os.Getenv("SERFEX_TEST_WP_TARBALL"); tarball != "" {
		spec.Version = os.Getenv("SERFEX_TEST_WP_VERSION")
		cache := "/home/" + user + "/.wp-cli/cache/core"
		run(t, "install", "-d", "-o", user, "-g", user, "/home/"+user+"/.wp-cli", "/home/"+user+"/.wp-cli/cache", cache)
		run(t, "install", "-o", user, "-g", user, "-m", "0644", tarball, cache+"/wordpress-"+spec.Version+"-en_US.tar.gz")
	}
	res, err := m.InstallWordPress(ctx, spec)
	if err != nil {
		logData, _ := m.ReadWordPressRun(wpRealDomain, "install", 0)
		t.Fatalf("install: %v\n%s", err, logData.Data)
	}
	t.Logf("WordPress %s installed in %d ms", res.Version, res.Millis)
	a := spec.App

	code, body, _ := fetch(t, "/", "")
	if code != 200 || !strings.Contains(body, "Serfex WordPress") {
		t.Fatalf("home page: %d %.300s", code, body)
	}
	// Hardening: nothing uploaded runs as PHP; settings and the version
	// files are not served.
	run(t, "runuser", "-u", user, "--", "sh", "-c", "mkdir -p "+a.dir()+"/wp-content/uploads && echo '<?php echo 1;' > "+a.dir()+"/wp-content/uploads/x.php")
	for _, p := range []string{"/wp-content/uploads/x.php", "/readme.html", "/wp-config.php"} {
		if code, _, _ := fetch(t, p, ""); code != 403 {
			t.Errorf("%s: HTTP %d, want 403", p, code)
		}
	}

	st, err := m.WordPressStatus(ctx, a)
	if err != nil {
		t.Fatal(err)
	}
	if st.Version != res.Version || len(st.Plugins) == 0 || len(st.Admins) != 1 || !st.Hardening["file_edit"] || !st.Hardening["config_perms"] || !st.Hardening["sensitive_files"] {
		t.Fatalf("status %+v", st)
	}

	// The sign-in link opens the dashboard once.
	login, err := m.WordPressLogin(ctx, WPLoginSpec{App: a})
	if err != nil {
		t.Fatal(err)
	}
	path := strings.TrimPrefix(login.URL, "http://"+wpRealDomain)
	code, _, hdr := fetch(t, path, "")
	if code != 302 || !strings.HasSuffix(hdr.Get("Location"), "/wp-admin/") || !strings.Contains(strings.Join(hdr.Values("Set-Cookie"), " "), "wordpress_logged_in_") {
		t.Fatalf("sign-in: %d %v", code, hdr)
	}
	if code, _, _ := fetch(t, path, ""); code != 403 {
		t.Fatalf("second use of the link: HTTP %d", code)
	}

	if _, err := m.WordPressMaintenance(ctx, WPMaintenanceSpec{App: a, On: true}); err != nil {
		t.Fatal(err)
	}
	if code, _, _ := fetch(t, "/", ""); code != 503 {
		t.Errorf("maintenance: HTTP %d", code)
	}
	if _, err := m.WordPressMaintenance(ctx, WPMaintenanceSpec{App: a, On: false}); err != nil {
		t.Fatal(err)
	}

	// A restore point of a plugin and the database, then changes, then
	// back: the plugin's files and the site title return.
	plugin := a.dir() + "/wp-content/plugins/serfex-test"
	run(t, "runuser", "-u", user, "--", "sh", "-c", "mkdir -p "+plugin+" && echo '<?php /* Plugin Name: Serfex test */' > "+plugin+"/serfex-test.php && echo hi > "+plugin+"/readme.txt")
	plan := []WPChange{{Kind: "plugin", Name: "serfex-test", From: "1", To: "2"}}
	if err := m.wpTakePoint(ctx, WPUpdateSpec{App: a, Run: "point1", Check: "/"}, plan); err != nil {
		t.Fatal(err)
	}
	if _, err := m.wp(ctx, a, true, "option", "update", "blogname", "Changed"); err != nil {
		t.Fatal(err)
	}
	run(t, "runuser", "-u", user, "--", "sh", "-c", "echo extra > "+plugin+"/new-file.txt && rm "+plugin+"/readme.txt")
	if err := m.RestoreWordPress(ctx, WPRestoreSpec{App: a, Run: "restore1", Point: "point1"}); err != nil {
		logData, _ := m.ReadWordPressRun(wpRealDomain, "restore1", 0)
		t.Fatalf("restore: %v\n%s", err, logData.Data)
	}
	if out, _ := m.wp(ctx, a, true, "option", "get", "blogname"); strings.TrimSpace(out) != "Serfex WordPress" {
		t.Errorf("blogname after restore: %q", out)
	}
	if _, err := os.Stat(plugin + "/readme.txt"); err != nil {
		t.Error("readme.txt did not come back")
	}
	if _, err := os.Stat(plugin + "/new-file.txt"); err == nil {
		t.Error("new-file.txt stayed")
	}

	if err := m.WordPressDetach(ctx, a); err != nil {
		t.Fatal(err)
	}
	// Nginx reloads in the background.
	code = 0
	for i := 0; i < 10 && code != 200; i++ {
		time.Sleep(300 * time.Millisecond)
		code, _, _ = fetch(t, "/readme.html", "")
	}
	if code != 200 {
		t.Errorf("readme.html after detach: HTTP %d", code)
	}
}

// fetch asks this server's Nginx for a path of the test site.
func fetch(t *testing.T, path, cookie string) (int, string, http.Header) {
	t.Helper()
	req, _ := http.NewRequest(http.MethodGet, "http://127.0.0.1"+path, nil)
	req.Host = wpRealDomain
	if cookie != "" {
		req.Header.Set("Cookie", cookie)
	}
	client := &http.Client{Timeout: 30 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	resp, err := client.Do(req)
	if err != nil {
		t.Fatal(err)
	}
	defer resp.Body.Close()
	b, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	return resp.StatusCode, string(b), resp.Header
}

func mysql(t *testing.T, sql string) {
	t.Helper()
	if out, err := exec.Command("mysql", "-uroot", "-e", sql).CombinedOutput(); err != nil {
		t.Fatalf("mysql: %v %s", err, out)
	}
}

func run(t *testing.T, name string, args ...string) {
	t.Helper()
	if out, err := exec.Command(name, args...).CombinedOutput(); err != nil {
		t.Fatalf("%s %v: %v %s", name, args, err, out)
	}
}
