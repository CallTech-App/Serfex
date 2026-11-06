// Package edge makes Nginx right for sites behind Cloudflare's proxy.
//
// A visitor behind the proxy reaches this server from one of Cloudflare's
// addresses. Two http-level files fix what that breaks:
//
//	/etc/nginx/conf.d/serfex-realip.conf   trust CF-Connecting-IP from Cloudflare's ranges only,
//	                                       so logs, WAF, limits and PHP see the visitor
//	/etc/nginx/conf.d/serfex-blocked.conf  the firewall's blocks as "deny" lines: the
//	                                       firewall drops a blocked visitor's own
//	                                       connections, Nginx refuses them through Cloudflare
//	/etc/serfex/cloudflare-ips.json        the ranges as the panel last sent them
//
// A site that sets its own allow/deny rules (IP restrictions, protected
// folders) does not inherit the http-level deny lines; the firewall still
// covers visitors who connect directly.
package edge

import (
	"context"
	"encoding/json"
	"fmt"
	"net/netip"
	"os"
	"strings"
	"sync"
	"time"

	"github.com/calltech-app/serfex/agent/internal/cloudflare"
	"github.com/calltech-app/serfex/agent/internal/firewall"
	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/txn"
)

const (
	RealIPConf  = "/etc/nginx/conf.d/serfex-realip.conf"
	BlockedConf = "/etc/nginx/conf.d/serfex-blocked.conf"
)

// ValidationError reports a request that can never succeed as given.
type ValidationError struct{ msg string }

func (e *ValidationError) Error() string { return e.msg }

// OperationError wraps a failure while changing the server.
type OperationError struct{ Err error }

func (e *OperationError) Error() string { return e.Err.Error() }
func (e *OperationError) Unwrap() error { return e.Err }

// Spec is what the panel sends.
type Spec struct {
	// Ranges are Cloudflare's proxy ranges; empty keeps the ones in force.
	Ranges []string `json:"ranges"`
}

// Status says what is in force.
type Status struct {
	Ranges  int  `json:"ranges"`
	Blocked int  `json:"blocked"`
	Nginx   bool `json:"nginx"`
	Changed bool `json:"changed"`
}

// Manager writes the files. Operations are serialised.
type Manager struct {
	h  host.Host
	fw *firewall.Manager
	mu sync.Mutex
	// Now is the clock; tests replace it.
	Now func() time.Time
}

// NewManager returns a Manager; fw supplies the blocks.
func NewManager(h host.Host, fw *firewall.Manager) *Manager {
	return &Manager{h: h, fw: fw, Now: time.Now}
}

// Apply saves new ranges (when given) and rewrites both files.
func (m *Manager) Apply(ctx context.Context, spec Spec) (Status, error) {
	var ranges []netip.Prefix
	if len(spec.Ranges) > 0 {
		r, err := cloudflare.ParseRanges(spec.Ranges)
		if err != nil {
			return Status{}, &ValidationError{err.Error()}
		}
		ranges = r
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	t := txn.New(m.h)
	if ranges != nil {
		if err := t.RootDir("/etc/serfex", 0o755, 0, 0); err != nil {
			return Status{}, &OperationError{err}
		}
		list := make([]string, len(ranges))
		for i, r := range ranges {
			list[i] = r.String()
		}
		data, _ := json.MarshalIndent(list, "", "  ")
		if err := t.WriteOwned(cloudflare.RangesFile, append(data, '\n'), 0o644, 0, 0); err != nil {
			_ = t.Rollback(ctx)
			return Status{}, &OperationError{err}
		}
	}
	st, err := m.write(ctx, t)
	if err != nil {
		_ = t.Rollback(ctx)
		return Status{}, err
	}
	return st, nil
}

// Sync rewrites the files from what is in force (after the firewall's
// blocks changed, and when the agent starts). Errors are returned for
// logging; nothing is left half-written.
func (m *Manager) Sync(ctx context.Context) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	t := txn.New(m.h)
	if _, err := m.write(ctx, t); err != nil {
		_ = t.Rollback(ctx)
		return err
	}
	return nil
}

// Run rewrites the deny lines every five minutes, so blocks that ran out
// leave Nginx too (the firewall's own blocks end in the kernel).
func (m *Manager) Run(ctx context.Context, log func(error)) {
	tick := time.NewTicker(5 * time.Minute)
	defer tick.Stop()
	for {
		if err := m.Sync(ctx); err != nil {
			log(err)
		}
		select {
		case <-ctx.Done():
			return
		case <-tick.C:
		}
	}
}

func (m *Manager) write(ctx context.Context, t *txn.Tx) (Status, error) {
	ranges := cloudflare.Ranges(m.h)
	st := Status{Ranges: len(ranges)}
	if fi, err := os.Stat(m.h.Path("/etc/nginx")); err != nil || !fi.IsDir() {
		return st, nil
	}
	st.Nginx = true
	var denies []string
	if m.fw != nil {
		blocks, err := m.fw.List()
		if err != nil {
			return st, &OperationError{err}
		}
		now := m.Now()
		for _, b := range blocks {
			if b.Expires != nil && !b.Expires.After(now) {
				continue
			}
			if _, err := netip.ParsePrefix(b.Address); err != nil {
				if _, err := netip.ParseAddr(b.Address); err != nil {
					continue
				}
			}
			denies = append(denies, b.Address)
		}
	}
	st.Blocked = len(denies)
	if err := t.RootDir("/etc/nginx/conf.d", 0o755, 0, 0); err != nil {
		return st, &OperationError{err}
	}
	if err := t.WriteFile(RealIPConf, RealIPContent(ranges)); err != nil {
		return st, &OperationError{err}
	}
	if err := t.WriteFile(BlockedConf, BlockedContent(denies)); err != nil {
		return st, &OperationError{err}
	}
	if !t.Changed {
		return st, nil
	}
	st.Changed = true
	if out, err := m.h.Run(ctx, host.Command{Name: "nginx", Args: []string{"-t"}}); err != nil {
		return st, &ValidationError{"Nginx refused the configuration, nothing was changed: " + strings.TrimSpace(out)}
	}
	if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"reload", "nginx.service"}}); err != nil {
		return st, &OperationError{fmt.Errorf("reloading Nginx: %w", err)}
	}
	return st, nil
}

// RealIPContent trusts CF-Connecting-IP from ranges only.
func RealIPContent(ranges []netip.Prefix) []byte {
	var b strings.Builder
	fmt.Fprintf(&b, "# %s. Manual changes are overwritten.\n", txn.Marker)
	b.WriteString("# Visitors behind Cloudflare's proxy: Nginx, the WAF, the logs and PHP\n")
	b.WriteString("# see the visitor's address. Only Cloudflare's ranges are trusted.\n")
	for _, r := range ranges {
		fmt.Fprintf(&b, "set_real_ip_from %s;\n", r)
	}
	b.WriteString("real_ip_header CF-Connecting-IP;\n")
	return []byte(b.String())
}

// BlockedContent refuses the firewall's blocked addresses.
func BlockedContent(addresses []string) []byte {
	var b strings.Builder
	fmt.Fprintf(&b, "# %s. Manual changes are overwritten.\n", txn.Marker)
	b.WriteString("# Addresses blocked on the Güvenlik page, refused here too when they\n")
	b.WriteString("# come through Cloudflare's proxy.\n")
	for _, a := range addresses {
		fmt.Fprintf(&b, "deny %s;\n", a)
	}
	return []byte(b.String())
}
