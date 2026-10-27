package laravel

import (
	"bytes"
	"context"
	"errors"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"text/template"

	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/txn"
)

// Queue workers run as systemd services, one template unit per worker
// group (servik-queue-<domain>_<name>@.service) and one instance per
// process, so a crashed worker restarts on its own and workers start with
// the server. No Composer package is needed in the application.

// Worker is a group of identical queue:work processes.
type Worker struct {
	Name string `json:"name"`
	// Connection is a connection from config/queue.php; empty uses the
	// application's default (QUEUE_CONNECTION).
	Connection string `json:"connection"`
	// Queue lists the queues to work, by priority, such as "high,default".
	Queue     string `json:"queue"`
	Processes int    `json:"processes"`
	Timeout   int    `json:"timeout"`
	Tries     int    `json:"tries"`
	Backoff   int    `json:"backoff"`
	Sleep     int    `json:"sleep"`
	MaxJobs   int    `json:"max_jobs"`
	MaxTime   int    `json:"max_time"`
	Memory    int    `json:"memory"`
}

// QueueSpec is every worker group an application should have.
type QueueSpec struct {
	App
	Workers []Worker `json:"workers"`
}

// WorkerState is one running (or not) worker process.
type WorkerState struct {
	Worker string `json:"worker"`
	Unit   string `json:"unit"`
	State  string `json:"state"`
}

var (
	connRe  = regexp.MustCompile(`^[A-Za-z0-9_-]{0,32}$`)
	queueRe = regexp.MustCompile(`^[A-Za-z0-9_.:-]+(,[A-Za-z0-9_.:-]+)*$`)
)

const maxProcesses = 16

func (w *Worker) normalise() error {
	if !workerRe.MatchString(w.Name) {
		return invalid("worker name %q must be lowercase letters, digits or -", w.Name)
	}
	if !connRe.MatchString(w.Connection) {
		return invalid("connection %q is not a valid connection name", w.Connection)
	}
	if w.Queue == "" {
		w.Queue = "default"
	}
	if len(w.Queue) > 200 || !queueRe.MatchString(w.Queue) {
		return invalid("queue %q must be queue names separated by commas", w.Queue)
	}
	if w.Processes < 0 || w.Processes > maxProcesses {
		return invalid("processes must be between 0 and %d", maxProcesses)
	}
	def := func(v *int, d, lo, hi int, name string) error {
		if *v == 0 {
			*v = d
		}
		if *v < lo || *v > hi {
			return invalid("%s must be between %d and %d", name, lo, hi)
		}
		return nil
	}
	return errors.Join(
		def(&w.Timeout, 60, 1, 86400, "timeout"),
		def(&w.Tries, 1, 1, 100, "tries"),
		def(&w.Sleep, 3, 1, 60, "sleep"),
		def(&w.Memory, 128, 32, 8192, "memory"),
		nonNegative(w.Backoff, "backoff"),
		nonNegative(w.MaxJobs, "max_jobs"),
		nonNegative(w.MaxTime, "max_time"),
	)
}

func nonNegative(v int, name string) error {
	if v < 0 {
		return invalid("%s must not be negative", name)
	}
	return nil
}

var workerTmpl = template.Must(template.New("worker").Parse(`# {{.Marker}}. Manual changes are overwritten.
[Unit]
Description=Laravel queue worker %i of {{.Domain}} ({{.Name}})
After=network.target

[Service]
User={{.User}}
Group={{.User}}
WorkingDirectory={{.Dir}}
ExecStart={{.PHP}} artisan queue:work{{if .Connection}} {{.Connection}}{{end}} --queue={{.Queue}} --sleep={{.Sleep}} --tries={{.Tries}} --timeout={{.Timeout}}{{if .Backoff}} --backoff={{.Backoff}}{{end}}{{if .MaxJobs}} --max-jobs={{.MaxJobs}}{{end}}{{if .MaxTime}} --max-time={{.MaxTime}}{{end}} --memory={{.Memory}}
Environment=HOME={{.Home}}
Restart=always
RestartSec=5
# SIGTERM lets the worker finish its job first.
KillSignal=SIGTERM
TimeoutStopSec={{.Stop}}
StandardOutput=append:{{.Log}}
StandardError=inherit
NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=full

[Install]
WantedBy=multi-user.target
`))

func workerUnit(domain, name string) string { return unitPrefix(domain) + name + "@" }

func queueLog(domain string) string { return logDir + "/" + domain + "/queue.log" }

// ApplyQueues makes the application run exactly these workers.
func (m *Manager) ApplyQueues(ctx context.Context, spec QueueSpec) (states []WorkerState, err error) {
	if err := spec.App.validate(); err != nil {
		return nil, err
	}
	seen := map[string]bool{}
	for i := range spec.Workers {
		if err := spec.Workers[i].normalise(); err != nil {
			return nil, err
		}
		if seen[spec.Workers[i].Name] {
			return nil, invalid("worker %q is listed twice", spec.Workers[i].Name)
		}
		seen[spec.Workers[i].Name] = true
	}
	if len(spec.Workers) > 0 {
		if err := m.requireApp(spec.App); err != nil {
			return nil, err
		}
	}
	unlock := m.lock(spec.Domain)
	defer unlock()

	t := txn.New(m.h)
	defer func() {
		if err != nil {
			_ = t.Rollback(context.WithoutCancel(ctx))
			_, _ = m.h.Run(context.WithoutCancel(ctx), host.Command{Name: "systemctl", Args: []string{"daemon-reload"}})
			err = &OperationError{err}
		}
	}()
	if err := m.logDirs(t, spec.Domain); err != nil {
		return nil, err
	}

	want := map[string]bool{} // instance units that should run
	var changed []string      // worker groups whose unit file changed
	for _, w := range spec.Workers {
		data := struct {
			Worker
			Marker, Domain, User, Home, Dir, PHP, Log string
			Stop                                      int
		}{w, txn.Marker, spec.Domain, spec.User, "/home/" + spec.User, spec.dir(), phpBin(spec.PHPVersion), queueLog(spec.Domain), w.Timeout + 30}
		var b bytes.Buffer
		if err := workerTmpl.Execute(&b, data); err != nil {
			return nil, err
		}
		before := t.Changed
		t.Changed = false
		if err := t.WriteFile(unitDir+"/"+workerUnit(spec.Domain, w.Name)+".service", b.Bytes()); err != nil {
			return nil, err
		}
		if t.Changed {
			changed = append(changed, w.Name)
		}
		t.Changed = t.Changed || before
		for i := 1; i <= w.Processes; i++ {
			want[workerUnit(spec.Domain, w.Name)+strconv.Itoa(i)+".service"] = true
		}
	}
	// Worker groups that are gone.
	files, _ := filepath.Glob(m.h.Path(unitDir + "/" + unitPrefix(spec.Domain) + "*@.service"))
	for _, f := range files {
		name := strings.TrimSuffix(strings.TrimPrefix(filepath.Base(f), unitPrefix(spec.Domain)), "@.service")
		if !seen[name] {
			if err := t.RemoveFile(unitDir + "/" + filepath.Base(f)); err != nil {
				return nil, err
			}
		}
	}

	running := m.enabledWorkers(spec.Domain)
	var stop, start, restart []string
	for _, u := range running {
		if !want[u] {
			stop = append(stop, u)
		}
	}
	for u := range want {
		if !contains(running, u) {
			start = append(start, u)
		} else if contains(changed, workerName(spec.Domain, u)) {
			restart = append(restart, u)
		}
	}
	sort.Strings(start)
	sort.Strings(restart)

	if len(stop) > 0 {
		if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: append([]string{"disable", "--now"}, stop...)}); err != nil {
			return nil, err
		}
	}
	if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"daemon-reload"}}); err != nil {
		return nil, err
	}
	if len(start) > 0 {
		if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: append([]string{"enable", "--now"}, start...)}); err != nil {
			return nil, err
		}
	}
	if len(restart) > 0 {
		if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: append([]string{"restart"}, restart...)}); err != nil {
			return nil, err
		}
	}
	return m.workerStates(ctx, spec.Domain), nil
}

// enabledWorkers lists the worker instances enabled at boot, which are
// the ones Servik started.
func (m *Manager) enabledWorkers(domain string) []string {
	links, _ := filepath.Glob(m.h.Path(unitDir + "/multi-user.target.wants/" + unitPrefix(domain) + "*@*.service"))
	out := []string{}
	for _, l := range links {
		out = append(out, filepath.Base(l))
	}
	sort.Strings(out)
	return out
}

func (m *Manager) hasWorkers(domain string) bool { return len(m.enabledWorkers(domain)) > 0 }

// workerName returns the worker group of an instance unit.
func workerName(domain, unit string) string {
	name, _, _ := strings.Cut(strings.TrimPrefix(unit, unitPrefix(domain)), "@")
	return name
}

// workerStates asks systemd how each enabled worker is doing.
func (m *Manager) workerStates(ctx context.Context, domain string) []WorkerState {
	units := m.enabledWorkers(domain)
	out := []WorkerState{}
	if len(units) == 0 {
		return out
	}
	// is-active exits non-zero when a unit is not active; the output still
	// has one line per unit.
	res, _ := m.h.Run(ctx, host.Command{Name: "systemctl", Args: append([]string{"is-active"}, units...)})
	lines := strings.Split(strings.TrimSpace(res), "\n")
	for i, u := range units {
		state := "unknown"
		if i < len(lines) && strings.TrimSpace(lines[i]) != "" {
			state = strings.TrimSpace(lines[i])
		}
		out = append(out, WorkerState{Worker: workerName(domain, u), Unit: u, State: state})
	}
	return out
}

// restartWorkers restarts the running workers of a site, so they load the
// live release and the current .env. Each finishes its job first.
func (m *Manager) restartWorkers(ctx context.Context, domain string) error {
	units := m.enabledWorkers(domain)
	if len(units) == 0 {
		return nil
	}
	_, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: append([]string{"try-restart"}, units...)})
	return err
}

// RestartQueues restarts every worker of a site.
func (m *Manager) RestartQueues(ctx context.Context, t Target) ([]WorkerState, error) {
	if err := t.validate(); err != nil {
		return nil, err
	}
	if err := m.restartWorkers(ctx, t.Domain); err != nil {
		return nil, &OperationError{err}
	}
	return m.workerStates(ctx, t.Domain), nil
}

// logDirs creates the root-owned log directory systemd appends to, and
// the logrotate rule that keeps it small.
func (m *Manager) logDirs(t *txn.Tx, domain string) error {
	for _, d := range []string{"/var/log/servik", logDir, logDir + "/" + domain} {
		if err := t.RootDir(d, 0o750, 0, 0); err != nil {
			return err
		}
	}
	if !m.isDir("/etc/logrotate.d") {
		return nil
	}
	return t.WriteFile("/etc/logrotate.d/servik-laravel", []byte("# "+txn.Marker+". Manual changes are overwritten.\n"+
		logDir+"/*/*.log {\n    weekly\n    rotate 4\n    compress\n    missingok\n    notifempty\n    copytruncate\n}\n"))
}

func contains(list []string, s string) bool {
	for _, v := range list {
		if v == s {
			return true
		}
	}
	return false
}

// removeUnits stops and deletes every unit of a site.
func (m *Manager) removeUnits(ctx context.Context, domain string) error {
	t := txn.New(m.h)
	units := append(m.enabledWorkers(domain), m.enabledProcesses(domain)...)
	if m.exists(unitDir + "/" + scheduleUnit(domain) + ".timer") {
		units = append(units, scheduleUnit(domain)+".timer")
	}
	if len(units) > 0 {
		if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: append([]string{"disable", "--now"}, units...)}); err != nil {
			return err
		}
	}
	files, _ := filepath.Glob(m.h.Path(unitDir + "/" + unitPrefix(domain) + "*@.service"))
	procs, _ := filepath.Glob(m.h.Path(unitDir + "/" + procPrefix(domain) + "*.service"))
	for _, f := range append(files, procs...) {
		if err := t.RemoveFile(unitDir + "/" + filepath.Base(f)); err != nil {
			return err
		}
	}
	for _, f := range []string{scheduleUnit(domain) + ".service", scheduleUnit(domain) + ".timer"} {
		if err := t.RemoveFile(unitDir + "/" + f); err != nil {
			return err
		}
	}
	if t.Changed {
		if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"daemon-reload"}}); err != nil {
			return err
		}
	}
	return nil
}
