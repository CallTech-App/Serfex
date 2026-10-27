package monitor

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"time"

	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/site"
)

// Temporary resource limits.
//
// A limit caps one site for a while: CPU and memory through a systemd
// scope that holds the site's PHP-FPM workers (and the same caps on each
// of its Laravel worker and process units), PHP workers through the
// pool's pm.max_children. PHP-FPM forks new workers from its master, so
// every sample moves the site's new workers into the scope. When a limit
// ends (on time, or removed), the caps go back to unlimited and the pool
// to its usual size; workers already in the scope finish their requests
// there and are replaced as usual.
//
//	/etc/servik/limits/<domain>.json   a limit in force, root 0600
//	servik-limit-<domain>.scope        the site's limited PHP-FPM workers
const limitsDir = "/etc/servik/limits"

// MaxLimitSeconds is the longest a temporary limit may last.
const MaxLimitSeconds = 7 * 24 * 3600

// ValidationError reports a request that can never succeed as given.
type ValidationError struct{ msg string }

func (e *ValidationError) Error() string { return e.msg }

func invalid(format string, a ...any) error { return &ValidationError{fmt.Sprintf(format, a...)} }

// OperationError wraps a failure while changing the server.
type OperationError struct{ Err error }

func (e *OperationError) Error() string { return e.Err.Error() }
func (e *OperationError) Unwrap() error { return e.Err }

// LimitSpec asks for a temporary limit on a site. Zero leaves a resource
// unlimited; at least one must be set.
type LimitSpec struct {
	Domain string `json:"domain"`
	// CPU is in percent of one core: 100 is one core, 50 half of one.
	CPU int `json:"cpu"`
	// MemoryMB caps the memory of the site's processes.
	MemoryMB int `json:"memory_mb"`
	// PHPWorkers is the most PHP-FPM workers the site may run.
	PHPWorkers int `json:"php_workers"`
	// Seconds is how long the limit lasts (60 seconds to 7 days).
	Seconds int `json:"seconds"`
}

// Limit is a limit in force.
type Limit struct {
	Domain     string    `json:"domain"`
	CPU        int       `json:"cpu"`
	MemoryMB   int       `json:"memory_mb"`
	PHPWorkers int       `json:"php_workers"`
	Created    time.Time `json:"created_at"`
	Expires    time.Time `json:"expires_at"`
}

func (l *Limit) caps() bool { return l.CPU > 0 || l.MemoryMB > 0 }

func limitFile(domain string) string { return limitsDir + "/" + domain + ".json" }

func scopeName(domain string) string { return "servik-limit-" + escape(domain) + ".scope" }

// PHPWorkers returns a site's temporary PHP worker limit, or 0; site.Manager
// asks so that re-applying a site keeps the limit.
func (m *Monitor) PHPWorkers(domain string) int {
	m.mu.Lock()
	defer m.mu.Unlock()
	if l := m.activeLimit(domain); l != nil {
		return l.PHPWorkers
	}
	return 0
}

// activeLimit returns a copy of the site's limit while it lasts. m.mu is held.
func (m *Monitor) activeLimit(domain string) *Limit {
	l := m.limits[domain]
	if l == nil || !l.Expires.After(m.now()) {
		return nil
	}
	c := *l
	return &c
}

// Limits returns the limits in force.
func (m *Monitor) Limits() []Limit {
	m.mu.Lock()
	defer m.mu.Unlock()
	out := []Limit{}
	for d := range m.limits {
		if l := m.activeLimit(d); l != nil {
			out = append(out, *l)
		}
	}
	sort.Slice(out, func(i, j int) bool { return out[i].Domain < out[j].Domain })
	return out
}

func (m *Monitor) siteByDomain(domain string) (siteInfo, bool) {
	for _, si := range discoverSites(m.h) {
		if si.Domain == domain {
			return si, true
		}
	}
	return siteInfo{}, false
}

// ApplyLimit puts a limit on a site, replacing the one it has.
func (m *Monitor) ApplyLimit(ctx context.Context, spec LimitSpec) (Limit, error) {
	if err := site.ValidateDomain(spec.Domain); err != nil {
		return Limit{}, err
	}
	switch {
	case spec.CPU == 0 && spec.MemoryMB == 0 && spec.PHPWorkers == 0:
		return Limit{}, invalid("set at least one of cpu, memory_mb and php_workers")
	case spec.CPU != 0 && (spec.CPU < 5 || spec.CPU > 6400):
		return Limit{}, invalid("cpu must be between 5 and 6400 (percent of one core)")
	case spec.MemoryMB != 0 && (spec.MemoryMB < 64 || spec.MemoryMB > 1<<20):
		return Limit{}, invalid("memory_mb must be at least 64")
	case spec.PHPWorkers < 0 || spec.PHPWorkers > 1000:
		return Limit{}, invalid("php_workers must be between 1 and 1000")
	case spec.Seconds < 60 || spec.Seconds > MaxLimitSeconds:
		return Limit{}, invalid("seconds must be between 60 and %d", MaxLimitSeconds)
	}
	if _, ok := m.siteByDomain(spec.Domain); !ok {
		return Limit{}, invalid("site %s does not exist on this server", spec.Domain)
	}

	now := m.now().UTC().Truncate(time.Second)
	l := &Limit{Domain: spec.Domain, CPU: spec.CPU, MemoryMB: spec.MemoryMB, PHPWorkers: spec.PHPWorkers,
		Created: now, Expires: now.Add(time.Duration(spec.Seconds) * time.Second)}

	m.mu.Lock()
	old := m.limits[spec.Domain]
	m.mu.Unlock()

	if err := m.saveLimit(l); err != nil {
		return Limit{}, &OperationError{err}
	}
	m.mu.Lock()
	m.limits[spec.Domain] = l
	delete(m.limitUnits, spec.Domain)
	m.mu.Unlock()

	if err := m.pools.SetMaxChildren(ctx, spec.Domain, spec.PHPWorkers); err != nil {
		m.dropLimit(ctx, spec.Domain, old)
		return Limit{}, err
	}
	if l.caps() {
		m.capUnits(ctx, l)
		m.sweep(ctx, l, readProcs(m.h))
	} else if old != nil && old.caps() {
		m.uncap(ctx, spec.Domain)
	}
	return *l, nil
}

// RemoveLimit ends a site's limit now. It reports whether there was one.
func (m *Monitor) RemoveLimit(ctx context.Context, domain string) (bool, error) {
	if err := site.ValidateDomain(domain); err != nil {
		return false, err
	}
	m.mu.Lock()
	l := m.limits[domain]
	m.mu.Unlock()
	if l == nil {
		return false, nil
	}
	return true, m.end(ctx, l)
}

// end lifts a limit: unlimited caps, the usual pool size, no state file.
func (m *Monitor) end(ctx context.Context, l *Limit) error {
	var errs []error
	if l.caps() {
		m.uncap(ctx, l.Domain)
	}
	if l.PHPWorkers > 0 {
		if _, ok := m.siteByDomain(l.Domain); ok {
			if err := m.pools.SetMaxChildren(ctx, l.Domain, 0); err != nil {
				errs = append(errs, err)
			}
		}
	}
	m.mu.Lock()
	if m.limits[l.Domain] == l {
		delete(m.limits, l.Domain)
		delete(m.limitUnits, l.Domain)
	}
	m.mu.Unlock()
	if err := os.Remove(m.h.Path(limitFile(l.Domain))); err != nil && !errors.Is(err, os.ErrNotExist) {
		errs = append(errs, err)
	}
	if len(errs) > 0 {
		return &OperationError{errors.Join(errs...)}
	}
	return nil
}

// dropLimit puts back the limit that was in force before a failed change.
func (m *Monitor) dropLimit(ctx context.Context, domain string, old *Limit) {
	m.mu.Lock()
	if old != nil {
		m.limits[domain] = old
	} else {
		delete(m.limits, domain)
	}
	m.mu.Unlock()
	if old != nil {
		_ = m.saveLimit(old)
	} else {
		_ = os.Remove(m.h.Path(limitFile(domain)))
	}
}

func (m *Monitor) saveLimit(l *Limit) error {
	if err := os.MkdirAll(m.h.Path(limitsDir), 0o700); err != nil {
		return err
	}
	data, err := json.MarshalIndent(l, "", "  ")
	if err != nil {
		return err
	}
	tmp := m.h.Path(limitFile(l.Domain) + ".tmp")
	if err := os.WriteFile(tmp, append(data, '\n'), 0o600); err != nil {
		return err
	}
	return os.Rename(tmp, m.h.Path(limitFile(l.Domain)))
}

// loadLimits reads the limits that were in force when the agent stopped;
// the ones that ran out meanwhile end at the first sample.
func (m *Monitor) loadLimits(ctx context.Context) error {
	files, err := filepath.Glob(m.h.Path(limitsDir + "/*.json"))
	if err != nil {
		return err
	}
	for _, f := range files {
		data, err := os.ReadFile(f)
		if err != nil {
			return err
		}
		var l Limit
		if err := json.Unmarshal(data, &l); err != nil || site.ValidateDomain(l.Domain) != nil {
			m.log.Warn("ignoring unreadable limit", "file", f)
			continue
		}
		m.mu.Lock()
		m.limits[l.Domain] = &l
		m.mu.Unlock()
	}
	return nil
}

// enforceLimits ends limits that ran out and keeps the others in force.
func (m *Monitor) enforceLimits(ctx context.Context, now time.Time, procs []proc) {
	m.mu.Lock()
	var expired, active []*Limit
	for _, l := range m.limits {
		if !l.Expires.After(now) {
			expired = append(expired, l)
		} else if l.caps() {
			active = append(active, l)
		}
	}
	m.mu.Unlock()
	for _, l := range expired {
		if err := m.end(ctx, l); err != nil {
			m.log.Error("ending a resource limit", "site", l.Domain, "err", err)
		} else {
			m.log.Info("resource limit ended", "site", l.Domain)
		}
	}
	for _, l := range active {
		m.capUnits(ctx, l)
		m.sweep(ctx, l, procs)
	}
}

// properties are the systemd resource settings of a limit; nil lifts them.
func properties(l *Limit) []string {
	if l == nil {
		return []string{"CPUQuota=", "MemoryMax=infinity"}
	}
	cpu, mem := "CPUQuota=", "MemoryMax=infinity"
	if l.CPU > 0 {
		cpu = fmt.Sprintf("CPUQuota=%d%%", l.CPU)
	}
	if l.MemoryMB > 0 {
		mem = fmt.Sprintf("MemoryMax=%dM", l.MemoryMB)
	}
	return []string{cpu, mem}
}

// sweep moves the site's PHP-FPM workers that are not in its scope yet
// into it, creating the scope with the limit's caps if it does not exist.
func (m *Monitor) sweep(ctx context.Context, l *Limit, procs []proc) {
	scope := scopeName(l.Domain)
	var pids []string
	for _, p := range procs {
		if p.Unit != scope && p.Cmd == "php-fpm: pool "+l.Domain {
			pids = append(pids, itoa(p.PID))
		}
	}
	if len(pids) == 0 {
		return
	}
	if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"is-active", "--quiet", scope}}); err == nil {
		if err := m.attach(ctx, scope, pids); err != nil {
			m.log.Warn("moving PHP workers into their limit", "site", l.Domain, "err", err)
		}
		return
	}
	// Delegate=yes lets the agent move later workers in by itself.
	args := []string{"call", "org.freedesktop.systemd1", "/org/freedesktop/systemd1", "org.freedesktop.systemd1.Manager",
		"StartTransientUnit", "ssa(sv)a(sa(sv))", scope, "fail"}
	props := [][]string{
		{"Description", "s", "Servik resource limit of " + l.Domain},
		append([]string{"PIDs", "au", itoa(len(pids))}, pids...),
		{"Delegate", "b", "true"},
		{"CollectMode", "s", "inactive-or-failed"},
	}
	if l.CPU > 0 {
		props = append(props, []string{"CPUQuotaPerSecUSec", "t", itoa(l.CPU * 10000)})
	}
	if l.MemoryMB > 0 {
		props = append(props, []string{"MemoryMax", "t", fmt.Sprint(uint64(l.MemoryMB) << 20)})
	}
	args = append(args, itoa(len(props)))
	for _, p := range props {
		args = append(args, p...)
	}
	args = append(args, "0")
	if _, err := m.h.Run(ctx, host.Command{Name: "busctl", Args: args}); err != nil {
		m.log.Warn("creating the limit scope", "site", l.Domain, "err", err)
		return
	}
	// The scope may have existed but stopped: make sure its caps are this limit's.
	_, _ = m.h.Run(ctx, host.Command{Name: "systemctl", Args: append([]string{"set-property", "--runtime", scope}, properties(l)...)})
}

// attach writes the processes into the scope's cgroup, which Delegate=yes
// hands to the agent.
func (m *Monitor) attach(ctx context.Context, scope string, pids []string) error {
	out, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"show", "--property=ControlGroup", "--value", scope}})
	if err != nil {
		return err
	}
	cg := strings.TrimSpace(out)
	if cg == "" || strings.Contains(cg, "..") {
		return fmt.Errorf("%s has no control group", scope)
	}
	file := m.h.Path("/sys/fs/cgroup" + cg + "/cgroup.procs")
	var errs []error
	for _, pid := range pids {
		// One write per process: a process that exited meanwhile fails
		// alone.
		f, err := os.OpenFile(file, os.O_WRONLY, 0)
		if err != nil {
			return err
		}
		if _, err := f.WriteString(pid); err != nil && !errors.Is(err, os.ErrNotExist) {
			errs = append(errs, fmt.Errorf("pid %s: %w", pid, err))
		}
		f.Close()
	}
	return errors.Join(errs...)
}

// capUnits puts the limit's caps on the site's Laravel units that do not
// have them yet.
func (m *Monitor) capUnits(ctx context.Context, l *Limit) {
	units := m.laravelUnits(ctx, l.Domain)
	m.mu.Lock()
	done := m.limitUnits[l.Domain]
	if done == nil {
		done = map[string]bool{}
		m.limitUnits[l.Domain] = done
	}
	var todo []string
	for _, u := range units {
		if !done[u] {
			todo = append(todo, u)
		}
	}
	m.mu.Unlock()
	for _, u := range todo {
		if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: append([]string{"set-property", "--runtime", u}, properties(l)...)}); err != nil {
			m.log.Warn("limiting a Laravel unit", "unit", u, "err", err)
			continue
		}
		m.mu.Lock()
		done[u] = true
		m.mu.Unlock()
	}
}

// uncap lifts the caps from the scope and the site's Laravel units.
func (m *Monitor) uncap(ctx context.Context, domain string) {
	units := append(m.laravelUnits(ctx, domain), scopeName(domain))
	for _, u := range units {
		if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"is-active", "--quiet", u}}); err != nil {
			continue
		}
		if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: append([]string{"set-property", "--runtime", u}, properties(nil)...)}); err != nil {
			m.log.Warn("lifting a limit", "unit", u, "err", err)
		}
	}
}

// laravelUnits lists the site's queue worker, scheduler and process units
// systemd has loaded.
func (m *Monitor) laravelUnits(ctx context.Context, domain string) []string {
	out, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"list-units", "--all", "--plain", "--no-legend", "--full",
		"servik-queue-" + domain + "_*", "servik-proc-" + domain + "_*", "servik-schedule-" + domain + ".service"}})
	if err != nil {
		return nil
	}
	var units []string
	for _, line := range strings.Split(out, "\n") {
		f := strings.Fields(line)
		if len(f) > 0 && strings.HasSuffix(f[0], ".service") && unitSite(f[0]) == domain {
			units = append(units, f[0])
		}
	}
	return units
}

// RestartPool stops the site's PHP-FPM workers, the way to unstick a pool
// whose workers hang; PHP-FPM starts new ones with the next request. Other
// sites are not touched. Workers get restartWait to finish, then are
// killed. It returns how many workers were stopped.
func (m *Monitor) RestartPool(ctx context.Context, domain string) (int, error) {
	if err := site.ValidateDomain(domain); err != nil {
		return 0, err
	}
	if _, ok := m.siteByDomain(domain); !ok {
		return 0, invalid("site %s does not exist on this server", domain)
	}
	// Only the workers running now: PHP-FPM may start new ones meanwhile.
	alive := func(pids []string) []string {
		var left []string
		for _, pid := range pids {
			b, err := os.ReadFile(m.h.Path("/proc/" + pid + "/cmdline"))
			if err == nil && strings.TrimSpace(strings.ReplaceAll(string(b), "\x00", " ")) == "php-fpm: pool "+domain {
				left = append(left, pid)
			}
		}
		return left
	}
	var pids []string
	for _, p := range readProcs(m.h) {
		if p.Cmd == "php-fpm: pool "+domain {
			pids = append(pids, itoa(p.PID))
		}
	}
	if len(pids) == 0 {
		return 0, nil
	}
	if _, err := m.h.Run(ctx, host.Command{Name: "kill", Args: append([]string{"-TERM"}, pids...)}); err != nil {
		// Workers that exited meanwhile make kill fail; the rest got it.
		m.log.Info("stopping PHP workers", "site", domain, "err", err)
	}
	deadline := time.Now().Add(m.restartWait)
	for time.Now().Before(deadline) {
		if len(alive(pids)) == 0 {
			return len(pids), nil
		}
		select {
		case <-ctx.Done():
			return len(pids), ctx.Err()
		case <-time.After(100 * time.Millisecond):
		}
	}
	if left := alive(pids); len(left) > 0 {
		_, _ = m.h.Run(ctx, host.Command{Name: "kill", Args: append([]string{"-KILL"}, left...)})
	}
	return len(pids), nil
}
