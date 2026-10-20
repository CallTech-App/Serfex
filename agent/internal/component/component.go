// Package component installs, updates and removes the optional server
// software customers' sites use (the panel's Yazılım Yönetimi page):
// Apache, PHP versions, database servers, Redis, Memcached, Supervisor,
// Node.js, Composer, Python, Fail2ban, ModSecurity and ClamAV.
//
// Packages come from Ubuntu's archive (PHP versions Ubuntu does not ship
// from the Ondřej Surý PPA), Node.js and Composer from their publishers
// (see package laravel). Every operation writes its progress to a run log
// the panel shows live: lines starting with "▸ " are steps, "✓ " the
// result, "✗ " a failure and "↺ " the rollback.
//
// An install that fails part way is undone: Servik's configuration files
// go back to how they were and the packages the install added are purged.
// Removal refuses software something still uses (a site behind Apache, a
// PHP version with sites, a Redis instance, Supervisor programs) and
// archives the configuration before purging. The panel's own services
// (servik-web, servik-php and the panel database) are never touched.
//
// Layout:
//
//	/var/lib/servik/components/runs/<run>.log   operation logs, kept 90 days
//	/var/lib/servik/components/latest.json      newest Node.js and Composer versions, from component.check
//	/var/lib/servik/backups/components/         configuration archived before a removal
//	/etc/servik/components.json                 settings (ModSecurity mode and paranoia level)
package component

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"regexp"
	"sort"
	"strings"
	"sync"
	"time"

	"github.com/winchester37/servik/agent/internal/database"
	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/i18n"
	"github.com/winchester37/servik/agent/internal/laravel"
	"github.com/winchester37/servik/agent/internal/php"
	"github.com/winchester37/servik/agent/internal/site"
	"github.com/winchester37/servik/agent/internal/txn"
)

const (
	stateDir     = "/var/lib/servik/components"
	runDir       = stateDir + "/runs"
	latestFile   = stateDir + "/latest.json"
	settingsFile = "/etc/servik/components.json"
	// BackupDir keeps configuration archived before a removal.
	BackupDir = database.ComponentBackups

	runKeep = 90 * 24 * time.Hour
)

// ValidationError reports a request that can never succeed as given.
type ValidationError struct{ msg string }

func (e *ValidationError) Error() string { return e.msg }

func invalid(format string, a ...any) error { return &ValidationError{fmt.Sprintf(format, a...)} }

// OperationError reports a valid request that failed on the server.
type OperationError struct{ Err error }

func (e *OperationError) Error() string { return e.Err.Error() }
func (e *OperationError) Unwrap() error { return e.Err }

// InUseError refuses to remove software something still uses.
type InUseError struct {
	ID     string
	UsedBy []string
}

func (e *InUseError) Error() string {
	return fmt.Sprintf("%s is in use: %s", e.ID, strings.Join(e.UsedBy, ", "))
}

// ErrBusy is returned while another operation runs: apt allows one at a
// time.
var ErrBusy = errors.New("another software operation is running; try again when it has finished")

func opError(err error) error {
	var v *ValidationError
	var o *OperationError
	var u *InUseError
	if err == nil || errors.As(err, &v) || errors.As(err, &o) || errors.As(err, &u) || errors.Is(err, ErrBusy) {
		return err
	}
	return &OperationError{err}
}

// Manager runs component operations, one at a time.
type Manager struct {
	h       host.Host
	sim     bool
	sites   *site.Manager
	db      *database.Manager
	laravel *laravel.Manager

	op    sync.Mutex
	rmu   sync.Mutex
	mu    sync.Mutex
	busy  map[string]string
	comps map[string]*component

	// PHP writes the php.ini defaults of a newly installed PHP version.
	PHP *php.Manager
	// Addresses returns the server's own addresses, which Fail2ban never
	// bans.
	Addresses func() []string
	// Fail, when set, is asked before each step of an operation; an error
	// makes the step fail (tests and the real-host test's rollback check).
	Fail func(id, step string) error
	now  func() time.Time
	// demo holds a dry run's Fail2ban bans.
	demo map[string][]string
}

// NewManager returns a Manager. On a simulator, versions are invented and
// services count as running.
func NewManager(h host.Host, sites *site.Manager, db *database.Manager, l *laravel.Manager) *Manager {
	_, sim := h.(*host.Simulator)
	m := &Manager{h: h, sim: sim, sites: sites, db: db, laravel: l, busy: map[string]string{}, now: time.Now}
	m.comps = map[string]*component{}
	for _, c := range catalog() {
		m.comps[c.id] = c
	}
	return m
}

var (
	idRe  = regexp.MustCompile(`^[a-z0-9][a-z0-9.-]{0,31}$`)
	runRe = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$`)
)

func (m *Manager) get(id string) (*component, error) {
	if !idRe.MatchString(id) {
		return nil, invalid("component id %q is not valid", id)
	}
	c, ok := m.comps[id]
	if !ok {
		return nil, invalid("unknown component %q", id)
	}
	return c, nil
}

func (m *Manager) exists(abs string) bool {
	_, err := os.Stat(m.h.Path(abs))
	return err == nil
}

// Status describes one component.
type Status struct {
	ID        string `json:"id"`
	Installed bool   `json:"installed"`
	Version   string `json:"version,omitempty"`
	// Latest is set when a newer version than Version is available.
	Latest string `json:"latest,omitempty"`
	// Running is true when every service of the component is active.
	Running bool     `json:"running"`
	Units   []string `json:"units,omitempty"`
	// Busy is install, remove or update while that operation runs.
	Busy string `json:"busy,omitempty"`
	// UsedBy lists what the server itself uses it for (sites behind
	// Apache, the webmail's PHP version); it cannot be removed then.
	UsedBy []string `json:"used_by,omitempty"`
	// Required says why it can never be removed.
	Required string         `json:"required,omitempty"`
	Settings map[string]any `json:"settings,omitempty"`
	// Default marks the Node.js line builds use.
	Default bool `json:"default,omitempty"`
}

// List is every component's status.
type List struct {
	Components []Status `json:"components"`
	// CheckedAt is when component.check last refreshed the package lists.
	CheckedAt string `json:"checked_at,omitempty"`
}

// List reports every component. It reads package versions and service
// states and changes nothing.
func (m *Manager) List(ctx context.Context) List {
	ids := make([]string, 0, len(m.comps))
	for id := range m.comps {
		ids = append(ids, id)
	}
	sort.Strings(ids)

	latest := m.loadLatest()
	var pkgs, units []string
	installed := map[string]bool{}
	for _, id := range ids {
		c := m.comps[id]
		installed[id] = c.isInstalled(m)
		if installed[id] {
			if c.versionPkg != "" {
				pkgs = append(pkgs, c.pkg(m))
			}
			units = append(units, c.unitsOf(m)...)
		}
	}
	versions := m.versions(ctx, pkgs)
	candidates := m.candidates(ctx, pkgs)
	active := m.active(ctx, units)

	m.mu.Lock()
	busy := map[string]string{}
	for k, v := range m.busy {
		busy[k] = v
	}
	m.mu.Unlock()

	out := List{Components: []Status{}, CheckedAt: latest.CheckedAt}
	for _, id := range ids {
		c := m.comps[id]
		s := Status{ID: id, Installed: installed[id], Busy: busy[id], Required: c.required}
		if s.Installed {
			if c.versionPkg != "" {
				s.Version = versions[c.pkg(m)]
				if cand := candidates[c.pkg(m)]; cand != "" && cand != s.Version {
					s.Latest = cand
				}
			}
			if c.version != nil {
				s.Version = c.version(ctx, m)
				if l := latest.Versions[id]; l != "" && l != s.Version && s.Version != "" {
					s.Latest = l
				}
			}
			s.Units = c.unitsOf(m)
			s.Running = true
			for _, u := range s.Units {
				s.Running = s.Running && active[u]
			}
			if c.inUse != nil {
				s.UsedBy = c.inUse(m)
			}
			if c.isDefault != nil {
				s.Default = c.isDefault(m)
			}
		}
		if c.settings != nil {
			s.Settings = c.settings(m)
		}
		out.Components = append(out.Components, s)
	}
	return out
}

// Check refreshes the package lists and asks Node.js and Composer for
// their newest versions, so List can tell which updates are available.
func (m *Manager) Check(ctx context.Context) (List, error) {
	if !m.op.TryLock() {
		return List{}, ErrBusy
	}
	if _, err := m.h.Run(ctx, host.Command{Name: "apt-get", Args: []string{"update", "-q"}, Env: aptEnv}); err != nil {
		m.op.Unlock()
		return List{}, opError(fmt.Errorf("apt-get update failed: %w", err))
	}
	l := latestState{Versions: map[string]string{}, CheckedAt: m.now().UTC().Format(time.RFC3339)}
	for id, c := range m.comps {
		if c.latest == nil || !c.isInstalled(m) {
			continue
		}
		if v, err := c.latest(ctx, m); err == nil {
			l.Versions[id] = v
		}
	}
	err := m.saveLatest(l)
	m.op.Unlock()
	if err != nil {
		return List{}, opError(err)
	}
	return m.List(ctx), nil
}

type latestState struct {
	Versions  map[string]string `json:"versions"`
	CheckedAt string            `json:"checked_at"`
}

func (m *Manager) loadLatest() latestState {
	var l latestState
	data, err := os.ReadFile(m.h.Path(latestFile))
	if err == nil {
		_ = json.Unmarshal(data, &l)
	}
	if l.Versions == nil {
		l.Versions = map[string]string{}
	}
	return l
}

func (m *Manager) saveLatest(l latestState) error {
	if err := os.MkdirAll(m.h.Path(stateDir), 0o755); err != nil {
		return err
	}
	data, _ := json.MarshalIndent(l, "", "  ")
	return os.WriteFile(m.h.Path(latestFile), data, 0o644)
}

// OpSpec asks for an operation on one component.
type OpSpec struct {
	ID string `json:"id"`
	// Run names the log the progress goes to (component.run.read).
	Run string `json:"run"`
	// Port is the web database manager's port, for database servers.
	Port int `json:"port,omitempty"`
}

// Result is the component's status after an operation.
type Result struct {
	Action string `json:"action"`
	Status Status `json:"status"`
}

// Install installs a component, or repairs an installed one.
func (m *Manager) Install(ctx context.Context, spec OpSpec) (Result, error) {
	return m.operate(ctx, spec, "install")
}

// Remove uninstalls a component nothing uses.
func (m *Manager) Remove(ctx context.Context, spec OpSpec) (Result, error) {
	return m.operate(ctx, spec, "remove")
}

// Update installs a component's newest version.
func (m *Manager) Update(ctx context.Context, spec OpSpec) (Result, error) {
	return m.operate(ctx, spec, "update")
}

func (m *Manager) operate(ctx context.Context, spec OpSpec, action string) (Result, error) {
	c, err := m.get(spec.ID)
	if err != nil {
		return Result{}, err
	}
	if !runRe.MatchString(spec.Run) {
		return Result{}, invalid("run %q must be 1-64 letters, digits, - or _", spec.Run)
	}
	if !m.op.TryLock() {
		return Result{}, ErrBusy
	}
	defer m.op.Unlock()
	w, err := m.openRun(spec.Run)
	if err != nil {
		return Result{}, opError(err)
	}
	defer w.Close()

	m.mu.Lock()
	m.busy[c.id] = action
	m.mu.Unlock()
	defer func() {
		m.mu.Lock()
		delete(m.busy, c.id)
		m.mu.Unlock()
	}()

	o := &op{ctx: ctx, m: m, c: c, w: w, port: spec.Port}
	switch action {
	case "install":
		err = o.install()
	case "remove":
		err = o.remove()
	default:
		err = o.update()
	}
	if err != nil {
		fmt.Fprintf(w, "✗ %s\n", lastLine(err.Error()))
		return Result{}, opError(err)
	}
	fmt.Fprintln(w, "✓ "+i18n.T(ctx, map[string]string{"install": "Kuruldu", "remove": "Kaldırıldı", "update": "Güncellendi"}[action]))
	for _, s := range m.List(ctx).Components {
		if s.ID == c.id {
			return Result{Action: action, Status: s}, nil
		}
	}
	return Result{Action: action}, nil
}

func lastLine(s string) string {
	s = strings.TrimSpace(s)
	if len(s) > 600 {
		s = "..." + s[len(s)-600:]
	}
	return strings.ReplaceAll(s, "\n", " ")
}

// openRun creates the log of a run, readable only by root.
func (m *Manager) openRun(run string) (*os.File, error) {
	dir := m.h.Path(runDir)
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return nil, err
	}
	if entries, err := os.ReadDir(dir); err == nil {
		for _, e := range entries {
			if fi, err := e.Info(); err == nil && time.Since(fi.ModTime()) > runKeep {
				_ = os.Remove(dir + "/" + e.Name())
			}
		}
	}
	return os.OpenFile(dir+"/"+run+".log", os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o600)
}

// RunChunk is part of a run log.
type RunChunk struct {
	Data   string `json:"data"`
	Offset int64  `json:"offset"`
	Size   int64  `json:"size"`
	// Done is true once the operation has finished.
	Done bool `json:"done"`
}

const maxChunk = 128 << 10

// ReadRun returns a run's log from offset on, cut at a line break.
func (m *Manager) ReadRun(run string, offset int64) (RunChunk, error) {
	if !runRe.MatchString(run) {
		return RunChunk{}, invalid("run %q must be 1-64 letters, digits, - or _", run)
	}
	f, err := os.Open(m.h.Path(runDir + "/" + run + ".log"))
	if errors.Is(err, os.ErrNotExist) {
		return RunChunk{}, nil
	}
	if err != nil {
		return RunChunk{}, opError(err)
	}
	defer f.Close()
	fi, err := f.Stat()
	if err != nil {
		return RunChunk{}, opError(err)
	}
	if offset < 0 || offset > fi.Size() {
		offset = 0
	}
	buf := make([]byte, maxChunk)
	n, err := f.ReadAt(buf, offset)
	if err != nil && !errors.Is(err, io.EOF) {
		return RunChunk{}, opError(err)
	}
	buf = buf[:n]
	if i := strings.LastIndexByte(string(buf), '\n'); i >= 0 {
		buf = buf[:i+1]
	} else {
		buf = buf[:0]
	}
	end := offset + int64(len(buf))
	done := false
	if end == fi.Size() && len(buf) > 0 {
		// The last line says whether the operation finished.
		lines := strings.Split(strings.TrimRight(string(buf), "\n"), "\n")
		last := lines[len(lines)-1]
		done = strings.HasPrefix(last, "✓ ") || strings.HasPrefix(last, "✗ ")
	}
	return RunChunk{Data: string(buf), Offset: end, Size: fi.Size(), Done: done}, nil
}

// Settings are component settings the panel can change.
type Settings struct {
	// ModSecurity: "detect" logs attacks, "block" also refuses them.
	ModSecurityMode string `json:"modsecurity_mode"`
	// ModSecurityParanoia is the CRS paranoia level, 1 (few false
	// positives) to 4.
	ModSecurityParanoia int `json:"modsecurity_paranoia"`
}

func (m *Manager) loadSettings() Settings {
	s := Settings{ModSecurityMode: "detect", ModSecurityParanoia: 1}
	if data, err := os.ReadFile(m.h.Path(settingsFile)); err == nil {
		_ = json.Unmarshal(data, &s)
	}
	return s
}

// ConfigureSpec changes a component's settings.
type ConfigureSpec struct {
	ID       string   `json:"id"`
	Settings Settings `json:"settings"`
}

// Configure applies new settings to an installed component; a service
// that rejects them gets the previous ones back.
func (m *Manager) Configure(ctx context.Context, spec ConfigureSpec) (Status, error) {
	c, err := m.get(spec.ID)
	if err != nil {
		return Status{}, err
	}
	if c.applySettings == nil {
		return Status{}, invalid("%s has no settings", c.id)
	}
	if !m.op.TryLock() {
		return Status{}, ErrBusy
	}
	defer m.op.Unlock()
	if !c.isInstalled(m) {
		return Status{}, invalid("%s is not installed", c.id)
	}
	o := &op{ctx: ctx, m: m, c: c, w: io.Discard}
	if err := c.applySettings(o, spec.Settings); err != nil {
		return Status{}, opError(err)
	}
	for _, s := range m.List(ctx).Components {
		if s.ID == c.id {
			return s, nil
		}
	}
	return Status{}, nil
}

// writeSettings stores settings with a transaction, so a failed apply
// puts the previous ones back.
func (m *Manager) writeSettings(t *txn.Tx, s Settings) error {
	data, _ := json.MarshalIndent(s, "", "  ")
	if err := t.RootDir("/etc/servik", 0o755, 0, 0); err != nil {
		return err
	}
	return t.WriteOwned(settingsFile, append(data, '\n'), 0o644, 0, 0)
}
