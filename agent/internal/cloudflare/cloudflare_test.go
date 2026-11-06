package cloudflare

import (
	"context"
	"net/netip"
	"strings"
	"testing"
	"time"
)

func TestZoneValid(t *testing.T) {
	ok := &Zone{ZoneID: strings.Repeat("a1", 16), Token: "tok_abc", Nameservers: []string{"ada.ns.cloudflare.com"}}
	if err := ok.Valid(); err != nil {
		t.Fatalf("valid zone refused: %v", err)
	}
	for name, z := range map[string]*Zone{
		"nil":          nil,
		"short id":     {ZoneID: "abc", Token: "t"},
		"upper id":     {ZoneID: strings.Repeat("A1", 16), Token: "t"},
		"no token":     {ZoneID: strings.Repeat("a1", 16)},
		"token spaces": {ZoneID: strings.Repeat("a1", 16), Token: "a b"},
		"bad ns":       {ZoneID: strings.Repeat("a1", 16), Token: "t", Nameservers: []string{"http://x"}},
	} {
		if z.Valid() == nil {
			t.Errorf("%s: accepted", name)
		}
	}
}

func TestParseRanges(t *testing.T) {
	got, err := ParseRanges([]string{"173.245.48.0/20", "2400:cb00::/32", "173.245.48.0/20"})
	if err != nil || len(got) != 2 {
		t.Fatalf("got %v, %v", got, err)
	}
	for _, bad := range [][]string{{"10.0.0.0/4"}, {"2400::/8"}, {"not-a-range"}, {}} {
		if _, err := ParseRanges(bad); err == nil {
			t.Errorf("%v accepted", bad)
		}
	}
}

func TestOverlaps(t *testing.T) {
	ranges, _ := ParseRanges([]string{"104.16.0.0/13"})
	for p, want := range map[string]bool{"104.18.1.1/32": true, "104.0.0.0/8": true, "203.0.113.0/24": false} {
		if _, got := Overlaps(ranges, netip.MustParsePrefix(p)); got != want {
			t.Errorf("%s: got %v", p, got)
		}
	}
}

func TestAwaitReturnsOnceEveryServerAnswers(t *testing.T) {
	calls := 0
	lookup := func(_ context.Context, server, _ string) ([]string, error) {
		calls++
		if server == "b" && calls < 3 {
			return nil, nil
		}
		return []string{"token-1", "token-2"}, nil
	}
	start := time.Now()
	Await(context.Background(), lookup, []string{"a", "b"}, "_acme-challenge.example.com", []string{"token-1", "token-2"}, 10*time.Second)
	if time.Since(start) > 5*time.Second {
		t.Fatal("waited too long")
	}
	if calls < 4 {
		t.Fatalf("asked %d times", calls)
	}
}
