package firewall

import (
	"context"
	"errors"
	"net/netip"
	"strings"
	"testing"
	"time"

	"github.com/calltech-app/serfex/agent/internal/host"
)

func newTest(t *testing.T) (*Manager, *host.Simulator, *time.Time) {
	t.Helper()
	sim, err := host.NewSimulator(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	now := time.Date(2026, 10, 19, 12, 0, 0, 0, time.UTC)
	m := NewManager(sim)
	m.Now = func() time.Time { return now }
	m.Protected = func() []netip.Addr { return []netip.Addr{netip.MustParseAddr("203.0.113.10")} }
	return m, sim, &now
}

func scripts(sim *host.Simulator) []string {
	var out []string
	for _, c := range sim.Commands() {
		if c.Name == "nft" && len(c.Args) == 2 && c.Args[0] == "-f" {
			out = append(out, string(c.Stdin))
		}
	}
	return out
}

func TestBlockAddsATimedElement(t *testing.T) {
	m, sim, _ := newTest(t)
	b, err := m.Block(context.Background(), Spec{Address: "45.155.205.233", Seconds: 3600, Reason: "wp-login"})
	if err != nil {
		t.Fatal(err)
	}
	if b.Address != "45.155.205.233" || b.Expires == nil || b.Expires.Sub(b.Created) != time.Hour {
		t.Fatalf("block = %+v", b)
	}
	s := scripts(sim)
	if len(s) != 1 || !strings.Contains(s[0], "add element inet servik blocked4 { 45.155.205.233 timeout 3600s }") {
		t.Fatalf("nft scripts = %q", s)
	}

	list, err := m.List()
	if err != nil || len(list) != 1 || list[0].Reason != "wp-login" {
		t.Fatalf("list = %+v, %v", list, err)
	}
}

func TestPermanentPrefixAndIPv6(t *testing.T) {
	m, sim, _ := newTest(t)
	ctx := context.Background()
	if b, err := m.Block(ctx, Spec{Address: "198.51.100.77/24"}); err != nil || b.Address != "198.51.100.0/24" || b.Expires != nil {
		t.Fatalf("prefix block = %+v, %v", b, err)
	}
	if b, err := m.Block(ctx, Spec{Address: "2001:DB8:0:0::1", Seconds: 60}); err != nil || b.Address != "2001:db8::1" {
		t.Fatalf("v6 block = %+v, %v", b, err)
	}
	s := scripts(sim)
	if !strings.Contains(s[0], "blocked4 { 198.51.100.0/24 }\n") || !strings.Contains(s[1], "blocked6 { 2001:db8::1 timeout 60s }") {
		t.Fatalf("nft scripts = %q", s)
	}
}

func TestRefusesDangerousAddresses(t *testing.T) {
	m, _, _ := newTest(t)
	ctx := context.Background()
	for _, a := range []string{"127.0.0.1", "::1", "0.0.0.0", "203.0.113.10", "203.0.113.0/24", "10.0.0.0/8", "2001:db8::/32", "not-an-ip", "fe80::1%eth0"} {
		_, err := m.Block(ctx, Spec{Address: a, Seconds: 60})
		var v *ValidationError
		if !errors.As(err, &v) {
			t.Errorf("%s: err = %v, want a validation error", a, err)
		}
	}
	if _, err := m.Block(ctx, Spec{Address: "192.0.2.1", Seconds: -1}); err == nil {
		t.Error("negative duration accepted")
	}
}

func TestOverlapsAreRefusedButTheSameAddressIsUpdated(t *testing.T) {
	m, _, _ := newTest(t)
	ctx := context.Background()
	if _, err := m.Block(ctx, Spec{Address: "192.0.2.0/24", Seconds: 60}); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Block(ctx, Spec{Address: "192.0.2.9", Seconds: 60}); err == nil || !strings.Contains(err.Error(), "overlaps 192.0.2.0/24") {
		t.Fatalf("overlap: %v", err)
	}
	if _, err := m.Block(ctx, Spec{Address: "192.0.2.0/24", Seconds: 7200, Reason: "longer"}); err != nil {
		t.Fatal(err)
	}
	list, _ := m.List()
	if len(list) != 1 || list[0].Reason != "longer" {
		t.Fatalf("list = %+v", list)
	}
}

func TestExpiredBlocksDisappearAndUnblock(t *testing.T) {
	m, sim, now := newTest(t)
	ctx := context.Background()
	if _, err := m.Block(ctx, Spec{Address: "192.0.2.1", Seconds: 60}); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Block(ctx, Spec{Address: "192.0.2.2"}); err != nil {
		t.Fatal(err)
	}
	*now = now.Add(2 * time.Minute)
	list, _ := m.List()
	if len(list) != 1 || list[0].Address != "192.0.2.2" {
		t.Fatalf("list after expiry = %+v", list)
	}
	removed, err := m.Unblock(ctx, "192.0.2.2")
	if err != nil || !removed {
		t.Fatalf("unblock = %v, %v", removed, err)
	}
	if removed, err := m.Unblock(ctx, "192.0.2.2"); err != nil || removed {
		t.Fatalf("second unblock = %v, %v", removed, err)
	}
	s := scripts(sim)
	if !strings.Contains(s[len(s)-1], "delete element inet servik blocked4 { 192.0.2.2 }") {
		t.Fatalf("unblock script = %q", s[len(s)-1])
	}
}

func TestRestoreRecreatesTheTableWithRemainingTime(t *testing.T) {
	m, sim, now := newTest(t)
	ctx := context.Background()
	if _, err := m.Block(ctx, Spec{Address: "192.0.2.1", Seconds: 3600}); err != nil {
		t.Fatal(err)
	}
	*now = now.Add(20 * time.Minute)
	if err := m.Restore(ctx); err != nil {
		t.Fatal(err)
	}
	s := scripts(sim)
	last := s[len(s)-1]
	if !strings.HasPrefix(last, "add table inet servik\ndelete table inet servik\n") || !strings.Contains(last, "{ 192.0.2.1 timeout 2400s }") {
		t.Fatalf("restore script:\n%s", last)
	}
}

func TestMissingTableIsCreated(t *testing.T) {
	m, sim, _ := newTest(t)
	sim.Fail = func(c host.Command) error {
		if c.Name == "nft" && len(c.Args) > 0 && c.Args[0] == "list" {
			return errors.New("No such file or directory")
		}
		return nil
	}
	if _, err := m.Block(context.Background(), Spec{Address: "192.0.2.1", Seconds: 60}); err != nil {
		t.Fatal(err)
	}
	s := scripts(sim)
	if len(s) != 2 || !strings.Contains(s[0], "chain input") || !strings.Contains(s[0], "ip saddr @blocked4") {
		t.Fatalf("scripts = %q", s)
	}
}
