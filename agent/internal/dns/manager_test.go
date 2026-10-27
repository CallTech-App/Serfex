package dns

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"testing"

	"github.com/winchester37/servik/agent/internal/host"
)

func newTest(t *testing.T) (*Manager, *Memory, *host.Simulator) {
	t.Helper()
	sim, err := host.NewSimulator(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	mem := NewMemory()
	return NewSimulatedManager(sim, mem), mem, sim
}

func setUp(t *testing.T, m *Manager) {
	t.Helper()
	if _, err := m.Setup(context.Background()); err != nil {
		t.Fatal(err)
	}
}

func TestZoneOperationsNeedSetup(t *testing.T) {
	m, _, _ := newTest(t)
	_, err := m.ApplyZone(context.Background(), zoneSpec())
	if !errors.Is(err, ErrNotSetUp) {
		t.Fatalf("err = %v, want ErrNotSetUp", err)
	}
	if st := m.Status(context.Background()); st.Configured || st.Running || st.Installed {
		t.Fatalf("status before setup = %+v", st)
	}
}

func TestSetupInstallsConfiguresAndStarts(t *testing.T) {
	m, _, sim := newTest(t)
	// Ubuntu's default: /etc/resolv.conf points at the stub resolver.
	if err := os.MkdirAll(sim.Path("/run/systemd/resolve"), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink("../run/systemd/resolve/stub-resolv.conf", sim.Path("/etc/resolv.conf")); err != nil {
		t.Fatal(err)
	}

	res, err := m.Setup(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	if !res.Installed || !res.Changed || res.Version != "simulated" {
		t.Fatalf("result = %+v", res)
	}
	for _, c := range []string{
		"apt-get install -y -q --no-install-recommends pdns-server pdns-backend-lmdb",
		"systemctl disable --now pdns.service",
		"/usr/sbin/pdns_server --config-name=servik --config=check",
		"systemctl restart systemd-resolved",
		"systemctl enable pdns@servik",
		"systemctl restart pdns@servik",
	} {
		if !sim.Ran(c) {
			t.Errorf("did not run %q", c)
		}
	}

	conf, err := os.ReadFile(sim.Path(configPath))
	if err != nil {
		t.Fatal(err)
	}
	for _, want := range []string{"Managed by Servik", "launch=lmdb", "api=yes", "webserver-address=127.0.0.1", "disable-axfr=yes\nallow-axfr-ips=\ncache-ttl=20\nnegquery-cache-ttl=60\nlog-dns-queries=no\nloglevel=4\nversion-string=anonymous"} {
		if !strings.Contains(string(conf), want) {
			t.Errorf("config lacks %q:\n%s", want, conf)
		}
	}
	if fi, _ := os.Stat(sim.Path(configPath)); fi.Mode().Perm() != 0o640 {
		t.Errorf("config mode = %v, want 0640 (it holds the API key)", fi.Mode().Perm())
	}
	cfg, err := m.readConfig()
	if err != nil || len(cfg.Key) != 64 || cfg.URL != "http://127.0.0.1:8081" {
		t.Fatalf("readConfig = %+v, %v", cfg, err)
	}
	if target, _ := os.Readlink(sim.Path("/etc/resolv.conf")); target != "/run/systemd/resolve/resolv.conf" {
		t.Errorf("resolv.conf -> %s", target)
	}
	if b, _ := os.ReadFile(sim.Path(resolvedDropIn)); !strings.Contains(string(b), "DNSStubListener=no") {
		t.Errorf("resolved drop-in = %q", b)
	}
	if st := m.Status(context.Background()); !st.Installed || !st.Configured || !st.Running {
		t.Fatalf("status after setup = %+v", st)
	}

	// Again: same key, nothing restarted.
	before := len(sim.Commands())
	res, err = m.Setup(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	if res.Changed || res.Installed {
		t.Fatalf("second setup = %+v", res)
	}
	if again, _ := m.readConfig(); again.Key != cfg.Key {
		t.Error("API key changed on the second run")
	}
	for _, c := range sim.Commands()[before:] {
		if strings.Contains(c.String(), "restart") || strings.HasPrefix(c.String(), "apt-get") {
			t.Errorf("second setup ran %q", c)
		}
	}
}

func TestSetupRollsBackWhenPowerDNSRejectsConfig(t *testing.T) {
	m, _, sim := newTest(t)
	sim.Fail = func(c host.Command) error {
		if strings.Contains(c.String(), "--config=check") {
			return errors.New("Fatal error: Trying to set unknown setting")
		}
		return nil
	}
	_, err := m.Setup(context.Background())
	var op *OperationError
	if !errors.As(err, &op) || !strings.Contains(err.Error(), "rejected the configuration") {
		t.Fatalf("err = %v", err)
	}
	if _, err := os.Stat(sim.Path(configPath)); !os.IsNotExist(err) {
		t.Error("config was left behind")
	}
	if sim.Ran("systemctl restart pdns@servik") {
		t.Error("PowerDNS was restarted with a rejected config")
	}
}

func TestApplyZoneCreatesUpdatesAndIsIdempotent(t *testing.T) {
	m, mem, _ := newTest(t)
	setUp(t, m)
	ctx := context.Background()

	spec := zoneSpec(
		Record{Name: "@", Type: "A", TTL: 3600, Content: "185.98.142.21"},
		Record{Name: "www", Type: "CNAME", TTL: 3600, Content: "kahveatolyesi.com.tr."},
	)
	res, err := m.ApplyZone(ctx, spec)
	if err != nil {
		t.Fatal(err)
	}
	if !res.Created || !res.Changed || res.Serial == 0 {
		t.Fatalf("create = %+v", res)
	}
	first := res.Serial

	res, err = m.ApplyZone(ctx, spec)
	if err != nil {
		t.Fatal(err)
	}
	if res.Created || res.Changed || res.Serial != first {
		t.Fatalf("re-apply = %+v", res)
	}

	// Change the A record, drop www, add TXT; raise refresh.
	spec.Records = append(spec.Records[:2],
		Record{Name: "@", Type: "A", TTL: 3600, Content: "185.98.142.30"},
		Record{Name: "@", Type: "TXT", TTL: 3600, Content: "v=spf1 a mx ~all"},
	)
	spec.SOA.Refresh = 7200
	res, err = m.ApplyZone(ctx, spec)
	if err != nil {
		t.Fatal(err)
	}
	if !res.Changed || res.Serial <= first {
		t.Fatalf("update = %+v", res)
	}

	info, err := m.GetZone(ctx, "kahveatolyesi.com.tr")
	if err != nil {
		t.Fatal(err)
	}
	got := map[string]string{}
	for _, r := range info.Records {
		got[r.Name+" "+r.Type] += r.Content + ";"
	}
	want := map[string]string{
		"@ NS":  "ns1.servik.net.tr.;ns2.servik.net.tr.;",
		"@ A":   "185.98.142.30;",
		"@ TXT": "v=spf1 a mx ~all;",
	}
	if len(got) != len(want) {
		t.Errorf("records = %v", got)
	}
	for k, v := range want {
		if got[k] != v {
			t.Errorf("%s = %q, want %q", k, got[k], v)
		}
	}
	if !strings.Contains(info.SOA, " 7200 ") {
		t.Errorf("SOA = %s", info.SOA)
	}
	if mem.Zones()[0] != "kahveatolyesi.com.tr." {
		t.Errorf("zones = %v", mem.Zones())
	}
}

func TestApplyZoneReportsRejection(t *testing.T) {
	m, mem, _ := newTest(t)
	setUp(t, m)
	mem.Fail = func(op, zone string) error {
		return &apiError{Status: 422, Message: "RRset www IN A: Conflicts with pre-existing RRset"}
	}
	_, err := m.ApplyZone(context.Background(), zoneSpec())
	var v *ValidationError
	if !errors.As(err, &v) || !strings.Contains(err.Error(), "Conflicts") {
		t.Fatalf("err = %v", err)
	}
}

func TestDeleteZone(t *testing.T) {
	m, mem, _ := newTest(t)
	setUp(t, m)
	ctx := context.Background()
	if _, err := m.ApplyZone(ctx, zoneSpec()); err != nil {
		t.Fatal(err)
	}
	res, err := m.DeleteZone(ctx, "kahveatolyesi.com.tr")
	if err != nil || !res.Deleted {
		t.Fatalf("delete = %+v, %v", res, err)
	}
	if len(mem.Zones()) != 0 {
		t.Fatalf("zones left: %v", mem.Zones())
	}
	res, err = m.DeleteZone(ctx, "kahveatolyesi.com.tr")
	if err != nil || res.Deleted {
		t.Fatalf("second delete = %+v, %v", res, err)
	}
	if _, err := m.DeleteZone(ctx, "../etc"); err == nil {
		t.Fatal("invalid zone name accepted")
	}
}

func TestDiffKeepsSerialOfChangedSOA(t *testing.T) {
	cur := &Zone{Name: "a.com.", Serial: 2026101805, RRSets: []RRSet{
		{Name: "a.com.", Type: "SOA", TTL: 3600, Records: []string{"ns1.a.com. h.a.com. 2026101805 10800 3600 604800 3600"}},
		{Name: "old.a.com.", Type: "A", TTL: 3600, Records: []string{"1.1.1.1"}},
	}}
	want := []RRSet{{Name: "a.com.", Type: "SOA", TTL: 3600, Records: []string{"ns1.a.com. h.a.com. 0 7200 3600 604800 3600"}}}
	changes := diff(cur, want)
	if len(changes) != 2 {
		t.Fatalf("changes = %+v", changes)
	}
	if changes[0].Records[0] != "ns1.a.com. h.a.com. 2026101805 7200 3600 604800 3600" {
		t.Errorf("SOA change = %v", changes[0].Records)
	}
	if !changes[1].Delete || changes[1].Name != "old.a.com." {
		t.Errorf("second change = %+v", changes[1])
	}
	want[0].Records[0] = "ns1.a.com. h.a.com. 0 10800 3600 604800 3600"
	if c := diff(cur, append(want, cur.RRSets[1])); len(c) != 0 {
		t.Errorf("unchanged zone gave %+v", c)
	}
}

// TestAPIClient checks the HTTP calls against a minimal fake of the
// PowerDNS API.
func TestAPIClient(t *testing.T) {
	var calls []string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("X-API-Key") != "k" {
			w.WriteHeader(http.StatusUnauthorized)
			return
		}
		body, _ := io.ReadAll(r.Body)
		calls = append(calls, r.Method+" "+r.URL.EscapedPath()+" "+string(body))
		switch {
		case r.URL.Path == "/api/v1/servers/localhost":
			_, _ = w.Write([]byte(`{"version":"4.8.3"}`))
		case r.Method == http.MethodGet && r.URL.Path == "/api/v1/servers/localhost/zones/missing.com.":
			w.WriteHeader(http.StatusNotFound)
			_, _ = w.Write([]byte(`Not Found`))
		case r.Method == http.MethodGet:
			_, _ = w.Write([]byte(`{"name":"a.com.","serial":7,"rrsets":[{"name":"a.com.","type":"A","ttl":60,"records":[{"content":"1.2.3.4","disabled":false},{"content":"5.6.7.8","disabled":true}]}]}`))
		case r.Method == http.MethodPatch:
			w.WriteHeader(http.StatusUnprocessableEntity)
			_, _ = w.Write([]byte(`{"error":"Conflicts with pre-existing RRset"}`))
		default:
			w.WriteHeader(http.StatusNoContent)
		}
	}))
	defer srv.Close()
	ctx := context.Background()

	if _, err := NewAPI(APIConfig{URL: srv.URL, Key: "bad"}).Version(ctx); err == nil {
		t.Fatal("wrong key accepted")
	}
	api := NewAPI(APIConfig{URL: srv.URL, Key: "k"})
	if v, err := api.Version(ctx); err != nil || v != "4.8.3" {
		t.Fatalf("version = %q, %v", v, err)
	}
	if z, err := api.Zone(ctx, "missing.com."); err != nil || z != nil {
		t.Fatalf("missing zone = %+v, %v", z, err)
	}
	z, err := api.Zone(ctx, "a.com.")
	if err != nil || z.Serial != 7 || len(z.RRSets) != 1 || len(z.RRSets[0].Records) != 1 {
		t.Fatalf("zone = %+v, %v", z, err)
	}
	if err := api.CreateZone(ctx, "a.com.", []RRSet{{Name: "a.com.", Type: "NS", TTL: 60, Records: []string{"ns1.a.com."}}}); err != nil {
		t.Fatal(err)
	}
	err = api.PatchZone(ctx, "a.com.", []Change{{RRSet: RRSet{Name: "x.a.com.", Type: "A"}, Delete: true}})
	var ae *apiError
	if !errors.As(err, &ae) || ae.Status != 422 || ae.Message != "Conflicts with pre-existing RRset" {
		t.Fatalf("patch err = %v", err)
	}
	if err := api.DeleteZone(ctx, "a.com."); err != nil {
		t.Fatal(err)
	}

	var create struct {
		Kind       string `json:"kind"`
		SOAEditAPI string `json:"soa_edit_api"`
	}
	for _, c := range calls {
		if strings.HasPrefix(c, "POST /api/v1/servers/localhost/zones ") {
			_ = json.Unmarshal([]byte(strings.SplitN(c, " ", 3)[2]), &create)
		}
		if strings.HasPrefix(c, "PATCH") && !strings.Contains(c, `"changetype":"DELETE"`) {
			t.Errorf("patch body = %s", c)
		}
	}
	if create.Kind != "Native" || create.SOAEditAPI != "DEFAULT" {
		t.Errorf("create = %+v", create)
	}
}
