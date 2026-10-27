package security

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/calltech-app/serfex/agent/internal/component"
	"github.com/calltech-app/serfex/agent/internal/files"
	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/site"
)

const domain = "kahve.com"

func newTest(t *testing.T) (*Manager, *host.Simulator) {
	t.Helper()
	sim, err := host.NewSimulator(t.TempDir(), "8.4")
	if err != nil {
		t.Fatal(err)
	}
	sites := site.NewManager(sim, "www-data")
	if _, err := sites.Apply(context.Background(), site.Spec{Domain: domain, User: "kahve", PHPVersion: "8.4"}); err != nil {
		t.Fatal(err)
	}
	m := NewManager(sim, files.NewManager(sim, "servik-agent"))
	m.Now = func() time.Time { return time.Date(2026, 10, 19, 12, 0, 0, 0, time.UTC) }
	return m, sim
}

func write(t *testing.T, sim *host.Simulator, abs, content string, mode os.FileMode) {
	t.Helper()
	p := sim.Path(abs)
	if err := os.MkdirAll(filepath.Dir(p), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(p, []byte(content), mode); err != nil {
		t.Fatal(err)
	}
	if err := os.Chmod(p, mode); err != nil {
		t.Fatal(err)
	}
}

func read(t *testing.T, sim *host.Simulator, abs string) string {
	t.Helper()
	b, err := os.ReadFile(sim.Path(abs))
	if err != nil {
		t.Fatal(err)
	}
	return string(b)
}

func installWAF(t *testing.T, sim *host.Simulator) {
	write(t, sim, "/usr/lib/nginx/modules/ngx_http_modsecurity_module.so", "", 0o644)
	write(t, sim, "/etc/nginx/conf.d/servik-modsecurity.conf", "# Managed by Servik\n", 0o644)
}

func TestVhostIncludesTheSiteSnippets(t *testing.T) {
	_, sim := newTest(t)
	if conf := read(t, sim, "/etc/nginx/sites-available/kahve.com.conf"); !strings.Contains(conf, "include /etc/servik/nginx/sites/kahve.com/*.conf;") {
		t.Fatalf("vhost lacks the include:\n%s", conf)
	}
}

func TestWAFModes(t *testing.T) {
	m, sim := newTest(t)
	ctx := context.Background()
	if _, err := m.ApplyWAF(ctx, WAFSpec{Domain: domain, Mode: ModeBlock}); !errors.Is(err, ErrWAFNotInstalled) {
		t.Fatalf("without ModSecurity: %v", err)
	}
	// Following the server with nothing excluded needs no files at all.
	if _, err := m.ApplyWAF(ctx, WAFSpec{Domain: domain}); err != nil {
		t.Fatal(err)
	}
	installWAF(t, sim)
	res, err := m.ApplyWAF(ctx, WAFSpec{Domain: domain, Mode: ModeBlock, Exclusions: []Exclusion{{RuleID: 942100, Path: "/wp-admin/"}, {RuleID: 920350}}})
	if err != nil || !res.Changed {
		t.Fatalf("block: %+v %v", res, err)
	}
	conf := read(t, sim, "/etc/servik/nginx/sites/kahve.com/waf.conf")
	rules := read(t, sim, "/etc/servik/nginx/sites/kahve.com/waf.rules")
	if !strings.Contains(conf, "modsecurity on;") || !strings.Contains(conf, "modsecurity_rules_file /etc/servik/nginx/sites/kahve.com/waf.rules;") {
		t.Fatalf("waf.conf:\n%s", conf)
	}
	for _, want := range []string{"SecRuleEngine On", `SecAction "id:10001,phase:1,pass,nolog,ctl:ruleRemoveById=920350"`,
		`SecRule REQUEST_FILENAME "@beginsWith /wp-admin/" "id:10002,phase:1,pass,nolog,ctl:ruleRemoveById=942100"`} {
		if !strings.Contains(rules, want) {
			t.Fatalf("waf.rules lacks %q:\n%s", want, rules)
		}
	}
	if !sim.Ran("nginx -t") || !sim.Ran("systemctl reload nginx") {
		t.Fatal("nginx was not checked and reloaded")
	}

	if _, err := m.ApplyWAF(ctx, WAFSpec{Domain: domain, Mode: ModeOff}); err != nil {
		t.Fatal(err)
	}
	if conf := read(t, sim, "/etc/servik/nginx/sites/kahve.com/waf.conf"); !strings.Contains(conf, "modsecurity off;") {
		t.Fatalf("off:\n%s", conf)
	}
	if _, err := os.Stat(sim.Path("/etc/servik/nginx/sites/kahve.com/waf.rules")); !os.IsNotExist(err) {
		t.Fatal("off keeps a rules file")
	}

	// Nginx refusing the change leaves the files as they were.
	sim.Fail = func(c host.Command) error {
		if c.String() == "nginx -t" {
			return errors.New("unknown directive")
		}
		return nil
	}
	if _, err := m.ApplyWAF(ctx, WAFSpec{Domain: domain, Mode: ModeDetect}); err == nil {
		t.Fatal("a refused configuration was applied")
	}
	if conf := read(t, sim, "/etc/servik/nginx/sites/kahve.com/waf.conf"); !strings.Contains(conf, "modsecurity off;") {
		t.Fatalf("not rolled back:\n%s", conf)
	}
	sim.Fail = nil

	if _, err := m.ApplyWAF(ctx, WAFSpec{Domain: domain}); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(sim.Path("/etc/servik/nginx/sites/kahve.com/waf.conf")); !os.IsNotExist(err) {
		t.Fatal("server mode keeps a waf.conf")
	}
	for _, bad := range []WAFSpec{{Domain: domain, Mode: "on"}, {Domain: domain, Exclusions: []Exclusion{{RuleID: 0}}},
		{Domain: domain, Exclusions: []Exclusion{{RuleID: 1, Path: `/a" "b`}}}, {Domain: domain, Exclusions: []Exclusion{{RuleID: 1, Path: "/a/../etc"}}}, {Domain: "nope.com"}} {
		if _, err := m.ApplyWAF(ctx, bad); err == nil {
			t.Fatalf("%+v was accepted", bad)
		}
	}
}

func TestWAFEvents(t *testing.T) {
	m, sim := newTest(t)
	installWAF(t, sim)
	data, err := os.ReadFile("testdata/audit.log")
	if err != nil {
		t.Fatal(err)
	}
	write(t, sim, component.ModSecurityLog, string(data)+"not json\n", 0o600)
	ev, err := m.WAFEvents(EventsSpec{})
	if err != nil || !ev.Installed {
		t.Fatalf("%+v %v", ev, err)
	}
	// The 401 of a protected folder involved no rule and is left out.
	if len(ev.Events) != 2 {
		t.Fatalf("events = %+v", ev.Events)
	}
	newest, oldest := ev.Events[0], ev.Events[1]
	if newest.Host != "b.test" || newest.Blocked || newest.Status != 200 {
		t.Fatalf("detected event = %+v", newest)
	}
	if oldest.Host != "a.test" || !oldest.Blocked || oldest.Score != 5 || len(oldest.Rules) != 1 || oldest.Rules[0].ID != 942100 ||
		oldest.IP != "127.0.0.1" || oldest.Method != "GET" || !strings.HasPrefix(oldest.URI, "/?id=1") || oldest.Agent != "curl/8.5.0" {
		t.Fatalf("blocked event = %+v", oldest)
	}
	if ev, _ := m.WAFEvents(EventsSpec{Hosts: []string{"A.test"}}); len(ev.Events) != 1 || ev.Events[0].Host != "a.test" {
		t.Fatalf("host filter: %+v", ev.Events)
	}
	if ev, _ := m.WAFEvents(EventsSpec{Since: oldest.Time}); len(ev.Events) != 0 && ev.Events[0].ID == oldest.ID {
		t.Fatalf("since filter: %+v", ev.Events)
	}
}

func TestProtectAndDeny(t *testing.T) {
	m, sim := newTest(t)
	ctx := context.Background()
	hash := "$6$saltsalt$" + strings.Repeat("a", 86)
	_, err := m.ApplyProtect(ctx, ProtectSpec{Domain: domain, Dirs: []ProtectedDir{
		{Path: "/admin/", Realm: "Yönetim", Users: []Login{{Name: "ali", Hash: hash}}},
		{Path: "/admin/deep/", Users: []Login{{Name: "veli", Hash: hash}}},
	}})
	if err != nil {
		t.Fatal(err)
	}
	conf := read(t, sim, "/etc/servik/nginx/sites/kahve.com/protect.conf")
	i, j := strings.Index(conf, `^/admin/"`), strings.Index(conf, `^/admin/deep/"`)
	if i < 0 || j < i || !strings.Contains(conf, "auth_basic $servik_auth;") || !strings.Contains(conf, "acme-challenge") {
		t.Fatalf("protect.conf:\n%s", conf)
	}
	if pw := read(t, sim, "/etc/servik/nginx/sites/kahve.com/protect-1.htpasswd"); !strings.Contains(pw, "ali:"+hash) {
		t.Fatalf("htpasswd:\n%s", pw)
	}
	if fi, _ := os.Stat(sim.Path("/etc/servik/nginx/sites/kahve.com/protect-1.htpasswd")); fi.Mode().Perm() != 0o640 {
		t.Fatalf("htpasswd mode %v", fi.Mode())
	}
	// One folder left: its file is renumbered, the other goes.
	if _, err := m.ApplyProtect(ctx, ProtectSpec{Domain: domain, Dirs: []ProtectedDir{{Path: "/admin/deep/", Users: []Login{{Name: "veli", Hash: hash}}}}}); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(sim.Path("/etc/servik/nginx/sites/kahve.com/protect-2.htpasswd")); !os.IsNotExist(err) {
		t.Fatal("stale password file kept")
	}
	if _, err := m.ApplyProtect(ctx, ProtectSpec{Domain: domain}); err != nil {
		t.Fatal(err)
	}
	if entries, _ := os.ReadDir(sim.Path("/etc/servik/nginx/sites/kahve.com")); len(entries) != 0 {
		t.Fatalf("files left: %v", entries)
	}
	for _, bad := range []ProtectSpec{
		{Domain: domain, Dirs: []ProtectedDir{{Path: "/admin", Users: []Login{{Name: "a", Hash: hash}}}}},
		{Domain: domain, Dirs: []ProtectedDir{{Path: "/admin/", Users: []Login{{Name: "a", Hash: "plain"}}}}},
		{Domain: domain, Dirs: []ProtectedDir{{Path: "/admin/", Realm: `x"; deny all; #`, Users: []Login{{Name: "a", Hash: hash}}}}},
		{Domain: domain, Dirs: []ProtectedDir{{Path: "/admin/"}}},
	} {
		if _, err := m.ApplyProtect(ctx, bad); err == nil {
			t.Fatalf("%+v accepted", bad)
		}
	}

	if _, err := m.ApplyDeny(ctx, DenySpec{Domain: domain, Paths: []string{"/backup.sql", "/old/"}}); err != nil {
		t.Fatal(err)
	}
	deny := read(t, sim, "/etc/servik/nginx/sites/kahve.com/deny.conf")
	if !strings.Contains(deny, "location = /backup.sql {") || !strings.Contains(deny, "location ^~ /old/ {") {
		t.Fatalf("deny.conf:\n%s", deny)
	}
	if _, err := m.ApplyDeny(ctx, DenySpec{Domain: domain, Paths: []string{"/"}}); err == nil {
		t.Fatal("denying the whole site was accepted")
	}
}

func plant(t *testing.T, sim *host.Simulator) {
	root := "/home/kahve/web/kahve.com"
	write(t, sim, root+"/public_html/index.php", "<?php echo 'merhaba';\n", 0o644)
	write(t, sim, root+"/public_html/wp-content/uploads/2026/x.php", "<?php system($_GET['c']); ?>\n", 0o644)
	write(t, sim, root+"/public_html/logo.png", "\x89PNG....<?php eval($_POST[1]);", 0o644)
	write(t, sim, root+"/public_html/.env", "DB_PASSWORD=x\n", 0o666)
	write(t, sim, root+"/public_html/yedek.sql", "CREATE TABLE x;\n", 0o644)
	write(t, sim, root+"/public_html/info.php", "<?php phpinfo(); ?>\n", 0o644)
	write(t, sim, root+"/public_html/wp-includes/version.php", "<?php\n$wp_version = '6.2.1';\n", 0o644)
	write(t, sim, root+"/public_html/vendor/lib/big.php", "<?php $x = '"+strings.Repeat("QUJD", 600)+"';\n", 0o644)
	write(t, sim, root+"/public_html/.git/HEAD", "ref: refs/heads/main\n", 0o644)
	write(t, sim, root+"/logs/error.log", "<?php system($_GET['c']);", 0o644)
	write(t, sim, root+"/laravel/releases/2/vendor/laravel/framework/src/Illuminate/Foundation/Application.php", "<?php class Application { const VERSION = '11.44.2'; }", 0o644)
	write(t, sim, root+"/laravel/releases/1/shell.php", "<?php eval(base64_decode('ZWNobyAxOw=='));", 0o644)
	write(t, sim, root+"/laravel/shared/.env", "APP_ENV=production\nAPP_DEBUG=true\n", 0o640)
	if err := os.Symlink("releases/2", sim.Path(root+"/laravel/current")); err != nil {
		t.Fatal(err)
	}
}

func TestScan(t *testing.T) {
	m, sim := newTest(t)
	plant(t, sim)
	res, err := m.Scan(context.Background(), ScanSpec{Sites: []ScanSite{{Domain: domain, User: "kahve", PHPVersion: "8.1"}}})
	if err != nil {
		t.Fatal(err)
	}
	if len(res.Sites) != 1 || res.Sites[0].Error != "" {
		t.Fatalf("sites = %+v", res.Sites)
	}
	got := map[string]Finding{}
	for _, f := range res.Sites[0].Findings {
		got[f.Check+" "+f.Code+" "+f.Path] = f
	}
	want := map[string]string{
		"webshell exec-input public_html/wp-content/uploads/2026/x.php": SeverityCritical,
		"webshell php-in-image public_html/logo.png":                    SeverityHigh,
		"writable file public_html/.env":                                SeverityMedium,
		"exposed env public_html/.env":                                  SeverityLow, // Nginx refuses dot files
		"exposed backup public_html/yedek.sql":                          SeverityHigh,
		"exposed phpinfo public_html/info.php":                          SeverityMedium,
		"exposed git public_html/.git":                                  SeverityLow,
		"outdated wordpress public_html":                                SeverityHigh,
		"outdated laravel laravel/releases/2":                           SeverityMedium,
		"debug app-debug laravel/shared/.env":                           SeverityHigh,
		"outdated php ":                                                 SeverityMedium,
	}
	for k, sev := range want {
		f, ok := got[k]
		if !ok {
			t.Errorf("missing finding %q", k)
			continue
		}
		if f.Severity != sev {
			t.Errorf("%q severity %s, want %s", k, f.Severity, sev)
		}
	}
	for k := range got {
		if strings.Contains(k, "vendor/lib/big.php") || strings.Contains(k, "logs/") || strings.Contains(k, "releases/1/") || strings.Contains(k, "index.php") {
			t.Errorf("unexpected finding %q", k)
		}
	}
	if f := got["exposed backup public_html/yedek.sql"]; f.URL != "/yedek.sql" || len(f.Fixes) != 2 {
		t.Errorf("backup finding = %+v", f)
	}
	if t.Failed() {
		t.Logf("findings: %+v", res.Sites[0].Findings)
	}

	// The site's web server answers for the exposure check.
	m.Fetch = func(_ context.Context, d, p string) int {
		if p == "/.env" {
			return 200
		}
		return 403
	}
	res, _ = m.Scan(context.Background(), ScanSpec{Sites: []ScanSite{{Domain: domain, User: "kahve", PHPVersion: "8.4"}}})
	for _, f := range res.Sites[0].Findings {
		switch {
		case f.Code == "env" && f.Severity != SeverityCritical:
			t.Errorf("served .env = %+v", f)
		case f.Code == "backup" && f.Severity != SeverityLow:
			t.Errorf("refused backup = %+v", f)
		case f.Code == "php":
			t.Errorf("PHP 8.4 reported as outdated")
		}
	}
}

func TestFixes(t *testing.T) {
	m, sim := newTest(t)
	plant(t, sim)
	ctx := context.Background()
	root := "/home/kahve/web/kahve.com"

	res, err := m.Fix(ctx, FixSpec{Domain: domain, User: "kahve", Path: "public_html/wp-content/uploads/2026/x.php", Action: FixQuarantine})
	if err != nil || res.TrashID == "" {
		t.Fatalf("quarantine: %+v %v", res, err)
	}
	if _, err := os.Stat(sim.Path(root + "/public_html/wp-content/uploads/2026/x.php")); !os.IsNotExist(err) {
		t.Fatal("the shell is still in place")
	}
	item := sim.Path(files.TrashDir("kahve", domain) + "/" + res.TrashID + "/item")
	if fi, err := os.Stat(item); err != nil || fi.Mode().Perm() != 0 {
		t.Fatalf("quarantined file: %v %v", fi, err)
	}
	// The file manager's trash brings it back with its mode.
	if _, err := m.Files.Do(ctx, files.Spec{User: "kahve", Domain: domain, Request: files.Request{Op: "trash.restore", IDs: []string{res.TrashID}}}); err != nil {
		t.Fatal(err)
	}
	if fi, err := os.Stat(sim.Path(root + "/public_html/wp-content/uploads/2026/x.php")); err != nil || fi.Mode().Perm() != 0o644 {
		t.Fatalf("restored: %v %v", fi, err)
	}

	write(t, sim, root+"/public_html/cache/a.txt", "x", 0o666)
	if err := os.Chmod(sim.Path(root+"/public_html/cache"), 0o777); err != nil {
		t.Fatal(err)
	}
	// A link out of the site is never followed.
	write(t, sim, "/etc/outside", "x", 0o666)
	if err := os.Symlink(sim.Path("/etc/outside"), sim.Path(root+"/public_html/cache/link")); err != nil {
		t.Fatal(err)
	}
	fix, err := m.Fix(ctx, FixSpec{Domain: domain, User: "kahve", Path: "public_html/cache", Action: FixPermissions})
	if err != nil || fix.Changed != 2 || fix.Undo["public_html/cache"] != "0777" || fix.Undo["public_html/cache/a.txt"] != "0666" {
		t.Fatalf("permissions: %+v %v", fix, err)
	}
	if fi, _ := os.Stat(sim.Path(root + "/public_html/cache/a.txt")); fi.Mode().Perm() != 0o664 {
		t.Fatalf("a.txt mode %v", fi.Mode())
	}
	if fi, _ := os.Stat(sim.Path("/etc/outside")); fi.Mode().Perm() != 0o666 {
		t.Fatal("followed a symlink out of the site")
	}
	if _, err := m.Fix(ctx, FixSpec{Domain: domain, User: "kahve", Path: "public_html/cache", Action: FixPermissions, Undo: fix.Undo}); err != nil {
		t.Fatal(err)
	}
	if fi, _ := os.Stat(sim.Path(root + "/public_html/cache/a.txt")); fi.Mode().Perm() != 0o666 {
		t.Fatalf("undo: a.txt mode %v", fi.Mode())
	}
	for _, bad := range []FixSpec{{Domain: domain, User: "kahve", Path: "../../etc", Action: FixPermissions},
		{Domain: domain, User: "kahve", Path: "", Action: FixQuarantine}, {Domain: domain, User: "kahve", Path: "x", Action: "rm"}} {
		if _, err := m.Fix(ctx, bad); err == nil {
			t.Fatalf("%+v accepted", bad)
		}
	}
}

func TestScanFiles(t *testing.T) {
	m, sim := newTest(t)
	plant(t, sim)
	f, err := m.ScanFiles(context.Background(), FileSpec{Domain: domain, User: "kahve", Paths: []string{"public_html/index.php", "public_html/logo.png", "public_html/yedek.sql"}})
	if err != nil || len(f) != 1 || f[0].Code != "php-in-image" {
		t.Fatalf("findings = %+v %v", f, err)
	}
}

func TestSSH(t *testing.T) {
	m, sim := newTest(t)
	ctx := context.Background()
	write(t, sim, "/usr/sbin/sshd", "", 0o755)
	st, err := m.SSH(ctx)
	if err != nil || !st.Installed || st.RootLogin != RootYes || !st.PasswordAuth || st.Managed {
		t.Fatalf("status = %+v %v", st, err)
	}
	if _, err := m.ApplySSH(ctx, SSHSettings{RootLogin: RootKey, PasswordAuth: false}); err == nil || !strings.Contains(err.Error(), "SSH key") {
		t.Fatalf("passwords off without any key: %v", err)
	}
	write(t, sim, "/root/.ssh/authorized_keys", "ssh-ed25519 AAAAC3Nz admin@laptop\n", 0o600)
	if _, err := m.ApplySSH(ctx, SSHSettings{RootLogin: RootNo, PasswordAuth: false}); err == nil {
		t.Fatal("root's key does not help when root may not log in")
	}
	st, err = m.ApplySSH(ctx, SSHSettings{RootLogin: RootKey, PasswordAuth: false})
	if err != nil || st.RootLogin != RootKey || st.PasswordAuth || !st.Managed || len(st.KeyAccounts) != 1 {
		t.Fatalf("applied = %+v %v", st, err)
	}
	conf := read(t, sim, SSHConfig)
	if !strings.Contains(conf, "PermitRootLogin prohibit-password\nPasswordAuthentication no\n") || !sim.Ran("sshd -t") || !sim.Ran("systemctl try-reload-or-restart ssh") {
		t.Fatalf("config:\n%s", conf)
	}
	sim.Fail = func(c host.Command) error {
		if c.String() == "sshd -t" {
			return errors.New("bad")
		}
		return nil
	}
	if _, err := m.ApplySSH(ctx, SSHSettings{RootLogin: RootYes, PasswordAuth: true}); err == nil {
		t.Fatal("a refused configuration was applied")
	}
	if read(t, sim, SSHConfig) != conf {
		t.Fatal("not rolled back")
	}
}
