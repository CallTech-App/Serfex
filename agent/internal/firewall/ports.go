package firewall

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/netip"
	"os"
	"regexp"
	"sort"
	"strconv"
	"strings"

	"github.com/calltech-app/serfex/agent/internal/host"
)

// The port filter is a second table, so blocks keep working whether it
// is on or off:
//
//	table inet servik-ports
//	  chain input   loopback, replies, ICMP and DHCP pass; then the
//	                panel's deny rules, its allow rules, and a drop
//	/etc/servik/firewall/rules.json   the rules as last applied
//
// Off, the table does not exist and every port is reachable as before.
const (
	PortsTable = "servik-ports"
	rulesFile  = stateDir + "/rules.json"
	// MaxRules bounds the rule list.
	MaxRules = 100
)

// Protocols of a rule.
const (
	TCP  = "tcp"
	UDP  = "udp"
	Both = "both"
)

// PortRule opens (or closes) ports, for everyone or some addresses.
type PortRule struct {
	ID       string `json:"id"`
	Name     string `json:"name"`
	Ports    string `json:"ports"`
	Protocol string `json:"protocol"`
	// Sources limits the rule to these addresses or prefixes; empty is
	// everyone.
	Sources []string `json:"sources"`
	// Action is "allow" (the default) or "deny"; deny rules come first.
	Action string `json:"action"`
}

// RulesSpec is the whole port filter.
type RulesSpec struct {
	Enabled bool       `json:"enabled"`
	Rules   []PortRule `json:"rules"`
	// Protect is the connection that must stay possible (the person
	// saving, to the panel's port); a filter that would cut it off is
	// refused.
	Protect *Protect `json:"protect,omitempty"`
}

// Protect is an address that must keep reaching a TCP port.
type Protect struct {
	Address string `json:"address"`
	Port    int    `json:"port"`
}

// RulesStatus is the filter as applied, and what listens on the server.
type RulesStatus struct {
	Enabled   bool       `json:"enabled"`
	Rules     []PortRule `json:"rules"`
	Active    bool       `json:"active"`
	Listening []Listener `json:"listening"`
}

// Listener is a port a program listens on, on a public address.
type Listener struct {
	Protocol string `json:"protocol"`
	Port     int    `json:"port"`
	Address  string `json:"address"`
	Process  string `json:"process,omitempty"`
}

var (
	ruleIDRe   = regexp.MustCompile(`^[a-z0-9][a-z0-9_-]{0,40}$`)
	portListRe = regexp.MustCompile(`^\d{1,5}(?:-\d{1,5})?(?:\s*,\s*\d{1,5}(?:-\d{1,5})?)*$`)
)

type portRange struct{ lo, hi int }

func parsePorts(s string) ([]portRange, error) {
	s = strings.TrimSpace(s)
	if !portListRe.MatchString(s) {
		return nil, invalid("ports %q are not valid; write them like 80, 443 or 49152-49352", s)
	}
	var out []portRange
	for _, part := range strings.Split(s, ",") {
		lo, hi, isRange := strings.Cut(strings.TrimSpace(part), "-")
		a, _ := strconv.Atoi(lo)
		b := a
		if isRange {
			b, _ = strconv.Atoi(hi)
		}
		if a < 1 || b > 65535 || a > b {
			return nil, invalid("port range %q is not valid", part)
		}
		out = append(out, portRange{a, b})
	}
	return out, nil
}

type compiled struct {
	PortRule
	ports   []portRange
	sources []netip.Prefix
}

func (c compiled) matches(addr netip.Addr, port int, proto string) bool {
	if c.Protocol != Both && c.Protocol != proto {
		return false
	}
	in := false
	for _, r := range c.ports {
		in = in || port >= r.lo && port <= r.hi
	}
	if !in {
		return false
	}
	if len(c.sources) == 0 {
		return true
	}
	for _, p := range c.sources {
		if p.Contains(addr) {
			return true
		}
	}
	return false
}

func compile(spec RulesSpec) ([]compiled, error) {
	if len(spec.Rules) > MaxRules {
		return nil, invalid("at most %d rules", MaxRules)
	}
	ids := map[string]bool{}
	var out []compiled
	for _, r := range spec.Rules {
		if !ruleIDRe.MatchString(r.ID) || ids[r.ID] {
			return nil, invalid("rule id %q is not valid or used twice", r.ID)
		}
		ids[r.ID] = true
		if len(r.Name) > 80 {
			return nil, invalid("rule name is longer than 80 characters")
		}
		switch r.Protocol {
		case TCP, UDP, Both:
		default:
			return nil, invalid("protocol of %s must be tcp, udp or both", r.ID)
		}
		switch r.Action {
		case "":
			r.Action = "allow"
		case "allow", "deny":
		default:
			return nil, invalid("action of %s must be allow or deny", r.ID)
		}
		c := compiled{PortRule: r}
		var err error
		if c.ports, err = parsePorts(r.Ports); err != nil {
			return nil, err
		}
		if len(r.Sources) > 50 {
			return nil, invalid("rule %s has more than 50 addresses", r.ID)
		}
		for _, s := range r.Sources {
			p, err := Normalize(s)
			if err != nil {
				return nil, err
			}
			c.sources = append(c.sources, p)
		}
		out = append(out, c)
	}
	return out, nil
}

// allowed says whether the filter lets addr reach port.
func allowed(rules []compiled, addr netip.Addr, port int, proto string) bool {
	if addr.IsLoopback() {
		return true
	}
	for _, r := range rules {
		if r.Action == "deny" && r.matches(addr, port, proto) {
			return false
		}
	}
	for _, r := range rules {
		if r.Action != "deny" && r.matches(addr, port, proto) {
			return true
		}
	}
	return false
}

// ApplyRules turns the port filter on with these rules, or off.
func (m *Manager) ApplyRules(ctx context.Context, spec RulesSpec) (RulesStatus, error) {
	rules, err := compile(spec)
	if err != nil {
		return RulesStatus{}, err
	}
	if spec.Enabled && spec.Protect != nil {
		a, err := netip.ParseAddr(strings.TrimSpace(spec.Protect.Address))
		if err != nil {
			return RulesStatus{}, invalid("protect address %q is not valid", spec.Protect.Address)
		}
		if !allowed(rules, a.Unmap(), spec.Protect.Port, TCP) {
			return RulesStatus{}, invalid("these rules would close port %d to %s, your own connection to the panel; nothing was changed", spec.Protect.Port, a.Unmap())
		}
	}
	for i := range spec.Rules {
		if spec.Rules[i].Action == "" {
			spec.Rules[i].Action = "allow"
		}
		if spec.Rules[i].Sources == nil {
			spec.Rules[i].Sources = []string{}
		}
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	script := "add table inet " + PortsTable + "\ndelete table inet " + PortsTable + "\n"
	if spec.Enabled {
		script += renderPorts(rules)
	}
	if _, err := m.h.Run(ctx, host.Command{Name: "nft", Args: []string{"-f", "-"}, Stdin: []byte(script)}); err != nil {
		return RulesStatus{}, &OperationError{err}
	}
	if err := m.saveRules(RulesSpec{Enabled: spec.Enabled, Rules: spec.Rules}); err != nil {
		return RulesStatus{}, &OperationError{err}
	}
	return m.rulesStatus(ctx, RulesSpec{Enabled: spec.Enabled, Rules: spec.Rules}), nil
}

func renderPorts(rules []compiled) string {
	var b strings.Builder
	fmt.Fprintf(&b, "table inet %s {\n\tchain input {\n\t\ttype filter hook input priority filter; policy drop;\n", PortsTable)
	b.WriteString("\t\tiif \"lo\" accept\n\t\tct state established,related accept\n\t\tct state invalid drop\n")
	b.WriteString("\t\tmeta l4proto icmp accept\n\t\tmeta l4proto ipv6-icmp accept\n")
	b.WriteString("\t\t# DHCP and DHCPv6 replies keep the server's address.\n\t\tudp sport 67 udp dport 68 accept\n\t\tudp sport 547 udp dport 546 accept\n")
	for _, pass := range []string{"deny", "allow"} {
		for _, r := range rules {
			if r.Action != pass {
				continue
			}
			verdict := "accept"
			if pass == "deny" {
				verdict = "drop"
			}
			fmt.Fprintf(&b, "\t\t# %s\n", strings.ReplaceAll(r.Name, "\n", " "))
			var v4, v6 []string
			for _, p := range r.sources {
				if p.Addr().Is4() {
					v4 = append(v4, format(p))
				} else {
					v6 = append(v6, format(p))
				}
			}
			match := protoMatch(r.Protocol, r.ports)
			if len(r.sources) == 0 {
				fmt.Fprintf(&b, "\t\t%s %s\n", match, verdict)
				continue
			}
			if len(v4) > 0 {
				fmt.Fprintf(&b, "\t\tip saddr { %s } %s %s\n", strings.Join(v4, ", "), match, verdict)
			}
			if len(v6) > 0 {
				fmt.Fprintf(&b, "\t\tip6 saddr { %s } %s %s\n", strings.Join(v6, ", "), match, verdict)
			}
		}
	}
	b.WriteString("\t\tcounter drop\n\t}\n}\n")
	return b.String()
}

func protoMatch(proto string, ports []portRange) string {
	var ps []string
	for _, r := range ports {
		if r.lo == r.hi {
			ps = append(ps, strconv.Itoa(r.lo))
		} else {
			ps = append(ps, fmt.Sprintf("%d-%d", r.lo, r.hi))
		}
	}
	set := "{ " + strings.Join(ps, ", ") + " }"
	switch proto {
	case TCP:
		return "tcp dport " + set
	case UDP:
		return "udp dport " + set
	}
	return "meta l4proto { tcp, udp } th dport " + set
}

// Rules returns the filter as last applied.
func (m *Manager) Rules(ctx context.Context) (RulesStatus, error) {
	m.mu.Lock()
	spec, err := m.loadRules()
	m.mu.Unlock()
	if err != nil {
		return RulesStatus{}, &OperationError{err}
	}
	return m.rulesStatus(ctx, spec), nil
}

func (m *Manager) rulesStatus(ctx context.Context, spec RulesSpec) RulesStatus {
	st := RulesStatus{Enabled: spec.Enabled, Rules: spec.Rules, Listening: m.listening(ctx)}
	if st.Rules == nil {
		st.Rules = []PortRule{}
	}
	if _, err := m.h.Run(ctx, host.Command{Name: "nft", Args: []string{"list", "table", "inet", PortsTable}}); err == nil {
		st.Active = spec.Enabled
	}
	return st
}

// RestoreRules turns the filter back on after a reboot.
func (m *Manager) RestoreRules(ctx context.Context) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	spec, err := m.loadRules()
	if err != nil || !spec.Enabled {
		return err
	}
	rules, err := compile(spec)
	if err != nil {
		return err
	}
	script := "add table inet " + PortsTable + "\ndelete table inet " + PortsTable + "\n" + renderPorts(rules)
	_, err = m.h.Run(ctx, host.Command{Name: "nft", Args: []string{"-f", "-"}, Stdin: []byte(script)})
	return err
}

func (m *Manager) loadRules() (RulesSpec, error) {
	var spec RulesSpec
	data, err := os.ReadFile(m.h.Path(rulesFile))
	if errors.Is(err, os.ErrNotExist) {
		return RulesSpec{Rules: []PortRule{}}, nil
	}
	if err != nil {
		return spec, err
	}
	if err := json.Unmarshal(data, &spec); err != nil {
		return spec, fmt.Errorf("%s: %w", rulesFile, err)
	}
	return spec, nil
}

func (m *Manager) saveRules(spec RulesSpec) error {
	if err := os.MkdirAll(m.h.Path(stateDir), 0o700); err != nil {
		return err
	}
	data, err := json.MarshalIndent(spec, "", "  ")
	if err != nil {
		return err
	}
	tmp := m.h.Path(rulesFile + ".tmp")
	if err := os.WriteFile(tmp, append(data, '\n'), 0o600); err != nil {
		return err
	}
	return os.Rename(tmp, m.h.Path(rulesFile))
}

var processRe = regexp.MustCompile(`users:\(\("([^"]+)"`)

// listening lists ports programs listen on outside loopback (ss -lntu).
func (m *Manager) listening(ctx context.Context) []Listener {
	out, err := m.h.Run(ctx, host.Command{Name: "ss", Args: []string{"-H", "-lntup"}})
	if err != nil {
		return []Listener{}
	}
	if _, sim := m.h.(*host.Simulator); sim && out == "" {
		out = simListening
	}
	return parseListening(out)
}

func parseListening(out string) []Listener {
	seen := map[string]bool{}
	res := []Listener{}
	for _, line := range strings.Split(out, "\n") {
		f := strings.Fields(line)
		if len(f) < 5 {
			continue
		}
		local := f[4]
		i := strings.LastIndex(local, ":")
		if i < 0 {
			continue
		}
		addr, port := strings.Trim(local[:i], "[]"), local[i+1:]
		if j := strings.Index(addr, "%"); j >= 0 {
			addr = addr[:j]
		}
		n, err := strconv.Atoi(port)
		if err != nil {
			continue
		}
		if a, err := netip.ParseAddr(addr); err == nil && a.IsLoopback() {
			continue
		}
		proto := f[0]
		key := proto + "/" + port
		if seen[key] {
			continue
		}
		seen[key] = true
		l := Listener{Protocol: proto, Port: n, Address: addr}
		if m := processRe.FindStringSubmatch(line); m != nil {
			l.Process = m[1]
		}
		res = append(res, l)
	}
	sort.Slice(res, func(i, j int) bool {
		if res[i].Port != res[j].Port {
			return res[i].Port < res[j].Port
		}
		return res[i].Protocol < res[j].Protocol
	})
	return res
}

// What a dry run shows as listening.
const simListening = `tcp LISTEN 0 511 0.0.0.0:80 0.0.0.0:* users:(("nginx",pid=1,fd=6))
tcp LISTEN 0 511 0.0.0.0:443 0.0.0.0:* users:(("nginx",pid=1,fd=7))
tcp LISTEN 0 128 0.0.0.0:22 0.0.0.0:* users:(("sshd",pid=2,fd=3))
tcp LISTEN 0 511 0.0.0.0:8443 0.0.0.0:* users:(("nginx",pid=1,fd=8))
tcp LISTEN 0 100 0.0.0.0:25 0.0.0.0:* users:(("master",pid=3,fd=13))
tcp LISTEN 0 100 0.0.0.0:587 0.0.0.0:* users:(("master",pid=3,fd=17))
tcp LISTEN 0 100 0.0.0.0:993 0.0.0.0:* users:(("dovecot",pid=4,fd=40))
tcp LISTEN 0 128 0.0.0.0:53 0.0.0.0:* users:(("pdns_server",pid=5,fd=6))
udp UNCONN 0 0 0.0.0.0:53 0.0.0.0:* users:(("pdns_server",pid=5,fd=5))
tcp LISTEN 0 151 0.0.0.0:3306 0.0.0.0:* users:(("mysqld",pid=6,fd=23))
tcp LISTEN 0 511 127.0.0.1:6379 0.0.0.0:* users:(("redis-server",pid=7,fd=6))
`
