package security

import (
	"bufio"
	"context"
	"errors"
	"fmt"
	"net"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"strconv"
	"strings"
	"syscall"
	"time"

	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/txn"
)

// The SSH port (Güvenlik > Giriş güvenliği). A change is made in two
// steps so nobody is locked out: the panel first adds the new port next
// to the old one, the administrator logs in through it, and only then is
// the old one dropped. Every step checks that sshd answers on each port
// before it reports success, and puts the old configuration back when it
// does not.
//
// sshd adds up every Port line it reads (unlike most settings, where the
// first wins), so Port lines elsewhere in the configuration are turned
// into comments and Serfex's file names every port.

// SSHPortsSpec is the ports sshd should listen on.
type SSHPortsSpec struct {
	Ports []int `json:"ports"`
}

// MaxSSHPorts: the new port and the old one while a change is checked.
const MaxSSHPorts = 2

// SSHMainConfig is the distribution's sshd configuration.
const SSHMainConfig = "/etc/ssh/sshd_config"

var (
	sshPortLineRe = regexp.MustCompile(`(?i)^\s*Port\s+(\d+)\s*(?:#.*)?$`)
	sshMatchRe    = regexp.MustCompile(`(?i)^\s*Match\s`)
)

// ValidSSHPort is 22 or an unprivileged port (1024-65535). Ports below
// 1024 other than 22 belong to well-known services.
func ValidSSHPort(p int) bool {
	return p == 22 || p >= 1024 && p <= 65535
}

// managedSSH reads Serfex's file: its hardening lines and its ports.
func (m *Manager) managedSSH() (hardening []string, ports []int, err error) {
	b, err := txn.New(m.h).ReadManaged(SSHConfig)
	if err != nil {
		return nil, nil, err
	}
	for _, line := range strings.Split(string(b), "\n") {
		line = strings.TrimSpace(line)
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		if p := sshPortLineRe.FindStringSubmatch(line); p != nil {
			n, _ := strconv.Atoi(p[1])
			ports = append(ports, n)
			continue
		}
		hardening = append(hardening, line)
	}
	return hardening, ports, nil
}

// ManagedSSHPorts are the ports Serfex set, nil when it never set any
// (Fail2ban's sshd jail then keeps its default port).
func (m *Manager) ManagedSSHPorts() []int {
	_, ports, err := m.managedSSH()
	if err != nil {
		return nil
	}
	return ports
}

func renderSSHConfig(hardening []string, ports []int) []byte {
	var b strings.Builder
	b.WriteString(header("SSH hardening and port (Güvenlik > Giriş güvenliği). Read first, so these values win."))
	for _, l := range hardening {
		b.WriteString(l + "\n")
	}
	if len(ports) > 0 {
		b.WriteString("# sshd listens on every Port line it reads; the ones elsewhere are comments.\n")
		for _, p := range ports {
			fmt.Fprintf(&b, "Port %d\n", p)
		}
	}
	return []byte(b.String())
}

// ApplySSHPorts makes sshd listen on exactly these ports. A port that is
// new must be free. When sshd does not answer on every port afterwards,
// the previous configuration is put back and sshd restarted with it.
func (m *Manager) ApplySSHPorts(ctx context.Context, spec SSHPortsSpec) (SSHStatus, error) {
	ports := spec.Ports
	if len(ports) == 0 || len(ports) > MaxSSHPorts {
		return SSHStatus{}, invalid("give one or two ports")
	}
	for i, p := range ports {
		if !ValidSSHPort(p) {
			return SSHStatus{}, invalid("port %d is not valid; use 22 or a port from 1024 to 65535", p)
		}
		if slices.Contains(ports[:i], p) {
			return SSHStatus{}, invalid("port %d is given twice", p)
		}
	}
	if !m.exists("/usr/sbin/sshd") {
		return SSHStatus{}, invalid("the SSH server is not installed")
	}
	before, err := m.SSH(ctx)
	if err != nil {
		return SSHStatus{}, err
	}
	for _, p := range ports {
		if !slices.Contains(before.Ports, p) && m.portInUse(p) {
			return SSHStatus{}, invalid("port %d is in use by another program", p)
		}
	}

	m.mu.Lock()
	defer m.mu.Unlock()
	t := txn.New(m.h)
	hardening, _, err := m.managedSSH()
	if err == nil {
		err = t.RootDir("/etc/ssh/sshd_config.d", 0o755, 0, 0)
	}
	if err == nil {
		err = t.WriteFile(SSHConfig, renderSSHConfig(hardening, ports))
	}
	if err == nil {
		err = m.commentOtherPorts(t)
	}
	if err != nil {
		_ = t.Rollback(context.WithoutCancel(ctx))
		return SSHStatus{}, &OperationError{err}
	}
	if !t.Changed {
		return m.SSH(ctx)
	}
	m.privsepDir()
	if out, err := m.h.Run(ctx, host.Command{Name: "sshd", Args: []string{"-t"}}); err != nil {
		_ = t.Rollback(context.WithoutCancel(ctx))
		return SSHStatus{}, &OperationError{fmt.Errorf("sshd rejected the configuration: %s", lastLines(out, 2))}
	}
	listen, err := m.sshListen(ctx)
	if err == nil {
		got := []int{}
		for _, a := range listen {
			if !slices.Contains(got, a.port) {
				got = append(got, a.port)
			}
		}
		want := slices.Clone(ports)
		slices.Sort(got)
		slices.Sort(want)
		if !slices.Equal(got, want) {
			err = fmt.Errorf("sshd would listen on %v; a ListenAddress line elsewhere names its own port, change it by hand", got)
		}
	}
	if err == nil {
		m.openInUFW(ctx, before.Ports, ports)
		err = m.restartSSH(ctx)
	}
	if err == nil {
		err = m.answers(ctx, listen, ports)
	}
	if err != nil {
		bg := context.WithoutCancel(ctx)
		_ = t.Rollback(bg)
		// The old ports again; this is what keeps the server reachable.
		_ = m.restartSSH(bg)
		return SSHStatus{}, &OperationError{err}
	}
	return m.SSH(ctx)
}

// openInUFW opens new ports in ufw too when it is on, which would
// otherwise drop them below Serfex's own firewall. Its rules for old
// ports are left to the administrator.
func (m *Manager) openInUFW(ctx context.Context, old, ports []int) {
	if !m.exists("/usr/sbin/ufw") {
		return
	}
	out, err := m.h.Run(ctx, host.Command{Name: "ufw", Args: []string{"status"}})
	if err != nil || !strings.Contains(out, "Status: active") {
		return
	}
	for _, p := range ports {
		if !slices.Contains(old, p) {
			_, _ = m.h.Run(ctx, host.Command{Name: "ufw", Args: []string{"allow", strconv.Itoa(p) + "/tcp", "comment", "SSH (Serfex)"}})
		}
	}
}

// commentOtherPorts turns Port lines outside Serfex's file into comments
// (only before the first Match block, where Port is allowed).
func (m *Manager) commentOtherPorts(t *txn.Tx) error {
	files := []string{SSHMainConfig}
	if matches, err := filepath.Glob(m.h.Path("/etc/ssh/sshd_config.d/*.conf")); err == nil {
		for _, p := range matches {
			abs := "/etc/ssh/sshd_config.d/" + filepath.Base(p)
			if abs != SSHConfig {
				files = append(files, abs)
			}
		}
	}
	for _, abs := range files {
		p := m.h.Path(abs)
		fi, err := os.Stat(p)
		if err != nil {
			continue
		}
		b, err := os.ReadFile(p)
		if err != nil {
			return err
		}
		lines := strings.Split(string(b), "\n")
		changed := false
		for i, line := range lines {
			if sshMatchRe.MatchString(line) {
				break
			}
			if sshPortLineRe.MatchString(line) {
				lines[i] = "#" + line + "  # Serfex: the port is set in " + filepath.Base(SSHConfig)
				changed = true
			}
		}
		if changed {
			if err := t.WriteOwned(abs, []byte(strings.Join(lines, "\n")), fi.Mode().Perm(), 0, 0); err != nil {
				return err
			}
		}
	}
	return nil
}

type listenAddr struct {
	host string
	port int
}

// sshListen reads the addresses sshd listens on from sshd -T.
func (m *Manager) sshListen(ctx context.Context) ([]listenAddr, error) {
	out, err := m.h.Run(ctx, host.Command{Name: "sshd", Args: []string{"-T"}})
	if err != nil {
		return nil, fmt.Errorf("sshd -T: %s", lastLines(out, 2))
	}
	if m.sim {
		out = m.simEffective()
	}
	var list []listenAddr
	sc := bufio.NewScanner(strings.NewReader(out))
	for sc.Scan() {
		k, v, _ := strings.Cut(strings.TrimSpace(sc.Text()), " ")
		if !strings.EqualFold(k, "listenaddress") {
			continue
		}
		h, p, err := net.SplitHostPort(strings.TrimSpace(v))
		if err != nil {
			continue
		}
		n, err := strconv.Atoi(p)
		if err != nil {
			continue
		}
		list = append(list, listenAddr{h, n})
	}
	return list, nil
}

// restartSSH makes sshd listen on the configured ports. Ubuntu 24.04
// starts sshd from ssh.socket, whose ports a generator writes from
// sshd's configuration at daemon-reload. Open sessions survive either
// way (KillMode=process).
func (m *Manager) restartSSH(ctx context.Context) error {
	if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"is-active", "--quiet", "ssh.socket"}}); err == nil {
		for _, args := range [][]string{{"daemon-reload"}, {"restart", "ssh.socket"}, {"try-restart", "ssh.service"}} {
			if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: args}); err != nil {
				return err
			}
		}
		return nil
	}
	_, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"try-reload-or-restart", "ssh"}})
	return err
}

// sshAnswerWait is how long sshd has to greet on a port after a restart.
var sshAnswerWait = 15 * time.Second

// answers waits until sshd greets on every port.
func (m *Manager) answers(ctx context.Context, listen []listenAddr, ports []int) error {
	if m.sim && m.dialSSH == nil {
		return nil
	}
	dial := m.dialSSH
	if dial == nil {
		dial = greets
	}
	deadline := time.Now().Add(sshAnswerWait)
	for _, p := range ports {
		targets := []string{}
		for _, a := range listen {
			if a.port != p {
				continue
			}
			h := a.host
			switch h {
			case "0.0.0.0", "":
				h = "127.0.0.1"
			case "::":
				h = "::1"
			}
			targets = append(targets, net.JoinHostPort(h, strconv.Itoa(p)))
		}
		if len(targets) == 0 {
			targets = []string{net.JoinHostPort("127.0.0.1", strconv.Itoa(p))}
		}
		for {
			var err error
			for _, addr := range targets {
				if err = dial(ctx, addr); err == nil {
					break
				}
			}
			if err == nil {
				break
			}
			if time.Now().After(deadline) || ctx.Err() != nil {
				return fmt.Errorf("sshd does not answer on port %d: %v", p, err)
			}
			time.Sleep(500 * time.Millisecond)
		}
	}
	return nil
}

// greets connects and reads sshd's "SSH-2.0-..." greeting.
func greets(ctx context.Context, addr string) error {
	d := net.Dialer{Timeout: 3 * time.Second}
	c, err := d.DialContext(ctx, "tcp", addr)
	if err != nil {
		return err
	}
	defer c.Close()
	_ = c.SetReadDeadline(time.Now().Add(5 * time.Second))
	line, err := bufio.NewReader(c).ReadString('\n')
	if err != nil {
		return err
	}
	if !strings.HasPrefix(line, "SSH-") {
		return errors.New("something other than SSH answered")
	}
	return nil
}

// portInUse: something already listens on the TCP port.
func (m *Manager) portInUse(p int) bool {
	if m.inUse != nil {
		return m.inUse(p)
	}
	l, err := net.Listen("tcp", ":"+strconv.Itoa(p))
	if err != nil {
		// Only "in use" counts; a dry run may not bind low ports at all.
		return errors.Is(err, syscall.EADDRINUSE)
	}
	_ = l.Close()
	return false
}

// simEffective stands in for sshd -T on a simulator: OpenSSH's defaults,
// the ports of the main file and Serfex's file, and Serfex's settings.
func (m *Manager) simEffective() string {
	var ports []string
	var settings strings.Builder
	for _, abs := range []string{SSHConfig, SSHMainConfig} {
		b, err := os.ReadFile(m.h.Path(abs))
		if err != nil {
			continue
		}
		for _, line := range strings.Split(string(b), "\n") {
			if sshMatchRe.MatchString(line) {
				break
			}
			if p := sshPortLineRe.FindStringSubmatch(line); p != nil {
				ports = append(ports, p[1])
			} else if abs == SSHConfig && !strings.HasPrefix(strings.TrimSpace(line), "#") {
				settings.WriteString(strings.ToLower(line) + "\n")
			}
		}
	}
	if len(ports) == 0 {
		ports = []string{"22"}
	}
	var b strings.Builder
	for _, p := range ports {
		b.WriteString("port " + p + "\n")
	}
	b.WriteString(settings.String())
	if !strings.Contains(settings.String(), "permitrootlogin") {
		b.WriteString("permitrootlogin yes\npasswordauthentication yes\n")
	}
	for _, p := range ports {
		b.WriteString("listenaddress [::]:" + p + "\nlistenaddress 0.0.0.0:" + p + "\n")
	}
	return b.String()
}
