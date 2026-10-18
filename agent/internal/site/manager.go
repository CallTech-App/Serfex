// Package site provisions web sites: the system account that owns them,
// their directories, an Nginx virtual host and a PHP-FPM pool.
//
// Layout on the server (Ubuntu 24.04):
//
//	/home/<user>/                          customer home, <user>:www-data 0710
//	/home/<user>/tmp/                      PHP temp files and sessions
//	/home/<user>/web/<domain>/public_html/ document root
//	/home/<user>/web/<domain>/logs/        PHP error log
//	/var/log/servik/sites/<domain>/        Nginx logs, root:<user> 0750
//	/etc/nginx/sites-available/<domain>.conf (+ sites-enabled symlink)
//	/etc/php/<version>/fpm/pool.d/<domain>.conf
//	/run/php/servik-<domain>.sock          PHP-FPM socket
//
// Every operation is idempotent and transactional: running it twice gives
// the same result, and a failure part way restores the previous state.
package site

import (
	"context"
	"errors"
	"fmt"
	"net/netip"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"sync"

	"github.com/winchester37/servik/agent/internal/cert"
	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/txn"
)

// userComment marks accounts that Servik created. Accounts without it are
// never adopted or deleted.
const userComment = "Servik site user"

// OperationError reports a valid request that failed on the server.
type OperationError struct{ Err error }

func (e *OperationError) Error() string { return e.Err.Error() }
func (e *OperationError) Unwrap() error { return e.Err }

// Spec is the desired state of a site.
type Spec struct {
	Domain     string   `json:"domain"`
	User       string   `json:"user"`
	PHPVersion string   `json:"php_version"`
	Aliases    []string `json:"aliases"`
	// TLS serves the site over HTTPS too; nil means HTTP only.
	TLS *TLSSpec `json:"tls"`
	// WebRoot is the directory Nginx serves, relative to the site
	// directory: "public_html" (the default), or for example
	// "laravel/current/public" for a Laravel application.
	WebRoot string `json:"web_root"`
}

// DefaultWebRoot is the document root of a new site.
const DefaultWebRoot = "public_html"

// TLSSpec says which installed certificate a site uses (see package cert).
type TLSSpec struct {
	Certificate string `json:"certificate"`
	// Redirect sends plain HTTP requests to HTTPS with a 301, except for
	// Let's Encrypt validation requests.
	Redirect bool `json:"redirect"`
}

// Result describes a provisioned site.
type Result struct {
	Domain       string `json:"domain"`
	User         string `json:"user"`
	UID          int    `json:"uid"`
	Home         string `json:"home"`
	DocumentRoot string `json:"document_root"`
	PHPVersion   string `json:"php_version"`
	PHPSocket    string `json:"php_socket"`
	NginxConfig  string `json:"nginx_config"`
	FPMConfig    string `json:"fpm_config"`
	UserCreated  bool   `json:"user_created"`
	Changed      bool   `json:"changed"`
}

// DeleteSpec says what to remove with a site.
type DeleteSpec struct {
	Domain string `json:"domain"`
	User   string `json:"user"`
	// RemoveFiles deletes /home/<user>/web/<domain> and the Nginx logs.
	RemoveFiles bool `json:"remove_files"`
	// RemoveUser deletes the account and its home when it has no sites left.
	RemoveUser bool `json:"remove_user"`
}

// DeleteResult describes what was removed.
type DeleteResult struct {
	Domain       string `json:"domain"`
	FilesRemoved bool   `json:"files_removed"`
	UserRemoved  bool   `json:"user_removed"`
}

// Manager applies site changes on a Host. Operations are serialised.
type Manager struct {
	h       host.Host
	webUser string
	mu      sync.Mutex
}

// NewManager returns a Manager. webUser is the account Nginx runs as.
func NewManager(h host.Host, webUser string) *Manager {
	return &Manager{h: h, webUser: webUser}
}

type paths struct {
	home, siteDir, docroot, logDir, socket, nginxAvail, nginxEnabled, fpm string
}

func layout(domain, user, php, webRoot string) paths {
	home := "/home/" + user
	site := home + "/web/" + domain
	if webRoot == "" {
		webRoot = DefaultWebRoot
	}
	return paths{
		home:         home,
		siteDir:      site,
		docroot:      site + "/" + webRoot,
		logDir:       "/var/log/servik/sites/" + domain,
		socket:       "/run/php/servik-" + domain + ".sock",
		nginxAvail:   "/etc/nginx/sites-available/" + domain + ".conf",
		nginxEnabled: "/etc/nginx/sites-enabled/" + domain + ".conf",
		fpm:          fpmPool(php, domain),
	}
}

func fpmPool(php, domain string) string { return "/etc/php/" + php + "/fpm/pool.d/" + domain + ".conf" }

// PHPVersions lists the PHP versions that have PHP-FPM installed.
func (m *Manager) PHPVersions() ([]string, error) {
	entries, err := os.ReadDir(m.h.Path("/etc/php"))
	if errors.Is(err, os.ErrNotExist) {
		return []string{}, nil
	}
	if err != nil {
		return nil, err
	}
	versions := []string{}
	for _, e := range entries {
		if ValidatePHPVersion(e.Name()) != nil {
			continue
		}
		if fi, err := os.Stat(m.h.Path("/etc/php/" + e.Name() + "/fpm/pool.d")); err == nil && fi.IsDir() {
			versions = append(versions, e.Name())
		}
	}
	sort.Strings(versions)
	return versions, nil
}

// Apply creates or updates a site so that it matches spec.
func (m *Manager) Apply(ctx context.Context, spec Spec) (res Result, err error) {
	if err := spec.validate(); err != nil {
		return Result{}, err
	}
	m.mu.Lock()
	defer m.mu.Unlock()

	versions, err := m.PHPVersions()
	if err != nil {
		return Result{}, &OperationError{err}
	}
	if !contains(versions, spec.PHPVersion) {
		return Result{}, invalid("PHP %s is not installed (installed: %s)", spec.PHPVersion, strings.Join(versions, ", "))
	}
	web, err := m.h.LookupUser(m.webUser)
	if err != nil {
		return Result{}, &OperationError{fmt.Errorf("web server user %q: %w", m.webUser, err)}
	}

	p := layout(spec.Domain, spec.User, spec.PHPVersion, spec.WebRoot)
	t := txn.New(m.h)
	defer func() {
		if err != nil {
			if rbErr := t.Rollback(context.WithoutCancel(ctx)); rbErr != nil {
				err = fmt.Errorf("%w (rollback also failed: %v)", err, rbErr)
			}
			var v *ValidationError
			if !errors.As(err, &v) {
				err = &OperationError{err}
			}
		}
	}()

	u, created, err := m.ensureUser(ctx, t, spec.User, p.home)
	if err != nil {
		return Result{}, err
	}

	// The home directory sits in root-owned /home, so root may set it up.
	// Everything below it is created as the customer.
	if err := t.RootDir(p.home, 0o710, u.UID, web.GID); err != nil {
		return Result{}, err
	}
	if err := t.UserDirs(ctx, u.Name, "0700", p.home+"/tmp"); err != nil {
		return Result{}, err
	}
	if err := t.UserDirs(ctx, u.Name, "0755", p.home+"/web", p.siteDir); err != nil {
		return Result{}, err
	}
	if err := t.UserDirs(ctx, u.Name, "0750", p.siteDir+"/logs"); err != nil {
		return Result{}, err
	}
	// public_html always exists; another web root (a Laravel release) is
	// created by whatever deploys there.
	public := p.siteDir + "/" + DefaultWebRoot
	_, statErr := os.Lstat(m.h.Path(public))
	newDocroot := errors.Is(statErr, os.ErrNotExist)
	if err := t.UserDirs(ctx, u.Name, "0755", public); err != nil {
		return Result{}, err
	}
	data := tmplData{
		Marker:       txn.Marker,
		Domain:       spec.Domain,
		User:         u.Name,
		PHPVersion:   spec.PHPVersion,
		WebUser:      m.webUser,
		ServerNames:  strings.Join(append([]string{spec.Domain}, spec.Aliases...), " "),
		Home:         p.home,
		SiteDir:      p.siteDir,
		DocumentRoot: p.docroot,
		LogDir:       p.logDir,
		PHPSocket:    p.socket,
		IPv6:         m.hasIPv6(),
		ACMERoot:     cert.Webroot,
	}
	if err := m.tlsData(&data, spec.TLS); err != nil {
		return Result{}, err
	}
	if newDocroot {
		index := host.AsUser(u.Name, host.Command{Name: "tee", Args: []string{public + "/index.html"}, Stdin: render(placeholderTmpl, data)})
		if _, err := m.h.Run(ctx, index); err != nil {
			return Result{}, err
		}
	}
	if err := t.RootDir("/var/log/servik/sites", 0o755, 0, 0); err != nil {
		return Result{}, err
	}
	if err := t.RootDir(p.logDir, 0o750, 0, u.GID); err != nil {
		return Result{}, err
	}
	// Every vhost answers Let's Encrypt validation from the shared webroot.
	for _, d := range []string{"/var/lib/servik", cert.Webroot, cert.Webroot + "/.well-known", cert.ChallengeDir} {
		if err := t.RootDir(d, 0o755, 0, 0); err != nil {
			return Result{}, err
		}
	}
	if data.TLS {
		if err := t.RootDir("/etc/servik", 0o755, 0, 0); err != nil {
			return Result{}, err
		}
		if err := t.RootDir("/etc/servik/nginx", 0o755, 0, 0); err != nil {
			return Result{}, err
		}
		if err := t.WriteFile(cert.TLSConf, tlsConf); err != nil {
			return Result{}, err
		}
	}

	// PHP-FPM: one pool per site. Moving to another PHP version removes the
	// pool from the old one.
	reload := []string{spec.PHPVersion}
	if err := t.WriteFile(p.fpm, render(fpmTmpl, data)); err != nil {
		return Result{}, err
	}
	for _, v := range versions {
		if v == spec.PHPVersion {
			continue
		}
		before := t.Changed
		t.Changed = false
		if err := t.RemoveFile(fpmPool(v, spec.Domain)); err != nil {
			return Result{}, err
		}
		if t.Changed {
			reload = append(reload, v)
		}
		t.Changed = t.Changed || before
	}

	if err := t.WriteFile(p.nginxAvail, render(nginxTmpl, data)); err != nil {
		return Result{}, err
	}
	if err := t.Symlink(p.nginxAvail, p.nginxEnabled); err != nil {
		return Result{}, err
	}

	res = Result{
		Domain:       spec.Domain,
		User:         u.Name,
		UID:          u.UID,
		Home:         p.home,
		DocumentRoot: p.docroot,
		PHPVersion:   spec.PHPVersion,
		PHPSocket:    p.socket,
		NginxConfig:  p.nginxAvail,
		FPMConfig:    p.fpm,
		UserCreated:  created,
		Changed:      t.Changed,
	}
	if !t.Changed {
		return res, nil
	}
	if err := m.checkAndReload(ctx, t, reload); err != nil {
		return Result{}, err
	}
	return res, nil
}

// Delete removes a site's web server configuration and, if asked, its
// files and its account.
func (m *Manager) Delete(ctx context.Context, spec DeleteSpec) (res DeleteResult, err error) {
	if err := ValidateDomain(spec.Domain); err != nil {
		return DeleteResult{}, err
	}
	if err := ValidateUser(spec.User); err != nil {
		return DeleteResult{}, err
	}
	m.mu.Lock()
	defer m.mu.Unlock()

	versions, err := m.PHPVersions()
	if err != nil {
		return DeleteResult{}, &OperationError{err}
	}
	p := layout(spec.Domain, spec.User, "", "")
	res = DeleteResult{Domain: spec.Domain}

	t := txn.New(m.h)
	fail := func(err error) (DeleteResult, error) {
		if rbErr := t.Rollback(context.WithoutCancel(ctx)); rbErr != nil {
			err = fmt.Errorf("%w (rollback also failed: %v)", err, rbErr)
		}
		var v *ValidationError
		if errors.As(err, &v) {
			return DeleteResult{}, err
		}
		return DeleteResult{}, &OperationError{err}
	}

	if err := t.Unlink(p.nginxEnabled); err != nil {
		return fail(err)
	}
	if err := t.RemoveFile(p.nginxAvail); err != nil {
		return fail(err)
	}
	var reload []string
	for _, v := range versions {
		before := t.Changed
		t.Changed = false
		if err := t.RemoveFile(fpmPool(v, spec.Domain)); err != nil {
			return fail(err)
		}
		if t.Changed {
			reload = append(reload, v)
		}
		t.Changed = t.Changed || before
	}
	if t.Changed {
		if err := m.checkAndReload(ctx, t, reload); err != nil {
			return fail(err)
		}
	}
	// From here on nothing serves the site, so file removal is final.

	u, err := m.h.LookupUser(spec.User)
	userExists := err == nil
	if err != nil && !errors.Is(err, host.ErrUserNotFound) {
		return res, &OperationError{err}
	}

	if spec.RemoveFiles {
		if err := os.RemoveAll(m.h.Path(p.logDir)); err != nil {
			return res, &OperationError{err}
		}
		if userExists {
			if _, err := m.h.Run(ctx, host.AsUser(u.Name, host.Command{Name: "rm", Args: []string{"-rf", "--one-file-system", p.siteDir}})); err != nil {
				return res, &OperationError{err}
			}
		}
		res.FilesRemoved = true
	}

	if spec.RemoveUser && userExists {
		if u.Comment != userComment {
			return res, &OperationError{fmt.Errorf("user %q was not created by Servik; not removing it", u.Name)}
		}
		left, err := os.ReadDir(m.h.Path(p.home + "/web"))
		if err != nil && !errors.Is(err, os.ErrNotExist) {
			return res, &OperationError{err}
		}
		if len(left) > 0 {
			return res, nil // other sites still use this account
		}
		if _, err := m.h.Run(ctx, host.Command{Name: "userdel", Args: []string{u.Name}}); err != nil {
			return res, &OperationError{err}
		}
		// The account is gone, so nothing can race us inside its home.
		if err := os.RemoveAll(m.h.Path(p.home)); err != nil {
			return res, &OperationError{err}
		}
		res.UserRemoved = true
	}
	return res, nil
}

// ensureUser returns the site's account, creating it when missing.
func (m *Manager) ensureUser(ctx context.Context, t *txn.Tx, name, home string) (host.User, bool, error) {
	u, err := m.h.LookupUser(name)
	if err == nil {
		if u.Comment != userComment || u.UID < 1000 {
			return host.User{}, false, invalid("user %q already exists and was not created by Servik", name)
		}
		if filepath.Clean(u.Home) != home {
			return host.User{}, false, invalid("user %q has home %s, expected %s", name, u.Home, home)
		}
		return u, false, nil
	}
	if !errors.Is(err, host.ErrUserNotFound) {
		return host.User{}, false, err
	}
	add := host.Command{Name: "useradd", Args: []string{
		"--home-dir", home, "--no-create-home", "--shell", "/usr/sbin/nologin",
		"--user-group", "--comment", userComment, name,
	}}
	if _, err := m.h.Run(ctx, add); err != nil {
		return host.User{}, false, err
	}
	t.Changed = true
	t.OnUndo(func(ctx context.Context) error {
		_, err := m.h.Run(ctx, host.Command{Name: "userdel", Args: []string{name}})
		return err
	})
	u, err = m.h.LookupUser(name)
	if err != nil {
		return host.User{}, false, fmt.Errorf("user %q missing after useradd: %w", name, err)
	}
	return u, true, nil
}

// checkAndReload validates the new configuration and reloads the services.
// If validation fails, the caller's rollback restores the old files before
// anything was reloaded. If a reload fails, the files are rolled back and
// the services reloaded again with the old configuration.
func (m *Manager) checkAndReload(ctx context.Context, t *txn.Tx, phpVersions []string) error {
	for _, v := range phpVersions {
		if _, err := m.h.Run(ctx, host.Command{Name: "php-fpm" + v, Args: []string{"-t"}}); err != nil {
			return fmt.Errorf("PHP-FPM %s rejected the configuration: %w", v, err)
		}
	}
	if _, err := m.h.Run(ctx, host.Command{Name: "nginx", Args: []string{"-t"}}); err != nil {
		return fmt.Errorf("nginx rejected the configuration: %w", err)
	}

	units := []string{}
	for _, v := range phpVersions {
		units = append(units, "php"+v+"-fpm")
	}
	units = append(units, "nginx")
	for _, unit := range units {
		if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"reload", unit}}); err != nil {
			rbErr := t.Rollback(context.WithoutCancel(ctx))
			for _, u := range units {
				_, _ = m.h.Run(context.WithoutCancel(ctx), host.Command{Name: "systemctl", Args: []string{"reload", u}})
			}
			return errors.Join(fmt.Errorf("reloading %s failed: %w", unit, err), rbErr)
		}
	}
	return nil
}

// tlsData fills in the HTTPS part of the vhost. The certificate must be
// installed already (ssl.issue or ssl.install).
func (m *Manager) tlsData(d *tmplData, spec *TLSSpec) error {
	if spec == nil {
		return nil
	}
	if err := cert.ValidateName(spec.Certificate); err != nil {
		return err
	}
	full, key := cert.Files(spec.Certificate)
	info, err := cert.ReadInfo(m.h.Path(full), spec.Certificate)
	if errors.Is(err, os.ErrNotExist) {
		return invalid("certificate %q is not installed", spec.Certificate)
	}
	if err != nil {
		return err
	}
	if _, err := os.Stat(m.h.Path(key)); err != nil {
		return invalid("the private key of certificate %q is missing", spec.Certificate)
	}
	d.TLS, d.Redirect = true, spec.Redirect
	d.CertFile, d.KeyFile, d.TLSConf = full, key, cert.TLSConf
	if info.OCSP {
		if r := m.resolvers(); r != "" {
			d.Stapling, d.Resolvers = true, r
		}
	}
	return nil
}

// resolvers returns the upstream name servers from resolv.conf, for OCSP
// stapling. The local stub listener is skipped: Servik's DNS server may
// have turned it off.
func (m *Manager) resolvers() string {
	var out []string
	for _, f := range []string{"/run/systemd/resolve/resolv.conf", "/etc/resolv.conf"} {
		data, err := os.ReadFile(m.h.Path(f))
		if err != nil {
			continue
		}
		for _, line := range strings.Split(string(data), "\n") {
			fields := strings.Fields(line)
			if len(fields) != 2 || fields[0] != "nameserver" || strings.HasPrefix(fields[1], "127.") {
				continue
			}
			ip, err := netip.ParseAddr(fields[1])
			if err != nil || ip.Zone() != "" {
				continue
			}
			if ip.Is6() {
				out = append(out, "["+ip.String()+"]")
			} else {
				out = append(out, ip.String())
			}
		}
		if len(out) > 0 {
			break
		}
	}
	return strings.Join(out, " ")
}

// hasIPv6 reports whether the kernel has IPv6; Nginx refuses to start when
// asked to listen on [::] without it.
func (m *Manager) hasIPv6() bool {
	_, err := os.Stat(m.h.Path("/proc/net/if_inet6"))
	return err == nil
}

func contains(list []string, s string) bool {
	for _, v := range list {
		if v == s {
			return true
		}
	}
	return false
}
