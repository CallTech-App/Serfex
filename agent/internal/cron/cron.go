// Package cron runs the scheduled tasks of sites (Zamanlanmış Görevler).
//
// The system's cron daemon starts the tasks: each site has a file in
// /etc/cron.d whose lines start "servik-agent cron-exec" as the site's
// user. cron-exec (Exec) reads the task from the site's state, runs its
// command with /bin/sh in the site folder with a small, fixed
// environment, enforces the task's timeout, refuses to start a run while
// the previous one still goes, and keeps a record of each run (exit
// code, duration, the last MaxOutput bytes of output) in the user's own
// home, where the agent reads it back carefully (Runs).
//
// Layout (for user kahve and site kahve.com):
//
//	/etc/servik/cron/kahve.com.json             the site's tasks, root:kahve 0640 (cron-exec reads it as kahve)
//	/etc/cron.d/servik-kahve_com                the enabled tasks as crontab lines, root 0644
//	/var/lib/servik/cron/php/<version>/php      the site's PHP, first on the tasks' PATH
//	/home/kahve/.servik/cron/kahve.com/<id>/    lock, running.json and runs/<start>.json (the newest RunsKept)
//
// A suspended site keeps its tasks with none enabled: its crontab file
// is removed, its state stays.
package cron

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/panel"
	"github.com/winchester37/servik/agent/internal/site"
	"github.com/winchester37/servik/agent/internal/txn"
)

const (
	// StateDir holds one state file per site.
	StateDir = "/etc/servik/cron"
	// CronDir is where the cron daemon reads system crontabs.
	CronDir = "/etc/cron.d"
	// ShimRoot holds a folder per PHP version with a php link to it.
	ShimRoot = "/var/lib/servik/cron/php"
	// InstalledExe is the agent of an installed server. It stays put
	// across updates; the release folder os.Executable names does not.
	InstalledExe = panel.CurrentLink + "/bin/servik-agent"

	// MaxTasks bounds the tasks of one site.
	MaxTasks = 100
	// MaxCommand bounds a task's command, in bytes.
	MaxCommand = 2000
	// DefaultTimeout is a task's timeout when the panel gives none.
	DefaultTimeout = 3600
	// MaxTimeout bounds a task's timeout (seconds).
	MaxTimeout = 86400
	// MaxOutput is how much of a run's output is kept: the last bytes.
	MaxOutput = 64 << 10
	// RunsKept is how many run records each task keeps.
	RunsKept = 20
	// maxRecord bounds a record file the agent reads.
	maxRecord = 256 << 10
	// basePath is the tasks' PATH after the PHP folder.
	basePath = "/usr/local/bin:/usr/bin:/bin"
)

// ValidationError reports a request that can never succeed as given.
type ValidationError struct{ msg string }

func (e *ValidationError) Error() string { return e.msg }

func invalid(format string, a ...any) error { return &ValidationError{fmt.Sprintf(format, a...)} }

// OperationError reports a valid request that failed on the server.
type OperationError struct{ Err error }

func (e *OperationError) Error() string { return e.Err.Error() }
func (e *OperationError) Unwrap() error { return e.Err }

func opError(err error) error {
	var v *ValidationError
	var o *OperationError
	if err == nil || errors.As(err, &v) || errors.As(err, &o) {
		return err
	}
	return &OperationError{err}
}

// Task is one scheduled command of a site.
type Task struct {
	// ID is the panel's id of the task, unique within the site.
	ID int `json:"id"`
	// Schedule has the five fields of crontab(5): minute, hour, day of
	// month, month, day of week.
	Schedule string `json:"schedule"`
	// Command runs with /bin/sh -c in the site folder.
	Command string `json:"command"`
	Enabled bool   `json:"enabled"`
	// Timeout is how long a run may take, in seconds (DefaultTimeout
	// when 0); the run is stopped then.
	Timeout int `json:"timeout"`
}

// Spec is every task of a site, as the panel wants them. It is also
// what the state file keeps.
type Spec struct {
	Domain string `json:"domain"`
	User   string `json:"user"`
	// PHP is the site's PHP version: "php" in a command runs it.
	PHP   string `json:"php"`
	Tasks []Task `json:"tasks"`
}

// Manager applies the tasks of sites.
type Manager struct {
	h host.Host
	// Exe is the agent binary the crontab lines start (cron-exec).
	Exe string
	// Demo seeds made-up run records for new tasks, so a dry run's
	// pages show a history.
	Demo bool
	// Spawn, when set, replaces starting a manual run (tests).
	Spawn func(ctx context.Context, user, domain string, id int) error
	now   func() time.Time
	mu    sync.Mutex
}

// NewManager returns a manager. The crontab lines start InstalledExe on
// an installed server, else this binary.
func NewManager(h host.Host) *Manager {
	m := &Manager{h: h, now: time.Now}
	if _, err := os.Stat(h.Path(InstalledExe)); err == nil {
		m.Exe = InstalledExe
	} else if exe, err := os.Executable(); err == nil {
		if real, err := filepath.EvalSymlinks(exe); err == nil {
			exe = real
		}
		m.Exe = exe
	}
	return m
}

func (m *Manager) sim() bool {
	_, ok := m.h.(*host.Simulator)
	return ok
}

func (m *Manager) exists(abs string) bool {
	_, err := os.Stat(m.h.Path(abs))
	return err == nil
}

// StateFile is the state of a site's tasks.
func StateFile(domain string) string { return StateDir + "/" + domain + ".json" }

// CronFile is a site's crontab. The cron daemon ignores files whose
// names hold dots, so every character outside [a-zA-Z0-9_-] becomes _.
func CronFile(domain string) string {
	b := []byte(domain)
	for i, c := range b {
		if !(c >= 'a' && c <= 'z' || c >= 'A' && c <= 'Z' || c >= '0' && c <= '9' || c == '_' || c == '-') {
			b[i] = '_'
		}
	}
	return CronDir + "/servik-" + string(b)
}

// ShimDir is the folder put first on the tasks' PATH for a PHP version.
func ShimDir(php string) string { return ShimRoot + "/" + php }

// TaskDir holds a task's lock, running marker and run records.
func TaskDir(user, domain string, id int) string {
	return SitesDir(user, domain) + "/" + strconv.Itoa(id)
}

// SitesDir holds the run records of a site's tasks.
func SitesDir(user, domain string) string { return "/home/" + user + "/.servik/cron/" + domain }

func siteDir(user, domain string) string { return "/home/" + user + "/web/" + domain }

// exeRe is what may stand unquoted in a crontab line.
var exeRe = regexp.MustCompile(`^/[A-Za-z0-9._/+-]+$`)

// validate checks the spec and fills defaults. Schedules are
// normalised.
func (spec *Spec) validate() error {
	if err := site.ValidateDomain(spec.Domain); err != nil {
		return invalid("%s", err.Error())
	}
	if err := site.ValidateUser(spec.User); err != nil {
		return invalid("%s", err.Error())
	}
	if spec.PHP != "" {
		if err := site.ValidatePHPVersion(spec.PHP); err != nil {
			return invalid("%s", err.Error())
		}
	}
	if len(spec.Tasks) > MaxTasks {
		return invalid("a site can have at most %d scheduled tasks", MaxTasks)
	}
	if spec.Tasks == nil {
		spec.Tasks = []Task{}
	}
	seen := map[int]bool{}
	for i := range spec.Tasks {
		t := &spec.Tasks[i]
		if t.ID < 1 {
			return invalid("task ids must be positive, not %d", t.ID)
		}
		if seen[t.ID] {
			return invalid("task %d is listed twice", t.ID)
		}
		seen[t.ID] = true
		s, err := NormalizeSchedule(t.Schedule)
		if err != nil {
			return err
		}
		t.Schedule = s
		if strings.TrimSpace(t.Command) == "" {
			return invalid("task %d has no command", t.ID)
		}
		if len(t.Command) > MaxCommand {
			return invalid("the command of task %d is longer than %d bytes", t.ID, MaxCommand)
		}
		if strings.ContainsAny(t.Command, "\n\r\x00") {
			return invalid("the command of task %d must be a single line", t.ID)
		}
		if t.Timeout == 0 {
			t.Timeout = DefaultTimeout
		}
		if t.Timeout < 1 || t.Timeout > MaxTimeout {
			return invalid("the timeout of task %d must be from 1 to %d seconds", t.ID, MaxTimeout)
		}
	}
	return nil
}

// owner returns the site's user after checking it is one: an existing
// account other than root, with the site layout's home and site folder.
func (m *Manager) owner(user, domain string) (host.User, error) {
	u, err := m.h.LookupUser(user)
	if err != nil {
		return host.User{}, invalid("site user %s: %v", user, err)
	}
	if u.UID == 0 || filepath.Clean(u.Home) != "/home/"+user {
		return host.User{}, invalid("%s is not a site user", user)
	}
	if !m.exists(siteDir(user, domain)) {
		return host.User{}, invalid("the site folder %s is missing", siteDir(user, domain))
	}
	return u, nil
}

func (m *Manager) load(domain string) (*Spec, error) {
	data, err := os.ReadFile(m.h.Path(StateFile(domain)))
	if err != nil {
		return nil, err
	}
	var st Spec
	if err := json.Unmarshal(data, &st); err != nil {
		return nil, fmt.Errorf("%s: %w", StateFile(domain), err)
	}
	return &st, nil
}

// loadSite returns a site's state, refusing sites without one.
func (m *Manager) loadSite(domain string) (*Spec, error) {
	if err := site.ValidateDomain(domain); err != nil {
		return nil, invalid("%s", err.Error())
	}
	st, err := m.load(domain)
	if errors.Is(err, os.ErrNotExist) {
		return nil, invalid("%s has no scheduled tasks", domain)
	}
	return st, err
}

// Applied is the result of Apply.
type Applied struct {
	Tasks   int `json:"tasks"`
	Enabled int `json:"enabled"`
}

// Apply replaces the tasks of a site. A failure leaves the previous
// tasks in place.
func (m *Manager) Apply(ctx context.Context, spec Spec) (Applied, error) {
	if err := spec.validate(); err != nil {
		return Applied{}, err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	res, err := m.apply(ctx, spec)
	return res, opError(err)
}

func (m *Manager) apply(ctx context.Context, spec Spec) (res Applied, err error) {
	owner, err := m.owner(spec.User, spec.Domain)
	if err != nil {
		return Applied{}, err
	}
	prev, err := m.load(spec.Domain)
	if err != nil && !errors.Is(err, os.ErrNotExist) {
		return Applied{}, err
	}
	if prev != nil && prev.User != spec.User {
		return Applied{}, invalid("the tasks of %s belong to %s", spec.Domain, prev.User)
	}
	t := txn.New(m.h)
	defer func() {
		if err != nil {
			if rbErr := t.Rollback(context.WithoutCancel(ctx)); rbErr != nil {
				err = fmt.Errorf("%w (rollback also failed: %v)", err, rbErr)
			}
		}
	}()
	if err := m.write(t, &spec, owner); err != nil {
		return Applied{}, err
	}

	// Removed tasks take their history along.
	if prev != nil {
		keep := map[int]bool{}
		for _, task := range spec.Tasks {
			keep[task.ID] = true
		}
		for _, task := range prev.Tasks {
			if !keep[task.ID] {
				m.removeHistory(ctx, spec.User, TaskDir(spec.User, spec.Domain, task.ID))
			}
		}
	}
	if m.Demo {
		m.seed(spec)
	}
	res.Tasks = len(spec.Tasks)
	for _, task := range spec.Tasks {
		if task.Enabled {
			res.Enabled++
		}
	}
	return res, nil
}

// write puts a site's state, PHP folder and crontab in place.
func (m *Manager) write(t *txn.Tx, spec *Spec, owner host.User) error {
	if err := t.RootDir(StateDir, 0o711, 0, 0); err != nil {
		return err
	}
	data, err := json.MarshalIndent(spec, "", "  ")
	if err != nil {
		return err
	}
	// The site's user reads it (cron-exec); others may not: commands
	// can hold secrets.
	if err := t.WriteOwned(StateFile(spec.Domain), append(data, '\n'), 0o640, 0, owner.GID); err != nil {
		return err
	}
	if err := m.shim(t, spec.PHP); err != nil {
		return err
	}
	return m.writeCrontab(t, spec)
}

// shim links php to the site's PHP version in the folder that comes
// first on the tasks' PATH, when that version is installed.
func (m *Manager) shim(t *txn.Tx, php string) error {
	if php == "" || !m.exists("/usr/bin/php"+php) {
		return nil
	}
	for _, d := range []string{"/var/lib/servik", "/var/lib/servik/cron", ShimRoot, ShimDir(php)} {
		if err := t.RootDir(d, 0o755, 0, 0); err != nil {
			return err
		}
	}
	return t.ReplaceSymlink("/usr/bin/php"+php, ShimDir(php)+"/php")
}

// Crontab renders a site's crontab file; "" when no task is enabled.
func Crontab(spec Spec, exe string) string {
	var b strings.Builder
	n := 0
	for _, task := range spec.Tasks {
		if !task.Enabled {
			continue
		}
		if n == 0 {
			fmt.Fprintf(&b, "# %s. Manual changes are overwritten.\n", txn.Marker)
			fmt.Fprintf(&b, "# Scheduled tasks of %s: each runs as %s through servik-agent cron-exec.\n", spec.Domain, spec.User)
			b.WriteString("SHELL=/bin/sh\nMAILTO=\"\"\n\n")
		}
		n++
		fmt.Fprintf(&b, "%s %s %s cron-exec %s %d\n", task.Schedule, spec.User, exe, spec.Domain, task.ID)
	}
	return b.String()
}

func (m *Manager) writeCrontab(t *txn.Tx, spec *Spec) error {
	exe := m.Exe
	if !exeRe.MatchString(exe) && m.Demo {
		// A dry run's binary may sit anywhere (go run); nothing runs
		// its crontab anyway.
		exe = InstalledExe
	}
	content := Crontab(*spec, exe)
	if content == "" {
		return t.RemoveFile(CronFile(spec.Domain))
	}
	if !exeRe.MatchString(exe) {
		return fmt.Errorf("the agent's path %q cannot go into a crontab", exe)
	}
	if err := t.RootDir(CronDir, 0o755, 0, 0); err != nil {
		return err
	}
	return t.WriteFileMode(CronFile(spec.Domain), []byte(content), 0o644, 0, 0)
}

// removeHistory deletes run records as the site's user (it is the
// user's folder), if that user still exists.
func (m *Manager) removeHistory(ctx context.Context, user, dir string) {
	if _, err := m.h.LookupUser(user); err != nil {
		return
	}
	if _, err := os.Lstat(m.h.Path(dir)); err != nil {
		return
	}
	_, _ = m.h.Run(ctx, host.AsUser(user, host.Command{Name: "rm", Args: []string{"-rf", "--one-file-system", dir}}))
}

// Remove deletes a site's tasks: its crontab, state and run records.
// Removing a site without tasks succeeds.
func (m *Manager) Remove(ctx context.Context, domain string) (map[string]bool, error) {
	if err := site.ValidateDomain(domain); err != nil {
		return nil, invalid("%s", err.Error())
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	if err := m.remove(ctx, domain); err != nil {
		return nil, opError(err)
	}
	return map[string]bool{"removed": true}, nil
}

func (m *Manager) remove(ctx context.Context, domain string) error {
	st, err := m.load(domain)
	if err != nil && !errors.Is(err, os.ErrNotExist) {
		return err
	}
	t := txn.New(m.h)
	if err := t.RemoveFile(CronFile(domain)); err != nil {
		return err
	}
	if err := os.Remove(m.h.Path(StateFile(domain))); err != nil && !errors.Is(err, os.ErrNotExist) {
		_ = t.Rollback(context.WithoutCancel(ctx))
		return err
	}
	if st != nil && site.ValidateUser(st.User) == nil {
		m.removeHistory(ctx, st.User, SitesDir(st.User, domain))
	}
	return nil
}

// Reconcile writes every site's crontab and PHP folder again from its
// state, with the current Exe (after an update the agent's path may
// have changed), and removes crontabs Servik wrote for sites without
// state. The agent calls it when it starts.
func (m *Manager) Reconcile(ctx context.Context) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	entries, err := os.ReadDir(m.h.Path(StateDir))
	if err != nil && !errors.Is(err, os.ErrNotExist) {
		return err
	}
	var errs []error
	want := map[string]bool{}
	for _, e := range entries {
		domain, ok := strings.CutSuffix(e.Name(), ".json")
		if !ok || !e.Type().IsRegular() || site.ValidateDomain(domain) != nil {
			continue
		}
		want[CronFile(domain)] = true
		if err := m.reconcile(ctx, domain); err != nil {
			errs = append(errs, fmt.Errorf("%s: %w", domain, err))
		}
	}
	files, _ := os.ReadDir(m.h.Path(CronDir))
	for _, f := range files {
		abs := CronDir + "/" + f.Name()
		if !strings.HasPrefix(f.Name(), "servik-") || want[abs] {
			continue
		}
		// Files without the marker are someone else's.
		if data, err := os.ReadFile(m.h.Path(abs)); err != nil || !txn.HasMarker(data) {
			continue
		}
		t := txn.New(m.h)
		if err := t.RemoveFile(abs); err != nil {
			errs = append(errs, err)
		}
	}
	return errors.Join(errs...)
}

func (m *Manager) reconcile(ctx context.Context, domain string) error {
	st, err := m.load(domain)
	if err != nil {
		return err
	}
	t := txn.New(m.h)
	owner, err := m.h.LookupUser(st.User)
	if err != nil || st.Domain != domain || site.ValidateUser(st.User) != nil {
		// Nothing can run for a user that is gone.
		return t.RemoveFile(CronFile(domain))
	}
	if err := m.write(t, st, owner); err != nil {
		_ = t.Rollback(context.WithoutCancel(ctx))
		return err
	}
	return nil
}

// RunSpec names a task.
type RunSpec struct {
	Domain string `json:"domain"`
	ID     int    `json:"id"`
}

// Started is the result of Run.
type Started struct {
	Started bool `json:"started"`
	// Running is true when the task was still running, so no new run
	// started.
	Running bool `json:"running,omitempty"`
}

// Run starts a task now, in the background, as the site's user (a run
// with trigger "manual"). A task that is still running is not started
// again.
func (m *Manager) Run(ctx context.Context, spec RunSpec) (Started, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	st, err := m.loadSite(spec.Domain)
	if err != nil {
		return Started{}, opError(err)
	}
	if find(st.Tasks, spec.ID) == nil {
		return Started{}, invalid("%s has no task %d", spec.Domain, spec.ID)
	}
	owner, err := m.owner(st.User, st.Domain)
	if err != nil {
		return Started{}, err
	}
	if running, _ := m.running(owner, spec.Domain, spec.ID); running != nil {
		return Started{Running: true}, nil
	}
	if m.sim() {
		if err := m.simulateRun(st.User, spec.Domain, spec.ID); err != nil {
			return Started{}, opError(err)
		}
		return Started{Started: true}, nil
	}
	if err := m.spawn(ctx, st.User, spec.Domain, spec.ID); err != nil {
		return Started{}, opError(fmt.Errorf("starting the task failed: %w", err))
	}
	return Started{Started: true}, nil
}

func find(tasks []Task, id int) *Task {
	for i := range tasks {
		if tasks[i].ID == id {
			return &tasks[i]
		}
	}
	return nil
}

// RunsSpec asks for the newest run records of a task.
type RunsSpec struct {
	Domain string `json:"domain"`
	ID     int    `json:"id"`
	// Limit is how many records to return (default and most: RunsKept).
	Limit int `json:"limit"`
}

// Runs is a task's history.
type Runs struct {
	// Runs are newest first.
	Runs    []Record `json:"runs"`
	Running bool     `json:"running"`
	// RunningSince is when the current run started.
	RunningSince *time.Time `json:"running_since,omitempty"`
}

// Runs reads a task's run records, newest first. They live in the site
// user's home, which the user can change, so they are read without
// following symlinks and with bounded sizes; files that do not parse are
// skipped.
func (m *Manager) Runs(_ context.Context, spec RunsSpec) (Runs, error) {
	if spec.Limit == 0 {
		spec.Limit = RunsKept
	}
	if spec.Limit < 1 || spec.Limit > RunsKept {
		return Runs{}, invalid("limit must be from 1 to %d", RunsKept)
	}
	st, err := m.loadSite(spec.Domain)
	if err != nil {
		return Runs{}, opError(err)
	}
	if spec.ID < 1 {
		return Runs{}, invalid("task ids must be positive, not %d", spec.ID)
	}
	owner, err := m.h.LookupUser(st.User)
	if err != nil {
		return Runs{}, invalid("site user %s: %v", st.User, err)
	}
	res := Runs{Runs: m.records(owner, spec.Domain, spec.ID, spec.Limit)}
	if r, _ := m.running(owner, spec.Domain, spec.ID); r != nil {
		res.Running = true
		since := r.StartedAt
		res.RunningSince = &since
	}
	return res, nil
}

// sortRecords orders record file names newest first.
func sortRecords(names []string) []string {
	var out []string
	for _, n := range names {
		if stem, ok := strings.CutSuffix(n, ".json"); ok && digits(stem, 20) {
			out = append(out, n)
		}
	}
	sort.Slice(out, func(i, j int) bool {
		a, _ := strconv.ParseInt(strings.TrimSuffix(out[i], ".json"), 10, 64)
		b, _ := strconv.ParseInt(strings.TrimSuffix(out[j], ".json"), 10, 64)
		return a > b
	})
	return out
}
