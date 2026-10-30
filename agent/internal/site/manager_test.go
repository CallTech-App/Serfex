package site

import (
	"context"
	"errors"
	"os"
	"strings"
	"testing"

	"github.com/calltech-app/serfex/agent/internal/host"
)

func newTest(t *testing.T, php ...string) (*Manager, *host.Simulator) {
	t.Helper()
	if len(php) == 0 {
		php = []string{"8.3", "8.4"}
	}
	sim, err := host.NewSimulator(t.TempDir(), php...)
	if err != nil {
		t.Fatal(err)
	}
	return NewManager(sim, "www-data"), sim
}

func read(t *testing.T, sim *host.Simulator, abs string) string {
	t.Helper()
	b, err := os.ReadFile(sim.Path(abs))
	if err != nil {
		t.Fatal(err)
	}
	return string(b)
}

func exists(sim *host.Simulator, abs string) bool {
	_, err := os.Lstat(sim.Path(abs))
	return err == nil
}

var spec = Spec{Domain: "kahveatolyesi.com.tr", User: "kahveatolyesi", PHPVersion: "8.4", Aliases: []string{"www.kahveatolyesi.com.tr"}}

func TestApplyCreatesSite(t *testing.T) {
	m, sim := newTest(t)
	res, err := m.Apply(context.Background(), spec)
	if err != nil {
		t.Fatal(err)
	}
	if !res.UserCreated || !res.Changed || res.UID != 1000 {
		t.Fatalf("unexpected result %+v", res)
	}
	if res.DocumentRoot != "/home/kahveatolyesi/web/kahveatolyesi.com.tr/public_html" {
		t.Fatalf("document root = %s", res.DocumentRoot)
	}

	for _, d := range []string{
		"/home/kahveatolyesi/tmp",
		"/home/kahveatolyesi/web/kahveatolyesi.com.tr/public_html/index.html",
		"/home/kahveatolyesi/web/kahveatolyesi.com.tr/logs",
		"/var/log/serfex/sites/kahveatolyesi.com.tr",
		"/etc/nginx/sites-enabled/kahveatolyesi.com.tr.conf",
	} {
		if !exists(sim, d) {
			t.Errorf("%s was not created", d)
		}
	}

	vhost := read(t, sim, res.NginxConfig)
	for _, want := range []string{
		"server_name kahveatolyesi.com.tr www.kahveatolyesi.com.tr;",
		"root /home/kahveatolyesi/web/kahveatolyesi.com.tr/public_html;",
		"fastcgi_pass unix:/run/php/serfex-kahveatolyesi.com.tr.sock;",
	} {
		if !strings.Contains(vhost, want) {
			t.Errorf("vhost lacks %q:\n%s", want, vhost)
		}
	}
	pool := read(t, sim, "/etc/php/8.4/fpm/pool.d/kahveatolyesi.com.tr.conf")
	for _, want := range []string{"[kahveatolyesi.com.tr]", "user = kahveatolyesi", "listen.owner = www-data", "open_basedir] = /home/kahveatolyesi/"} {
		if !strings.Contains(pool, want) {
			t.Errorf("pool lacks %q:\n%s", want, pool)
		}
	}

	for _, c := range []string{"useradd", "php-fpm8.4 -t", "nginx -t", "systemctl reload php8.4-fpm", "systemctl reload nginx"} {
		if !sim.Ran(c) {
			t.Errorf("did not run %q", c)
		}
	}
	// Files inside the customer's home are written as the customer.
	for _, c := range sim.Commands() {
		if c.Name == "install" || c.Name == "tee" || c.Name == "rm" {
			t.Errorf("%s ran as root", c)
		}
	}
}

func TestApplyIsIdempotent(t *testing.T) {
	m, sim := newTest(t)
	if _, err := m.Apply(context.Background(), spec); err != nil {
		t.Fatal(err)
	}
	n := len(sim.Commands())
	res, err := m.Apply(context.Background(), spec)
	if err != nil {
		t.Fatal(err)
	}
	if res.Changed || res.UserCreated {
		t.Fatalf("second apply changed something: %+v", res)
	}
	if got := len(sim.Commands()); got != n {
		t.Fatalf("second apply ran %d commands: %v", got-n, sim.Commands()[n:])
	}
}

func TestApplyMovesPoolToNewPHPVersion(t *testing.T) {
	m, sim := newTest(t)
	if _, err := m.Apply(context.Background(), spec); err != nil {
		t.Fatal(err)
	}
	s := spec
	s.PHPVersion = "8.3"
	if _, err := m.Apply(context.Background(), s); err != nil {
		t.Fatal(err)
	}
	if exists(sim, "/etc/php/8.4/fpm/pool.d/kahveatolyesi.com.tr.conf") {
		t.Error("old pool was left behind")
	}
	if !exists(sim, "/etc/php/8.3/fpm/pool.d/kahveatolyesi.com.tr.conf") {
		t.Error("new pool missing")
	}
	if !sim.Ran("systemctl reload php8.4-fpm") || !sim.Ran("systemctl reload php8.3-fpm") {
		t.Error("both PHP-FPM versions must be reloaded")
	}
}

func TestApplyRollsBackWhenNginxRejectsConfig(t *testing.T) {
	m, sim := newTest(t)
	sim.Fail = func(c host.Command) error {
		if c.String() == "nginx -t" {
			return errors.New("nginx: [emerg] unexpected end of file")
		}
		return nil
	}
	_, err := m.Apply(context.Background(), spec)
	var opErr *OperationError
	if !errors.As(err, &opErr) || !strings.Contains(err.Error(), "unexpected end of file") {
		t.Fatalf("err = %v", err)
	}
	for _, p := range []string{
		"/etc/nginx/sites-available/kahveatolyesi.com.tr.conf",
		"/etc/nginx/sites-enabled/kahveatolyesi.com.tr.conf",
		"/etc/php/8.4/fpm/pool.d/kahveatolyesi.com.tr.conf",
		"/home/kahveatolyesi",
		"/var/log/serfex/sites/kahveatolyesi.com.tr",
	} {
		if exists(sim, p) {
			t.Errorf("%s survived the rollback", p)
		}
	}
	if _, err := sim.LookupUser("kahveatolyesi"); !errors.Is(err, host.ErrUserNotFound) {
		t.Errorf("user survived the rollback: %v", err)
	}
	if sim.Ran("systemctl reload") {
		t.Error("services were reloaded with a broken configuration")
	}
}

func TestApplyRollsBackChangesToExistingSite(t *testing.T) {
	m, sim := newTest(t)
	if _, err := m.Apply(context.Background(), spec); err != nil {
		t.Fatal(err)
	}
	before := read(t, sim, "/etc/nginx/sites-available/kahveatolyesi.com.tr.conf")

	sim.Fail = func(c host.Command) error {
		if c.String() == "systemctl reload nginx" {
			return errors.New("Job for nginx.service failed")
		}
		return nil
	}
	s := spec
	s.Aliases = nil
	if _, err := m.Apply(context.Background(), s); err == nil {
		t.Fatal("expected an error")
	}
	if after := read(t, sim, "/etc/nginx/sites-available/kahveatolyesi.com.tr.conf"); after != before {
		t.Errorf("vhost was not restored:\n%s", after)
	}
	if _, err := sim.LookupUser("kahveatolyesi"); err != nil {
		t.Error("existing user must survive a failed update")
	}
}

func TestApplyRefusesForeignFilesAndUsers(t *testing.T) {
	t.Run("hand-written vhost", func(t *testing.T) {
		m, sim := newTest(t)
		if err := os.WriteFile(sim.Path("/etc/nginx/sites-available/kahveatolyesi.com.tr.conf"), []byte("server {}\n"), 0o644); err != nil {
			t.Fatal(err)
		}
		if _, err := m.Apply(context.Background(), spec); err == nil || !strings.Contains(err.Error(), "not managed by Serfex") {
			t.Fatalf("err = %v", err)
		}
		if got := read(t, sim, "/etc/nginx/sites-available/kahveatolyesi.com.tr.conf"); got != "server {}\n" {
			t.Fatal("foreign file was changed")
		}
	})
	t.Run("existing account", func(t *testing.T) {
		m, _ := newTest(t)
		s := spec
		s.User = "www-data"
		if _, err := m.Apply(context.Background(), s); err == nil {
			t.Fatal("must not adopt www-data")
		}
	})
	t.Run("account not created by Serfex", func(t *testing.T) {
		m, sim := newTest(t)
		f, _ := os.OpenFile(sim.Path("/etc/passwd"), os.O_APPEND|os.O_WRONLY, 0)
		f.WriteString("kahveatolyesi:x:1000:1000:Kahve:/home/kahveatolyesi:/bin/bash\n")
		f.Close()
		var v *ValidationError
		if _, err := m.Apply(context.Background(), spec); !errors.As(err, &v) {
			t.Fatalf("err = %v", err)
		}
	})
}

func TestApplyValidates(t *testing.T) {
	m, _ := newTest(t)
	bad := []Spec{
		{Domain: "", User: "a", PHPVersion: "8.4"},
		{Domain: "localhost", User: "a", PHPVersion: "8.4"},
		{Domain: "Kahve.com", User: "a", PHPVersion: "8.4"},
		{Domain: "kahve.com;evil", User: "a", PHPVersion: "8.4"},
		{Domain: "-a.com", User: "a", PHPVersion: "8.4"},
		{Domain: "a.com", User: "1abc", PHPVersion: "8.4"},
		{Domain: "a.com", User: "root", PHPVersion: "8.4"},
		{Domain: "a.com", User: "a", PHPVersion: "8"},
		{Domain: "a.com", User: "a", PHPVersion: "7.4"}, // valid shape, not installed
		{Domain: "a.com", User: "a", PHPVersion: "8.4", Aliases: []string{"a.com"}},
		{Domain: "a.com", User: "a", PHPVersion: "8.4", Aliases: []string{"b.com }"}},
	}
	for _, s := range bad {
		var v *ValidationError
		if _, err := m.Apply(context.Background(), s); !errors.As(err, &v) {
			t.Errorf("%+v: err = %v, want a validation error", s, err)
		}
	}
	for _, d := range []string{"a.co", "xn--kahve-tya.com.tr", "api.hizli-kargo.app", "a.xn--p1ai"} {
		if err := ValidateDomain(d); err != nil {
			t.Errorf("%s: %v", d, err)
		}
	}
}

func TestDelete(t *testing.T) {
	m, sim := newTest(t)
	ctx := context.Background()
	if _, err := m.Apply(ctx, spec); err != nil {
		t.Fatal(err)
	}
	second := Spec{Domain: "eski.kahveatolyesi.com.tr", User: "kahveatolyesi", PHPVersion: "8.3"}
	if _, err := m.Apply(ctx, second); err != nil {
		t.Fatal(err)
	}

	// The account still owns the second site, so it stays.
	res, err := m.Delete(ctx, DeleteSpec{Domain: spec.Domain, User: spec.User, RemoveFiles: true, RemoveUser: true})
	if err != nil {
		t.Fatal(err)
	}
	if !res.FilesRemoved || res.UserRemoved {
		t.Fatalf("unexpected result %+v", res)
	}
	for _, p := range []string{
		"/etc/nginx/sites-enabled/kahveatolyesi.com.tr.conf",
		"/etc/nginx/sites-available/kahveatolyesi.com.tr.conf",
		"/etc/php/8.4/fpm/pool.d/kahveatolyesi.com.tr.conf",
		"/home/kahveatolyesi/web/kahveatolyesi.com.tr",
		"/var/log/serfex/sites/kahveatolyesi.com.tr",
	} {
		if exists(sim, p) {
			t.Errorf("%s was not removed", p)
		}
	}
	if !exists(sim, "/etc/nginx/sites-enabled/eski.kahveatolyesi.com.tr.conf") {
		t.Error("the other site was touched")
	}

	// Deleting again is a no-op.
	if _, err := m.Delete(ctx, DeleteSpec{Domain: spec.Domain, User: spec.User}); err != nil {
		t.Fatal(err)
	}

	res, err = m.Delete(ctx, DeleteSpec{Domain: second.Domain, User: second.User, RemoveFiles: true, RemoveUser: true})
	if err != nil {
		t.Fatal(err)
	}
	if !res.UserRemoved || exists(sim, "/home/kahveatolyesi") {
		t.Fatalf("account not removed: %+v", res)
	}
	if _, err := sim.LookupUser("kahveatolyesi"); !errors.Is(err, host.ErrUserNotFound) {
		t.Fatal("account still in passwd")
	}
}

func TestDeleteSiteWhoseAccountIsGone(t *testing.T) {
	m, sim := newTest(t)
	ctx := context.Background()
	if _, err := m.Apply(ctx, spec); err != nil {
		t.Fatal(err)
	}
	if ok, err := m.AccountExists(spec.User); !ok || err != nil {
		t.Fatalf("account not found: %v %v", ok, err)
	}
	// The account and its home went before the site did.
	if _, err := sim.Run(ctx, host.Command{Name: "userdel", Args: []string{spec.User}}); err != nil {
		t.Fatal(err)
	}
	if err := os.RemoveAll(sim.Path("/home/kahveatolyesi")); err != nil {
		t.Fatal(err)
	}
	if ok, err := m.AccountExists(spec.User); ok || err != nil {
		t.Fatalf("account reported on the server: %v %v", ok, err)
	}
	res, err := m.Delete(ctx, DeleteSpec{Domain: spec.Domain, User: spec.User, RemoveFiles: true, RemoveUser: true})
	if err != nil {
		t.Fatal(err)
	}
	if !res.UserRemoved {
		t.Fatalf("the missing account was not reported removed: %+v", res)
	}
	if exists(sim, "/etc/nginx/sites-enabled/kahveatolyesi.com.tr.conf") {
		t.Error("site is still enabled")
	}
}

func TestDeleteKeepsFilesWhenAsked(t *testing.T) {
	m, sim := newTest(t)
	ctx := context.Background()
	if _, err := m.Apply(ctx, spec); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Delete(ctx, DeleteSpec{Domain: spec.Domain, User: spec.User}); err != nil {
		t.Fatal(err)
	}
	if !exists(sim, "/home/kahveatolyesi/web/kahveatolyesi.com.tr/public_html/index.html") {
		t.Error("files were removed")
	}
	if exists(sim, "/etc/nginx/sites-enabled/kahveatolyesi.com.tr.conf") {
		t.Error("site is still enabled")
	}
}

func TestPHPVersions(t *testing.T) {
	m, sim := newTest(t, "8.5", "8.3")
	if err := os.MkdirAll(sim.Path("/etc/php/8.2/cli"), 0o755); err != nil { // CLI only, no FPM
		t.Fatal(err)
	}
	got, err := m.PHPVersions()
	if err != nil {
		t.Fatal(err)
	}
	if strings.Join(got, ",") != "8.3,8.5" {
		t.Fatalf("versions = %v", got)
	}
}

func TestWebRoot(t *testing.T) {
	m, sim := newTest(t)
	s := spec
	s.WebRoot = "laravel/current/public"
	res, err := m.Apply(context.Background(), s)
	if err != nil {
		t.Fatal(err)
	}
	if res.DocumentRoot != "/home/kahveatolyesi/web/kahveatolyesi.com.tr/laravel/current/public" {
		t.Fatalf("document root = %s", res.DocumentRoot)
	}
	if vhost := read(t, sim, res.NginxConfig); !strings.Contains(vhost, "root /home/kahveatolyesi/web/kahveatolyesi.com.tr/laravel/current/public;") {
		t.Fatalf("vhost:\n%s", vhost)
	}
	// public_html is still there for the files the site had.
	if !exists(sim, "/home/kahveatolyesi/web/kahveatolyesi.com.tr/public_html/index.html") {
		t.Fatal("public_html missing")
	}
	for _, bad := range []string{"../other", "/etc", "laravel/../../x", ".hidden"} {
		s.WebRoot = bad
		var v *ValidationError
		if _, err := m.Apply(context.Background(), s); !errors.As(err, &v) {
			t.Errorf("web_root %q: %v", bad, err)
		}
	}
}

func TestSetMaxChildren(t *testing.T) {
	m, sim := newTest(t)
	ctx := context.Background()
	if _, err := m.Apply(ctx, spec); err != nil {
		t.Fatal(err)
	}
	pool := "/etc/php/8.4/fpm/pool.d/kahveatolyesi.com.tr.conf"
	if p := read(t, sim, pool); !strings.Contains(p, "pm.max_children = 10\n") || !strings.Contains(p, "pm.status_listen = /run/php/serfex-kahveatolyesi.com.tr.status.sock\n") {
		t.Fatalf("pool:\n%s", p)
	}
	if err := m.SetMaxChildren(ctx, spec.Domain, 3); err != nil {
		t.Fatal(err)
	}
	if p := read(t, sim, pool); !strings.Contains(p, "pm.max_children = 3\n") {
		t.Fatalf("pool:\n%s", p)
	}
	// A rejected configuration is rolled back.
	sim.Fail = func(c host.Command) error {
		if c.Name == "php-fpm8.4" {
			return errors.New("bad")
		}
		return nil
	}
	if err := m.SetMaxChildren(ctx, spec.Domain, 5); err == nil {
		t.Fatal("expected an error")
	}
	if p := read(t, sim, pool); !strings.Contains(p, "pm.max_children = 3\n") {
		t.Fatalf("not rolled back:\n%s", p)
	}
	sim.Fail = nil
	if err := m.SetMaxChildren(ctx, "yok.com", 3); err == nil {
		t.Fatal("missing site accepted")
	}
	// The hook keeps a limit when the site is applied again.
	m.MaxChildren = func(string) int { return 2 }
	if _, err := m.Apply(ctx, spec); err != nil {
		t.Fatal(err)
	}
	if p := read(t, sim, pool); !strings.Contains(p, "pm.max_children = 2\n") {
		t.Fatalf("pool:\n%s", p)
	}
}
