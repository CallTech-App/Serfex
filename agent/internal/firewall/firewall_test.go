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
	now := time.Now().UTC().Truncate(time.Second)
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
	if len(s) != 1 || !strings.Contains(s[0], "add element inet serfex blocked4 { 45.155.205.233 timeout 3600s }") {
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
	if !strings.Contains(s[len(s)-1], "delete element inet serfex blocked4 { 192.0.2.2 }") {
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
	if !strings.HasPrefix(last, "add table inet serfex\ndelete table inet serfex\n") || !strings.Contains(last, "{ 192.0.2.1 timeout 2400s }") {
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

func watchChanges(m *Manager) *int {
	n := 0
	m.Changed = func(context.Context) { n++ }
	return &n
}

func TestBlockManyWritesOneScriptAndSkipsBadItems(t *testing.T) {
	m, sim, _ := newTest(t)
	changes := watchChanges(m)
	res, err := m.BlockMany(context.Background(), []Spec{
		{Address: "45.155.205.233", Seconds: 3600, Reason: "list"},
		{Address: "198.51.100.77/24"},
		{Address: "not-an-ip"},
		{Address: "127.0.0.1"},
		{Address: "198.51.100.9"},   // inside the prefix two lines up
		{Address: "45.155.205.233"}, // listed twice
		{Address: "2001:DB8:0:0::1", Seconds: 60},
		{Address: "192.0.2.1", Seconds: -5},
	})
	if err != nil {
		t.Fatal(err)
	}
	var got []string
	for _, b := range res.Blocked {
		got = append(got, b.Address)
	}
	if strings.Join(got, " ") != "45.155.205.233 198.51.100.0/24 2001:db8::1" {
		t.Fatalf("blocked = %v", got)
	}
	if res.Blocked[0].Expires == nil || res.Blocked[0].Reason != "list" || res.Blocked[1].Expires != nil {
		t.Fatalf("blocked = %+v", res.Blocked)
	}
	var skipped []string
	for _, s := range res.Skipped {
		if s.Error == "" {
			t.Errorf("skipped %s without a reason", s.Address)
		}
		skipped = append(skipped, s.Address)
	}
	if strings.Join(skipped, " ") != "not-an-ip 127.0.0.1 198.51.100.9 45.155.205.233 192.0.2.1" {
		t.Fatalf("skipped = %+v", res.Skipped)
	}
	if !strings.Contains(res.Skipped[2].Error, "overlaps 198.51.100.0/24") {
		t.Errorf("overlap reason = %q", res.Skipped[2].Error)
	}
	s := scripts(sim)
	if len(s) != 1 {
		t.Fatalf("nft ran %d scripts, want 1: %q", len(s), s)
	}
	for _, want := range []string{
		"add element inet serfex blocked4 { 45.155.205.233 timeout 3600s }",
		"add element inet serfex blocked4 { 198.51.100.0/24 }\n",
		"add element inet serfex blocked6 { 2001:db8::1 timeout 60s }",
	} {
		if !strings.Contains(s[0], want) {
			t.Errorf("script lacks %q:\n%s", want, s[0])
		}
	}
	if *changes != 1 {
		t.Errorf("Changed ran %d times, want 1", *changes)
	}
	if list, _ := m.List(); len(list) != 3 {
		t.Fatalf("list = %+v", list)
	}
}

func TestBlockManyUpdatesAStoredAddressAndSkipsOverlapsWithStoredBlocks(t *testing.T) {
	m, _, _ := newTest(t)
	ctx := context.Background()
	if _, err := m.Block(ctx, Spec{Address: "192.0.2.0/24", Seconds: 60, Reason: "old"}); err != nil {
		t.Fatal(err)
	}
	res, err := m.BlockMany(ctx, []Spec{
		{Address: "192.0.2.0/24", Seconds: 7200, Reason: "longer"},
		{Address: "192.0.2.9"},
	})
	if err != nil {
		t.Fatal(err)
	}
	if len(res.Blocked) != 1 || len(res.Skipped) != 1 || !strings.Contains(res.Skipped[0].Error, "overlaps 192.0.2.0/24, which is already blocked") {
		t.Fatalf("result = %+v", res)
	}
	list, _ := m.List()
	if len(list) != 1 || list[0].Reason != "longer" {
		t.Fatalf("list = %+v", list)
	}
}

func TestBlockManyRefusesMoreThanTheLimit(t *testing.T) {
	m, sim, _ := newTest(t)
	specs := make([]Spec, MaxBatch+1)
	for i := range specs {
		specs[i] = Spec{Address: netip.AddrFrom4([4]byte{198, 51, byte(i >> 8), byte(i)}).String()}
	}
	_, err := m.BlockMany(context.Background(), specs)
	var v *ValidationError
	if !errors.As(err, &v) {
		t.Fatalf("err = %v, want a validation error", err)
	}
	if len(scripts(sim)) != 0 {
		t.Fatal("nft ran")
	}
	res, err := m.BlockMany(context.Background(), specs[:MaxBatch])
	if err != nil || len(res.Blocked) != MaxBatch || len(scripts(sim)) != 1 {
		t.Fatalf("full batch: %d blocked, %d scripts, %v", len(res.Blocked), len(scripts(sim)), err)
	}
}

func TestBlockManyStoresNothingWhenNftFails(t *testing.T) {
	m, sim, _ := newTest(t)
	changes := watchChanges(m)
	sim.Fail = func(c host.Command) error {
		if c.Name == "nft" && len(c.Args) > 0 && c.Args[0] == "-f" {
			return errors.New("netlink: Error: Could not process rule")
		}
		return nil
	}
	_, err := m.BlockMany(context.Background(), []Spec{{Address: "192.0.2.1"}, {Address: "192.0.2.2"}})
	var op *OperationError
	if !errors.As(err, &op) {
		t.Fatalf("err = %v, want an operation error", err)
	}
	if list, _ := m.List(); len(list) != 0 || *changes != 0 {
		t.Fatalf("list = %+v, changes = %d", list, *changes)
	}
}

func TestBlockManyWithNothingToBlockRunsNothing(t *testing.T) {
	m, sim, _ := newTest(t)
	changes := watchChanges(m)
	res, err := m.BlockMany(context.Background(), []Spec{{Address: "bogus"}})
	if err != nil || len(res.Blocked) != 0 || len(res.Skipped) != 1 {
		t.Fatalf("result = %+v, %v", res, err)
	}
	if len(sim.Commands()) != 0 || *changes != 0 {
		t.Fatalf("commands = %v, changes = %d", sim.Commands(), *changes)
	}
}

func TestUnblockManyRemovesInOneScript(t *testing.T) {
	m, sim, _ := newTest(t)
	ctx := context.Background()
	if _, err := m.BlockMany(ctx, []Spec{{Address: "192.0.2.1"}, {Address: "192.0.2.2"}, {Address: "2001:db8::2"}, {Address: "192.0.2.3"}}); err != nil {
		t.Fatal(err)
	}
	changes := watchChanges(m)
	before := len(scripts(sim))
	n, err := m.UnblockMany(ctx, []string{"192.0.2.1", "2001:DB8::2", "192.0.2.200", "junk", "192.0.2.1"})
	if err != nil || n != 2 {
		t.Fatalf("removed = %d, %v", n, err)
	}
	s := scripts(sim)
	if len(s) != before+1 {
		t.Fatalf("nft ran %d scripts, want 1", len(s)-before)
	}
	last := s[len(s)-1]
	if !strings.Contains(last, "delete element inet serfex blocked4 { 192.0.2.1 }") || !strings.Contains(last, "delete element inet serfex blocked6 { 2001:db8::2 }") || strings.Contains(last, "junk") {
		t.Fatalf("script:\n%s", last)
	}
	list, _ := m.List()
	if len(list) != 2 || *changes != 1 {
		t.Fatalf("list = %+v, changes = %d", list, *changes)
	}
	if n, err := m.UnblockMany(ctx, []string{"192.0.2.200"}); err != nil || n != 0 || *changes != 1 {
		t.Fatalf("nothing to remove: %d, %v, changes %d", n, err, *changes)
	}
}

func TestSetAllowedReplacesTheListNormalisedAndSorted(t *testing.T) {
	m, sim, _ := newTest(t)
	changes := watchChanges(m)
	ctx := context.Background()
	got, err := m.SetAllowed(ctx, []string{"198.51.100.7", "2001:DB8:1::/48", "192.0.2.50", " 192.0.2.50 ", "::ffff:192.0.2.60", "192.0.2.77/24"})
	if err != nil {
		t.Fatal(err)
	}
	if strings.Join(got, " ") != "192.0.2.0/24 192.0.2.50 192.0.2.60 198.51.100.7 2001:db8:1::/48" {
		t.Fatalf("allowed = %v", got)
	}
	s := scripts(sim)
	last := s[len(s)-1]
	for _, want := range []string{
		"flush set inet serfex allowed4\n", "flush set inet serfex allowed6\n",
		"add element inet serfex allowed4 { 192.0.2.0/24, 198.51.100.7 }",
		"add element inet serfex allowed6 { 2001:db8:1::/48 }",
	} {
		if !strings.Contains(last, want) {
			t.Errorf("script lacks %q:\n%s", want, last)
		}
	}
	if stored, err := m.Allowed(); err != nil || strings.Join(stored, " ") != strings.Join(got, " ") {
		t.Fatalf("stored = %v, %v", stored, err)
	}
	if *changes != 1 {
		t.Errorf("Changed ran %d times, want 1", *changes)
	}

	if got, err := m.SetAllowed(ctx, nil); err != nil || len(got) != 0 {
		t.Fatalf("cleared = %v, %v", got, err)
	}
	s = scripts(sim)
	if last := s[len(s)-1]; !strings.Contains(last, "flush set inet serfex allowed4") || strings.Contains(last, "add element") {
		t.Fatalf("clearing script:\n%s", last)
	}
	if stored, _ := m.Allowed(); len(stored) != 0 {
		t.Fatalf("stored after clearing = %v", stored)
	}
}

func TestSetAllowedRefusesWhatCannotBeAllowed(t *testing.T) {
	m, sim, _ := newTest(t)
	ctx := context.Background()
	for _, a := range []string{"10.0.0.0/8", "2001:db8::/32", "127.0.0.1", "::1", "0.0.0.0", "224.0.0.1", "nope", "fe80::1%eth0"} {
		_, err := m.SetAllowed(ctx, []string{"192.0.2.1", a})
		var v *ValidationError
		if !errors.As(err, &v) {
			t.Errorf("%s: err = %v, want a validation error", a, err)
		}
	}
	many := make([]string, MaxBatch+1)
	for i := range many {
		many[i] = netip.AddrFrom4([4]byte{198, 51, byte(i >> 8), byte(i)}).String()
	}
	if _, err := m.SetAllowed(ctx, many); err == nil {
		t.Error("a list over the limit was accepted")
	}
	if stored, _ := m.Allowed(); len(stored) != 0 || len(scripts(sim)) != 0 {
		t.Fatalf("stored = %v, scripts = %q", stored, scripts(sim))
	}
}

func TestSetAllowedStoresNothingWhenNftFails(t *testing.T) {
	m, sim, _ := newTest(t)
	changes := watchChanges(m)
	sim.Fail = func(c host.Command) error {
		if c.Name == "nft" && len(c.Args) > 0 && c.Args[0] == "-f" {
			return errors.New("netlink: Error")
		}
		return nil
	}
	_, err := m.SetAllowed(context.Background(), []string{"192.0.2.1"})
	var op *OperationError
	if !errors.As(err, &op) {
		t.Fatalf("err = %v, want an operation error", err)
	}
	if stored, _ := m.Allowed(); len(stored) != 0 || *changes != 0 {
		t.Fatalf("stored = %v, changes = %d", stored, *changes)
	}
}

func TestAnAllowedAddressCannotBeBlockedButAWiderBlockAroundItCan(t *testing.T) {
	m, _, _ := newTest(t)
	ctx := context.Background()
	if _, err := m.SetAllowed(ctx, []string{"192.0.2.50", "198.51.100.0/24"}); err != nil {
		t.Fatal(err)
	}
	for _, a := range []string{"192.0.2.50", "198.51.100.9", "198.51.100.0/24", "198.51.100.128/25"} {
		_, err := m.Block(ctx, Spec{Address: a})
		var v *ValidationError
		if !errors.As(err, &v) || !strings.Contains(err.Error(), "it is in the allowed list (") {
			t.Errorf("%s: err = %v", a, err)
		}
	}
	if _, err := m.Block(ctx, Spec{Address: "192.0.2.0/24"}); err != nil {
		t.Fatalf("a prefix around an allowed address: %v", err)
	}
	res, err := m.BlockMany(ctx, []Spec{{Address: "198.51.100.200"}, {Address: "198.51.0.0/16"}})
	if err != nil || len(res.Blocked) != 1 || res.Blocked[0].Address != "198.51.0.0/16" ||
		len(res.Skipped) != 1 || !strings.Contains(res.Skipped[0].Error, "allowed list (198.51.100.0/24)") {
		t.Fatalf("batch = %+v, %v", res, err)
	}
}

func TestTheTableAcceptsAllowedAddressesBeforeItDrops(t *testing.T) {
	m, sim, _ := newTest(t)
	ctx := context.Background()
	if _, err := m.SetAllowed(ctx, []string{"192.0.2.50", "2001:db8:1::/48"}); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Block(ctx, Spec{Address: "192.0.2.0/24", Seconds: 3600}); err != nil {
		t.Fatal(err)
	}
	if err := m.Restore(ctx); err != nil {
		t.Fatal(err)
	}
	s := scripts(sim)
	last := s[len(s)-1]
	accept, drop := strings.Index(last, "ip saddr @allowed4 accept"), strings.Index(last, "ip saddr @blocked4 counter drop")
	accept6, drop6 := strings.Index(last, "ip6 saddr @allowed6 accept"), strings.Index(last, "ip6 saddr @blocked6 counter drop")
	if accept < 0 || drop < accept || accept6 < 0 || drop6 < accept6 {
		t.Fatalf("accept must come before drop:\n%s", last)
	}
	for _, want := range []string{
		"set allowed4 {", "set allowed6 {",
		"add element inet serfex allowed4 { 192.0.2.50 }",
		"add element inet serfex allowed6 { 2001:db8:1::/48 }",
		"add element inet serfex blocked4 { 192.0.2.0/24 timeout 3600s }",
	} {
		if !strings.Contains(last, want) {
			t.Errorf("restore script lacks %q:\n%s", want, last)
		}
	}
}

func TestRestoreBringsBackAllowedAddressesWhenNothingIsBlocked(t *testing.T) {
	m, sim, _ := newTest(t)
	ctx := context.Background()
	if _, err := m.SetAllowed(ctx, []string{"192.0.2.50"}); err != nil {
		t.Fatal(err)
	}
	before := len(scripts(sim))
	if err := m.Restore(ctx); err != nil {
		t.Fatal(err)
	}
	s := scripts(sim)
	if len(s) != before+1 || !strings.Contains(s[len(s)-1], "add element inet serfex allowed4 { 192.0.2.50 }") {
		t.Fatalf("restore scripts = %q", s[before:])
	}
}

// A table made by an agent from before the allowed and country sets has
// no "country" chain: the first change rebuilds it, and every stored block
// comes back.
func TestATableWithoutTheNewestSetsIsRebuiltWithEveryStoredBlock(t *testing.T) {
	m, sim, now := newTest(t)
	ctx := context.Background()
	if _, err := m.BlockMany(ctx, []Spec{{Address: "192.0.2.1"}, {Address: "192.0.2.2", Seconds: 3600}, {Address: "2001:db8::9", Seconds: 600}}); err != nil {
		t.Fatal(err)
	}
	*now = now.Add(10 * time.Minute) // the IPv6 block has run out
	var probes []string
	sim.Fail = func(c host.Command) error {
		if c.Name == "nft" && len(c.Args) > 0 && c.Args[0] == "list" {
			probes = append(probes, strings.Join(c.Args, " "))
			if c.Args[len(c.Args)-1] == "country" {
				return errors.New("Error: No such file or directory")
			}
		}
		return nil
	}
	before := len(scripts(sim))
	if _, err := m.Block(ctx, Spec{Address: "192.0.2.3", Seconds: 60}); err != nil {
		t.Fatal(err)
	}
	if len(probes) == 0 || probes[0] != "list chain inet serfex country" {
		t.Fatalf("probes = %q", probes)
	}
	s := scripts(sim)[before:]
	if len(s) != 2 {
		t.Fatalf("scripts = %q", s)
	}
	for _, want := range []string{
		"delete table inet serfex", "set allowed6 {", "set country6 {",
		"add element inet serfex blocked4 { 192.0.2.1 }\n",
		"add element inet serfex blocked4 { 192.0.2.2 timeout 3000s }",
	} {
		if !strings.Contains(s[0], want) {
			t.Errorf("rebuild lacks %q:\n%s", want, s[0])
		}
	}
	if strings.Contains(s[0], "2001:db8::9") {
		t.Errorf("a block that ran out came back:\n%s", s[0])
	}
	if !strings.Contains(s[1], "{ 192.0.2.3 timeout 60s }") {
		t.Errorf("new block script:\n%s", s[1])
	}
}

func prefixes(list ...string) []netip.Prefix {
	out := make([]netip.Prefix, len(list))
	for i, p := range list {
		out[i] = netip.MustParsePrefix(p)
	}
	return out
}

func TestSetCountriesFillsBothSetsInOneTransaction(t *testing.T) {
	m, sim, _ := newTest(t)
	changes := watchChanges(m)
	if err := m.SetCountries(context.Background(), prefixes("1.0.1.0/24", "1.0.2.0/23"), prefixes("2001:db8::/31"), nil); err != nil {
		t.Fatal(err)
	}
	s := scripts(sim)
	if len(s) != 1 {
		t.Fatalf("scripts = %q", s)
	}
	for _, want := range []string{
		"flush set inet serfex country4\n", "flush set inet serfex country6\n",
		"add element inet serfex country4 { 1.0.1.0/24, 1.0.2.0/23 }\n",
		"add element inet serfex country6 { 2001:db8::/31 }\n",
	} {
		if !strings.Contains(s[0], want) {
			t.Errorf("script lacks %q:\n%s", want, s[0])
		}
	}
	// The blocked addresses did not change: Nginx's deny lines stay.
	if *changes != 0 {
		t.Fatalf("Changed ran %d times", *changes)
	}
}

func TestSetCountriesWithNothingAndNoTableRunsNothing(t *testing.T) {
	m, sim, _ := newTest(t)
	sim.Fail = func(c host.Command) error {
		if c.Name == "nft" && len(c.Args) > 0 && c.Args[0] == "list" {
			return errors.New("No such file or directory")
		}
		return nil
	}
	if err := m.SetCountries(context.Background(), nil, nil, nil); err != nil {
		t.Fatal(err)
	}
	if s := scripts(sim); len(s) != 0 {
		t.Fatalf("a server that blocks no country got a table: %q", s)
	}
}

func TestSetCountriesEmptiesTheSets(t *testing.T) {
	m, sim, _ := newTest(t)
	ctx := context.Background()
	if err := m.SetCountries(ctx, prefixes("1.0.1.0/24"), prefixes("2001:db8::/32"), nil); err != nil {
		t.Fatal(err)
	}
	if err := m.SetCountries(ctx, nil, nil, nil); err != nil {
		t.Fatal(err)
	}
	s := scripts(sim)
	last := s[len(s)-1]
	if !strings.Contains(last, "flush set inet serfex country4\n") || !strings.Contains(last, "flush set inet serfex country6\n") || strings.Contains(last, "add element") {
		t.Fatalf("emptying script:\n%s", last)
	}
	// The rules go with the last country, and connection tracking with them.
	if !strings.Contains(last, "flush chain inet serfex country\n") || strings.Contains(last, "add rule") {
		t.Fatalf("the country rules stayed:\n%s", last)
	}
}

func TestCountrySetsComeBackWhenTheTableIsRebuilt(t *testing.T) {
	m, sim, _ := newTest(t)
	ctx := context.Background()
	if err := m.SetCountries(ctx, prefixes("1.0.1.0/24"), prefixes("2001:db8::/32"), nil); err != nil {
		t.Fatal(err)
	}
	// Someone flushed the ruleset: the next change finds no table.
	sim.Fail = func(c host.Command) error {
		if c.Name == "nft" && len(c.Args) > 0 && c.Args[0] == "list" {
			return errors.New("No such file or directory")
		}
		return nil
	}
	before := len(scripts(sim))
	if _, err := m.Block(ctx, Spec{Address: "192.0.2.1", Seconds: 60}); err != nil {
		t.Fatal(err)
	}
	rebuilt := scripts(sim)[before]
	for _, want := range []string{
		"add element inet serfex country4 { 1.0.1.0/24 }\n",
		"add element inet serfex country6 { 2001:db8::/32 }\n",
		"add rule inet serfex country ip saddr @country4 ct state new tcp dport != { 25, 53, 80, 443 } counter drop\n",
	} {
		if !strings.Contains(rebuilt, want) {
			t.Errorf("rebuilt table lacks %q:\n%s", want, rebuilt)
		}
	}
	// Restore, when the agent starts, brings them back too.
	if err := m.Restore(ctx); err != nil {
		t.Fatal(err)
	}
	s := scripts(sim)
	if !strings.Contains(s[len(s)-1], "add element inet serfex country4 { 1.0.1.0/24 }\n") {
		t.Errorf("restore lacks the country set:\n%s", s[len(s)-1])
	}
}

func TestCountryRulesLeaveWebMailAndDNSOpenAndOnlyStopNewConnections(t *testing.T) {
	m, sim, _ := newTest(t)
	sim.Fail = func(c host.Command) error {
		if c.Name == "nft" && len(c.Args) > 0 && c.Args[0] == "list" {
			return errors.New("No such file or directory")
		}
		return nil
	}
	if err := m.SetCountries(context.Background(), prefixes("1.0.1.0/24"), nil, nil); err != nil {
		t.Fatal(err)
	}
	s := scripts(sim)
	if len(s) != 2 {
		t.Fatalf("scripts = %q", s)
	}
	inOrder(t, s[0], []string{
		// The server talking to itself is never judged.
		`iifname "lo" accept`,
		"ip saddr @allowed4 accept",
		"ip6 saddr @allowed6 accept",
		"ip saddr @blocked4 counter drop",
		"ip6 saddr @blocked6 counter drop",
		"jump country",
	})
	for _, want := range []string{"set country4 {", "set country6 {", "set countrypass4 {", "set countrypass6 {", "chain country {"} {
		if !strings.Contains(s[0], want) {
			t.Errorf("table lacks %q", want)
		}
	}
	inOrder(t, s[1], []string{
		"flush chain inet serfex country\n",
		"add element inet serfex country4 { 1.0.1.0/24 }\n",
		// Web sites are refused by Nginx (403, also behind Cloudflare, and
		// Let's Encrypt still gets through); incoming mail and DNS stay
		// open. Only new connections are stopped: answers to what the
		// server itself asked for come back.
		"add rule inet serfex country ip saddr @country4 ct state new tcp dport != { 25, 53, 80, 443 } counter drop\n",
		"add rule inet serfex country ip saddr @country4 ct state new udp dport != 53 counter drop\n",
		"add rule inet serfex country ip6 saddr @country6 ct state new tcp dport != { 25, 53, 80, 443 } counter drop\n",
		"add rule inet serfex country ip6 saddr @country6 ct state new udp dport != 53 counter drop\n",
	})
}

// A rule that reads the connection's state makes the kernel track every
// connection of the server. A server that blocks no country must not pay
// for that, nor change at all because the agent was updated.
func TestATableWithoutCountriesTracksNoConnections(t *testing.T) {
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
	for _, script := range scripts(sim) {
		if strings.Contains(script, "ct state") || strings.Contains(script, "add rule") {
			t.Fatalf("a server that blocks no country got the country rules:\n%s", script)
		}
	}
}

// Cloudflare's proxy ranges and the server's own addresses are let
// through before the country is judged, on every port.
func TestPassAddressesAreLetThroughBeforeTheCountryRules(t *testing.T) {
	m, sim, _ := newTest(t)
	ctx := context.Background()
	if err := m.SetCountries(ctx, prefixes("1.0.1.0/24"), prefixes("2001:db8::/32"), prefixes("173.245.48.0/20", "2400:cb00::/32", "203.0.113.10/32")); err != nil {
		t.Fatal(err)
	}
	s := scripts(sim)
	inOrder(t, s[len(s)-1], []string{
		"flush set inet serfex countrypass4\n",
		"flush set inet serfex countrypass6\n",
		"add element inet serfex countrypass4 { 173.245.48.0/20, 203.0.113.10/32 }\n",
		"add element inet serfex countrypass6 { 2400:cb00::/32 }\n",
		"add rule inet serfex country ip saddr @countrypass4 return\n",
		"add rule inet serfex country ip6 saddr @countrypass6 return\n",
		"add rule inet serfex country ip saddr @country4 ct state new tcp dport",
	})
	// With no country left the pass sets are emptied as well.
	if err := m.SetCountries(ctx, nil, nil, prefixes("173.245.48.0/20")); err != nil {
		t.Fatal(err)
	}
	s = scripts(sim)
	if last := s[len(s)-1]; strings.Contains(last, "add element") || strings.Contains(last, "add rule") {
		t.Fatalf("pass addresses stayed without a country:\n%s", last)
	}
	// A rebuilt table lets them through again.
	if err := m.SetCountries(ctx, prefixes("1.0.1.0/24"), nil, prefixes("173.245.48.0/20")); err != nil {
		t.Fatal(err)
	}
	if err := m.Restore(ctx); err != nil {
		t.Fatal(err)
	}
	s = scripts(sim)
	inOrder(t, s[len(s)-1], []string{
		"add element inet serfex countrypass4 { 173.245.48.0/20 }\n",
		"add rule inet serfex country ip saddr @countrypass4 return\n",
		"add rule inet serfex country ip saddr @country4 ct state new tcp dport",
	})
}

func inOrder(t *testing.T, script string, order []string) {
	t.Helper()
	at := -1
	for _, want := range order {
		i := strings.Index(script, want)
		if i < 0 || i < at {
			t.Fatalf("%q is missing or out of order:\n%s", want, script)
		}
		at = i
	}
}

func TestManyCountryPrefixesAreSplitIntoStatements(t *testing.T) {
	m, sim, _ := newTest(t)
	list := make([]netip.Prefix, 0, 5000)
	for i := 0; i < 5000; i++ {
		list = append(list, netip.PrefixFrom(netip.AddrFrom4([4]byte{11, byte(i >> 8), byte(i), 0}), 32))
	}
	if err := m.SetCountries(context.Background(), list, nil, nil); err != nil {
		t.Fatal(err)
	}
	s := scripts(sim)
	if len(s) != 1 {
		t.Fatalf("%d nft runs, want one transaction", len(s))
	}
	if n := strings.Count(s[0], "add element inet serfex country4 {"); n != 3 {
		t.Fatalf("%d statements for 5,000 prefixes, want 3", n)
	}
	if n := strings.Count(s[0], "/32"); n != 5000 {
		t.Fatalf("%d prefixes in the script", n)
	}
}

func TestSetCountriesKeepsTheOldSetsWhenNftFails(t *testing.T) {
	m, sim, _ := newTest(t)
	ctx := context.Background()
	if err := m.SetCountries(ctx, prefixes("1.0.1.0/24"), nil, nil); err != nil {
		t.Fatal(err)
	}
	sim.Fail = func(c host.Command) error {
		if c.Name == "nft" && len(c.Args) == 2 && c.Args[0] == "-f" && strings.Contains(string(c.Stdin), "9.9.9.0/24") {
			return errors.New("Error: Could not process rule")
		}
		return nil
	}
	err := m.SetCountries(ctx, prefixes("9.9.9.0/24"), nil, nil)
	var op *OperationError
	if !errors.As(err, &op) {
		t.Fatalf("err = %v, want an operation error", err)
	}
	// A rebuild still holds what is in force, not what was refused.
	sim.Fail = nil
	if err := m.Restore(ctx); err != nil {
		t.Fatal(err)
	}
	s := scripts(sim)
	if last := s[len(s)-1]; strings.Contains(last, "9.9.9.0/24") {
		t.Fatalf("a refused set came back:\n%s", last)
	}
}
