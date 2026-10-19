// Package firewall blocks IP addresses with nftables.
//
// Servik keeps its own table so the distribution's firewall (ufw,
// iptables-nft, a hand-written ruleset) stays untouched:
//
//	table inet servik
//	  set blocked4, blocked6   addresses and prefixes, each with its own timeout
//	  chain input              drops everything from those sets, before other filters
//	/etc/servik/firewall/blocks.json   the blocks as last applied, root 0600
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

	"github.com/winchester37/servik/agent/internal/host"
)

const (
	stateDir  = "/etc/servik/firewall"
	stateFile = stateDir + "/blocks.json"

	// Table is the nftables table Servik owns.
	Table = "servik"

	// MaxSeconds is the longest timed block; longer ones are permanent.
	MaxSeconds = 365 * 24 * 3600
)

// ruleset creates the table from scratch. "add table" before "delete
// table" makes the delete work whether or not the table exists.
const ruleset = `add table inet servik
delete table inet servik
table inet servik {
	set blocked4 {
		type ipv4_addr
		flags interval, timeout
	}
	set blocked6 {
		type ipv6_addr
		flags interval, timeout
	}
	chain input {
		type filter hook input priority filter - 10; policy accept;
		ip saddr @blocked4 counter drop
		ip6 saddr @blocked6 counter drop
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

func (m *Manager) check(p netip.Prefix) error {
	a := p.Addr()
	if a.Is4() && p.Bits() < 16 || a.Is6() && p.Bits() < 48 {
		return invalid("%s is too wide; block at most a /16 (IPv4) or /48 (IPv6)", format(p))
	}
	for _, bad := range []netip.Prefix{
		netip.MustParsePrefix("127.0.0.0/8"), netip.MustParsePrefix("::1/128"),
		netip.MustParsePrefix("0.0.0.0/32"), netip.MustParsePrefix("::/128"),
		netip.MustParsePrefix("224.0.0.0/4"), netip.MustParsePrefix("ff00::/8"),
	} {
		if bad.Overlaps(p) {
			return invalid("%s cannot be blocked: it is a loopback, unspecified or multicast address", format(p))
		}
	}
	if m.Protected != nil {
		for _, own := range m.Protected() {
			if p.Contains(own.Unmap()) {
				return invalid("%s cannot be blocked: it is this server's own address %s", format(p), own)
			}
		}
	}
	return nil
}

// Block blocks an address, or changes the duration and reason of an
// address already blocked.
func (m *Manager) Block(ctx context.Context, spec Spec) (Block, error) {
	p, err := Normalize(spec.Address)
	if err != nil {
		return Block{}, err
	}
	if err := m.check(p); err != nil {
		return Block{}, err
	}
	if spec.Seconds < 0 || spec.Seconds > MaxSeconds {
		return Block{}, invalid("seconds must be between 0 (until removed) and %d", MaxSeconds)
	}
	if len(spec.Reason) > 200 {
		return Block{}, invalid("reason is longer than 200 characters")
	}
	m.mu.Lock()
	defer m.mu.Unlock()

	blocks, err := m.load()
	if err != nil {
		return Block{}, &OperationError{err}
	}
	addr := format(p)
	for _, b := range blocks {
		bp, _ := Normalize(b.Address)
		if b.Address != addr && bp.Overlaps(p) {
			return Block{}, invalid("%s overlaps %s, which is already blocked; remove that block first", addr, b.Address)
		}
	}
	if err := m.ensure(ctx, blocks); err != nil {
		return Block{}, &OperationError{err}
	}

	now := m.Now().UTC().Truncate(time.Second)
	b := Block{Address: addr, Reason: strings.TrimSpace(spec.Reason), Created: now}
	if spec.Seconds > 0 {
		exp := now.Add(time.Duration(spec.Seconds) * time.Second)
		b.Expires = &exp
	}
	set := setFor(p)
	// add + delete + add replaces the element whether or not it existed,
	// in one transaction, so the new timeout always applies.
	script := fmt.Sprintf("add element inet %[1]s %[2]s { %[3]s }\ndelete element inet %[1]s %[2]s { %[3]s }\nadd element inet %[1]s %[2]s { %[3]s%[4]s }\n",
		Table, set, addr, timeout(b, now))
	if _, err := m.h.Run(ctx, host.Command{Name: "nft", Args: []string{"-f", "-"}, Stdin: []byte(script)}); err != nil {
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
	return b, nil
}

// Unblock removes a block; removing an address that is not blocked (or
// whose block ran out) is not an error. It reports whether a block was
// removed.
func (m *Manager) Unblock(ctx context.Context, address string) (bool, error) {
	p, err := Normalize(address)
	if err != nil {
		return false, err
	}
	addr := format(p)
	m.mu.Lock()
	defer m.mu.Unlock()

	blocks, err := m.load()
	if err != nil {
		return false, &OperationError{err}
	}
	found := false
	kept := blocks[:0]
	for _, b := range blocks {
		if b.Address == addr {
			found = true
			continue
		}
		kept = append(kept, b)
	}
	// The kernel may have dropped the element already; add-then-delete
	// removes it either way.
	script := fmt.Sprintf("add element inet %[1]s %[2]s { %[3]s }\ndelete element inet %[1]s %[2]s { %[3]s }\n", Table, setFor(p), addr)
	if _, err := m.h.Run(ctx, host.Command{Name: "nft", Args: []string{"-f", "-"}, Stdin: []byte(script)}); err != nil {
		// No table means nothing is blocked.
		if _, lerr := m.h.Run(ctx, host.Command{Name: "nft", Args: []string{"list", "table", "inet", Table}}); lerr == nil {
			return false, &OperationError{err}
		}
	}
	if err := m.save(kept); err != nil {
		return false, &OperationError{err}
	}
	return found, nil
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

// Restore recreates the table with the blocks still in force; the agent
// calls it when it starts, so blocks survive a reboot.
func (m *Manager) Restore(ctx context.Context) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	blocks, err := m.load()
	if err != nil {
		return err
	}
	if len(blocks) == 0 {
		return nil
	}
	return m.create(ctx, blocks)
}

// ensure creates the table, with every stored block, when it is missing
// (first use, or someone flushed the ruleset).
func (m *Manager) ensure(ctx context.Context, blocks []Block) error {
	if _, err := m.h.Run(ctx, host.Command{Name: "nft", Args: []string{"list", "set", "inet", Table, "blocked6"}}); err == nil {
		return nil
	}
	return m.create(ctx, blocks)
}

func (m *Manager) create(ctx context.Context, blocks []Block) error {
	var b strings.Builder
	b.WriteString(ruleset)
	now := m.Now()
	for _, bl := range blocks {
		p, err := Normalize(bl.Address)
		if err != nil {
			continue
		}
		fmt.Fprintf(&b, "add element inet %s %s { %s%s }\n", Table, setFor(p), bl.Address, timeout(bl, now))
	}
	if _, err := m.h.Run(ctx, host.Command{Name: "nft", Args: []string{"-f", "-"}, Stdin: []byte(b.String())}); err != nil {
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
	if err := os.MkdirAll(m.h.Path(stateDir), 0o700); err != nil {
		return err
	}
	data, err := json.MarshalIndent(blocks, "", "  ")
	if err != nil {
		return err
	}
	tmp := m.h.Path(stateFile + ".tmp")
	if err := os.WriteFile(tmp, append(data, '\n'), 0o600); err != nil {
		return err
	}
	return os.Rename(tmp, m.h.Path(stateFile))
}
