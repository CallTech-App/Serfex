package service

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/tuning"
)

func newTest(t *testing.T, files ...string) (*Manager, *host.Simulator) {
	t.Helper()
	sim, err := host.NewSimulator(t.TempDir(), "8.4")
	if err != nil {
		t.Fatal(err)
	}
	for _, f := range append([]string{"/usr/sbin/nginx", "/usr/sbin/php-fpm8.4", "/usr/sbin/sshd"}, files...) {
		if err := os.MkdirAll(filepath.Dir(sim.Path(f)), 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(sim.Path(f), nil, 0o644); err != nil {
			t.Fatal(err)
		}
	}
	return NewManager(sim), sim
}

func find(t *testing.T, m *Manager, unit string) Status {
	t.Helper()
	list, err := m.List(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	for _, s := range list {
		if s.Unit == unit {
			return s
		}
	}
	t.Fatalf("no service %s", unit)
	return Status{}
}

func TestListShowsInstalledServicesInGroups(t *testing.T) {
	m, _ := newTest(t)
	m.Usage = func() map[string]Usage { return map[string]Usage{"nginx": {CPU: 3.5, Memory: 40 << 20}} }
	list, err := m.List(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	units := map[string]Status{}
	for _, s := range list {
		units[s.Unit] = s
	}
	for _, want := range []string{"nginx", "php8.4-fpm", "ssh", "serfex-agent", "serfex-web", "postgresql@16-serfex"} {
		if _, ok := units[want]; !ok {
			t.Errorf("%s missing from %v", want, list)
		}
	}
	for _, absent := range []string{"apache2", "mysql", "postfix"} {
		if _, ok := units[absent]; ok {
			t.Errorf("%s listed although not installed", absent)
		}
	}
	if n := units["nginx"]; n.State != "active" || n.Group != GroupHosting || n.CPU != 3.5 || n.Settings != "nginx" || n.Since == nil {
		t.Errorf("nginx = %+v", n)
	}
	if units["serfex-web"].Group != GroupSerfex || units["serfex-web"].Protected == "" || units["ssh"].Protected == "" {
		t.Errorf("panel services and SSH must be protected: %+v %+v", units["serfex-web"], units["ssh"])
	}
}

func TestActions(t *testing.T) {
	m, sim := newTest(t)
	ctx := context.Background()
	s, err := m.Do(ctx, ActionSpec{Unit: "nginx", Action: "stop"})
	if err != nil || s.State != "inactive" {
		t.Fatalf("stop: %+v %v", s, err)
	}
	if s, err = m.Do(ctx, ActionSpec{Unit: "nginx", Action: "disable"}); err != nil || s.Enabled != "disabled" {
		t.Fatalf("disable: %+v %v", s, err)
	}
	if s, err = m.Do(ctx, ActionSpec{Unit: "nginx", Action: "start"}); err != nil || s.State != "active" {
		t.Fatalf("start: %+v %v", s, err)
	}
	logs, err := m.Logs(ctx, LogsSpec{Unit: "nginx", Lines: 50})
	if err != nil || !strings.Contains(strings.Join(logs.Lines, "\n"), "Stopped nginx.service") {
		t.Fatalf("logs: %v %v", logs, err)
	}
	// The configuration is tested before a restart; a broken one leaves
	// the service alone.
	sim.Fail = func(c host.Command) error {
		if c.String() == "nginx -t" {
			return errors.New(`nginx: [emerg] unknown directive "server_nmae"`)
		}
		return nil
	}
	_, err = m.Do(ctx, ActionSpec{Unit: "nginx", Action: "restart"})
	var v *ValidationError
	if !errors.As(err, &v) || !strings.Contains(err.Error(), "server_nmae") {
		t.Fatalf("restart with a broken configuration: %v", err)
	}
	sim.Fail = nil

	for _, c := range []ActionSpec{
		{Unit: "serfex-web", Action: "stop"},
		{Unit: "ssh", Action: "disable"},
		{Unit: "serfex-agent", Action: "stop"},
		{Unit: "nginx", Action: "kill"},
		{Unit: "apache2", Action: "start"},
		{Unit: "../etc/passwd", Action: "start"},
		{Unit: "cron", Action: "reload"},
	} {
		if _, err := m.Do(ctx, c); !errors.As(err, &v) {
			t.Errorf("%+v: got %v, want a refusal", c, err)
		}
	}
	// Restarting is fine for protected services.
	if _, err := m.Do(ctx, ActionSpec{Unit: "serfex-web", Action: "restart"}); err != nil {
		t.Fatal(err)
	}
	restarted := false
	m.SelfRestart = func(context.Context) error { restarted = true; return nil }
	if _, err := m.Do(ctx, ActionSpec{Unit: "serfex-agent", Action: "restart"}); err != nil || !restarted {
		t.Fatalf("agent restart: %v", err)
	}
}

func TestNginxSettings(t *testing.T) {
	m, sim := newTest(t)
	ctx := context.Background()
	conf := "user www-data;\nworker_processes auto;\n\nevents {\n\tworker_connections 768;\n\t# multi_accept on;\n}\n\nhttp {\n\tsendfile on;\n\tkeepalive_timeout 65;\n}\n"
	if err := os.WriteFile(sim.Path(nginxConf), []byte(conf), 0o644); err != nil {
		t.Fatal(err)
	}
	vhost := "# Managed by Serfex. Manual changes are overwritten.\nserver {\n    error_log x;\n\n    client_max_body_size 64m;\n\n    include y;\n}\n"
	if err := os.WriteFile(sim.Path("/etc/nginx/sites-available/a.example.conf"), []byte(vhost), 0o644); err != nil {
		t.Fatal(err)
	}
	got, err := m.Settings(ctx, "nginx")
	if err != nil || got.Nginx == nil || got.Nginx.WorkerConnections != 768 || got.Nginx.ClientMaxBodyMB != 64 {
		t.Fatalf("settings: %+v %v", got.Nginx, err)
	}
	got, err = m.ApplySettings(ctx, SettingsSpec{Unit: "nginx", Nginx: &tuning.Nginx{WorkerProcesses: "4", WorkerConnections: 2048, ClientMaxBodyMB: 256, KeepaliveTimeout: 30}})
	if err != nil {
		t.Fatal(err)
	}
	if got.Nginx.WorkerProcesses != "4" || got.Nginx.WorkerConnections != 2048 || got.Nginx.KeepaliveTimeout != 30 || got.Nginx.ClientMaxBodyMB != 256 {
		t.Errorf("after apply: %+v", got.Nginx)
	}
	b, _ := os.ReadFile(sim.Path(nginxConf))
	if want := "user www-data;\nworker_processes 4;\n\nevents {\n\tworker_connections 2048;\n\t# multi_accept on;\n}\n\nhttp {\n\tsendfile on;\n\tkeepalive_timeout 30;\n}\n"; string(b) != want {
		t.Errorf("nginx.conf:\n%s", b)
	}
	b, _ = os.ReadFile(sim.Path(tuning.NginxConf))
	if !strings.Contains(string(b), "client_max_body_size 256m;") {
		t.Errorf("tuning conf:\n%s", b)
	}
	b, _ = os.ReadFile(sim.Path("/etc/nginx/sites-available/a.example.conf"))
	if strings.Contains(string(b), "client_max_body_size") {
		t.Errorf("old per-site limit stays:\n%s", b)
	}

	// Nginx refusing the settings brings back the old files.
	sim.Fail = func(c host.Command) error {
		if c.String() == "nginx -t" {
			return errors.New("nginx: [emerg] too many worker processes")
		}
		return nil
	}
	_, err = m.ApplySettings(ctx, SettingsSpec{Unit: "nginx", Nginx: &tuning.Nginx{WorkerProcesses: "999", WorkerConnections: 2048, ClientMaxBodyMB: 512, KeepaliveTimeout: 30}})
	if err == nil {
		t.Fatal("refused settings were applied")
	}
	sim.Fail = nil
	if b, _ := os.ReadFile(sim.Path(nginxConf)); !strings.Contains(string(b), "worker_processes 4;") {
		t.Errorf("nginx.conf not restored:\n%s", b)
	}
	if s := tuning.Load(sim); s.Nginx.ClientMaxBodyMB != 256 {
		t.Errorf("settings file not restored: %+v", s.Nginx)
	}
	for _, bad := range []tuning.Nginx{
		{WorkerProcesses: "many", WorkerConnections: 1024, ClientMaxBodyMB: 64, KeepaliveTimeout: 65},
		{WorkerProcesses: "auto", WorkerConnections: 10, ClientMaxBodyMB: 64, KeepaliveTimeout: 65},
		{WorkerProcesses: "auto", WorkerConnections: 1024, ClientMaxBodyMB: 0, KeepaliveTimeout: 65},
	} {
		var v *ValidationError
		if _, err := m.ApplySettings(ctx, SettingsSpec{Unit: "nginx", Nginx: &bad}); !errors.As(err, &v) {
			t.Errorf("%+v: %v", bad, err)
		}
	}
}

func TestDatabaseAndPostfixSettings(t *testing.T) {
	m, sim := newTest(t, "/etc/mysql/mysql.conf.d/zz-serfex.cnf", "/usr/sbin/postfix")
	ctx := context.Background()
	if _, err := m.ApplySettings(ctx, SettingsSpec{Unit: "mysql", Database: &tuning.Database{MaxConnections: 300, BufferPoolMB: 1024, MaxAllowedPacketMB: 128}}); err != nil {
		t.Fatal(err)
	}
	b, _ := os.ReadFile(sim.Path(mysqlTuning))
	if !strings.Contains(string(b), "max_connections = 300\ninnodb_buffer_pool_size = 1024M\nmax_allowed_packet = 128M\n") {
		t.Errorf("mysql settings:\n%s", b)
	}
	_ = os.MkdirAll(filepath.Dir(sim.Path(postfixMain)), 0o755)
	if err := os.WriteFile(sim.Path(postfixMain), []byte("# Managed by Serfex.\nmyhostname = mail.example.net\nmessage_size_limit = 52428800\nmailbox_size_limit = 0\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	got, err := m.ApplySettings(ctx, SettingsSpec{Unit: "postfix", Postfix: &tuning.Postfix{MessageSizeMB: 25}})
	if err != nil || got.Postfix.MessageSizeMB != 25 {
		t.Fatalf("postfix: %+v %v", got, err)
	}
	b, _ = os.ReadFile(sim.Path(postfixMain))
	if !strings.Contains(string(b), "message_size_limit = 26214400\nmailbox_size_limit = 0") {
		t.Errorf("main.cf:\n%s", b)
	}
	// Settings of a stopped service are not changed.
	if _, err := m.Do(ctx, ActionSpec{Unit: "postfix", Action: "stop"}); err != nil {
		t.Fatal(err)
	}
	var v *ValidationError
	if _, err := m.ApplySettings(ctx, SettingsSpec{Unit: "postfix", Postfix: &tuning.Postfix{MessageSizeMB: 30}}); !errors.As(err, &v) {
		t.Errorf("stopped service: %v", err)
	}
	if _, err := m.ApplySettings(ctx, SettingsSpec{Unit: "ssh"}); !errors.As(err, &v) {
		t.Errorf("service without settings: %v", err)
	}
}

func TestSetDirectiveAddsMissingLines(t *testing.T) {
	conf := []byte("user www-data;\n\nevents {\n\t# multi_accept on;\n}\n\nhttp {\n    sendfile on;\n}\n")
	for _, d := range [][3]string{
		{"", "worker_processes", "auto"},
		{"events", "worker_connections", "1024"},
		{"http", "keepalive_timeout", "65"},
	} {
		var err error
		if conf, err = setDirective(conf, d[0], d[1], d[2]); err != nil {
			t.Fatal(err)
		}
	}
	want := "worker_processes auto;\nuser www-data;\n\nevents {\n    worker_connections 1024;\n\t# multi_accept on;\n}\n\nhttp {\n    keepalive_timeout 65;\n    sendfile on;\n}\n"
	if string(conf) != want {
		t.Fatalf("got:\n%s", conf)
	}
	if again, _ := setDirective(conf, "http", "keepalive_timeout", "30"); !strings.Contains(string(again), "    keepalive_timeout 30;\n    sendfile") {
		t.Fatalf("second set:\n%s", again)
	}
	if _, err := setDirective([]byte("user www-data;\n"), "http", "keepalive_timeout", "65"); err == nil {
		t.Fatal("a configuration without an http block was accepted")
	}
}

func TestApacheSettings(t *testing.T) {
	m, sim := newTest(t, "/usr/sbin/apache2")
	ctx := context.Background()
	// An Apache Serfex did not set up has nothing to change here.
	if s := find(t, m, "apache2"); s.Settings != "" {
		t.Fatalf("apache2 without Serfex's configuration offers %q", s.Settings)
	}
	// Installed from Yazılım Yönetimi: serfex.conf written and enabled.
	_ = os.MkdirAll(filepath.Dir(sim.Path(apacheEnabled)), 0o755)
	_ = os.MkdirAll(filepath.Dir(sim.Path(tuning.ApacheConf)), 0o755)
	if err := os.WriteFile(sim.Path(tuning.ApacheConf), tuning.ApacheContent(tuning.Defaults().Apache), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink("../conf-available/serfex.conf", sim.Path(apacheEnabled)); err != nil {
		t.Fatal(err)
	}
	if s := find(t, m, "apache2"); s.Settings != "apache" {
		t.Fatalf("settings = %q", s.Settings)
	}
	got, err := m.Settings(ctx, "apache2")
	if err != nil || got.Apache == nil || *got.Apache != (tuning.Apache{MaxRequestWorkers: 150, Timeout: 300}) {
		t.Fatalf("defaults: %+v %v", got.Apache, err)
	}
	got, err = m.ApplySettings(ctx, SettingsSpec{Unit: "apache2", Apache: &tuning.Apache{MaxRequestWorkers: 200, Timeout: 120}})
	if err != nil || got.Apache.MaxRequestWorkers != 200 || got.Apache.Timeout != 120 {
		t.Fatalf("apply: %+v %v", got.Apache, err)
	}
	b, _ := os.ReadFile(sim.Path(tuning.ApacheConf))
	for _, want := range []string{"ServerTokens Prod", "Timeout 120\n<IfModule mpm_event_module>\n", "ThreadsPerChild 25\n    ServerLimit 16\n    MaxRequestWorkers 200\n"} {
		if !strings.Contains(string(b), want) {
			t.Errorf("serfex.conf lacks %q:\n%s", want, b)
		}
	}
	if !sim.Ran("apache2ctl configtest") || !sim.Ran("systemctl reload apache2.service") || sim.Ran("systemctl restart apache2.service") {
		t.Errorf("commands: %v", sim.Commands())
	}
	// More process slots need a restart; a graceful reload keeps the old.
	if _, err := m.ApplySettings(ctx, SettingsSpec{Unit: "apache2", Apache: &tuning.Apache{MaxRequestWorkers: 1000, Timeout: 120}}); err != nil {
		t.Fatal(err)
	}
	b, _ = os.ReadFile(sim.Path(tuning.ApacheConf))
	if !strings.Contains(string(b), "ServerLimit 80\n    MaxRequestWorkers 1000\n") || !sim.Ran("systemctl restart apache2.service") {
		t.Errorf("serfex.conf:\n%s", b)
	}

	// Apache refusing the configuration puts the old one back.
	sim.Fail = func(c host.Command) error {
		if c.String() == "apache2ctl configtest" {
			return errors.New("AH00526: Syntax error")
		}
		return nil
	}
	var v *ValidationError
	if _, err := m.ApplySettings(ctx, SettingsSpec{Unit: "apache2", Apache: &tuning.Apache{MaxRequestWorkers: 300, Timeout: 60}}); !errors.As(err, &v) {
		t.Fatalf("refused: %v", err)
	}
	sim.Fail = nil
	if b, _ := os.ReadFile(sim.Path(tuning.ApacheConf)); !strings.Contains(string(b), "MaxRequestWorkers 1000") {
		t.Errorf("serfex.conf not restored:\n%s", b)
	}
	if s := tuning.Load(sim); s.Apache.MaxRequestWorkers != 1000 {
		t.Errorf("settings file not restored: %+v", s.Apache)
	}
	for _, bad := range []tuning.Apache{
		{MaxRequestWorkers: 10, Timeout: 60},
		{MaxRequestWorkers: 2001, Timeout: 60},
		{MaxRequestWorkers: 150, Timeout: 0},
		{MaxRequestWorkers: 150, Timeout: 600},
	} {
		if _, err := m.ApplySettings(ctx, SettingsSpec{Unit: "apache2", Apache: &bad}); !errors.As(err, &v) {
			t.Errorf("%+v: %v", bad, err)
		}
	}
}

func TestPowerDNSSettings(t *testing.T) {
	m, sim := newTest(t)
	ctx := context.Background()
	conf := "# Managed by Serfex. Manual changes are overwritten.\nlaunch=lmdb\napi-key=secret\n\n# No secondary servers in this version: refuse zone transfers.\ndisable-axfr=yes\nversion-string=anonymous\n"
	_ = os.MkdirAll(filepath.Dir(sim.Path(pdnsConf)), 0o755)
	if err := os.WriteFile(sim.Path(pdnsConf), []byte(conf), 0o640); err != nil {
		t.Fatal(err)
	}
	if s := find(t, m, "pdns@serfex"); s.Settings != "powerdns" {
		t.Fatalf("settings = %q", s.Settings)
	}
	got, err := m.Settings(ctx, "pdns@serfex")
	if err != nil || got.PowerDNS == nil || got.PowerDNS.CacheTTL != 20 || got.PowerDNS.NegativeTTL != 60 || got.PowerDNS.LogQueries || got.PowerDNS.AXFRAllow == nil {
		t.Fatalf("defaults: %+v %v", got.PowerDNS, err)
	}
	got, err = m.ApplySettings(ctx, SettingsSpec{Unit: "pdns@serfex", PowerDNS: &tuning.PowerDNS{
		CacheTTL: 60, NegativeTTL: 30, LogQueries: true,
		AXFRAllow: []string{" 192.0.2.10 ", "2001:db8::1/48", "192.0.2.10", ""},
	}})
	if err != nil {
		t.Fatal(err)
	}
	if p := got.PowerDNS; p.CacheTTL != 60 || !p.LogQueries || strings.Join(p.AXFRAllow, ",") != "192.0.2.10,2001:db8::/48" {
		t.Errorf("after apply: %+v", p)
	}
	b, _ := os.ReadFile(sim.Path(pdnsConf))
	want := "# Managed by Serfex. Manual changes are overwritten.\nlaunch=lmdb\napi-key=secret\n\n# Zone transfers (to secondary name servers), caching and query logging\n# are changed on the panel's Hizmetler page.\ndisable-axfr=no\nversion-string=anonymous\n" +
		"allow-axfr-ips=192.0.2.10,2001:db8::/48\ncache-ttl=60\nnegquery-cache-ttl=30\nlog-dns-queries=yes\nloglevel=5\n"
	if string(b) != want {
		t.Errorf("pdns-serfex.conf:\n%s", b)
	}
	if fi, _ := os.Stat(sim.Path(pdnsConf)); fi.Mode().Perm() != 0o640 {
		t.Errorf("mode %v, want 0640 (it holds the API key)", fi.Mode().Perm())
	}
	if !sim.Ran("pdns_server --config-name=serfex --config=check") || !sim.Ran("systemctl restart pdns@serfex.service") {
		t.Errorf("commands: %v", sim.Commands())
	}

	// Back to no transfers; lines already there are replaced in place.
	if _, err := m.ApplySettings(ctx, SettingsSpec{Unit: "pdns@serfex", PowerDNS: &tuning.PowerDNS{CacheTTL: 20, NegativeTTL: 60}}); err != nil {
		t.Fatal(err)
	}
	b, _ = os.ReadFile(sim.Path(pdnsConf))
	if !strings.Contains(string(b), "\ndisable-axfr=yes\nversion-string=anonymous\nallow-axfr-ips=\ncache-ttl=20\nnegquery-cache-ttl=60\nlog-dns-queries=no\nloglevel=4\n") {
		t.Errorf("pdns-serfex.conf:\n%s", b)
	}

	// PowerDNS refusing to start puts the old file back.
	sim.Fail = func(c host.Command) error {
		if c.String() == "systemctl restart pdns@serfex.service" {
			return errors.New("job failed")
		}
		return nil
	}
	if _, err := m.ApplySettings(ctx, SettingsSpec{Unit: "pdns@serfex", PowerDNS: &tuning.PowerDNS{CacheTTL: 5, NegativeTTL: 60}}); err == nil {
		t.Fatal("failed restart reported as success")
	}
	sim.Fail = nil
	if b, _ := os.ReadFile(sim.Path(pdnsConf)); !strings.Contains(string(b), "cache-ttl=20\n") {
		t.Errorf("pdns-serfex.conf not restored:\n%s", b)
	}
	var v *ValidationError
	for _, bad := range []tuning.PowerDNS{
		{CacheTTL: -1, NegativeTTL: 60},
		{CacheTTL: 20, NegativeTTL: 100000},
		{CacheTTL: 20, NegativeTTL: 60, AXFRAllow: []string{"ns2.example.net"}},
		{CacheTTL: 20, NegativeTTL: 60, AXFRAllow: []string{"192.0.2.0/33"}},
	} {
		if _, err := m.ApplySettings(ctx, SettingsSpec{Unit: "pdns@serfex", PowerDNS: &bad}); !errors.As(err, &v) {
			t.Errorf("%+v: %v", bad, err)
		}
	}
}

func TestOldClusterKeptByAnUpgradeIsNotListed(t *testing.T) {
	m, sim := newTest(t, "/etc/postgresql/16/main/postgresql.conf", "/etc/postgresql/18/main/postgresql.conf", "/etc/postgresql/16/serfex/postgresql.conf")
	if err := os.WriteFile(sim.Path("/etc/postgresql/16/main/start.conf"), []byte("# Automatic startup configuration\nmanual\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(sim.Path("/etc/postgresql/18/main/start.conf"), []byte("auto\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	units := map[string]bool{}
	list, err := m.List(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	for _, s := range list {
		units[s.Unit] = true
	}
	if !units["postgresql@18-main"] || units["postgresql@16-main"] || !units["postgresql@16-serfex"] {
		t.Errorf("units %v", units)
	}
}
