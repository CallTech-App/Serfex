package component

import (
	"bufio"
	"context"
	"net/netip"
	"regexp"
	"sort"
	"strings"
	"time"

	"github.com/winchester37/servik/agent/internal/database"
	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/txn"
)

// Fail2banStatus is what Fail2ban bans right now, jail by jail.
type Fail2banStatus struct {
	Installed bool   `json:"installed"`
	Running   bool   `json:"running"`
	Jails     []Jail `json:"jails"`
	// Current is false when the jails Servik writes are out of date (the
	// panel's jail or the mail jails are missing); Fail2banRefresh fixes it.
	Current bool `json:"current"`
}

// Jail is one Fail2ban jail and its bans.
type Jail struct {
	Name   string `json:"name"`
	Banned []Ban  `json:"banned"`
}

// Ban is an address a jail keeps out.
type Ban struct {
	Address string     `json:"address"`
	Since   time.Time  `json:"since"`
	Until   *time.Time `json:"until"`
}

// BanSpec names an address in a jail.
type BanSpec struct {
	Jail    string `json:"jail"`
	Address string `json:"address"`
}

var jailRe = regexp.MustCompile(`^[a-z0-9][a-z0-9_.-]{0,40}$`)

func (m *Manager) fail2banInstalled() bool { return m.exists("/usr/bin/fail2ban-server") }

func (m *Manager) panelPort() int {
	if m.db == nil {
		return database.DefaultPort
	}
	return m.db.WebPort()
}

// Fail2ban lists every jail with its bans.
func (m *Manager) Fail2ban(ctx context.Context) (Fail2banStatus, error) {
	st := Fail2banStatus{Jails: []Jail{}}
	if !m.fail2banInstalled() {
		return st, nil
	}
	st.Installed = true
	cur, _ := m.readManaged(jailConf)
	st.Current = cur == string(renderJails(m))
	if m.sim {
		st.Running = true
		st.Jails = m.demoJails()
		return st, nil
	}
	out, err := m.h.Run(ctx, host.Command{Name: "fail2ban-client", Args: []string{"status"}})
	if err != nil {
		return st, nil // not running
	}
	st.Running = true
	for _, name := range parseJailList(out) {
		j := Jail{Name: name, Banned: []Ban{}}
		out, err := m.h.Run(ctx, host.Command{Name: "fail2ban-client", Args: []string{"get", name, "banip", "--with-time"}})
		if err == nil {
			j.Banned = parseBans(out, time.Local)
		}
		st.Jails = append(st.Jails, j)
	}
	return st, nil
}

func (m *Manager) readManaged(abs string) (string, error) {
	b, err := txn.New(m.h).ReadManaged(abs)
	return string(b), err
}

// Fail2banRefresh rewrites Servik's jails (after the mail server or a new
// panel version) and reloads Fail2ban when they changed.
func (m *Manager) Fail2banRefresh(ctx context.Context) (bool, error) {
	if !m.fail2banInstalled() {
		return false, invalid("Fail2ban is not installed")
	}
	m.op.Lock()
	defer m.op.Unlock()
	t := txn.New(m.h)
	if err := writeJails(t, m); err != nil {
		_ = t.Rollback(context.WithoutCancel(ctx))
		return false, &OperationError{err}
	}
	if !t.Changed {
		return false, nil
	}
	if _, err := m.h.Run(ctx, host.Command{Name: "fail2ban-client", Args: []string{"--test"}}); err != nil {
		_ = t.Rollback(context.WithoutCancel(ctx))
		return false, &OperationError{err}
	}
	if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"reload-or-restart", "fail2ban"}}); err != nil {
		return false, &OperationError{err}
	}
	return true, nil
}

// Fail2banUnban lifts a ban early.
func (m *Manager) Fail2banUnban(ctx context.Context, spec BanSpec) error {
	return m.banCmd(ctx, spec, "unbanip")
}

// Fail2banBan bans an address in a jail again (Undo of an unban).
func (m *Manager) Fail2banBan(ctx context.Context, spec BanSpec) error {
	return m.banCmd(ctx, spec, "banip")
}

func (m *Manager) banCmd(ctx context.Context, spec BanSpec, verb string) error {
	if !jailRe.MatchString(spec.Jail) {
		return invalid("jail %q is not valid", spec.Jail)
	}
	a, err := netip.ParseAddr(strings.TrimSpace(spec.Address))
	if err != nil {
		return invalid("%q is not an IP address", spec.Address)
	}
	if !m.fail2banInstalled() {
		return invalid("Fail2ban is not installed")
	}
	if m.sim {
		m.demoBan(spec.Jail, a.String(), verb == "banip")
		return nil
	}
	if _, err := m.h.Run(ctx, host.Command{Name: "fail2ban-client", Args: []string{"set", spec.Jail, verb, a.String()}}); err != nil {
		return &OperationError{err}
	}
	return nil
}

// parseJailList reads "`- Jail list:	sshd, recidive" from fail2ban-client status.
func parseJailList(out string) []string {
	for _, line := range strings.Split(out, "\n") {
		_, rest, ok := strings.Cut(line, "Jail list:")
		if !ok {
			continue
		}
		var names []string
		for _, n := range strings.Split(rest, ",") {
			if n = strings.TrimSpace(n); jailRe.MatchString(n) {
				names = append(names, n)
			}
		}
		sort.Strings(names)
		return names
	}
	return nil
}

// parseBans reads "get JAIL banip --with-time" lines such as
// "203.0.113.5 	2026-10-19 13:00:00 + 3600 = 2026-10-19 14:00:00".
func parseBans(out string, loc *time.Location) []Ban {
	bans := []Ban{}
	sc := bufio.NewScanner(strings.NewReader(out))
	for sc.Scan() {
		f := strings.Fields(sc.Text())
		if len(f) < 1 {
			continue
		}
		a, err := netip.ParseAddr(f[0])
		if err != nil {
			continue
		}
		b := Ban{Address: a.String()}
		if len(f) >= 3 {
			if t, err := time.ParseInLocation("2006-01-02 15:04:05", f[1]+" "+f[2], loc); err == nil {
				b.Since = t.UTC()
			}
		}
		if len(f) >= 8 && f[3] == "+" && f[5] == "=" && f[4] != "-1" {
			if t, err := time.ParseInLocation("2006-01-02 15:04:05", f[6]+" "+f[7], loc); err == nil && t.Year() < 9000 {
				u := t.UTC()
				b.Until = &u
			}
		}
		bans = append(bans, b)
	}
	return bans
}

// A dry run shows a few bans so the panel has something to show.
var demoBans = map[string][]string{
	"sshd":         {"92.118.39.73", "218.92.0.112"},
	"postfix-sasl": {"141.98.11.62"},
	"servik-panel": {},
	"recidive":     {"80.82.77.139"},
}

func (m *Manager) demoState() map[string][]string {
	if m.demo == nil {
		m.demo = map[string][]string{}
		for k, v := range demoBans {
			m.demo[k] = append([]string{}, v...)
		}
	}
	return m.demo
}

func (m *Manager) demoJails() []Jail {
	m.mu.Lock()
	defer m.mu.Unlock()
	now := m.now().UTC().Truncate(time.Minute)
	var jails []Jail
	for name, ips := range m.demoState() {
		j := Jail{Name: name, Banned: []Ban{}}
		for i, ip := range ips {
			until := now.Add(time.Duration(40-i*15) * time.Minute)
			if name == "recidive" {
				until = now.Add(6 * 24 * time.Hour)
			}
			j.Banned = append(j.Banned, Ban{Address: ip, Since: until.Add(-time.Hour), Until: &until})
		}
		jails = append(jails, j)
	}
	sort.Slice(jails, func(i, k int) bool { return jails[i].Name < jails[k].Name })
	return jails
}

func (m *Manager) demoBan(jail, ip string, ban bool) {
	m.mu.Lock()
	defer m.mu.Unlock()
	list := m.demoState()[jail]
	out := []string{}
	for _, a := range list {
		if a != ip {
			out = append(out, a)
		}
	}
	if ban {
		out = append(out, ip)
	}
	m.demo[jail] = out
}
