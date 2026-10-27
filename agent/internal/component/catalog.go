package component

import (
	"context"
	"fmt"
	"net"
	"os"
	"path/filepath"
	"slices"
	"sort"
	"strings"
	"time"

	"github.com/winchester37/servik/agent/internal/aptcache"
	"github.com/winchester37/servik/agent/internal/database"
	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/i18n"
	"github.com/winchester37/servik/agent/internal/panel"
	"github.com/winchester37/servik/agent/internal/site"
	"github.com/winchester37/servik/agent/internal/tuning"
	"github.com/winchester37/servik/agent/internal/txn"
)

// PHPVersions are the PHP versions Servik offers. Ubuntu 24.04 ships 8.3;
// the others come from the Ondřej Surý archive.
var PHPVersions = []string{"8.1", "8.2", "8.3", "8.4", "8.5"}

// NodeLines are the Node.js release lines Servik offers.
var NodeLines = []string{"22", "24", "26"}

// phpExtensions are installed with every PHP version: what WordPress,
// Laravel and most PHP applications expect.
var phpExtensions = []string{"cli", "common", "mbstring", "xml", "curl", "zip", "intl", "bcmath", "gd", "mysql", "pgsql", "sqlite3", "readline", "opcache"}

func phpPackages(v string) []string {
	// The CLI runs Artisan, Composer and the scheduler of Laravel sites.
	out := []string{"php" + v + "-fpm", "php" + v + "-cli"}
	for _, e := range phpExtensions {
		// OPcache is part of PHP itself from 8.5 on.
		if e == "opcache" && v >= "8.5" {
			continue
		}
		out = append(out, "php"+v+"-"+e)
	}
	return out
}

// installedPHP lists the PHP versions with PHP-FPM installed.
func (m *Manager) installedPHP() []string {
	var out []string
	for _, v := range PHPVersions {
		if m.exists("/usr/sbin/php-fpm" + v) {
			out = append(out, v)
		}
	}
	return out
}

// phpExt names a PHP extension package for every installed version.
func (m *Manager) phpExt(ext string) []string {
	var out []string
	for _, v := range m.installedPHP() {
		out = append(out, "php"+v+"-"+ext)
	}
	return out
}

const (
	mailState   = "/etc/servik/mail/server.json"
	redisDir    = "/etc/servik/redis"
	redisReadme = redisDir + "/README"
)

func catalog() []*component {
	cs := []*component{
		{
			id: "nginx", packages: []string{"nginx"}, marker: "/usr/sbin/nginx", versionPkg: "nginx",
			units:    []string{"nginx"},
			required: "Servik serves every site with Nginx",
			check:    func(o *op) error { _, err := o.run(host.Command{Name: "nginx", Args: []string{"-t"}}); return err },
		},
		apache(),
		{
			id: "redis", packages: []string{"redis-server", "redis-tools"}, versionPkg: "redis-server",
			extra:     func(m *Manager) []string { return m.phpExt("redis") },
			installed: func(m *Manager) bool { return m.exists("/usr/bin/redis-server") && m.exists(redisReadme) },
			configure: configureRedis,
			check: func(o *op) error {
				_, err := o.run(host.Command{Name: "redis-server", Args: []string{"--version"}})
				return err
			},
			inUse: func(m *Manager) []string { return m.redisInstances() },
			purge: func(m *Manager) []string {
				out := []string{"php*-redis"}
				// The mail server keeps its own Redis instance.
				if !m.exists(mailState) {
					out = append(out, "redis-server", "redis-tools")
				}
				return out
			},
			unconfigure: func(o *op) error { return o.t.RemoveFile(redisReadme) },
		},
		{
			id: "memcached", packages: []string{"memcached"}, marker: "/usr/bin/memcached", versionPkg: "memcached",
			extra:  func(m *Manager) []string { return m.phpExt("memcached") },
			units:  []string{"memcached"},
			backup: []string{"/etc/memcached.conf"},
			purge:  func(m *Manager) []string { return []string{"memcached", "php*-memcached"} },
			check:  func(o *op) error { return o.dial("127.0.0.1:11211", "version\r\n", "VERSION") },
		},
		{
			id: "supervisor", packages: []string{"supervisor"}, marker: "/usr/bin/supervisord", versionPkg: "supervisor",
			units:  []string{"supervisor"},
			backup: []string{"/etc/supervisor"},
			inUse: func(m *Manager) []string {
				files, _ := filepath.Glob(m.h.Path("/etc/supervisor/conf.d/*.conf"))
				if len(files) == 0 {
					return nil
				}
				return []string{fmt.Sprintf("%d Supervisor programs", len(files))}
			},
			check: func(o *op) error {
				return o.retry(15*time.Second, func() error {
					_, err := o.quiet(host.Command{Name: "supervisorctl", Args: []string{"version"}})
					return err
				})
			},
		},
		{
			id:        "composer",
			installed: func(m *Manager) bool { return m.laravel.ComposerVersion(context.Background()) != "" },
			version:   func(ctx context.Context, m *Manager) string { return m.laravel.ComposerVersion(ctx) },
			latest:    func(ctx context.Context, m *Manager) (string, error) { return m.laravel.LatestComposer(ctx) },
			from:      "getcomposer.org",
			install: func(o *op) error {
				o.step("Composer indiriliyor (getcomposer.org, SHA-256 denetimiyle)")
				return o.m.laravel.InstallComposer(o.ctx)
			},
			update: func(o *op) error {
				o.step("Composer'ın son sürümü indiriliyor")
				return o.m.laravel.InstallComposer(o.ctx)
			},
			remove: func(o *op) error {
				o.step("Composer siliniyor")
				return o.m.laravel.RemoveComposer()
			},
		},
		{
			id: "python", packages: []string{"python3-pip", "python3-venv", "python3-dev"}, marker: "/usr/bin/pip3", versionPkg: "python3",
			check: func(o *op) error {
				_, err := o.run(host.Command{Name: "python3", Args: []string{"-m", "venv", "--help"}})
				return err
			},
		},
		fail2ban(),
		modsecurity(),
		clamav(),
	}
	for _, v := range PHPVersions {
		cs = append(cs, phpComponent(v))
	}
	for _, line := range NodeLines {
		cs = append(cs, nodeComponent(line))
	}
	for _, p := range []string{"mysql", "mariadb", "postgresql"} {
		cs = append(cs, databaseComponent(p))
	}
	return cs
}

func phpComponent(v string) *component {
	return &component{
		id: "php" + v, packages: phpPackages(v), marker: "/usr/sbin/php-fpm" + v, versionPkg: "php" + v + "-fpm",
		ppa: true,
		extra: func(m *Manager) []string {
			var out []string
			if m.comps["redis"].isInstalled(m) {
				out = append(out, "php"+v+"-redis")
			}
			if m.comps["memcached"].isInstalled(m) {
				out = append(out, "php"+v+"-memcached")
			}
			return out
		},
		units:  []string{"php" + v + "-fpm"},
		backup: []string{"/etc/php/" + v},
		// Servik's php.ini defaults (Hizmetler → PHP sürümleri); a
		// version that has its own keeps them.
		configure: func(o *op) error {
			if o.m.PHP == nil {
				return nil
			}
			return o.m.PHP.WriteDefaults(o.t, v)
		},
		purge: func(m *Manager) []string { return []string{"php" + v + "-*"} },
		check: func(o *op) error {
			_, err := o.run(host.Command{Name: "php-fpm" + v, Args: []string{"-t"}})
			return err
		},
		inUse: func(m *Manager) []string {
			var used []string
			pools, _ := filepath.Glob(m.h.Path("/etc/php/" + v + "/fpm/pool.d/*.conf"))
			for _, p := range pools {
				name := strings.TrimSuffix(filepath.Base(p), ".conf")
				switch name {
				case "www":
				case "servik-webmail":
					used = append(used, "webmail (Roundcube)")
				default:
					used = append(used, name)
				}
			}
			if cfg, ok := panel.Load(m.h); ok && cfg.PHP == v {
				used = append(used, "Servik paneli")
			} else if m.db.AdminPHP() == v {
				used = append(used, "phpMyAdmin / Adminer")
			}
			return used
		},
	}
}

func nodeComponent(line string) *component {
	return &component{
		id: "node" + line,
		installed: func(m *Manager) bool {
			_, ok := m.laravel.NodeVersions()[line]
			return ok
		},
		version:   func(_ context.Context, m *Manager) string { return m.laravel.NodeVersions()[line] },
		latest:    func(ctx context.Context, m *Manager) (string, error) { return m.laravel.LatestNode(ctx, line) },
		from:      "nodejs.org",
		isDefault: func(m *Manager) bool { return m.laravel.DefaultNode() == line },
		install: func(o *op) error {
			o.step("Node.js %s indiriliyor (nodejs.org, SHA-256 denetimiyle)", line)
			v, err := o.m.laravel.InstallNode(o.ctx, line)
			if err == nil {
				fmt.Fprintln(o.w, "  "+i18n.T(o.ctx, "Node.js %s kuruldu", v))
			}
			return err
		},
		update: func(o *op) error {
			o.step("Node.js %s son sürümü indiriliyor", line)
			_, err := o.m.laravel.InstallNode(o.ctx, line)
			return err
		},
		remove: func(o *op) error {
			o.step("Node.js %s siliniyor", line)
			return o.m.laravel.RemoveNode(line)
		},
	}
}

// databaseComponent hands the database servers to package database, which
// already installs them with a rollback (Veritabanları).
func databaseComponent(product string) *component {
	server := product
	if product == "mariadb" {
		server = "mysql"
	}
	units := func(m *Manager) []string {
		if p, ok := m.db.Installed()[server]; ok && p[0] == product {
			return []string{p[1]}
		}
		return nil
	}
	c := &component{
		id: product,
		installed: func(m *Manager) bool {
			p, ok := m.db.Installed()[server]
			return ok && p[0] == product
		},
		unitList: units,
		choices: func(ctx context.Context, m *Manager, probe bool) []database.Release {
			return m.db.Choices(ctx, product, probe)
		},
		install: func(o *op) error {
			o.step("%s kuruluyor ve ayarlanıyor", dbLabel[product])
			if _, err := o.m.db.Install(o.ctx, database.InstallSpec{Product: product, Version: o.version, Log: o.w}); err != nil {
				return err
			}
			o.step("Web yöneticisi (phpMyAdmin, Adminer) hazırlanıyor")
			_, err := o.m.db.SetupManager(o.ctx, database.ManagerSpec{Port: o.port})
			return err
		},
		remove: func(o *op) error {
			return o.m.db.Remove(o.ctx, product, o.w)
		},
	}
	switch product {
	case "mysql":
		c.versionPkg, c.packages = "mysql-server", []string{"mysql-server"}
	case "mariadb":
		c.versionPkg, c.packages = "mariadb-server", []string{"mariadb-server", "mariadb-client"}
	case "postgresql":
		// The customers' cluster stays on the major version it was
		// created with; updates bring that version's minor releases.
		pkg := func(m *Manager) string {
			if v := m.db.PGVersion(); v != "" {
				return "postgresql-" + v
			}
			return ""
		}
		c.version = func(ctx context.Context, m *Manager) string {
			p := pkg(m)
			if p == "" {
				return ""
			}
			if v := aptcache.Upstream(m.versions(ctx, []string{p})[p]); v != "" {
				return v
			}
			return strings.TrimPrefix(p, "postgresql-")
		}
		c.latest = func(ctx context.Context, m *Manager) (string, error) {
			p := pkg(m)
			if p == "" {
				return "", nil
			}
			return aptcache.Upstream(m.candidates(ctx, []string{p})[p].Version), nil
		}
		c.update = func(o *op) error {
			v := o.m.db.PGVersion()
			o.t = txn.New(o.m.h)
			pkgs := []string{"postgresql-" + v, "postgresql-client-" + v}
			o.step("Yeni sürüm kuruluyor: %s", strings.Join(pkgs, " "))
			args := append(append([]string{"install", "--only-upgrade"}, aptOpts...), pkgs...)
			if _, err := o.run(host.Command{Name: "apt-get", Args: args, Env: aptEnv}); err != nil {
				return err
			}
			o.step("Sunucu denetleniyor")
			_, err := o.m.db.Install(o.ctx, database.InstallSpec{Product: product, Log: o.w})
			return err
		}
	}
	if product != "postgresql" {
		c.update = func(o *op) error {
			o.t = txn.New(o.m.h)
			o.step("Yeni sürüm kuruluyor: %s", strings.Join(c.packages, " "))
			args := append(append([]string{"install", "--only-upgrade"}, aptOpts...), c.packages...)
			if _, err := o.run(host.Command{Name: "apt-get", Args: args, Env: aptEnv}); err != nil {
				return err
			}
			o.step("Sunucu denetleniyor")
			_, err := o.m.db.Install(o.ctx, database.InstallSpec{Product: product, Log: o.w})
			return err
		}
	}
	return c
}

var dbLabel = map[string]string{"mysql": "MySQL", "mariadb": "MariaDB", "postgresql": "PostgreSQL"}

// dial connects to a local service, sends a line and expects an answer
// starting with want.
func (o *op) dial(addr, send, want string) error {
	if o.m.sim {
		return nil
	}
	return o.retry(15*time.Second, func() error {
		conn, err := net.DialTimeout("tcp", addr, 2*time.Second)
		if err != nil {
			return err
		}
		defer conn.Close()
		_ = conn.SetDeadline(time.Now().Add(3 * time.Second))
		if _, err := conn.Write([]byte(send)); err != nil {
			return err
		}
		buf := make([]byte, 256)
		n, err := conn.Read(buf)
		if err != nil {
			return err
		}
		if !strings.HasPrefix(string(buf[:n]), want) {
			return fmt.Errorf("%s answered %q", addr, strings.TrimSpace(string(buf[:n])))
		}
		return nil
	})
}

// retry calls f until it succeeds or the time is up.
func (o *op) retry(d time.Duration, f func() error) error {
	if o.m.sim {
		return nil
	}
	deadline := time.Now().Add(d)
	for {
		err := f()
		if err == nil || time.Now().After(deadline) {
			return err
		}
		select {
		case <-o.ctx.Done():
			return o.ctx.Err()
		case <-time.After(time.Second):
		}
	}
}

// ---- Apache ----

var apachePorts = []byte(`# ` + txn.Marker + `. Manual changes are overwritten.
# Apache runs behind Nginx and only listens on the loopback address;
# Nginx answers visitors on ports 80 and 443.
Listen 127.0.0.1:` + fmt.Sprint(site.ApachePort) + `
`)

var apacheModules = []string{"proxy_fcgi", "setenvif", "rewrite", "remoteip", "headers", "expires", "deflate"}

func apache() *component {
	return &component{
		id: "apache", packages: []string{"apache2"}, versionPkg: "apache2",
		installed: func(m *Manager) bool { return m.sites.ApacheReady() },
		units:     []string{"apache2"},
		backup:    []string{"/etc/apache2"},
		// Written before the package arrives, so Apache never tries port
		// 80, where Nginx listens.
		beforeInstall: func(o *op) error {
			if err := o.t.RootDir("/etc/apache2", 0o755, 0, 0); err != nil {
				return err
			}
			if err := o.t.TakeOver(site.ApachePorts); err != nil {
				return err
			}
			return o.t.WriteFile(site.ApachePorts, apachePorts)
		},
		configure: func(o *op) error {
			if err := o.t.TakeOver(site.ApachePorts); err != nil {
				return err
			}
			if err := o.t.WriteFile(site.ApachePorts, apachePorts); err != nil {
				return err
			}
			if err := o.t.WriteFile(tuning.ApacheConf, tuning.ApacheContent(tuning.Load(o.m.h).Apache)); err != nil {
				return err
			}
			// prefork (mod_php) is never used: PHP runs in PHP-FPM.
			if o.m.exists("/etc/apache2/mods-enabled/mpm_prefork.load") {
				if _, err := o.run(host.Command{Name: "a2dismod", Args: []string{"-q", "mpm_prefork"}}); err != nil {
					return err
				}
				if _, err := o.run(host.Command{Name: "a2enmod", Args: []string{"-q", "mpm_event"}}); err != nil {
					return err
				}
			}
			for _, cmd := range [][]string{
				append([]string{"a2enmod", "-q"}, apacheModules...),
				{"a2enconf", "-q", "servik"},
				{"a2disconf", "-q", "other-vhosts-access-log"},
				{"a2dissite", "-q", "000-default"},
			} {
				if _, err := o.quiet(host.Command{Name: cmd[0], Args: cmd[1:]}); err != nil && cmd[0] != "a2disconf" && cmd[0] != "a2dissite" {
					return err
				}
			}
			return nil
		},
		check: func(o *op) error {
			if _, err := o.run(host.Command{Name: "apache2ctl", Args: []string{"configtest"}}); err != nil {
				return err
			}
			return o.dial(fmt.Sprintf("127.0.0.1:%d", site.ApachePort), "HEAD / HTTP/1.0\r\n\r\n", "HTTP/")
		},
		inUse: func(m *Manager) []string {
			var sites []string
			files, _ := filepath.Glob(m.h.Path("/etc/apache2/sites-enabled/*.conf"))
			for _, f := range files {
				// The links point at sites-available by absolute path.
				data, err := os.ReadFile(m.h.Path("/etc/apache2/sites-available/" + filepath.Base(f)))
				if err == nil && txn.HasMarker(data) {
					sites = append(sites, strings.TrimSuffix(filepath.Base(f), ".conf"))
				}
			}
			sort.Strings(sites)
			return sites
		},
	}
}

// ---- Redis ----

var redisReadmeText = []byte(`# ` + txn.Marker + `. Manual changes are overwritten.
#
# Customers' Redis: every application that uses Redis gets its own
# instance (servik-redis-<domain>.service), running as the site's user,
# listening on 127.0.0.1 with a password, with its data in
# /var/lib/servik/redis/<domain>. The distribution's redis-server.service
# stays off, so applications never share keys.
`)

func configureRedis(o *op) error {
	if err := o.t.RootDir("/etc/servik", 0o755, 0, 0); err != nil {
		return err
	}
	if err := o.t.RootDir(redisDir, 0o755, 0, 0); err != nil {
		return err
	}
	if err := o.t.WriteFile(redisReadme, redisReadmeText); err != nil {
		return err
	}
	// The shared instance the package starts is not used by anyone.
	_, _ = o.quiet(host.Command{Name: "systemctl", Args: []string{"disable", "--now", "redis-server.service"}})
	// PHP-FPM loads the new extension on restart.
	for _, v := range o.m.installedPHP() {
		_, _ = o.quiet(host.Command{Name: "systemctl", Args: []string{"reload", "php" + v + "-fpm"}})
	}
	return nil
}

// ---- Fail2ban ----

func fail2ban() *component {
	return &component{
		id: "fail2ban", packages: []string{"fail2ban", "python3-systemd", "nftables"}, marker: "/usr/bin/fail2ban-server", versionPkg: "fail2ban",
		purge:     func(m *Manager) []string { return []string{"fail2ban"} },
		units:     []string{"fail2ban"},
		backup:    []string{"/etc/fail2ban"},
		configure: func(o *op) error { return writeJails(o.t, o.m) },
		check: func(o *op) error {
			if _, err := o.run(host.Command{Name: "fail2ban-client", Args: []string{"--test"}}); err != nil {
				return err
			}
			return o.retry(20*time.Second, func() error {
				_, err := o.quiet(host.Command{Name: "fail2ban-client", Args: []string{"ping"}})
				return err
			})
		},
		unconfigure: func(o *op) error {
			if err := o.t.RemoveFile(panelFilter); err != nil {
				return err
			}
			return o.t.RemoveFile(jailConf)
		},
	}
}

const (
	jailConf    = "/etc/fail2ban/jail.d/servik.conf"
	panelFilter = "/etc/fail2ban/filter.d/servik-panel.conf"
)

// The panel logs failed logins to the journal as servik-panel.
var panelFilterConf = []byte(`# ` + txn.Marker + `. Manual changes are overwritten.
# Failed logins to the Servik panel, logged by the panel to the journal.
[INCLUDES]
before = common.conf

[DEFAULT]
_daemon = servik-panel

[Definition]
failregex = ^%(__prefix_line)sFailed panel login from <HOST>(?: |$)
ignoreregex =
journalmatch = SYSLOG_IDENTIFIER=servik-panel
`)

func writeJails(t *txn.Tx, m *Manager) error {
	for _, d := range []string{"/etc/fail2ban/jail.d", "/etc/fail2ban/filter.d"} {
		if err := t.RootDir(d, 0o755, 0, 0); err != nil {
			return err
		}
	}
	if err := t.WriteFile(panelFilter, panelFilterConf); err != nil {
		return err
	}
	return t.WriteFile(jailConf, renderJails(m))
}

// renderJails writes Servik's jails: SSH always, mail logins when the
// mail server is installed, and repeat offenders for a week.
func renderJails(m *Manager) []byte {
	ignore := []string{"127.0.0.1/8", "::1"}
	if m.Addresses != nil {
		// Sorted, so the order interfaces are listed in never counts as a
		// change (the panel rewrites jails that are not current).
		own := slices.Clone(m.Addresses())
		slices.Sort(own)
		ignore = append(ignore, slices.Compact(own)...)
	}
	mail := m.exists("/usr/sbin/postfix") && m.exists("/usr/sbin/dovecot")
	var b strings.Builder
	fmt.Fprintf(&b, `# %s. Manual changes are overwritten.
# Servik's jails. Bans use nftables (table inet f2b-table) and end on
# their own. Addresses of this server are never banned.

[DEFAULT]
backend = systemd
banaction = nftables-multiport
banaction_allports = nftables-allports
bantime = 1h
findtime = 10m
maxretry = 5
ignoreip = %s

[sshd]
enabled = true

# Logins to the Servik panel: ten failures in 15 minutes close the
# panel's port to that address for an hour.
[servik-panel]
enabled = true
filter = servik-panel
port = %d
maxretry = 10
findtime = 15m
bantime = 1h
`, txn.Marker, strings.Join(ignore, " "), m.panelPort())
	if mail {
		b.WriteString(`
[dovecot]
enabled = true

[postfix-sasl]
enabled = true
`)
	}
	b.WriteString(`
# Addresses banned five times within a day stay out for a week.
[recidive]
enabled = true
backend = auto
logpath = /var/log/fail2ban.log
bantime = 1w
findtime = 1d
maxretry = 5
`)
	return []byte(b.String())
}

// ---- ModSecurity ----

const (
	modsecDir     = "/etc/servik/modsecurity"
	modsecMain    = modsecDir + "/main.conf"
	modsecCRS     = modsecDir + "/crs-setup.conf"
	modsecNginx   = "/etc/nginx/conf.d/servik-modsecurity.conf"
	modsecLogDir  = "/var/log/servik/modsecurity"
	modsecModule  = "/usr/lib/nginx/modules/ngx_http_modsecurity_module.so"
	modsecCRSRule = "/usr/share/modsecurity-crs/rules"
	// ModSecurityLog is the audit log: one JSON object per line for each
	// request a rule matched (package security reads it).
	ModSecurityLog  = modsecLogDir + "/audit.log"
	modsecLogrotate = "/etc/logrotate.d/servik-modsecurity"
)

// The audit log is written by libmodsecurity itself, which never reopens
// it, so it is truncated in place.
var modsecRotate = []byte(`# ` + txn.Marker + `. Manual changes are overwritten.
` + ModSecurityLog + ` {
	daily
	rotate 14
	maxsize 100M
	missingok
	notifempty
	compress
	delaycompress
	copytruncate
}
`)

func modsecurity() *component {
	return &component{
		id: "modsecurity", packages: []string{"libnginx-mod-http-modsecurity", "modsecurity-crs"}, versionPkg: "modsecurity-crs",
		installed: func(m *Manager) bool { return m.exists(modsecModule) && m.exists(modsecNginx) },
		backup:    []string{modsecDir},
		settings: func(m *Manager) map[string]any {
			s := m.loadSettings()
			return map[string]any{"mode": s.ModSecurityMode, "paranoia": s.ModSecurityParanoia}
		},
		configure: func(o *op) error { return writeModsec(o, o.m.loadSettings()) },
		check:     reloadNginx,
		applySettings: func(o *op, s Settings) error {
			if s.ModSecurityMode != "detect" && s.ModSecurityMode != "block" {
				return invalid("mode must be detect or block")
			}
			if s.ModSecurityParanoia < 1 || s.ModSecurityParanoia > 4 {
				return invalid("paranoia must be between 1 and 4")
			}
			o.t = txn.New(o.m.h)
			cur := o.m.loadSettings()
			cur.ModSecurityMode, cur.ModSecurityParanoia = s.ModSecurityMode, s.ModSecurityParanoia
			if err := o.m.writeSettings(o.t, cur); err != nil {
				return err
			}
			if err := writeModsec(o, cur); err != nil {
				_ = o.t.Rollback(context.WithoutCancel(o.ctx))
				return err
			}
			if err := reloadNginx(o); err != nil {
				_ = o.t.Rollback(context.WithoutCancel(o.ctx))
				_, _ = o.m.h.Run(context.WithoutCancel(o.ctx), host.Command{Name: "systemctl", Args: []string{"reload", "nginx"}})
				return err
			}
			return nil
		},
		unconfigure: func(o *op) error {
			// Sites' own WAF settings use the module's directives too; the
			// panel writes them again after a new install.
			dirs, _ := os.ReadDir(o.m.h.Path(site.ExtraRoot))
			for _, d := range dirs {
				for _, name := range []string{"waf.conf", "waf.rules"} {
					if err := o.t.RemoveFile(site.ExtraRoot + "/" + d.Name() + "/" + name); err != nil {
						return err
					}
				}
			}
			if err := o.t.RemoveFile(modsecLogrotate); err != nil {
				return err
			}
			if err := o.t.RemoveFile(modsecNginx); err != nil {
				return err
			}
			return reloadNginx(o)
		},
	}
}

func reloadNginx(o *op) error {
	if _, err := o.run(host.Command{Name: "nginx", Args: []string{"-t"}}); err != nil {
		return fmt.Errorf("nginx rejected the configuration: %w", err)
	}
	_, err := o.run(host.Command{Name: "systemctl", Args: []string{"reload", "nginx"}})
	return err
}

func writeModsec(o *op, s Settings) error {
	t := o.t
	web, err := o.m.h.LookupUser("www-data")
	if err != nil {
		return err
	}
	for _, d := range []string{"/etc/servik", modsecDir, "/var/log/servik"} {
		if err := t.RootDir(d, 0o755, 0, 0); err != nil {
			return err
		}
	}
	if err := t.RootDir(modsecLogDir, 0o750, web.UID, 0); err != nil {
		return err
	}
	engine := "DetectionOnly"
	if s.ModSecurityMode == "block" {
		engine = "On"
	}
	main := fmt.Sprintf(`# %s. Manual changes are overwritten.
# ModSecurity with the OWASP Core Rule Set, for every site.
# Server mode: Güvenlik > WAF; a site can have its own (package security).
Include /etc/nginx/modsecurity.conf
SecRuleEngine %s
# Responses are not inspected: it costs every page and breaks downloads.
SecResponseBodyAccess Off
# Requests a rule matched, as JSON lines, for Güvenlik > WAF. Request
# bodies and responses are left out.
SecAuditEngine RelevantOnly
SecAuditLogParts ABHZ
SecAuditLogFormat JSON
SecAuditLogType Serial
SecAuditLog %s
SecTmpDir /tmp/
SecDataDir /tmp/
Include %s
Include %s/*.conf
`, txn.Marker, engine, ModSecurityLog, modsecCRS, modsecCRSRule)
	crs := fmt.Sprintf(`# %s. Manual changes are overwritten.
# OWASP CRS settings: anomaly scoring, paranoia level %d.
SecDefaultAction "phase:1,log,auditlog,pass"
SecDefaultAction "phase:2,log,auditlog,pass"
SecAction "id:900000,phase:1,nolog,pass,t:none,setvar:tx.paranoia_level=%d"
SecAction "id:900990,phase:1,nolog,pass,t:none,setvar:tx.crs_setup_version=335"
`, txn.Marker, s.ModSecurityParanoia, s.ModSecurityParanoia)
	nginx := fmt.Sprintf(`# %s. Manual changes are overwritten.
modsecurity on;
modsecurity_rules_file %s;
`, txn.Marker, modsecMain)
	if err := t.WriteFile(modsecMain, []byte(main)); err != nil {
		return err
	}
	if err := t.WriteFile(modsecCRS, []byte(crs)); err != nil {
		return err
	}
	if err := t.RootDir("/etc/logrotate.d", 0o755, 0, 0); err != nil {
		return err
	}
	if err := t.WriteFile(modsecLogrotate, modsecRotate); err != nil {
		return err
	}
	if err := t.RootDir("/etc/nginx/conf.d", 0o755, 0, 0); err != nil {
		return err
	}
	return t.WriteFile(modsecNginx, []byte(nginx))
}

// ---- ClamAV ----

const (
	clamSocket    = "/run/clamav/clamd.ctl"
	rspamdVirus   = "/etc/rspamd/local.d/antivirus.conf"
	clamDatabases = "/var/lib/clamav"
)

var rspamdAntivirus = []byte(`# ` + txn.Marker + `. Manual changes are overwritten.
# Incoming and outgoing mail is scanned by ClamAV; infected mail is
# rejected.
clamav {
  type = "clamav";
  servers = "` + clamSocket + `";
  action = "reject";
  scan_mime_parts = true;
  symbol = "CLAM_VIRUS";
  log_clean = false;
}
`)

func clamav() *component {
	return &component{
		id: "clamav", packages: []string{"clamav", "clamav-daemon", "clamav-freshclam"}, marker: "/usr/sbin/clamd", versionPkg: "clamav",
		purge: func(m *Manager) []string {
			return []string{"clamav", "clamav-daemon", "clamav-freshclam", "clamav-base"}
		},
		units:  []string{"clamav-freshclam", "clamav-daemon"},
		backup: []string{"/etc/clamav"},
		configure: func(o *op) error {
			if !o.m.sim && !hasClamDB(o.m) {
				o.step("Virüs imza veritabanı indiriliyor (yaklaşık 200 MB)")
				_, _ = o.quiet(host.Command{Name: "systemctl", Args: []string{"stop", "clamav-freshclam"}})
				if _, err := o.run(host.Command{Name: "freshclam", Args: []string{"--stdout", "--quiet"}}); err != nil {
					return err
				}
			}
			if o.m.exists("/usr/bin/rspamd") {
				if err := o.t.RootDir("/etc/rspamd/local.d", 0o755, 0, 0); err != nil {
					return err
				}
				return o.t.WriteFile(rspamdVirus, rspamdAntivirus)
			}
			return nil
		},
		check: func(o *op) error {
			if err := o.retry(3*time.Minute, func() error {
				if !o.m.exists(clamSocket) {
					return fmt.Errorf("clamd is not listening on %s yet", clamSocket)
				}
				return nil
			}); err != nil {
				return err
			}
			if o.m.exists(rspamdVirus) {
				if _, err := o.run(host.Command{Name: "rspamadm", Args: []string{"configtest"}}); err != nil {
					return err
				}
				_, err := o.run(host.Command{Name: "systemctl", Args: []string{"reload", "rspamd"}})
				return err
			}
			return nil
		},
		unconfigure: func(o *op) error {
			if err := o.t.RemoveFile(rspamdVirus); err != nil {
				return err
			}
			if o.m.exists("/usr/bin/rspamd") && o.t.Changed {
				_, err := o.run(host.Command{Name: "systemctl", Args: []string{"reload", "rspamd"}})
				return err
			}
			return nil
		},
	}
}

func hasClamDB(m *Manager) bool {
	for _, f := range []string{"main.cvd", "main.cld"} {
		if m.exists(clamDatabases + "/" + f) {
			return true
		}
	}
	return false
}

// simVersions are the versions a dry run shows.
var simVersions = map[string]string{
	"nginx": "1.24.0-2ubuntu7.5", "apache2": "2.4.58-1ubuntu8.8", "redis-server": "5:7.0.15-1ubuntu0.24.04.2",
	"memcached": "1.6.24-1build3", "supervisor": "4.2.5-1", "python3": "3.12.3-0ubuntu2",
	"fail2ban": "1.0.2-3ubuntu0.1", "modsecurity-crs": "3.3.5-2", "clamav": "1.4.3+dfsg-0ubuntu0.24.04.1",
	"php8.1-fpm": "8.1.33-1+ubuntu24.04.1+deb.sury.org+1", "php8.2-fpm": "8.2.29-1+ubuntu24.04.1+deb.sury.org+1",
	"php8.3-fpm": "8.3.6-0ubuntu0.24.04.5", "php8.4-fpm": "8.4.13-1+ubuntu24.04.1+deb.sury.org+1",
	"php8.5-fpm":   "8.5.0-1+ubuntu24.04.1+deb.sury.org+1",
	"mysql-server": "8.0.43-0ubuntu0.24.04.2", "mariadb-server": "1:10.11.13-0ubuntu0.24.04.1",
	"postgresql-16": "16.10-0ubuntu0.24.04.1", "postgresql-18": "18.1-1.pgdg24.04+2",
}

// simArchive is where a dry run says a package version comes from.
func simArchive(v string) string {
	switch {
	case strings.Contains(v, "deb.sury.org"):
		return "ppa.launchpadcontent.net/ondrej/php/ubuntu"
	case strings.Contains(v, "pgdg"):
		return "apt.postgresql.org/pub/repos/apt"
	}
	return "archive.ubuntu.com/ubuntu"
}

// simCandidates make a dry run show available updates.
var simCandidates = map[string]string{
	"nginx":         "1.24.0-2ubuntu7.6",
	"php8.3-fpm":    "8.3.6-0ubuntu0.24.04.6",
	"postgresql-18": "18.2-1.pgdg24.04+1",
}
