package laravel

import (
	"context"
	"encoding/json"
	"errors"
	"os"
	"strings"
	"testing"

	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/site"
)

const wpDir = "/home/hizlikargo/web/api.hizlikargo.app/public_html"

var wpApp = App{Target: target, Path: "public_html"}

// newWPTest is newTest with PHP 8.4's command line and a Snippet writer
// that keeps the site's Nginx snippets in ExtraDir.
func newWPTest(t *testing.T) (*Manager, *host.Simulator) {
	t.Helper()
	m, sim := newTest(t)
	if err := os.MkdirAll(sim.Path("/usr/bin"), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(sim.Path("/usr/bin/php8.4"), nil, 0o755); err != nil {
		t.Fatal(err)
	}
	m.Snippet = func(_ context.Context, domain, name string, data []byte) error {
		p := sim.Path(site.ExtraDir(domain) + "/" + name)
		if data == nil {
			_ = os.Remove(p)
			return nil
		}
		if err := os.MkdirAll(sim.Path(site.ExtraDir(domain)), 0o755); err != nil {
			return err
		}
		return os.WriteFile(p, data, 0o644)
	}
	return m, sim
}

func installSpec(run string) WPInstallSpec {
	return WPInstallSpec{
		App: wpApp, Run: run, URL: "https://api.hizlikargo.app", Title: "Hızlı Kargo", AdminUser: "aylin",
		AdminEmail: "aylin@hizlikargo.app", AdminPassword: "Gizli-parola-123", Locale: "tr_TR", Harden: true,
		DB: WPDatabase{Name: "hizlikargo_wp", User: "hizlikargo_wp", Password: "db-parola-xyz", Host: "localhost"},
	}
}

func TestInstallWordPressIntoTheWebRoot(t *testing.T) {
	m, sim := newWPTest(t)
	ctx := context.Background()

	res, err := m.InstallWordPress(ctx, installSpec("wp1"))
	if err != nil {
		t.Fatal(err)
	}
	if res.Version != "6.8.3" {
		t.Fatalf("version %q", res.Version)
	}
	// The default page made way; WordPress, its settings and the sign-in
	// plugin are there.
	if exists(sim, wpDir+"/index.html") || !exists(sim, wpDir+"/wp-includes/version.php") || !exists(sim, wpDir+"/"+wpLoginPlugin) {
		t.Fatal("WordPress files missing")
	}
	config := read(t, sim, wpDir+"/wp-config.php")
	for _, want := range []string{"'DB_NAME', 'hizlikargo_wp'", "'DB_PASSWORD', 'db-parola-xyz'", "'DISALLOW_FILE_EDIT', true"} {
		if !strings.Contains(config, want) {
			t.Errorf("wp-config.php lacks %s:\n%s", want, config)
		}
	}
	if fi, _ := os.Stat(sim.Path(wpDir + "/wp-config.php")); fi.Mode().Perm() != 0o600 {
		t.Errorf("wp-config.php mode %v", fi.Mode().Perm())
	}
	snippet := read(t, sim, site.ExtraDir(target.Domain)+"/"+wpSnippet)
	if !strings.Contains(snippet, "# rule: uploads_php") || !strings.Contains(snippet, "# rule: sensitive_files") || strings.Contains(snippet, "# rule: xmlrpc") {
		t.Errorf("snippet:\n%s", snippet)
	}
	// Passwords reach WP-CLI on its standard input, never as arguments.
	for _, c := range sim.Commands() {
		if s := c.String(); strings.Contains(s, "db-parola-xyz") || strings.Contains(s, "Gizli-parola-123") {
			t.Errorf("password on a command line: %s", s)
		}
	}
	all := commands(sim)
	for _, want := range []string{
		"runuser -u hizlikargo -- env -C " + wpDir,
		"/opt/serfex/wp-cli/wp-cli.phar --path=" + wpDir + " --no-color core download --locale=tr_TR",
		"core install --url=https://api.hizlikargo.app --title=Hızlı Kargo --admin_user=aylin --admin_email=aylin@hizlikargo.app --skip-email --prompt=admin_password",
	} {
		if !strings.Contains(all, want) {
			t.Errorf("no command with %q in:\n%s", want, all)
		}
	}
	log, _ := m.ReadWordPressRun(target.Domain, "wp1", 0)
	if !strings.Contains(log.Data, "✓ WordPress 6.8.3 kuruldu.") {
		t.Errorf("log:\n%s", log.Data)
	}

	// A second install refuses the folder now that it is not empty.
	_, err = m.InstallWordPress(ctx, installSpec("wp2"))
	if err == nil || !strings.Contains(err.Error(), "public_html is not empty") {
		t.Fatalf("second install: %v", err)
	}
	var v *ValidationError
	if !errors.As(err, &v) {
		t.Fatalf("not a validation error: %T", err)
	}
}

func TestFailedInstallPutsTheFolderBack(t *testing.T) {
	m, sim := newWPTest(t)
	page := read(t, sim, wpDir+"/index.html")
	sim.Fail = func(c host.Command) error {
		if strings.Contains(c.String(), "core install") {
			return errors.New("Error: Error establishing a database connection.")
		}
		return nil
	}
	_, err := m.InstallWordPress(context.Background(), installSpec("wp1"))
	if err == nil || !strings.Contains(err.Error(), "core install") {
		t.Fatalf("err %v", err)
	}
	if exists(sim, wpDir+"/wp-includes") || exists(sim, wpDir+"/wp-config.php") || read(t, sim, wpDir+"/index.html") != page {
		t.Fatal("the folder was not put back")
	}
	log, _ := m.ReadWordPressRun(target.Domain, "wp1", 0)
	if !strings.Contains(log.Data, "Kurulan dosyalar kaldırılıyor") || !strings.Contains(log.Data, "✗ wp core install") {
		t.Errorf("log:\n%s", log.Data)
	}
}

func TestWordPressStatusScanLoginAndMaintenance(t *testing.T) {
	m, sim := newWPTest(t)
	ctx := context.Background()
	if _, err := m.InstallWordPress(ctx, installSpec("wp1")); err != nil {
		t.Fatal(err)
	}

	found, err := m.ScanWordPress(ctx, target, "public_html")
	if err != nil || len(found) != 1 || found[0] != (WPFound{Path: "public_html", Version: "6.8.3"}) {
		t.Fatalf("scan: %+v %v", found, err)
	}

	st, err := m.WordPressStatus(ctx, wpApp)
	if err != nil {
		t.Fatal(err)
	}
	if st.Version != "6.8.3" || st.CoreUpdate != "6.9" || st.SiteURL != "https://api.hizlikargo.app" || st.Database != "hizlikargo_wp" || st.Problem != "" {
		t.Fatalf("status %+v", st)
	}
	if len(st.Plugins) != 2 || st.Plugins[0].Name != "akismet" || st.Plugins[0].Update != "5.6" || len(st.Themes) != 2 || st.Themes[0].Status != "active" {
		t.Fatalf("items %+v %+v", st.Plugins, st.Themes)
	}
	if len(st.Admins) != 1 || st.Admins[0] != "aylin" || st.AutoUpdateCore != "minor" {
		t.Fatalf("admins %v auto %s", st.Admins, st.AutoUpdateCore)
	}
	if !st.Hardening["file_edit"] || !st.Hardening["config_perms"] || !st.Hardening["uploads_php"] || st.Hardening["xmlrpc"] {
		t.Fatalf("hardening %v", st.Hardening)
	}
	// The password of the database is not passed on.
	if b, _ := json.Marshal(st); strings.Contains(string(b), "db-parola-xyz") {
		t.Fatal("status carries the database password")
	}

	login, err := m.WordPressLogin(ctx, WPLoginSpec{App: wpApp})
	if err != nil {
		t.Fatal(err)
	}
	token, ok := strings.CutPrefix(login.URL, "https://api.hizlikargo.app/wp-login.php?serfex_login=")
	if !ok || len(token) != 64 || login.User != "aylin" {
		t.Fatalf("login %+v", login)
	}
	var state host.SimWP
	_ = json.Unmarshal([]byte(read(t, sim, wpDir+"/"+host.SimWPState)), &state)
	if saved := state.Options["serfex_login"]; strings.Contains(saved, token) || !strings.Contains(saved, `"hash"`) {
		t.Fatalf("saved %s", saved)
	}
	if _, err := m.WordPressLogin(ctx, WPLoginSpec{App: wpApp, User: "nobody"}); err == nil {
		t.Fatal("a login for an unknown administrator")
	}

	if _, err := m.WordPressMaintenance(ctx, WPMaintenanceSpec{App: wpApp, On: true}); err != nil || !exists(sim, wpDir+"/.maintenance") {
		t.Fatalf("maintenance on: %v", err)
	}
	if st, _ := m.WordPressStatus(ctx, wpApp); !st.Maintenance {
		t.Fatal("status does not show maintenance")
	}
	if _, err := m.WordPressMaintenance(ctx, WPMaintenanceSpec{App: wpApp, On: false}); err != nil || exists(sim, wpDir+"/.maintenance") {
		t.Fatalf("maintenance off: %v", err)
	}

	item, err := m.WordPressItem(ctx, WPItemSpec{App: wpApp, Kind: "plugin", Name: "akismet", Action: "activate"})
	if err != nil || item.Status != "active" {
		t.Fatalf("activate: %+v %v", item, err)
	}
	if _, err := m.WordPressItem(ctx, WPItemSpec{App: wpApp, Kind: "theme", Name: "x", Action: "deactivate"}); err == nil {
		t.Fatal("themes cannot be deactivated")
	}

	if st, err := m.WordPressAutoUpdate(ctx, WPAutoUpdateSpec{App: wpApp, Core: "all"}); err != nil || st.AutoUpdateCore != "all" {
		t.Fatalf("auto update: %v", err)
	}
	if !strings.Contains(read(t, sim, wpDir+"/wp-config.php"), "'WP_AUTO_UPDATE_CORE', true") {
		t.Fatal("WP_AUTO_UPDATE_CORE not written")
	}
}

func TestBrokenPluginIsReportedAndCanBeTurnedOff(t *testing.T) {
	m, sim := newWPTest(t)
	ctx := context.Background()
	if _, err := m.InstallWordPress(ctx, installSpec("wp1")); err != nil {
		t.Fatal(err)
	}
	var state host.SimWP
	_ = json.Unmarshal([]byte(read(t, sim, wpDir+"/"+host.SimWPState)), &state)
	state.Broken = "PHP Fatal error:  Uncaught Error: Call to undefined function wc_get_product() in wp-content/plugins/akismet/akismet.php:12"
	b, _ := json.Marshal(state)
	if err := os.WriteFile(sim.Path(wpDir+"/"+host.SimWPState), b, 0o644); err != nil {
		t.Fatal(err)
	}
	st, err := m.WordPressStatus(ctx, wpApp)
	if err != nil || !strings.Contains(st.Problem, "Call to undefined function") || len(st.Plugins) != 2 {
		t.Fatalf("status %+v %v", st, err)
	}
	// Deactivating works without loading the plugins.
	if _, err := m.WordPressItem(ctx, WPItemSpec{App: wpApp, Kind: "plugin", Name: "akismet", Action: "deactivate"}); err != nil {
		t.Fatal(err)
	}
}

func TestHardeningRulesComeAndGo(t *testing.T) {
	m, sim := newWPTest(t)
	ctx := context.Background()
	spec := installSpec("wp1")
	spec.Harden = false
	if _, err := m.InstallWordPress(ctx, spec); err != nil {
		t.Fatal(err)
	}
	if exists(sim, site.ExtraDir(target.Domain)+"/"+wpSnippet) {
		t.Fatal("snippet without hardening")
	}
	st, err := m.WordPressHarden(ctx, WPHardenSpec{App: wpApp, WebPath: "/", Rules: map[string]bool{"xmlrpc": true, "uploads_php": true}})
	if err != nil || !st.Hardening["xmlrpc"] || !st.Hardening["uploads_php"] || st.Hardening["file_edit"] {
		t.Fatalf("harden: %v %v", st.Hardening, err)
	}
	snippet := read(t, sim, site.ExtraDir(target.Domain)+"/"+wpSnippet)
	for _, want := range []string{"location = /xmlrpc.php {\n    deny all;\n}", `location ~* "^/wp-content/uploads/.*\.(php[0-9]?|phtml|phar)$"`} {
		if !strings.Contains(snippet, want) {
			t.Errorf("snippet lacks %s:\n%s", want, snippet)
		}
	}
	if _, err := m.WordPressHarden(ctx, WPHardenSpec{App: wpApp, WebPath: "/", Rules: map[string]bool{"xmlrpc": false, "uploads_php": false}}); err != nil {
		t.Fatal(err)
	}
	if exists(sim, site.ExtraDir(target.Domain)+"/"+wpSnippet) {
		t.Fatal("the snippet stayed with no rule on")
	}
	// A WordPress in a folder of the site gets rules for its address.
	if got := string(wpNginxConf("a.com", "/blog/", map[string]bool{"sensitive_files": true})); !strings.Contains(got, `"^/blog/(wp-config\.php|`) {
		t.Errorf("folder rules:\n%s", got)
	}
	for _, bad := range []WPHardenSpec{
		{App: wpApp, WebPath: "/../", Rules: map[string]bool{"xmlrpc": true}},
		{App: wpApp, WebPath: "/", Rules: map[string]bool{"everything": true}},
	} {
		if _, err := m.WordPressHarden(ctx, bad); err == nil {
			t.Errorf("accepted %+v", bad)
		}
	}

	if err := m.WordPressDetach(ctx, wpApp); err != nil || exists(sim, wpDir+"/"+wpLoginPlugin) || !exists(sim, wpDir+"/wp-config.php") {
		t.Fatalf("detach: %v", err)
	}
}

func TestUpdateTakesARestorePointAndRestores(t *testing.T) {
	m, sim := newWPTest(t)
	ctx := context.Background()
	if _, err := m.InstallWordPress(ctx, installSpec("wp1")); err != nil {
		t.Fatal(err)
	}
	res, err := m.UpdateWordPress(ctx, WPUpdateSpec{App: wpApp, Run: "up1", Core: true, Plugins: []string{"*"}, Check: "/"})
	if err != nil {
		t.Fatal(err)
	}
	want := []WPChange{{"core", "wordpress", "6.8.3", "6.9"}, {"plugin", "akismet", "5.5", "5.6"}}
	if res.Point != "up1" || res.RolledBack || res.Problem != "" || len(res.Changes) != 2 || res.Changes[0] != want[0] || res.Changes[1] != want[1] {
		t.Fatalf("update %+v", res)
	}
	point := wpPointDir(target.Domain) + "/up1"
	var meta wpPointMeta
	if err := json.Unmarshal([]byte(read(t, sim, point+"/meta.json")), &meta); err != nil {
		t.Fatal(err)
	}
	for _, p := range []string{"wp-admin", "wp-includes", "wp-content/plugins/akismet", "index.php", "xmlrpc.php"} {
		if !contains(meta.Paths, p) {
			t.Errorf("restore point lacks %s: %v", p, meta.Paths)
		}
	}
	if contains(meta.Paths, "wp-config.php") || !exists(sim, point+"/database.sql.gz") || !exists(sim, point+"/files.tar.gz") {
		t.Fatalf("restore point %v", meta.Paths)
	}
	if v := read(t, sim, wpDir+"/wp-includes/version.php"); !strings.Contains(v, "'6.9'") {
		t.Fatal("core not updated")
	}

	// Nothing left to update: no restore point.
	if res, err := m.UpdateWordPress(ctx, WPUpdateSpec{App: wpApp, Run: "up2", Core: true, Plugins: []string{"*"}, Check: "/"}); err != nil || res.Point != "" {
		t.Fatalf("empty update %+v %v", res, err)
	}

	// Back to before the update: the files of 6.8.3 return.
	if err := m.RestoreWordPress(ctx, WPRestoreSpec{App: wpApp, Run: "re1", Point: "up1"}); err != nil {
		t.Fatal(err)
	}
	if v := read(t, sim, wpDir+"/wp-includes/version.php"); !strings.Contains(v, "'6.8.3'") {
		t.Fatalf("version after restore: %s", v)
	}
	if !strings.Contains(commands(sim), "--skip-plugins --skip-themes db import -") {
		t.Error("the database was not imported")
	}
	if points, _ := m.WordPressPoints(target.Domain); len(points) != 1 || points[0] != "up1" {
		t.Fatalf("points %v", points)
	}
	if err := m.RestoreWordPress(ctx, WPRestoreSpec{App: wpApp, Run: "re2", Point: "nope"}); err == nil {
		t.Fatal("restored a point that does not exist")
	}
}

func TestUpdateThatBreaksTheSiteIsUndone(t *testing.T) {
	m, sim := newWPTest(t)
	ctx := context.Background()
	if _, err := m.InstallWordPress(ctx, installSpec("wp1")); err != nil {
		t.Fatal(err)
	}
	calls := 0
	var hosts []string
	m.Health = func(_ context.Context, host, _ string) HealthResult {
		calls++
		hosts = append(hosts, host)
		if calls == 1 {
			return HealthResult{OK: true, Status: 200}
		}
		return HealthResult{Status: 500}
	}
	// The site sends visitors to its www. name: the checks ask for it.
	res, err := m.UpdateWordPress(ctx, WPUpdateSpec{App: wpApp, Run: "up1", Plugins: []string{"akismet"}, Check: "/", Host: "www." + target.Domain})
	if err != nil {
		t.Fatal(err)
	}
	if len(hosts) != 2 || hosts[0] != "www."+target.Domain || hosts[1] != "www."+target.Domain {
		t.Errorf("checked %v", hosts)
	}
	if !res.RolledBack || len(res.Changes) != 0 || !strings.Contains(res.Problem, "HTTP 500") {
		t.Fatalf("update %+v", res)
	}
	var state host.SimWP
	_ = json.Unmarshal([]byte(read(t, sim, wpDir+"/"+host.SimWPState)), &state)
	log, _ := m.ReadWordPressRun(target.Domain, "up1", 0)
	if !strings.Contains(log.Data, "güncelleme öncesine dönülüyor") || !strings.Contains(log.Data, "Veritabanı güncelleme öncesine döndürülüyor") {
		t.Errorf("log:\n%s", log.Data)
	}
}

func TestWordPressValidation(t *testing.T) {
	m, _ := newWPTest(t)
	ctx := context.Background()
	for name, mod := range map[string]func(*WPInstallSpec){
		"url":      func(s *WPInstallSpec) { s.URL = "https://a.com/blog?x=1" },
		"user":     func(s *WPInstallSpec) { s.AdminUser = "a;b" },
		"email":    func(s *WPInstallSpec) { s.AdminEmail = "nope" },
		"password": func(s *WPInstallSpec) { s.AdminPassword = "short" },
		"locale":   func(s *WPInstallSpec) { s.Locale = "../x" },
		"db":       func(s *WPInstallSpec) { s.DB.Name = "x; drop" },
		"path":     func(s *WPInstallSpec) { s.Path = "../other" },
	} {
		spec := installSpec("v1")
		mod(&spec)
		if _, err := m.InstallWordPress(ctx, spec); err == nil {
			t.Errorf("%s: accepted", name)
		}
	}
	if _, err := m.WordPressStatus(ctx, wpApp); err == nil || !strings.Contains(err.Error(), "no WordPress") {
		t.Errorf("status without WordPress: %v", err)
	}
	if _, err := m.UpdateWordPress(ctx, WPUpdateSpec{App: wpApp, Run: "u", Plugins: []string{"../x"}, Check: "/"}); err == nil {
		t.Error("bad plugin name accepted")
	}
	if _, err := m.UpdateWordPress(ctx, WPUpdateSpec{App: wpApp, Run: "u", Core: true, Check: "/", Host: "evil.example"}); err == nil {
		t.Error("a host outside the site accepted")
	}
}
