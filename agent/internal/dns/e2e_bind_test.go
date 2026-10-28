//go:build e2ebind && realhost

// This test runs Serfex's PowerDNS as the primary and a real BIND 9.18 as
// its secondary, configured the way the panel's "How do I set up the
// secondary server?" tells: signed zones transfer, changes arrive through
// NOTIFY, new and deleted zones through the catalog zone. It needs root,
// pdns-server, bind9 and 127.0.0.2 on the loopback interface:
//
//	sudo ip addr add 127.0.0.2/32 dev lo
//	sudo go test -tags realhost,e2ebind -run TestE2EBind -timeout 5m ./internal/dns
package dns

import (
	"context"
	"os"
	"os/exec"
	"strings"
	"testing"
	"time"

	"github.com/calltech-app/serfex/agent/internal/host"
)

func digAt(server, name, typ string) string {
	out, _ := exec.Command("dig", "+short", "+time=1", "+tries=1", "@"+server, name, typ).CombinedOutput()
	return strings.TrimSpace(string(out))
}

func waitFor(t *testing.T, what string, f func() bool) {
	t.Helper()
	// PowerDNS looks at the catalog's members once a minute.
	for i := 0; i < 200; i++ {
		if f() {
			return
		}
		time.Sleep(500 * time.Millisecond)
	}
	t.Fatalf("timed out waiting for %s", what)
}

func TestE2EBindSecondary(t *testing.T) {
	m := NewManager(serviceHost{t: t})
	ctx := context.Background()
	if _, err := m.Setup(ctx); err != nil {
		t.Fatal(err)
	}
	// PowerDNS on 127.0.0.1 only, BIND on 127.0.0.2.
	conf, _ := os.ReadFile(configPath)
	conf = []byte(strings.Replace(string(conf), "local-address=0.0.0.0", "local-address=127.0.0.1\nquery-local-address=127.0.0.1", 1))
	conf = []byte(strings.Replace(string(conf), "loglevel=4", "loglevel=6", 1))
	_ = os.WriteFile(configPath, conf, 0o640)
	_, _ = serviceHost{t: t}.Run(ctx, host.Command{Name: "systemctl", Args: []string{"restart", unit}})
	b, _ := m.backend()
	if _, err := m.waitForAPI(ctx, b); err != nil {
		t.Fatal(err)
	}
	for _, z := range []string{"e2e-one.example", "e2e-two.example"} {
		_, _ = m.DeleteZone(ctx, z)
	}
	spec := func(zone string, ip string) ZoneSpec {
		return ZoneSpec{Zone: zone,
			SOA: SOA{Primary: "ns1." + zone, Hostmaster: "hostmaster@" + zone, Refresh: 10800, Retry: 3600, Expire: 604800, Minimum: 3600, TTL: 3600},
			Records: []Record{
				{Name: "@", Type: "NS", TTL: 3600, Content: "ns1." + zone},
				{Name: "ns1", Type: "A", TTL: 3600, Content: "192.0.2.53"},
				{Name: "www", Type: "A", TTL: 300, Content: ip},
			},
			DNSSEC: &DNSSECSpec{Enabled: true},
		}
	}
	if _, err := m.ApplyZone(ctx, spec("e2e-one.example", "192.0.2.1")); err != nil {
		t.Fatal(err)
	}
	sec, err := m.SetSecondaries(ctx, SecondariesSpec{Addresses: []string{"127.0.0.2"}})
	if err != nil {
		t.Fatal(err)
	}
	t.Logf("catalog %s", sec.Catalog)

	// BIND as the README tells it.
	named := `options {
	directory "/tmp/bindx";
	listen-on port 53 { 127.0.0.2; };
	listen-on-v6 { none; };
	pid-file "/tmp/bindx/named.pid";
	recursion no;
	transfer-source 127.0.0.2;
	query-source address 127.0.0.2;
	catalog-zones {
		zone "` + sec.Catalog + `" default-primaries { 127.0.0.1; };
	};
};
zone "` + sec.Catalog + `" {
	type secondary;
	file "/tmp/bindx/` + sec.Catalog + `.db";
	primaries { 127.0.0.1; };
};
`
	_ = os.WriteFile("/tmp/bindx/named.conf", []byte(named), 0o644)
	cmd := exec.Command("named", "-g", "-u", "root", "-c", "/tmp/bindx/named.conf")
	logf, _ := os.Create("/tmp/bindx/named.log")
	cmd.Stdout, cmd.Stderr = logf, logf
	if err := cmd.Start(); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = cmd.Process.Kill(); _ = cmd.Wait() })

	waitFor(t, "zone one on BIND", func() bool { return digAt("127.0.0.2", "www.e2e-one.example", "A") == "192.0.2.1" })
	if out, _ := exec.Command("dig", "+dnssec", "@127.0.0.2", "www.e2e-one.example", "A").CombinedOutput(); !strings.Contains(string(out), "RRSIG") {
		t.Errorf("BIND serves the zone unsigned:\n%s", out)
	}
	// A change reaches BIND through NOTIFY, long before the SOA refresh.
	start := time.Now()
	if _, err := m.ApplyZone(ctx, spec("e2e-one.example", "192.0.2.2")); err != nil {
		t.Fatal(err)
	}
	waitFor(t, "change on BIND", func() bool { return digAt("127.0.0.2", "www.e2e-one.example", "A") == "192.0.2.2" })
	t.Logf("change on BIND after %s", time.Since(start).Round(time.Millisecond))
	// A new zone appears through the catalog.
	start = time.Now()
	if _, err := m.ApplyZone(ctx, spec("e2e-two.example", "192.0.2.3")); err != nil {
		t.Fatal(err)
	}
	waitFor(t, "new zone on BIND", func() bool {
		t.Logf("catalog SOA on PowerDNS: %s", digAt("127.0.0.1", sec.Catalog, "SOA"))
		return digAt("127.0.0.2", "www.e2e-two.example", "A") == "192.0.2.3"
	})
	t.Logf("new zone on BIND after %s", time.Since(start).Round(time.Millisecond))
	// A deleted zone goes away.
	if _, err := m.DeleteZone(ctx, "e2e-two.example"); err != nil {
		t.Fatal(err)
	}
	start = time.Now()
	waitFor(t, "zone gone from BIND", func() bool {
		out, _ := exec.Command("dig", "+time=1", "+tries=1", "@127.0.0.2", "www.e2e-two.example", "A").CombinedOutput()
		return strings.Contains(string(out), "REFUSED")
	})
	t.Logf("deleted zone gone from BIND after %s", time.Since(start).Round(time.Millisecond))
	_, _ = m.DeleteZone(ctx, "e2e-one.example")
	_, _ = m.SetSecondaries(ctx, SecondariesSpec{})
}
