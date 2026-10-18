// Package dns runs Servik's authoritative DNS server: PowerDNS
// Authoritative with the LMDB backend, managed only through its HTTP API.
//
// Layout on the server (Ubuntu 24.04):
//
//	/etc/powerdns/pdns-servik.conf     instance config, root:pdns 0640 (holds the API key)
//	/var/lib/powerdns/servik.lmdb      zone data
//	pdns@servik.service                the instance (the stock pdns.service is disabled)
//	127.0.0.1:8081                     HTTP API, reachable only from this host
//
// The panel keeps the records users edit in its own database and sends the
// whole zone with dns.zone.apply; the agent turns that into the smallest
// set of record-set changes and applies them in one atomic API call, so
// every operation is idempotent and a rejected change leaves the zone as
// it was.
package dns

import (
	"context"
	"errors"
	"fmt"
	"sort"
	"sync"
	"time"

	"github.com/winchester37/servik/agent/internal/host"
)

// ValidationError reports a request that can never succeed as given.
type ValidationError struct{ msg string }

func (e *ValidationError) Error() string { return e.msg }

func invalid(format string, a ...any) error { return &ValidationError{fmt.Sprintf(format, a...)} }

// OperationError reports a valid request that failed on the server.
type OperationError struct{ Err error }

func (e *OperationError) Error() string { return e.Err.Error() }
func (e *OperationError) Unwrap() error { return e.Err }

// ErrNotSetUp is returned by zone operations before dns.setup has run.
var ErrNotSetUp = errors.New("the DNS server is not set up yet")

// Manager runs the DNS server and its zones. Operations are serialised.
type Manager struct {
	h          host.Host
	newBackend func(APIConfig) Backend
	// apiWait bounds how long Setup waits for PowerDNS to answer.
	apiWait time.Duration
	mu      sync.Mutex
}

// NewManager returns a Manager that talks to PowerDNS over its HTTP API.
func NewManager(h host.Host) *Manager {
	return &Manager{h: h, newBackend: func(c APIConfig) Backend { return NewAPI(c) }, apiWait: 30 * time.Second}
}

// NewSimulatedManager returns a Manager whose zones live in b; used by
// tests and dry runs.
func NewSimulatedManager(h host.Host, b Backend) *Manager {
	return &Manager{h: h, newBackend: func(APIConfig) Backend { return b }, apiWait: time.Second}
}

// backend returns the zone store, or ErrNotSetUp.
func (m *Manager) backend() (Backend, error) {
	cfg, err := m.readConfig()
	if err != nil {
		return nil, err
	}
	return m.newBackend(cfg), nil
}

// ZoneResult describes an applied zone.
type ZoneResult struct {
	Zone    string `json:"zone"`
	Serial  int64  `json:"serial"`
	Created bool   `json:"created"`
	Changed bool   `json:"changed"`
}

// ApplyZone creates or updates a zone so that it serves exactly spec.
func (m *Manager) ApplyZone(ctx context.Context, spec ZoneSpec) (ZoneResult, error) {
	sets, err := spec.normalize()
	if err != nil {
		return ZoneResult{}, err
	}
	m.mu.Lock()
	defer m.mu.Unlock()

	b, err := m.backend()
	if err != nil {
		return ZoneResult{}, err
	}
	name := fqdn(spec.Zone)
	res := ZoneResult{Zone: spec.Zone}

	cur, err := b.Zone(ctx, name)
	if err != nil {
		return ZoneResult{}, &OperationError{err}
	}
	if cur == nil {
		if err := b.CreateZone(ctx, name, sets); err != nil {
			return ZoneResult{}, rejected(err)
		}
		res.Created, res.Changed = true, true
	} else if changes := diff(cur, sets); len(changes) > 0 {
		if err := b.PatchZone(ctx, name, changes); err != nil {
			return ZoneResult{}, rejected(err)
		}
		res.Changed = true
	}

	if !res.Changed {
		res.Serial = cur.Serial
		return res, nil
	}
	z, err := b.Zone(ctx, name)
	if err != nil || z == nil {
		return ZoneResult{}, &OperationError{fmt.Errorf("zone %s missing after the change: %v", spec.Zone, err)}
	}
	res.Serial = z.Serial
	return res, nil
}

// rejected turns a refusal by PowerDNS (bad content, conflicting records)
// into a validation error the user can act on.
func rejected(err error) error {
	var ae *apiError
	if errors.As(err, &ae) && ae.Status == 422 {
		return invalid("the DNS server rejected the zone: %s", ae.Message)
	}
	return &OperationError{err}
}

// diff returns the changes that turn cur into want. The SOA serial is
// left to PowerDNS: a changed SOA keeps the current serial, which
// SOA-EDIT-API then raises.
func diff(cur *Zone, want []RRSet) []Change {
	have := map[string]RRSet{}
	for _, s := range cur.RRSets {
		have[s.key()] = s
	}
	var changes []Change
	seen := map[string]bool{}
	for _, w := range want {
		seen[w.key()] = true
		h, ok := have[w.key()]
		if w.Type == "SOA" {
			if ok && len(h.Records) == 1 && h.TTL == w.TTL && soaWithoutSerial(h.Records[0]) == soaWithoutSerial(w.Records[0]) {
				continue
			}
			w.Records = []string{soaWithSerial(w.Records[0], cur.Serial)}
			changes = append(changes, Change{RRSet: w})
			continue
		}
		if !ok || !h.equal(w) {
			changes = append(changes, Change{RRSet: w})
		}
	}
	var gone []string
	for k := range have {
		if !seen[k] {
			gone = append(gone, k)
		}
	}
	sort.Strings(gone)
	for _, k := range gone {
		changes = append(changes, Change{RRSet: have[k], Delete: true})
	}
	return changes
}

// ZoneInfo is a zone as it is served, in the panel's record form.
type ZoneInfo struct {
	Zone    string   `json:"zone"`
	Serial  int64    `json:"serial"`
	SOA     string   `json:"soa"`
	Records []Record `json:"records"`
}

// GetZone returns what the DNS server serves for zone.
func (m *Manager) GetZone(ctx context.Context, zone string) (ZoneInfo, error) {
	if err := validateZone(zone); err != nil {
		return ZoneInfo{}, err
	}
	b, err := m.backend()
	if err != nil {
		return ZoneInfo{}, err
	}
	z, err := b.Zone(ctx, fqdn(zone))
	if err != nil {
		return ZoneInfo{}, &OperationError{err}
	}
	if z == nil {
		return ZoneInfo{}, invalid("zone %s does not exist on the DNS server", zone)
	}
	info := ZoneInfo{Zone: zone, Serial: z.Serial, Records: []Record{}}
	for _, s := range z.RRSets {
		if s.Type == "SOA" {
			if len(s.Records) == 1 {
				info.SOA = s.Records[0]
			}
			continue
		}
		for _, c := range sorted(s.Records) {
			info.Records = append(info.Records, Record{Name: relativeName(s.Name, zone), Type: s.Type, TTL: s.TTL, Content: displayContent(s.Type, c)})
		}
	}
	return info, nil
}

// DeleteResult describes a removed zone.
type DeleteResult struct {
	Zone    string `json:"zone"`
	Deleted bool   `json:"deleted"`
}

// DeleteZone removes a zone and all its records. Deleting a missing zone
// succeeds, so the panel can retry.
func (m *Manager) DeleteZone(ctx context.Context, zone string) (DeleteResult, error) {
	if err := validateZone(zone); err != nil {
		return DeleteResult{}, err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	b, err := m.backend()
	if err != nil {
		return DeleteResult{}, err
	}
	cur, err := b.Zone(ctx, fqdn(zone))
	if err != nil {
		return DeleteResult{}, &OperationError{err}
	}
	if cur == nil {
		return DeleteResult{Zone: zone}, nil
	}
	if err := b.DeleteZone(ctx, fqdn(zone)); err != nil {
		return DeleteResult{}, &OperationError{err}
	}
	return DeleteResult{Zone: zone, Deleted: true}, nil
}

func validateZone(zone string) error {
	_, err := ZoneSpec{Zone: zone}.normalizeName()
	return err
}
