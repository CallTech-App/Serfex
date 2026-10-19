// Package security is the agent's part of Güvenlik: each site's WAF mode
// and rule exclusions, password-protected folders and blocked paths (all
// Nginx snippets in the site's site.ExtraDir), the security scan with its
// fixes, and SSH hardening.
//
//	/etc/servik/nginx/sites/<domain>/waf.conf        modsecurity on|off, rules file
//	/etc/servik/nginx/sites/<domain>/waf.rules       SecRuleEngine, rule exclusions
//	/etc/servik/nginx/sites/<domain>/protect.conf    auth_basic for protected folders
//	/etc/servik/nginx/sites/<domain>/protect-<n>.htpasswd
//	/etc/servik/nginx/sites/<domain>/deny.conf       paths nobody may fetch
//	/etc/ssh/sshd_config.d/00-servik.conf            SSH hardening
//
// The panel is the source of truth: it sends a site's whole WAF, folder
// or path list each time, and the agent writes it, checks Nginx and
// reloads, or puts the old files back.
package security

import (
	"context"
	"errors"
	"fmt"
	"os"
	"sort"
	"strings"
	"sync"
	"time"

	"github.com/winchester37/servik/agent/internal/files"
	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/site"
	"github.com/winchester37/servik/agent/internal/txn"
)

// ValidationError reports a request that can never succeed as given.
type ValidationError struct{ msg string }

func (e *ValidationError) Error() string { return e.msg }

func invalid(format string, a ...any) error { return &ValidationError{fmt.Sprintf(format, a...)} }

// OperationError wraps a failure while changing the server.
type OperationError struct{ Err error }

func (e *OperationError) Error() string { return e.Err.Error() }
func (e *OperationError) Unwrap() error { return e.Err }

// ErrWAFNotInstalled says ModSecurity is not installed (Yazılım Yönetimi).
var ErrWAFNotInstalled = errors.New("ModSecurity is not installed; install it from Yazılım Yönetimi first")

// Manager applies security settings. Snippet changes are serialised.
type Manager struct {
	h   host.Host
	sim bool
	mu  sync.Mutex
	// scan allows one scan at a time.
	scan sync.Mutex

	// Files quarantines files into a site's trash.
	Files *files.Manager
	// Fetch asks the local web server for a site's path and returns the
	// status code (0 when it could not ask). The scan uses it to tell an
	// exposed file from one Nginx refuses to send.
	Fetch func(ctx context.Context, domain, path string) int
	// Now is the clock; tests replace it.
	Now func() time.Time
	// WebUser is the account Nginx runs as; it reads password files.
	WebUser string
}

// NewManager returns a Manager acting on h.
func NewManager(h host.Host, f *files.Manager) *Manager {
	_, sim := h.(*host.Simulator)
	m := &Manager{h: h, sim: sim, Files: f, Now: time.Now, WebUser: "www-data"}
	if !sim {
		m.Fetch = fetchLocal
	}
	return m
}

func (m *Manager) exists(abs string) bool {
	_, err := os.Stat(m.h.Path(abs))
	return err == nil
}

// wafInstalled: the module is loaded and Servik's server-wide file exists.
func (m *Manager) wafInstalled() bool {
	return m.exists("/usr/lib/nginx/modules/ngx_http_modsecurity_module.so") && m.exists("/etc/nginx/conf.d/servik-modsecurity.conf")
}

func (m *Manager) checkSite(domain string) error {
	if err := site.ValidateDomain(domain); err != nil {
		return err
	}
	if !m.exists("/etc/nginx/sites-available/" + domain + ".conf") {
		return invalid("site %s does not exist", domain)
	}
	return nil
}

// snippet is one file of a site's ExtraDir; nil Data removes it.
type snippet struct {
	Name   string
	Data   []byte
	Secret bool // readable by the web server only
}

// writeSnippets replaces files in the site's ExtraDir, removes the
// managed files whose names match one of prune and are not written, then
// checks Nginx and reloads it. Nothing changes when Nginx refuses.
func (m *Manager) writeSnippets(ctx context.Context, domain string, snippets []snippet, prune func(name string) bool) (changed bool, err error) {
	dir := site.ExtraDir(domain)
	t := txn.New(m.h)
	defer func() {
		if err != nil {
			if rb := t.Rollback(context.WithoutCancel(ctx)); rb != nil {
				err = errors.Join(err, rb)
			}
			var v *ValidationError
			if !errors.As(err, &v) {
				err = &OperationError{err}
			}
		}
	}()
	for _, d := range []string{"/etc/servik", "/etc/servik/nginx", site.ExtraRoot, dir} {
		if err := t.RootDir(d, 0o755, 0, 0); err != nil {
			return false, err
		}
	}
	keep := map[string]bool{}
	web, werr := m.h.LookupUser(m.WebUser)
	for _, s := range snippets {
		keep[s.Name] = true
		switch {
		case s.Data == nil:
			err = t.RemoveFile(dir + "/" + s.Name)
		case s.Secret:
			if werr != nil {
				return false, fmt.Errorf("web server user %s: %w", m.WebUser, werr)
			}
			err = t.WriteFileMode(dir+"/"+s.Name, s.Data, 0o640, 0, web.GID)
		default:
			err = t.WriteFile(dir+"/"+s.Name, s.Data)
		}
		if err != nil {
			return false, err
		}
	}
	if prune != nil {
		entries, _ := os.ReadDir(m.h.Path(dir))
		names := []string{}
		for _, e := range entries {
			names = append(names, e.Name())
		}
		sort.Strings(names)
		for _, n := range names {
			if !keep[n] && prune(n) {
				if err := t.RemoveFile(dir + "/" + n); err != nil {
					return false, err
				}
			}
		}
	}
	if !t.Changed {
		return false, nil
	}
	if out, err := m.h.Run(ctx, host.Command{Name: "nginx", Args: []string{"-t"}}); err != nil {
		return false, fmt.Errorf("nginx rejected the configuration: %s", lastLines(out, 3))
	}
	if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"reload", "nginx"}}); err != nil {
		_ = t.Rollback(context.WithoutCancel(ctx))
		_, _ = m.h.Run(context.WithoutCancel(ctx), host.Command{Name: "systemctl", Args: []string{"reload", "nginx"}})
		return false, err
	}
	return true, nil
}

func lastLines(s string, n int) string {
	lines := strings.Split(strings.TrimSpace(s), "\n")
	if len(lines) > n {
		lines = lines[len(lines)-n:]
	}
	return strings.Join(lines, " | ")
}

func header(what string) string {
	return "# " + txn.Marker + ". Manual changes are overwritten.\n# " + what + "\n"
}
