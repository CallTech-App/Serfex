// Package laravel runs Laravel applications on Servik sites: zero-downtime
// deploys from Git or a fresh Laravel project, the .env file, Artisan,
// Composer and Node.js commands, queue workers, the scheduler, logs and
// maintenance mode.
//
// Layout of an application Servik deploys (inside the site directory
// /home/<user>/web/<domain>):
//
//	laravel/releases/<release>/   one directory per deploy
//	laravel/shared/.env           kept across releases (release/.env links here)
//	laravel/shared/storage/       kept across releases (release/storage links here)
//	laravel/current -> releases/<release>
//	laravel/.ssh/id_ed25519       deploy key for private repositories
//
// The site's web root becomes laravel/current/public. Switching current
// is a single rename, so visitors never see a half-built release.
//
// Outside the customer's home, owned by root:
//
//	/var/lib/servik/laravel/<domain>/runs/<run>.log   deploy and command output
//	/var/log/servik/laravel/<domain>/                 queue and scheduler output
//	/etc/systemd/system/servik-queue-<domain>_<name>@.service
//	/etc/systemd/system/servik-schedule-<domain>.{service,timer}
//	/opt/servik/composer, /opt/servik/node, /opt/servik/php/<version>/bin
//
// Everything inside the customer's home is read, written and run as the
// site's own account (runuser), never as root. Applications found on a
// site that Servik did not deploy are managed where they are (App.Path).
package laravel

import (
	"context"
	"fmt"
	"io"
	"os"
	"regexp"
	"strings"
	"sync"

	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/site"
)

const (
	// Base is the directory of a deployed application, relative to the
	// site directory.
	Base = "laravel"
	// CurrentPath is where the live release of a deployed application is.
	CurrentPath = Base + "/current"

	stateDir = "/var/lib/servik/laravel"
	logDir   = "/var/log/servik/laravel"
	toolsDir = "/opt/servik"
	unitDir  = "/etc/systemd/system"
)

// ValidationError reports a request that can never succeed as given.
type ValidationError struct{ msg string }

func (e *ValidationError) Error() string { return e.msg }

func invalid(format string, a ...any) error { return &ValidationError{fmt.Sprintf(format, a...)} }

// OperationError reports a valid request that failed on the server.
type OperationError struct{ Err error }

func (e *OperationError) Error() string { return e.Err.Error() }
func (e *OperationError) Unwrap() error { return e.Err }

func failed(format string, a ...any) error { return &OperationError{fmt.Errorf(format, a...)} }

// Target is the site an application belongs to.
type Target struct {
	Domain     string `json:"domain"`
	User       string `json:"user"`
	PHPVersion string `json:"php_version"`
}

// App is an application on a site. Path is its directory relative to the
// site directory: CurrentPath for applications Servik deploys, or wherever
// an existing application was found (for example "public_html").
type App struct {
	Target
	Path string `json:"path"`
}

func (t Target) validate() error {
	if err := site.ValidateDomain(t.Domain); err != nil {
		return err
	}
	if err := site.ValidateUser(t.User); err != nil {
		return err
	}
	if t.PHPVersion != "" {
		return site.ValidatePHPVersion(t.PHPVersion)
	}
	return nil
}

func (t Target) validatePHP() error {
	if err := t.validate(); err != nil {
		return err
	}
	if t.PHPVersion == "" {
		return invalid("php_version is required")
	}
	return nil
}

func (a App) validate() error {
	if err := a.Target.validatePHP(); err != nil {
		return err
	}
	if err := site.ValidateRelPath(a.Path); err != nil {
		return invalid("path: %s", err.Error())
	}
	return nil
}

var (
	idRe     = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$`)
	workerRe = regexp.MustCompile(`^[a-z0-9][a-z0-9-]{0,31}$`)
)

func validateID(kind, id string) error {
	if !idRe.MatchString(id) {
		return invalid("%s %q must be 1-64 letters, digits, - or _", kind, id)
	}
	return nil
}

// Manager runs Laravel operations on a Host.
type Manager struct {
	h   host.Host
	sim bool

	// Health requests a URL of a site from this server; tests replace it.
	Health func(ctx context.Context, domain, path string) HealthResult
	// Download fetches a URL; tests replace it.
	Download func(ctx context.Context, url string) ([]byte, error)

	mu    sync.Mutex
	locks map[string]*sync.Mutex
}

// NewManager returns a Manager. On a simulator, health checks pass and
// tools are not downloaded.
func NewManager(h host.Host) *Manager {
	_, sim := h.(*host.Simulator)
	m := &Manager{h: h, sim: sim, locks: map[string]*sync.Mutex{}}
	m.Health = checkHealth
	m.Download = download
	if sim {
		m.Health = func(context.Context, string, string) HealthResult {
			return HealthResult{OK: true, Status: 200, Millis: 38}
		}
	}
	return m
}

// lock serialises deploys and unit changes of one site.
func (m *Manager) lock(domain string) func() {
	m.mu.Lock()
	l, ok := m.locks[domain]
	if !ok {
		l = &sync.Mutex{}
		m.locks[domain] = l
	}
	m.mu.Unlock()
	l.Lock()
	return l.Unlock
}

func siteDir(t Target) string { return "/home/" + t.User + "/web/" + t.Domain }
func baseDir(t Target) string { return siteDir(t) + "/" + Base }
func (a App) dir() string     { return siteDir(a.Target) + "/" + a.Path }

func phpBin(v string) string      { return "/usr/bin/php" + v }
func shimDir(v string) string     { return toolsDir + "/php/" + v + "/bin" }
func composerPhar() string        { return toolsDir + "/composer/composer.phar" }
func nodeBin() string             { return toolsDir + "/node/current/bin" }
func runDir(domain string) string { return stateDir + "/" + domain + "/runs" }

// proxyEnv are passed on to customer commands: a server that reaches the
// internet through a proxy (one that inspects TLS too) needs them to fetch
// packages.
var proxyEnv = []string{"http_proxy", "https_proxy", "no_proxy", "HTTP_PROXY", "HTTPS_PROXY", "NO_PROXY", "NODE_EXTRA_CA_CERTS", "NODE_USE_ENV_PROXY"}

// cmd runs name as the site's account in dir, with a clean environment
// in which php and composer are the site's PHP version.
func (m *Manager) cmd(t Target, dir string, stream io.Writer, name string, args ...string) host.Command {
	return m.cmdEnv(t, dir, stream, nil, name, args...)
}

// cmdEnv is cmd with extra NAME=value environment variables.
func (m *Manager) cmdEnv(t Target, dir string, stream io.Writer, extra []string, name string, args ...string) host.Command {
	home := "/home/" + t.User
	path := "/usr/local/bin:/usr/bin:/bin"
	if t.PHPVersion != "" {
		path = shimDir(t.PHPVersion) + ":" + nodeBin() + ":" + path
	}
	env := []string{"-C", dir,
		"HOME=" + home, "USER=" + t.User, "LOGNAME=" + t.User, "PATH=" + path,
		"LANG=C.UTF-8", "LC_ALL=C.UTF-8", "TERM=dumb",
		"COMPOSER_HOME=" + home + "/.config/composer", "COMPOSER_NO_INTERACTION=1",
		"COREPACK_ENABLE_DOWNLOAD_PROMPT=0", "NO_UPDATE_NOTIFIER=1", "npm_config_update_notifier=false", "npm_config_fund=false",
	}
	for _, k := range proxyEnv {
		if v := os.Getenv(k); v != "" {
			env = append(env, k+"="+v)
		}
	}
	env = append(env, extra...)
	args = append(append(env, name), args...)
	return host.Command{Name: "runuser", Args: append([]string{"-u", t.User, "--", "env"}, args...), Stream: stream}
}

// exists reports whether abs exists, without following a final symlink.
// Only metadata is read as root; contents are always read as the user.
func (m *Manager) exists(abs string) bool {
	_, err := os.Lstat(m.h.Path(abs))
	return err == nil
}

// isDir reports whether abs is a directory, following symlinks.
func (m *Manager) isDir(abs string) bool {
	fi, err := os.Stat(m.h.Path(abs))
	return err == nil && fi.IsDir()
}

// readFile reads a file as the site's account, up to max bytes from the end.
func (m *Manager) readFile(ctx context.Context, t Target, abs string, max int) ([]byte, error) {
	out, err := m.h.Run(ctx, m.cmd(t, "/", nil, "tail", "-c", fmt.Sprint(max), abs))
	if err != nil {
		return nil, fmt.Errorf("reading %s failed", abs)
	}
	return []byte(out), nil
}

// writeFile writes a file as the site's account. dd keeps the content
// out of the command's output, so a failure cannot echo a secret.
func (m *Manager) writeFile(ctx context.Context, t Target, abs string, data []byte, mode string) error {
	c := m.cmd(t, "/", nil, "dd", "of="+abs, "status=none")
	c.Stdin = data
	if _, err := m.h.Run(ctx, c); err != nil {
		return fmt.Errorf("writing %s failed", abs)
	}
	if _, err := m.h.Run(ctx, m.cmd(t, "/", nil, "chmod", mode, abs)); err != nil {
		return fmt.Errorf("chmod %s failed", abs)
	}
	return nil
}

// userRun runs a command as the site's account and returns its output.
func (m *Manager) userRun(ctx context.Context, t Target, dir string, name string, args ...string) (string, error) {
	return m.h.Run(ctx, m.cmd(t, dir, nil, name, args...))
}

// phpInstalled reports whether the PHP CLI of version v is installed.
func (m *Manager) phpInstalled(v string) bool { return m.exists(phpBin(v)) }

func (m *Manager) requirePHP(v string) error {
	if !m.phpInstalled(v) {
		return invalid("the PHP %s command line (php%s-cli) is not installed", v, v)
	}
	return nil
}

func (m *Manager) requireApp(a App) error {
	if err := m.requirePHP(a.PHPVersion); err != nil {
		return err
	}
	if !m.exists(a.dir() + "/artisan") {
		return invalid("no Laravel application at %s", a.dir())
	}
	return nil
}

// unitPrefix is the start of every queue worker unit of a site. Domains
// cannot contain "_", so one site's prefix never matches another's.
func unitPrefix(domain string) string { return "servik-queue-" + domain + "_" }

func scheduleUnit(domain string) string { return "servik-schedule-" + domain }

func firstLine(s string) string {
	s = strings.TrimSpace(s)
	line, _, _ := strings.Cut(s, "\n")
	return strings.TrimSpace(line)
}

// host0 is the exit code of a command; one that could not start counts
// as 127, like a shell's "command not found".
func host0(err error) int {
	if c := host.ExitCode(err); c >= 0 {
		return c
	}
	return 127
}
