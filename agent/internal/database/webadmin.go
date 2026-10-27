package database

import (
	"context"
	"crypto/rand"
	"crypto/x509"
	"encoding/hex"
	"encoding/json"
	"encoding/pem"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"slices"
	"sort"
	"strings"
	"time"

	"github.com/calltech-app/serfex/agent/internal/cert"
	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/panel"
	"github.com/calltech-app/serfex/agent/internal/site"
	"github.com/calltech-app/serfex/agent/internal/txn"
)

// The web database manager runs on servik-web and servik-php, Servik's own
// Nginx and PHP-FPM processes with their own configuration, accounts and
// systemd units, on a port of their own. Customers' sites, PHP versions
// and pools never serve it. phpMyAdmin serves MySQL and MariaDB, Adminer
// PostgreSQL.
const (
	// WebUnit is Servik's own web server (also the panel's, see cert.PanelUnit).
	WebUnit = cert.PanelUnit
	// PHPUnit is Servik's own PHP-FPM.
	PHPUnit = "servik-php"
	// WebUser runs servik-web's workers.
	WebUser = "servik-web"
	// AdminUser runs the manager's PHP.
	AdminUser = "servik-dbadmin"

	// DefaultPort is where servik-web listens (HTTPS).
	DefaultPort = 8443
	// SessionTTL is how long a one-click sign-in lasts.
	SessionTTL = 4 * time.Hour
	// tokenTTL is how long a sign-in link can be opened.
	tokenTTL = time.Minute

	webDir      = "/etc/servik/web"
	webConf     = webDir + "/nginx.conf"
	phpConf     = webDir + "/php-fpm.conf"
	webTLSDir   = webDir + "/tls"
	webCert     = webTLSDir + "/fullchain.pem"
	webKey      = webTLSDir + "/privkey.pem"
	webLogs     = "/var/log/servik/web"
	webTemp     = "/var/lib/servik/web"
	adminDir    = "/var/lib/servik/dbadmin"
	adminWWW    = adminDir + "/www"
	adminTokens = adminDir + "/tokens"
	adminSess   = adminDir + "/sessions"
	adminTmp    = adminDir + "/tmp"
	phpSocket   = "/run/servik-php/dbadmin.sock"
	pmaConf     = "/etc/phpmyadmin/conf.d/servik.php"
	webUnitFile = "/etc/systemd/system/" + WebUnit + ".service"
	phpUnitFile = "/etc/systemd/system/" + PHPUnit + ".service"

	pmaIndex     = "/usr/share/phpmyadmin/index.php"
	adminerIndex = "/usr/share/adminer/adminer.php"
)

// sessionPrefix starts the names of temporary sign-in users. The hyphen
// keeps them apart from customer names.
const sessionPrefix = "servik-s-"

// ManagerSpec configures the web manager.
type ManagerSpec struct {
	Port int `json:"port"`
}

// ManagerStatus describes the web manager.
type ManagerStatus struct {
	// Configured is true once db.manager.setup ran.
	Configured bool   `json:"configured"`
	Port       int    `json:"port,omitempty"`
	PhpMyAdmin bool   `json:"phpmyadmin"`
	Adminer    bool   `json:"adminer"`
	PHPVersion string `json:"php_version,omitempty"`
}

func (m *Manager) managerStatus(context.Context) ManagerStatus {
	st, err := m.loadState()
	if err != nil || st.Manager == nil {
		return ManagerStatus{}
	}
	return ManagerStatus{
		Configured: true,
		Port:       m.webPort(st),
		PhpMyAdmin: m.exists(pmaIndex) && m.exists(phpConf),
		Adminer:    m.exists(adminerIndex) && m.exists(phpConf),
		PHPVersion: m.adminPHP(),
	}
}

// adminPHP is the PHP version servik-php runs (from its unit), if any.
func (m *Manager) adminPHP() string {
	data, err := os.ReadFile(m.h.Path(phpUnitFile))
	if err != nil {
		return ""
	}
	if _, rest, ok := strings.Cut(string(data), "/usr/sbin/php-fpm"); ok {
		v, _, _ := strings.Cut(rest, " ")
		return v
	}
	return ""
}

// SetupManager installs phpMyAdmin (when MySQL or MariaDB is installed)
// and Adminer (when PostgreSQL is), and runs them on servik-web and
// servik-php. Running it again after a server is installed adds that
// server's tool. Configuration changes are rolled back if a service
// rejects them.
func (m *Manager) SetupManager(ctx context.Context, spec ManagerSpec) (res ManagerStatus, err error) {
	if spec.Port != 0 && (spec.Port < 1 || spec.Port > 65535 || spec.Port == 80 || spec.Port == 443) {
		return ManagerStatus{}, invalid("port must be between 1 and 65535, and not 80 or 443 (the sites' ports)")
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	_, mysql := m.installedProduct(MySQL)
	_, pg := m.installedProduct(PostgreSQL)
	if !mysql && !pg {
		return ManagerStatus{}, invalid("install a database server first")
	}
	st, err := m.loadState()
	if err != nil {
		return ManagerStatus{}, opError(err)
	}
	// A port given replaces servik-web's ports; without one they stay.
	ports := m.webPorts(st)
	if spec.Port != 0 {
		ports = []int{spec.Port}
	}
	php, err := m.applyWeb(ctx, ports, mysql, pg)
	if err != nil {
		return ManagerStatus{}, err
	}
	st.Manager = &ManagerSpec{Port: ports[0]}
	if spec.Port != 0 {
		st.WebPorts = ports
	}
	if err := m.saveState(st); err != nil {
		return ManagerStatus{}, err
	}
	return ManagerStatus{Configured: true, Port: ports[0], PhpMyAdmin: mysql, Adminer: pg, PHPVersion: php}, nil
}

// WebStatus describes servik-web as the panel uses it.
type WebStatus struct {
	// Port is the primary port; Ports are all servik-web listens on.
	Port       int    `json:"port"`
	Ports      []int  `json:"ports"`
	PHPVersion string `json:"php_version"`
	Panel      bool   `json:"panel"`
}

// SetupPanelWeb serves the installed panel on servik-web and servik-php,
// next to the database manager when one is set up (install.sh and each
// update run it). It changes nothing that is already right.
func (m *Manager) SetupPanelWeb(ctx context.Context) (WebStatus, error) {
	if !panel.Installed(m.h) {
		return WebStatus{}, invalid("the panel is not installed in %s", panel.CurrentLink)
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	_, mysql := m.installedProduct(MySQL)
	_, pg := m.installedProduct(PostgreSQL)
	st, err := m.loadState()
	if err != nil {
		return WebStatus{}, opError(err)
	}
	if st.Manager == nil {
		// Without a manager the tools stay off until db.manager.setup.
		mysql, pg = false, false
	}
	ports := m.webPorts(st)
	php, err := m.applyWeb(ctx, ports, mysql, pg)
	if err != nil {
		return WebStatus{}, err
	}
	return WebStatus{Port: ports[0], Ports: ports, PHPVersion: php, Panel: true}, nil
}

// WebPort is the primary port servik-web listens on (the panel's port).
func (m *Manager) WebPort() int {
	st, _ := m.loadState()
	return m.webPort(st)
}

func (m *Manager) webPort(st *state) int {
	return m.webPorts(st)[0]
}

// webPorts are the ports servik-web listens on, the primary first: the
// ones panel.web.ports set, else the database manager's (older setups),
// else DefaultPort.
func (m *Manager) webPorts(st *state) []int {
	switch {
	case st != nil && len(st.WebPorts) > 0:
		return append([]int(nil), st.WebPorts...)
	case st != nil && st.Manager != nil && st.Manager.Port != 0:
		return []int{st.Manager.Port}
	}
	return []int{DefaultPort}
}

// applyWeb writes servik-web's and servik-php's configuration for the
// tools asked for and, when installed, the panel, then (re)starts what
// changed. servik-web listens on every port of ports. Called with m.mu
// held.
func (m *Manager) applyWeb(ctx context.Context, ports []int, mysql, pg bool) (php string, err error) {
	cfg, withPanel := panel.Load(m.h)
	withPanel = withPanel && panel.Installed(m.h)

	t := txn.New(m.h)
	started := false
	defer func() {
		if err == nil {
			return
		}
		bg := context.WithoutCancel(ctx)
		if rbErr := t.Rollback(bg); rbErr != nil {
			err = fmt.Errorf("%w (rollback also failed: %v)", err, rbErr)
		}
		if started {
			_, _ = m.h.Run(bg, host.Command{Name: "systemctl", Args: []string{"daemon-reload"}})
			_, _ = m.h.Run(bg, host.Command{Name: "systemctl", Args: []string{"restart", PHPUnit, WebUnit}})
		}
		err = opError(err)
	}()

	var packages, markers []string
	if withPanel {
		// The tools' PHP is the panel's, so servik-php runs one version.
		v := cfg.PHP
		packages = []string{"nginx", "php" + v + "-fpm", "php" + v + "-mbstring", "php" + v + "-xml"}
		if mysql {
			packages = append(packages, "php"+v+"-mysql")
		}
		if pg {
			packages = append(packages, "php"+v+"-pgsql")
		}
		for _, p := range packages[1:] {
			markers = append(markers, map[string]string{
				"fpm": "/usr/sbin/php-fpm" + v, "mbstring": "/etc/php/" + v + "/mods-available/mbstring.ini",
				"xml": "/etc/php/" + v + "/mods-available/xml.ini", "mysql": "/etc/php/" + v + "/mods-available/mysqli.ini",
				"pgsql": "/etc/php/" + v + "/mods-available/pgsql.ini",
			}[strings.TrimPrefix(p, "php"+v+"-")])
		}
	} else {
		packages = []string{"nginx", "php-fpm", "php-mbstring", "php-xml"}
	}
	if mysql {
		packages = append(packages, "phpmyadmin")
		if !withPanel {
			packages = append(packages, "php-mysql")
		}
		markers = append(markers, pmaIndex)
	}
	if pg {
		packages = append(packages, "adminer")
		if !withPanel {
			packages = append(packages, "php-pgsql")
		}
		markers = append(markers, adminerIndex)
	}
	missing := !m.exists("/usr/sbin/nginx")
	for _, f := range markers {
		missing = missing || !m.exists(f)
	}
	if missing {
		if err := installPackages(ctx, m.h, packages, pmaPreseed, nil); err != nil {
			return "", err
		}
	}
	if withPanel {
		php = cfg.PHP
	} else if php, err = m.pickPHP(mysql, pg); err != nil {
		return "", err
	}

	web, err := ensureAccount(ctx, m.h, WebUser, "/nonexistent", "Servik web server")
	if err != nil {
		return "", err
	}
	admin, err := ensureAccount(ctx, m.h, AdminUser, adminDir, "Servik database manager")
	if err != nil {
		return "", err
	}
	for _, d := range []struct {
		path     string
		mode     os.FileMode
		uid, gid int
	}{
		{"/etc/servik", 0o755, 0, 0},
		{webDir, 0o755, 0, 0},
		{webTLSDir, 0o750, 0, 0},
		{"/var/log/servik", 0o755, 0, 0},
		{webLogs, 0o750, 0, 0},
		{"/var/lib/servik", 0o755, 0, 0},
		{webTemp, 0o750, web.UID, web.GID},
		{adminDir, 0o755, 0, 0},
		{adminWWW, 0o755, 0, 0},
		{adminTokens, 0o700, admin.UID, admin.GID},
		{adminSess, 0o700, admin.UID, admin.GID},
		{adminTmp, 0o700, admin.UID, admin.GID},
	} {
		if err := t.RootDir(d.path, d.mode, d.uid, d.gid); err != nil {
			return "", err
		}
	}

	data, err := m.webConfData(t, ports, mysql, pg, withPanel)
	if err != nil {
		return "", err
	}
	data.PHP = php
	if err := t.RootDir("/etc/servik/nginx", 0o755, 0, 0); err != nil {
		return "", err
	}
	if err := t.WriteFile(cert.TLSConf, site.TLSConfig()); err != nil {
		return "", err
	}
	restart := map[string]bool{}
	track := func(unit string, write func() error) error {
		before := t.Changed
		t.Changed = false
		err := write()
		restart[unit] = restart[unit] || t.Changed
		t.Changed = t.Changed || before
		return err
	}
	daemonReload := false
	if err := track(WebUnit, func() error {
		if err := t.WriteFile(webConf, render(webConfTmpl, data)); err != nil {
			return err
		}
		before := t.Changed
		if err := t.WriteFile(webUnitFile, render(webUnitTmpl, data)); err != nil {
			return err
		}
		daemonReload = daemonReload || t.Changed != before
		return nil
	}); err != nil {
		return "", err
	}
	if err := track(PHPUnit, func() error {
		if err := t.WriteFile(phpConf, render(phpConfTmpl, data)); err != nil {
			return err
		}
		before := t.Changed
		if err := t.WriteFile(phpUnitFile, render(phpUnitTmpl, data)); err != nil {
			return err
		}
		daemonReload = daemonReload || t.Changed != before
		for name, tmpl := range adminPages {
			if err := t.WriteFile(adminWWW+"/"+name, render(tmpl, data)); err != nil {
				return err
			}
		}
		if mysql {
			secret, err := m.blowfishSecret()
			if err != nil {
				return err
			}
			data.Secret = secret
			return t.WriteFileMode(pmaConf, render(pmaConfTmpl, data), 0o640, 0, admin.GID)
		}
		return nil
	}); err != nil {
		return "", err
	}

	for _, c := range []host.Command{
		{Name: "php-fpm" + php, Args: []string{"-t", "--fpm-config", phpConf}},
		{Name: "nginx", Args: []string{"-t", "-q", "-c", webConf}},
	} {
		if _, err := m.h.Run(ctx, c); err != nil {
			return "", fmt.Errorf("%s rejected the configuration: %w", c.Name, err)
		}
	}
	started = true
	if daemonReload {
		if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"daemon-reload"}}); err != nil {
			return "", err
		}
	}
	if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"enable", PHPUnit, WebUnit}}); err != nil {
		return "", err
	}
	for _, u := range []string{PHPUnit, WebUnit} {
		verb := "start"
		if restart[u] {
			verb = "restart"
		}
		if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{verb, u}}); err != nil {
			return "", fmt.Errorf("%s did not start (see journalctl -u %s): %w", u, u, err)
		}
	}
	return php, nil
}

// debconf answers that keep phpMyAdmin from configuring Apache or a
// database of its own.
const pmaPreseed = `phpmyadmin phpmyadmin/dbconfig-install boolean false
phpmyadmin phpmyadmin/reconfigure-webserver multiselect
`

// pickPHP returns the newest PHP-FPM version with the extensions the
// tools need.
func (m *Manager) pickPHP(mysql, pg bool) (string, error) {
	entries, _ := os.ReadDir(m.h.Path("/etc/php"))
	var versions []string
	for _, e := range entries {
		v := e.Name()
		if site.ValidatePHPVersion(v) != nil || !m.exists("/usr/sbin/php-fpm"+v) {
			continue
		}
		mods := []string{"mbstring", "xml"}
		if mysql {
			mods = append(mods, "mysqli")
		}
		if pg {
			mods = append(mods, "pgsql")
		}
		ok := true
		for _, mod := range mods {
			ok = ok && m.exists("/etc/php/"+v+"/mods-available/"+mod+".ini")
		}
		if ok {
			versions = append(versions, v)
		}
	}
	sort.Slice(versions, func(i, j int) bool { return phpLess(versions[i], versions[j]) })
	if len(versions) == 0 {
		return "", errors.New("no PHP-FPM version has the extensions the database manager needs (mysqli or pgsql, mbstring, xml)")
	}
	return versions[len(versions)-1], nil
}

func phpLess(a, b string) bool {
	am, an, _ := strings.Cut(a, ".")
	bm, bn, _ := strings.Cut(b, ".")
	if am != bm {
		return len(am) < len(bm) || (len(am) == len(bm) && am < bm)
	}
	return len(an) < len(bn) || (len(an) == len(bn) && an < bn)
}

// webConfData is what servik-web's configuration is made from.
func (m *Manager) webConfData(t *txn.Tx, ports []int, mysql, pg, withPanel bool) (webData, error) {
	certFile, keyFile, err := m.webTLS(t)
	if err != nil {
		return webData{}, err
	}
	data := webData{
		Marker: txn.Marker, Ports: ports, IPv6: m.hasIPv6(), Cert: certFile, Key: keyFile, Socket: phpSocket,
		WWW: adminWWW, Logs: webLogs, Temp: webTemp, WebUser: WebUser, AdminUser: AdminUser,
		PhpMyAdmin: mysql, Adminer: pg, Tokens: adminTokens, Sessions: adminSess, Tmp: adminTmp, TLSConf: cert.TLSConf,
		Panel: withPanel, PanelRoot: panel.CurrentLink + "/panel/public", PanelSocket: panel.Socket, PanelUser: panel.User,
	}
	data.PanelCert, data.PanelKey = cert.PanelFiles()
	data.PanelNames = m.panelNames(data.PanelCert, data.PanelKey)
	return data, nil
}

// webTLS returns servik-web's own self-signed certificate, made on first
// use. It answers on the IP address and every name but the panel's, so a
// page opened before the panel had a certificate keeps working after.
func (m *Manager) webTLS(t *txn.Tx) (string, string, error) {
	if m.exists(webCert) && m.exists(webKey) {
		return webCert, webKey, nil
	}
	name, _ := os.Hostname()
	if name == "" {
		name = "localhost"
	}
	certPEM, keyPEM, err := selfSigned(name)
	if err != nil {
		return "", "", err
	}
	if err := t.WriteOwned(webKey, keyPEM, 0o600, 0, 0); err != nil {
		return "", "", err
	}
	return webCert, webKey, t.WriteOwned(webCert, certPEM, 0o644, 0, 0)
}

// panelNames are the names on the panel's own certificate (Let's Encrypt
// or uploaded), or none while it has none.
func (m *Manager) panelNames(full, key string) []string {
	if !m.exists(key) {
		return nil
	}
	data, err := os.ReadFile(m.h.Path(full))
	if err != nil {
		return nil
	}
	block, _ := pem.Decode(data)
	if block == nil {
		return nil
	}
	c, err := x509.ParseCertificate(block.Bytes)
	if err != nil {
		return nil
	}
	var names []string
	for _, n := range c.DNSNames {
		if n = strings.ToLower(n); validServerName(n) && !slices.Contains(names, n) {
			names = append(names, n)
		}
	}
	return names
}

// validServerName keeps a certificate's names to what server_name takes
// safely: host names, with a leading "*." for wildcards.
func validServerName(n string) bool {
	n = strings.TrimPrefix(n, "*.")
	if n == "" || len(n) > 253 {
		return false
	}
	for _, label := range strings.Split(n, ".") {
		if label == "" || len(label) > 63 || label[0] == '-' || label[len(label)-1] == '-' {
			return false
		}
		for _, r := range label {
			if !(r >= 'a' && r <= 'z' || r >= '0' && r <= '9' || r == '-') {
				return false
			}
		}
	}
	return true
}

// PanelCertificateChanged serves the panel's new certificate on the
// panel's name, while the IP address keeps servik-web's own; the
// certificate store reloads servik-web afterwards.
func (m *Manager) PanelCertificateChanged(ctx context.Context) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	if !m.exists(webConf) {
		return nil // not set up
	}
	st, err := m.loadState()
	if err != nil {
		return err
	}
	_, mysql := m.installedProduct(MySQL)
	_, pg := m.installedProduct(PostgreSQL)
	if st.Manager == nil {
		mysql, pg = false, false
	}
	_, withPanel := panel.Load(m.h)
	withPanel = withPanel && panel.Installed(m.h)
	t := txn.New(m.h)
	data, err := m.webConfData(t, m.webPorts(st), mysql, pg, withPanel)
	if err == nil {
		err = t.WriteFile(webConf, render(webConfTmpl, data))
	}
	if err == nil && t.Changed {
		if _, err = m.h.Run(ctx, host.Command{Name: "nginx", Args: []string{"-t", "-q", "-c", webConf}}); err != nil {
			err = fmt.Errorf("nginx rejected the configuration: %w", err)
		}
	}
	if err != nil {
		return errors.Join(err, t.Rollback(context.WithoutCancel(ctx)))
	}
	return nil
}

// blowfishSecret keeps phpMyAdmin's cookie encryption key across setups.
func (m *Manager) blowfishSecret() (string, error) {
	if data, err := os.ReadFile(m.h.Path(pmaConf)); err == nil {
		for _, line := range strings.Split(string(data), "\n") {
			if rest, ok := strings.CutPrefix(line, "$cfg['blowfish_secret'] = sodium_hex2bin('"); ok {
				if s, _, ok := strings.Cut(rest, "'"); ok && len(s) == 64 {
					return s, nil
				}
			}
		}
	}
	return randomHex(32)
}

func randomHex(n int) (string, error) {
	b := make([]byte, n)
	if _, err := rand.Read(b); err != nil {
		return "", err
	}
	return hex.EncodeToString(b), nil
}

// ensureAccount creates a system account (no login, no home created) or
// returns the existing one.
func ensureAccount(ctx context.Context, h host.Host, name, home, comment string) (host.User, error) {
	u, err := h.LookupUser(name)
	if err == nil {
		return u, nil
	}
	if !errors.Is(err, host.ErrUserNotFound) {
		return host.User{}, err
	}
	if _, err := h.Run(ctx, host.Command{Name: "useradd", Args: []string{
		"--system", "--user-group", "--no-create-home", "--home-dir", home, "--shell", "/usr/sbin/nologin", "--comment", comment, name,
	}}); err != nil {
		return host.User{}, err
	}
	return h.LookupUser(name)
}

// LoginSpec asks for a one-click sign-in to a database.
type LoginSpec struct {
	Server   string `json:"server"`
	Database string `json:"database"`
}

// Login is a sign-in link: Path on servik-web's Port, usable once before
// Expires.
type Login struct {
	Path    string    `json:"path"`
	Port    int       `json:"port"`
	Tool    string    `json:"tool"`
	Expires time.Time `json:"expires"`
}

// Login creates a temporary user with full access to one database, valid
// for SessionTTL, and a single-use link that signs the browser into the
// manager with it. The customer's own users and passwords are never
// involved.
func (m *Manager) Login(ctx context.Context, spec LoginSpec) (Login, error) {
	if err := (DatabaseSpec{Server: spec.Server, Name: spec.Database}).validate(); err != nil {
		return Login{}, err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	e, err := m.engineFor(spec.Server)
	if err != nil {
		return Login{}, err
	}
	st, err := m.loadState()
	if err != nil {
		return Login{}, opError(err)
	}
	if st.Manager == nil {
		return Login{}, invalid("the database manager is not set up")
	}
	tool, page, ready := "phpmyadmin", "/phpmyadmin/servik-sso.php?token=", m.exists(pmaIndex)
	if spec.Server == PostgreSQL {
		tool, page, ready = "adminer", "/adminer/?servik_token=", m.exists(adminerIndex)
	}
	if !ready {
		return Login{}, invalid("%s is not installed; set the database manager up again", tool)
	}
	if _, ok := st.Databases[key(spec.Server, spec.Database)]; !ok {
		return Login{}, invalid("database %s is not managed by Servik", spec.Database)
	}
	m.sweepLocked(ctx, st)

	suffix, err := randomHex(6)
	if err != nil {
		return Login{}, opError(err)
	}
	password, err := randomHex(24)
	if err != nil {
		return Login{}, opError(err)
	}
	user := sessionPrefix + suffix
	expires := m.now().Add(SessionTTL)
	if err := e.createSession(ctx, user, password, spec.Database, expires); err != nil {
		return Login{}, opError(err)
	}
	st.Sessions = append(st.Sessions, session{Server: spec.Server, User: user, Database: spec.Database, Expires: expires})
	if err := m.saveState(st); err != nil {
		_ = e.dropSession(ctx, user, spec.Database)
		return Login{}, opError(err)
	}

	token, err := randomHex(32)
	if err != nil {
		return Login{}, opError(err)
	}
	linkExpires := m.now().Add(tokenTTL)
	body, _ := json.Marshal(map[string]any{
		"server": spec.Server, "user": user, "password": password, "database": spec.Database, "expires": linkExpires.Unix(),
	})
	acct, err := m.h.LookupUser(AdminUser)
	if err != nil {
		return Login{}, opError(err)
	}
	p := m.h.Path(adminTokens + "/" + token + ".json")
	if err := os.WriteFile(p, body, 0o600); err != nil {
		return Login{}, opError(err)
	}
	if err := m.h.Chown(p, acct.UID, acct.GID); err != nil {
		_ = os.Remove(p)
		return Login{}, opError(err)
	}
	return Login{Path: page + token, Port: m.webPort(st), Tool: tool, Expires: linkExpires.UTC()}, nil
}

// Run cleans up while the agent runs: expired sign-in users, unused
// links and old backups.
func (m *Manager) Run(ctx context.Context) {
	tick := time.NewTicker(5 * time.Minute)
	defer tick.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-tick.C:
			m.Sweep(ctx)
		}
	}
}

// Sweep does one round of Run's clean-up.
func (m *Manager) Sweep(ctx context.Context) {
	m.mu.Lock()
	defer m.mu.Unlock()
	st, err := m.loadState()
	if err != nil {
		return
	}
	m.sweepLocked(ctx, st)
	_ = m.saveState(st)
}

func (m *Manager) sweepLocked(ctx context.Context, st *state) {
	now := m.now()
	st.Sessions = slices.DeleteFunc(st.Sessions, func(s session) bool {
		if s.Expires.After(now) {
			return false
		}
		e, ok := m.engines[s.Server]
		if !ok {
			return true
		}
		return e.dropSession(ctx, s.User, s.Database) == nil
	})
	for _, dir := range []struct {
		path string
		age  time.Duration
	}{{adminTokens, 2 * tokenTTL}, {BackupDir, BackupRetention}} {
		entries, _ := os.ReadDir(m.h.Path(dir.path))
		for _, e := range entries {
			if fi, err := e.Info(); err == nil && fi.Mode().IsRegular() && now.Sub(fi.ModTime()) > dir.age {
				_ = os.Remove(filepath.Join(m.h.Path(dir.path), e.Name()))
			}
		}
	}
}
