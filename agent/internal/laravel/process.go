package laravel

import (
	"bytes"
	"context"
	"fmt"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"text/template"

	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/txn"
)

// Long-running processes of an application: Horizon, Octane, Reverb and
// the customer's own commands. Each is a systemd service
// (servik-proc-<domain>_<name>.service) running as the site's account in
// the live release, restarted when it stops and after every deploy. The
// web server part of Octane and Reverb (proxying to their port) is the
// site's (site.Spec.Proxy and WebSockets).

// Process is one long-running process of an application.
type Process struct {
	Name string `json:"name"`
	// Kind is horizon, octane, reverb or custom.
	Kind string `json:"kind"`
	// Enabled processes run and start with the server; the others are
	// stopped but keep their settings.
	Enabled bool `json:"enabled"`
	// Port is where Octane or Reverb listens, on 127.0.0.1.
	Port int `json:"port"`
	// Octane: the server (frankenphp, swoole or roadrunner), its worker
	// count (0 lets Octane pick) and requests per worker before a restart.
	Server      string `json:"server"`
	Workers     int    `json:"workers"`
	MaxRequests int    `json:"max_requests"`
	// Command is the program and arguments of a custom process. "php" is
	// the site's PHP and "artisan" is short for "php artisan".
	Command []string `json:"command"`
}

// ProcessSpec is every long-running process an application should have.
type ProcessSpec struct {
	App
	Processes []Process `json:"processes"`
}

// ProcessState is how a process is doing.
type ProcessState struct {
	Name    string `json:"name"`
	Unit    string `json:"unit"`
	Enabled bool   `json:"enabled"`
	State   string `json:"state"`
}

const maxProcs = 20

var octaneServers = map[string]bool{"frankenphp": true, "swoole": true, "roadrunner": true}

// programRe is a program name or absolute path of a custom process.
var programRe = regexp.MustCompile(`^(/[A-Za-z0-9._+-]+)+$|^[A-Za-z0-9._+-]+$`)

func (p *Process) normalise() error {
	if !workerRe.MatchString(p.Name) {
		return invalid("process name %q must be lowercase letters, digits or -", p.Name)
	}
	switch p.Kind {
	case "horizon":
	case "octane":
		if p.Server == "" {
			p.Server = "frankenphp"
		}
		if !octaneServers[p.Server] {
			return invalid("octane server must be frankenphp, swoole or roadrunner")
		}
		if p.Workers < 0 || p.Workers > 256 {
			return invalid("workers must be between 0 and 256")
		}
		if p.MaxRequests == 0 {
			p.MaxRequests = 500
		}
		if p.MaxRequests < 1 || p.MaxRequests > 1000000 {
			return invalid("max_requests must be between 1 and 1000000")
		}
	case "reverb":
	case "custom":
		if len(p.Command) == 0 || len(p.Command) > 64 {
			return invalid("a custom process needs a command of at most 64 words")
		}
		if !programRe.MatchString(p.Command[0]) || strings.Contains(p.Command[0], "..") {
			return invalid("%q is not a program name", p.Command[0])
		}
		for _, a := range p.Command {
			if len(a) > 1024 || strings.ContainsAny(a, "\x00\n\r") {
				return invalid("command arguments must be single lines shorter than 1 KB")
			}
		}
	default:
		return invalid("kind must be horizon, octane, reverb or custom")
	}
	if p.Kind == "octane" || p.Kind == "reverb" {
		if p.Port < 1024 || p.Port > 65535 {
			return invalid("%s needs a port between 1024 and 65535", p.Kind)
		}
	} else if p.Port != 0 {
		return invalid("only octane and reverb listen on a port")
	}
	return nil
}

// argv is the command line of the process.
func (p Process) argv(php string) []string {
	artisan := []string{php, "artisan"}
	switch p.Kind {
	case "horizon":
		return append(artisan, "horizon")
	case "octane":
		args := append(artisan, "octane:start", "--server="+p.Server, "--host=127.0.0.1", fmt.Sprintf("--port=%d", p.Port), fmt.Sprintf("--max-requests=%d", p.MaxRequests))
		if p.Workers > 0 {
			args = append(args, fmt.Sprintf("--workers=%d", p.Workers))
		}
		switch p.Server {
		case "frankenphp":
			// Every application needs its own admin port.
			args = append(args, fmt.Sprintf("--admin-port=%d", adminPort(p.Port)))
		case "roadrunner":
			args = append(args, fmt.Sprintf("--rpc-port=%d", adminPort(p.Port)))
		}
		return args
	case "reverb":
		return append(artisan, "reverb:start", "--host=127.0.0.1", fmt.Sprintf("--port=%d", p.Port))
	}
	switch p.Command[0] {
	case "php":
		return append([]string{php}, p.Command[1:]...)
	case "artisan":
		return append(artisan, p.Command[1:]...)
	}
	if strings.HasPrefix(p.Command[0], "/") {
		return p.Command
	}
	// A bare name is looked up on the deploy PATH.
	return append([]string{"/usr/bin/env"}, p.Command...)
}

// adminPort is the second port Octane's FrankenPHP and RoadRunner servers
// need, kept away from the range the panel hands out.
func adminPort(port int) int {
	if port >= 30000 {
		return port - 20000
	}
	return port + 20000
}

// stopTimeout gives Horizon time to finish its jobs.
func (p Process) stopTimeout() int {
	if p.Kind == "horizon" {
		return 300
	}
	return 60
}

// systemdQuote quotes one ExecStart word so systemd passes it unchanged.
func systemdQuote(s string) string {
	s = strings.NewReplacer(`\`, `\\`, `"`, `\"`, "%", "%%", "$", "$$").Replace(s)
	return `"` + s + `"`
}

var processTmpl = template.Must(template.New("process").Parse(`# {{.Marker}}. Manual changes are overwritten.
[Unit]
Description=Laravel {{.Label}} of {{.Domain}} ({{.Name}})
After=network.target

[Service]
User={{.User}}
Group={{.User}}
WorkingDirectory={{.Dir}}
ExecStart={{.Exec}}
Environment=HOME={{.Home}}
Environment=PATH={{.Path}}
Restart=always
RestartSec=5
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

func procPrefix(domain string) string { return "servik-proc-" + domain + "_" }

func procUnit(domain, name string) string { return procPrefix(domain) + name + ".service" }

// ProcessLog is the output file of a process.
func processLog(domain, name string) string { return logDir + "/" + domain + "/proc-" + name + ".log" }

var processLabels = map[string]string{"horizon": "Horizon", "octane": "Octane", "reverb": "Reverb", "custom": "process"}

// ApplyProcesses makes the application have exactly these processes.
func (m *Manager) ApplyProcesses(ctx context.Context, spec ProcessSpec) (states []ProcessState, err error) {
	if err := spec.App.validate(); err != nil {
		return nil, err
	}
	if len(spec.Processes) > maxProcs {
		return nil, invalid("at most %d processes are allowed", maxProcs)
	}
	seen := map[string]bool{}
	ports := map[int]bool{}
	for i := range spec.Processes {
		p := &spec.Processes[i]
		if err := p.normalise(); err != nil {
			return nil, err
		}
		if seen[p.Name] {
			return nil, invalid("process %q is listed twice", p.Name)
		}
		seen[p.Name] = true
		if p.Port != 0 {
			if ports[p.Port] {
				return nil, invalid("port %d is used twice", p.Port)
			}
			ports[p.Port] = true
		}
	}
	for _, p := range spec.Processes {
		if p.Enabled {
			if err := m.requireApp(spec.App); err != nil {
				return nil, err
			}
			break
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
	path := "/usr/local/bin:/usr/bin:/bin"
	if spec.PHPVersion != "" {
		path = shimDir(spec.PHPVersion) + ":" + nodeBin() + ":" + path
	}
	var changed []string
	for _, p := range spec.Processes {
		words := []string{}
		for _, w := range p.argv(phpBin(spec.PHPVersion)) {
			words = append(words, systemdQuote(w))
		}
		var b bytes.Buffer
		err := processTmpl.Execute(&b, map[string]any{
			"Marker": txn.Marker, "Label": processLabels[p.Kind], "Domain": spec.Domain, "Name": p.Name, "User": spec.User,
			"Home": "/home/" + spec.User, "Dir": spec.dir(), "Exec": strings.Join(words, " "), "Path": path,
			"Stop": p.stopTimeout(), "Log": processLog(spec.Domain, p.Name),
		})
		if err != nil {
			return nil, err
		}
		before := t.Changed
		t.Changed = false
		if err := t.WriteFile(unitDir+"/"+procUnit(spec.Domain, p.Name), b.Bytes()); err != nil {
			return nil, err
		}
		if t.Changed {
			changed = append(changed, p.Name)
		}
		t.Changed = t.Changed || before
	}

	var stop, start, restart, gone []string
	files, _ := filepath.Glob(m.h.Path(unitDir + "/" + procPrefix(spec.Domain) + "*.service"))
	for _, f := range files {
		name := strings.TrimSuffix(strings.TrimPrefix(filepath.Base(f), procPrefix(spec.Domain)), ".service")
		if !seen[name] {
			gone = append(gone, filepath.Base(f))
		}
	}
	enabled := m.enabledProcesses(spec.Domain)
	for _, u := range gone {
		if contains(enabled, u) {
			stop = append(stop, u)
		}
	}
	for _, p := range spec.Processes {
		u := procUnit(spec.Domain, p.Name)
		switch {
		case !p.Enabled && contains(enabled, u):
			stop = append(stop, u)
		case p.Enabled && !contains(enabled, u):
			start = append(start, u)
		case p.Enabled && contains(changed, p.Name):
			restart = append(restart, u)
		}
	}
	if len(stop) > 0 {
		if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: append([]string{"disable", "--now"}, stop...)}); err != nil {
			return nil, err
		}
	}
	for _, u := range gone {
		if err := t.RemoveFile(unitDir + "/" + u); err != nil {
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
	return m.processStates(ctx, spec.Domain), nil
}

// ProcessAction restarts one process.
type ProcessAction struct {
	Domain string `json:"domain"`
	Name   string `json:"name"`
}

// RestartProcess restarts an enabled process.
func (m *Manager) RestartProcess(ctx context.Context, a ProcessAction) ([]ProcessState, error) {
	if err := (Target{Domain: a.Domain, User: "x"}).validate(); err != nil {
		return nil, err
	}
	if !workerRe.MatchString(a.Name) {
		return nil, invalid("process name %q is not valid", a.Name)
	}
	u := procUnit(a.Domain, a.Name)
	if !contains(m.enabledProcesses(a.Domain), u) {
		return nil, invalid("process %s is not running", a.Name)
	}
	if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"restart", u}}); err != nil {
		return nil, &OperationError{err}
	}
	return m.processStates(ctx, a.Domain), nil
}

// ReadProcessLog returns the output of a process from offset on.
func (m *Manager) ReadProcessLog(a ProcessAction, offset int64) (RunChunk, error) {
	if err := (Target{Domain: a.Domain, User: "x"}).validate(); err != nil {
		return RunChunk{}, err
	}
	if !workerRe.MatchString(a.Name) {
		return RunChunk{}, invalid("process name %q is not valid", a.Name)
	}
	return m.readChunk(processLog(a.Domain, a.Name), offset, true)
}

// enabledProcesses lists the process units enabled at boot.
func (m *Manager) enabledProcesses(domain string) []string {
	links, _ := filepath.Glob(m.h.Path(unitDir + "/multi-user.target.wants/" + procPrefix(domain) + "*.service"))
	out := []string{}
	for _, l := range links {
		out = append(out, filepath.Base(l))
	}
	sort.Strings(out)
	return out
}

// processStates reports every process unit of a site.
func (m *Manager) processStates(ctx context.Context, domain string) []ProcessState {
	files, _ := filepath.Glob(m.h.Path(unitDir + "/" + procPrefix(domain) + "*.service"))
	out := []ProcessState{}
	if len(files) == 0 {
		return out
	}
	enabled := m.enabledProcesses(domain)
	units := make([]string, len(files))
	for i, f := range files {
		units[i] = filepath.Base(f)
	}
	sort.Strings(units)
	res, _ := m.h.Run(ctx, host.Command{Name: "systemctl", Args: append([]string{"is-active"}, units...)})
	lines := strings.Split(strings.TrimSpace(res), "\n")
	for i, u := range units {
		state := "inactive"
		if i < len(lines) && strings.TrimSpace(lines[i]) != "" {
			state = strings.TrimSpace(lines[i])
		}
		out = append(out, ProcessState{
			Name:    strings.TrimSuffix(strings.TrimPrefix(u, procPrefix(domain)), ".service"),
			Unit:    u,
			Enabled: contains(enabled, u),
			State:   state,
		})
	}
	return out
}

// restartProcesses restarts the running processes of a site after a
// deploy, so they run the live release. Horizon finishes its jobs first.
func (m *Manager) restartProcesses(ctx context.Context, domain string) error {
	units := m.enabledProcesses(domain)
	if len(units) == 0 {
		return nil
	}
	_, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: append([]string{"try-restart"}, units...)})
	return err
}
