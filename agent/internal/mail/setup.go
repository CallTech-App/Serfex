package mail

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"slices"
	"strings"

	"github.com/calltech-app/serfex/agent/internal/cert"
	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/tuning"
	"github.com/calltech-app/serfex/agent/internal/txn"
)

// Packages are the Ubuntu packages of the mail server.
var Packages = []string{
	"postfix", "dovecot-core", "dovecot-imapd", "dovecot-pop3d", "dovecot-lmtpd",
	"dovecot-sieve", "dovecot-managesieved", "rspamd", "redis-server",
}

// packageFiles mark the packages as installed.
var packageFiles = []string{
	"/usr/sbin/postfix", "/usr/sbin/dovecot", "/usr/lib/dovecot/imap", "/usr/lib/dovecot/pop3",
	"/usr/lib/dovecot/lmtp", "/usr/bin/sievec", "/usr/lib/dovecot/managesieve", "/usr/bin/rspamd",
	"/usr/bin/redis-server",
}

// debconf answers that keep the packages from configuring themselves:
// Serfex writes Postfix's configuration and Roundcube's database.
const preseed = `postfix postfix/main_mailer_type select No configuration
roundcube-core roundcube/dbconfig-install boolean false
`

// SetupSpec configures the mail server.
type SetupSpec struct {
	// Hostname is the server's mail name: the SMTP greeting, the address
	// mail clients connect to and the webmail address.
	Hostname string `json:"hostname"`
	// Certificate is the name of a certificate in the store (ssl.issue)
	// that covers Hostname; empty uses a self-signed one.
	Certificate string `json:"certificate"`
	// HourlyLimit is how many messages a mailbox may send per hour; 0 is
	// no limit.
	HourlyLimit int `json:"hourly_limit"`
	// Webmail installs and serves webmail at https://<hostname>/.
	Webmail bool `json:"webmail"`
	// WebmailApp is the webmail served: Roundcube (also when empty) or
	// SnappyMail. Each keeps its settings and contacts when the other
	// one is chosen.
	WebmailApp string `json:"webmail_app,omitempty"`
}

// server is what the agent stores about the applied setup.
type server struct {
	SetupSpec
	// MasterPassword is the webmail master user's password; DESKey
	// encrypts Roundcube sessions.
	MasterPassword string `json:"master_password"`
	DESKey         string `json:"des_key"`
	PHPVersion     string `json:"php_version,omitempty"`
	// SnappyMailVersion is the SnappyMail release in use.
	SnappyMailVersion string `json:"snappymail_version,omitempty"`
}

func (s *SetupSpec) validate() error {
	if err := validateDomain(s.Hostname); err != nil {
		return err
	}
	if s.Certificate != "" {
		if err := cert.ValidateName(s.Certificate); err != nil {
			return invalid("%s", err.Error())
		}
	}
	if s.HourlyLimit < 0 || s.HourlyLimit > 100000 {
		return invalid("hourly_limit must be between 0 and 100000")
	}
	if s.WebmailApp != "" && s.WebmailApp != Roundcube && s.WebmailApp != SnappyMail {
		return invalid("webmail_app must be %s or %s", Roundcube, SnappyMail)
	}
	return nil
}

// loadServer returns the applied setup, or ErrNotSetUp.
func (m *Manager) loadServer() (*server, error) {
	data, err := os.ReadFile(m.h.Path(serverState))
	if errors.Is(err, os.ErrNotExist) {
		return nil, ErrNotSetUp
	}
	if err != nil {
		return nil, err
	}
	var s server
	if err := json.Unmarshal(data, &s); err != nil {
		return nil, err
	}
	return &s, nil
}

// tlsFiles returns the certificate and key the services use.
func (s *server) tlsFiles() (string, string) {
	if s.Certificate == "" {
		return selfCert, selfKey
	}
	return cert.Files(s.Certificate)
}

// Status describes the mail server.
type Status struct {
	Installed  bool            `json:"installed"`
	Configured bool            `json:"configured"`
	Running    bool            `json:"running"`
	Services   map[string]bool `json:"services"`
	Hostname   string          `json:"hostname,omitempty"`
	Webmail    string          `json:"webmail_url,omitempty"`
	// WebmailApp is the webmail served, with its version for SnappyMail;
	// WebmailLatest is set when this agent brings a newer SnappyMail
	// (mail.setup installs it).
	WebmailApp     string `json:"webmail_app,omitempty"`
	WebmailVersion string `json:"webmail_version,omitempty"`
	WebmailLatest  string `json:"webmail_latest,omitempty"`
	Error          string `json:"error,omitempty"`
}

// Status reports whether the mail server is installed, set up and running.
func (m *Manager) Status(ctx context.Context) Status {
	st := Status{Installed: m.installed(packageFiles), Services: map[string]bool{}}
	s, err := m.loadServer()
	if errors.Is(err, ErrNotSetUp) {
		return st
	}
	if err != nil {
		st.Error = err.Error()
		return st
	}
	st.Configured, st.Hostname = true, s.Hostname
	if s.Webmail {
		st.Webmail = "https://" + s.Hostname + "/"
		st.WebmailApp = s.webmailApp()
		if st.WebmailApp == SnappyMail {
			st.WebmailVersion = s.SnappyMailVersion
			if s.SnappyMailVersion != SnappyMailRelease.Version {
				st.WebmailLatest = SnappyMailRelease.Version
			}
		}
	}
	st.Running = true
	for _, u := range Units {
		_, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"is-active", "--quiet", u}})
		st.Services[u] = err == nil
		st.Running = st.Running && err == nil
	}
	return st
}

func (m *Manager) installed(files []string) bool {
	for _, f := range files {
		if !m.exists(f) {
			return false
		}
	}
	return true
}

// SetupResult describes what Setup did.
type SetupResult struct {
	Installed bool   `json:"installed"`
	Changed   bool   `json:"changed"`
	Hostname  string `json:"hostname"`
	Webmail   string `json:"webmail_url,omitempty"`
	// WebmailApp and WebmailVersion (SnappyMail's) describe the webmail.
	WebmailApp     string `json:"webmail_app,omitempty"`
	WebmailVersion string `json:"webmail_version,omitempty"`
}

// templateData fills the configuration templates for the server s.
func (m *Manager) templateData(s *server, vmail host.User) tmplData {
	certFile, keyFile := s.tlsFiles()
	return tmplData{
		Hostname: s.Hostname, CertFile: certFile, KeyFile: keyFile, IPv6: m.hasIPv6(),
		VmailUID: vmail.UID, HourlyLimit: s.HourlyLimit, MilterAddr: milterAddr,
		RedisSocket: redisSocket, Maps: postfixMaps, DovecotDir: dovecotDir, VmailDir: VmailDir,
		MasterUser: MasterUser,
		// Hizmetler → Postfix changes it.
		MessageSize: int64(tuning.Load(m.h).Postfix.MessageSizeMB) << 20,
	}
}

// RefreshPostfix rewrites Postfix's configuration and maps from the
// applied setup, so a Serfex update brings servers set up before it to
// the new settings. It reports whether anything changed; before
// mail.setup it does nothing.
func (m *Manager) RefreshPostfix(ctx context.Context) (changed bool, err error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	s, err := m.loadServer()
	if errors.Is(err, ErrNotSetUp) {
		return false, nil
	}
	if err != nil {
		return false, err
	}
	vmail, err := m.h.LookupUser(VmailUser)
	if err != nil {
		return false, fmt.Errorf("%s account: %w", VmailUser, err)
	}
	t := txn.New(m.h)
	defer m.finish(ctx, t, &err)

	data := m.templateData(s, vmail)
	for path, tmpl := range map[string][]byte{postfixMain: render(postfixMainTmpl, data), postfixMaster: render(postfixMasterTmpl, data)} {
		if err := t.WriteFile(path, tmpl); err != nil {
			return false, err
		}
	}
	// rebuild checks and reloads Postfix only when its maps changed.
	conf := t.Changed
	if err := m.rebuild(ctx, t); err != nil {
		return false, err
	}
	if conf {
		if _, err := m.h.Run(ctx, host.Command{Name: "postfix", Args: []string{"check"}}); err != nil {
			return false, fmt.Errorf("postfix rejected the configuration: %w", err)
		}
		if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"reload", "postfix"}}); err != nil {
			return false, err
		}
	}
	return t.Changed, nil
}

// Setup installs the mail server's packages when missing, writes the
// configuration and (re)starts the services. Running it again repairs a
// stopped or changed server. Configuration changes are rolled back if a
// service rejects them; installed packages stay.
func (m *Manager) Setup(ctx context.Context, spec SetupSpec) (res SetupResult, err error) {
	if err := spec.validate(); err != nil {
		return SetupResult{}, err
	}
	m.mu.Lock()
	defer m.mu.Unlock()

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
			// Bring the services back with the previous configuration.
			_, _ = m.h.Run(bg, host.Command{Name: "systemctl", Args: []string{"daemon-reload"}})
			for _, u := range Units {
				_, _ = m.h.Run(bg, host.Command{Name: "systemctl", Args: []string{"restart", u}})
			}
			_, _ = m.h.Run(bg, host.Command{Name: "systemctl", Args: []string{"reload", "nginx"}})
		}
		err = opError(err)
	}()

	if spec.WebmailApp == "" {
		spec.WebmailApp = Roundcube
	}
	roundcube := spec.Webmail && spec.WebmailApp == Roundcube
	if !m.installed(packageFiles) {
		packages := slices.Clone(Packages)
		if roundcube {
			packages = append(packages, WebmailPackages...)
		}
		if err := m.install(ctx, "the mail server", packages); err != nil {
			return SetupResult{}, err
		}
		res.Installed = true
		// The packages start stock instances Serfex does not use.
		_, _ = m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"disable", "--now", "redis-server.service"}})
	}
	// Roundcube chosen later is installed on its own.
	if roundcube && !m.installed(webmailFiles) {
		if err := m.install(ctx, "Roundcube", WebmailPackages); err != nil {
			return SetupResult{}, err
		}
	}

	// Like the packages, PHP parts installed for webmail stay on a
	// failure.
	php, snappy := "", ""
	if spec.Webmail {
		if php, err = m.webmailPHP(ctx); err != nil {
			return SetupResult{}, err
		}
		if spec.WebmailApp == SnappyMail {
			if snappy, err = m.ensureSnappyMail(ctx); err != nil {
				return SetupResult{}, err
			}
		}
	}

	prev, err := m.loadServer()
	if err != nil && !errors.Is(err, ErrNotSetUp) {
		return SetupResult{}, err
	}
	s := &server{SetupSpec: spec, PHPVersion: php, SnappyMailVersion: snappy}
	if prev != nil {
		s.MasterPassword, s.DESKey = prev.MasterPassword, prev.DESKey
	}
	if s.MasterPassword == "" {
		if s.MasterPassword, err = randomHex(24); err != nil {
			return SetupResult{}, err
		}
	}
	if s.DESKey == "" {
		if s.DESKey, err = randomHex(12); err != nil {
			return SetupResult{}, err
		}
	}

	vmail, err := m.ensureAccount(ctx, VmailUser, VmailDir, "Serfex mail")
	if err != nil {
		return SetupResult{}, err
	}
	for _, d := range []struct {
		path      string
		mode      os.FileMode
		uid, gid  int
		groupUser string
	}{
		{path: "/etc/serfex", mode: 0o755},
		{path: stateDir, mode: 0o755},
		{path: domainsDir, mode: 0o700},
		{path: dkimDir, mode: 0o750, groupUser: "_rspamd"},
		{path: tlsDir, mode: 0o750},
		{path: postfixMaps, mode: 0o755},
		{path: dovecotDir, mode: 0o750, groupUser: "dovecot"},
		{path: sieveDir, mode: 0o755},
		{path: rspamdLocal, mode: 0o755},
		{path: "/etc/systemd/system/rspamd.service.d", mode: 0o755},
		{path: VmailDir, mode: 0o750, uid: vmail.UID, gid: vmail.GID},
	} {
		if d.groupUser != "" {
			u, err := m.h.LookupUser(d.groupUser)
			if err != nil {
				return SetupResult{}, fmt.Errorf("%s account: %w", d.groupUser, err)
			}
			d.gid = u.GID
		}
		if err := t.RootDir(d.path, d.mode, d.uid, d.gid); err != nil {
			return SetupResult{}, err
		}
	}

	if spec.Certificate == "" {
		if err := m.writeSelfSigned(t, spec.Hostname); err != nil {
			return SetupResult{}, err
		}
	} else if certFile, _ := s.tlsFiles(); !m.exists(certFile) {
		return SetupResult{}, invalid("certificate %s is not installed", spec.Certificate)
	}

	dovecot, err := m.h.LookupUser("dovecot")
	if err != nil {
		return SetupResult{}, fmt.Errorf("dovecot account: %w", err)
	}
	data := m.templateData(s, vmail)

	// Each service restarts only when its own files changed.
	restart := map[string]bool{}
	track := func(unit string, write func() error) error {
		c, err := changes(t, write)
		restart[unit] = restart[unit] || c
		return err
	}

	if err := track("postfix", func() error {
		for path, tmpl := range map[string][]byte{postfixMain: render(postfixMainTmpl, data), postfixMaster: render(postfixMasterTmpl, data)} {
			if err := t.TakeOver(path); err != nil {
				return err
			}
			if err := t.WriteFile(path, tmpl); err != nil {
				return err
			}
		}
		return nil
	}); err != nil {
		return SetupResult{}, err
	}
	if err := track("dovecot", func() error {
		if err := t.TakeOver(dovecotConf); err != nil {
			return err
		}
		if err := t.WriteFile(dovecotConf, render(dovecotTmpl, data)); err != nil {
			return err
		}
		hash, err := m.masterHash(s.MasterPassword)
		if err != nil {
			return err
		}
		line := MasterUser + ":" + hash + "::::::allow_nets=127.0.0.1,::1\n"
		if err := t.WriteFileMode(dovecotMasters, []byte(mapHeader("Webmail's master user (one-click login from the panel).")+line), 0o640, 0, dovecot.GID); err != nil {
			return err
		}
		return t.WriteFile(spamSieve, spamSieveTmpl)
	}); err != nil {
		return SetupResult{}, err
	}
	daemonReload := false
	if err := track("rspamd", func() error {
		if err := t.WriteFile(rspamdDropIn, rspamdUnitDropIn); err != nil {
			return err
		}
		daemonReload = t.Changed
		for name, tmpl := range rspamdFiles {
			if err := t.WriteFile(rspamdLocal+"/"+name, render(tmpl, data)); err != nil {
				return err
			}
		}
		return nil
	}); err != nil {
		return SetupResult{}, err
	}
	if err := track(redisUnit, func() error { return t.WriteFile(redisConf, render(redisTmpl, data)) }); err != nil {
		return SetupResult{}, err
	}

	// The maps must exist before Postfix and Dovecot start.
	domains, err := m.loadDomains()
	if err != nil {
		return SetupResult{}, err
	}
	built, err := m.allMaps(domains)
	if err != nil {
		return SetupResult{}, err
	}
	mapChanges, err := m.writeMaps(t, built, dovecot.GID)
	if err != nil {
		return SetupResult{}, err
	}
	for u, c := range mapChanges {
		restart[u] = restart[u] || c
	}

	webChanged, err := m.writeWebmail(ctx, t, s, data)
	if err != nil {
		return SetupResult{}, err
	}
	// Roundcube's job drop-ins are part of webmail's files.
	daemonReload = daemonReload || webChanged

	state, err := json.MarshalIndent(s, "", "  ")
	if err != nil {
		return SetupResult{}, err
	}
	if err := t.WriteOwned(serverState, state, 0o600, 0, 0); err != nil {
		return SetupResult{}, err
	}

	res.Changed = t.Changed || res.Installed
	res.Hostname = spec.Hostname
	if spec.Webmail {
		res.Webmail = "https://" + spec.Hostname + "/"
		res.WebmailApp, res.WebmailVersion = spec.WebmailApp, snappy
	}

	// Check every configuration before touching a running service.
	for _, c := range []host.Command{
		{Name: "postfix", Args: []string{"check"}},
		{Name: "doveconf", Args: []string{"-n"}},
		{Name: "rspamadm", Args: []string{"configtest"}},
	} {
		if _, err := m.h.Run(ctx, c); err != nil {
			return SetupResult{}, fmt.Errorf("%s rejected the configuration: %w", c.Name, err)
		}
	}
	if err := m.compileSieve(ctx, spamSieve); err != nil {
		return SetupResult{}, err
	}
	if webChanged {
		if err := m.checkWeb(ctx, s.PHPVersion); err != nil {
			return SetupResult{}, err
		}
	}

	started = true
	if daemonReload {
		if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"daemon-reload"}}); err != nil {
			return SetupResult{}, err
		}
	}
	if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: append([]string{"enable"}, Units...)}); err != nil {
		return SetupResult{}, err
	}
	for _, u := range Units {
		verb := "start"
		if restart[u] || res.Installed {
			verb = "restart"
		}
		if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{verb, u}}); err != nil {
			return SetupResult{}, fmt.Errorf("%s did not start (see journalctl -u %s): %w", u, u, err)
		}
		if u == "rspamd" {
			if err := m.waitRspamd(ctx); err != nil {
				return SetupResult{}, err
			}
		}
	}
	if webChanged {
		if err := m.reloadWeb(ctx, s.PHPVersion); err != nil {
			return SetupResult{}, err
		}
	}
	// The release before stays for a rollback; older ones go.
	if snappy != "" {
		before := ""
		if prev != nil {
			before = prev.SnappyMailVersion
		}
		m.pruneSnappyMail(snappy, before)
	}
	return res, nil
}

// install installs packages (what names them in errors), refreshing the
// package lists once if the first attempt fails.
func (m *Manager) install(ctx context.Context, what string, packages []string) error {
	if _, err := m.h.Run(ctx, host.Command{Name: "debconf-set-selections", Stdin: []byte(preseed)}); err != nil {
		return fmt.Errorf("preparing the mail packages: %w", err)
	}
	env := []string{"DEBIAN_FRONTEND=noninteractive"}
	inst := host.Command{Name: "apt-get", Args: append([]string{"install", "-y", "-q", "--no-install-recommends"}, packages...), Env: env}
	if _, err := m.h.Run(ctx, inst); err == nil {
		return nil
	}
	if _, err := m.h.Run(ctx, host.Command{Name: "apt-get", Args: []string{"update", "-q"}, Env: env}); err != nil {
		return fmt.Errorf("installing %s: %w", what, err)
	}
	if _, err := m.h.Run(ctx, inst); err != nil {
		return fmt.Errorf("installing %s: %w", what, err)
	}
	return nil
}

// ensureAccount creates a system account (no login, no home created) or
// returns the existing one.
func (m *Manager) ensureAccount(ctx context.Context, name, home, comment string) (host.User, error) {
	u, err := m.h.LookupUser(name)
	if err == nil {
		return u, nil
	}
	if !errors.Is(err, host.ErrUserNotFound) {
		return host.User{}, err
	}
	if _, err := m.h.Run(ctx, host.Command{Name: "useradd", Args: []string{
		"--system", "--user-group", "--no-create-home", "--home-dir", home, "--shell", "/usr/sbin/nologin", "--comment", comment, name,
	}}); err != nil {
		return host.User{}, err
	}
	return m.h.LookupUser(name)
}

// writeSelfSigned keeps a self-signed certificate for hostname.
func (m *Manager) writeSelfSigned(t *txn.Tx, hostname string) error {
	if data, err := os.ReadFile(m.h.Path(selfCert)); err == nil {
		if names, err := certNames(data); err == nil && slices.Equal(names, []string{hostname}) && m.exists(selfKey) {
			return nil
		}
	}
	certPEM, keyPEM, err := selfSigned(hostname)
	if err != nil {
		return err
	}
	if err := t.WriteOwned(selfKey, keyPEM, 0o600, 0, 0); err != nil {
		return err
	}
	return t.WriteOwned(selfCert, certPEM, 0o644, 0, 0)
}

// masterHash keeps the stored hash when it still matches the password,
// so a repeated setup changes nothing.
func (m *Manager) masterHash(password string) (string, error) {
	if data, err := os.ReadFile(m.h.Path(dovecotMasters)); err == nil {
		for _, line := range strings.Split(string(data), "\n") {
			if rest, ok := strings.CutPrefix(line, MasterUser+":"); ok {
				hash, _, _ := strings.Cut(rest, ":")
				if checkSSHA512(hash, password) {
					return hash, nil
				}
			}
		}
	}
	return ssha512(password)
}

// compileSieve precompiles a script so deliveries do not have to.
func (m *Manager) compileSieve(ctx context.Context, path string) error {
	if _, err := m.h.Run(ctx, host.Command{Name: "sievec", Args: []string{path}}); err != nil {
		return fmt.Errorf("the Sieve script %s does not compile: %w", path, err)
	}
	return nil
}
