// Package logrotate keeps the logs Serfex's web servers write from filling
// the disk. Every log is rotated once a day, compressed from the second
// day on, and kept for the number of days the panel sets (Araçlar ve
// Ayarlar → Sunucu).
//
//	/etc/serfex/logs.json             the settings
//	/etc/logrotate.d/serfex-sites     every site's Nginx and Apache logs
//	/etc/logrotate.d/serfex-php       every site's PHP error log
//	/etc/logrotate.d/serfex-web       serfex-web and serfex-php (the panel)
//	/etc/logrotate.d/serfex-webmail   the webmail's Nginx, PHP and Roundcube or SnappyMail logs
//
// The rules are rebuilt from what is on the server (Sync): the sites are
// the PHP-FPM pools Serfex wrote. Laravel's and ModSecurity's logs have
// their own rules (packages laravel and component).
//
// The web servers reopen their logs after rotation, so no line is lost.
// The newest rotated file stays uncompressed (delaycompress): readers that
// follow a log (package monitor) finish it after the rename.
package logrotate

import (
	"context"
	"encoding/json"
	"fmt"
	"log/slog"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"sync"
	"time"

	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/site"
	"github.com/calltech-app/serfex/agent/internal/txn"
)

const (
	// File holds the settings.
	File = "/etc/serfex/logs.json"
	// Dir is where logrotate reads its rules.
	Dir = "/etc/logrotate.d"

	SitesRules   = Dir + "/serfex-sites"
	PHPRules     = Dir + "/serfex-php"
	WebRules     = Dir + "/serfex-web"
	WebmailRules = Dir + "/serfex-webmail"

	// DefaultKeepDays is how long rotated logs are kept unless the panel
	// says otherwise.
	DefaultKeepDays = 14
	// MaxKeepDays bounds the setting: a year of daily files.
	MaxKeepDays = 365

	sitesLogs   = "/var/log/serfex/sites"
	webLogs     = "/var/log/serfex/web"
	webmailLogs = "/var/log/serfex/webmail"
	webmailUser = "serfex-webmail"
)

// Settings are the rotation settings the panel can change.
type Settings struct {
	// KeepDays is how many days of rotated logs are kept.
	KeepDays int `json:"keep_days"`
}

// ValidationError reports settings the agent refuses.
type ValidationError struct{ Msg string }

func (e *ValidationError) Error() string { return e.Msg }

// Validate checks the settings.
func (s Settings) Validate() error {
	if s.KeepDays < 1 || s.KeepDays > MaxKeepDays {
		return &ValidationError{fmt.Sprintf("keep_days must be between 1 and %d", MaxKeepDays)}
	}
	return nil
}

// Load reads the settings; missing ones are the defaults.
func Load(h host.Host) Settings {
	s := Settings{KeepDays: DefaultKeepDays}
	if data, err := os.ReadFile(h.Path(File)); err == nil {
		_ = json.Unmarshal(data, &s)
	}
	if s.Validate() != nil {
		s.KeepDays = DefaultKeepDays
	}
	return s
}

// OperationError reports settings logrotate refused.
type OperationError struct{ Err error }

func (e *OperationError) Error() string { return e.Err.Error() }
func (e *OperationError) Unwrap() error { return e.Err }

// Manager writes the rules.
type Manager struct {
	h host.Host
	// mu keeps two rebuilds from interleaving: the last one to run has
	// seen every site written before it started.
	mu sync.Mutex
}

// NewManager returns a Manager.
func NewManager(h host.Host) *Manager { return &Manager{h: h} }

// Settings returns the current settings.
func (m *Manager) Settings() Settings { return Load(m.h) }

// Sync rebuilds the rules from the sites and services on the server. It
// does nothing where logrotate is not installed, or on a nil Manager.
func (m *Manager) Sync(ctx context.Context) error {
	if m == nil {
		return nil
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	if !isDir(m.h, Dir) {
		return nil
	}
	t := txn.New(m.h)
	if err := write(ctx, t, Load(m.h)); err != nil {
		_ = t.Rollback(ctx)
		return &OperationError{err}
	}
	return nil
}

// Run rebuilds the rules every hour, for services set up after the agent
// started (the panel's web server, the webmail).
func (m *Manager) Run(ctx context.Context, log *slog.Logger) {
	tick := time.NewTicker(time.Hour)
	defer tick.Stop()
	for {
		if err := m.Sync(ctx); err != nil {
			log.Error("writing log rotation rules", "err", err)
		}
		select {
		case <-ctx.Done():
			return
		case <-tick.C:
		}
	}
}

// Set saves the settings and rewrites the rules with them.
func (m *Manager) Set(ctx context.Context, s Settings) (Settings, error) {
	if err := s.Validate(); err != nil {
		return Settings{}, err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	t := txn.New(m.h)
	fail := func(err error) (Settings, error) {
		_ = t.Rollback(ctx)
		return Settings{}, &OperationError{err}
	}
	if err := t.RootDir("/etc/serfex", 0o755, 0, 0); err != nil {
		return fail(err)
	}
	data, _ := json.MarshalIndent(s, "", "  ")
	if err := t.WriteOwned(File, append(data, '\n'), 0o644, 0, 0); err != nil {
		return fail(err)
	}
	if isDir(m.h, Dir) {
		if err := write(ctx, t, s); err != nil {
			return fail(err)
		}
	}
	return s, nil
}

// write puts every rule file in place and has logrotate check the ones
// that changed; a rule it refuses is undone with the rest.
func write(ctx context.Context, t *txn.Tx, s Settings) error {
	var check []string
	for _, f := range Rules(t.H, s) {
		before := t.Changed
		t.Changed = false
		var err error
		if f.Content == nil {
			err = t.RemoveFile(f.Path)
		} else {
			err = t.WriteFile(f.Path, f.Content)
		}
		if err != nil {
			return err
		}
		if t.Changed && f.Content != nil {
			check = append(check, f.Path)
		}
		t.Changed = t.Changed || before
	}
	for _, path := range check {
		// --debug reads the rules and rotates nothing.
		if out, err := t.H.Run(ctx, host.Command{Name: "logrotate", Args: []string{"--debug", path}}); err != nil {
			return fmt.Errorf("logrotate refused %s: %s", path, strings.TrimSpace(out))
		}
	}
	return nil
}

// RuleFile is one file in /etc/logrotate.d; nil Content removes it.
type RuleFile struct {
	Path    string
	Content []byte
}

// Rules returns the rule files the server needs.
func Rules(h host.Host, s Settings) []RuleFile {
	files := []RuleFile{{Path: SitesRules}, {Path: PHPRules}, {Path: WebRules}, {Path: WebmailRules}}
	if isDir(h, sitesLogs) {
		files[0].Content = rules(s, rule{
			paths:   []string{sitesLogs + "/*/*.log"},
			reopen:  []string{reopenNginx, reloadApache},
			comment: "Every site's Nginx access and error logs and Apache error log.",
		})
	}
	if sites := Sites(h); len(sites) > 0 {
		var rs []rule
		for _, si := range sites {
			// The directory belongs to the site's user, so logrotate works
			// in it as that user: a link planted there reaches nothing else.
			rs = append(rs, rule{paths: []string{phpLog(si)}, su: si.User})
		}
		rs[0].comment = "Every site's PHP error log. PHP opens the file for each message, so nothing reopens it."
		files[1].Content = rules(s, rs...)
	}
	if isDir(h, webLogs) {
		files[2].Content = rules(s, rule{
			paths:   []string{webLogs + "/*.log"},
			reopen:  []string{reopen("serfex-web"), reopen("serfex-php")},
			comment: "serfex-web and serfex-php: the panel's own web server and PHP.",
		})
	}
	if isDir(h, webmailLogs) {
		if _, err := h.LookupUser(webmailUser); err == nil {
			files[3].Content = rules(s, rule{
				paths:   []string{webmailLogs + "/*.log"},
				su:      webmailUser,
				reopen:  []string{reopenNginx},
				comment: "The webmail: Nginx, PHP and Roundcube or SnappyMail.",
			})
		}
	}
	return files
}

// Site is a site whose PHP error log is rotated.
type Site struct {
	Domain, User string
}

func phpLog(si Site) string { return "/home/" + si.User + "/web/" + si.Domain + "/logs/php-error.log" }

// Sites reads the sites from the PHP-FPM pools Serfex wrote: one per site.
// A site whose account or log directory is missing is left out, since
// logrotate refuses a whole file over one unknown user.
func Sites(h host.Host) []Site {
	files, _ := filepath.Glob(h.Path("/etc/php/*/fpm/pool.d/*.conf"))
	seen := map[string]bool{}
	var sites []Site
	for _, f := range files {
		b, err := os.ReadFile(f)
		if err != nil || !txn.HasMarker(b) {
			continue
		}
		var si Site
		for _, line := range strings.Split(string(b), "\n") {
			line = strings.TrimSpace(line)
			if strings.HasPrefix(line, "[") && strings.HasSuffix(line, "]") {
				si.Domain = line[1 : len(line)-1]
				continue
			}
			if k, v, ok := strings.Cut(line, "="); ok && strings.TrimSpace(k) == "user" {
				si.User = strings.TrimSpace(v)
			}
		}
		if seen[si.Domain] || site.ValidateDomain(si.Domain) != nil || site.ValidateUser(si.User) != nil {
			continue
		}
		if _, err := h.LookupUser(si.User); err != nil || !isDir(h, filepath.Dir(phpLog(si))) {
			continue
		}
		seen[si.Domain] = true
		sites = append(sites, si)
	}
	sort.Slice(sites, func(i, j int) bool { return sites[i].Domain < sites[j].Domain })
	return sites
}

// Commands that make a server reopen its logs. A server that is not
// running has nothing open.
const (
	reopenNginx  = "if systemctl -q is-active nginx; then systemctl kill --kill-whom=main --signal=USR1 nginx; fi"
	reloadApache = "if systemctl -q is-active apache2; then systemctl reload apache2; fi"
)

// reopen sends USR1 to a unit's main process: Nginx and PHP-FPM both
// reopen their logs on it.
func reopen(unit string) string {
	return "if systemctl -q is-active " + unit + "; then systemctl kill --kill-whom=main --signal=USR1 " + unit + "; fi"
}

type rule struct {
	paths   []string
	su      string
	reopen  []string
	comment string
}

// rules renders a rule file. Every rule repeats its options: options set
// outside a rule would also change the rules of files read after it.
func rules(s Settings, list ...rule) []byte {
	var b strings.Builder
	b.WriteString("# " + txn.Marker + ". Manual changes are overwritten.\n")
	for _, r := range list {
		if r.comment != "" {
			b.WriteString("# " + r.comment + "\n")
		}
		b.WriteString(strings.Join(r.paths, " ") + " {\n")
		fmt.Fprintf(&b, "\tdaily\n\trotate %d\n\tmissingok\n\tnotifempty\n\tcompress\n\tdelaycompress\n", s.KeepDays)
		if r.su != "" {
			// The user cannot give a new file root's or Nginx's owner;
			// the writer makes it again.
			fmt.Fprintf(&b, "\tsu %s %s\n\tnocreate\n", r.su, r.su)
		} else {
			b.WriteString("\tcreate\n")
		}
		if len(r.reopen) > 0 {
			b.WriteString("\tsharedscripts\n\tpostrotate\n")
			for _, c := range r.reopen {
				b.WriteString("\t\t" + c + "\n")
			}
			b.WriteString("\tendscript\n")
		}
		b.WriteString("}\n")
	}
	return []byte(b.String())
}

func isDir(h host.Host, abs string) bool {
	fi, err := os.Stat(h.Path(abs))
	return err == nil && fi.IsDir()
}
