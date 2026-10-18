package dns

import (
	"context"
	"errors"
	"testing"
)

func TestChallengeSurvivesZoneSync(t *testing.T) {
	m, mem, _ := newTest(t)
	setUp(t, m)
	ctx := context.Background()
	spec := zoneSpec()
	if _, err := m.ApplyZone(ctx, spec); err != nil {
		t.Fatal(err)
	}
	name := "_acme-challenge." + spec.Zone
	if err := m.SetChallenge(ctx, spec.Zone, name, []string{"abc-1", "def_2"}); err != nil {
		t.Fatal(err)
	}
	txt := func() []string {
		z, _ := mem.Zone(ctx, fqdn(spec.Zone))
		for _, s := range z.RRSets {
			if s.Name == name+"." && s.Type == "TXT" {
				return s.Records
			}
		}
		return nil
	}
	if got := txt(); len(got) != 2 || got[0] != `"abc-1"` {
		t.Fatalf("challenge records = %v", got)
	}

	// The panel syncs the zone while validation runs: the answer stays.
	spec.Records = append(spec.Records, Record{Name: "blog", Type: "A", TTL: 3600, Content: "203.0.113.20"})
	if _, err := m.ApplyZone(ctx, spec); err != nil {
		t.Fatal(err)
	}
	if len(txt()) != 2 {
		t.Fatal("zone sync removed the challenge")
	}

	if err := m.ClearChallenge(ctx, spec.Zone, name); err != nil {
		t.Fatal(err)
	}
	if txt() != nil {
		t.Fatal("challenge not cleared")
	}
}

func TestChallengeNames(t *testing.T) {
	m, _, _ := newTest(t)
	setUp(t, m)
	ctx := context.Background()
	spec := zoneSpec()
	if _, err := m.ApplyZone(ctx, spec); err != nil {
		t.Fatal(err)
	}
	for _, name := range []string{"www." + spec.Zone, "_acme-challenge.other.com", "_acme-challenge"} {
		var v *ValidationError
		if err := m.SetChallenge(ctx, spec.Zone, name, []string{"x"}); !errors.As(err, &v) {
			t.Errorf("%s: err = %v, want validation error", name, err)
		}
	}
	if err := m.SetChallenge(ctx, spec.Zone, "_acme-challenge.shop."+spec.Zone, []string{"x"}); err != nil {
		t.Fatalf("subdomain challenge: %v", err)
	}
	var v *ValidationError
	if err := m.SetChallenge(ctx, "missing.com", "_acme-challenge.missing.com", []string{"x"}); !errors.As(err, &v) {
		t.Fatalf("missing zone: err = %v", err)
	}
}
