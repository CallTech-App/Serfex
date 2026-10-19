// Package service shows and controls the server's system services (the
// panel's Hizmetler page): status, uptime and boot setting from systemd,
// start, stop, restart and reload with the service's own configuration
// test first, recent journal lines, and the few settings worth changing
// from the panel (Nginx limits, the database server's connections and
// buffers, Postfix's message size), applied with a rollback.
//
// Only services Servik knows are listed and controlled. The panel's own
// services (servik-web, servik-php, its PostgreSQL cluster and workers),
// SSH and the agent itself can be restarted but never stopped, so the
// panel cannot lock itself or the administrator out.
package service

import (
	"context"
	"encoding/json"
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
)

// ValidationError reports a request that can never succeed as given.
type ValidationError struct{ msg string }

func (e *ValidationError) Error() string { return e.msg }

func invalid(format string, a ...any) error { return &ValidationError{fmt.Sprintf(format, a...)} }

// OperationError reports a valid request that failed on the server.
type OperationError struct{ Err error }

func (e *OperationError) Error() string { return e.Err.Error() }
func (e *OperationError) Unwrap() error { return e.Err }

// Groups of services.
const (
	// GroupHosting are services customers' sites, mail and databases use.
	GroupHosting = "hosting"
	// GroupSystem are the operating system's services.
	GroupSystem = "system"
	// GroupServik are the panel's own services.
	GroupServik = "servik"
)

// def describes a service Servik knows.
type def struct {
	unit  string
	name  string
	group string
	// marker is a file that exists while it is installed (dry runs).
	marker string
	// test checks the configuration before a restart or reload.
	test []string
	// reload is false for services that cannot reload their settings.
	reload bool
	// protect says why the service may not be stopped.
	protect string
	// settings names the settings the panel can change (Settings).
	settings string
	// logs are the units whose journal is the service's log (default:
	// the unit itself).
	logs []string
}

// PHPVersions are the PHP versions whose PHP-FPM is looked for.
var PHPVersions = []string{"8.1", "8.2", "8.3", "8.4", "8.5"}

const (
	protectPanel = "The panel needs it to run"
	protectSSH   = "Stopping SSH would lock you out of the server"
	protectAgent = "The panel manages the server through it"
)

// catalog lists every service Servik knows, the PostgreSQL clusters and
// PHP versions found on the server included.
func (m *Manager) catalog() []def {
	defs := []def{
		{unit: "nginx", name: "Nginx", group: GroupHosting, marker: "/usr/sbin/nginx", test: []string{"nginx", "-t"}, reload: true, settings: "nginx"},
		{unit: "apache2", name: "Apache", group: GroupHosting, marker: "/usr/sbin/apache2", test: []string{"apache2ctl", "configtest"}, reload: true},
	}
	for _, v := range PHPVersions {
		defs = append(defs, def{unit: "php" + v + "-fpm", name: "PHP " + v + " (PHP-FPM)", group: GroupHosting, marker: "/usr/sbin/php-fpm" + v, test: []string{"php-fpm" + v, "-t"}, reload: true})
	}
	defs = append(defs,
		def{unit: "mysql", name: "MySQL", group: GroupHosting, marker: "/etc/mysql/mysql.conf.d/zz-servik.cnf", settings: "database"},
		def{unit: "mariadb", name: "MariaDB", group: GroupHosting, marker: "/etc/mysql/mariadb.conf.d/90-servik.cnf", settings: "database"},
	)
	for _, c := range m.clusters() {
		if c[1] == "main" {
			defs = append(defs, def{unit: "postgresql@" + c[0] + "-main", name: "PostgreSQL " + c[0], group: GroupHosting, reload: true})
		}
	}
	defs = append(defs,
		def{unit: "pdns@servik", name: "PowerDNS (DNS sunucusu)", group: GroupHosting, marker: "/etc/powerdns/pdns-servik.conf"},
		def{unit: "postfix", name: "Postfix (posta gönderme ve alma)", group: GroupHosting, marker: "/usr/sbin/postfix", test: []string{"postfix", "check"}, reload: true, settings: "postfix", logs: []string{"postfix", "postfix@-"}},
		def{unit: "dovecot", name: "Dovecot (IMAP ve POP3)", group: GroupHosting, marker: "/usr/sbin/dovecot", test: []string{"doveconf", "-n"}, reload: true},
		def{unit: "rspamd", name: "Rspamd (spam filtresi)", group: GroupHosting, marker: "/usr/bin/rspamd", test: []string{"rspamadm", "configtest"}, reload: true},
		def{unit: "redis-server@servik-mail", name: "Redis (posta)", group: GroupHosting, marker: "/etc/redis/servik-mail.conf"},
		def{unit: "memcached", name: "Memcached", group: GroupHosting, marker: "/usr/bin/memcached"},
		def{unit: "supervisor", name: "Supervisor", group: GroupHosting, marker: "/usr/bin/supervisord", reload: true},
		def{unit: "clamav-daemon", name: "ClamAV (virüs tarayıcı)", group: GroupHosting, marker: "/usr/sbin/clamd", reload: true},
		def{unit: "clamav-freshclam", name: "ClamAV imza güncelleyici", group: GroupHosting, marker: "/usr/bin/freshclam"},

		def{unit: "ssh", name: "SSH", group: GroupSystem, marker: "/usr/sbin/sshd", test: []string{"sshd", "-t"}, reload: true, protect: protectSSH},
		def{unit: "fail2ban", name: "Fail2ban", group: GroupSystem, marker: "/usr/bin/fail2ban-server", test: []string{"fail2ban-client", "--test"}, reload: true},
		def{unit: "cron", name: "Cron (zamanlanmış görevler)", group: GroupSystem, marker: "/usr/sbin/cron"},

		def{unit: "servik-agent", name: "Servik Agent", group: GroupServik, protect: protectAgent},
		def{unit: "servik-web", name: "Panel web sunucusu (Nginx)", group: GroupServik, marker: "/etc/servik/web/nginx.conf", test: []string{"nginx", "-t", "-q", "-c", "/etc/servik/web/nginx.conf"}, reload: true, protect: protectPanel},
		def{unit: "servik-php", name: "Panel PHP", group: GroupServik, marker: "/etc/servik/web/php-fpm.conf", reload: true, protect: protectPanel},
	)
	for _, c := range m.clusters() {
		if c[1] == "servik" {
			defs = append(defs, def{unit: "postgresql@" + c[0] + "-servik", name: "Panel veritabanı (PostgreSQL " + c[0] + ")", group: GroupServik, reload: true, protect: protectPanel})
		}
	}
	defs = append(defs,
		def{unit: "servik-panel-queue", name: "Panel iş kuyruğu", group: GroupServik, marker: "/etc/systemd/system/servik-panel-queue.service", protect: protectPanel},
		def{unit: "servik-panel-scheduler", name: "Panel zamanlayıcısı", group: GroupServik, marker: "/etc/systemd/system/servik-panel-scheduler.service", protect: protectPanel},
		def{unit: "servik-panel-backups", name: "Panel yedekleme kuyruğu", group: GroupServik, marker: "/etc/systemd/system/servik-panel-backups.service", protect: protectPanel},
	)
	return defs
}

var clusterRe = regexp.MustCompile(`^\d+$`)

// clusters are the PostgreSQL clusters configured on the server, as
// [version, name].
func (m *Manager) clusters() [][2]string {
	dirs, _ := filepath.Glob(m.h.Path("/etc/postgresql/*/*"))
	var out [][2]string
	for _, d := range dirs {
		v, name := filepath.Base(filepath.Dir(d)), filepath.Base(d)
		if clusterRe.MatchString(v) && (name == "main" || name == "servik") {
			out = append(out, [2]string{v, name})
		}
	}
	if m.sim && len(out) == 0 {
		// A dry run stands in for an installed server, which has the
		// panel's own cluster.
		out = append(out, [2]string{"16", "servik"})
	}
	sort.Slice(out, func(i, j int) bool { return out[i][0]+out[i][1] < out[j][0]+out[j][1] })
	return out
}

// Manager reads and controls services.
type Manager struct {
	h   host.Host
	sim bool
	mu  sync.Mutex
	now func() time.Time

	// Usage, when set, returns the CPU (percent of one core) and memory
	// the monitor measured for each unit (package monitor).
	Usage func() map[string]Usage
	// SelfRestart restarts the agent shortly after the answer was sent.
	SelfRestart func(ctx context.Context) error
}

// Usage is what a service uses.
type Usage struct {
	CPU    float64 `json:"cpu"`
	Memory uint64  `json:"memory"`
}

// NewManager returns a Manager. On a simulator, states are kept in a file
// under the simulated root.
func NewManager(h host.Host) *Manager {
	_, sim := h.(*host.Simulator)
	return &Manager{h: h, sim: sim, now: time.Now}
}

// Status is one service.
type Status struct {
	Unit  string `json:"unit"`
	Name  string `json:"name"`
	Group string `json:"group"`
	// State is systemd's active state: active, reloading, inactive,
	// failed, activating or deactivating.
	State    string `json:"state"`
	SubState string `json:"sub_state"`
	// Enabled is the boot setting: enabled, disabled, static, masked...
	Enabled string `json:"enabled"`
	// Since is when the service entered its state.
	Since    *time.Time `json:"since,omitempty"`
	PID      int        `json:"pid,omitempty"`
	Restarts int        `json:"restarts"`
	// CanReload is true when its settings can be reloaded without a
	// restart.
	CanReload bool `json:"can_reload"`
	// Protected says why it cannot be stopped.
	Protected string `json:"protected,omitempty"`
	// Settings names the settings the panel can change.
	Settings string  `json:"settings,omitempty"`
	CPU      float64 `json:"cpu"`
	Memory   uint64  `json:"memory"`
}

// List reports every known service that is installed.
func (m *Manager) List(ctx context.Context) ([]Status, error) {
	defs := m.catalog()
	var out []Status
	var err error
	if m.sim {
		out = m.simList(defs)
	} else {
		out, err = m.show(ctx, defs)
		if err != nil {
			return nil, &OperationError{err}
		}
	}
	if m.Usage != nil {
		usage := m.Usage()
		for i := range out {
			if u, ok := usage[out[i].Unit]; ok && out[i].State != "inactive" && out[i].State != "failed" {
				out[i].CPU, out[i].Memory = u.CPU, u.Memory
			}
		}
	}
	return out, nil
}

var showProps = "Id,LoadState,ActiveState,SubState,UnitFileState,ActiveEnterTimestamp,InactiveEnterTimestamp,MainPID,NRestarts,MemoryCurrent"

// show asks systemd about the units and leaves out those not installed.
func (m *Manager) show(ctx context.Context, defs []def) ([]Status, error) {
	args := []string{"show", "--timestamp=unix", "-p", showProps}
	for _, d := range defs {
		args = append(args, d.unit+".service")
	}
	out, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: args})
	if err != nil {
		return nil, err
	}
	blocks := strings.Split(strings.TrimSpace(out), "\n\n")
	if len(blocks) != len(defs) {
		return nil, fmt.Errorf("systemctl show returned %d units for %d", len(blocks), len(defs))
	}
	var list []Status
	for i, b := range blocks {
		p := map[string]string{}
		for _, line := range strings.Split(b, "\n") {
			if k, v, ok := strings.Cut(line, "="); ok {
				p[k] = v
			}
		}
		if p["LoadState"] != "loaded" {
			continue
		}
		s := m.status(defs[i])
		s.State, s.SubState, s.Enabled = p["ActiveState"], p["SubState"], p["UnitFileState"]
		s.PID, _ = strconv.Atoi(p["MainPID"])
		s.Restarts, _ = strconv.Atoi(p["NRestarts"])
		stamp := p["ActiveEnterTimestamp"]
		if s.State == "inactive" || s.State == "failed" {
			stamp = p["InactiveEnterTimestamp"]
		}
		if t, ok := unixStamp(stamp); ok {
			s.Since = &t
		}
		if mem, err := strconv.ParseUint(p["MemoryCurrent"], 10, 64); err == nil {
			s.Memory = mem
		}
		list = append(list, s)
	}
	return list, nil
}

func (m *Manager) status(d def) Status {
	return Status{Unit: d.unit, Name: d.name, Group: d.group, CanReload: d.reload, Protected: d.protect, Settings: d.settings}
}

func unixStamp(v string) (time.Time, bool) {
	sec, err := strconv.ParseInt(strings.TrimPrefix(v, "@"), 10, 64)
	if err != nil || sec <= 0 {
		return time.Time{}, false
	}
	return time.Unix(sec, 0).UTC(), true
}

// find returns the known, installed service of a unit name.
func (m *Manager) find(ctx context.Context, unit string) (def, Status, error) {
	if !unitRe.MatchString(unit) {
		return def{}, Status{}, invalid("unit %q is not valid", unit)
	}
	list, err := m.List(ctx)
	if err != nil {
		return def{}, Status{}, err
	}
	for _, d := range m.catalog() {
		if d.unit != unit {
			continue
		}
		for _, s := range list {
			if s.Unit == unit {
				return d, s, nil
			}
		}
	}
	return def{}, Status{}, invalid("%s is not a service Servik manages on this server", unit)
}

var unitRe = regexp.MustCompile(`^[a-z0-9][a-z0-9@.-]{0,63}$`)

// ActionSpec asks for something to be done with a service.
type ActionSpec struct {
	Unit string `json:"unit"`
	// Action is start, stop, restart, reload, enable or disable.
	Action string `json:"action"`
}

// Do runs an action and returns the service's new status. A restart or
// reload first tests the configuration; a configuration the service would
// refuse leaves it running as it is.
func (m *Manager) Do(ctx context.Context, spec ActionSpec) (Status, error) {
	switch spec.Action {
	case "start", "stop", "restart", "reload", "enable", "disable":
	default:
		return Status{}, invalid("action must be start, stop, restart, reload, enable or disable")
	}
	d, _, err := m.find(ctx, spec.Unit)
	if err != nil {
		return Status{}, err
	}
	if (spec.Action == "stop" || spec.Action == "disable") && d.protect != "" {
		return Status{}, invalid("%s cannot be stopped: %s", d.name, d.protect)
	}
	if spec.Action == "reload" && !d.reload {
		return Status{}, invalid("%s cannot reload its settings; restart it", d.name)
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	if (spec.Action == "restart" || spec.Action == "reload") && len(d.test) > 0 {
		if out, err := m.h.Run(ctx, host.Command{Name: d.test[0], Args: d.test[1:]}); err != nil {
			return Status{}, invalid("configuration test failed, %s was left as it is: %s", d.name, tail(out, err))
		}
	}
	if d.unit == "servik-agent" {
		if spec.Action != "restart" {
			return Status{}, invalid("the agent can only be restarted")
		}
		if m.SelfRestart != nil {
			if err := m.SelfRestart(ctx); err != nil {
				return Status{}, &OperationError{err}
			}
		}
		_, s, err := m.find(ctx, d.unit)
		return s, err
	}
	if m.sim {
		m.simDo(d, spec.Action)
	} else if out, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{spec.Action, d.unit + ".service"}}); err != nil {
		logs, _ := m.Logs(context.WithoutCancel(ctx), LogsSpec{Unit: d.unit, Lines: 5})
		msg := tail(out, err)
		if len(logs.Lines) > 0 {
			msg += " | " + strings.Join(logs.Lines, " | ")
		}
		return Status{}, &OperationError{fmt.Errorf("systemctl %s %s failed: %s", spec.Action, d.unit, msg)}
	}
	if spec.Action == "start" || spec.Action == "restart" {
		m.settle(ctx, d.unit)
	}
	_, s, err := m.find(ctx, d.unit)
	return s, err
}

// settle waits briefly for a starting service to finish starting.
func (m *Manager) settle(ctx context.Context, unit string) {
	if m.sim {
		return
	}
	for i := 0; i < 10; i++ {
		out, _ := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"is-active", unit + ".service"}})
		if s := strings.TrimSpace(out); s != "activating" && s != "reloading" {
			return
		}
		time.Sleep(500 * time.Millisecond)
	}
}

func tail(out string, err error) string {
	out = strings.TrimSpace(out)
	if out == "" {
		return err.Error()
	}
	lines := strings.Split(out, "\n")
	if len(lines) > 4 {
		lines = lines[len(lines)-4:]
	}
	return strings.Join(lines, " ")
}

// LogsSpec asks for a service's newest journal lines.
type LogsSpec struct {
	Unit  string `json:"unit"`
	Lines int    `json:"lines"`
}

// Logs are journal lines, oldest first.
type Logs struct {
	Lines []string `json:"lines"`
}

// Logs reads the newest journal lines of a service.
func (m *Manager) Logs(ctx context.Context, spec LogsSpec) (Logs, error) {
	if !unitRe.MatchString(spec.Unit) {
		return Logs{}, invalid("unit %q is not valid", spec.Unit)
	}
	var units []string
	for _, d := range m.catalog() {
		if d.unit == spec.Unit {
			units = d.logs
			if units == nil {
				units = []string{d.unit}
			}
		}
	}
	if units == nil {
		return Logs{}, invalid("%s is not a service Servik manages", spec.Unit)
	}
	n := spec.Lines
	if n <= 0 {
		n = 50
	}
	n = min(n, 500)
	if m.sim {
		return m.simLogs(spec.Unit, n), nil
	}
	args := []string{"--no-pager", "-q", "-o", "short-iso", "-n", strconv.Itoa(n)}
	for _, u := range units {
		args = append(args, "-u", u+".service")
	}
	out, err := m.h.Run(ctx, host.Command{Name: "journalctl", Args: args})
	if err != nil && strings.TrimSpace(out) == "" {
		return Logs{}, &OperationError{err}
	}
	lines := []string{}
	for _, l := range strings.Split(strings.TrimRight(out, "\n"), "\n") {
		if l != "" {
			if len(l) > 2000 {
				l = l[:2000] + "…"
			}
			lines = append(lines, l)
		}
	}
	return Logs{Lines: lines}, nil
}

// simState is a dry run's service states.
type simState struct {
	Units map[string]simUnit `json:"units"`
}

type simUnit struct {
	State   string    `json:"state"`
	Enabled string    `json:"enabled"`
	Since   time.Time `json:"since"`
	Log     []string  `json:"log"`
}

const simFile = "/var/lib/servik/services/simulated.json"

func (m *Manager) simLoad() simState {
	st := simState{Units: map[string]simUnit{}}
	if b, err := os.ReadFile(m.h.Path(simFile)); err == nil {
		_ = json.Unmarshal(b, &st)
	}
	if st.Units == nil {
		st.Units = map[string]simUnit{}
	}
	return st
}

func (m *Manager) simSave(st simState) {
	_ = os.MkdirAll(filepath.Dir(m.h.Path(simFile)), 0o755)
	b, _ := json.MarshalIndent(st, "", "  ")
	_ = os.WriteFile(m.h.Path(simFile), b, 0o644)
}

func (m *Manager) simInstalled(d def) bool {
	// A dry run stands in for an installed server, which runs the
	// panel's own services.
	if d.marker == "" || d.group == GroupServik {
		return true
	}
	_, err := os.Stat(m.h.Path(d.marker))
	return err == nil
}

// simUnit returns a dry run's unit, running since a made-up start.
func (m *Manager) simUnit(st simState, d def) simUnit {
	u, ok := st.Units[d.unit]
	if !ok {
		// Started when the server booted, a few days ago; the panel's
		// own services with the last update.
		u = simUnit{State: "active", Enabled: "enabled", Since: m.now().Add(-(76*time.Hour + time.Duration(len(d.unit))*time.Minute)).UTC().Truncate(time.Second)}
		if d.group == GroupServik {
			u.Since = m.now().Add(-5 * time.Hour).UTC().Truncate(time.Second)
		}
	}
	return u
}

func (m *Manager) simList(defs []def) []Status {
	st := m.simLoad()
	var out []Status
	for i, d := range defs {
		if !m.simInstalled(d) {
			continue
		}
		u := m.simUnit(st, d)
		s := m.status(d)
		s.State, s.Enabled = u.State, u.Enabled
		s.SubState = map[string]string{"active": "running", "inactive": "dead", "failed": "failed"}[u.State]
		since := u.Since
		s.Since = &since
		if u.State == "active" {
			s.PID = 900 + i*37
			s.Memory = uint64(20+i*7) << 20
		}
		out = append(out, s)
	}
	return out
}

func (m *Manager) simDo(d def, action string) {
	st := m.simLoad()
	u := m.simUnit(st, d)
	at := m.now().UTC()
	stamp := at.Local().Format("2006-01-02T15:04:05-0700") + " servik "
	switch action {
	case "start", "restart":
		if action == "restart" && u.State == "active" {
			u.Log = append(u.Log, stamp+"systemd[1]: Stopping "+d.unit+".service - "+d.name+"...", stamp+"systemd[1]: "+d.unit+".service: Deactivated successfully.")
		}
		u.State, u.Since = "active", at.Truncate(time.Second)
		u.Log = append(u.Log, stamp+"systemd[1]: Starting "+d.unit+".service - "+d.name+"...", stamp+"systemd[1]: Started "+d.unit+".service - "+d.name+".")
	case "stop":
		u.State, u.Since = "inactive", at.Truncate(time.Second)
		u.Log = append(u.Log, stamp+"systemd[1]: Stopping "+d.unit+".service - "+d.name+"...", stamp+"systemd[1]: "+d.unit+".service: Deactivated successfully.", stamp+"systemd[1]: Stopped "+d.unit+".service - "+d.name+".")
	case "reload":
		u.Log = append(u.Log, stamp+"systemd[1]: Reloading "+d.unit+".service - "+d.name+"...", stamp+"systemd[1]: Reloaded "+d.unit+".service - "+d.name+".")
	case "enable":
		u.Enabled = "enabled"
	case "disable":
		u.Enabled = "disabled"
	}
	if len(u.Log) > 200 {
		u.Log = u.Log[len(u.Log)-200:]
	}
	st.Units[d.unit] = u
	m.simSave(st)
}

func (m *Manager) simLogs(unit string, n int) Logs {
	st := m.simLoad()
	var d def
	for _, c := range m.catalog() {
		if c.unit == unit {
			d = c
		}
	}
	u := m.simUnit(st, d)
	start := u.Since
	if _, ok := st.Units[unit]; ok {
		start = m.now().Add(-76 * time.Hour)
	}
	stamp := func(t time.Time) string { return t.Local().Format("2006-01-02T15:04:05-0700") + " servik " }
	lines := []string{
		stamp(start) + "systemd[1]: Starting " + unit + ".service - " + d.name + "...",
		stamp(start.Add(time.Second)) + "systemd[1]: Started " + unit + ".service - " + d.name + ".",
	}
	lines = append(lines, simExtra(unit, start, stamp)...)
	lines = append(lines, u.Log...)
	if len(lines) > n {
		lines = lines[len(lines)-n:]
	}
	return Logs{Lines: lines}
}

// simExtra are typical lines a dry run shows for some services.
func simExtra(unit string, start time.Time, stamp func(time.Time) string) []string {
	switch {
	case strings.HasPrefix(unit, "php") && strings.HasSuffix(unit, "-fpm"):
		v := strings.TrimSuffix(strings.TrimPrefix(unit, "php"), "-fpm")
		return []string{
			stamp(start.Add(2*time.Hour)) + "php-fpm" + v + "[1042]: [WARNING] [pool magaza.com.tr] server reached pm.max_children setting (10), consider raising it",
			stamp(start.Add(26*time.Hour)) + "systemd[1]: Reloading " + unit + ".service - The PHP " + v + " FastCGI Process Manager...",
			stamp(start.Add(26*time.Hour)) + "systemd[1]: Reloaded " + unit + ".service - The PHP " + v + " FastCGI Process Manager.",
		}
	case unit == "nginx":
		return []string{
			stamp(start.Add(26*time.Hour)) + "systemd[1]: Reloading nginx.service - A high performance web server and a reverse proxy server...",
			stamp(start.Add(26*time.Hour)) + "systemd[1]: Reloaded nginx.service - A high performance web server and a reverse proxy server.",
		}
	case unit == "postfix":
		return []string{stamp(start.Add(3*time.Hour)) + "postfix/postfix-script[2210]: refreshing the Postfix mail system"}
	}
	return nil
}
