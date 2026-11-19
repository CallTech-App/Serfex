package visits

import (
	"bytes"
	"compress/gzip"
	"context"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/calltech-app/serfex/agent/internal/host"
)

var day = time.Date(2026, 11, 18, 10, 0, 0, 0, time.UTC)

func newTest(t *testing.T) (*Manager, *host.Simulator) {
	t.Helper()
	sim, err := host.NewSimulator(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	m := NewManager(sim)
	m.Resolve = func(context.Context, string) string { return "" }
	return m, sim
}

// hit is one access log line, minutes after the test's day began.
func hit(ip string, minutes int, method, path string, status int) string {
	at := day.Add(time.Duration(minutes) * time.Minute).Format("02/Jan/2006:15:04:05 -0700")
	return fmt.Sprintf(`%s - - [%s] "%s %s HTTP/1.1" %d 512 "-" "Mozilla/5.0 (test)"`, ip, at, method, path, status)
}

func put(t *testing.T, sim *host.Simulator, domain, name string, lines ...string) {
	t.Helper()
	dir := sim.Path("/var/log/serfex/sites/" + domain)
	if err := os.MkdirAll(dir, 0o755); err != nil {
		t.Fatal(err)
	}
	data := []byte(strings.Join(lines, "\n") + "\n")
	if strings.HasSuffix(name, ".gz") {
		var buf bytes.Buffer
		w := gzip.NewWriter(&buf)
		_, _ = w.Write(data)
		_ = w.Close()
		data = buf.Bytes()
	}
	if err := os.WriteFile(filepath.Join(dir, name), data, 0o640); err != nil {
		t.Fatal(err)
	}
}

func TestReportAcrossCurrentRotatedAndCompressedLogs(t *testing.T) {
	m, sim := newTest(t)
	const bad = "45.155.205.233"
	put(t, sim, "a.com", "access.log",
		hit("88.230.1.1", 300, "GET", "/", 200),
		hit(bad, 301, "POST", "/wp-login.php", 200),
		hit(bad, 302, "POST", "/wp-login.php?x=1", 403))
	put(t, sim, "a.com", "access.log.1", hit(bad, 200, "GET", "/shop", 404))
	put(t, sim, "a.com", "access.log.2.gz", hit("88.230.1.2", 90, "GET", "/", 200), hit(bad, 100, "POST", "/wp-login.php", 200))
	put(t, sim, "b.com", "access.log", hit(bad, 250, "GET", "/admin", 403))
	put(t, sim, "b.com", "error.log", bad+" is not an access log")

	r, err := m.Address(context.Background(), Spec{Address: " " + bad + " "})
	if err != nil {
		t.Fatal(err)
	}
	if r.Address != bad || r.Requests != 5 || r.Denied != 2 || r.Logins != 3 || r.Truncated {
		t.Fatalf("report = %+v", r)
	}
	if r.First == nil || !r.First.Equal(day.Add(100*time.Minute)) || r.Last == nil || !r.Last.Equal(day.Add(302*time.Minute)) {
		t.Fatalf("first = %v, last = %v", r.First, r.Last)
	}
	if r.Since == nil || !r.Since.Equal(day.Add(90*time.Minute)) {
		t.Fatalf("since = %v, want the oldest line that was read", r.Since)
	}
	if len(r.Sites) != 2 || r.Sites[0].Domain != "a.com" || r.Sites[1].Domain != "b.com" {
		t.Fatalf("sites = %+v", r.Sites)
	}
	a := r.Sites[0]
	if a.Requests != 4 || a.Denied != 1 || a.TopPath != "/wp-login.php" || a.TopCount != 3 || !a.Last.Equal(day.Add(302*time.Minute)) {
		t.Fatalf("a.com = %+v", a)
	}
	if a.Statuses["200"] != 2 || a.Statuses["403"] != 1 || a.Statuses["404"] != 1 {
		t.Fatalf("a.com statuses = %v", a.Statuses)
	}
	var order []string
	for _, v := range r.Recent {
		order = append(order, fmt.Sprintf("%s %s %s %d", v.Domain, v.Method, v.Path, v.Status))
	}
	want := "a.com POST /wp-login.php 403|a.com POST /wp-login.php 200|b.com GET /admin 403|a.com GET /shop 404|a.com POST /wp-login.php 200"
	if strings.Join(order, "|") != want || r.Recent[0].Agent != "Mozilla/5.0 (test)" {
		t.Fatalf("recent = %v", order)
	}
}

func TestAPrefixMatchesEveryAddressInItAndAnAddressOnlyItself(t *testing.T) {
	m, sim := newTest(t)
	put(t, sim, "a.com", "access.log",
		hit("45.155.205.233", 1, "GET", "/", 200),
		hit("45.155.205.7", 2, "GET", "/", 200),
		hit("45.155.205.23", 3, "GET", "/", 200),
		hit("45.155.206.1", 4, "GET", "/", 200),
		hit("2001:db8:5::9", 5, "GET", "/", 200))
	ctx := context.Background()
	if r, err := m.Address(ctx, Spec{Address: "45.155.205.99/24"}); err != nil || r.Requests != 3 || r.Address != "45.155.205.0/24" {
		t.Fatalf("prefix: %+v, %v", r, err)
	}
	if r, err := m.Address(ctx, Spec{Address: "45.155.205.23"}); err != nil || r.Requests != 1 {
		t.Fatalf("an address that is the start of another: %+v, %v", r, err)
	}
	if r, err := m.Address(ctx, Spec{Address: "2001:DB8:5:0::9"}); err != nil || r.Requests != 1 || r.Address != "2001:db8:5::9" {
		t.Fatalf("IPv6: %+v, %v", r, err)
	}
	if r, err := m.Address(ctx, Spec{Address: "2001:db8:5::/48"}); err != nil || r.Requests != 1 {
		t.Fatalf("IPv6 prefix: %+v, %v", r, err)
	}
}

func TestMalformedLinesAreSkipped(t *testing.T) {
	m, sim := newTest(t)
	const bad = "45.155.205.233"
	put(t, sim, "a.com", "access.log",
		"",
		bad,
		bad+" - - [not a time] \"GET / HTTP/1.1\" 200 5 \"-\" \"x\"",
		bad+" - - [18/Nov/2026:10:00:00 +0000] no request here",
		bad+" - - [18/Nov/2026:10:00:00 +0000] \"GET /"+strings.Repeat("a", 300_000)+" HTTP/1.1\" 200 5 \"-\" \"x\"",
		"\x00\x01\x02 binary",
		hit(bad, 5, "GET", "/ok", 200),
		bad+" - - [18/Nov/2026:10:06:00 +0000] \"\\x16\\x03\\x01\" 400 150 \"-\" \"-\"")
	put(t, sim, "a.com", "access.log.3.gz", "this is not gzip")
	if err := os.WriteFile(sim.Path("/var/log/serfex/sites/a.com/access.log.2.gz"), []byte("plain text, wrong name\n"), 0o640); err != nil {
		t.Fatal(err)
	}
	r, err := m.Address(context.Background(), Spec{Address: bad})
	if err != nil {
		t.Fatal(err)
	}
	// The good line, and the TLS handshake sent to the plain port: it has
	// a status but no method or path.
	if r.Requests != 2 || len(r.Recent) != 2 || r.Recent[1].Path != "/ok" || r.Recent[0].Status != 400 || r.Recent[0].Path != "" {
		t.Fatalf("report = %+v", r)
	}
}

func TestRecentIsNewestFirstAndCapped(t *testing.T) {
	m, sim := newTest(t)
	const bad = "45.155.205.233"
	var a, b []string
	for i := 0; i < 400; i++ {
		a = append(a, hit(bad, 2*i, "GET", fmt.Sprintf("/a/%d", i), 200))
		b = append(b, hit(bad, 2*i+1, "GET", fmt.Sprintf("/b/%d", i), 200))
	}
	put(t, sim, "a.com", "access.log", a...)
	put(t, sim, "b.com", "access.log", b...)
	ctx := context.Background()
	r, err := m.Address(ctx, Spec{Address: bad, Limit: 50})
	if err != nil {
		t.Fatal(err)
	}
	if r.Requests != 800 || len(r.Recent) != 50 || r.Recent[0].Path != "/b/399" || r.Recent[1].Path != "/a/399" || r.Recent[49].Path != "/a/375" {
		t.Fatalf("requests = %d, recent = %d, first = %+v", r.Requests, len(r.Recent), r.Recent[0])
	}
	if r, _ := m.Address(ctx, Spec{Address: bad}); len(r.Recent) != 200 {
		t.Fatalf("default limit gave %d", len(r.Recent))
	}
	if r, _ := m.Address(ctx, Spec{Address: bad, Limit: 100000}); len(r.Recent) != 500 {
		t.Fatalf("a huge limit gave %d", len(r.Recent))
	}
}

func TestTheTimeBudgetStopsTheSearchAfterTheNewestLogs(t *testing.T) {
	m, sim := newTest(t)
	const bad = "45.155.205.233"
	put(t, sim, "a.com", "access.log", hit(bad, 500, "GET", "/new", 200))
	put(t, sim, "b.com", "access.log", hit(bad, 501, "GET", "/new", 200))
	put(t, sim, "a.com", "access.log.1", hit(bad, 100, "GET", "/old", 200))
	put(t, sim, "a.com", "access.log.2.gz", hit(bad, 50, "GET", "/older", 200))
	// Every look at the clock takes 400 ms; the budget is a second.
	now := day
	m.Now = func() time.Time { now = now.Add(400 * time.Millisecond); return now }
	m.Budget = time.Second
	r, err := m.Address(context.Background(), Spec{Address: bad})
	if err != nil {
		t.Fatal(err)
	}
	if !r.Truncated || r.Requests != 2 || r.Recent[0].Path != "/new" || r.Recent[1].Path != "/new" {
		t.Fatalf("report = %+v", r)
	}
	if r.Since == nil || !r.Since.Equal(day.Add(500*time.Minute)) {
		t.Fatalf("since = %v, want the oldest line of the logs that were read", r.Since)
	}
}

func TestACancelledCallStops(t *testing.T) {
	m, sim := newTest(t)
	put(t, sim, "a.com", "access.log", hit("45.155.205.233", 1, "GET", "/", 200))
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	if _, err := m.Address(ctx, Spec{Address: "45.155.205.233"}); !errors.Is(err, context.Canceled) {
		t.Fatalf("err = %v", err)
	}
}

func TestWhatCannotBeLookedUpIsRefused(t *testing.T) {
	m, _ := newTest(t)
	for _, a := range []string{"", "nope", "10.0.0.0/8", "2001:db8::/32", "fe80::1%eth0", "0.0.0.0/0"} {
		_, err := m.Address(context.Background(), Spec{Address: a})
		var v *ValidationError
		if !errors.As(err, &v) {
			t.Errorf("%q: err = %v, want a validation error", a, err)
		}
	}
}

func TestWithoutLogsTheReportIsEmptyNotNull(t *testing.T) {
	m, _ := newTest(t)
	r, err := m.Address(context.Background(), Spec{Address: "45.155.205.233"})
	if err != nil {
		t.Fatal(err)
	}
	if r.Requests != 0 || r.Sites == nil || r.Recent == nil || r.First != nil || r.Since != nil || r.Truncated {
		t.Fatalf("report = %+v", r)
	}
}

func TestTheHostnameIsLookedUpForOneAddressOnly(t *testing.T) {
	m, _ := newTest(t)
	var asked []string
	m.Resolve = func(_ context.Context, a string) string { asked = append(asked, a); return "scanner.example.net" }
	ctx := context.Background()
	if r, _ := m.Address(ctx, Spec{Address: "45.155.205.233"}); r.Hostname != "scanner.example.net" {
		t.Fatalf("hostname = %q", r.Hostname)
	}
	if r, _ := m.Address(ctx, Spec{Address: "45.155.205.0/24"}); r.Hostname != "" || len(asked) != 1 {
		t.Fatalf("a prefix was looked up: %q, asked %v", r.Hostname, asked)
	}
}

// The log directory belongs to root, but a link there must still never
// make the agent read another file.
func TestALinkInTheLogDirectoryIsNotFollowed(t *testing.T) {
	m, sim := newTest(t)
	const bad = "45.155.205.233"
	put(t, sim, "a.com", "access.log.1", hit(bad, 1, "GET", "/real", 200))
	secret := filepath.Join(t.TempDir(), "secret")
	if err := os.WriteFile(secret, []byte(hit(bad, 2, "GET", "/secret", 200)+"\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(secret, sim.Path("/var/log/serfex/sites/a.com/access.log")); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(filepath.Dir(secret), sim.Path("/var/log/serfex/sites/linked.com")); err != nil {
		t.Fatal(err)
	}
	r, err := m.Address(context.Background(), Spec{Address: bad})
	if err != nil {
		t.Fatal(err)
	}
	if r.Requests != 1 || r.Recent[0].Path != "/real" {
		t.Fatalf("report = %+v", r)
	}
}
