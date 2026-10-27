package component

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"

	"github.com/winchester37/servik/agent/internal/database"
	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/laravel"
	"github.com/winchester37/servik/agent/internal/php"
	"github.com/winchester37/servik/agent/internal/site"
)

func newTest(t *testing.T) (*Manager, *host.Simulator, *site.Manager) {
	t.Helper()
	sim, err := host.NewSimulator(t.TempDir(), "8.3")
	if err != nil {
		t.Fatal(err)
	}
	// Nginx and PHP 8.3 are there from the start, as on a Servik server.
	for _, f := range []string{"/usr/sbin/nginx", "/usr/sbin/php-fpm8.3", "/etc/php/8.3/fpm/pool.d/www.conf"} {
		if err := os.MkdirAll(filepath.Dir(sim.Path(f)), 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(sim.Path(f), nil, 0o755); err != nil {
			t.Fatal(err)
		}
	}
	sites := site.NewManager(sim, "www-data")
	m := NewManager(sim, sites, database.NewSimulated(sim), laravel.NewManager(sim))
	return m, sim, sites
}

func status(t *testing.T, m *Manager, id string) Status {
	t.Helper()
	for _, s := range m.List(context.Background()).Components {
		if s.ID == id {
			return s
		}
	}
	t.Fatalf("no component %s", id)
	return Status{}
}

func exists(sim *host.Simulator, abs string) bool {
	_, err := os.Lstat(sim.Path(abs))
	return err == nil
}

func read(t *testing.T, sim *host.Simulator, abs string) string {
	t.Helper()
	b, err := os.ReadFile(sim.Path(abs))
	if err != nil {
		t.Fatal(err)
	}
	return string(b)
}

func ran(sim *host.Simulator, cmd string) bool {
	for _, c := range sim.Commands() {
		if c.String() == cmd {
			return true
		}
	}
	return false
}

func TestListReportsInstalledSoftware(t *testing.T) {
	m, _, _ := newTest(t)
	nginx := status(t, m, "nginx")
	if !nginx.Installed || nginx.Version == "" || nginx.Required == "" || !nginx.Running {
		t.Errorf("nginx = %+v", nginx)
	}
	if nginx.Latest == "" {
		t.Errorf("the dry run's nginx update is not reported: %+v", nginx)
	}
	if s := status(t, m, "apache"); s.Installed {
		t.Errorf("apache = %+v", s)
	}
	if s := status(t, m, "php8.3"); !s.Installed || s.Units[0] != "php8.3-fpm" {
		t.Errorf("php8.3 = %+v", s)
	}
	if s := status(t, m, "modsecurity"); s.Settings["mode"] != "detect" || s.Settings["paranoia"] != 1 {
		t.Errorf("modsecurity settings = %+v", s.Settings)
	}
}

func TestInstallApacheAndServeASiteBehindIt(t *testing.T) {
	m, sim, sites := newTest(t)
	res, err := m.Install(context.Background(), OpSpec{ID: "apache", Run: "r1"})
	if err != nil {
		t.Fatal(err)
	}
	if !res.Status.Installed || res.Status.Version == "" {
		t.Fatalf("status = %+v", res.Status)
	}
	if ports := read(t, sim, site.ApachePorts); !strings.Contains(ports, "Listen 127.0.0.1:7080") {
		t.Errorf("ports.conf = %s", ports)
	}
	for _, cmd := range []string{"a2enmod -q proxy_fcgi setenvif rewrite remoteip headers expires deflate", "a2dissite -q 000-default", "systemctl enable apache2", "systemctl restart apache2", "apache2ctl configtest"} {
		if !ran(sim, cmd) {
			t.Errorf("%q did not run", cmd)
		}
	}
	log, err := m.ReadRun("r1", 0)
	if err != nil {
		t.Fatal(err)
	}
	if !log.Done || !strings.Contains(log.Data, "▸ Paketler kuruluyor: apache2") || !strings.HasSuffix(log.Data, "✓ Kuruldu\n") {
		t.Errorf("run log:\n%s", log.Data)
	}

	spec := site.Spec{Domain: "eski.kahveatolyesi.com.tr", User: "kahve", PHPVersion: "8.3", WebServer: site.WebApache}
	if _, err := sites.Apply(context.Background(), spec); err != nil {
		t.Fatal(err)
	}
	if s := status(t, m, "apache"); len(s.UsedBy) != 1 || s.UsedBy[0] != "eski.kahveatolyesi.com.tr" {
		t.Errorf("used by = %v", s.UsedBy)
	}
	var inUse *InUseError
	if _, err := m.Remove(context.Background(), OpSpec{ID: "apache", Run: "r2"}); !errors.As(err, &inUse) {
		t.Fatalf("removing Apache in use: %v", err)
	}
	if !exists(sim, "/usr/sbin/apache2") {
		t.Fatal("apache removed while in use")
	}

	spec.WebServer = ""
	if _, err := sites.Apply(context.Background(), spec); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Remove(context.Background(), OpSpec{ID: "apache", Run: "r3"}); err != nil {
		t.Fatal(err)
	}
	if exists(sim, "/usr/sbin/apache2") || status(t, m, "apache").Installed {
		t.Error("apache still installed")
	}
	if !ran(sim, "apt-get purge -y -q apache2") || !ran(sim, "systemctl disable --now apache2") {
		t.Errorf("apache not stopped and purged: %v", sim.Commands())
	}
	if archives, _ := filepath.Glob(sim.Path(BackupDir + "/apache-*.tar.gz")); len(archives) != 1 {
		t.Error("configuration not archived before removal")
	}
}

func TestFailedInstallIsRolledBack(t *testing.T) {
	m, sim, _ := newTest(t)
	m.Fail = func(id, step string) error {
		if id == "apache" && step == "check" {
			return errors.New("apache does not answer")
		}
		return nil
	}
	if _, err := m.Install(context.Background(), OpSpec{ID: "apache", Run: "fail"}); err == nil {
		t.Fatal("install succeeded")
	}
	if exists(sim, site.ApachePorts) || exists(sim, "/usr/sbin/apache2") {
		t.Error("files of the failed install are left")
	}
	if !ran(sim, "apt-get purge -y -q apache2") || !ran(sim, "systemctl disable --now apache2") {
		t.Errorf("rollback did not purge: %v", sim.Commands())
	}
	log, _ := m.ReadRun("fail", 0)
	if !log.Done || !strings.Contains(log.Data, "↺ Değişiklikler geri alınıyor") || !strings.Contains(log.Data, "✗ apache does not answer") {
		t.Errorf("run log:\n%s", log.Data)
	}
	if status(t, m, "apache").Installed {
		t.Error("apache counts as installed")
	}
}

func TestFailedRepairKeepsWhatWasInstalled(t *testing.T) {
	m, sim, _ := newTest(t)
	if _, err := m.Install(context.Background(), OpSpec{ID: "memcached", Run: "a"}); err != nil {
		t.Fatal(err)
	}
	m.Fail = func(id, step string) error { return map[bool]error{true: errors.New("broken")}[step == "check"] }
	if _, err := m.Install(context.Background(), OpSpec{ID: "memcached", Run: "b"}); err == nil {
		t.Fatal("repair succeeded")
	}
	if !exists(sim, "/usr/bin/memcached") {
		t.Error("a failed repair purged the installed package")
	}
}

func TestRequiredAndUnknownComponents(t *testing.T) {
	m, _, _ := newTest(t)
	var v *ValidationError
	if _, err := m.Remove(context.Background(), OpSpec{ID: "nginx", Run: "x"}); !errors.As(err, &v) {
		t.Errorf("removing nginx: %v", err)
	}
	if _, err := m.Install(context.Background(), OpSpec{ID: "iis", Run: "x"}); !errors.As(err, &v) {
		t.Errorf("unknown component: %v", err)
	}
	if _, err := m.Install(context.Background(), OpSpec{ID: "redis", Run: "../x"}); !errors.As(err, &v) {
		t.Errorf("bad run id: %v", err)
	}
}

func TestOneOperationAtATime(t *testing.T) {
	m, _, _ := newTest(t)
	started, release := make(chan struct{}), make(chan struct{})
	var once sync.Once
	m.Fail = func(id, step string) error {
		if id == "supervisor" {
			once.Do(func() { close(started) })
			<-release
		}
		return nil
	}
	done := make(chan error)
	go func() {
		_, err := m.Install(context.Background(), OpSpec{ID: "supervisor", Run: "slow"})
		done <- err
	}()
	<-started
	if s := status(t, m, "supervisor"); s.Busy != "install" {
		t.Errorf("busy = %q", s.Busy)
	}
	if _, err := m.Install(context.Background(), OpSpec{ID: "memcached", Run: "other"}); !errors.Is(err, ErrBusy) {
		t.Errorf("second operation: %v", err)
	}
	close(release)
	if err := <-done; err != nil {
		t.Fatal(err)
	}
}

func TestPHPVersionsAndExtensions(t *testing.T) {
	m, sim, sites := newTest(t)
	if _, err := m.Install(context.Background(), OpSpec{ID: "redis", Run: "r"}); err != nil {
		t.Fatal(err)
	}
	if !ran(sim, "apt-get install -y -q --no-install-recommends -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold redis-server redis-tools php8.3-redis") {
		t.Errorf("redis without the PHP extension: %v", sim.Commands())
	}
	if !ran(sim, "systemctl disable --now redis-server.service") {
		t.Error("the shared Redis instance was left running")
	}
	if _, err := m.Install(context.Background(), OpSpec{ID: "php8.4", Run: "p"}); err != nil {
		t.Fatal(err)
	}
	if !exists(sim, "/usr/sbin/php-fpm8.4") || !exists(sim, "/etc/php/8.4/mods-available/redis.ini") {
		t.Error("PHP 8.4 or its Redis extension missing")
	}
	if !ran(sim, "systemctl restart php8.4-fpm") || !ran(sim, "php-fpm8.4 -t") {
		t.Errorf("php8.4-fpm not started and checked")
	}

	if _, err := sites.Apply(context.Background(), site.Spec{Domain: "klinikmavi.com", User: "klinik", PHPVersion: "8.4"}); err != nil {
		t.Fatal(err)
	}
	var inUse *InUseError
	if _, err := m.Remove(context.Background(), OpSpec{ID: "php8.4", Run: "x"}); !errors.As(err, &inUse) || inUse.UsedBy[0] != "klinikmavi.com" {
		t.Fatalf("removing PHP 8.4 in use: %v", err)
	}
	if _, err := sites.Delete(context.Background(), site.DeleteSpec{Domain: "klinikmavi.com", User: "klinik"}); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Remove(context.Background(), OpSpec{ID: "php8.4", Run: "y"}); err != nil {
		t.Fatal(err)
	}
	if !ran(sim, "apt-get purge -y -q php8.4-*") || exists(sim, "/usr/sbin/php-fpm8.4") {
		t.Error("PHP 8.4 not purged")
	}
	if _, err := sites.Apply(context.Background(), site.Spec{Domain: "klinikmavi.com", User: "klinik", PHPVersion: "8.4"}); err == nil {
		t.Error("a site can still use the removed PHP version")
	}
}

func TestRedisInstances(t *testing.T) {
	m, sim, sites := newTest(t)
	spec := RedisSpec{Domain: "api.hizlikargo.app", User: "hizli", Port: 21000, Password: "Abcdefghij0123456789"}
	var v *ValidationError
	if _, err := m.ApplyRedis(context.Background(), spec); !errors.As(err, &v) {
		t.Fatalf("redis before installing it: %v", err)
	}
	if _, err := m.Install(context.Background(), OpSpec{ID: "redis", Run: "r"}); err != nil {
		t.Fatal(err)
	}
	if _, err := sites.Apply(context.Background(), site.Spec{Domain: spec.Domain, User: spec.User, PHPVersion: "8.3"}); err != nil {
		t.Fatal(err)
	}
	res, err := m.ApplyRedis(context.Background(), spec)
	if err != nil {
		t.Fatal(err)
	}
	if res.Unit != "servik-redis-api.hizlikargo.app.service" || !res.Changed {
		t.Fatalf("result = %+v", res)
	}
	conf := read(t, sim, "/etc/servik/redis/api.hizlikargo.app.conf")
	for _, want := range []string{"bind 127.0.0.1", "port 21000", `requirepass "Abcdefghij0123456789"`, "maxmemory 128mb", "maxmemory-policy noeviction", "dir /var/lib/servik/redis/api.hizlikargo.app"} {
		if !strings.Contains(conf, want) {
			t.Errorf("redis conf lacks %q", want)
		}
	}
	unit := read(t, sim, "/etc/systemd/system/servik-redis-api.hizlikargo.app.service")
	if !strings.Contains(unit, "User=hizli") || !strings.Contains(unit, "ExecStart=/usr/bin/redis-server /etc/servik/redis/api.hizlikargo.app.conf") {
		t.Errorf("unit:\n%s", unit)
	}
	if !ran(sim, "systemctl restart servik-redis-api.hizlikargo.app.service") {
		t.Error("instance not started")
	}
	// The same port cannot go to another site.
	other := spec
	other.Domain = "staging.hizlikargo.app"
	if _, err := m.ApplyRedis(context.Background(), other); !errors.As(err, &v) {
		t.Errorf("port reuse: %v", err)
	}
	var inUse *InUseError
	if _, err := m.Remove(context.Background(), OpSpec{ID: "redis", Run: "x"}); !errors.As(err, &inUse) {
		t.Errorf("removing Redis in use: %v", err)
	}
	removed, err := m.RemoveRedis(context.Background(), spec.Domain)
	if err != nil || !removed {
		t.Fatalf("remove = %v %v", removed, err)
	}
	if exists(sim, "/etc/systemd/system/servik-redis-api.hizlikargo.app.service") || exists(sim, "/var/lib/servik/redis/api.hizlikargo.app") {
		t.Error("instance left behind")
	}
	if _, err := m.Remove(context.Background(), OpSpec{ID: "redis", Run: "y"}); err != nil {
		t.Fatal(err)
	}
	if !ran(sim, "apt-get purge -y -q php*-redis redis-server redis-tools") {
		t.Errorf("redis not purged: %v", sim.Commands())
	}
}

func TestRedisStaysForTheMailServer(t *testing.T) {
	m, sim, _ := newTest(t)
	if _, err := m.Install(context.Background(), OpSpec{ID: "redis", Run: "r"}); err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(sim.Path("/etc/servik/mail"), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(sim.Path(mailState), []byte("{}"), 0o600); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Remove(context.Background(), OpSpec{ID: "redis", Run: "x"}); err != nil {
		t.Fatal(err)
	}
	if !ran(sim, "apt-get purge -y -q php*-redis") {
		t.Errorf("unexpected purge: %v", sim.Commands())
	}
}

func TestModSecuritySettings(t *testing.T) {
	m, sim, _ := newTest(t)
	if _, err := m.Install(context.Background(), OpSpec{ID: "modsecurity", Run: "m"}); err != nil {
		t.Fatal(err)
	}
	main := read(t, sim, modsecMain)
	if !strings.Contains(main, "SecRuleEngine DetectionOnly") || !strings.Contains(main, "Include /usr/share/modsecurity-crs/rules/*.conf") {
		t.Errorf("main.conf:\n%s", main)
	}
	if !strings.Contains(read(t, sim, modsecNginx), "modsecurity_rules_file /etc/servik/modsecurity/main.conf;") {
		t.Error("nginx does not load the rules")
	}
	if !ran(sim, "nginx -t") || !ran(sim, "systemctl reload nginx") {
		t.Error("nginx not checked and reloaded")
	}
	s, err := m.Configure(context.Background(), ConfigureSpec{ID: "modsecurity", Settings: Settings{ModSecurityMode: "block", ModSecurityParanoia: 2}})
	if err != nil {
		t.Fatal(err)
	}
	if s.Settings["mode"] != "block" || s.Settings["paranoia"] != 2 {
		t.Errorf("settings = %v", s.Settings)
	}
	if !strings.Contains(read(t, sim, modsecMain), "SecRuleEngine On") || !strings.Contains(read(t, sim, modsecCRS), "tx.paranoia_level=2") {
		t.Error("new settings not written")
	}
	// Nginx refusing them puts the old ones back.
	sim.Fail = func(c host.Command) error {
		if c.String() == "nginx -t" {
			return errors.New("nginx: [emerg]")
		}
		return nil
	}
	if _, err := m.Configure(context.Background(), ConfigureSpec{ID: "modsecurity", Settings: Settings{ModSecurityMode: "detect", ModSecurityParanoia: 4}}); err == nil {
		t.Fatal("rejected settings were applied")
	}
	if !strings.Contains(read(t, sim, modsecMain), "SecRuleEngine On") || m.loadSettings().ModSecurityParanoia != 2 {
		t.Error("settings not rolled back")
	}
	var v *ValidationError
	if _, err := m.Configure(context.Background(), ConfigureSpec{ID: "modsecurity", Settings: Settings{ModSecurityMode: "loud", ModSecurityParanoia: 1}}); !errors.As(err, &v) {
		t.Errorf("bad mode: %v", err)
	}
	sim.Fail = nil
	if _, err := m.Remove(context.Background(), OpSpec{ID: "modsecurity", Run: "rm"}); err != nil {
		t.Fatal(err)
	}
	if exists(sim, modsecNginx) {
		t.Error("nginx still loads ModSecurity")
	}
}

func TestFail2banJails(t *testing.T) {
	m, sim, _ := newTest(t)
	m.Addresses = func() []string { return []string{"185.98.142.21"} }
	if _, err := m.Install(context.Background(), OpSpec{ID: "fail2ban", Run: "f"}); err != nil {
		t.Fatal(err)
	}
	jails := read(t, sim, jailConf)
	for _, want := range []string{"[sshd]\nenabled = true", "banaction = nftables-multiport", "ignoreip = 127.0.0.1/8 ::1 185.98.142.21", "[recidive]"} {
		if !strings.Contains(jails, want) {
			t.Errorf("jails lack %q:\n%s", want, jails)
		}
	}
	if strings.Contains(jails, "[dovecot]") {
		t.Error("mail jails without a mail server")
	}
	for _, f := range []string{"/usr/sbin/postfix", "/usr/sbin/dovecot"} {
		_ = os.MkdirAll(filepath.Dir(sim.Path(f)), 0o755)
		_ = os.WriteFile(sim.Path(f), nil, 0o755)
	}
	if _, err := m.Install(context.Background(), OpSpec{ID: "fail2ban", Run: "g"}); err != nil {
		t.Fatal(err)
	}
	if jails := read(t, sim, jailConf); !strings.Contains(jails, "[dovecot]") || !strings.Contains(jails, "[postfix-sasl]") {
		t.Errorf("mail jails missing after a repair:\n%s", jails)
	}
}

func TestClamAVScansMail(t *testing.T) {
	m, sim, _ := newTest(t)
	_ = os.MkdirAll(sim.Path("/usr/bin"), 0o755)
	_ = os.WriteFile(sim.Path("/usr/bin/rspamd"), nil, 0o755)
	if _, err := m.Install(context.Background(), OpSpec{ID: "clamav", Run: "c"}); err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(read(t, sim, rspamdVirus), `servers = "/run/clamav/clamd.ctl"`) {
		t.Error("rspamd does not scan with ClamAV")
	}
	if !ran(sim, "rspamadm configtest") || !ran(sim, "systemctl reload rspamd") {
		t.Error("rspamd not checked and reloaded")
	}
	if _, err := m.Remove(context.Background(), OpSpec{ID: "clamav", Run: "d"}); err != nil {
		t.Fatal(err)
	}
	if exists(sim, rspamdVirus) {
		t.Error("rspamd still asks ClamAV")
	}
}

func TestNodeLines(t *testing.T) {
	m, _, _ := newTest(t)
	if _, err := m.Install(context.Background(), OpSpec{ID: "node24", Run: "n1"}); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Install(context.Background(), OpSpec{ID: "node26", Run: "n2"}); err != nil {
		t.Fatal(err)
	}
	n24, n26 := status(t, m, "node24"), status(t, m, "node26")
	if !n24.Installed || !n24.Default || n24.Version != "v24.11.0" || !n26.Installed || n26.Default {
		t.Fatalf("node24 = %+v, node26 = %+v", n24, n26)
	}
	if _, err := m.Remove(context.Background(), OpSpec{ID: "node24", Run: "n3"}); err != nil {
		t.Fatal(err)
	}
	if n26 := status(t, m, "node26"); !n26.Default {
		t.Errorf("builds did not move to Node.js 26: %+v", n26)
	}
	if _, err := m.Check(context.Background()); err != nil {
		t.Fatal(err)
	}
	if n26 := status(t, m, "node26"); n26.Latest != "v26.2.0" {
		t.Errorf("latest = %q", n26.Latest)
	}
}

func TestDatabaseServers(t *testing.T) {
	m, _, _ := newTest(t)
	if _, err := m.Install(context.Background(), OpSpec{ID: "mariadb", Run: "d1", Port: 8443}); err != nil {
		t.Fatal(err)
	}
	if s := status(t, m, "mariadb"); !s.Installed || s.Units[0] != "mariadb" {
		t.Errorf("mariadb = %+v", s)
	}
	if s := status(t, m, "mysql"); s.Installed {
		t.Errorf("mysql counts as installed: %+v", s)
	}
	var v *database.ValidationError
	if _, err := m.Install(context.Background(), OpSpec{ID: "mysql", Run: "d2"}); !errors.As(err, &v) {
		t.Errorf("MySQL next to MariaDB: %v", err)
	}
	if _, err := m.db.CreateDatabase(context.Background(), database.DatabaseSpec{Server: "mysql", Name: "kahve_wp"}); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Remove(context.Background(), OpSpec{ID: "mariadb", Run: "d3"}); !errors.As(err, &v) {
		t.Errorf("removing MariaDB with a database: %v", err)
	}
	if _, err := m.db.DeleteDatabase(context.Background(), database.DeleteSpec{Server: "mysql", Name: "kahve_wp"}); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Remove(context.Background(), OpSpec{ID: "mariadb", Run: "d4"}); err != nil {
		t.Fatal(err)
	}
	if s := status(t, m, "mariadb"); s.Installed {
		t.Errorf("mariadb still installed: %+v", s)
	}
	if _, err := m.Install(context.Background(), OpSpec{ID: "mysql", Run: "d5"}); err != nil {
		t.Errorf("MySQL after MariaDB was removed: %v", err)
	}
}

func TestPHPDefaultsAndExtensionPackages(t *testing.T) {
	m, sim, _ := newTest(t)
	m.PHP = php.NewManager(sim)
	ctx := context.Background()
	if _, err := m.Install(ctx, OpSpec{ID: "php8.4", Run: "p"}); err != nil {
		t.Fatal(err)
	}
	if ini := read(t, sim, php.IniFile("8.4")); !strings.Contains(ini, "memory_limit = 256M") || !strings.Contains(ini, "opcache.enable = 1") {
		t.Errorf("PHP 8.4 without Servik's defaults:\n%s", ini)
	}

	if _, err := m.Extension(ctx, ExtensionSpec{Version: "8.4", Name: "imagick", Run: "e1"}); err != nil {
		t.Fatal(err)
	}
	if !ran(sim, "apt-get install -y -q --no-install-recommends -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold php8.4-imagick") || !ran(sim, "systemctl reload php8.4-fpm") {
		t.Errorf("imagick not installed: %v", sim.Commands())
	}
	if !exists(sim, "/etc/php/8.4/fpm/conf.d/20-imagick.ini") {
		t.Error("imagick not switched on")
	}
	if log := read(t, sim, runDir+"/e1.log"); !strings.Contains(log, "✓ Kuruldu") {
		t.Errorf("run log:\n%s", log)
	}
	if _, err := m.Extension(ctx, ExtensionSpec{Version: "8.4", Name: "imagick", Remove: true, Run: "e2"}); err != nil {
		t.Fatal(err)
	}
	if !ran(sim, "apt-get purge -y -q php8.4-imagick") || exists(sim, "/etc/php/8.4/mods-available/imagick.ini") {
		t.Error("imagick not removed")
	}

	// A failing check purges the package again.
	m.Fail = func(id, step string) error {
		if step == "extension" {
			return errors.New("broken extension")
		}
		return nil
	}
	if _, err := m.Extension(ctx, ExtensionSpec{Version: "8.4", Name: "apcu", Run: "e3"}); err == nil {
		t.Fatal("failed install reported success")
	}
	if !ran(sim, "apt-get purge -y -q php8.4-apcu") {
		t.Error("failed extension install not rolled back")
	}
	m.Fail = nil

	var v *ValidationError
	for _, bad := range []ExtensionSpec{
		{Version: "8.4", Name: "evil", Run: "e4"},
		{Version: "8.2", Name: "apcu", Run: "e5"},
		{Version: "8.4", Name: "apcu", Run: "bad run"},
	} {
		if _, err := m.Extension(ctx, bad); !errors.As(err, &v) {
			t.Errorf("%+v: %v", bad, err)
		}
	}
	// The Redis extension comes and goes with Redis.
	if _, err := m.Install(ctx, OpSpec{ID: "redis", Run: "r"}); err != nil {
		t.Fatal(err)
	}
	var inUse *InUseError
	if _, err := m.Extension(ctx, ExtensionSpec{Version: "8.4", Name: "redis", Remove: true, Run: "e6"}); !errors.As(err, &inUse) {
		t.Errorf("removing the Redis extension: %v", err)
	}
}

func TestPostgreSQLNewestVersionAndMinorUpdates(t *testing.T) {
	m, sim, _ := newTest(t)
	ctx := context.Background()
	if _, err := m.Install(ctx, OpSpec{ID: "postgresql", Run: "pg1", Port: 8443}); err != nil {
		t.Fatal(err)
	}
	log := read(t, sim, runDir+"/pg1.log")
	for _, want := range []string{"▸ PostgreSQL paket deposu ekleniyor (apt.postgresql.org)", "▸ Paketler kuruluyor: postgresql-18 postgresql-client-18", "▸ Müşteri kümesi oluşturuluyor (PostgreSQL 18 main, port 5432)", "✓ Kuruldu"} {
		if !strings.Contains(log, want) {
			t.Errorf("log lacks %q:\n%s", want, log)
		}
	}
	if s := status(t, m, "postgresql"); !s.Installed || s.Version != "18.1" || s.Units[0] != "postgresql@18-main" || s.Latest != "" {
		t.Errorf("postgresql = %+v", s)
	}
	// The archive has a newer minor release; Güncelle installs it.
	if _, err := m.Check(ctx); err != nil {
		t.Fatal(err)
	}
	if s := status(t, m, "postgresql"); s.Latest != "18.2" {
		t.Errorf("latest = %+v", s)
	}
	if _, err := m.Update(ctx, OpSpec{ID: "postgresql", Run: "pg2"}); err != nil {
		t.Fatal(err)
	}
	if !ran(sim, "apt-get install --only-upgrade -y -q --no-install-recommends -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold postgresql-18 postgresql-client-18") {
		t.Errorf("update: %v", sim.Commands())
	}
}

func TestCandidatesBeforeInstall(t *testing.T) {
	m, sim, _ := newTest(t)
	ctx := context.Background()
	// Servik's own PostgreSQL brought postgresql-common and the
	// PostgreSQL archive's key.
	if _, err := sim.Run(ctx, host.Command{Name: "apt-get", Args: []string{"install", "postgresql-common"}}); err != nil {
		t.Fatal(err)
	}
	for id, want := range map[string][2]string{
		"mysql":   {"8.0.43-0ubuntu0.24.04.2", "archive.ubuntu.com/ubuntu"},
		"mariadb": {"1:10.11.13-0ubuntu0.24.04.1", "archive.ubuntu.com/ubuntu"},
		"php8.4":  {"8.4.13-1+ubuntu24.04.1+deb.sury.org+1", "ppa.launchpadcontent.net/ondrej/php/ubuntu"},
		"apache":  {"2.4.58-1ubuntu8.8", "archive.ubuntu.com/ubuntu"},
		// Not known before component.check: no apt list says it.
		"postgresql": {"", ""},
		"node24":     {"", ""},
	} {
		if s := status(t, m, id); s.Installed || s.Candidate != want[0] || s.CandidateFrom != want[1] {
			t.Errorf("%s = %+v", id, s)
		}
	}
	// Installed ones show their version, not a candidate.
	if s := status(t, m, "nginx"); s.Candidate != "" {
		t.Errorf("nginx = %+v", s)
	}

	if _, err := m.Check(ctx); err != nil {
		t.Fatal(err)
	}
	for id, want := range map[string][2]string{
		"postgresql": {"18.1-1.pgdg24.04+2", "apt.postgresql.org/pub/repos/apt"},
		"node24":     {"v24.21.0", "nodejs.org"},
		"composer":   {"2.8.12", "getcomposer.org"},
	} {
		if s := status(t, m, id); s.Candidate != want[0] || s.CandidateFrom != want[1] {
			t.Errorf("%s after check = %+v", id, s)
		}
	}
	if exists(sim, "/etc/apt/sources.list.d/pgdg.sources") {
		t.Error("the check listed the PostgreSQL archive for apt")
	}

	// What was installed shows its version and no stale update.
	if _, err := m.Install(ctx, OpSpec{ID: "node24", Run: "n"}); err != nil {
		t.Fatal(err)
	}
	if s := status(t, m, "node24"); s.Candidate != "" || s.Latest != "" || s.Version == "" {
		t.Errorf("installed node24 = %+v", s)
	}
}
