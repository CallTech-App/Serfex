package stats

import (
	"net/netip"
	"strings"
	"testing"
)

func geoOf(t *testing.T, lines ...string) *Geo {
	t.Helper()
	g, err := BuildGeo(strings.NewReader(strings.Join(lines, "\n") + "\n"))
	if err != nil {
		t.Fatal(err)
	}
	return g
}

func join(list []netip.Prefix) string {
	out := make([]string, len(list))
	for i, p := range list {
		out[i] = p.String()
	}
	return strings.Join(out, " ")
}

func TestPrefixesMergeTouchingBlocksAndSplitOddSizes(t *testing.T) {
	g := geoOf(t,
		"demo|CN|ipv4|1.0.1.0|256|20110414|allocated",
		"demo|CN|ipv4|1.0.2.0|512|20110414|allocated",
		"demo|DE|ipv4|1.0.4.0|256|20110414|allocated",
		"demo|CN|ipv4|10.0.0.0|768|20110414|allocated",
	)
	v4, v6 := g.Prefixes([]string{"cn"})
	// 1.0.1.0–1.0.3.255 is one run of addresses, but no single prefix:
	// 1.0.1.0 is not on a /22 boundary. 768 addresses are a /23 and a /24.
	if got := join(v4); got != "1.0.1.0/24 1.0.2.0/23 10.0.0.0/23 10.0.2.0/24" {
		t.Fatalf("IPv4 prefixes: %s", got)
	}
	if len(v6) != 0 {
		t.Fatalf("IPv6 prefixes: %s", join(v6))
	}
}

func TestPrefixesOfSeveralCountriesAreOneAscendingList(t *testing.T) {
	g := geoOf(t,
		"demo|RU|ipv4|5.0.0.0|256|20110414|allocated",
		"demo|CN|ipv4|5.0.1.0|256|20110414|allocated",
		"demo|US|ipv4|5.0.2.0|256|20110414|allocated",
		"demo|CN|ipv4|1.2.0.0|65536|20110414|assigned",
	)
	// Blocks of two blocked countries that touch become one prefix.
	v4, _ := g.Prefixes([]string{"RU", "CN", "CN"})
	if got := join(v4); got != "1.2.0.0/16 5.0.0.0/23" {
		t.Fatalf("prefixes: %s", got)
	}
}

func TestPrefixesIPv6(t *testing.T) {
	g := geoOf(t,
		"demo|CN|ipv6|2001:db8::|32|20110414|allocated",
		"demo|CN|ipv6|2001:db9::|32|20110414|allocated",
		"demo|DE|ipv6|2a00::|29|20110414|allocated",
		"demo|CN|ipv6|2400:1::|48|20110414|allocated",
	)
	_, v6 := g.Prefixes([]string{"CN"})
	if got := join(v6); got != "2001:db8::/31 2400:1::/48" {
		t.Fatalf("IPv6 prefixes: %s", got)
	}
}

func TestPrefixesReachTheEndOfTheAddressSpace(t *testing.T) {
	g := geoOf(t, "demo|ZA|ipv4|255.255.255.0|256|20110414|allocated")
	v4, _ := g.Prefixes([]string{"ZA"})
	if got := join(v4); got != "255.255.255.0/24" {
		t.Fatalf("prefixes: %s", got)
	}
}

func TestPrefixesOfAnUnknownCountryOrNoTableAreEmpty(t *testing.T) {
	g := geoOf(t, "demo|CN|ipv4|1.0.1.0|256|20110414|allocated")
	if v4, v6 := g.Prefixes([]string{"XX", "", "CHN"}); len(v4)+len(v6) != 0 {
		t.Fatalf("unknown country: %s %s", join(v4), join(v6))
	}
	if v4, v6 := g.Prefixes(nil); len(v4)+len(v6) != 0 {
		t.Fatal("no country asked for")
	}
	var none *Geo
	if v4, v6 := none.Prefixes([]string{"CN"}); len(v4)+len(v6) != 0 {
		t.Fatal("no table")
	}
}

func TestBlocksCountsACountry(t *testing.T) {
	g := geoOf(t,
		"demo|CN|ipv4|1.0.1.0|256|20110414|allocated",
		"demo|CN|ipv6|2001:db8::|32|20110414|allocated",
		"demo|DE|ipv4|1.0.4.0|256|20110414|allocated",
	)
	if n := g.Blocks("cn"); n != 2 {
		t.Fatalf("CN blocks: %d", n)
	}
	if n := g.Blocks("FR"); n != 0 {
		t.Fatalf("FR blocks: %d", n)
	}
	var none *Geo
	if n := none.Blocks("CN"); n != 0 {
		t.Fatalf("no table: %d", n)
	}
}

func TestOnGeoRunsWhenTheTableIsSet(t *testing.T) {
	a := New()
	if a.Geo() != nil {
		t.Fatal("a new aggregator has no table")
	}
	ran := 0
	a.OnGeo = func() { ran++ }
	g := geoOf(t, "demo|CN|ipv4|1.0.1.0|256|20110414|allocated")
	a.SetGeo(g)
	if ran != 1 || a.Geo() != g {
		t.Fatalf("OnGeo ran %d times, table set: %v", ran, a.Geo() == g)
	}
}
