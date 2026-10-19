package database

import (
	"net/netip"
	"strconv"
	"strings"
)

// AnyHost allows a user to connect from anywhere.
const AnyHost = "%"

// ValidateHost checks a remote host a user may connect from: "%" (any),
// an IPv4 or IPv6 address, an IPv4 network ("88.247.120.0/28") or an
// IPv4 prefix with a wildcard ("10.8.0.%").
func ValidateHost(h string) error {
	if _, ok := hostPrefix(h); !ok {
		return invalid("host %q must be %%, an IP address, an IPv4 network such as 10.0.0.0/24 or a prefix such as 10.0.0.%%", h)
	}
	return nil
}

// hostPrefix returns the network a host stands for; "%" is 0.0.0.0/0
// (PostgreSQL also gets ::/0 for it).
func hostPrefix(h string) (netip.Prefix, bool) {
	if h == AnyHost {
		return netip.MustParsePrefix("0.0.0.0/0"), true
	}
	if rest, ok := strings.CutSuffix(h, ".%"); ok {
		parts := strings.Split(rest, ".")
		if len(parts) < 1 || len(parts) > 3 {
			return netip.Prefix{}, false
		}
		for _, p := range parts {
			n, err := strconv.Atoi(p)
			if err != nil || n < 0 || n > 255 || strconv.Itoa(n) != p {
				return netip.Prefix{}, false
			}
		}
		for len(parts) < 4 {
			parts = append(parts, "0")
		}
		addr, err := netip.ParseAddr(strings.Join(parts, "."))
		if err != nil {
			return netip.Prefix{}, false
		}
		return netip.PrefixFrom(addr, 8*len(strings.Split(rest, "."))), true
	}
	if strings.Contains(h, "/") {
		p, err := netip.ParsePrefix(h)
		if err != nil || !p.Addr().Is4() || p.Masked() != p {
			return netip.Prefix{}, false
		}
		return p, true
	}
	addr, err := netip.ParseAddr(h)
	if err != nil || addr.Zone() != "" || addr.Is4In6() {
		return netip.Prefix{}, false
	}
	return netip.PrefixFrom(addr, addr.BitLen()), true
}

// mysqlHost writes a host the way MySQL and MariaDB match it: IPv4
// networks as address/netmask, which both understand.
func mysqlHost(h string) string {
	if !strings.Contains(h, "/") {
		return h
	}
	p, _ := hostPrefix(h)
	b := [4]byte{}
	for i := 0; i < p.Bits(); i++ {
		b[i/8] |= 0x80 >> (i % 8)
	}
	return p.Addr().String() + "/" + netip.AddrFrom4(b).String()
}

// pgNetworks returns the networks of a host for pg_hba.conf.
func pgNetworks(h string) []string {
	if h == AnyHost {
		return []string{"0.0.0.0/0", "::/0"}
	}
	p, _ := hostPrefix(h)
	return []string{p.String()}
}
