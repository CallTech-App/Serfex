package mail

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/calltech-app/serfex/agent/internal/cert"
	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/site"
	"github.com/calltech-app/serfex/agent/internal/txn"
)

// WebmailPackages are installed when webmail is on. php-cgi keeps apt
// from pulling in Apache (or another PHP-FPM service) for the php
// package Roundcube depends on; webmail runs on its own PHP-FPM pool of
// webmailPHP's version.
var WebmailPackages = []string{"roundcube-core", "roundcube-sqlite3", "roundcube-plugins", "sqlite3", "php-cgi"}

// webmailMaxPHP is the newest PHP Ubuntu's Roundcube (1.6) runs on: PHP
// 8.5 made array_first() a built-in function, which Roundcube 1.6
// declares itself ("Cannot redeclare function array_first()").
const webmailMaxPHP = "8.4"

// webmailPHPParts are what Roundcube needs of a PHP version: the package
// suffix and the file that marks it installed (%s is the version).
var webmailPHPParts = []struct{ pkg, file string }{
	{"fpm", "/usr/sbin/php-fpm%s"},
	{"cli", "/usr/bin/php%s"},
	{"sqlite3", "/etc/php/%s/mods-available/pdo_sqlite.ini"},
	{"intl", "/etc/php/%s/mods-available/intl.ini"},
	{"mbstring", "/etc/php/%s/mods-available/mbstring.ini"},
	{"xml", "/etc/php/%s/mods-available/xml.ini"},
}

// roundcubeJobs are the package's systemd housekeeping jobs; Servik's
// drop-ins run them on webmail's database, account and PHP version.
var roundcubeJobs = map[string]string{"roundcube-gc": "gc.sh", "roundcube-cleandb": "cleandb.sh"}

var webmailFiles = []string{"/usr/share/roundcube/index.php", "/usr/share/roundcube/plugins/managesieve/managesieve.php", "/usr/bin/sqlite3"}

const (
	// WebmailUser runs Roundcube's PHP; it is neither a customer nor the
	// panel.
	WebmailUser = "servik-webmail"

	webmailDir     = "/var/lib/servik/webmail"
	webmailData    = webmailDir + "/data"
	webmailPlugin  = webmailDir + "/plugins/servik_sso"
	webmailConfig  = "/etc/servik/webmail"
	webmailLogs    = "/var/log/servik/webmail"
	webmailSocket  = "/run/php/servik-webmail.sock"
	webmailSSO     = webmailData + "/sso"
	roundcubeRoot  = "/var/lib/roundcube"
	roundcubeSQL   = "/usr/share/roundcube/SQL"
	autoconfigDir  = "/var/lib/servik/mail/autoconfig"
	mailVhost      = "/etc/nginx/sites-available/servik-mail.conf"
	mailVhostLink  = "/etc/nginx/sites-enabled/servik-mail.conf"
	ssoTokenExpiry = time.Minute
)

func webmailPool(version string) string {
	return "/etc/php/" + version + "/fpm/pool.d/servik-webmail.conf"
}

func roundcubeJobDropIn(unit string) string {
	return "/etc/systemd/system/" + unit + ".service.d/servik.conf"
}

// writeWebmail writes the mail vhost (always, for certificate validation
// and mail client settings) and, with webmail on, Roundcube's account,
// configuration, database and PHP-FPM pool on s.PHPVersion. It reports
// whether Nginx or PHP-FPM need a reload.
func (m *Manager) writeWebmail(ctx context.Context, t *txn.Tx, s *server, data tmplData) (bool, error) {
	return changes(t, func() error {
		data.Webmail = s.Webmail
		data.WebUser = m.webUser
		data.ACMERoot = cert.Webroot
		data.TLSConf = cert.TLSConf
		data.AutoconfigDir = autoconfigDir
		data.LogDir, data.ConfigDir, data.DataDir = webmailLogs, webmailConfig, webmailData
		data.PHPSocket = webmailSocket
		data.MasterPass, data.DESKey = s.MasterPassword, s.DESKey

		for _, d := range []string{"/var/lib/servik", "/var/lib/servik/mail", autoconfigDir, cert.Webroot} {
			if err := t.RootDir(d, 0o755, 0, 0); err != nil {
				return err
			}
		}
		if err := t.WriteFile(autoconfigDir+"/config-v1.1.xml", render(autoconfigTmpl, data)); err != nil {
			return err
		}

		// PHP-FPM pools of every installed version but the one in use
		// are removed, also when webmail is turned off.
		versions, err := m.phpVersions()
		if err != nil {
			return err
		}
		if s.Webmail {
			if err := m.writeRoundcube(ctx, t, s, data); err != nil {
				return err
			}
		}
		for _, v := range versions {
			if v != s.PHPVersion {
				if err := t.RemoveFile(webmailPool(v)); err != nil {
					return err
				}
			}
		}
		if !s.Webmail {
			if err := t.Unlink(roundcubeRoot + "/plugins/servik_sso"); err != nil {
				return err
			}
			for unit := range roundcubeJobs {
				if err := t.RemoveFile(roundcubeJobDropIn(unit)); err != nil {
					return err
				}
			}
		}

		if s.Webmail {
			if err := t.RootDir("/etc/servik/nginx", 0o755, 0, 0); err != nil {
				return err
			}
			if err := t.WriteFile(cert.TLSConf, site.TLSConfig()); err != nil {
				return err
			}
		}
		if err := t.WriteFile(mailVhost, render(mailVhostTmpl, data)); err != nil {
			return err
		}
		return t.Symlink(mailVhost, mailVhostLink)
	})
}

// writeRoundcube sets up Roundcube to read Servik's configuration, with
// its own SQLite database and PHP-FPM pool.
func (m *Manager) writeRoundcube(ctx context.Context, t *txn.Tx, s *server, data tmplData) error {
	acct, err := m.ensureAccount(ctx, WebmailUser, webmailDir, "Servik webmail")
	if err != nil {
		return err
	}
	for _, d := range []struct {
		path     string
		mode     os.FileMode
		uid, gid int
	}{
		{webmailDir, 0o755, 0, 0},
		{webmailDir + "/plugins", 0o755, 0, 0},
		{webmailPlugin, 0o755, 0, 0},
		{webmailData, 0o700, acct.UID, acct.GID},
		{webmailData + "/temp", 0o700, acct.UID, acct.GID},
		{webmailSSO, 0o700, acct.UID, acct.GID},
		{webmailConfig, 0o750, 0, acct.GID},
		{"/var/log/servik", 0o755, 0, 0},
		{webmailLogs, 0o750, acct.UID, acct.GID},
	} {
		if err := t.RootDir(d.path, d.mode, d.uid, d.gid); err != nil {
			return err
		}
	}
	if err := t.WriteFileMode(webmailConfig+"/config.inc.php", render(roundcubeConfigTmpl, data), 0o640, 0, acct.GID); err != nil {
		return err
	}
	// Roundcube's defaults stay the package's own.
	for _, f := range []string{"defaults.inc.php", "mimetypes.php"} {
		if err := t.ReplaceSymlink("/etc/roundcube/"+f, webmailConfig+"/"+f); err != nil {
			return err
		}
	}
	if err := t.WriteFile(webmailPlugin+"/servik_sso.php", ssoPlugin); err != nil {
		return err
	}
	if err := t.Symlink(webmailPlugin, roundcubeRoot+"/plugins/servik_sso"); err != nil {
		return err
	}
	data.PHPSocket = webmailSocket
	if err := t.WriteFile(webmailPool(s.PHPVersion), render(webmailPoolTmpl, data)); err != nil {
		return err
	}
	// Roundcube's scripts start with "#!/usr/bin/env php", which is the
	// newest PHP on the server; they are run with webmail's PHP instead.
	php := "/usr/bin/php" + s.PHPVersion
	for unit, script := range roundcubeJobs {
		if err := t.RootDir("/etc/systemd/system/"+unit+".service.d", 0o755, 0, 0); err != nil {
			return err
		}
		if err := t.WriteFile(roundcubeJobDropIn(unit), roundcubeJobUnit(php, script)); err != nil {
			return err
		}
	}

	// The database is created once and upgraded on every setup, after a
	// Roundcube package update too.
	db := webmailData + "/roundcube.db"
	if !m.exists(db) {
		schema, err := os.ReadFile(m.h.Path(roundcubeSQL + "/sqlite.initial.sql"))
		if err != nil {
			return fmt.Errorf("Roundcube's database schema: %w", err)
		}
		if _, err := m.h.Run(ctx, host.AsUser(WebmailUser, host.Command{Name: "sqlite3", Args: []string{db}, Stdin: schema})); err != nil {
			return fmt.Errorf("creating Roundcube's database: %w", err)
		}
		t.Changed = true
	}
	if _, err := m.h.Run(ctx, host.AsUser(WebmailUser, host.Command{Name: "env", Args: []string{
		"ROUNDCUBE_CONFIG_DIR=" + webmailConfig, php, "/usr/share/roundcube/bin/updatedb.sh", "--package=roundcube", "--dir=" + roundcubeSQL,
	}})); err != nil {
		return fmt.Errorf("upgrading Roundcube's database: %w", err)
	}
	return nil
}

// phpVersions lists the PHP versions with PHP-FPM installed.
func (m *Manager) phpVersions() ([]string, error) {
	entries, err := os.ReadDir(m.h.Path("/etc/php"))
	if errors.Is(err, os.ErrNotExist) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	var out []string
	for _, e := range entries {
		if site.ValidatePHPVersion(e.Name()) == nil && m.exists("/etc/php/"+e.Name()+"/fpm/pool.d") {
			out = append(out, e.Name())
		}
	}
	sort.Strings(out)
	return out, nil
}

// webmailPHP picks the PHP version webmail runs on: the newest one up to
// webmailMaxPHP that has everything Roundcube needs. Without one, the
// newest version up to webmailMaxPHP on the server (or webmailMaxPHP
// itself) gets the missing parts installed.
func (m *Manager) webmailPHP(ctx context.Context) (string, error) {
	entries, err := os.ReadDir(m.h.Path("/etc/php"))
	if err != nil && !errors.Is(err, os.ErrNotExist) {
		return "", err
	}
	var versions []string
	for _, e := range entries {
		if site.ValidatePHPVersion(e.Name()) == nil && !phpNewer(e.Name(), webmailMaxPHP) {
			versions = append(versions, e.Name())
		}
	}
	// Newest first.
	sort.Slice(versions, func(i, j int) bool { return phpNewer(versions[i], versions[j]) })
	for _, v := range versions {
		if len(m.webmailPHPMissing(v)) == 0 {
			return v, nil
		}
	}
	v := webmailMaxPHP
	if len(versions) > 0 {
		v = versions[0]
	}
	if err := m.install(ctx, "PHP "+v+" for webmail", m.webmailPHPMissing(v)); err != nil {
		return "", err
	}
	if missing := m.webmailPHPMissing(v); len(missing) > 0 {
		return "", fmt.Errorf("PHP %s for webmail is incomplete after installing it: %s missing", v, strings.Join(missing, " "))
	}
	return v, nil
}

// webmailPHPMissing lists the packages version v lacks for Roundcube.
func (m *Manager) webmailPHPMissing(v string) []string {
	var out []string
	for _, p := range webmailPHPParts {
		if !m.exists(fmt.Sprintf(p.file, v)) {
			out = append(out, "php"+v+"-"+p.pkg)
		}
	}
	return out
}

// phpNewer reports whether PHP version a (such as "8.10") is newer than b.
func phpNewer(a, b string) bool {
	n := func(s string) int { v, _ := strconv.Atoi(s); return v }
	amaj, amin, _ := strings.Cut(a, ".")
	bmaj, bmin, _ := strings.Cut(b, ".")
	if amaj != bmaj {
		return n(amaj) > n(bmaj)
	}
	return n(amin) > n(bmin)
}

// checkWeb tests the PHP-FPM and Nginx configuration.
func (m *Manager) checkWeb(ctx context.Context, php string) error {
	if php != "" {
		if _, err := m.h.Run(ctx, host.Command{Name: "php-fpm" + php, Args: []string{"-t"}}); err != nil {
			return fmt.Errorf("php-fpm rejected the webmail pool: %w", err)
		}
	}
	if _, err := m.h.Run(ctx, host.Command{Name: "nginx", Args: []string{"-t"}}); err != nil {
		return fmt.Errorf("nginx rejected the configuration: %w", err)
	}
	return nil
}

// reloadWeb reloads every PHP-FPM version (a removed pool must stop too)
// and Nginx.
func (m *Manager) reloadWeb(ctx context.Context, _ string) error {
	versions, err := m.phpVersions()
	if err != nil {
		return err
	}
	for _, v := range versions {
		if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"reload", "php" + v + "-fpm"}}); err != nil {
			return err
		}
	}
	_, err = m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"reload", "nginx"}})
	return err
}

// Login is a one-click webmail login.
type Login struct {
	URL     string    `json:"url"`
	Expires time.Time `json:"expires"`
}

// WebmailLogin creates a single-use token that signs into address's
// mailbox in webmail within a minute, without its password.
func (m *Manager) WebmailLogin(address string) (Login, error) {
	local, domain, _ := strings.Cut(address, "@")
	if err := validateDomain(domain); err != nil {
		return Login{}, err
	}
	if err := validateLocal(local); err != nil {
		return Login{}, err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	s, err := m.loadServer()
	if err != nil {
		return Login{}, err
	}
	if !s.Webmail {
		return Login{}, invalid("webmail is turned off")
	}
	d, err := m.loadDomain(domain)
	if err != nil {
		return Login{}, opError(err)
	}
	if d == nil || d.mailbox(local) == nil {
		return Login{}, invalid("%s is not a mailbox on this server", address)
	}
	acct, err := m.h.LookupUser(WebmailUser)
	if err != nil {
		return Login{}, opError(err)
	}
	m.expireTokens()

	token, err := randomHex(32)
	if err != nil {
		return Login{}, opError(err)
	}
	expires := time.Now().Add(ssoTokenExpiry)
	body, _ := json.Marshal(map[string]any{"user": address, "expires": expires.Unix()})
	p := m.h.Path(webmailSSO + "/" + token + ".json")
	if err := os.WriteFile(p, body, 0o600); err != nil {
		return Login{}, opError(err)
	}
	if err := m.h.Chown(p, acct.UID, acct.GID); err != nil {
		_ = os.Remove(p)
		return Login{}, opError(err)
	}
	return Login{URL: "https://" + s.Hostname + "/?_task=login&_action=servik-sso&_token=" + token, Expires: expires.UTC()}, nil
}

// expireTokens removes tokens nobody used.
func (m *Manager) expireTokens() {
	entries, _ := os.ReadDir(m.h.Path(webmailSSO))
	for _, e := range entries {
		if fi, err := e.Info(); err == nil && time.Since(fi.ModTime()) > 2*ssoTokenExpiry {
			_ = os.Remove(m.h.Path(webmailSSO + "/" + e.Name()))
		}
	}
}

// UsesCertificate reports whether the mail services use certificate name
// (cert.Consumer).
func (m *Manager) UsesCertificate(name string) bool {
	s, err := m.loadServer()
	return err == nil && s.Certificate == name
}

// ReloadCertificate makes Postfix and Dovecot read a renewed certificate.
// Nginx is reloaded by the certificate store itself.
func (m *Manager) ReloadCertificate(ctx context.Context) error {
	for _, u := range []string{"postfix", "dovecot"} {
		if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"reload", u}}); err != nil {
			return err
		}
	}
	return nil
}
