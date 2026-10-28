package dns

import (
	"context"
	"errors"
	"fmt"
	"os"
	"strings"
	"testing"

	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/tuning"
)

func signed(enabled bool, key string) *DNSSECSpec { return &DNSSECSpec{Enabled: enabled, Key: key} }

func TestDNSSECSignsAZoneAndKeepsItsKey(t *testing.T) {
	m, mem, _ := newTest(t)
	setUp(t, m)
	ctx := context.Background()
	const zone = "kahveatolyesi.com.tr."

	first, err := m.ApplyZone(ctx, zoneSpec())
	if err != nil {
		t.Fatal(err)
	}
	if first.DNSSEC != nil {
		t.Fatalf("a spec without dnssec reported %+v", first.DNSSEC)
	}

	// Turning signing on alone raises the serial, for the secondaries.
	spec := zoneSpec()
	spec.DNSSEC = signed(true, "")
	res, err := m.ApplyZone(ctx, spec)
	if err != nil {
		t.Fatal(err)
	}
	d := res.DNSSEC
	if !res.Changed || res.Serial <= first.Serial || d == nil || !d.Enabled || d.Key == "" || d.Algorithm != 13 || d.KeyTag == 0 {
		t.Fatalf("result = %+v, dnssec %+v", res, d)
	}
	if len(d.DS) != 2 || strings.Fields(d.DS[0])[2] != "2" || strings.Fields(d.DS[1])[2] != "4" || !strings.HasPrefix(d.DNSKEY, "257 3 13 ") {
		t.Errorf("DS %v DNSKEY %q: want SHA-256 then SHA-384, no SHA-1", d.DS, d.DNSKEY)
	}
	z, _ := mem.Zone(ctx, zone)
	if !z.DNSSEC || z.NSEC3Param != "1 0 0 -" || z.SOAEdit != "INCEPTION-INCREMENT" {
		t.Errorf("zone = %+v", z)
	}
	key, ds := d.Key, d.DS[0]

	// The panel sends the key back from now on; nothing changes.
	spec.DNSSEC = signed(true, key)
	res, err = m.ApplyZone(ctx, spec)
	if err != nil || res.Changed || res.DNSSEC.Key != "" || res.DNSSEC.DS[0] != ds {
		t.Fatalf("again: %+v %+v %v", res, res.DNSSEC, err)
	}

	// Off: the key stays in PowerDNS, inactive.
	spec.DNSSEC = signed(false, key)
	res, err = m.ApplyZone(ctx, spec)
	if err != nil || !res.Changed || res.DNSSEC.Enabled || len(res.DNSSEC.DS) != 0 {
		t.Fatalf("off: %+v %+v %v", res, res.DNSSEC, err)
	}
	z, _ = mem.Zone(ctx, zone)
	keys, _ := mem.Keys(ctx, zone)
	if z.DNSSEC || z.NSEC3Param != "" || z.SOAEdit != "" || len(keys) != 1 || keys[0].Active || keys[0].Published {
		t.Errorf("off: zone %+v keys %+v", z, keys)
	}

	// On again: the same key, so the registrar's DS record still fits.
	spec.DNSSEC = signed(true, key)
	if res, err = m.ApplyZone(ctx, spec); err != nil || res.DNSSEC.DS[0] != ds {
		t.Fatalf("on again: %+v %v", res.DNSSEC, err)
	}

	// Deleted and restored (or moved to another server): the panel's key
	// is imported.
	if _, err := m.DeleteZone(ctx, "kahveatolyesi.com.tr"); err != nil {
		t.Fatal(err)
	}
	if res, err = m.ApplyZone(ctx, spec); err != nil || !res.Created || res.DNSSEC.DS[0] != ds || res.DNSSEC.Key != "" {
		t.Fatalf("restored: %+v %+v %v", res, res.DNSSEC, err)
	}

	// A key PowerDNS has but the panel does not know is set aside.
	other, _ := mem.AddKey(ctx, zone, "")
	if res, err = m.ApplyZone(ctx, spec); err != nil || res.DNSSEC.DS[0] != ds {
		t.Fatalf("with a stray key: %+v %v", res.DNSSEC, err)
	}
	keys, _ = mem.Keys(ctx, zone)
	for _, k := range keys {
		if k.ID == other.ID && k.Active {
			t.Errorf("stray key still signs: %+v", k)
		}
	}
}

func TestDNSSECRejectsAnUnusableKey(t *testing.T) {
	m, _, _ := newTest(t)
	setUp(t, m)
	spec := zoneSpec()
	spec.DNSSEC = signed(true, "not a key")
	var v *ValidationError
	if _, err := m.ApplyZone(context.Background(), spec); !errors.As(err, &v) {
		t.Fatalf("err = %v, want a validation error", err)
	}
}

func TestSecondariesTransferNotifyAndCatalog(t *testing.T) {
	m, mem, sim := newTest(t)
	setUp(t, m)
	ctx := context.Background()
	if err := os.WriteFile(sim.Path("/etc/hostname"), []byte("server.example.net\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	if _, err := m.ApplyZone(ctx, zoneSpec()); err != nil {
		t.Fatal(err)
	}
	const zone, catalog = "kahveatolyesi.com.tr.", "catalog.server.example.net.invalid."

	before, err := m.GetSecondaries(ctx)
	if err != nil || len(before.Addresses) != 0 || before.Catalog != "" {
		t.Fatalf("before: %+v %v", before, err)
	}

	got, err := m.SetSecondaries(ctx, SecondariesSpec{Addresses: []string{" 192.0.2.10", "198.51.100.0/24", "2001:db8::53/128", "192.0.2.10"}})
	if err != nil {
		t.Fatal(err)
	}
	if strings.Join(got.Addresses, ",") != "192.0.2.10,198.51.100.0/24,2001:db8::53" || strings.Join(got.Notify, ",") != "192.0.2.10,2001:db8::53" ||
		got.Catalog != strings.TrimSuffix(catalog, ".") || got.Zones != 1 {
		t.Fatalf("secondaries = %+v", got)
	}
	conf, _ := os.ReadFile(sim.Path(configPath))
	for _, want := range []string{"\ndisable-axfr=no\n", "\nallow-axfr-ips=192.0.2.10,198.51.100.0/24,2001:db8::53\n", "\nprimary=yes\n", "\nalso-notify=192.0.2.10,2001:db8::53\n"} {
		if !strings.Contains(string(conf), want) {
			t.Errorf("config lacks %q:\n%s", want, conf)
		}
	}
	if !sim.Ran("systemctl restart pdns@serfex") {
		t.Errorf("PowerDNS not restarted: %v", sim.Commands())
	}
	z, _ := mem.Zone(ctx, zone)
	c, _ := mem.Zone(ctx, catalog)
	if z.Kind != "Master" || z.Catalog != catalog || c == nil || c.Kind != "Producer" || mem.Notified[zone] != 1 || mem.Notified[catalog] != 1 {
		t.Fatalf("zone %+v catalog %+v notified %v", z, c, mem.Notified)
	}
	if p := tuning.Load(sim).PowerDNS; p.Catalog != catalog {
		t.Errorf("catalog name not kept: %+v", p)
	}

	// New zones join; every change is notified.
	spec := zoneSpec()
	spec.Zone = "ornek.com.tr"
	if _, err := m.ApplyZone(ctx, spec); err != nil {
		t.Fatal(err)
	}
	if z, _ := mem.Zone(ctx, "ornek.com.tr."); z.Kind != "Master" || z.Catalog != catalog || mem.Notified["ornek.com.tr."] != 1 {
		t.Errorf("new zone %+v notified %v", z, mem.Notified)
	}
	if _, err := m.ApplyZone(ctx, zoneSpec(Record{Name: "www", Type: "A", TTL: 300, Content: "192.0.2.80"})); err != nil {
		t.Fatal(err)
	}
	if mem.Notified[zone] != 2 {
		t.Errorf("change not notified: %v", mem.Notified)
	}
	if s, _ := m.GetSecondaries(ctx); s.Zones != 2 {
		t.Errorf("zones = %d", s.Zones)
	}

	// The same list again does not restart PowerDNS.
	n := len(sim.Commands())
	if _, err := m.SetSecondaries(ctx, SecondariesSpec{Addresses: got.Addresses}); err != nil {
		t.Fatal(err)
	}
	for _, c := range sim.Commands()[n:] {
		if c.Name == "systemctl" {
			t.Errorf("ran %s for an unchanged list", c)
		}
	}

	// None: zones go back to native, the catalog goes away, its name stays.
	if got, err = m.SetSecondaries(ctx, SecondariesSpec{}); err != nil || len(got.Addresses) != 0 || got.Catalog != "" {
		t.Fatalf("off: %+v %v", got, err)
	}
	z, _ = mem.Zone(ctx, zone)
	c, _ = mem.Zone(ctx, catalog)
	if z.Kind != "Native" || z.Catalog != "" || c != nil {
		t.Errorf("off: zone %+v catalog %+v", z, c)
	}
	conf, _ = os.ReadFile(sim.Path(configPath))
	if !strings.Contains(string(conf), "\ndisable-axfr=yes\nallow-axfr-ips=\nprimary=no\nalso-notify=\n") {
		t.Errorf("config:\n%s", conf)
	}
	if p := tuning.Load(sim).PowerDNS; p.Catalog != catalog {
		t.Errorf("catalog name lost: %+v", p)
	}
}

func TestSecondariesRefuseBadAddressesAndRollBack(t *testing.T) {
	m, mem, sim := newTest(t)
	setUp(t, m)
	ctx := context.Background()
	var v *ValidationError
	for _, bad := range [][]string{{"ns2.example.net"}, {"192.0.2.0/33"}, {"fe80::1%eth0"}} {
		if _, err := m.SetSecondaries(ctx, SecondariesSpec{Addresses: bad}); !errors.As(err, &v) {
			t.Errorf("%v: %v", bad, err)
		}
	}
	many := make([]string, 0, 33)
	for i := range 33 {
		many = append(many, fmt.Sprintf("192.0.2.%d", i))
	}
	if _, err := m.SetSecondaries(ctx, SecondariesSpec{Addresses: many}); !errors.As(err, &v) {
		t.Errorf("33 addresses: %v", err)
	}

	before, _ := os.ReadFile(sim.Path(configPath))
	sim.Fail = func(c host.Command) error {
		if c.String() == "systemctl restart pdns@serfex" {
			return errors.New("job failed")
		}
		return nil
	}
	if _, err := m.SetSecondaries(ctx, SecondariesSpec{Addresses: []string{"192.0.2.10"}}); err == nil {
		t.Fatal("failed restart reported as success")
	}
	sim.Fail = nil
	if after, _ := os.ReadFile(sim.Path(configPath)); string(after) != string(before) {
		t.Errorf("config not restored:\n%s", after)
	}
	if p := tuning.Load(sim).PowerDNS; len(p.AXFRAllow) != 0 {
		t.Errorf("settings not restored: %+v", p)
	}
	if len(mem.ZoneNames()) != 0 {
		t.Errorf("zones: %v", mem.ZoneNames())
	}
}
