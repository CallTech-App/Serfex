// Package update keeps an installed Servik up to date: it finds new
// releases on GitHub, installs one (panel and agent together) and goes
// back to the previous release, automatically when the new one fails its
// health check or on request.
//
// A release is one archive, servik-<version>-linux-<arch>.tar.gz, listed
// in the release's SHA256SUMS. It unpacks into its own directory under
// /opt/servik/releases and becomes the running one when /opt/servik/current
// is switched to it (see package panel for the layout). The panel's
// database, settings and files live outside the releases, so the previous
// release stays ready to switch back to.
//
// An update runs outside the agent, as a transient systemd unit started
// from the running agent's binary ("servik-agent -update-run RUN"), because
// it restarts the agent and the panel it would otherwise run inside. Its
// steps go to a run log the panel shows live, with the same markers as
// component operations: "▸ " a step, "$ " a command, "↺ " the way back,
// "✓ " done and "✗ " failed.
//
// Layout:
//
//	/var/lib/servik/updates/latest.json         the last check: releases newer than the running one
//	/var/lib/servik/updates/state.json          previous release, migration batch, running and last update
//	/var/lib/servik/updates/runs/<run>.json     what a run does
//	/var/lib/servik/updates/runs/<run>.log      what it did, kept 180 days
//	/var/lib/servik/updates/downloads/<v>/      archives being installed
//	/var/lib/servik/updates/snapshots/          the panel database taken before the last switch
package update

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"

	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/panel"
)

const (
	stateDir    = "/var/lib/servik/updates"
	runDir      = stateDir + "/runs"
	downloadDir = stateDir + "/downloads"
	snapshotDir = stateDir + "/snapshots"
	latestFile  = stateDir + "/latest.json"
	stateFile   = stateDir + "/state.json"
	lockFile    = stateDir + "/lock"

	// AgentUnit is the agent's own systemd unit.
	AgentUnit = "servik-agent"
	// SumsAsset lists the SHA-256 sums of a release's archives.
	SumsAsset = "SHA256SUMS"

	runKeep = 180 * 24 * time.Hour
	// startGrace is how long a started run may take to take the lock.
	startGrace = 2 * time.Minute
)

// Channels: stable sees releases, beta also pre-releases.
const (
	Stable = "stable"
	Beta   = "beta"
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
	if err == nil || errors.As(err, &v) || errors.As(err, &o) || errors.Is(err, ErrBusy) {
		return err
	}
	return &OperationError{err}
}

// ErrBusy is returned while an update or rollback runs.
var ErrBusy = errors.New("an update is running; try again when it has finished")

// Manager checks for, installs and rolls back releases.
type Manager struct {
	h   host.Host
	sim bool
	// Arch is the release architecture this server takes (amd64, arm64).
	Arch string
	// Agent is the running agent's version.
	Agent string
	// HTTP fetches the release list and archives.
	HTTP *http.Client

	// Spawn starts Execute(run) in a process of its own (a systemd unit);
	// nil runs it on a goroutine (dry runs and tests).
	Spawn func(ctx context.Context, run string) error
	// RestartAgent restarts the agent and waits until it answers with
	// version; nil only logs (the agent running the update is not
	// restarted in a dry run).
	RestartAgent func(ctx context.Context, version string) error
	// ApplyPanel applies the running agent's panel setup (Setup: units
	// and servik-web) after a switch; nil skips it.
	ApplyPanel func(ctx context.Context) error
	// Web serves the panel on servik-web (the database package's
	// SetupPanelWeb), for Setup.
	Web func(ctx context.Context) (any, error)
	// Health checks that the panel answers; nil checks it over HTTPS on
	// WebPort, or skips the check on a simulator.
	Health func(ctx context.Context) error
	// WebPort is servik-web's port.
	WebPort func() int
	// Fail, when set, is asked before each step; an error fails the step
	// (tests and the real-host test's rollback check).
	Fail func(step string) error

	now  func() time.Time
	mu   sync.Mutex
	wait time.Duration
}

// NewManager returns a Manager for the agent version running.
func NewManager(h host.Host, agentVersion, arch string) *Manager {
	_, sim := h.(*host.Simulator)
	return &Manager{
		h: h, sim: sim, Arch: arch, Agent: agentVersion,
		HTTP: &http.Client{Timeout: 15 * time.Minute},
		now:  time.Now, wait: 2 * time.Second,
	}
}

var runRe = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$`)

// Release is one published version.
type Release struct {
	Version     string `json:"version"`
	Tag         string `json:"tag"`
	Name        string `json:"name"`
	Notes       string `json:"notes"`
	PublishedAt string `json:"published_at"`
	Prerelease  bool   `json:"prerelease"`
	URL         string `json:"url"`
	// Archive and Sums are the download addresses for this server.
	Archive string `json:"archive"`
	Size    int64  `json:"size"`
	Sums    string `json:"sums"`
}

// latest is the result of the last check.
type latest struct {
	CheckedAt string    `json:"checked_at"`
	Channel   string    `json:"channel"`
	Current   string    `json:"current"`
	Releases  []Release `json:"releases"`
	Error     string    `json:"error,omitempty"`
}

// Outcome is how an update or rollback ended.
type Outcome struct {
	Run        string `json:"run"`
	Kind       string `json:"kind"`
	From       string `json:"from"`
	To         string `json:"to"`
	Status     string `json:"status"` // succeeded, failed, rolled_back
	Error      string `json:"error,omitempty"`
	FinishedAt string `json:"finished_at"`
}

type state struct {
	// Previous is the release a rollback returns to.
	Previous string `json:"previous,omitempty"`
	// Batch is the migration batch the running release added, which a
	// rollback undoes.
	Batch     int       `json:"batch,omitempty"`
	Running   string    `json:"running,omitempty"`
	StartedAt time.Time `json:"started_at,omitzero"`
	Last      *Outcome  `json:"last,omitempty"`
}

// Status describes the installed release and the last check.
type Status struct {
	// Installed is false on a development setup, which cannot update.
	Installed bool   `json:"installed"`
	Version   string `json:"version"`
	Agent     string `json:"agent"`
	Arch      string `json:"arch"`
	// Previous is the release "Önceki sürüme dön" goes back to.
	Previous string `json:"previous,omitempty"`
	// Running is the run in progress.
	Running    string    `json:"running,omitempty"`
	Last       *Outcome  `json:"last,omitempty"`
	CheckedAt  string    `json:"checked_at,omitempty"`
	Channel    string    `json:"channel,omitempty"`
	CheckError string    `json:"check_error,omitempty"`
	Releases   []Release `json:"releases"`
}

// Status reports the installed release, the newer ones found by the last
// check and the last update. An update whose process died is marked
// failed here.
func (m *Manager) Status() (Status, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	st, err := m.loadState()
	if err != nil {
		return Status{}, opError(err)
	}
	if st.Running != "" && !m.lockHeld() && m.now().Sub(st.StartedAt) > startGrace {
		m.interrupted(st)
	}
	cur, _ := panel.CurrentVersion(m.h)
	l := m.loadLatest()
	out := Status{
		Installed: panel.Installed(m.h), Version: cur, Agent: m.Agent, Arch: m.Arch,
		Running: st.Running, Last: st.Last, CheckedAt: l.CheckedAt, Channel: l.Channel, CheckError: l.Error,
		Releases: newer(l.Releases, cur),
	}
	if st.Previous != "" && m.exists(panel.ReleasesDir+"/"+st.Previous+"/VERSION") {
		out.Previous = st.Previous
	}
	return out, nil
}

// interrupted ends a run whose process is gone. Called with m.mu held.
func (m *Manager) interrupted(st *state) {
	run := st.Running
	if f, err := os.OpenFile(m.h.Path(runDir+"/"+run+".log"), os.O_WRONLY|os.O_APPEND, 0o600); err == nil {
		if chunk, _ := m.readRun(run, 0); !chunk.Done {
			fmt.Fprintln(f, "✗ Güncelleme yarıda kesildi; sunucuda journalctl -u servik-update-"+run+" ile bakın.")
		}
		f.Close()
	}
	spec, _ := m.loadSpec(run)
	st.Last = &Outcome{Run: run, Kind: spec.Kind, From: spec.From, To: spec.To, Status: "failed", Error: "Güncelleme yarıda kesildi.", FinishedAt: m.now().UTC().Format(time.RFC3339)}
	st.Running = ""
	_ = m.saveState(st)
}

// CheckSpec asks for the releases of a channel.
type CheckSpec struct {
	Channel string `json:"channel"`
}

// Check asks the release source for releases newer than the running one
// on channel and keeps the answer for Status and Start.
func (m *Manager) Check(ctx context.Context, spec CheckSpec) (Status, error) {
	if spec.Channel == "" {
		spec.Channel = Stable
	}
	if spec.Channel != Stable && spec.Channel != Beta {
		return Status{}, invalid("channel must be %s or %s", Stable, Beta)
	}
	cfg, ok := panel.Load(m.h)
	if !ok {
		cfg.Source = panel.DefaultSource
	}
	cur, _ := panel.CurrentVersion(m.h)
	l := latest{CheckedAt: m.now().UTC().Format(time.RFC3339), Channel: spec.Channel, Current: cur, Releases: []Release{}}
	releases, err := m.fetchReleases(ctx, cfg.Source, spec.Channel)
	if err != nil {
		l.Error = err.Error()
		// The releases found before stay offered.
		l.Releases = m.loadLatest().Releases
	} else {
		l.Releases = newer(releases, cur)
	}
	if err := m.saveJSON(latestFile, l, 0o644); err != nil {
		return Status{}, opError(err)
	}
	st, err := m.Status()
	if err != nil {
		return Status{}, err
	}
	if l.Error != "" {
		return st, &OperationError{fmt.Errorf("checking %s: %s", cfg.Source, l.Error)}
	}
	return st, nil
}

// fetchReleases reads the GitHub release list of source.
func (m *Manager) fetchReleases(ctx context.Context, source, channel string) ([]Release, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, strings.TrimRight(source, "/")+"/releases?per_page=50", nil)
	if err != nil {
		return nil, err
	}
	req.Header.Set("Accept", "application/vnd.github+json")
	req.Header.Set("User-Agent", "servik-agent/"+m.Agent)
	cctx, cancel := context.WithTimeout(ctx, 30*time.Second)
	defer cancel()
	resp, err := m.HTTP.Do(req.WithContext(cctx))
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		body, _ := io.ReadAll(io.LimitReader(resp.Body, 300))
		return nil, fmt.Errorf("HTTP %d: %s", resp.StatusCode, strings.TrimSpace(string(body)))
	}
	var list []struct {
		Tag         string `json:"tag_name"`
		Name        string `json:"name"`
		Body        string `json:"body"`
		Draft       bool   `json:"draft"`
		Prerelease  bool   `json:"prerelease"`
		PublishedAt string `json:"published_at"`
		URL         string `json:"html_url"`
		Assets      []struct {
			Name string `json:"name"`
			URL  string `json:"browser_download_url"`
			Size int64  `json:"size"`
		} `json:"assets"`
	}
	if err := json.NewDecoder(io.LimitReader(resp.Body, 16<<20)).Decode(&list); err != nil {
		return nil, fmt.Errorf("unreadable release list: %w", err)
	}
	var out []Release
	for _, r := range list {
		v := strings.TrimPrefix(r.Tag, "v")
		if r.Draft || (r.Prerelease && channel != Beta) || !validVersion(v) {
			continue
		}
		rel := Release{Version: v, Tag: r.Tag, Name: r.Name, Notes: r.Body, PublishedAt: r.PublishedAt, Prerelease: r.Prerelease, URL: r.URL}
		for _, a := range r.Assets {
			switch a.Name {
			case ArchiveName(v, m.Arch):
				rel.Archive, rel.Size = a.URL, a.Size
			case SumsAsset:
				rel.Sums = a.URL
			}
		}
		if rel.Archive != "" && rel.Sums != "" {
			out = append(out, rel)
		}
	}
	return out, nil
}

// ArchiveName is the release archive for arch.
func ArchiveName(version, arch string) string {
	return "servik-" + version + "-linux-" + arch + ".tar.gz"
}

// newer returns the releases newer than cur, newest first.
func newer(releases []Release, cur string) []Release {
	out := []Release{}
	for _, r := range releases {
		if cur == "" || Compare(r.Version, cur) > 0 {
			out = append(out, r)
		}
	}
	sort.SliceStable(out, func(i, j int) bool { return Compare(out[i].Version, out[j].Version) > 0 })
	return out
}

// StartSpec asks to install a release found by the last check.
type StartSpec struct {
	Version string `json:"version"`
	Run     string `json:"run"`
}

// RollbackSpec asks to return to the previous release.
type RollbackSpec struct {
	Run string `json:"run"`
}

// Started names the run that was started.
type Started struct {
	Run  string `json:"run"`
	From string `json:"from"`
	To   string `json:"to"`
}

// Start begins installing a release; progress goes to the run log.
func (m *Manager) Start(ctx context.Context, spec StartSpec) (Started, error) {
	if !runRe.MatchString(spec.Run) {
		return Started{}, invalid("run %q must be 1-64 letters, digits, - or _", spec.Run)
	}
	if !panel.Installed(m.h) {
		return Started{}, invalid("Servik was not installed from a release here, so it cannot update itself")
	}
	cur, err := panel.CurrentVersion(m.h)
	if err != nil {
		return Started{}, opError(err)
	}
	var rel *Release
	for _, r := range m.loadLatest().Releases {
		if r.Version == spec.Version {
			rel = &r
			break
		}
	}
	if rel == nil {
		return Started{}, invalid("release %s was not found by the last check", spec.Version)
	}
	if Compare(rel.Version, cur) <= 0 {
		return Started{}, invalid("%s is not newer than the running %s", rel.Version, cur)
	}
	return m.begin(ctx, runSpec{Kind: "upgrade", Run: spec.Run, From: cur, To: rel.Version, Archive: rel.Archive, Sums: rel.Sums})
}

// Rollback begins returning to the previous release.
func (m *Manager) Rollback(ctx context.Context, spec RollbackSpec) (Started, error) {
	if !runRe.MatchString(spec.Run) {
		return Started{}, invalid("run %q must be 1-64 letters, digits, - or _", spec.Run)
	}
	st, err := m.Status()
	if err != nil {
		return Started{}, err
	}
	if st.Previous == "" {
		return Started{}, invalid("there is no previous release to return to")
	}
	raw, _ := m.loadState()
	return m.begin(ctx, runSpec{Kind: "rollback", Run: spec.Run, From: st.Version, To: st.Previous, Batch: raw.Batch})
}

func (m *Manager) begin(ctx context.Context, spec runSpec) (Started, error) {
	m.mu.Lock()
	st, err := m.loadState()
	if err != nil {
		m.mu.Unlock()
		return Started{}, opError(err)
	}
	if m.lockHeld() || (st.Running != "" && m.now().Sub(st.StartedAt) <= startGrace) {
		m.mu.Unlock()
		return Started{}, ErrBusy
	}
	if err := os.MkdirAll(m.h.Path(runDir), 0o700); err != nil {
		m.mu.Unlock()
		return Started{}, opError(err)
	}
	m.pruneRuns()
	if err := m.saveJSON(runDir+"/"+spec.Run+".json", spec, 0o600); err != nil {
		m.mu.Unlock()
		return Started{}, opError(err)
	}
	st.Running, st.StartedAt = spec.Run, m.now()
	if err := m.saveState(st); err != nil {
		m.mu.Unlock()
		return Started{}, opError(err)
	}
	m.mu.Unlock()

	if m.Spawn == nil {
		go func() { _ = m.Execute(context.WithoutCancel(ctx), spec.Run) }()
	} else if err := m.Spawn(ctx, spec.Run); err != nil {
		m.mu.Lock()
		if st, e := m.loadState(); e == nil && st.Running == spec.Run {
			st.Running = ""
			_ = m.saveState(st)
		}
		m.mu.Unlock()
		return Started{}, opError(fmt.Errorf("starting the update: %w", err))
	}
	return Started{Run: spec.Run, From: spec.From, To: spec.To}, nil
}

// RunChunk is part of a run log.
type RunChunk struct {
	Data   string `json:"data"`
	Offset int64  `json:"offset"`
	Size   int64  `json:"size"`
	// Done is true once the run has finished.
	Done bool `json:"done"`
}

const maxChunk = 128 << 10

// ReadRun returns a run's log from offset on, cut at a line break.
func (m *Manager) ReadRun(run string, offset int64) (RunChunk, error) {
	if !runRe.MatchString(run) {
		return RunChunk{}, invalid("run %q must be 1-64 letters, digits, - or _", run)
	}
	return m.readRun(run, offset)
}

func (m *Manager) readRun(run string, offset int64) (RunChunk, error) {
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
		lines := strings.Split(strings.TrimRight(string(buf), "\n"), "\n")
		last := lines[len(lines)-1]
		done = strings.HasPrefix(last, "✓ ") || strings.HasPrefix(last, "✗ ")
	}
	return RunChunk{Data: string(buf), Offset: end, Size: fi.Size(), Done: done}, nil
}

func (m *Manager) pruneRuns() {
	dir := m.h.Path(runDir)
	entries, _ := os.ReadDir(dir)
	for _, e := range entries {
		if fi, err := e.Info(); err == nil && m.now().Sub(fi.ModTime()) > runKeep {
			_ = os.Remove(dir + "/" + e.Name())
		}
	}
}

// lockHeld reports whether a run holds the update lock.
func (m *Manager) lockHeld() bool {
	unlock, err := m.lock()
	if err != nil {
		return true
	}
	unlock()
	return false
}

// lock takes the update lock, which the running update holds; the kernel
// drops it when that process dies.
func (m *Manager) lock() (func(), error) {
	if err := os.MkdirAll(m.h.Path(stateDir), 0o755); err != nil {
		return nil, err
	}
	f, err := os.OpenFile(m.h.Path(lockFile), os.O_CREATE|os.O_RDWR, 0o600)
	if err != nil {
		return nil, err
	}
	if err := syscall.Flock(int(f.Fd()), syscall.LOCK_EX|syscall.LOCK_NB); err != nil {
		f.Close()
		return nil, ErrBusy
	}
	return func() {
		_ = syscall.Flock(int(f.Fd()), syscall.LOCK_UN)
		f.Close()
	}, nil
}

func (m *Manager) exists(abs string) bool {
	_, err := os.Stat(m.h.Path(abs))
	return err == nil
}

func (m *Manager) loadState() (*state, error) {
	st := &state{}
	data, err := os.ReadFile(m.h.Path(stateFile))
	if errors.Is(err, os.ErrNotExist) {
		return st, nil
	}
	if err != nil {
		return nil, err
	}
	return st, json.Unmarshal(data, st)
}

func (m *Manager) saveState(st *state) error { return m.saveJSON(stateFile, st, 0o600) }

func (m *Manager) loadLatest() latest {
	var l latest
	if data, err := os.ReadFile(m.h.Path(latestFile)); err == nil {
		_ = json.Unmarshal(data, &l)
	}
	return l
}

func (m *Manager) loadSpec(run string) (runSpec, error) {
	var s runSpec
	data, err := os.ReadFile(m.h.Path(runDir + "/" + run + ".json"))
	if err != nil {
		return s, err
	}
	return s, json.Unmarshal(data, &s)
}

// saveJSON writes v atomically.
func (m *Manager) saveJSON(abs string, v any, mode os.FileMode) error {
	data, err := json.MarshalIndent(v, "", "  ")
	if err != nil {
		return err
	}
	p := m.h.Path(abs)
	if err := os.MkdirAll(p[:strings.LastIndexByte(p, '/')], 0o755); err != nil {
		return err
	}
	tmp := p + ".tmp"
	if err := os.WriteFile(tmp, append(data, '\n'), mode); err != nil {
		return err
	}
	return os.Rename(tmp, p)
}

// Version comparison follows semantic versioning: 1.2.3 < 1.2.4-beta.1 <
// 1.2.4. Build metadata (+...) is ignored.

var versionRe = regexp.MustCompile(`^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-[0-9A-Za-z.-]+)?(\+[0-9A-Za-z.-]+)?$`)

func validVersion(v string) bool { return len(v) <= 64 && versionRe.MatchString(v) }

// Compare returns -1, 0 or 1 as a is older than, the same as or newer
// than b. Versions that do not parse are older than any that do.
func Compare(a, b string) int {
	pa, pb := versionRe.FindStringSubmatch(a), versionRe.FindStringSubmatch(b)
	switch {
	case pa == nil && pb == nil:
		return strings.Compare(a, b)
	case pa == nil:
		return -1
	case pb == nil:
		return 1
	}
	for i := 1; i <= 3; i++ {
		x, _ := strconv.Atoi(pa[i])
		y, _ := strconv.Atoi(pb[i])
		if x != y {
			if x < y {
				return -1
			}
			return 1
		}
	}
	ra, rb := strings.TrimPrefix(pa[4], "-"), strings.TrimPrefix(pb[4], "-")
	switch {
	case ra == rb:
		return 0
	case ra == "":
		return 1
	case rb == "":
		return -1
	}
	fa, fb := strings.Split(ra, "."), strings.Split(rb, ".")
	for i := 0; i < len(fa) && i < len(fb); i++ {
		if fa[i] == fb[i] {
			continue
		}
		na, ea := strconv.Atoi(fa[i])
		nb, eb := strconv.Atoi(fb[i])
		switch {
		case ea == nil && eb == nil:
			if na < nb {
				return -1
			}
			return 1
		case ea == nil:
			return -1
		case eb == nil:
			return 1
		}
		return strings.Compare(fa[i], fb[i])
	}
	switch {
	case len(fa) < len(fb):
		return -1
	case len(fa) > len(fb):
		return 1
	}
	return 0
}
