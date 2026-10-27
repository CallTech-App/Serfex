package database

import (
	"context"
	"os"
	"regexp"
	"slices"
	"strconv"
	"strings"

	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/panel"
	"github.com/calltech-app/serfex/agent/internal/site"
)

// MaxWebPorts bounds serfex-web's ports: the panel's port and, while a
// change waits for confirmation, the new one (and one more to spare).
const MaxWebPorts = 3

// webPID is serfex-web's master process (see webUnitTmpl).
const webPID = "/run/serfex-web.pid"

// reservedPorts can never be serfex-web's.
var reservedPorts = map[int]string{
	80:              "the sites' HTTP port",
	443:             "the sites' HTTPS port",
	site.ApachePort: "the port of Apache behind Nginx",
	8081:            "the DNS server's API port",
}

// WebPortsSpec sets the ports serfex-web listens on.
type WebPortsSpec struct {
	Ports []int `json:"ports"`
}

// WebPorts are the ports serfex-web listens on.
type WebPorts struct {
	Ports []int `json:"ports"`
	// Port is the primary port, the first of Ports.
	Port int `json:"port"`
}

// WebPortsStatus returns serfex-web's ports.
func (m *Manager) WebPortsStatus() (WebPorts, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	st, err := m.loadState()
	if err != nil {
		return WebPorts{}, opError(err)
	}
	ports := m.webPorts(st)
	return WebPorts{Ports: ports, Port: ports[0]}, nil
}

// SetWebPorts makes serfex-web listen on ports, the first being the
// primary one. The panel moves to a new port in two steps: first the old
// and the new port together, then, once the new one is confirmed to
// work, the new one alone (or the old one alone to go back).
func (m *Manager) SetWebPorts(ctx context.Context, spec WebPortsSpec) (WebPorts, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	st, err := m.loadState()
	if err != nil {
		return WebPorts{}, opError(err)
	}
	current := m.webPorts(st)
	if err := validWebPorts(spec.Ports, current); err != nil {
		return WebPorts{}, err
	}
	busy, err := m.listening(ctx)
	if err != nil {
		return WebPorts{}, opError(err)
	}
	for _, p := range spec.Ports {
		if busy[p] {
			return WebPorts{}, invalid("port %d is already used by another program", p)
		}
	}
	ports := append([]int(nil), spec.Ports...)

	// serfex-web is (re)configured the way SetupPanelWeb does it: the
	// database tools only once the manager is set up. A development
	// setup without serfex-web only keeps the ports for later.
	if panel.Installed(m.h) || m.exists(webConf) {
		_, mysql := m.installedProduct(MySQL)
		_, pg := m.installedProduct(PostgreSQL)
		if st.Manager == nil {
			mysql, pg = false, false
		}
		if _, err := m.applyWeb(ctx, ports, mysql, pg); err != nil {
			return WebPorts{}, err
		}
	}
	st.WebPorts = ports
	if st.Manager != nil {
		st.Manager.Port = ports[0]
	}
	if err := m.saveState(st); err != nil {
		return WebPorts{}, opError(err)
	}
	return WebPorts{Ports: ports, Port: ports[0]}, nil
}

// validWebPorts checks 1 to MaxWebPorts distinct ports: unprivileged
// ones (1024-65535) or ones serfex-web already listens on, never one of
// reservedPorts.
func validWebPorts(ports, current []int) error {
	if len(ports) == 0 || len(ports) > MaxWebPorts {
		return invalid("give 1 to %d ports", MaxWebPorts)
	}
	seen := map[int]bool{}
	for _, p := range ports {
		if seen[p] {
			return invalid("port %d is listed twice", p)
		}
		seen[p] = true
		if what, ok := reservedPorts[p]; ok {
			return invalid("port %d is %s", p, what)
		}
		if (p < 1024 || p > 65535) && !slices.Contains(current, p) {
			return invalid("port %d must be between 1024 and 65535", p)
		}
	}
	return nil
}

// listening returns the TCP ports programs other than serfex-web listen
// on, on any address.
func (m *Manager) listening(ctx context.Context) (map[int]bool, error) {
	if m.Listening != nil {
		return m.Listening(ctx)
	}
	if _, sim := m.h.(*host.Simulator); sim {
		return map[int]bool{}, nil
	}
	out, err := m.h.Run(ctx, host.Command{Name: "ss", Args: []string{"-ltnHp"}})
	if err != nil {
		return nil, err
	}
	master := 0
	if data, err := os.ReadFile(m.h.Path(webPID)); err == nil {
		master, _ = strconv.Atoi(strings.TrimSpace(string(data)))
	}
	ours := func(pid int) bool {
		return master > 0 && (pid == master || m.parentPID(pid) == master)
	}
	return parseListeners(out, ours), nil
}

// parentPID reads a process's parent from /proc, 0 when unknown.
func (m *Manager) parentPID(pid int) int {
	data, err := os.ReadFile(m.h.Path("/proc/" + strconv.Itoa(pid) + "/stat"))
	if err != nil {
		return 0
	}
	// pid (comm) state ppid ...; comm may hold spaces and parentheses.
	s := string(data)
	i := strings.LastIndex(s, ")")
	if i < 0 {
		return 0
	}
	f := strings.Fields(s[i+1:])
	if len(f) < 2 {
		return 0
	}
	ppid, _ := strconv.Atoi(f[1])
	return ppid
}

var ssPIDRe = regexp.MustCompile(`pid=(\d+)`)

// parseListeners reads "ss -ltnHp" output into the ports listened on,
// leaving out sockets whose processes are all ours.
func parseListeners(out string, ours func(pid int) bool) map[int]bool {
	res := map[int]bool{}
	for _, line := range strings.Split(out, "\n") {
		f := strings.Fields(line)
		var local string
		switch {
		case len(f) >= 4 && f[0] == "LISTEN":
			local = f[3]
		case len(f) >= 5 && f[1] == "LISTEN":
			local = f[4]
		default:
			continue
		}
		i := strings.LastIndex(local, ":")
		if i < 0 {
			continue
		}
		port, err := strconv.Atoi(local[i+1:])
		if err != nil {
			continue
		}
		pids := ssPIDRe.FindAllStringSubmatch(line, -1)
		mine := len(pids) > 0
		for _, p := range pids {
			n, _ := strconv.Atoi(p[1])
			mine = mine && ours(n)
		}
		if !mine {
			res[port] = true
		}
	}
	return res
}
