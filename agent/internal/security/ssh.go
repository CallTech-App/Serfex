package security

import (
	"bufio"
	"context"
	"fmt"
	"os"
	"sort"
	"strconv"
	"strings"

	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/txn"
)

// SSHConfig is Servik's SSH hardening file. sshd keeps the first value it
// reads for each setting and reads sshd_config.d/ in order, so this file
// wins over the distribution's and cloud-init's. The SFTP accounts' Match
// block (package ftp) keeps password logins for them.
const SSHConfig = "/etc/ssh/sshd_config.d/00-servik.conf"

// Root login values of sshd's PermitRootLogin.
const (
	RootYes = "yes"
	RootKey = "prohibit-password"
	RootNo  = "no"
)

// SSHSettings is what the panel changes.
type SSHSettings struct {
	RootLogin    string `json:"root_login"`
	PasswordAuth bool   `json:"password_auth"`
}

// SSHStatus is sshd's effective configuration.
type SSHStatus struct {
	Installed bool `json:"installed"`
	SSHSettings
	Ports []int `json:"ports"`
	// Managed is true once Servik wrote its file.
	Managed bool `json:"managed"`
	// KeyAccounts are the administrator accounts with an SSH key, who
	// can still log in when passwords are turned off.
	KeyAccounts []string `json:"key_accounts"`
}

// SSH reads the effective configuration with sshd -T.
func (m *Manager) SSH(ctx context.Context) (SSHStatus, error) {
	st := SSHStatus{Ports: []int{}, KeyAccounts: m.keyAccounts()}
	if !m.exists("/usr/sbin/sshd") {
		return st, nil
	}
	st.Installed = true
	_, err := os.Stat(m.h.Path(SSHConfig))
	st.Managed = err == nil
	m.privsepDir()
	out, err := m.h.Run(ctx, host.Command{Name: "sshd", Args: []string{"-T"}})
	if err != nil {
		return st, &OperationError{fmt.Errorf("sshd -T: %s", lastLines(out, 2))}
	}
	if m.sim && !st.Managed {
		out = "port 22\npermitrootlogin yes\npasswordauthentication yes\n"
	} else if m.sim {
		b, _ := os.ReadFile(m.h.Path(SSHConfig))
		out = "port 22\n" + strings.ToLower(string(b))
	}
	st.RootLogin, st.PasswordAuth = RootYes, true
	sc := bufio.NewScanner(strings.NewReader(out))
	for sc.Scan() {
		k, v, _ := strings.Cut(strings.TrimSpace(sc.Text()), " ")
		switch strings.ToLower(k) {
		case "port":
			if n, err := strconv.Atoi(v); err == nil {
				st.Ports = append(st.Ports, n)
			}
		case "permitrootlogin":
			if v == "without-password" {
				v = RootKey
			}
			st.RootLogin = v
		case "passwordauthentication":
			st.PasswordAuth = v == "yes"
		}
	}
	return st, nil
}

// ApplySSH writes the hardening file, checks it with sshd -t and reloads
// sshd. Turning passwords off is refused while no administrator account
// has a key, which would lock everyone out.
func (m *Manager) ApplySSH(ctx context.Context, s SSHSettings) (SSHStatus, error) {
	switch s.RootLogin {
	case RootYes, RootKey, RootNo:
	default:
		return SSHStatus{}, invalid("root_login must be yes, prohibit-password or no")
	}
	if !m.exists("/usr/sbin/sshd") {
		return SSHStatus{}, invalid("the SSH server is not installed")
	}
	if !s.PasswordAuth {
		keys := m.keyAccounts()
		usable := false
		for _, a := range keys {
			usable = usable || a != "root" || s.RootLogin != RootNo
		}
		if !usable {
			return SSHStatus{}, invalid("no administrator account that may log in has an SSH key; add a key before turning password logins off")
		}
	}
	m.mu.Lock()
	t := txn.New(m.h)
	pw := "yes"
	if !s.PasswordAuth {
		pw = "no"
	}
	conf := header("SSH hardening (Güvenlik > Giriş güvenliği). Read first, so these values win.") +
		"PermitRootLogin " + s.RootLogin + "\nPasswordAuthentication " + pw + "\nKbdInteractiveAuthentication " + pw + "\n"
	err := t.RootDir("/etc/ssh/sshd_config.d", 0o755, 0, 0)
	if err == nil {
		err = t.WriteFile(SSHConfig, []byte(conf))
	}
	if err == nil && t.Changed {
		m.privsepDir()
		if out, cerr := m.h.Run(ctx, host.Command{Name: "sshd", Args: []string{"-t"}}); cerr != nil {
			err = fmt.Errorf("sshd rejected the configuration: %s", lastLines(out, 2))
		} else if _, rerr := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"try-reload-or-restart", "ssh"}}); rerr != nil {
			err = rerr
		}
	}
	if err != nil {
		_ = t.Rollback(context.WithoutCancel(ctx))
		m.mu.Unlock()
		return SSHStatus{}, &OperationError{err}
	}
	m.mu.Unlock()
	return m.SSH(ctx)
}

// privsepDir makes /run/sshd, which sshd -t and -T need; with socket
// activation (Ubuntu 24.04) it is missing until the first login.
func (m *Manager) privsepDir() {
	if !m.sim {
		_ = os.MkdirAll("/run/sshd", 0o755)
	}
}

// keyAccounts lists root and the sudo/admin group members that have a
// non-empty authorized_keys file.
func (m *Manager) keyAccounts() []string {
	accounts := map[string]bool{"root": true}
	if data, err := os.ReadFile(m.h.Path("/etc/group")); err == nil {
		for _, line := range strings.Split(string(data), "\n") {
			f := strings.Split(line, ":")
			if len(f) == 4 && (f[0] == "sudo" || f[0] == "admin" || f[0] == "wheel") {
				for _, u := range strings.Split(f[3], ",") {
					if u = strings.TrimSpace(u); u != "" {
						accounts[u] = true
					}
				}
			}
		}
	}
	out := []string{}
	for name := range accounts {
		home := "/root"
		if name != "root" {
			u, err := m.h.LookupUser(name)
			if err != nil {
				continue
			}
			home = u.Home
		}
		if hasKey(m.h.Path(home + "/.ssh/authorized_keys")) {
			out = append(out, name)
		}
	}
	sort.Strings(out)
	return out
}

func hasKey(path string) bool {
	data, err := os.ReadFile(path)
	if err != nil {
		return false
	}
	for _, line := range strings.Split(string(data), "\n") {
		line = strings.TrimSpace(line)
		if line != "" && !strings.HasPrefix(line, "#") && strings.Contains(line, " ") {
			return true
		}
	}
	return false
}
