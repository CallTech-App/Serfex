package dns

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/calltech-app/serfex/agent/internal/host"
)

// oldServer is a server set up when PowerDNS's archive could not be read:
// Ubuntu's 4.8.3 with two zones, one signed, and an LMDB database.
func oldServer(t *testing.T) (*Manager, *Memory, *host.Simulator) {
	t.Helper()
	m, mem, sim := newTest(t)
	sim.Fail = func(c host.Command) error {
		if c.String() == "apt-get update -q" {
			return errors.New("repo.powerdns.com: connection timed out")
		}
		return nil
	}
	setUp(t, m)
	sim.Fail = nil
	if m.archiveListed() {
		t.Fatal("PowerDNS's archive is listed after a setup that could not read it")
	}
	if v := m.PackageVersion(context.Background()); v != simUbuntuVersion {
		t.Fatalf("version = %q", v)
	}
	ctx := context.Background()
	if _, err := m.ApplyZone(ctx, zoneSpec(Record{Name: "@", Type: "A", TTL: 300, Content: "192.0.2.10"})); err != nil {
		t.Fatal(err)
	}
	signed := zoneSpec(Record{Name: "www", Type: "A", TTL: 300, Content: "192.0.2.11"})
	signed.Zone = "imzali.example"
	signed.DNSSEC = &DNSSECSpec{Enabled: true}
	if _, err := m.ApplyZone(ctx, signed); err != nil {
		t.Fatal(err)
	}
	for f, body := range map[string]string{lmdbName: "schema 5", lmdbName + "-1": "shard", lmdbName + "-lock": ""} {
		if err := os.WriteFile(sim.Path(lmdbDir+"/"+f), []byte(body), 0o600); err != nil {
			t.Fatal(err)
		}
	}
	return m, mem, sim
}

func TestSetupInstallsFromPowerDNSArchive(t *testing.T) {
	m, _, sim := newTest(t)
	setUp(t, m)
	src, err := os.ReadFile(sim.Path(archiveSources))
	if err != nil {
		t.Fatal(err)
	}
	for _, want := range []string{"URIs: https://repo.powerdns.com/ubuntu", "Suites: noble-auth-51", "Signed-By: " + archiveKeyring} {
		if !strings.Contains(string(src), want) {
			t.Errorf("sources lack %q:\n%s", want, src)
		}
	}
	if pin, _ := os.ReadFile(sim.Path(archivePin)); !strings.Contains(string(pin), "Package: pdns-*\nPin: origin repo.powerdns.com\nPin-Priority: 600") {
		t.Errorf("pin = %q", pin)
	}
	if v := m.PackageVersion(context.Background()); v != simSeriesVersion {
		t.Errorf("version = %q, want %s", v, simSeriesVersion)
	}
	if o := UpgradeOffer(m.PackageVersion(context.Background())); o != nil {
		t.Errorf("offer on %s = %+v", Series, o)
	}
}

func TestSetupFallsBackToUbuntuWithoutTheArchive(t *testing.T) {
	m, _, sim := oldServer(t)
	for _, f := range []string{archiveSources, archivePin, archiveKeyring} {
		if _, err := os.Stat(sim.Path(f)); err == nil {
			t.Errorf("%s left behind", f)
		}
	}
	if !sim.Ran("apt-get install -y -q --no-install-recommends pdns-server pdns-backend-lmdb") {
		t.Error("Ubuntu's packages were not installed")
	}
	if o := UpgradeOffer(m.PackageVersion(context.Background())); o == nil || o.From != "4.8" || o.To != Series {
		t.Errorf("offer = %+v", o)
	}
}

func TestUpgradeOffer(t *testing.T) {
	for v, want := range map[string]string{"4.8.3-4build3": "4.8", "4.9.18-1pdns.noble": "4.9", "5.0.8-1pdns.noble": "5.0", "5.1.5-1pdns.noble": "", "5.2.0-1pdns.noble": "", "": ""} {
		o := UpgradeOffer(v)
		if (o == nil) != (want == "") || o != nil && (o.From != want || o.To != Series) {
			t.Errorf("UpgradeOffer(%q) = %+v, want from %q", v, o, want)
		}
	}
}

func TestUpgradeKeepsTheOldVersionAndVerifies(t *testing.T) {
	m, _, sim := oldServer(t)
	ctx := context.Background()
	var log strings.Builder
	k, err := m.Upgrade(ctx, UpgradeSpec{Log: &log})
	if err != nil {
		t.Fatalf("%v\n%s", err, log.String())
	}
	if k.From != simUbuntuVersion || k.To != simSeriesVersion || k.Zones != 2 || k.Until.Sub(k.Upgraded) != KeepFor {
		t.Fatalf("kept = %+v", k)
	}
	if got := m.KeptVersion(); got == nil || got.Dir != k.Dir {
		t.Fatalf("KeptVersion = %+v", got)
	}
	if m.PackageVersion(ctx) != simSeriesVersion || !m.archiveListed() {
		t.Error("not on the series from PowerDNS's archive")
	}
	for _, f := range []string{"/lmdb/" + lmdbName, "/lmdb/" + lmdbName + "-1", "/zones.json", "/zones-after.json", "/packages/pdns-server_" + simUbuntuVersion + "_amd64.deb"} {
		if _, err := os.Stat(sim.Path(k.Dir + f)); err != nil {
			t.Errorf("not kept: %s", f)
		}
	}
	// The old packages were kept before PowerDNS stopped, and it stopped
	// (and its database was copied) before the new packages went in.
	var order []string
	for _, c := range sim.Commands() {
		switch s := c.String(); {
		case strings.Contains(s, "apt-get download -q pdns-server="+simUbuntuVersion+" pdns-backend-lmdb="+simUbuntuVersion):
			order = append(order, "download")
		case s == "systemctl stop pdns@serfex":
			order = append(order, "stop")
		case strings.HasPrefix(s, "apt-get install -y -q --no-install-recommends -o Dpkg::Options::=--force-confdef"):
			order = append(order, "install")
		case s == "systemctl restart pdns@serfex" && len(order) > 0:
			order = append(order, "start")
		}
	}
	if strings.Join(order, " ") != "download stop install start" {
		t.Errorf("order = %v", order)
	}
	for _, want := range []string{"▸ PowerDNS paket deposu ekleniyor (repo.powerdns.com, noble-auth-51)", "▸ Bölgeler, kayıtlar ve DNSSEC anahtarları karşılaştırılıyor", "2 bölge aynı"} {
		if !strings.Contains(log.String(), want) {
			t.Errorf("log lacks %q:\n%s", want, log.String())
		}
	}
	if _, err := m.Upgrade(ctx, UpgradeSpec{}); err == nil {
		t.Error("upgraded twice")
	}
}

func TestUpgradeRollsBackWhenAZoneDiffers(t *testing.T) {
	m, mem, sim := oldServer(t)
	ctx := context.Background()
	var log strings.Builder
	_, err := m.Upgrade(ctx, UpgradeSpec{Log: &log, Fail: func(step string) error {
		switch step {
		case "start":
			// The new version moves the database to its schema...
			_ = os.WriteFile(sim.Path(lmdbDir+"/"+lmdbName), []byte("schema 6"), 0o600)
		case "verify":
			// ...and serves a zone differently.
			mem.zones["kahveatolyesi.com.tr."].RRSets = mem.zones["kahveatolyesi.com.tr."].RRSets[1:]
		}
		return nil
	}})
	if err == nil || !strings.Contains(err.Error(), "kahveatolyesi.com.tr.") {
		t.Fatalf("err = %v\n%s", err, log.String())
	}
	if !strings.Contains(log.String(), "↺ Eski sürüme dönülüyor") {
		t.Errorf("no rollback in the log:\n%s", log.String())
	}
	if b, _ := os.ReadFile(sim.Path(lmdbDir + "/" + lmdbName)); string(b) != "schema 5" {
		t.Errorf("database = %q, want the copy taken before", b)
	}
	if m.archiveListed() || m.PackageVersion(ctx) != simUbuntuVersion {
		t.Error("PowerDNS's archive is still listed")
	}
	if !sim.Ran("dpkg --force-confdef --force-confold -i") {
		t.Error("the old packages were not installed again")
	}
	if m.KeptVersion() != nil {
		t.Error("a failed upgrade is kept")
	}
	if dirs, _ := filepath.Glob(sim.Path(upgradeDir + "/*")); len(dirs) != 0 {
		t.Errorf("left behind: %v", dirs)
	}
}

func TestUpgradeStopsBeforeChangingWhenTheArchiveIsUnreachable(t *testing.T) {
	m, _, sim := oldServer(t)
	sim.Fail = func(c host.Command) error {
		if c.String() == "apt-get update -q" {
			return errors.New("repo.powerdns.com: connection timed out")
		}
		return nil
	}
	_, err := m.Upgrade(context.Background(), UpgradeSpec{})
	if err == nil || !strings.Contains(err.Error(), "archive could not be read") {
		t.Fatalf("err = %v", err)
	}
	if m.archiveListed() || sim.Ran("systemctl stop pdns@serfex") {
		t.Error("changed the server")
	}
}

func TestRevertPutsTheOldVersionBack(t *testing.T) {
	m, _, sim := oldServer(t)
	ctx := context.Background()
	k, err := m.Upgrade(ctx, UpgradeSpec{Fail: func(step string) error {
		if step == "start" {
			_ = os.WriteFile(sim.Path(lmdbDir+"/"+lmdbName), []byte("schema 6"), 0o600)
		}
		return nil
	}})
	if err != nil {
		t.Fatal(err)
	}
	var log strings.Builder
	if err := m.Revert(ctx, UpgradeSpec{Log: &log}); err != nil {
		t.Fatalf("%v\n%s", err, log.String())
	}
	if b, _ := os.ReadFile(sim.Path(lmdbDir + "/" + lmdbName)); string(b) != "schema 5" {
		t.Errorf("database = %q", b)
	}
	if m.archiveListed() || m.PackageVersion(ctx) != simUbuntuVersion || m.KeptVersion() != nil {
		t.Error("not back on 4.8")
	}
	if _, err := os.Stat(sim.Path(k.Dir)); err == nil {
		t.Error("the copies are still kept")
	}
	if err := m.Revert(ctx, UpgradeSpec{}); err == nil {
		t.Error("reverted twice")
	}
	if UpgradeOffer(m.PackageVersion(ctx)) == nil {
		t.Error("no upgrade offered after going back")
	}
}

func TestKeptGoesAfterAWeek(t *testing.T) {
	m, _, sim := oldServer(t)
	ctx := context.Background()
	k, err := m.Upgrade(ctx, UpgradeSpec{})
	if err != nil {
		t.Fatal(err)
	}
	m.PruneKept(ctx)
	if m.KeptVersion() == nil {
		t.Fatal("pruned too early")
	}
	m.now = func() time.Time { return k.Until.Add(time.Minute) }
	m.PruneKept(ctx)
	if m.KeptVersion() != nil {
		t.Error("still kept after a week")
	}
	if _, err := os.Stat(sim.Path(k.Dir)); err == nil {
		t.Error("the copies are still there")
	}
}
