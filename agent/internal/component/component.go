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

	"github.com/calltech-app/serfex/agent/internal/aptcache"
	"github.com/calltech-app/serfex/agent/internal/database"
	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/i18n"
	"github.com/calltech-app/serfex/agent/internal/laravel"
	"github.com/calltech-app/serfex/agent/internal/php"
	"github.com/calltech-app/serfex/agent/internal/site"
	"github.com/calltech-app/serfex/agent/internal/txn"
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
	// Candidate is the version an install would bring, while it is not
	// installed, and CandidateFrom where it comes from: an apt archive as
	// host and path, nodejs.org or getcomposer.org. Both are empty when
	// that is not known yet (component.check finds out).
	Candidate     string `json:"candidate,omitempty"`
	CandidateFrom string `json:"candidate_from,omitempty"`
	// Choices are the major versions a database server can be installed
	// in, the default first (database.Releases).
	Choices []database.Release `json:"choices,omitempty"`
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
	var pkgs, all, units []string
	installed := map[string]bool{}
	for _, id := range ids {
		c := m.comps[id]
		installed[id] = c.isInstalled(m)
		if c.versionPkg != "" {
			all = append(all, c.pkg(m))
		}
		if installed[id] {
			if c.versionPkg != "" {
				pkgs = append(pkgs, c.pkg(m))
			}
			units = append(units, c.unitsOf(m)...)
		}
	}
	versions := m.versions(ctx, pkgs)
	candidates := m.candidates(ctx, all)
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
				if cand := candidates[c.pkg(m)].Version; cand != "" && cand != s.Version {
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
		} else {
			var cand aptcache.Candidate
			switch {
			case c.choices != nil:
				// Archives apt does not list were read by the last check.
				s.Choices = c.choices(ctx, m, false)
				for i, r := range s.Choices {
					if r.Version == "" {
						for _, l := range latest.Choices[id] {
							if l.Major == r.Major {
								s.Choices[i].Version, s.Choices[i].From = l.Version, l.From
							}
						}
					}
				}
				cand = aptcache.Candidate{Version: s.Choices[0].Version, From: s.Choices[0].From}
			case c.versionPkg != "":
				cand = candidates[c.pkg(m)]
			}
			if cand.Version == "" {
				cand = latest.Candidates[id]
			}
			s.Candidate, s.CandidateFrom = cand.Version, cand.From
		}
		if c.settings != nil {
			s.Settings = c.settings(m)
		}
		out.Components = append(out.Components, s)
	}
	return out
}

// Check refreshes the package lists and asks Node.js and Composer for
// their newest versions, so List can tell which updates are available and
// what an install would bring.
func (m *Manager) Check(ctx context.Context) (List, error) {
	if !m.op.TryLock() {
		return List{}, ErrBusy
	}
	if _, err := m.h.Run(ctx, host.Command{Name: "apt-get", Args: []string{"update", "-q"}, Env: aptEnv}); err != nil {
		m.op.Unlock()
		return List{}, opError(fmt.Errorf("apt-get update failed: %w", err))
	}
	l := latestState{Versions: map[string]string{}, Candidates: map[string]aptcache.Candidate{}, Choices: map[string][]database.Release{}, CheckedAt: m.now().UTC().Format(time.RFC3339)}
	for id, c := range m.comps {
		switch {
		case c.isInstalled(m):
			if c.latest == nil {
				continue
			}
			if v, err := c.latest(ctx, m); err == nil {
				l.Versions[id] = v
			}
		case c.choices != nil:
			l.Choices[id] = c.choices(ctx, m, true)
		case c.latest != nil && c.from != "":
			if v, err := c.latest(ctx, m); err == nil && v != "" {
				l.Candidates[id] = aptcache.Candidate{Version: v, From: c.from}
			}
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
	// Versions are the newest versions of installed components that do
	// not come from apt; Candidates what installing the others would
	// bring when apt's own lists cannot tell.
	Versions   map[string]string             `json:"versions"`
	Candidates map[string]aptcache.Candidate `json:"candidates,omitempty"`
	Choices    map[string][]database.Release `json:"choices,omitempty"`
	CheckedAt  string                        `json:"checked_at"`
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
	if l.Candidates == nil {
		l.Candidates = map[string]aptcache.Candidate{}
	}
	if l.Choices == nil {
		l.Choices = map[string][]database.Release{}
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
	// Version is the major version a database server is installed in
	// (one of its Choices; "" for the default).
	Version string `json:"version,omitempty"`
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

	o := &op{ctx: ctx, m: m, c: c, w: w, port: spec.Port, version: spec.Version}
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
