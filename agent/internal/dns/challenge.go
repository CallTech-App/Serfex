package dns

import (
	"context"
	"strings"
)

// challengeTTL keeps validation records short-lived in resolver caches.
const challengeTTL = MinTTL

// SetChallenge publishes the TXT values of an ACME DNS-01 challenge at
// name (for example _acme-challenge.example.com) in zone, replacing any
// values published there before. Only names starting with _acme-challenge.
// are accepted, so certificate issuance can never touch other records.
//
// The panel owns every other record of the zone: until ClearChallenge
// runs, dns.zone.apply leaves these records alone instead of removing them
// as unknown.
func (m *Manager) SetChallenge(ctx context.Context, zone, name string, values []string) error {
	set, err := challengeSet(zone, name, values)
	if err != nil {
		return err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	b, err := m.backend()
	if err != nil {
		return err
	}
	if cur, err := b.Zone(ctx, fqdn(zone)); err != nil {
		return &OperationError{err}
	} else if cur == nil {
		return invalid("zone %s is not served by this server", zone)
	}
	if err := b.PatchZone(ctx, fqdn(zone), []Change{{RRSet: set}}); err != nil {
		return rejected(err)
	}
	if m.challenges == nil {
		m.challenges = map[string]bool{}
	}
	m.challenges[set.key()] = true
	return nil
}

// ClearChallenge removes the TXT records SetChallenge published at name.
func (m *Manager) ClearChallenge(ctx context.Context, zone, name string) error {
	set, err := challengeSet(zone, name, []string{"x"})
	if err != nil {
		return err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	delete(m.challenges, set.key())
	b, err := m.backend()
	if err != nil {
		return err
	}
	if cur, err := b.Zone(ctx, fqdn(zone)); err != nil || cur == nil {
		return err
	}
	if err := b.PatchZone(ctx, fqdn(zone), []Change{{RRSet: RRSet{Name: set.Name, Type: "TXT"}, Delete: true}}); err != nil {
		return &OperationError{err}
	}
	return nil
}

// challengeSet validates a challenge record and returns its record set.
func challengeSet(zone, name string, values []string) (RRSet, error) {
	if err := validateZone(zone); err != nil {
		return RRSet{}, err
	}
	name = strings.TrimSuffix(strings.ToLower(name), ".")
	if !strings.HasPrefix(name, "_acme-challenge.") || (name != "_acme-challenge."+zone && !strings.HasSuffix(name, "."+zone)) {
		return RRSet{}, invalid("%s is not an ACME challenge name in zone %s", name, zone)
	}
	if _, err := ownerName(strings.TrimSuffix(name, "."+zone), zone); err != nil {
		return RRSet{}, err
	}
	if len(values) == 0 {
		return RRSet{}, invalid("a challenge needs at least one value")
	}
	set := RRSet{Name: name + ".", Type: "TXT", TTL: challengeTTL}
	for _, v := range values {
		if v == "" || len(v) > 255 || !printable(v) || strings.ContainsAny(v, "\" \\") {
			return RRSet{}, invalid("invalid challenge value %q", v)
		}
		set.Records = append(set.Records, quoteTXT(v))
	}
	return set, nil
}

// withoutChallenges returns cur without the challenge record sets that
// want does not mention, so a zone sync does not delete a validation in
// progress.
func (m *Manager) withoutChallenges(cur *Zone, want []RRSet) *Zone {
	if len(m.challenges) == 0 {
		return cur
	}
	wanted := map[string]bool{}
	for _, w := range want {
		wanted[w.key()] = true
	}
	out := &Zone{Name: cur.Name, Serial: cur.Serial}
	for _, s := range cur.RRSets {
		if m.challenges[s.key()] && !wanted[s.key()] {
			continue
		}
		out.RRSets = append(out.RRSets, s)
	}
	return out
}
