package logrotate

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/calltech-app/serfex/agent/internal/host"
)

func newSim(t *testing.T) (*Manager, *host.Simulator) {
	t.Helper()
	sim, err := host.NewSimulator(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	mkdir(t, sim, Dir)
	return NewManager(sim), sim
}

func mkdir(t *testing.T, sim *host.Simulator, abs string) {
	t.Helper()
	if err := os.MkdirAll(sim.Path(abs), 0o755); err != nil {
		t.Fatal(err)
	}
}

func writeFile(t *testing.T, sim *host.Simulator, abs, content string) {
	t.Helper()
	mkdir(t, sim, filepath.Dir(abs))
	if err := os.WriteFile(sim.Path(abs), []byte(content), 0o644); err != nil {
		t.Fatal(err)
	}
}

func read(t *testing.T, sim *host.Simulator, abs string) string {
	t.Helper()
	b, err := os.ReadFile(sim.Path(abs))
	if errors.Is(err, os.ErrNotExist) {
		return ""
	}
	if err != nil {
		t.Fatal(err)
	}
	return string(b)
}

// addSite makes what site.apply leaves behind: the account, the PHP-FPM
// pool and the log directories.
func addSite(t *testing.T, sim *host.Simulator, domain, user string) {
	t.Helper()
	if _, err := sim.LookupUser(user); err != nil {
		if _, err := sim.Run(context.Background(), host.Command{Name: "useradd", Args: []string{"--home-dir", "/home/" + user, "--user-group", user}}); err != nil {
			t.Fatal(err)
		}
	}
	writeFile(t, sim, "/etc/php/8.4/fpm/pool.d/"+domain+".conf", "; Managed by Serfex. Manual changes are overwritten.\n["+domain+"]\nuser = "+user+"\ngroup = "+user+"\n")
	mkdir(t, sim, "/home/"+user+"/web/"+domain+"/logs")
	mkdir(t, sim, "/var/log/serfex/sites/"+domain)
}

func TestSyncWritesRules(t *testing.T) {
	m, sim := newSim(t)
	ctx := context.Background()
	addSite(t, sim, "example.com", "example")
	addSite(t, sim, "shop.example.net", "shop")
	// Not a site: the webmail's pool, and a pool Serfex did not write.
	writeFile(t, sim, "/etc/php/8.4/fpm/pool.d/serfex-webmail.conf", "; Managed by Serfex.\n[serfex-webmail]\nuser = serfex-webmail\n")
	writeFile(t, sim, "/etc/php/8.4/fpm/pool.d/www.conf", "[www]\nuser = www-data\n")

	if err := m.Sync(ctx); err != nil {
		t.Fatal(err)
	}
	sites := read(t, sim, SitesRules)
	for _, want := range []string{
		"# Managed by Serfex",
		"/var/log/serfex/sites/*/*.log {\n\tdaily\n\trotate 14\n\tmissingok\n\tnotifempty\n\tcompress\n\tdelaycompress\n\tcreate\n\tsharedscripts\n\tpostrotate\n",
		"systemctl kill --kill-whom=main --signal=USR1 nginx",
		"if systemctl -q is-active apache2; then systemctl reload apache2; fi",
	} {
		if !strings.Contains(sites, want) {
			t.Errorf("serfex-sites lacks %q:\n%s", want, sites)
		}
	}
	php := read(t, sim, PHPRules)
	for _, want := range []string{
		"/home/example/web/example.com/logs/php-error.log {\n\tdaily\n\trotate 14\n",
		"\tsu example example\n\tnocreate\n}",
		"/home/shop/web/shop.example.net/logs/php-error.log {",
		"\tsu shop shop\n",
	} {
		if !strings.Contains(php, want) {
			t.Errorf("serfex-php lacks %q:\n%s", want, php)
		}
	}
	if strings.Contains(php, "webmail") || strings.Contains(php, "www-data") || strings.Contains(php, "postrotate") {
		t.Errorf("serfex-php has more than the sites:\n%s", php)
	}
	// No panel web server or webmail yet.
	if read(t, sim, WebRules) != "" || read(t, sim, WebmailRules) != "" {
		t.Error("wrote rules for services that are not set up")
	}
	if !sim.Ran("logrotate --debug "+SitesRules) || !sim.Ran("logrotate --debug "+PHPRules) {
		t.Errorf("rules not checked: %v", sim.Commands())
	}
}

func TestSyncFollowsServices(t *testing.T) {
	m, sim := newSim(t)
	ctx := context.Background()
	mkdir(t, sim, webLogs)
	mkdir(t, sim, webmailLogs)
	// The webmail's account is not there yet: its rule waits for it.
	if err := m.Sync(ctx); err != nil {
		t.Fatal(err)
	}
	web := read(t, sim, WebRules)
	for _, want := range []string{"/var/log/serfex/web/*.log {", "\tcreate\n", "--signal=USR1 serfex-web", "--signal=USR1 serfex-php"} {
		if !strings.Contains(web, want) {
			t.Errorf("serfex-web lacks %q:\n%s", want, web)
		}
	}
	if read(t, sim, WebmailRules) != "" || read(t, sim, SitesRules) != "" || read(t, sim, PHPRules) != "" {
		t.Error("wrote rules for logs that are not there")
	}

	if _, err := sim.Run(ctx, host.Command{Name: "useradd", Args: []string{"--system", "--user-group", webmailUser}}); err != nil {
		t.Fatal(err)
	}
	if err := m.Sync(ctx); err != nil {
		t.Fatal(err)
	}
	webmail := read(t, sim, WebmailRules)
	for _, want := range []string{"/var/log/serfex/webmail/*.log {", "\tsu serfex-webmail serfex-webmail\n\tnocreate\n", "--signal=USR1 nginx"} {
		if !strings.Contains(webmail, want) {
			t.Errorf("serfex-webmail lacks %q:\n%s", want, webmail)
		}
	}
}

func TestSyncDropsRemovedSites(t *testing.T) {
	m, sim := newSim(t)
	ctx := context.Background()
	addSite(t, sim, "example.com", "example")
	addSite(t, sim, "gone.example.org", "gone")
	if err := m.Sync(ctx); err != nil {
		t.Fatal(err)
	}
	// site.delete removes the pool; an account already gone (userdel)
	// must not stay either, or logrotate refuses the whole file.
	os.Remove(sim.Path("/etc/php/8.4/fpm/pool.d/gone.example.org.conf"))
	if err := m.Sync(ctx); err != nil {
		t.Fatal(err)
	}
	if php := read(t, sim, PHPRules); strings.Contains(php, "gone") || !strings.Contains(php, "example.com") {
		t.Errorf("serfex-php after removal:\n%s", php)
	}
	os.Remove(sim.Path("/etc/php/8.4/fpm/pool.d/example.com.conf"))
	if err := m.Sync(ctx); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(sim.Path(PHPRules)); !errors.Is(err, os.ErrNotExist) {
		t.Errorf("serfex-php kept without sites: %v", err)
	}
}

func TestSitesNeedAccountAndLogs(t *testing.T) {
	_, sim := newSim(t)
	addSite(t, sim, "example.com", "example")
	writeFile(t, sim, "/etc/php/8.3/fpm/pool.d/nouser.example.com.conf", "; Managed by Serfex.\n[nouser.example.com]\nuser = nobody2\n")
	writeFile(t, sim, "/etc/php/8.3/fpm/pool.d/bad.conf", "; Managed by Serfex.\n[bad domain]\nuser = example\n")
	writeFile(t, sim, "/etc/php/8.3/fpm/pool.d/nologs.example.com.conf", "; Managed by Serfex.\n[nologs.example.com]\nuser = example\n")
	got := Sites(sim)
	if len(got) != 1 || got[0] != (Site{Domain: "example.com", User: "example"}) {
		t.Errorf("Sites = %v", got)
	}
}

func TestSetKeepDays(t *testing.T) {
	m, sim := newSim(t)
	ctx := context.Background()
	addSite(t, sim, "example.com", "example")
	if got := m.Settings(); got.KeepDays != DefaultKeepDays {
		t.Fatalf("default = %+v", got)
	}
	for _, bad := range []int{0, -1, MaxKeepDays + 1} {
		var v *ValidationError
		if _, err := m.Set(ctx, Settings{KeepDays: bad}); !errors.As(err, &v) {
			t.Errorf("Set(%d) = %v", bad, err)
		}
	}
	got, err := m.Set(ctx, Settings{KeepDays: 30})
	if err != nil || got.KeepDays != 30 {
		t.Fatalf("Set = %+v, %v", got, err)
	}
	if m.Settings().KeepDays != 30 {
		t.Error("setting not kept")
	}
	for _, f := range []string{SitesRules, PHPRules} {
		if c := read(t, sim, f); !strings.Contains(c, "\trotate 30\n") || strings.Contains(c, "rotate 14") {
			t.Errorf("%s:\n%s", f, c)
		}
	}
	// A broken settings file falls back to the default.
	writeFile(t, sim, File, `{"keep_days": 9000}`)
	if m.Settings().KeepDays != DefaultKeepDays {
		t.Error("out-of-range setting used")
	}
}

func TestSetUndoneWhenLogrotateRefuses(t *testing.T) {
	m, sim := newSim(t)
	ctx := context.Background()
	addSite(t, sim, "example.com", "example")
	if err := m.Sync(ctx); err != nil {
		t.Fatal(err)
	}
	before := read(t, sim, SitesRules)
	sim.Fail = func(c host.Command) error {
		if c.Name == "logrotate" {
			return errors.New("error: bad rule")
		}
		return nil
	}
	var op *OperationError
	if _, err := m.Set(ctx, Settings{KeepDays: 60}); !errors.As(err, &op) {
		t.Fatalf("Set = %v", err)
	}
	if read(t, sim, SitesRules) != before || m.Settings().KeepDays != DefaultKeepDays {
		t.Error("refused change not undone")
	}
}

func TestNoLogrotate(t *testing.T) {
	sim, err := host.NewSimulator(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	addSite(t, sim, "example.com", "example")
	m := NewManager(sim)
	if err := m.Sync(context.Background()); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(sim.Path(Dir)); !errors.Is(err, os.ErrNotExist) {
		t.Error("wrote rules without logrotate")
	}
	var none *Manager
	if err := none.Sync(context.Background()); err != nil {
		t.Error(err)
	}
}
