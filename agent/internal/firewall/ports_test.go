package firewall

import (
	"context"
	"net/netip"
	"strings"
	"testing"
)

func defaultRules() []PortRule {
	return []PortRule{
		{ID: "web", Name: "Web", Ports: "80, 443", Protocol: TCP},
		{ID: "panel", Name: "Servik paneli", Ports: "8443", Protocol: TCP},
		{ID: "dns", Name: "DNS", Ports: "53", Protocol: Both},
		{ID: "mysql", Name: "MySQL", Ports: "3306", Protocol: TCP, Sources: []string{"10.0.0.0/8", "2001:db8::/48"}},
		{ID: "bad", Name: "Kötü ağ", Ports: "1-65535", Protocol: Both, Sources: []string{"198.51.100.0/24"}, Action: "deny"},
	}
}

func TestPortFilter(t *testing.T) {
	m, sim, _ := newTest(t)
	ctx := context.Background()
	st, err := m.ApplyRules(ctx, RulesSpec{Enabled: true, Rules: defaultRules(), Protect: &Protect{Address: "203.0.113.50", Port: 8443}})
	if err != nil || !st.Enabled || !st.Active || len(st.Rules) != 5 {
		t.Fatalf("apply: %+v %v", st, err)
	}
	s := scripts(sim)
	script := s[len(s)-1]
	for _, want := range []string{
		"delete table inet servik-ports",
		"policy drop;",
		"iif \"lo\" accept",
		"ct state established,related accept",
		"udp sport 67 udp dport 68 accept",
		"tcp dport { 80, 443 } accept",
		"meta l4proto { tcp, udp } th dport { 53 } accept",
		"ip saddr { 10.0.0.0/8 } tcp dport { 3306 } accept",
		"ip6 saddr { 2001:db8::/48 } tcp dport { 3306 } accept",
		"ip saddr { 198.51.100.0/24 } meta l4proto { tcp, udp } th dport { 1-65535 } drop",
	} {
		if !strings.Contains(script, want) {
			t.Errorf("script lacks %q", want)
		}
	}
	if strings.Index(script, "198.51.100.0/24") > strings.Index(script, "tcp dport { 80, 443 }") {
		t.Error("deny rules must come before allow rules")
	}
	if t.Failed() {
		t.Log(script)
	}
	got, err := m.Rules(ctx)
	if err != nil || !got.Enabled || len(got.Rules) != 5 || len(got.Listening) == 0 {
		t.Fatalf("rules: %+v %v", got, err)
	}

	// Closing the panel to the person saving is refused.
	rules := defaultRules()[:1]
	if _, err := m.ApplyRules(ctx, RulesSpec{Enabled: true, Rules: rules, Protect: &Protect{Address: "203.0.113.50", Port: 8443}}); err == nil || !strings.Contains(err.Error(), "your own connection") {
		t.Fatalf("lockout: %v", err)
	}
	rules = append(defaultRules(), PortRule{ID: "me", Name: "x", Ports: "8443", Protocol: TCP, Sources: []string{"203.0.113.0/24"}, Action: "deny"})
	if _, err := m.ApplyRules(ctx, RulesSpec{Enabled: true, Rules: rules, Protect: &Protect{Address: "203.0.113.50", Port: 8443}}); err == nil {
		t.Fatal("denying the admin's network was accepted")
	}
	// Off removes the table; turning off can never lock anyone out.
	if _, err := m.ApplyRules(ctx, RulesSpec{Enabled: false, Rules: defaultRules()[:1], Protect: &Protect{Address: "203.0.113.50", Port: 8443}}); err != nil {
		t.Fatal(err)
	}
	s = scripts(sim)
	if last := s[len(s)-1]; strings.Contains(last, "policy drop") {
		t.Fatalf("off still filters:\n%s", last)
	}
	for _, bad := range []PortRule{
		{ID: "x", Ports: "70000", Protocol: TCP}, {ID: "x", Ports: "80;", Protocol: TCP}, {ID: "x", Ports: "90-80", Protocol: TCP},
		{ID: "x", Ports: "80", Protocol: "icmp"}, {ID: "X Y", Ports: "80", Protocol: TCP}, {ID: "x", Ports: "80", Protocol: TCP, Sources: []string{"nope"}},
	} {
		if _, err := m.ApplyRules(ctx, RulesSpec{Enabled: true, Rules: []PortRule{bad}}); err == nil {
			t.Errorf("%+v accepted", bad)
		}
	}
}

func TestAllowed(t *testing.T) {
	rules, err := compile(RulesSpec{Rules: defaultRules()})
	if err != nil {
		t.Fatal(err)
	}
	for _, c := range []struct {
		addr  string
		port  int
		proto string
		want  bool
	}{
		{"203.0.113.9", 443, TCP, true}, {"203.0.113.9", 3306, TCP, false}, {"10.1.2.3", 3306, TCP, true},
		{"198.51.100.4", 443, TCP, false}, {"203.0.113.9", 53, UDP, true}, {"203.0.113.9", 443, UDP, false},
		{"127.0.0.1", 9999, TCP, true},
	} {
		if got := allowed(rules, netip.MustParseAddr(c.addr), c.port, c.proto); got != c.want {
			t.Errorf("%s %d/%s = %v, want %v", c.addr, c.port, c.proto, got, c.want)
		}
	}
}

func TestParseListening(t *testing.T) {
	l := parseListening("tcp LISTEN 0 511 0.0.0.0:80 0.0.0.0:* users:((\"nginx\",pid=1,fd=6))\ntcp LISTEN 0 511 [::]:80 [::]:*\ntcp LISTEN 0 511 127.0.0.1:6379 0.0.0.0:*\nudp UNCONN 0 0 [fe80::1%eth0]:546 [::]:*\n")
	if len(l) != 2 || l[0].Port != 80 || l[0].Process != "nginx" || l[1].Port != 546 || l[1].Protocol != "udp" {
		t.Fatalf("%+v", l)
	}
}
