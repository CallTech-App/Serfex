//go:build realhost

// This test sets up a real PowerDNS and checks its answers with dig. It
// needs root and changes the machine, so it only builds with -tags realhost:
//
//	sudo go test -tags realhost -run TestRealHost ./internal/dns
//
// CI runs it on a throwaway runner.
package dns

import (
	"context"
	"os"
	"os/exec"
	"strconv"
	"strings"
	"testing"
	"time"

	"github.com/calltech-app/serfex/agent/internal/host"
)

// serviceHost is the real system. Where systemd is not PID 1 (containers)
// it starts pdns_server itself instead of through systemctl.
type serviceHost struct {
	host.System
	t *testing.T
}

func hasSystemd() bool {
	b, err := os.ReadFile("/proc/1/comm")
	return err == nil && strings.TrimSpace(string(b)) == "systemd"
}

func (h serviceHost) Run(ctx context.Context, c host.Command) (string, error) {
	if c.Name != "systemctl" || hasSystemd() {
		return h.System.Run(ctx, c)
	}
	if len(c.Args) == 2 && c.Args[0] == "restart" && c.Args[1] == unit {
		_ = exec.Command("pkill", "-f", "pdns_server --config-name="+instance).Run()
		time.Sleep(300 * time.Millisecond)
		_ = os.MkdirAll("/run/pdns-"+instance, 0o755)
		_ = exec.Command("chown", "pdns:pdns", "/run/pdns-"+instance).Run()
		cmd := exec.Command(pdnsBinary, "--config-name="+instance, "--guardian=no", "--daemon=no",
			"--setuid=pdns", "--setgid=pdns", "--socket-dir=/run/pdns-"+instance)
		if f := os.Getenv("SERFEX_PDNS_LOG"); f != "" {
			if w, err := os.OpenFile(f, os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0o644); err == nil {
				cmd.Stdout, cmd.Stderr = w, w
			}
		}
		if err := cmd.Start(); err != nil {
			return "", err
		}
		h.t.Cleanup(func() { _ = cmd.Process.Kill(); _ = cmd.Wait() })
	}
	return "", nil
}

func dig(t *testing.T, name, typ string) string {
	t.Helper()
	out, err := exec.Command("dig", "+short", "+time=2", "+tries=1", "@127.0.0.1", name, typ).CombinedOutput()
	if err != nil {
		t.Fatalf("dig %s %s: %v: %s", name, typ, err, out)
	}
	return strings.TrimSpace(string(out))
}

func digStatus(t *testing.T, name string) string {
	t.Helper()
	out, _ := exec.Command("dig", "+time=2", "+tries=1", "@127.0.0.1", name, "SOA").CombinedOutput()
	for _, l := range strings.Split(string(out), "\n") {
		if i := strings.Index(l, "status: "); i >= 0 {
			return strings.TrimSuffix(strings.Fields(l[i+8:])[0], ",")
		}
	}
	return string(out)
}

func serialOf(t *testing.T, soa string) int64 {
	t.Helper()
	f := strings.Fields(soa)
	if len(f) != 7 {
		t.Fatalf("SOA answer %q", soa)
	}
	n, _ := strconv.ParseInt(f[2], 10, 64)
	return n
}

func TestRealHost(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("needs root")
	}
	m := NewManager(serviceHost{t: t})
	ctx := context.Background()

	res, err := m.Setup(ctx)
	if err != nil {
		t.Fatal(err)
	}
	if !strings.HasPrefix(res.Version, "4.") {
		t.Fatalf("setup = %+v", res)
	}
	t.Logf("PowerDNS %s, installed now: %v", res.Version, res.Installed)

	const zone = "serfex-test.example"
	t.Cleanup(func() { _, _ = m.DeleteZone(ctx, zone) })
	long := "v=DKIM1; k=rsa; p=" + strings.Repeat("A", 300)
	spec := ZoneSpec{
		Zone: zone,
		SOA:  SOA{Primary: "ns1.serfex-test.example", Hostmaster: "hostmaster@serfex-test.example", Refresh: 10800, Retry: 3600, Expire: 604800, Minimum: 3600, TTL: 3600},
		Records: []Record{
			{Name: "@", Type: "NS", TTL: 3600, Content: "ns1.serfex-test.example"},
			{Name: "@", Type: "NS", TTL: 3600, Content: "ns2.serfex-test.example"},
			{Name: "ns1", Type: "A", TTL: 3600, Content: "192.0.2.53"},
			{Name: "@", Type: "A", TTL: 300, Content: "192.0.2.10"},
			{Name: "@", Type: "AAAA", TTL: 300, Content: "2001:db8:0:0::10"},
			{Name: "www", Type: "CNAME", TTL: 300, Content: zone},
			{Name: "@", Type: "MX", TTL: 3600, Content: "10 mail.serfex-test.example"},
			{Name: "default._domainkey", Type: "TXT", TTL: 3600, Content: long},
			{Name: "@", Type: "CAA", TTL: 3600, Content: `0 issue "letsencrypt.org"`},
		},
	}
	if _, err := m.ApplyZone(ctx, spec); err != nil {
		t.Fatal(err)
	}

	for _, c := range []struct{ name, typ, want string }{
		{zone, "A", "192.0.2.10"},
		{zone, "AAAA", "2001:db8::10"},
		{"www." + zone, "CNAME", zone + "."},
		{zone, "MX", "10 mail." + zone + "."},
		{zone, "CAA", `0 issue "letsencrypt.org"`},
		{"ns1." + zone, "A", "192.0.2.53"},
	} {
		if got := dig(t, c.name, c.typ); got != c.want {
			t.Errorf("%s %s = %q, want %q", c.name, c.typ, got, c.want)
		}
	}
	if got := strings.ReplaceAll(strings.ReplaceAll(dig(t, "default._domainkey."+zone, "TXT"), `" "`, ""), `"`, ""); got != long {
		t.Errorf("long TXT = %q", got)
	}
	ns := dig(t, zone, "NS")
	if !strings.Contains(ns, "ns1."+zone+".") || !strings.Contains(ns, "ns2."+zone+".") {
		t.Errorf("NS = %q", ns)
	}
	first := serialOf(t, dig(t, zone, "SOA"))

	// Change a record: the answer and the serial follow.
	spec.Records[3].Content = "192.0.2.20"
	if _, err := m.ApplyZone(ctx, spec); err != nil {
		t.Fatal(err)
	}
	if got := dig(t, zone, "A"); got != "192.0.2.20" {
		t.Errorf("A after update = %q", got)
	}
	if s := serialOf(t, dig(t, zone, "SOA")); s <= first {
		t.Errorf("serial %d did not increase from %d", s, first)
	}

	// Setup again changes nothing.
	if res, err := m.Setup(ctx); err != nil || res.Changed {
		t.Fatalf("second setup = %+v, %v", res, err)
	}

	if _, err := m.DeleteZone(ctx, zone); err != nil {
		t.Fatal(err)
	}
	if st := digStatus(t, zone); st != "REFUSED" {
		t.Errorf("deleted zone answers with %s, want REFUSED", st)
	}
}

func digAll(t *testing.T, args ...string) string {
	t.Helper()
	out, _ := exec.Command("dig", append([]string{"+time=2", "+tries=1", "@127.0.0.1"}, args...)...).CombinedOutput()
	return string(out)
}

func TestRealHostDNSSECAndSecondaries(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("needs root")
	}
	m := NewManager(serviceHost{t: t})
	ctx := context.Background()
	if _, err := m.Setup(ctx); err != nil {
		t.Fatal(err)
	}
	const zone = "serfex-signed.example"
	// Left over from an interrupted run, the zone would still be signed.
	if _, err := m.DeleteZone(ctx, zone); err != nil {
		t.Fatal(err)
	}
	spec := ZoneSpec{
		Zone: zone,
		SOA:  SOA{Primary: "ns1.serfex-signed.example", Hostmaster: "hostmaster@serfex-signed.example", Refresh: 10800, Retry: 3600, Expire: 604800, Minimum: 3600, TTL: 3600},
		Records: []Record{
			{Name: "@", Type: "NS", TTL: 3600, Content: "ns1.serfex-signed.example"},
			{Name: "ns1", Type: "A", TTL: 3600, Content: "192.0.2.53"},
			{Name: "www", Type: "A", TTL: 300, Content: "192.0.2.10"},
		},
	}
	if _, err := m.ApplyZone(ctx, spec); err != nil {
		t.Fatal(err)
	}
	if out := digAll(t, zone, "AXFR"); !strings.Contains(out, "Transfer failed") {
		t.Errorf("AXFR allowed with no secondaries:\n%s", out)
	}
	unsigned := serialOf(t, dig(t, zone, "SOA"))

	// Sign it.
	spec.DNSSEC = &DNSSECSpec{Enabled: true}
	res, err := m.ApplyZone(ctx, spec)
	if err != nil {
		t.Fatal(err)
	}
	d := res.DNSSEC
	if d == nil || !d.Enabled || d.Key == "" || len(d.DS) != 2 || d.Algorithm != 13 {
		t.Fatalf("dnssec = %+v", d)
	}
	t.Logf("DS %s", d.DS[0])
	if s := serialOf(t, dig(t, zone, "SOA")); s <= unsigned {
		t.Errorf("signing left the serial at %d (was %d)", s, unsigned)
	}
	// dig splits the key in two.
	if got := dig(t, zone, "DNSKEY"); strings.ReplaceAll(got, " ", "") != strings.ReplaceAll(d.DNSKEY, " ", "") {
		t.Errorf("DNSKEY = %q, want %q", got, d.DNSKEY)
	}
	if out := digAll(t, "+dnssec", "www."+zone, "A"); !strings.Contains(out, "RRSIG\tA 13 ") {
		t.Errorf("www not signed:\n%s", out)
	}
	if out := digAll(t, "+dnssec", "nope."+zone, "A"); !strings.Contains(out, "NSEC3") {
		t.Errorf("no NSEC3 proof for a missing name:\n%s", out)
	}
	// A record change is signed too (API-RECTIFY).
	spec.Records = append(spec.Records, Record{Name: "new", Type: "A", TTL: 300, Content: "192.0.2.11"})
	if _, err := m.ApplyZone(ctx, spec); err != nil {
		t.Fatal(err)
	}
	if out := digAll(t, "+dnssec", "new."+zone, "A"); !strings.Contains(out, "RRSIG\tA 13 ") {
		t.Errorf("new record not signed:\n%s", out)
	}

	// Secondaries: transfers with signatures, the catalog lists the zone.
	sec, err := m.SetSecondaries(ctx, SecondariesSpec{Addresses: []string{"127.0.0.1"}})
	if err != nil {
		t.Fatal(err)
	}
	if sec.Catalog == "" || sec.Zones < 1 {
		t.Fatalf("secondaries = %+v", sec)
	}
	axfr := digAll(t, zone, "AXFR")
	for _, want := range []string{"RRSIG", "NSEC3", "DNSKEY", "192.0.2.11"} {
		if !strings.Contains(axfr, want) {
			t.Errorf("AXFR lacks %s:\n%s", want, axfr)
		}
	}
	if out := digAll(t, sec.Catalog, "AXFR"); !strings.Contains(out, "PTR\t"+zone+".") {
		t.Errorf("catalog does not list the zone:\n%s", out)
	}

	// Off and on again: the same key, so the DS record stays valid.
	spec.DNSSEC = &DNSSECSpec{Enabled: false, Key: d.Key}
	if _, err := m.ApplyZone(ctx, spec); err != nil {
		t.Fatal(err)
	}
	if got := dig(t, zone, "DNSKEY"); got != "" {
		t.Errorf("DNSKEY after turning off = %q", got)
	}
	if out := digAll(t, "+dnssec", "www."+zone, "A"); strings.Contains(out, "RRSIG") {
		t.Errorf("still signed after turning off:\n%s", out)
	}
	spec.DNSSEC = &DNSSECSpec{Enabled: true, Key: d.Key}
	if res, err = m.ApplyZone(ctx, spec); err != nil || res.DNSSEC.DS[0] != d.DS[0] {
		t.Fatalf("on again: %+v %v", res.DNSSEC, err)
	}

	// Deleted and applied again with the panel's key: the same DS.
	if _, err := m.DeleteZone(ctx, zone); err != nil {
		t.Fatal(err)
	}
	if res, err = m.ApplyZone(ctx, spec); err != nil || res.DNSSEC.DS[0] != d.DS[0] {
		t.Fatalf("restored: %+v %v", res.DNSSEC, err)
	}
	if got := dig(t, zone, "DNSKEY"); strings.ReplaceAll(got, " ", "") != strings.ReplaceAll(d.DNSKEY, " ", "") {
		t.Errorf("restored DNSKEY = %q", got)
	}

	if _, err := m.SetSecondaries(ctx, SecondariesSpec{}); err != nil {
		t.Fatal(err)
	}
	if out := digAll(t, zone, "AXFR"); !strings.Contains(out, "Transfer failed") {
		t.Errorf("AXFR still allowed:\n%s", out)
	}
	if _, err := m.DeleteZone(ctx, zone); err != nil {
		t.Fatal(err)
	}
}
