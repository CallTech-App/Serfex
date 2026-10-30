package database

import (
	"compress/gzip"
	"context"
	"errors"
	"io"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/calltech-app/serfex/agent/internal/cert"
	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/panel"
)

func newSim(t *testing.T) (*Manager, *host.Simulator) {
	t.Helper()
	sim, err := host.NewSimulator(t.TempDir(), "8.3")
	if err != nil {
		t.Fatal(err)
	}
	return NewSimulated(sim), sim
}

func read(t *testing.T, sim *host.Simulator, abs string) string {
	t.Helper()
	b, err := os.ReadFile(sim.Path(abs))
	if err != nil {
		t.Fatal(err)
	}
	return string(b)
}

func contains(t *testing.T, what, got string, want ...string) {
	t.Helper()
	for _, w := range want {
		if !strings.Contains(got, w) {
			t.Errorf("%s: missing %q in:\n%s", what, w, got)
		}
	}
}

func TestInstallAndExclusivity(t *testing.T) {
	m, sim := newSim(t)
	ctx := context.Background()

	if st := m.Status(ctx); st.Servers[MySQL].Installed || st.Servers[PostgreSQL].Installed {
		t.Fatalf("nothing should be installed: %+v", st)
	}
	if _, err := m.CreateDatabase(ctx, DatabaseSpec{Server: MySQL, Name: "acme_shop"}); !errors.Is(err, ErrNotInstalled) {
		t.Fatalf("create before install: %v", err)
	}

	res, err := m.Install(ctx, InstallSpec{Product: MariaDB})
	if err != nil {
		t.Fatal(err)
	}
	if !res.Installed || res.Server != MySQL || res.Product != MariaDB {
		t.Fatalf("result %+v", res)
	}
	if !sim.Ran("apt-get install -y -q --no-install-recommends mariadb-server") || !sim.Ran("systemctl restart mariadb") {
		t.Errorf("commands: %v", sim.Commands())
	}
	contains(t, "mariadb conf", read(t, sim, "/etc/mysql/mariadb.conf.d/90-serfex.cnf"), "bind-address = 127.0.0.1", "local-infile = 0")
	if _, err := m.Install(ctx, InstallSpec{Product: MySQL}); err == nil || !strings.Contains(err.Error(), "cannot run on the same server") {
		t.Fatalf("MySQL next to MariaDB: %v", err)
	}

	if _, err := m.Install(ctx, InstallSpec{Product: PostgreSQL}); err != nil {
		t.Fatal(err)
	}
	contains(t, "pg conf", read(t, sim, "/etc/postgresql/18/main/conf.d/serfex.conf"), "listen_addresses = 'localhost'", "hba_file = '/etc/serfex/databases/pg_hba.conf'")
	contains(t, "pg_hba", read(t, sim, pgHBA), "local   all             postgres                                peer", "host    all             all             127.0.0.1/32            scram-sha-256")

	st := m.Status(ctx)
	if s := st.Servers[MySQL]; !s.Installed || !s.Running || s.Product != MariaDB {
		t.Errorf("mysql status %+v", s)
	}
	if s := st.Servers[PostgreSQL]; !s.Installed || s.Version != "18.1" {
		t.Errorf("pg status %+v", s)
	}

	// Again: nothing to install, nothing to restart.
	before := len(sim.Commands())
	if res, err := m.Install(ctx, InstallSpec{Product: PostgreSQL}); err != nil || res.Installed {
		t.Fatalf("repeat: %+v %v", res, err)
	}
	for _, c := range sim.Commands()[before:] {
		if strings.HasPrefix(c.String(), "systemctl restart") || strings.HasPrefix(c.String(), "apt-get") {
			t.Errorf("repeat ran %s", c)
		}
	}
}

func TestInstallFailureRemovesNewServer(t *testing.T) {
	m, sim := newSim(t)
	sim.Fail = func(c host.Command) error {
		if c.String() == "systemctl restart mysql" {
			return errors.New("Job for mysql.service failed")
		}
		return nil
	}
	_, err := m.Install(context.Background(), InstallSpec{Product: MySQL})
	var op *OperationError
	if !errors.As(err, &op) {
		t.Fatalf("want an operation error, got %v", err)
	}
	if !sim.Ran("apt-get purge -y -q mysql-server") {
		t.Errorf("the new server was not removed: %v", sim.Commands())
	}
	if _, err := os.Stat(sim.Path("/etc/mysql/mysql.conf.d/zz-serfex.cnf")); !errors.Is(err, os.ErrNotExist) {
		t.Errorf("configuration kept: %v", err)
	}
}

func TestDatabasesUsersAndRemoteAccess(t *testing.T) {
	m, sim := newSim(t)
	ctx := context.Background()
	for _, p := range []string{MySQL, PostgreSQL} {
		if _, err := m.Install(ctx, InstallSpec{Product: p}); err != nil {
			t.Fatal(err)
		}
	}
	for _, s := range []string{MySQL, PostgreSQL} {
		if _, err := m.CreateDatabase(ctx, DatabaseSpec{Server: s, Name: "acme_shop", Site: "acme.com.tr"}); err != nil {
			t.Fatal(err)
		}
	}
	if got := m.Sites()["acme_shop"]; got != "acme.com.tr" {
		t.Errorf("site of acme_shop = %q", got)
	}

	// A new user needs a password.
	if _, err := m.ApplyUser(ctx, UserSpec{Server: MySQL, Name: "acme_app", Grants: []Grant{{"acme_shop", AccessAll}}}); err == nil {
		t.Fatal("a user without a password was created")
	}
	if _, err := m.ApplyUser(ctx, UserSpec{Server: MySQL, Name: "acme_app", Password: "Tq4-demlik-73", Grants: []Grant{{"acme_shop", AccessAll}}}); err != nil {
		t.Fatal(err)
	}
	if strings.Contains(read(t, sim, "/etc/mysql/mysql.conf.d/zz-serfex.cnf"), "bind-address = 0.0.0.0") {
		t.Error("listening on all addresses without remote users")
	}

	// A remote host opens the server to other machines.
	before := len(sim.Commands())
	if _, err := m.ApplyUser(ctx, UserSpec{Server: MySQL, Name: "acme_app", Hosts: []string{"88.247.120.0/28", "88.247.120.0/28"}, Grants: []Grant{{"acme_shop", AccessReadOnly}}}); err != nil {
		t.Fatal(err)
	}
	contains(t, "mysql conf", read(t, sim, "/etc/mysql/mysql.conf.d/zz-serfex.cnf"), "bind-address = 0.0.0.0", "mysqlx-bind-address = 127.0.0.1")
	restarted := false
	for _, c := range sim.Commands()[before:] {
		restarted = restarted || c.String() == "systemctl restart mysql"
	}
	if !restarted {
		t.Error("MySQL was not restarted for the new bind address")
	}
	if s := m.Status(ctx).Servers[MySQL]; !s.Remote {
		t.Error("status does not show remote access")
	}

	if _, err := m.ApplyUser(ctx, UserSpec{Server: PostgreSQL, Name: "acme_bi", Password: "Mh8-kavanoz-19", Hosts: []string{"%", "10.8.0.%"}, Grants: []Grant{{"acme_shop", AccessReadOnly}}}); err != nil {
		t.Fatal(err)
	}
	contains(t, "pg_hba", read(t, sim, pgHBA), `"acme_bi"`, "0.0.0.0/0", "::/0", "10.8.0.0/24")
	contains(t, "pg conf", read(t, sim, "/etc/postgresql/18/main/conf.d/serfex.conf"), "listen_addresses = '*'")

	// Removing the last remote user closes the server again.
	if _, err := m.DeleteUser(ctx, UserRef{Server: PostgreSQL, Name: "acme_bi"}); err != nil {
		t.Fatal(err)
	}
	if hba := read(t, sim, pgHBA); strings.Contains(hba, "acme_bi") {
		t.Errorf("pg_hba still lists the user:\n%s", hba)
	}
	contains(t, "pg conf", read(t, sim, "/etc/postgresql/18/main/conf.d/serfex.conf"), "listen_addresses = 'localhost'")

	l, err := m.List(ctx, MySQL)
	if err != nil {
		t.Fatal(err)
	}
	if len(l.Databases) != 1 || l.Databases[0].Name != "acme_shop" || l.Databases[0].Size <= 0 || len(l.Users) != 1 {
		t.Errorf("listing %+v", l)
	}

	// Deleting with a backup keeps a dump and takes the grant away.
	at := time.Now().UTC().Truncate(time.Second)
	m.now = func() time.Time { return at }
	res, err := m.DeleteDatabase(ctx, DeleteSpec{Server: MySQL, Name: "acme_shop", Backup: true})
	if err != nil {
		t.Fatal(err)
	}
	if res.Backup != BackupDir+"/mysql-acme_shop-"+at.Format("20060102-150405")+".sql.gz" || res.Size == 0 {
		t.Errorf("backup %+v", res)
	}
	f, err := os.Open(sim.Path(res.Backup))
	if err != nil {
		t.Fatal(err)
	}
	zr, err := gzip.NewReader(f)
	if err != nil {
		t.Fatal(err)
	}
	dump, _ := io.ReadAll(zr)
	f.Close()
	contains(t, "dump", string(dump), "dump of acme_shop")
	st, _ := m.loadState()
	if g := st.Users[key(MySQL, "acme_app")].Grants; len(g) != 0 {
		t.Errorf("grant kept after delete: %+v", g)
	}
	if _, ok := st.Databases[key(MySQL, "acme_shop")]; ok {
		t.Error("deleted database still in state")
	}
}

func TestValidation(t *testing.T) {
	m, _ := newSim(t)
	ctx := context.Background()
	if _, err := m.Install(ctx, InstallSpec{Product: MySQL}); err != nil {
		t.Fatal(err)
	}
	for name, spec := range map[string]UserSpec{
		"reserved":      {Server: MySQL, Name: "root", Password: "long-enough"},
		"uppercase":     {Server: MySQL, Name: "Acme", Password: "long-enough"},
		"too long":      {Server: MySQL, Name: strings.Repeat("a", 33), Password: "long-enough"},
		"short pass":    {Server: MySQL, Name: "acme_app", Password: "short"},
		"bad host":      {Server: MySQL, Name: "acme_app", Password: "long-enough", Hosts: []string{"evil.example.com"}},
		"bad access":    {Server: MySQL, Name: "acme_app", Password: "long-enough", Grants: []Grant{{"acme_shop", "owner"}}},
		"double grant":  {Server: MySQL, Name: "acme_app", Password: "long-enough", Grants: []Grant{{"acme_shop", AccessAll}, {"acme_shop", AccessReadOnly}}},
		"bad server":    {Server: "oracle", Name: "acme_app", Password: "long-enough"},
		"line in pass":  {Server: MySQL, Name: "acme_app", Password: "long\nenough"},
		"session names": {Server: MySQL, Name: "serfex-s-abc", Password: "long-enough"},
	} {
		var v *ValidationError
		if _, err := m.ApplyUser(ctx, spec); !errors.As(err, &v) {
			t.Errorf("%s: want a validation error, got %v", name, err)
		}
	}
	for _, n := range []string{"information_schema", "pg_catalog", "a-b", "1db", strings.Repeat("a", 55)} {
		if ValidateDatabaseName(n) == nil {
			t.Errorf("database name %q accepted", n)
		}
	}
}

func TestHosts(t *testing.T) {
	for in, want := range map[string]string{
		"%":               "%",
		"78.186.44.12":    "78.186.44.12",
		"88.247.120.0/28": "88.247.120.0/255.255.255.240",
		"10.8.0.%":        "10.8.0.%",
		"2001:db8::1":     "2001:db8::1",
	} {
		if err := ValidateHost(in); err != nil {
			t.Errorf("%s: %v", in, err)
			continue
		}
		if got := mysqlHost(in); got != want {
			t.Errorf("mysqlHost(%s) = %s, want %s", in, got, want)
		}
	}
	if got := pgNetworks("10.8.%"); len(got) != 1 || got[0] != "10.8.0.0/16" {
		t.Errorf("pgNetworks(10.8.%%) = %v", got)
	}
	for _, bad := range []string{"", "localhost", "10.0.0.1/24", "300.1.1.1", "10.%.1.%", "fe80::1%eth0", "2001:db8::/32", "' OR 1=1"} {
		if ValidateHost(bad) == nil {
			t.Errorf("host %q accepted", bad)
		}
	}
}

func TestManagerSetupAndLogin(t *testing.T) {
	m, sim := newSim(t)
	ctx := context.Background()
	if _, err := m.SetupManager(ctx, ManagerSpec{}); err == nil {
		t.Fatal("manager set up without a database server")
	}
	for _, p := range []string{MySQL, PostgreSQL} {
		if _, err := m.Install(ctx, InstallSpec{Product: p}); err != nil {
			t.Fatal(err)
		}
	}
	res, err := m.SetupManager(ctx, ManagerSpec{})
	if err != nil {
		t.Fatal(err)
	}
	if res.Port != DefaultPort || !res.PhpMyAdmin || !res.Adminer || res.PHPVersion != "8.3" {
		t.Errorf("setup %+v", res)
	}
	contains(t, "nginx", read(t, sim, webConf), "user serfex-web;", "listen 8443 ssl http2 default_server;", "location = /phpmyadmin/serfex-sso.php",
		"fastcgi_pass unix:/run/serfex-php/dbadmin.sock;", "location = /adminer/", "ssl_certificate /etc/serfex/web/tls/fullchain.pem;")
	contains(t, "php-fpm", read(t, sim, phpConf), "user = serfex-dbadmin", "listen.owner = serfex-web", "php_admin_value[session.save_path] = /var/lib/serfex/dbadmin/sessions")
	contains(t, "units", read(t, sim, webUnitFile)+read(t, sim, phpUnitFile), "ExecStart=/usr/sbin/nginx -c /etc/serfex/web/nginx.conf", "ExecStart=/usr/sbin/php-fpm8.3 --nodaemonize")
	pma := read(t, sim, pmaConf)
	contains(t, "phpMyAdmin", pma, "'auth_type'] = 'signon'", "SignonSession'] = 'SerfexSignon'", "AllowRoot'] = false")
	contains(t, "pages", read(t, sim, adminWWW+"/adminer.php")+read(t, sim, adminWWW+"/phpmyadmin-sso.php"), "'server' => '127.0.0.1'", "PMA_single_signon_user")
	if !sim.Ran("systemctl enable serfex-php serfex-web") || !sim.Ran("nginx -t -q -c /etc/serfex/web/nginx.conf") {
		t.Errorf("commands: %v", sim.Commands())
	}

	// A second setup keeps phpMyAdmin's secret and restarts nothing.
	before := len(sim.Commands())
	if _, err := m.SetupManager(ctx, ManagerSpec{}); err != nil {
		t.Fatal(err)
	}
	if read(t, sim, pmaConf) != pma {
		t.Error("phpMyAdmin's configuration changed on a repeated setup")
	}
	for _, c := range sim.Commands()[before:] {
		if strings.HasPrefix(c.String(), "systemctl restart") {
			t.Errorf("repeat ran %s", c)
		}
	}

	if _, err := m.Login(ctx, LoginSpec{Server: MySQL, Database: "acme_shop"}); err == nil {
		t.Fatal("login to a database Serfex does not manage")
	}
	if _, err := m.CreateDatabase(ctx, DatabaseSpec{Server: PostgreSQL, Name: "acme_shop"}); err != nil {
		t.Fatal(err)
	}
	now := time.Now()
	m.now = func() time.Time { return now }
	login, err := m.Login(ctx, LoginSpec{Server: PostgreSQL, Database: "acme_shop"})
	if err != nil {
		t.Fatal(err)
	}
	if login.Tool != "adminer" || login.Port != DefaultPort || !strings.HasPrefix(login.Path, "/adminer/?serfex_token=") {
		t.Errorf("login %+v", login)
	}
	token := strings.TrimPrefix(login.Path, "/adminer/?serfex_token=")
	contains(t, "token", read(t, sim, adminTokens+"/"+token+".json"), `"server":"postgresql"`, `"database":"acme_shop"`, `"user":"serfex-s-`)
	st, _ := m.loadState()
	if len(st.Sessions) != 1 {
		t.Fatalf("sessions %+v", st.Sessions)
	}

	// After its lifetime the temporary user goes.
	m.now = func() time.Time { return now.Add(SessionTTL + time.Minute) }
	m.Sweep(ctx)
	if st, _ := m.loadState(); len(st.Sessions) != 0 {
		t.Errorf("expired session kept: %+v", st.Sessions)
	}
}

func TestPanelCertificateChanged(t *testing.T) {
	m, sim := newSim(t)
	ctx := context.Background()
	if _, err := m.Install(ctx, InstallSpec{Product: MySQL}); err != nil {
		t.Fatal(err)
	}
	if _, err := m.SetupManager(ctx, ManagerSpec{Port: 8443}); err != nil {
		t.Fatal(err)
	}
	// No certificate of its own yet: one server with serfex-web's.
	if err := m.PanelCertificateChanged(ctx); err != nil {
		t.Fatal(err)
	}
	conf := read(t, sim, webConf)
	contains(t, "nginx", conf, "listen 8443 ssl http2 default_server;", "ssl_certificate /etc/serfex/web/tls/fullchain.pem;")
	if strings.Contains(conf, "server_name") || strings.Contains(conf, "/etc/serfex/panel/ssl/") {
		t.Fatalf("panel certificate served before it exists:\n%s", conf)
	}

	// With one, the panel's name gets it and the IP address keeps the
	// certificate a browser already accepted.
	full, key := cert.PanelFiles()
	certPEM, keyPEM, err := selfSigned("server.ornek.com.tr")
	if err != nil {
		t.Fatal(err)
	}
	for path, data := range map[string][]byte{full: certPEM, key: keyPEM} {
		if err := os.MkdirAll(filepath.Dir(sim.Path(path)), 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(sim.Path(path), data, 0o600); err != nil {
			t.Fatal(err)
		}
	}
	if err := m.PanelCertificateChanged(ctx); err != nil {
		t.Fatal(err)
	}
	conf = read(t, sim, webConf)
	byIP, byName, ok := strings.Cut(conf, "server_name server.ornek.com.tr;")
	if !ok {
		t.Fatalf("no server for the panel's name:\n%s", conf)
	}
	contains(t, "default server", byIP, "listen 8443 ssl http2 default_server;", "ssl_certificate /etc/serfex/web/tls/fullchain.pem;", "location ^~ /phpmyadmin/")
	contains(t, "panel's name", byName, "ssl_certificate /etc/serfex/panel/ssl/fullchain.pem;", "ssl_certificate_key /etc/serfex/panel/ssl/privkey.pem;", "location ^~ /phpmyadmin/")
	if strings.Contains(byIP, "/etc/serfex/panel/ssl/") {
		t.Errorf("the IP address serves the panel's certificate:\n%s", byIP)
	}
	// Neither may be framed by another site; only the name, with a
	// certificate browsers trust, asks for HTTPS from then on.
	frame := []string{"add_header X-Frame-Options DENY always;", `add_header Content-Security-Policy "frame-ancestors 'none'; base-uri 'self'; object-src 'none'" always;`, "add_header X-Content-Type-Options nosniff always;"}
	contains(t, "default server", byIP, frame...)
	contains(t, "panel's name", byName, append(frame, `add_header Strict-Transport-Security "max-age=31536000" always;`)...)
	if strings.Contains(byIP, "Strict-Transport-Security") {
		t.Errorf("HSTS on the IP address and its self-signed certificate:\n%s", byIP)
	}
	if strings.Contains(conf, "includeSubDomains") || strings.Contains(conf, "preload") {
		t.Errorf("HSTS must not reach the customers' sites under the panel's domain:\n%s", conf)
	}
	if n := strings.Count(conf, " ssl http2 default_server;"); n == 0 || strings.Count(conf, " ssl http2;") != n {
		t.Errorf("the name's server must listen on the same ports without default_server:\n%s", conf)
	}
}

func TestPanelNamesKeepServerNameSafe(t *testing.T) {
	for n, want := range map[string]bool{"server.ornek.com.tr": true, "*.ornek.com.tr": true, "bad name;": false, "a..b": false, "-x.com": false, "": false} {
		if got := validServerName(n); got != want {
			t.Errorf("validServerName(%q) = %v", n, got)
		}
	}
}

// recorder answers mysql with canned output and keeps the scripts.
type recorder struct {
	*host.Simulator
	scripts []string
	answer  func(script string) string
}

func (r *recorder) Run(ctx context.Context, c host.Command) (string, error) {
	if c.Name == "mysql" {
		in := string(c.Stdin)
		if c.Input != nil {
			b, err := io.ReadAll(c.Input)
			if err != nil {
				return "", err
			}
			in = string(b)
		}
		r.scripts = append(r.scripts, in)
		return r.answer(in), nil
	}
	return r.Simulator.Run(ctx, c)
}

func TestMySQLUserScript(t *testing.T) {
	sim, err := host.NewSimulator(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	hosts := "localhost\n127.0.0.1\n"
	r := &recorder{Simulator: sim, answer: func(s string) string {
		switch {
		case strings.Contains(s, "SELECT VERSION()"):
			return "8.0.46-0ubuntu0.24.04.4\n"
		case strings.Contains(s, "SELECT Host FROM mysql.user"):
			return hosts
		case strings.Contains(s, "SHOW CREATE USER"):
			return "CREATE USER `acme_app`@`localhost` IDENTIFIED WITH 'caching_sha2_password' AS 0x2441243030 REQUIRE NONE PASSWORD EXPIRE DEFAULT\n"
		}
		return ""
	}}
	e := &mysqlEngine{h: r}
	if err := e.applyUser(context.Background(), "acme_app", "", []string{"10.8.0.%"}, []Grant{{"acme_shop", AccessReadWrite}}, nil); err != nil {
		t.Fatal(err)
	}
	script := r.scripts[len(r.scripts)-1]
	contains(t, "script", script,
		"SET SESSION sql_mode = REPLACE(@@sql_mode, 'NO_BACKSLASH_ESCAPES', '');",
		"CREATE USER 'acme_app'@'10.8.0.%' IDENTIFIED WITH 'caching_sha2_password' AS 0x2441243030",
		"REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'acme_app'@'localhost';",
		"GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE, LOCK TABLES, CREATE TEMPORARY TABLES, SHOW VIEW ON `acme\\_shop`.* TO 'acme_app'@'10.8.0.%';")
	if strings.Contains(script, "IDENTIFIED BY") {
		t.Errorf("password changed without one: %s", script)
	}

	// Quotes and backslashes in a password stay inside the literal; a
	// remote host no longer wanted goes.
	hosts += "10.8.0.%\n"
	if err := e.applyUser(context.Background(), "acme_app", `it's\x`, nil, nil, nil); err != nil {
		t.Fatal(err)
	}
	contains(t, "password", r.scripts[len(r.scripts)-1], `ALTER USER 'acme_app'@'localhost' IDENTIFIED BY 'it\'s\\x';`, "DROP USER 'acme_app'@'10.8.0.%';")
}

func TestPanelWeb(t *testing.T) {
	m, sim := newSim(t)
	ctx := context.Background()
	if _, err := m.SetupPanelWeb(ctx); err == nil {
		t.Fatal("served a panel that is not installed")
	}
	for abs, content := range map[string]string{
		panel.ConfigFile: `{"php":"8.5"}`,
		panel.ReleasesDir + "/0.1.0/panel/public/index.php": "<?php\n",
		"/etc/passwd": "root:x:0:0:root:/root:/bin/bash\nserfex:x:998:998::/var/lib/serfex/panel:/usr/sbin/nologin\n",
	} {
		if err := os.MkdirAll(filepath.Dir(sim.Path(abs)), 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(sim.Path(abs), []byte(content), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	if err := os.Symlink("releases/0.1.0", sim.Path(panel.CurrentLink)); err != nil {
		t.Fatal(err)
	}
	// Without a database server, serfex-web serves the panel alone.
	res, err := m.SetupPanelWeb(ctx)
	if err != nil {
		t.Fatal(err)
	}
	if res.Port != DefaultPort || res.PHPVersion != "8.5" || !res.Panel {
		t.Errorf("setup %+v", res)
	}
	conf := read(t, sim, webConf)
	contains(t, "nginx", conf, "root /opt/serfex/current/panel/public;", "fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;", "fastcgi_pass unix:/run/serfex-php/panel.sock;")
	if strings.Contains(conf, "phpmyadmin") || strings.Contains(conf, "return 404;\n        }\n    }") {
		t.Errorf("nginx:\n%s", conf)
	}
	contains(t, "php-fpm", read(t, sim, phpConf), "[panel]", "user = serfex", "listen = /run/serfex-php/panel.sock")
	contains(t, "unit", read(t, sim, phpUnitFile), "ExecStart=/usr/sbin/php-fpm8.5 --nodaemonize")

	// The manager set up later keeps the panel and uses its PHP.
	if _, err := m.Install(ctx, InstallSpec{Product: MySQL}); err != nil {
		t.Fatal(err)
	}
	ms, err := m.SetupManager(ctx, ManagerSpec{})
	if err != nil {
		t.Fatal(err)
	}
	if ms.PHPVersion != "8.5" {
		t.Errorf("manager PHP %s", ms.PHPVersion)
	}
	contains(t, "nginx", read(t, sim, webConf), "root /opt/serfex/current/panel/public;", "location = /phpmyadmin/serfex-sso.php")
	if !sim.Ran("apt-get install -y -q --no-install-recommends nginx php8.5-fpm php8.5-mbstring php8.5-xml php8.5-mysql phpmyadmin") {
		t.Errorf("commands: %v", sim.Commands())
	}
	if m.WebPort() != DefaultPort {
		t.Errorf("web port %d", m.WebPort())
	}
}

func TestPostgreSQLComesFromItsArchive(t *testing.T) {
	ctx := context.Background()

	// Serfex's own cluster runs on Ubuntu's 16; the customers' cluster
	// gets the archive's newest version on port 5432.
	m, sim := newSim(t)
	if _, err := sim.Run(ctx, host.Command{Name: "apt-get", Args: []string{"install", "postgresql-16"}}); err != nil {
		t.Fatal(err)
	}
	if _, err := sim.Run(ctx, host.Command{Name: "pg_createcluster", Args: []string{"--port", "5433", "16", "serfex"}}); err != nil {
		t.Fatal(err)
	}
	if st := m.Status(ctx); st.Servers[PostgreSQL].Installed {
		t.Fatalf("Serfex's own cluster counts as the customers': %+v", st.Servers[PostgreSQL])
	}
	res, err := m.Install(ctx, InstallSpec{Product: PostgreSQL})
	if err != nil {
		t.Fatal(err)
	}
	if !res.Installed || res.Version != "18.1" {
		t.Errorf("result %+v", res)
	}
	contains(t, "pgdg", read(t, sim, pgdgSources), "URIs: https://apt.postgresql.org/pub/repos/apt", "Suites: noble-pgdg", "Components: main", "Signed-By: /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc")
	for _, want := range []string{"apt-get install -y -q --no-install-recommends postgresql-18 postgresql-client-18", "pg_createcluster --port 5432 18 main", "systemctl restart postgresql@18-main"} {
		if !sim.Ran(want) {
			t.Errorf("did not run %q: %v", want, sim.Commands())
		}
	}
	if got := m.Installed()[PostgreSQL]; got != [2]string{PostgreSQL, "postgresql@18-main"} {
		t.Errorf("installed %v", got)
	}

	// A cluster made earlier keeps its version; nothing is added to apt.
	m, sim = newSim(t)
	if _, err := sim.Run(ctx, host.Command{Name: "apt-get", Args: []string{"install", "postgresql"}}); err != nil {
		t.Fatal(err)
	}
	if res, err := m.Install(ctx, InstallSpec{Product: PostgreSQL}); err != nil || res.Version != "16.10" || res.Installed {
		t.Fatalf("existing 16: %+v %v", res, err)
	}
	if sim.Ran("apt-cache pkgnames postgresql-") || m.exists(pgdgSources) {
		t.Errorf("the archive was added for an existing cluster: %v", sim.Commands())
	}

	// Without the archive nothing is installed: Ubuntu's 16 is not
	// offered.
	m, sim = newSim(t)
	sim.Fail = func(c host.Command) error {
		if c.String() == "apt-get update -q --error-on=any" && m.exists(pgdgSources) {
			return errors.New("E: Failed to fetch https://apt.postgresql.org/pub/repos/apt/dists/noble-pgdg/InRelease")
		}
		return nil
	}
	if _, err := m.Install(ctx, InstallSpec{Product: PostgreSQL}); err == nil || !strings.Contains(err.Error(), "the PostgreSQL archive could not be read") {
		t.Fatalf("offline: %v", err)
	}
	if m.exists(pgdgSources) || sim.Ran("pg_createcluster --port 5432 16 main") {
		t.Errorf("offline install: %v", sim.Commands())
	}
}

func TestReleasesComeFromTheVendorsArchives(t *testing.T) {
	ctx := context.Background()
	m, sim := newSim(t)
	// Serfex's own PostgreSQL brought postgresql-common and its key.
	if _, err := sim.Run(ctx, host.Command{Name: "apt-get", Args: []string{"install", "postgresql-common", "postgresql-16"}}); err != nil {
		t.Fatal(err)
	}

	// Before a check nothing but the list is known; apt's own lists have
	// none of these archives.
	for _, product := range []string{MySQL, MariaDB, PostgreSQL} {
		for i, r := range m.Choices(ctx, product, false) {
			if r.Major != Releases[product][i] || r.Default != (i == 0) || r.Version != "" {
				t.Errorf("%s without probe: %+v", product, r)
			}
		}
	}
	// A probe reads each archive in a directory of its own.
	want := map[string][]Release{
		MySQL:      {{"9.7", true, "9.7.0-1ubuntu24.04", "repo.mysql.com/apt/ubuntu"}, {"8.4", false, "8.4.0-1ubuntu24.04", "repo.mysql.com/apt/ubuntu"}},
		MariaDB:    {{"12.3", true, "1:12.3.1+maria~ubu2404", "dlm.mariadb.com/repo/mariadb-server/12.3/repo/ubuntu"}, {"11.8", false, "1:11.8.1+maria~ubu2404", "dlm.mariadb.com/repo/mariadb-server/11.8/repo/ubuntu"}},
		PostgreSQL: {{"18", true, "18.1-1.pgdg24.04+2", "apt.postgresql.org/pub/repos/apt"}},
	}
	for product, w := range want {
		if got := m.Choices(ctx, product, true); !slices.Equal(got, w) {
			t.Errorf("%s: %+v", product, got)
		}
	}
	contains(t, "probe", read(t, sim, probeDir+"/mysql-8.4/sources.list.d/archive.sources"), "URIs: https://repo.mysql.com/apt/ubuntu", "Suites: noble", "Components: mysql-8.4-lts", "Signed-By: "+probeDir+"/mysql-8.4/keyring.gpg")
	if files, _ := os.ReadDir(sim.Path("/etc/apt/sources.list.d")); len(files) != 0 {
		t.Errorf("the probe changed apt: %v", files)
	}

	// An archive that cannot be read leaves the version unknown.
	sim.Fail = func(c host.Command) error {
		if strings.HasSuffix(c.String(), "update -q --error-on=any") {
			return errors.New("E: Failed to fetch https://repo.mysql.com/apt/ubuntu/dists/noble/InRelease")
		}
		return nil
	}
	if got := m.Choices(ctx, MySQL, true); got[0].Version != "" {
		t.Errorf("offline: %+v", got)
	}
	// So does installing: it fails and apt is left as it was.
	if _, err := m.Install(ctx, InstallSpec{Product: MySQL}); err == nil || !strings.Contains(err.Error(), "could not be read") {
		t.Fatalf("offline install: %v", err)
	}
	if m.exists("/etc/apt/sources.list.d/serfex-mysql.sources") || m.exists(keyringDir+"/serfex-mysql.gpg") || m.exists("/usr/sbin/mysqld") {
		t.Error("the failed install left the archive or the server behind")
	}
	sim.Fail = nil

	// A version Serfex does not offer is refused.
	var v *ValidationError
	if _, err := m.Install(ctx, InstallSpec{Product: MySQL, Version: "8.0"}); !errors.As(err, &v) {
		t.Fatalf("MySQL 8.0: %v", err)
	}

	// The chosen version: its archive is listed for apt, then installed.
	res, err := m.Install(ctx, InstallSpec{Product: MySQL, Version: "8.4"})
	if err != nil || !res.Installed {
		t.Fatalf("MySQL 8.4: %+v %v", res, err)
	}
	contains(t, "mysql sources", read(t, sim, "/etc/apt/sources.list.d/serfex-mysql.sources"), "Components: mysql-8.4-lts", "Signed-By: /etc/apt/keyrings/serfex-mysql.gpg")
	contains(t, "mysql key", read(t, sim, keyringDir+"/serfex-mysql.gpg"), "simulated key from https://repo.mysql.com/RPM-GPG-KEY-mysql-2023")
	if !sim.Ran("apt-get install -y -q --no-install-recommends mysql-server") {
		t.Errorf("commands: %v", sim.Commands())
	}
	if got := m.Choices(ctx, MySQL, false); got[1].Version != "8.4.0-1ubuntu24.04" {
		t.Errorf("listed archive: %+v", got)
	}
	// Removing it takes Oracle's packages and archive away again.
	if err := m.Remove(ctx, MySQL, io.Discard); err != nil {
		t.Fatal(err)
	}
	if !sim.Ran("apt-get purge -y -q mysql-server* mysql-client* mysql-community-server* mysql-community-client*") || m.exists("/etc/apt/sources.list.d/serfex-mysql.sources") || m.exists(keyringDir+"/serfex-mysql.gpg") {
		t.Errorf("removal: %v", sim.Commands())
	}

	// MariaDB defaults to the newest version offered.
	if _, err := m.Install(ctx, InstallSpec{Product: MariaDB}); err != nil {
		t.Fatal(err)
	}
	contains(t, "mariadb sources", read(t, sim, "/etc/apt/sources.list.d/serfex-mariadb.sources"), "URIs: https://dlm.mariadb.com/repo/mariadb-server/12.3/repo/ubuntu", "Components: main")
}
