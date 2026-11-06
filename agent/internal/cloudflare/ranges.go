package cloudflare

import (
	"encoding/json"
	"fmt"
	"net/netip"
	"os"
	"slices"

	"github.com/calltech-app/serfex/agent/internal/host"
)

// RangesFile holds Cloudflare's proxy ranges as the panel last sent them
// (it reads https://www.cloudflare.com/ips/ every week).
const RangesFile = "/etc/serfex/cloudflare-ips.json"

// Builtin are Cloudflare's proxy ranges as published when this version
// was built; they stand in until the panel sends a newer list.
var Builtin = []string{
	"173.245.48.0/20", "103.21.244.0/22", "103.22.200.0/22", "103.31.4.0/22",
	"141.101.64.0/18", "108.162.192.0/18", "190.93.240.0/20", "188.114.96.0/20",
	"197.234.240.0/22", "198.41.128.0/17", "162.158.0.0/15", "104.16.0.0/13",
	"104.24.0.0/14", "172.64.0.0/13", "131.0.72.0/22",
	"2400:cb00::/32", "2606:4700::/32", "2803:f800::/32", "2405:b500::/32",
	"2405:8100::/32", "2a06:98c0::/29", "2c0f:f248::/32",
}

// MaxRanges bounds the list the panel may send.
const MaxRanges = 200

// ParseRanges checks a list of prefixes and returns them in canonical,
// sorted form. Each must be at least a /8 (IPv4) or /16 (IPv6): a wider
// one would trust or protect half the internet.
func ParseRanges(list []string) ([]netip.Prefix, error) {
	if len(list) == 0 || len(list) > MaxRanges {
		return nil, fmt.Errorf("between 1 and %d ranges are needed", MaxRanges)
	}
	out := make([]netip.Prefix, 0, len(list))
	for _, s := range list {
		p, err := netip.ParsePrefix(s)
		if err != nil {
			return nil, fmt.Errorf("%q is not an IP range", s)
		}
		p = p.Masked()
		if p.Addr().Is4() && p.Bits() < 8 || p.Addr().Is6() && p.Bits() < 16 {
			return nil, fmt.Errorf("%s is too wide for a Cloudflare range", p)
		}
		if !slices.Contains(out, p) {
			out = append(out, p)
		}
	}
	slices.SortFunc(out, func(a, b netip.Prefix) int {
		if c := a.Addr().Compare(b.Addr()); c != 0 {
			return c
		}
		return a.Bits() - b.Bits()
	})
	return out, nil
}

// Ranges returns the ranges in force: the panel's list, or the built-in
// one.
func Ranges(h host.Host) []netip.Prefix {
	var list []string
	if data, err := os.ReadFile(h.Path(RangesFile)); err == nil && json.Unmarshal(data, &list) == nil {
		if p, err := ParseRanges(list); err == nil {
			return p
		}
	}
	p, _ := ParseRanges(Builtin)
	return p
}

// Overlaps returns the Cloudflare range p overlaps, if any.
func Overlaps(ranges []netip.Prefix, p netip.Prefix) (netip.Prefix, bool) {
	for _, r := range ranges {
		if r.Overlaps(p) {
			return r, true
		}
	}
	return netip.Prefix{}, false
}
