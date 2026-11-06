//go:build realhost

// This test copies a real WordPress (WP-CLI, MySQL or MariaDB, Nginx,
// PHP-FPM) to a staging site the way Siteyi kopyala does, checks the copy
// (its address everywhere in the database, its own database, e-mail and
// WP-Cron held back), changes it and publishes it back onto the live
// site. It needs root and changes the machine:
//
//	sudo SERFEX_TEST_WP_TARBALL=/tmp/wordpress-6.8.3.tar.gz SERFEX_TEST_WP_VERSION=6.8.3 \
//	    go test -tags realhost -run TestRealHostWordPressCopy -timeout 15m ./internal/backup
//
// Without SERFEX_TEST_WP_TARBALL WordPress is downloaded from wordpress.org.
package backup

import (
	"context"
	"io"
	"net/http"
	"os"
	"os/exec"
	"strings"
	"testing"
	"time"

	"github.com/calltech-app/serfex/agent/internal/database"
	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/laravel"
	"github.com/calltech-app/serfex/agent/internal/site"
)

const (
	copyLive    = "kopya-canli.example"
	copyStaging = "staging.kopya-canli.example"
	copyUser    = "wpkopya"
	copyDB      = "wpkopya_wp"
	copyDBUser  = "wpkopya_app"
	copyDBPass  = "Kopya-parola-42"
	wpCLI       = "/opt/serfex/wp-cli/wp-cli.phar"
)

func TestRealHostWordPressCopy(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("needs root")
	}
	h := serviceHost{}
	ctx := context.Background()
	sites := site.NewManager(h, "www-data")
	versions, err := sites.PHPVersions()
	if err != nil || len(versions) == 0 {
		t.Fatalf("no PHP-FPM installed: %v", err)
	}
	php := versions[len(versions)-1]
	db := database.NewManager(h)
	product := database.MySQL
	if out, _ := exec.Command("mysqld", "--version").CombinedOutput(); strings.Contains(strings.ToLower(string(out)), "mariadb") {
		product = database.MariaDB
	}
	if _, err := db.Install(ctx, database.InstallSpec{Product: product}); err != nil {
		t.Fatal(err)
	}
	wpm := laravel.NewManager(h)
	// security.Manager.WriteSnippet in the agent.
	wpm.Snippet = func(ctx context.Context, domain, name string, data []byte) error {
		p := site.ExtraDir(domain) + "/" + name
		if data == nil {
			_ = os.Remove(p)
		} else if err := os.MkdirAll(site.ExtraDir(domain), 0o755); err != nil {
			return err
		} else if err := os.WriteFile(p, data, 0o644); err != nil {
			return err
		}
		_, err := h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"reload", "nginx"}})
		return err
	}
	m := NewManager(h, db, wpm)

	t.Cleanup(func() {
		ctx := context.Background()
		for _, d := range []string{copyStaging, copyLive} {
			_, _ = sites.Delete(ctx, site.DeleteSpec{Domain: d, User: copyUser, RemoveFiles: true, RemoveUser: d == copyLive})
		}
		_, _ = db.DeleteUser(ctx, database.UserRef{Server: database.MySQL, Name: copyDBUser})
		for _, n := range []string{copyDB, copyDB + "_copy"} {
			_, _ = db.DeleteDatabase(ctx, database.DeleteSpec{Server: database.MySQL, Name: n})
		}
		_ = os.RemoveAll("/var/lib/serfex/wordpress/" + copyLive)
	})
	for _, d := range []string{copyLive, copyStaging} {
		if _, err := sites.Apply(ctx, site.Spec{Domain: d, User: copyUser, PHPVersion: php}); err != nil {
			t.Fatal(err)
		}
	}
	for d, s := range map[string]string{copyDB: copyLive, copyDB + "_copy": copyStaging} {
		if _, err := db.CreateDatabase(ctx, database.DatabaseSpec{Server: database.MySQL, Name: d, Site: s}); err != nil {
			t.Fatal(err)
		}
	}
	if _, err := db.ApplyUser(ctx, database.UserSpec{Server: database.MySQL, Name: copyDBUser, Password: copyDBPass, Grants: []database.Grant{
		{Database: copyDB, Access: database.AccessAll}, {Database: copyDB + "_copy", Access: database.AccessAll},
	}}); err != nil {
		t.Fatal(err)
	}

	// WordPress on the live site, with its address in a post and in a
	// plugin's serialized setting.
	live := laravel.App{Target: laravel.Target{Domain: copyLive, User: copyUser, PHPVersion: php}, Path: "public_html"}
	spec := laravel.WPInstallSpec{
		App: live, Run: "install", URL: "http://" + copyLive, Title: "Kahve Dükkanı",
		AdminUser: "aylin", AdminEmail: "aylin@example.com", AdminPassword: "Yonetici-parola-42", Locale: "en_US", Harden: true,
		DB: laravel.WPDatabase{Name: copyDB, User: copyDBUser, Password: copyDBPass, Host: "localhost"},
	}
	if tarball := os.Getenv("SERFEX_TEST_WP_TARBALL"); tarball != "" {
		spec.Version = os.Getenv("SERFEX_TEST_WP_VERSION")
		cache := "/home/" + copyUser + "/.wp-cli/cache/core"
		run(t, "install", "-d", "-o", copyUser, "-g", copyUser, "/home/"+copyUser+"/.wp-cli", "/home/"+copyUser+"/.wp-cli/cache", cache)
		run(t, "install", "-o", copyUser, "-g", copyUser, "-m", "0644", tarball, cache+"/wordpress-"+spec.Version+"-en_US.tar.gz")
	}
	if _, err := wpm.InstallWordPress(ctx, spec); err != nil {
		logData, _ := wpm.ReadWordPressRun(copyLive, "install", 0)
		t.Fatalf("install: %v\n%s", err, logData.Data)
	}
	liveDir := "/home/" + copyUser + "/web/" + copyLive + "/public_html"
	stagingDir := "/home/" + copyUser + "/web/" + copyStaging + "/public_html"
	wp(t, liveDir, "post", "create", "--post_status=publish", "--post_title=Hakkımızda", "--post_content=<a href=\"http://"+copyLive+"/iletisim\">İletişim</a>")
	wp(t, liveDir, "option", "update", "serfex_test", `{"link":"http://`+copyLive+`/kampanya"}`, "--format=json")

	// The copy.
	res, err := m.Copy(ctx, CopySpec{
		Run: "kopya-1", Files: true,
		From:      CopySite{Domain: copyLive, User: copyUser, PHPVersion: php},
		To:        CopySite{Domain: copyStaging, User: copyUser, PHPVersion: php},
		Databases: []CopyDatabase{{Server: database.MySQL, From: copyDB, To: copyDB + "_copy"}},
		Hosts:     [][2]string{{copyLive, copyStaging}},
		WordPress: "public_html", Public: "0",
		Staging: &laravel.WPStaging{Mail: true, Cron: true},
	})
	if err != nil {
		logData, _ := m.ReadRun("kopya-1", 0)
		t.Fatalf("copy: %v\n%s", err, logData.Data)
	}
	logData, _ := m.ReadRun("kopya-1", 0)
	t.Logf("copied %d bytes in %d ms, notes %v\n%s", res.Bytes, res.Millis, res.Notes, logData.Data)

	if got := wp(t, stagingDir, "option", "get", "home"); got != "http://"+copyStaging {
		t.Errorf("staging home = %q", got)
	}
	if got := wp(t, stagingDir, "option", "get", "blog_public"); got != "0" {
		t.Errorf("staging blog_public = %q", got)
	}
	if got := wp(t, stagingDir, "config", "get", "DB_NAME"); got != copyDB+"_copy" {
		t.Errorf("staging DB_NAME = %q", got)
	}
	if got := wp(t, stagingDir, "post", "list", "--name=hakkimizda", "--field=post_content"); !strings.Contains(got, "http://"+copyStaging+"/iletisim") {
		t.Errorf("staging post = %q", got)
	}
	if got := wp(t, stagingDir, "option", "get", "serfex_test", "--format=json"); !strings.Contains(got, copyStaging+`\/kampanya`) && !strings.Contains(got, copyStaging+"/kampanya") {
		t.Errorf("staging serialized option = %q", got)
	}
	if got := wp(t, liveDir, "option", "get", "home"); got != "http://"+copyLive {
		t.Errorf("the live home changed: %q", got)
	}

	// On the copy no e-mail leaves and no scheduled event is ready; the
	// live site is untouched.
	const mailProbe = `var_dump(wp_mail("musteri@example.com", "Siparişiniz", "Teşekkürler"));`
	const cronProbe = `wp_schedule_single_event(time() - 60, "serfex_test_hook"); echo count(wp_get_ready_cron_jobs());`
	if got := wpAll(t, stagingDir, "eval", mailProbe); !strings.Contains(got, "bool(true)") || !strings.Contains(got, "e-mail not sent to musteri@example.com") {
		t.Errorf("staging wp_mail: %q", got)
	}
	// A plugin of its own wp_mail() still ends at PHPMailer without recipients.
	if got := wpAll(t, stagingDir, "eval", `remove_all_filters("pre_wp_mail"); var_dump(wp_mail("musteri@example.com", "x", "y"));`); !strings.Contains(got, "bool(false)") {
		t.Errorf("staging wp_mail through PHPMailer: %q", got)
	}
	if got := wp(t, stagingDir, "eval", cronProbe); got != "0" {
		t.Errorf("staging ready cron jobs = %q", got)
	}
	if got := wp(t, liveDir, "eval", cronProbe); got == "0" {
		t.Errorf("the live site's cron is held back too")
	}
	if got := wpAll(t, liveDir, "eval", mailProbe); strings.Contains(got, "e-mail not sent") {
		t.Errorf("the live site's mail is held back: %q", got)
	}
	// The dashboard says so.
	if got := wpAll(t, stagingDir, "eval", `wp_set_current_user(1); do_action("admin_notices");`); !strings.Contains(got, "This is a staging copy") {
		t.Errorf("admin notice: %q", got)
	}
	// The staging site answers on its own name through Nginx.
	if code, body := get(t, copyStaging, "/"); code != 200 || !strings.Contains(body, "http://"+copyStaging) || strings.Contains(body, "http://"+copyLive+"/") {
		t.Errorf("staging home page: %d %.300s", code, body)
	}

	// A change on the copy, published onto the live site.
	wp(t, stagingDir, "option", "update", "blogname", "Kahve Dükkanı (yeni)")
	if _, err := m.Copy(ctx, CopySpec{
		Run: "aktar-1", Files: true,
		From:      CopySite{Domain: copyStaging, User: copyUser, PHPVersion: php},
		To:        CopySite{Domain: copyLive, User: copyUser, PHPVersion: php},
		Databases: []CopyDatabase{{Server: database.MySQL, From: copyDB + "_copy", To: copyDB}},
		Hosts:     [][2]string{{copyStaging, copyLive}},
		WordPress: "public_html", Public: "keep",
	}); err != nil {
		logData, _ := m.ReadRun("aktar-1", 0)
		t.Fatalf("publish: %v\n%s", err, logData.Data)
	}
	if got := wp(t, liveDir, "option", "get", "blogname"); got != "Kahve Dükkanı (yeni)" {
		t.Errorf("live blogname = %q", got)
	}
	if got := wp(t, liveDir, "option", "get", "home"); got != "http://"+copyLive {
		t.Errorf("live home = %q", got)
	}
	if got := wp(t, liveDir, "option", "get", "blog_public"); got != "1" {
		t.Errorf("live blog_public = %q", got)
	}
	if got := wp(t, liveDir, "config", "get", "DB_NAME"); got != copyDB {
		t.Errorf("live DB_NAME = %q", got)
	}
	if got := wp(t, liveDir, "post", "list", "--name=hakkimizda", "--field=post_content"); !strings.Contains(got, "http://"+copyLive+"/iletisim") || strings.Contains(got, copyStaging) {
		t.Errorf("live post = %q", got)
	}
	if _, err := os.Stat(liveDir + "/wp-content/mu-plugins/serfex-staging.php"); !os.IsNotExist(err) {
		t.Errorf("the staging plugin reached the live site: %v", err)
	}
	if got := wp(t, liveDir, "eval", cronProbe); got == "0" {
		t.Error("the live site's cron is held back after the publish")
	}
	if code, body := get(t, copyLive, "/"); code != 200 || !strings.Contains(body, "Kahve Dükkanı (yeni)") {
		t.Errorf("live home page: %d %.300s", code, body)
	}
}

// wp runs WP-CLI as the account and returns its trimmed standard output.
func wp(t *testing.T, dir string, args ...string) string {
	t.Helper()
	cmd := exec.Command("runuser", append([]string{"-u", copyUser, "--", "php", wpCLI, "--path=" + dir, "--skip-themes"}, args...)...)
	out, err := cmd.Output()
	if err != nil {
		var stderr []byte
		if ee, ok := err.(*exec.ExitError); ok {
			stderr = ee.Stderr
		}
		t.Fatalf("wp %s: %v\n%s%s", strings.Join(args, " "), err, out, stderr)
	}
	return strings.TrimSpace(string(out))
}

// wpAll is wp with standard error too (PHP's error_log goes there).
func wpAll(t *testing.T, dir string, args ...string) string {
	t.Helper()
	out, _ := exec.Command("runuser", append([]string{"-u", copyUser, "--", "php", wpCLI, "--path=" + dir, "--skip-themes"}, args...)...).CombinedOutput()
	return string(out)
}

// get asks this server's Nginx for a path of a site.
func get(t *testing.T, host, path string) (int, string) {
	t.Helper()
	req, _ := http.NewRequest(http.MethodGet, "http://127.0.0.1"+path, nil)
	req.Host = host
	client := &http.Client{Timeout: 30 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	resp, err := client.Do(req)
	if err != nil {
		t.Fatal(err)
	}
	defer resp.Body.Close()
	b, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	return resp.StatusCode, string(b)
}
