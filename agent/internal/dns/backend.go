package dns

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"sort"
	"strings"
	"sync"
	"time"
)

// Zone is a zone as the DNS server holds it.
type Zone struct {
	Name   string // absolute, with trailing dot
	Serial int64
	RRSets []RRSet
}

// Change replaces (Records non-empty) or deletes (Delete) one record set.
type Change struct {
	RRSet
	Delete bool
}

// Backend is the DNS server's zone store. Each call is atomic: a rejected
// change leaves the zone as it was.
type Backend interface {
	// Version returns the server version; it fails while the server is down.
	Version(ctx context.Context) (string, error)
	// Zone returns the zone, or nil when it does not exist.
	Zone(ctx context.Context, name string) (*Zone, error)
	// CreateZone adds a zone with the given record sets.
	CreateZone(ctx context.Context, name string, sets []RRSet) error
	// PatchZone applies changes to an existing zone.
	PatchZone(ctx context.Context, name string, changes []Change) error
	// DeleteZone removes a zone; a missing zone is not an error.
	DeleteZone(ctx context.Context, name string) error
}

// APIConfig says how to reach the PowerDNS HTTP API.
type APIConfig struct {
	URL string // http://127.0.0.1:8081
	Key string
}

// API talks to PowerDNS Authoritative over its HTTP API.
type API struct {
	cfg    APIConfig
	client *http.Client
}

// NewAPI returns a client for the API described by cfg.
func NewAPI(cfg APIConfig) *API {
	return &API{cfg: cfg, client: &http.Client{Timeout: 15 * time.Second}}
}

type apiRecord struct {
	Content  string `json:"content"`
	Disabled bool   `json:"disabled"`
}

type apiRRSet struct {
	Name       string      `json:"name"`
	Type       string      `json:"type"`
	TTL        int         `json:"ttl,omitempty"`
	ChangeType string      `json:"changetype,omitempty"`
	Records    []apiRecord `json:"records"`
}

type apiZone struct {
	Name        string     `json:"name"`
	Kind        string     `json:"kind,omitempty"`
	Serial      int64      `json:"serial,omitempty"`
	SOAEditAPI  string     `json:"soa_edit_api,omitempty"`
	Nameservers []string   `json:"nameservers"`
	RRSets      []apiRRSet `json:"rrsets"`
}

// apiError is a non-2xx answer from PowerDNS.
type apiError struct {
	Status  int
	Message string
}

func (e *apiError) Error() string {
	return fmt.Sprintf("PowerDNS answered %d: %s", e.Status, e.Message)
}

func (a *API) do(ctx context.Context, method, path string, body any, out any) error {
	var r io.Reader
	if body != nil {
		b, err := json.Marshal(body)
		if err != nil {
			return err
		}
		r = bytes.NewReader(b)
	}
	req, err := http.NewRequestWithContext(ctx, method, a.cfg.URL+"/api/v1/servers/localhost"+path, r)
	if err != nil {
		return err
	}
	req.Header.Set("X-API-Key", a.cfg.Key)
	req.Header.Set("Accept", "application/json")
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	resp, err := a.client.Do(req)
	if err != nil {
		return fmt.Errorf("PowerDNS API unreachable: %w", err)
	}
	defer resp.Body.Close()
	data, err := io.ReadAll(io.LimitReader(resp.Body, 16<<20))
	if err != nil {
		return err
	}
	if resp.StatusCode < 200 || resp.StatusCode > 299 {
		var e struct {
			Error string `json:"error"`
		}
		msg := strings.TrimSpace(string(data))
		if json.Unmarshal(data, &e) == nil && e.Error != "" {
			msg = e.Error
		}
		return &apiError{Status: resp.StatusCode, Message: msg}
	}
	if out != nil && len(data) > 0 {
		return json.Unmarshal(data, out)
	}
	return nil
}

func zonePath(name string) string { return "/zones/" + url.PathEscape(name) }

// Version implements Backend.
func (a *API) Version(ctx context.Context) (string, error) {
	var s struct {
		Version string `json:"version"`
	}
	if err := a.do(ctx, http.MethodGet, "", nil, &s); err != nil {
		return "", err
	}
	return s.Version, nil
}

// Zone implements Backend.
func (a *API) Zone(ctx context.Context, name string) (*Zone, error) {
	var z apiZone
	err := a.do(ctx, http.MethodGet, zonePath(name), nil, &z)
	var ae *apiError
	if errors.As(err, &ae) && (ae.Status == http.StatusNotFound || ae.Status == http.StatusUnprocessableEntity && strings.Contains(ae.Message, "Could not find")) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	out := &Zone{Name: z.Name, Serial: z.Serial}
	for _, s := range z.RRSets {
		set := RRSet{Name: s.Name, Type: s.Type, TTL: s.TTL}
		for _, r := range s.Records {
			if !r.Disabled {
				set.Records = append(set.Records, r.Content)
			}
		}
		if len(set.Records) > 0 {
			out.RRSets = append(out.RRSets, set)
		}
	}
	return out, nil
}

func toAPI(s RRSet, changeType string) apiRRSet {
	out := apiRRSet{Name: s.Name, Type: s.Type, TTL: s.TTL, ChangeType: changeType, Records: []apiRecord{}}
	for _, c := range s.Records {
		out.Records = append(out.Records, apiRecord{Content: c})
	}
	return out
}

// CreateZone implements Backend. Zones are native (no AXFR) and PowerDNS
// raises the SOA serial on every API change (SOA-EDIT-API DEFAULT).
func (a *API) CreateZone(ctx context.Context, name string, sets []RRSet) error {
	z := apiZone{Name: name, Kind: "Native", SOAEditAPI: "DEFAULT", Nameservers: []string{}}
	for _, s := range sets {
		z.RRSets = append(z.RRSets, toAPI(s, ""))
	}
	return a.do(ctx, http.MethodPost, "/zones", z, nil)
}

// PatchZone implements Backend.
func (a *API) PatchZone(ctx context.Context, name string, changes []Change) error {
	body := struct {
		RRSets []apiRRSet `json:"rrsets"`
	}{}
	for _, c := range changes {
		if c.Delete {
			body.RRSets = append(body.RRSets, apiRRSet{Name: c.Name, Type: c.Type, ChangeType: "DELETE", Records: []apiRecord{}})
		} else {
			body.RRSets = append(body.RRSets, toAPI(c.RRSet, "REPLACE"))
		}
	}
	return a.do(ctx, http.MethodPatch, zonePath(name), body, nil)
}

// DeleteZone implements Backend.
func (a *API) DeleteZone(ctx context.Context, name string) error {
	err := a.do(ctx, http.MethodDelete, zonePath(name), nil, nil)
	var ae *apiError
	if errors.As(err, &ae) && (ae.Status == http.StatusNotFound || ae.Status == http.StatusUnprocessableEntity) {
		return nil
	}
	return err
}

// Memory is an in-memory Backend for tests and dry runs. Like PowerDNS
// with SOA-EDIT-API it raises the serial on every change.
type Memory struct {
	// Fail, when set, is consulted before each change.
	Fail func(op, zone string) error

	mu    sync.Mutex
	zones map[string]*Zone
}

// NewMemory returns an empty Memory backend.
func NewMemory() *Memory { return &Memory{zones: map[string]*Zone{}} }

// Version implements Backend.
func (m *Memory) Version(context.Context) (string, error) { return "simulated", nil }

// Zones lists the zone names held.
func (m *Memory) Zones() []string {
	m.mu.Lock()
	defer m.mu.Unlock()
	var out []string
	for n := range m.zones {
		out = append(out, n)
	}
	sort.Strings(out)
	return out
}

// Zone implements Backend.
func (m *Memory) Zone(_ context.Context, name string) (*Zone, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	z := m.zones[name]
	if z == nil {
		return nil, nil
	}
	c := &Zone{Name: z.Name, Serial: z.Serial}
	for _, s := range z.RRSets {
		s.Records = append([]string(nil), s.Records...)
		c.RRSets = append(c.RRSets, s)
	}
	return c, nil
}

// CreateZone implements Backend.
func (m *Memory) CreateZone(_ context.Context, name string, sets []RRSet) error {
	if err := m.fail("create", name); err != nil {
		return err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.zones[name] != nil {
		return &apiError{Status: http.StatusConflict, Message: "Domain '" + name + "' already exists"}
	}
	z := &Zone{Name: name, Serial: 2026010101}
	z.RRSets = append(z.RRSets, sets...)
	m.setSerial(z)
	m.zones[name] = z
	return nil
}

// PatchZone implements Backend.
func (m *Memory) PatchZone(_ context.Context, name string, changes []Change) error {
	if err := m.fail("patch", name); err != nil {
		return err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	z := m.zones[name]
	if z == nil {
		return &apiError{Status: http.StatusNotFound, Message: "Not Found"}
	}
	for _, c := range changes {
		kept := z.RRSets[:0]
		for _, s := range z.RRSets {
			if s.key() != c.key() {
				kept = append(kept, s)
			}
		}
		z.RRSets = kept
		if !c.Delete {
			z.RRSets = append(z.RRSets, c.RRSet)
		}
	}
	z.Serial++
	m.setSerial(z)
	return nil
}

// DeleteZone implements Backend.
func (m *Memory) DeleteZone(_ context.Context, name string) error {
	if err := m.fail("delete", name); err != nil {
		return err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	delete(m.zones, name)
	return nil
}

func (m *Memory) fail(op, zone string) error {
	if m.Fail == nil {
		return nil
	}
	return m.Fail(op, zone)
}

// setSerial writes the zone serial into its SOA record.
func (m *Memory) setSerial(z *Zone) {
	for i, s := range z.RRSets {
		if s.Type == "SOA" && len(s.Records) == 1 {
			z.RRSets[i].Records = []string{soaWithSerial(s.Records[0], z.Serial)}
		}
	}
}
