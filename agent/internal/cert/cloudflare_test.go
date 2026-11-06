package cert

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/calltech-app/serfex/agent/internal/cloudflare"
)

const cfZoneID = "0123456789abcdef0123456789abcdef"

// fakeCloudflare records TXT records added and removed through the API.
type fakeCloudflare struct {
	mu      sync.Mutex
	records map[string]string // id → name=value
	added   []string
	deleted []string
	auth    []string
	next    int
}

func (f *fakeCloudflare) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.auth = append(f.auth, r.Header.Get("Authorization"))
	prefix := "/zones/" + cfZoneID + "/dns_records"
	switch {
	case r.Method == http.MethodPost && r.URL.Path == prefix:
		var body map[string]any
		_ = json.NewDecoder(r.Body).Decode(&body)
		f.next++
		id := strings.Repeat("a", 31) + string(rune('0'+f.next))
		f.records[id] = body["name"].(string) + "=" + body["content"].(string)
		f.added = append(f.added, f.records[id])
		_, _ = w.Write([]byte(`{"success":true,"errors":[],"result":{"id":"` + id + `"}}`))
	case r.Method == http.MethodDelete && strings.HasPrefix(r.URL.Path, prefix+"/"):
		id := strings.TrimPrefix(r.URL.Path, prefix+"/")
		f.deleted = append(f.deleted, f.records[id])
		delete(f.records, id)
		_, _ = w.Write([]byte(`{"success":true,"errors":[],"result":{"id":"` + id + `"}}`))
	default:
		w.WriteHeader(http.StatusNotFound)
		_, _ = w.Write([]byte(`{"success":false,"errors":[{"code":7003,"message":"Could not route"}]}`))
	}
}

func TestIssueWildcardAtCloudflare(t *testing.T) {
	_, sim, _ := newTest(t)
	// The plain simulated CA: the probe would look for the answers in
	// this server's DNS.
	m := NewManager(sim, nil, &Simulated{})
	f := &fakeCloudflare{records: map[string]string{}}
	srv := httptest.NewServer(f)
	defer srv.Close()
	m.cfBase = srv.URL
	var looked []string
	m.cfLookup = func(_ context.Context, server, name string) ([]string, error) {
		looked = append(looked, server+" "+name)
		f.mu.Lock()
		defer f.mu.Unlock()
		var out []string
		for _, r := range f.records {
			if n, v, _ := strings.Cut(r, "="); n == name {
				out = append(out, v)
			}
		}
		return out, nil
	}
	m.cfWait = 5 * time.Second

	res, err := m.Issue(context.Background(), IssueSpec{
		Name: zone, Names: []string{zone, "*." + zone}, Challenge: DNS01, DNSZone: zone,
		Cloudflare: &cloudflare.Zone{ZoneID: cfZoneID, Token: "secret-token", Nameservers: []string{"ada.ns.cloudflare.com"}},
	})
	if err != nil {
		t.Fatal(err)
	}
	if len(res.Names) != 2 {
		t.Fatalf("names %v", res.Names)
	}
	if len(f.added) != 2 || len(f.deleted) != 2 || len(f.records) != 0 {
		t.Fatalf("added %v, deleted %v, left %v", f.added, f.deleted, f.records)
	}
	if !strings.HasPrefix(f.added[0], "_acme-challenge."+zone+"=") {
		t.Fatalf("added %v", f.added)
	}
	for _, a := range f.auth {
		if a != "Bearer secret-token" {
			t.Fatalf("authorization %q", a)
		}
	}
	if len(looked) == 0 || !strings.HasPrefix(looked[0], "ada.ns.cloudflare.com _acme-challenge.") {
		t.Fatalf("looked up %v", looked)
	}
}

func TestIssueCloudflareRefusals(t *testing.T) {
	m, _, _ := newTest(t)
	ctx := context.Background()
	for _, spec := range []IssueSpec{
		{Name: zone, Names: []string{zone}, Challenge: HTTP01, Cloudflare: &cloudflare.Zone{ZoneID: cfZoneID, Token: "t"}},
		{Name: zone, Names: []string{zone}, Challenge: DNS01, DNSZone: zone, Cloudflare: &cloudflare.Zone{ZoneID: "nope", Token: "t"}},
		{Name: zone, Names: []string{zone}, Challenge: DNS01, DNSZone: zone, Cloudflare: &cloudflare.Zone{ZoneID: cfZoneID, Token: ""}},
	} {
		if _, err := m.Issue(ctx, spec); err == nil {
			t.Fatalf("accepted %+v", spec)
		}
	}
}

func TestIssueCloudflareErrorIsReported(t *testing.T) {
	_, sim, _ := newTest(t)
	m := NewManager(sim, nil, &Simulated{})
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusForbidden)
		_, _ = w.Write([]byte(`{"success":false,"errors":[{"code":10000,"message":"Authentication error"}]}`))
	}))
	defer srv.Close()
	m.cfBase = srv.URL
	_, err := m.Issue(context.Background(), IssueSpec{
		Name: zone, Names: []string{zone}, Challenge: DNS01, DNSZone: zone,
		Cloudflare: &cloudflare.Zone{ZoneID: cfZoneID, Token: "t"},
	})
	if err == nil || !strings.Contains(err.Error(), "Authentication error (10000)") {
		t.Fatalf("err = %v", err)
	}
}
