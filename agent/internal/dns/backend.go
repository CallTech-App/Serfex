package dns

import (
	"bytes"
	"context"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"
)

// Zone is a zone as the DNS server holds it.
type Zone struct {
	Name   string // absolute, with trailing dot
	Serial int64
	RRSets []RRSet
	// Kind is Native (no NOTIFY), Master (NOTIFY to secondaries) or
	// Producer (a catalog zone).
	Kind string
	// Catalog is the catalog zone that lists this zone, if any.
	Catalog    string
	NSEC3Param string
	SOAEdit    string
	// DNSSEC is true while the zone has an active key.
	DNSSEC bool
}

// ZoneOptions are a zone's settings besides its records; nil fields stay
// as they are.
type ZoneOptions struct {
	Kind       *string
	Catalog    *string
	NSEC3Param *string
	SOAEdit    *string
}

// Key is a DNSSEC signing key of a zone.
type Key struct {
	ID        int
	Active    bool
	Published bool
	Flags     int
	Algorithm string
	// DNSKEY is the record's content: "257 3 13 <public key>".
	DNSKEY string
	// DS are the DS record contents for the parent zone, one per digest.
	DS []string
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
	// Zones lists every zone, without records.
	Zones(ctx context.Context) ([]Zone, error)
	// SetZoneOptions changes a zone's kind, catalog or DNSSEC settings.
	SetZoneOptions(ctx context.Context, name string, o ZoneOptions) error
	// Keys lists the zone's DNSSEC keys.
	Keys(ctx context.Context, zone string) ([]Key, error)
	// PrivateKey returns a key's private part in PowerDNS's format.
	PrivateKey(ctx context.Context, zone string, id int) (string, error)
	// AddKey adds an active combined signing key: the given private key,
	// or a new ECDSA P-256 key when it is empty.
	AddKey(ctx context.Context, zone, private string) (Key, error)
	// SetKeyActive turns a key's signing and publishing on or off.
	SetKeyActive(ctx context.Context, zone string, id int, active bool) error
	// Rectify brings the zone's DNSSEC ordering data up to date.
	Rectify(ctx context.Context, zone string) error
	// Notify tells the zone's secondary servers that it changed.
	Notify(ctx context.Context, zone string) error
	// FlushCache drops cached answers for the zone.
	FlushCache(ctx context.Context, zone string) error
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
	APIRectify  bool       `json:"api_rectify,omitempty"`
	Nameservers []string   `json:"nameservers"`
	RRSets      []apiRRSet `json:"rrsets"`
	// Read only: what the zone list and zone answers carry besides.
	Catalog    string `json:"catalog,omitempty"`
	NSEC3Param string `json:"nsec3param,omitempty"`
	SOAEdit    string `json:"soa_edit,omitempty"`
	DNSSEC     bool   `json:"dnssec,omitempty"`
}

type apiKey struct {
	ID         int      `json:"id,omitempty"`
	KeyType    string   `json:"keytype,omitempty"`
	Active     bool     `json:"active"`
	Published  bool     `json:"published"`
	Flags      int      `json:"flags,omitempty"`
	Algorithm  string   `json:"algorithm,omitempty"`
	DNSKEY     string   `json:"dnskey,omitempty"`
	DS         []string `json:"ds,omitempty"`
	PrivateKey string   `json:"privatekey,omitempty"`
}

func (k apiKey) key() Key {
	return Key{ID: k.ID, Active: k.Active, Published: k.Published, Flags: k.Flags, Algorithm: k.Algorithm, DNSKEY: k.DNSKEY, DS: k.DS}
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
	out := &Zone{Name: z.Name, Serial: z.Serial, Kind: z.Kind, Catalog: z.Catalog, NSEC3Param: z.NSEC3Param, SOAEdit: z.SOAEdit, DNSSEC: z.DNSSEC}
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

// CreateZone implements Backend. Zones start native (no NOTIFY), PowerDNS
// raises the SOA serial on every API change (SOA-EDIT-API DEFAULT) and
// keeps a signed zone's ordering data right on every change (API-RECTIFY).
func (a *API) CreateZone(ctx context.Context, name string, sets []RRSet) error {
	z := apiZone{Name: name, Kind: "Native", SOAEditAPI: "DEFAULT", APIRectify: true, Nameservers: []string{}}
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

// Zones implements Backend.
func (a *API) Zones(ctx context.Context) ([]Zone, error) {
	var list []apiZone
	if err := a.do(ctx, http.MethodGet, "/zones", nil, &list); err != nil {
		return nil, err
	}
	out := make([]Zone, 0, len(list))
	for _, z := range list {
		out = append(out, Zone{Name: z.Name, Serial: z.Serial, Kind: z.Kind, Catalog: z.Catalog, DNSSEC: z.DNSSEC})
	}
	return out, nil
}

// SetZoneOptions implements Backend.
func (a *API) SetZoneOptions(ctx context.Context, name string, o ZoneOptions) error {
	body := map[string]any{}
	for k, v := range map[string]*string{"kind": o.Kind, "catalog": o.Catalog, "nsec3param": o.NSEC3Param, "soa_edit": o.SOAEdit} {
		if v != nil {
			body[k] = *v
		}
	}
	if len(body) == 0 {
		return nil
	}
	if o.NSEC3Param != nil {
		body["api_rectify"] = true
	}
	return a.do(ctx, http.MethodPut, zonePath(name), body, nil)
}

// Keys implements Backend.
func (a *API) Keys(ctx context.Context, zone string) ([]Key, error) {
	var list []apiKey
	if err := a.do(ctx, http.MethodGet, zonePath(zone)+"/cryptokeys", nil, &list); err != nil {
		return nil, err
	}
	out := make([]Key, 0, len(list))
	for _, k := range list {
		out = append(out, k.key())
	}
	sort.Slice(out, func(i, j int) bool { return out[i].ID < out[j].ID })
	return out, nil
}

// PrivateKey implements Backend.
func (a *API) PrivateKey(ctx context.Context, zone string, id int) (string, error) {
	var k apiKey
	if err := a.do(ctx, http.MethodGet, zonePath(zone)+"/cryptokeys/"+strconv.Itoa(id), nil, &k); err != nil {
		return "", err
	}
	return k.PrivateKey, nil
}

// AddKey implements Backend.
func (a *API) AddKey(ctx context.Context, zone, private string) (Key, error) {
	req := apiKey{KeyType: "csk", Active: true, Published: true}
	if private == "" {
		req.Algorithm = "ECDSAP256SHA256"
	} else {
		req.PrivateKey = private
	}
	var k apiKey
	if err := a.do(ctx, http.MethodPost, zonePath(zone)+"/cryptokeys", req, &k); err != nil {
		return Key{}, err
	}
	return k.key(), nil
}

// SetKeyActive implements Backend.
func (a *API) SetKeyActive(ctx context.Context, zone string, id int, active bool) error {
	return a.do(ctx, http.MethodPut, zonePath(zone)+"/cryptokeys/"+strconv.Itoa(id), apiKey{Active: active, Published: active}, nil)
}

// Rectify implements Backend.
func (a *API) Rectify(ctx context.Context, zone string) error {
	return a.do(ctx, http.MethodPut, zonePath(zone)+"/rectify", nil, nil)
}

// Notify implements Backend.
func (a *API) Notify(ctx context.Context, zone string) error {
	return a.do(ctx, http.MethodPut, zonePath(zone)+"/notify", nil, nil)
}

// FlushCache implements Backend.
func (a *API) FlushCache(ctx context.Context, zone string) error {
	return a.do(ctx, http.MethodPut, "/cache/flush?domain="+url.QueryEscape(zone), nil, nil)
}

// Memory is an in-memory Backend for tests and dry runs. Like PowerDNS
// with SOA-EDIT-API it raises the serial on every change.
type Memory struct {
	// Fail, when set, is consulted before each change.
	Fail func(op, zone string) error

	mu    sync.Mutex
	zones map[string]*Zone
	keys  map[string][]memKey
	// Notified counts the NOTIFYs sent per zone.
	Notified map[string]int
	lastKey  int
}

type memKey struct {
	Key
	private string
}

// NewMemory returns an empty Memory backend.
func NewMemory() *Memory {
	return &Memory{zones: map[string]*Zone{}, keys: map[string][]memKey{}, Notified: map[string]int{}}
}

// Version implements Backend.
func (m *Memory) Version(context.Context) (string, error) { return "simulated", nil }

// ZoneNames lists the zone names held.
func (m *Memory) ZoneNames() []string {
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
	c := *z
	c.RRSets = nil
	c.DNSSEC = m.signed(name)
	for _, s := range z.RRSets {
		s.Records = append([]string(nil), s.Records...)
		c.RRSets = append(c.RRSets, s)
	}
	return &c, nil
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
	z := &Zone{Name: name, Serial: 2026010101, Kind: "Native"}
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
	delete(m.keys, name)
	return nil
}

// Zones implements Backend.
func (m *Memory) Zones(context.Context) ([]Zone, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	out := []Zone{}
	for _, z := range m.zones {
		out = append(out, Zone{Name: z.Name, Serial: z.Serial, Kind: z.Kind, Catalog: z.Catalog, DNSSEC: m.signed(z.Name)})
	}
	sort.Slice(out, func(i, j int) bool { return out[i].Name < out[j].Name })
	return out, nil
}

// SetZoneOptions implements Backend.
func (m *Memory) SetZoneOptions(_ context.Context, name string, o ZoneOptions) error {
	if err := m.fail("options", name); err != nil {
		return err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	z := m.zones[name]
	if z == nil {
		return &apiError{Status: http.StatusNotFound, Message: "Not Found"}
	}
	if o.Kind != nil {
		z.Kind = *o.Kind
	}
	if o.Catalog != nil {
		if *o.Catalog != "" && (m.zones[*o.Catalog] == nil || m.zones[*o.Catalog].Kind != "Producer") {
			return &apiError{Status: http.StatusUnprocessableEntity, Message: "catalog zone does not exist"}
		}
		z.Catalog = *o.Catalog
	}
	if o.NSEC3Param != nil {
		z.NSEC3Param = *o.NSEC3Param
	}
	if o.SOAEdit != nil {
		z.SOAEdit = *o.SOAEdit
	}
	return nil
}

// Keys implements Backend.
func (m *Memory) Keys(_ context.Context, zone string) ([]Key, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.zones[zone] == nil {
		return nil, &apiError{Status: http.StatusNotFound, Message: "Not Found"}
	}
	out := []Key{}
	for _, k := range m.keys[zone] {
		out = append(out, k.Key)
	}
	return out, nil
}

// PrivateKey implements Backend.
func (m *Memory) PrivateKey(_ context.Context, zone string, id int) (string, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	for _, k := range m.keys[zone] {
		if k.ID == id {
			return k.private, nil
		}
	}
	return "", &apiError{Status: http.StatusNotFound, Message: "Not Found"}
}

// AddKey implements Backend. Its keys are made up: the public parts are
// derived from the private text, which is enough to tell keys apart.
func (m *Memory) AddKey(_ context.Context, zone, private string) (Key, error) {
	if err := m.fail("addkey", zone); err != nil {
		return Key{}, err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.zones[zone] == nil {
		return Key{}, &apiError{Status: http.StatusNotFound, Message: "Not Found"}
	}
	if private == "" {
		b := make([]byte, 32)
		_, _ = rand.Read(b)
		private = "Private-key-format: v1.2\nAlgorithm: 13 (ECDSAP256SHA256)\nPrivateKey: " + base64.StdEncoding.EncodeToString(b) + "\n"
	} else if !strings.HasPrefix(private, "Private-key-format:") {
		return Key{}, &apiError{Status: http.StatusUnprocessableEntity, Message: "Key could not be parsed"}
	}
	sum := sha256.Sum256([]byte(strings.TrimSpace(private)))
	tag := int(sum[0])<<8 | int(sum[1])
	m.lastKey++
	k := Key{ID: m.lastKey, Active: true, Published: true, Flags: 257, Algorithm: "ECDSAP256SHA256",
		DNSKEY: "257 3 13 " + base64.StdEncoding.EncodeToString(sum[:]),
		DS:     []string{fmt.Sprintf("%d 13 1 %x", tag, sum[:20]), fmt.Sprintf("%d 13 2 %x", tag, sum), fmt.Sprintf("%d 13 4 %x%x", tag, sum, sum[:16])}}
	m.keys[zone] = append(m.keys[zone], memKey{Key: k, private: private})
	return k, nil
}

// SetKeyActive implements Backend.
func (m *Memory) SetKeyActive(_ context.Context, zone string, id int, active bool) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	for i, k := range m.keys[zone] {
		if k.ID == id {
			m.keys[zone][i].Active, m.keys[zone][i].Published = active, active
			return nil
		}
	}
	return &apiError{Status: http.StatusNotFound, Message: "Not Found"}
}

// Rectify implements Backend.
func (m *Memory) Rectify(context.Context, string) error { return nil }

// Notify implements Backend.
func (m *Memory) Notify(_ context.Context, zone string) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	if z := m.zones[zone]; z == nil || z.Kind != "Master" && z.Kind != "Producer" {
		return &apiError{Status: http.StatusUnprocessableEntity, Message: "Domain is not a primary zone"}
	}
	m.Notified[zone]++
	return nil
}

// FlushCache implements Backend.
func (m *Memory) FlushCache(context.Context, string) error { return nil }

// signed reports whether the zone has an active key; m.mu is held.
func (m *Memory) signed(zone string) bool {
	for _, k := range m.keys[zone] {
		if k.Active {
			return true
		}
	}
	return false
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
