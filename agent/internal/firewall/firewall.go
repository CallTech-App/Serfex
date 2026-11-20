// Package firewall blocks IP addresses with nftables.
//
// Serfex keeps its own table so the distribution's firewall (ufw,
// iptables-nft, a hand-written ruleset) stays untouched:
//
//	table inet serfex
//	  set allowed4, allowed6   addresses and prefixes that are never dropped here
//	  set blocked4, blocked6   addresses and prefixes, each with its own timeout
//	  set country4, country6   the prefixes of blocked countries, when the
//	                           country block covers the whole server
//	  chain input              accepts the allowed sets, then drops the blocked
//	                           ones, before other filters
//	/etc/serfex/firewall/blocks.json    the blocks as last applied, root 0600
//	/etc/serfex/firewall/allowed.json   the allowed addresses, root 0600
//
// "accept" ends this chain only: the port filter and Fail2ban's own table
// still see the packet, so an allowed address is exempt from Serfex's
// blocks, not from closed ports.
//
// A blocked country is treated more gently than a blocked address. Only
// new connections are dropped, so answers to what the server itself asked
// for still arrive, and four ports stay open: 80 and 443, where Nginx
// refuses the country's visitors itself (it also sees them behind
// Cloudflare's proxy, and lets Let's Encrypt's validation through), 25,
// so mail from the country's mail servers is not lost, and 53, so the
// names hosted here still resolve for them. The country sets are filled by
// the geoblock package and are not stored here: they are worked out again
// from the country table.
//
// A block is time-boxed by the kernel itself (the set element's timeout),
// so it ends on time even while the agent or the panel is down. The state
// file only brings the blocks back after a reboot, when the agent starts.
package firewall

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/netip"
	"os"
	"sort"
	"strings"
	"sync"
	"time"

	"github.com/calltech-app/serfex/agent/internal/cloudflare"
	"github.com/calltech-app/serfex/agent/internal/host"
)

const (
	stateDir    = "/etc/serfex/firewall"
	stateFile   = stateDir + "/blocks.json"
	allowedFile = stateDir + "/allowed.json"

	// Table is the nftables table Serfex owns.
	Table = "serfex"

	// MaxSeconds is the longest timed block; longer ones are permanent.
	MaxSeconds = 365 * 24 * 3600

	// MaxBatch is how many addresses one BlockMany, UnblockMany or
	// SetAllowed call takes.
	MaxBatch = 1000

	// OpenPorts are the TCP ports a blocked country still reaches when
	// its block covers the whole server.
	OpenPorts = "25, 53, 80, 443"

	// countryChunk is how many prefixes one "add element" statement of a
	// country set holds.
	countryChunk = 2000
)

// ruleset creates the table from scratch. "add table" before "delete
// table" makes the delete work whether or not the table exists.
const ruleset = `add table inet serfex
delete table inet serfex
table inet serfex {
	set allowed4 {
		type ipv4_addr
		flags interval
		auto-merge
	}
	set allowed6 {
		type ipv6_addr
		flags interval
		auto-merge
	}
	set blocked4 {
		type ipv4_addr
		flags interval, timeout
	}
	set blocked6 {
		type ipv6_addr
		flags interval, timeout
	}
	set country4 {
		type ipv4_addr
		flags interval
		auto-merge
	}
	set country6 {
		type ipv6_addr
		flags interval
		auto-merge
	}
	chain input {
		type filter hook input priority filter - 10; policy accept;
		iifname "lo" accept
		ip saddr @allowed4 accept
		ip6 saddr @allowed6 accept
		ip saddr @blocked4 counter drop
		ip6 saddr @blocked6 counter drop
		ip saddr @country4 ct state new tcp dport != { ` + OpenPorts + ` } counter drop
		ip saddr @country4 ct state new udp dport != 53 counter drop
		ip6 saddr @country6 ct state new tcp dport != { ` + OpenPorts + ` } counter drop
		ip6 saddr @country6 ct state new udp dport != 53 counter drop
	}
}
`

// ValidationError reports a request that can never succeed as given.
type ValidationError struct{ msg string }

func (e *ValidationError) Error() string { return e.msg }

func invalid(format string, a ...any) error { return &ValidationError{fmt.Sprintf(format, a...)} }

// OperationError wraps a failure while changing the firewall.
type OperationError struct{ Err error }

func (e *OperationError) Error() string { return e.Err.Error() }
func (e *OperationError) Unwrap() error { return e.Err }

// Spec asks for an address to be blocked.
type Spec struct {
	// Address is an IPv4 or IPv6 address, or a prefix such as
	// 203.0.113.0/24 (at least /16 for IPv4, /48 for IPv6).
	Address string `json:"address"`
	// Seconds is how long the block lasts; 0 blocks until it is removed.
	Seconds int `json:"seconds"`
	// Reason is shown next to the block.
	Reason string `json:"reason"`
}

// Block is an address being blocked.
type Block struct {
	Address string     `json:"address"`
	Reason  string     `json:"reason"`
	Created time.Time  `json:"created_at"`
	Expires *time.Time `json:"expires_at"`
}

// Manager changes the blocks. Operations are serialised.
type Manager struct {
	h  host.Host
	mu sync.Mutex
	// Now is the clock; tests replace it.
	Now func() time.Time
	// Protected returns addresses that must never be blocked (the
	// server's own); loopback, unspecified and multicast always are.
	Protected func() []netip.Addr
	// Changed, when set, runs after the blocks changed (the edge package
	// mirrors them into Nginx for visitors behind Cloudflare). Its
	// failure does not undo the block.
	Changed func(ctx context.Context)

	// The country sets as last applied: they refill the table whenever
	// it is rebuilt.
	country4, country6 []netip.Prefix
}

// NewManager returns a Manager acting on h.
func NewManager(h host.Host) *Manager {
	return &Manager{h: h, Now: time.Now}
}

// Normalize parses an address or prefix and returns it in canonical form
// ("2001:db8::1", "203.0.113.0/24"); a prefix covering one address becomes
// that address.
func Normalize(s string) (netip.Prefix, error) {
	s = strings.TrimSpace(s)
	var p netip.Prefix
	if strings.Contains(s, "/") {
		pp, err := netip.ParsePrefix(s)
		if err != nil {
			return netip.Prefix{}, invalid("%q is not an IP address or prefix", s)
		}
		p = pp.Masked()
	} else {
		a, err := netip.ParseAddr(s)
		if err != nil || a.Zone() != "" {
			return netip.Prefix{}, invalid("%q is not an IP address", s)
		}
		p = netip.PrefixFrom(a.Unmap(), a.Unmap().BitLen())
	}
	if p.Addr().Is4In6() {
		p = netip.PrefixFrom(p.Addr().Unmap(), p.Bits()-96)
	}
	return p, nil
}

func format(p netip.Prefix) string {
	if p.IsSingleIP() {
		return p.Addr().String()
	}
	return p.String()
}

// shape refuses a prefix that may be neither blocked nor allowed: one that
// is too wide, or a loopback, unspecified or multicast address. verb is
// "block" or "allow".
func shape(p netip.Prefix, verb string) error {
	a := p.Addr()
	if a.Is4() && p.Bits() < 16 || a.Is6() && p.Bits() < 48 {
		return invalid("%s is too wide; %s at most a /16 (IPv4) or /48 (IPv6)", format(p), verb)
	}
	for _, bad := range []netip.Prefix{
		netip.MustParsePrefix("127.0.0.0/8"), netip.MustParsePrefix("::1/128"),
		netip.MustParsePrefix("0.0.0.0/32"), netip.MustParsePrefix("::/128"),
		netip.MustParsePrefix("224.0.0.0/4"), netip.MustParsePrefix("ff00::/8"),
	} {
		if bad.Overlaps(p) {
			return invalid("%s cannot be %sed: it is a loopback, unspecified or multicast address", format(p), verb)
		}
	}
	return nil
}

// guard is what a block is checked against, read once per call.
type guard struct {
	cloudflare []netip.Prefix
	own        []netip.Addr
	allowed    []netip.Prefix
}

func (m *Manager) guard(allowed []netip.Prefix) guard {
	g := guard{cloudflare: cloudflare.Ranges(m.h), allowed: allowed}
	if m.Protected != nil {
		g.own = m.Protected()
	}
	return g
}

func (g guard) check(p netip.Prefix) error {
	if err := shape(p, "block"); err != nil {
		return err
	}
	// Visitors behind Cloudflare's proxy reach this server from
	// Cloudflare's addresses: blocking one would block them all.
	if r, ok := cloudflare.Overlaps(g.cloudflare, p); ok {
		return invalid("%s cannot be blocked: it is in Cloudflare's proxy range %s, which visitors of sites behind Cloudflare come from", format(p), r)
	}
	for _, own := range g.own {
		if p.Contains(own.Unmap()) {
			return invalid("%s cannot be blocked: it is this server's own address %s", format(p), own)
		}
	}
	// A block inside an allowed entry would never match. A wider block
	// around one is fine: the allowed addresses in it stay reachable.
	for _, a := range g.allowed {
		if a.Bits() <= p.Bits() && a.Overlaps(p) {
			return invalid("%s cannot be blocked: it is in the allowed list (%s)", format(p), format(a))
		}
	}
	return nil
}

// checkSpec refuses a duration or reason that cannot be stored.
func checkSpec(spec Spec) error {
	if spec.Seconds < 0 || spec.Seconds > MaxSeconds {
		return invalid("seconds must be between 0 (until removed) and %d", MaxSeconds)
	}
	if len(spec.Reason) > 200 {
		return invalid("reason is longer than 200 characters")
	}
	return nil
}

// newBlock is the block spec asks for, starting now.
func newBlock(p netip.Prefix, spec Spec, now time.Time) Block {
	b := Block{Address: format(p), Reason: strings.TrimSpace(spec.Reason), Created: now}
	if spec.Seconds > 0 {
		exp := now.Add(time.Duration(spec.Seconds) * time.Second)
		b.Expires = &exp
	}
	return b
}

// replaceElement puts b into its set whether or not the address was there:
// add + delete + add, in one transaction, so the new timeout always applies.
func replaceElement(p netip.Prefix, b Block, now time.Time) string {
	return fmt.Sprintf("add element inet %[1]s %[2]s { %[3]s }\ndelete element inet %[1]s %[2]s { %[3]s }\nadd element inet %[1]s %[2]s { %[3]s%[4]s }\n",
		Table, setFor(p), b.Address, timeout(b, now))
}

// removeElement takes an address out of its set. The kernel may have
// dropped the element already; add-then-delete removes it either way.
func removeElement(p netip.Prefix) string {
	return fmt.Sprintf("add element inet %[1]s %[2]s { %[3]s }\ndelete element inet %[1]s %[2]s { %[3]s }\n", Table, setFor(p), format(p))
}

func (m *Manager) nft(ctx context.Context, script string) error {
	_, err := m.h.Run(ctx, host.Command{Name: "nft", Args: []string{"-f", "-"}, Stdin: []byte(script)})
	return err
}

// Block blocks an address, or changes the duration and reason of an
// address already blocked.
func (m *Manager) Block(ctx context.Context, spec Spec) (Block, error) {
	p, err := Normalize(spec.Address)
	if err != nil {
		return Block{}, err
	}
	if err := checkSpec(spec); err != nil {
		return Block{}, err
	}
	changed := false
	defer m.notify(ctx, &changed) // runs after the lock is released
	m.mu.Lock()
	defer m.mu.Unlock()

	blocks, allowed, err := m.state()
	if err != nil {
		return Block{}, &OperationError{err}
	}
	if err := m.guard(allowed).check(p); err != nil {
		return Block{}, err
	}
	addr := format(p)
	for _, b := range blocks {
		bp, _ := Normalize(b.Address)
		if b.Address != addr && bp.Overlaps(p) {
			return Block{}, invalid("%s overlaps %s, which is already blocked; remove that block first", addr, b.Address)
		}
	}
	if err := m.ensure(ctx, blocks, allowed); err != nil {
		return Block{}, &OperationError{err}
	}

	now := m.Now().UTC().Truncate(time.Second)
	b := newBlock(p, spec, now)
	if err := m.nft(ctx, replaceElement(p, b, now)); err != nil {
		return Block{}, &OperationError{err}
	}

	kept := blocks[:0]
	for _, old := range blocks {
		if old.Address != addr {
			kept = append(kept, old)
		}
	}
	if err := m.save(append(kept, b)); err != nil {
		return Block{}, &OperationError{err}
	}
	changed = true
	return b, nil
}

// Skipped is an item of a batch that was left out, and why.
type Skipped struct {
	// Address is the item as it was given.
	Address string `json:"address"`
	Error   string `json:"error"`
}

// BatchResult is what BlockMany did.
type BatchResult struct {
	Blocked []Block   `json:"blocked"`
	Skipped []Skipped `json:"skipped"`
}

// BlockMany blocks up to MaxBatch addresses in one nftables transaction,
// so the firewall changes once and Changed runs once. An item that cannot
// be blocked (not an address, protected, overlapping a block or an earlier
// item) is skipped with its reason; the others are still blocked. An
// address already blocked gets the new duration and reason. When nftables
// refuses the transaction nothing is blocked and nothing is stored.
func (m *Manager) BlockMany(ctx context.Context, specs []Spec) (BatchResult, error) {
	res := BatchResult{Blocked: []Block{}, Skipped: []Skipped{}}
	if len(specs) > MaxBatch {
		return res, invalid("at most %d addresses can be blocked at once", MaxBatch)
	}
	changed := false
	defer m.notify(ctx, &changed) // runs after the lock is released
	m.mu.Lock()
	defer m.mu.Unlock()

	blocks, allowed, err := m.state()
	if err != nil {
		return res, &OperationError{err}
	}
	stored := make([]netip.Prefix, 0, len(blocks))
	for _, b := range blocks {
		bp, _ := Normalize(b.Address)
		stored = append(stored, bp)
	}
	g := m.guard(allowed)
	now := m.Now().UTC().Truncate(time.Second)
	var script strings.Builder
	var taken []netip.Prefix
	replaced := map[string]bool{}
	for _, spec := range specs {
		skip := func(err error) {
			res.Skipped = append(res.Skipped, Skipped{Address: strings.TrimSpace(spec.Address), Error: err.Error()})
		}
		p, err := Normalize(spec.Address)
		if err == nil {
			err = checkSpec(spec)
		}
		if err == nil {
			err = g.check(p)
		}
		if err == nil {
			err = overlap(p, stored, taken)
		}
		if err != nil {
			skip(err)
			continue
		}
		b := newBlock(p, spec, now)
		script.WriteString(replaceElement(p, b, now))
		taken = append(taken, p)
		replaced[b.Address] = true
		res.Blocked = append(res.Blocked, b)
	}
	if len(res.Blocked) == 0 {
		return res, nil
	}
	if err := m.ensure(ctx, blocks, allowed); err != nil {
		return BatchResult{Blocked: []Block{}, Skipped: res.Skipped}, &OperationError{err}
	}
	if err := m.nft(ctx, script.String()); err != nil {
		return BatchResult{Blocked: []Block{}, Skipped: res.Skipped}, &OperationError{err}
	}
	kept := make([]Block, 0, len(blocks)+len(res.Blocked))
	for _, old := range blocks {
		if !replaced[old.Address] {
			kept = append(kept, old)
		}
	}
	if err := m.save(append(kept, res.Blocked...)); err != nil {
		return BatchResult{Blocked: []Block{}, Skipped: res.Skipped}, &OperationError{err}
	}
	changed = true
	return res, nil
}

// overlap refuses p when it overlaps a stored block other than itself, or
// an item taken earlier from the same batch.
func overlap(p netip.Prefix, stored, taken []netip.Prefix) error {
	for _, sp := range stored {
		if sp != p && sp.Overlaps(p) {
			return invalid("%s overlaps %s, which is already blocked; remove that block first", format(p), format(sp))
		}
	}
	for _, t := range taken {
		if t == p {
			return invalid("%s is listed more than once", format(p))
		}
		if t.Overlaps(p) {
			return invalid("%s overlaps %s, which is in the same list", format(p), format(t))
		}
	}
	return nil
}

// notify runs Changed when *changed is set.
func (m *Manager) notify(ctx context.Context, changed *bool) {
	if *changed && m.Changed != nil {
		m.Changed(context.WithoutCancel(ctx))
	}
}

// Unblock removes a block; removing an address that is not blocked (or
// whose block ran out) is not an error. It reports whether a block was
// removed.
func (m *Manager) Unblock(ctx context.Context, address string) (bool, error) {
	if _, err := Normalize(address); err != nil {
		return false, err
	}
	n, err := m.UnblockMany(ctx, []string{address})
	return n > 0, err
}

// UnblockMany removes up to MaxBatch blocks in one nftables transaction
// and reports how many were in force. Addresses that are not blocked, or
// are not addresses at all, are passed over.
func (m *Manager) UnblockMany(ctx context.Context, addresses []string) (int, error) {
	if len(addresses) > MaxBatch {
		return 0, invalid("at most %d addresses can be unblocked at once", MaxBatch)
	}
	remove := map[string]bool{}
	var script strings.Builder
	for _, a := range addresses {
		p, err := Normalize(a)
		if err != nil || remove[format(p)] {
			continue
		}
		remove[format(p)] = true
		script.WriteString(removeElement(p))
	}
	if len(remove) == 0 {
		return 0, nil
	}
	changed := false
	defer m.notify(ctx, &changed) // runs after the lock is released
	m.mu.Lock()
	defer m.mu.Unlock()

	blocks, err := m.load()
	if err != nil {
		return 0, &OperationError{err}
	}
	found := 0
	kept := blocks[:0]
	for _, b := range blocks {
		if remove[b.Address] {
			found++
			continue
		}
		kept = append(kept, b)
	}
	if err := m.nft(ctx, script.String()); err != nil {
		// No table means nothing is blocked.
		if _, lerr := m.h.Run(ctx, host.Command{Name: "nft", Args: []string{"list", "table", "inet", Table}}); lerr == nil {
			return 0, &OperationError{err}
		}
	}
	if err := m.save(kept); err != nil {
		return 0, &OperationError{err}
	}
	changed = found > 0
	return found, nil
}

// Allowed returns the allowed addresses and prefixes, sorted.
func (m *Manager) Allowed() ([]string, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	allowed, err := m.loadAllowed()
	if err != nil {
		return nil, &OperationError{err}
	}
	return formatAll(allowed), nil
}

// SetAllowed replaces the allowed list: addresses and prefixes that
// Serfex's blocks never drop, even inside a wider block. The list comes
// back normalised and sorted. One entry that cannot be allowed refuses
// the whole list, because the caller sends the list it wants in force.
func (m *Manager) SetAllowed(ctx context.Context, addresses []string) ([]string, error) {
	if len(addresses) > MaxBatch {
		return nil, invalid("at most %d addresses can be allowed", MaxBatch)
	}
	seen := map[netip.Prefix]bool{}
	list := []netip.Prefix{}
	for _, a := range addresses {
		p, err := Normalize(a)
		if err != nil {
			return nil, err
		}
		if err := shape(p, "allow"); err != nil {
			return nil, err
		}
		if !seen[p] {
			seen[p] = true
			list = append(list, p)
		}
	}
	sortPrefixes(list)

	changed := false
	defer m.notify(ctx, &changed) // runs after the lock is released
	m.mu.Lock()
	defer m.mu.Unlock()

	blocks, current, err := m.state()
	if err != nil {
		return nil, &OperationError{err}
	}
	if err := m.ensure(ctx, blocks, current); err != nil {
		return nil, &OperationError{err}
	}
	script := fmt.Sprintf("flush set inet %[1]s allowed4\nflush set inet %[1]s allowed6\n", Table) + allowedElements(list)
	if err := m.nft(ctx, script); err != nil {
		return nil, &OperationError{err}
	}
	if err := m.saveAllowed(list); err != nil {
		return nil, &OperationError{err}
	}
	changed = strings.Join(formatAll(current), " ") != strings.Join(formatAll(list), " ")
	return formatAll(list), nil
}

// allowedElements fills the allowed sets. An entry inside another one is
// left out: the wider entry already covers it.
func allowedElements(allowed []netip.Prefix) string {
	sets := map[string][]string{}
	for _, p := range allowed {
		covered := false
		for _, o := range allowed {
			covered = covered || o != p && o.Bits() <= p.Bits() && o.Overlaps(p)
		}
		if !covered {
			set := "allowed4"
			if p.Addr().Is6() {
				set = "allowed6"
			}
			sets[set] = append(sets[set], format(p))
		}
	}
	var b strings.Builder
	for _, set := range []string{"allowed4", "allowed6"} {
		if len(sets[set]) > 0 {
			fmt.Fprintf(&b, "add element inet %s %s { %s }\n", Table, set, strings.Join(sets[set], ", "))
		}
	}
	return b.String()
}

func sortPrefixes(list []netip.Prefix) {
	sort.Slice(list, func(i, j int) bool {
		if c := list[i].Addr().Compare(list[j].Addr()); c != 0 {
			return c < 0
		}
		return list[i].Bits() < list[j].Bits()
	})
}

func formatAll(list []netip.Prefix) []string {
	out := make([]string, 0, len(list))
	for _, p := range list {
		out = append(out, format(p))
	}
	return out
}

// SetCountries replaces the prefixes of the blocked countries (see the
// package comment for what is dropped) in one nftables transaction. Empty
// lists empty the sets; a server that blocks no country and has no table
// does not get one for it. When nftables refuses, the sets stay as they
// were.
func (m *Manager) SetCountries(ctx context.Context, v4, v6 []netip.Prefix) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	if len(v4)+len(v6) == 0 {
		if !m.complete(ctx) {
			m.country4, m.country6 = nil, nil
			return nil
		}
	} else {
		blocks, allowed, err := m.state()
		if err != nil {
			return &OperationError{err}
		}
		if err := m.ensure(ctx, blocks, allowed); err != nil {
			return &OperationError{err}
		}
	}
	script := fmt.Sprintf("flush set inet %[1]s country4\nflush set inet %[1]s country6\n", Table) + countryElements(v4, v6)
	if err := m.nft(ctx, script); err != nil {
		return &OperationError{err}
	}
	m.country4, m.country6 = v4, v6
	return nil
}

// countryElements fills the country sets, a few thousand prefixes to a
// statement so no line grows without bound.
func countryElements(v4, v6 []netip.Prefix) string {
	var b strings.Builder
	fill := func(set string, list []netip.Prefix) {
		for len(list) > 0 {
			n := min(len(list), countryChunk)
			fmt.Fprintf(&b, "add element inet %s %s { ", Table, set)
			for i, p := range list[:n] {
				if i > 0 {
					b.WriteString(", ")
				}
				b.WriteString(p.String())
			}
			b.WriteString(" }\n")
			list = list[n:]
		}
	}
	fill("country4", v4)
	fill("country6", v6)
	return b.String()
}

// List returns the blocks in force, newest first.
func (m *Manager) List() ([]Block, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	blocks, err := m.load()
	if err != nil {
		return nil, &OperationError{err}
	}
	sort.Slice(blocks, func(i, j int) bool { return blocks[i].Created.After(blocks[j].Created) })
	return blocks, nil
}

// Restore recreates the table with the blocks still in force and the
// allowed addresses; the agent calls it when it starts, so both survive a
// reboot.
func (m *Manager) Restore(ctx context.Context) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	blocks, allowed, err := m.state()
	if err != nil {
		return err
	}
	if len(blocks) == 0 && len(allowed) == 0 && len(m.country4)+len(m.country6) == 0 {
		return nil
	}
	return m.create(ctx, blocks, allowed)
}

// ensure creates the table, with every stored block and allowed address,
// when it is missing or incomplete: first use, someone flushed the
// ruleset, or the table was made by an agent from before the allowed and
// country sets.
func (m *Manager) ensure(ctx context.Context, blocks []Block, allowed []netip.Prefix) error {
	if m.complete(ctx) {
		return nil
	}
	return m.create(ctx, blocks, allowed)
}

// complete tells whether the table is there with all its sets (country6
// is the last set that was added to it).
func (m *Manager) complete(ctx context.Context) bool {
	_, err := m.h.Run(ctx, host.Command{Name: "nft", Args: []string{"list", "set", "inet", Table, "country6"}})
	return err == nil
}

func (m *Manager) create(ctx context.Context, blocks []Block, allowed []netip.Prefix) error {
	var b strings.Builder
	b.WriteString(ruleset)
	b.WriteString(allowedElements(allowed))
	b.WriteString(countryElements(m.country4, m.country6))
	now := m.Now()
	for _, bl := range blocks {
		p, err := Normalize(bl.Address)
		if err != nil {
			continue
		}
		fmt.Fprintf(&b, "add element inet %s %s { %s%s }\n", Table, setFor(p), bl.Address, timeout(bl, now))
	}
	if err := m.nft(ctx, b.String()); err != nil {
		return fmt.Errorf("creating the nftables table: %w", err)
	}
	return nil
}

func setFor(p netip.Prefix) string {
	if p.Addr().Is4() {
		return "blocked4"
	}
	return "blocked6"
}

func timeout(b Block, now time.Time) string {
	if b.Expires == nil {
		return ""
	}
	s := int(b.Expires.Sub(now).Round(time.Second).Seconds())
	if s < 1 {
		s = 1
	}
	return fmt.Sprintf(" timeout %ds", s)
}

// state reads both state files.
func (m *Manager) state() ([]Block, []netip.Prefix, error) {
	blocks, err := m.load()
	if err != nil {
		return nil, nil, err
	}
	allowed, err := m.loadAllowed()
	return blocks, allowed, err
}

// loadAllowed reads the allowed list, sorted.
func (m *Manager) loadAllowed() ([]netip.Prefix, error) {
	data, err := os.ReadFile(m.h.Path(allowedFile))
	if errors.Is(err, os.ErrNotExist) {
		return []netip.Prefix{}, nil
	}
	if err != nil {
		return nil, err
	}
	var all []string
	if err := json.Unmarshal(data, &all); err != nil {
		return nil, fmt.Errorf("%s: %w", allowedFile, err)
	}
	list := []netip.Prefix{}
	for _, a := range all {
		if p, err := Normalize(a); err == nil {
			list = append(list, p)
		}
	}
	sortPrefixes(list)
	return list, nil
}

func (m *Manager) saveAllowed(list []netip.Prefix) error {
	return m.write(allowedFile, formatAll(list))
}

// load reads the state file, dropping blocks that have run out.
func (m *Manager) load() ([]Block, error) {
	data, err := os.ReadFile(m.h.Path(stateFile))
	if errors.Is(err, os.ErrNotExist) {
		return []Block{}, nil
	}
	if err != nil {
		return nil, err
	}
	var all []Block
	if err := json.Unmarshal(data, &all); err != nil {
		return nil, fmt.Errorf("%s: %w", stateFile, err)
	}
	now := m.Now()
	blocks := []Block{}
	for _, b := range all {
		if b.Expires == nil || b.Expires.After(now) {
			blocks = append(blocks, b)
		}
	}
	return blocks, nil
}

func (m *Manager) save(blocks []Block) error {
	return m.write(stateFile, blocks)
}

// write replaces a state file in one step.
func (m *Manager) write(file string, v any) error {
	if err := os.MkdirAll(m.h.Path(stateDir), 0o700); err != nil {
		return err
	}
	data, err := json.MarshalIndent(v, "", "  ")
	if err != nil {
		return err
	}
	tmp := m.h.Path(file + ".tmp")
	if err := os.WriteFile(tmp, append(data, '\n'), 0o600); err != nil {
		return err
	}
	return os.Rename(tmp, m.h.Path(file))
}
