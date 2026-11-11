//go:build realhost

// This test upgrades a real PowerDNS 4.8 with zones (one signed, a
// catalog for secondaries) to a 5.x build and back. It needs root,
// Ubuntu's pdns-server and pdns-backend-lmdb installed, and a 5.x build
// laid out as the packages lay it out:
//
//	SERFEX_PDNS5=/opt/pdns51 sudo -E go test -tags realhost -run TestRealHostUpgrade ./internal/dns
//
// with $SERFEX_PDNS5/pdns_server.sh, pdnsutil.sh and lib/pdns/liblmdbbackend.so.
// PowerDNS's archive is not reached: apt-get update, apt-cache and the
// key check answer as if it were, and the install copies the build in.
// Downloading and reinstalling Ubuntu's 4.8 packages is real.
package dns

import (
	"context"
	"fmt"
	"os"
	"os/exec"
	"strings"
	"testing"
	"time"

	"github.com/calltech-app/serfex/agent/internal/host"
)

type upgradeHost struct {
	serviceHost
	build   string
	swapped *bool
}

func (h upgradeHost) Run(ctx context.Context, c host.Command) (string, error) {
	s := c.String()
	switch {
	case s == "apt-get update -q":
		return "", nil
	case s == "apt-cache policy pdns-server":
		v, from := "4.8.3-4build3", "http://archive.ubuntu.com/ubuntu noble/universe"
		if _, err := os.Stat(archiveSources); err == nil {
			v, from = "5.1.5-1pdns.noble", "https://repo.powerdns.com/ubuntu noble-auth-51/main"
		}
		return fmt.Sprintf("pdns-server:\n  Installed: 4.8.3-4build3\n  Candidate: %s\n  Version table:\n     %s 600\n        600 %s amd64 Packages\n", v, v, from), nil
	case strings.HasPrefix(s, "gpg --batch --with-colons --show-keys"):
		return "pub:-:4096:1:1C5EE990D2E71575:1433861228:::-:::scSC::::::23::0:\nfpr:::::::::" + archive.Fingerprint + ":\n", nil
	case strings.HasPrefix(s, "dpkg-query -W -f=${db:Status-Status} ${Version}") && *h.swapped:
		return "installed 5.1.5-1pdns.noble", nil
	case strings.HasPrefix(s, "apt-get install -y -q --no-install-recommends -o Dpkg::Options::=--force-confdef"):
		for from, to := range map[string]string{"/pdns_server.sh": pdnsBinary, "/pdnsutil.sh": "/usr/bin/pdnsutil", "/lib/pdns/liblmdbbackend.so": "/usr/lib/x86_64-linux-gnu/pdns/liblmdbbackend.so"} {
			if out, err := exec.Command("cp", h.build+from, to).CombinedOutput(); err != nil {
				return string(out), err
			}
		}
		*h.swapped = true
		return "", nil
	case strings.HasPrefix(s, "dpkg --force-confdef --force-confold -i"):
		out, err := h.serviceHost.Run(ctx, c)
		if err == nil {
			*h.swapped = false
		}
		return out, err
	case s == "systemctl stop "+unit && !hasSystemd():
		_ = exec.Command("pkill", "-f", "pdns_server --config-name="+instance).Run()
		time.Sleep(500 * time.Millisecond)
		return "", nil
	}
	return h.serviceHost.Run(ctx, c)
}

func upgradeZones() []ZoneSpec {
	mk := func(zone string, sign bool) ZoneSpec {
		s := ZoneSpec{Zone: zone,
			SOA: SOA{Primary: "ns1." + zone, Hostmaster: "hostmaster@" + zone, Refresh: 10800, Retry: 3600, Expire: 604800, Minimum: 3600, TTL: 3600},
			Records: []Record{
				{Name: "@", Type: "NS", TTL: 3600, Content: "ns1." + zone},
				{Name: "@", Type: "NS", TTL: 3600, Content: "ns2." + zone},
				{Name: "ns1", Type: "A", TTL: 3600, Content: "192.0.2.53"},
				{Name: "@", Type: "A", TTL: 300, Content: "192.0.2.10"},
				{Name: "@", Type: "AAAA", TTL: 300, Content: "2001:db8:0:0::10"},
				{Name: "www", Type: "CNAME", TTL: 300, Content: zone},
				{Name: "@", Type: "MX", TTL: 3600, Content: "10 mail." + zone},
				{Name: "@", Type: "TXT", TTL: 3600, Content: "v=spf1 a mx ~all"},
				{Name: "default._domainkey", Type: "TXT", TTL: 3600, Content: "v=DKIM1; k=rsa; p=" + strings.Repeat("A", 300)},
				{Name: "_dmarc", Type: "TXT", TTL: 3600, Content: "v=DMARC1; p=none; rua=mailto:dmarc@" + zone},
				{Name: "@", Type: "CAA", TTL: 3600, Content: `0 issue "letsencrypt.org"`},
				{Name: "_sip._tcp", Type: "SRV", TTL: 3600, Content: "10 5 5060 sip." + zone},
				{Name: "*.app", Type: "A", TTL: 300, Content: "192.0.2.12"},
			}}
		if sign {
			s.DNSSEC = &DNSSECSpec{Enabled: true}
		}
		return s
	}
	return []ZoneSpec{mk("upgrade-plain.example", false), mk("upgrade-signed.example", true)}
}

func TestRealHostUpgrade(t *testing.T) {
	build := os.Getenv("SERFEX_PDNS5")
	if os.Geteuid() != 0 || build == "" {
		t.Skip("needs root and SERFEX_PDNS5")
	}
	swapped := false
	m := NewManager(upgradeHost{serviceHost: serviceHost{t: t}, build: build, swapped: &swapped})
	ctx := context.Background()
	for _, f := range []string{archiveSources, archivePin, archiveKeyring, keptFile} {
		_ = os.Remove(f)
	}
	m.Fetch = func(context.Context, string) ([]byte, error) {
		return []byte("-----BEGIN PGP PUBLIC KEY BLOCK-----\n\ntest\n-----END PGP PUBLIC KEY BLOCK-----\n"), nil
	}
	// Setup would install 5.x straight away: start from Ubuntu's 4.8.
	if _, err := m.Setup(ctx); err != nil {
		t.Fatal(err)
	}
	if v, _ := m.newBackend(must(m.readConfig())).Version(ctx); !strings.HasPrefix(v, "4.8") {
		t.Skipf("PowerDNS %s is running, not Ubuntu's 4.8", v)
	}
	_ = os.Remove(archiveSources)
	_ = os.Remove(archivePin)
	_ = os.Remove(archiveKeyring)
	specs := upgradeZones()
	t.Cleanup(func() {
		for _, s := range specs {
			_, _ = m.DeleteZone(ctx, s.Zone)
		}
		_, _ = m.SetSecondaries(ctx, SecondariesSpec{})
	})
	var key string
	for i, s := range specs {
		res, err := m.ApplyZone(ctx, s)
		if err != nil {
			t.Fatal(err)
		}
		if res.DNSSEC != nil {
			key = res.DNSSEC.Key
			specs[i].DNSSEC.Key = key
		}
	}
	if _, err := m.SetSecondaries(ctx, SecondariesSpec{Addresses: []string{"127.0.0.2"}}); err != nil {
		t.Fatal(err)
	}
	ds := dig(t, "upgrade-signed.example", "DNSKEY")

	// A failed check puts 4.8 and its database back.
	var log strings.Builder
	_, err := m.Upgrade(ctx, UpgradeSpec{Log: &log, Fail: func(step string) error {
		if step == "verify" {
			return fmt.Errorf("test failure")
		}
		return nil
	}})
	if err == nil || swapped {
		t.Fatalf("failed upgrade: err=%v swapped=%v\n%s", err, swapped, log.String())
	}
	t.Logf("rolled back:\n%s", log.String())
	if v, _ := m.newBackend(must(m.readConfig())).Version(ctx); !strings.HasPrefix(v, "4.8") {
		t.Fatalf("after the rollback PowerDNS %s runs", v)
	}
	if got := dig(t, "upgrade-plain.example", "A"); got != "192.0.2.10" {
		t.Fatalf("after the rollback A = %q", got)
	}

	// The real upgrade.
	log.Reset()
	k, err := m.Upgrade(ctx, UpgradeSpec{Log: &log})
	if err != nil {
		t.Fatalf("%v\n%s", err, log.String())
	}
	t.Logf("upgraded: %+v\n%s", k, log.String())
	if v, _ := m.newBackend(must(m.readConfig())).Version(ctx); strings.HasPrefix(v, "4.") {
		t.Fatalf("after the upgrade PowerDNS %s runs", v)
	}
	if got := dig(t, "upgrade-signed.example", "DNSKEY"); got != ds {
		t.Errorf("DNSKEY changed: %q, was %q", got, ds)
	}
	if out := digAll(t, "+dnssec", "www.upgrade-signed.example", "CNAME"); !strings.Contains(out, "RRSIG") {
		t.Errorf("not signed after the upgrade:\n%s", out)
	}
	for _, s := range specs {
		res, err := m.ApplyZone(ctx, s)
		if err != nil || res.Changed {
			t.Errorf("applying %s again after the upgrade: changed=%v err=%v", s.Zone, res.Changed, err)
		}
	}
	// A change on 5.x, then back to 4.8: the panel writes its zones again.
	specs[0].Records[3].Content = "192.0.2.99"
	if _, err := m.ApplyZone(ctx, specs[0]); err != nil {
		t.Fatal(err)
	}
	log.Reset()
	if err := m.Revert(ctx, UpgradeSpec{Log: &log}); err != nil {
		t.Fatalf("%v\n%s", err, log.String())
	}
	t.Logf("reverted:\n%s", log.String())
	if v, _ := m.newBackend(must(m.readConfig())).Version(ctx); !strings.HasPrefix(v, "4.8") {
		t.Fatalf("after Revert PowerDNS %s runs", v)
	}
	if got := dig(t, "upgrade-plain.example", "A"); got != "192.0.2.10" {
		t.Fatalf("after Revert A = %q (the database before the upgrade)", got)
	}
	if res, err := m.ApplyZone(ctx, specs[0]); err != nil || !res.Changed {
		t.Fatalf("writing the zone again: %+v %v", res, err)
	}
	if got := dig(t, "upgrade-plain.example", "A"); got != "192.0.2.99" {
		t.Errorf("after writing again A = %q", got)
	}
	if got := dig(t, "upgrade-signed.example", "DNSKEY"); got != ds {
		t.Errorf("DNSKEY after Revert: %q, was %q", got, ds)
	}
}

func must[T any](v T, err error) T {
	if err != nil {
		panic(err)
	}
	return v
}
