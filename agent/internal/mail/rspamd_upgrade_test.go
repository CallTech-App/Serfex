package mail

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

// oldMailServer is a mail server set up while Rspamd's archive could not
// be read: Ubuntu's Rspamd 3.8.1, with a stock file and Serfex's settings.
func oldMailServer(t *testing.T) (*Manager, *host.Simulator) {
	t.Helper()
	m, sim := newTest(t)
	sim.Fail = func(c host.Command) error {
		if c.String() == "apt-get update -q" {
			return errors.New("rspamd.com: connection timed out")
		}
		return nil
	}
	setUp(t, m)
	sim.Fail = nil
	if m.rspamdArchiveListed() {
		t.Fatal("Rspamd's archive is listed after a setup that could not read it")
	}
	if v := m.RspamdVersion(context.Background()); v != simRspamdUbuntu {
		t.Fatalf("version = %q", v)
	}
	if err := os.WriteFile(sim.Path(rspamdEtc+"/rspamd.conf"), []byte("stock 3.8\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	return m, sim
}

func TestMailSetupInstallsRspamdFromItsArchive(t *testing.T) {
	m, sim := newTest(t)
	setUp(t, m)
	if v := m.RspamdVersion(context.Background()); v != simRspamdNew || !RspamdCurrent(v) || RspamdOffer(v) != nil {
		t.Fatalf("version = %q", v)
	}
	contains(t, "sources", read(t, sim, rspamdArchive.Sources()), "URIs: https://rspamd.com/apt-stable\n", "Suites: noble\n", "Components: main\n", "Signed-By: "+rspamdArchive.Keyring())
	contains(t, "pin", read(t, sim, rspamdArchive.Pin()), "Package: rspamd*\nPin: version 4.*\nPin-Priority: 600\n")
	// The archive came before the packages.
	var order []string
	for _, c := range sim.Commands() {
		switch s := c.String(); {
		case s == "apt-get update -q" && len(order) == 0:
			order = append(order, "archive")
		case strings.HasPrefix(s, "apt-get install -y -q --no-install-recommends postfix"):
			order = append(order, "install")
		}
	}
	if strings.Join(order, " ") != "archive install" {
		t.Errorf("order = %v", order)
	}
}

func TestRspamdOffer(t *testing.T) {
	if o := RspamdOffer("3.8.1-1ubuntu3"); o == nil || o.From != "3.8" || o.To != RspamdSeries {
		t.Errorf("3.8.1: %+v", o)
	}
	for _, v := range []string{"4.2.3-1~50bde75~noble", "4.3.0-1~abc~noble", "5.0.0-1", "", "garbage"} {
		if o := RspamdOffer(v); o != nil {
			t.Errorf("%s: %+v", v, o)
		}
	}
	if !RspamdCurrent("4.3.0-1~abc~noble") || RspamdCurrent("3.8.1-1ubuntu3") || RspamdCurrent("") {
		t.Error("RspamdCurrent")
	}
}

func TestRspamdUpgradeKeepsTheOldVersionAndReverts(t *testing.T) {
	m, sim := oldMailServer(t)
	ctx := context.Background()
	var log strings.Builder
	k, err := m.UpgradeRspamd(ctx, UpgradeSpec{Log: &log})
	if err != nil {
		t.Fatalf("%v\n%s", err, log.String())
	}
	if k.From != simRspamdUbuntu || k.To != simRspamdNew || k.Until.Sub(k.Upgraded) != KeepFor {
		t.Errorf("kept = %+v", k)
	}
	if m.RspamdVersion(ctx) != simRspamdNew || !m.rspamdArchiveListed() {
		t.Fatal("not on Rspamd's own version")
	}
	if got := m.RspamdKept(); got == nil || got.Dir != k.Dir {
		t.Fatalf("RspamdKept = %+v", got)
	}
	for _, f := range []string{"/packages/rspamd_" + simRspamdUbuntu + "_amd64.deb", "/etc/rspamd.conf", "/etc/local.d/actions.conf"} {
		if _, err := os.Stat(sim.Path(k.Dir + f)); err != nil {
			t.Errorf("not kept: %s", f)
		}
	}
	// The old package and settings were kept before the new version went
	// in, and the settings were checked with it before it started.
	var order []string
	for _, c := range sim.Commands() {
		switch s := c.String(); {
		case strings.Contains(s, "apt-get download -q rspamd="+simRspamdUbuntu):
			order = append(order, "keep")
		case strings.HasPrefix(s, "apt-get install -y -q --no-install-recommends -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold rspamd"):
			order = append(order, "install")
		case s == "rspamadm configtest" && len(order) > 1:
			order = append(order, "check")
		case s == "systemctl restart rspamd" && len(order) > 2:
			order = append(order, "start")
		}
	}
	if strings.Join(order, " ") != "keep install check start" {
		t.Errorf("order = %v\n%s", order, log.String())
	}
	for _, want := range []string{"Rspamd paket deposu ekleniyor (rspamd.com)", "Eski sürümün paketi ve ayarları saklanıyor (3.8.1)", "Rspamd 4.2 kuruluyor", "Rspamd 4.2.3 ayarlarla denetleniyor"} {
		if !strings.Contains(log.String(), want) {
			t.Errorf("log lacks %q:\n%s", want, log.String())
		}
	}
	if _, err := m.UpgradeRspamd(ctx, UpgradeSpec{}); err == nil {
		t.Error("upgraded again")
	}

	// After the upgrade the stock files are 4.x's and Serfex wrote its
	// settings again (a new limit).
	if err := os.WriteFile(sim.Path(rspamdEtc+"/rspamd.conf"), []byte("stock 4.2\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(sim.Path(rspamdLocal+"/actions.conf"), []byte("reject = 20;\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	log.Reset()
	if err := m.RevertRspamd(ctx, UpgradeSpec{Log: &log}); err != nil {
		t.Fatalf("%v\n%s", err, log.String())
	}
	if m.RspamdVersion(ctx) != simRspamdUbuntu || m.rspamdArchiveListed() {
		t.Error("not back on Ubuntu's version")
	}
	if !sim.Ran("dpkg --force-confdef --force-confold -i " + k.Dir + "/packages/rspamd_" + simRspamdUbuntu + "_amd64.deb") {
		t.Error("the kept package was not installed")
	}
	if got := read(t, sim, rspamdEtc+"/rspamd.conf"); got != "stock 3.8\n" {
		t.Errorf("stock file after the revert: %q", got)
	}
	if got := read(t, sim, rspamdLocal+"/actions.conf"); got != "reject = 20;\n" {
		t.Errorf("Serfex's settings after the revert: %q", got)
	}
	if m.RspamdKept() != nil {
		t.Error("still kept after the revert")
	}
	if _, err := os.Stat(sim.Path(k.Dir)); err == nil {
		t.Error("the kept files stayed after the revert")
	}
	if RspamdOffer(m.RspamdVersion(ctx)) == nil {
		t.Error("no upgrade offered again")
	}
}

func TestRspamdUpgradeFailurePutsTheOldVersionBack(t *testing.T) {
	for _, step := range []string{"archive", "install", "start"} {
		t.Run(step, func(t *testing.T) {
			m, sim := oldMailServer(t)
			ctx := context.Background()
			local := read(t, sim, rspamdLocal+"/actions.conf")
			var log strings.Builder
			_, err := m.UpgradeRspamd(ctx, UpgradeSpec{Log: &log, Fail: func(s string) error {
				if s == step {
					return errors.New("broken on purpose")
				}
				return nil
			}})
			if err == nil {
				t.Fatal("no error")
			}
			if v := m.RspamdVersion(ctx); v != simRspamdUbuntu {
				t.Errorf("version = %q", v)
			}
			if m.rspamdArchiveListed() || m.RspamdKept() != nil {
				t.Error("the archive or a kept version stayed")
			}
			if left, _ := filepath.Glob(sim.Path(rspamdUpgradeDir + "/*")); len(left) != 0 {
				t.Errorf("left: %v", left)
			}
			if got := read(t, sim, rspamdEtc+"/rspamd.conf"); got != "stock 3.8\n" {
				t.Errorf("stock file: %q", got)
			}
			if got := read(t, sim, rspamdLocal+"/actions.conf"); got != local {
				t.Errorf("Serfex's settings: %q", got)
			}
			if step != "archive" && !strings.Contains(log.String(), "↺ Eski sürüme dönülüyor") {
				t.Errorf("log:\n%s", log.String())
			}
		})
	}
}

func TestRspamdKeptVersionGoes(t *testing.T) {
	m, sim := oldMailServer(t)
	ctx := context.Background()
	k, err := m.UpgradeRspamd(ctx, UpgradeSpec{})
	if err != nil {
		t.Fatal(err)
	}
	m.PruneRspamd(ctx)
	if m.RspamdKept() == nil {
		t.Fatal("pruned before its time")
	}
	k.Until = time.Now().Add(-time.Minute)
	if err := m.saveRspamdKept(k); err != nil {
		t.Fatal(err)
	}
	m.PruneRspamd(ctx)
	if m.RspamdKept() != nil {
		t.Error("kept after its time")
	}
	if _, err := os.Stat(sim.Path(k.Dir)); err == nil {
		t.Error("files kept after their time")
	}
	if err := m.RevertRspamd(ctx, UpgradeSpec{}); err == nil {
		t.Error("reverted with nothing kept")
	}
	if m.RspamdVersion(ctx) != simRspamdNew {
		t.Error("the new version did not stay")
	}
}

func TestRspamdUpgradeNeedsAnOldVersion(t *testing.T) {
	m, _ := newTest(t)
	ctx := context.Background()
	if _, err := m.UpgradeRspamd(ctx, UpgradeSpec{}); !errors.Is(err, ErrNotSetUp) {
		t.Errorf("before setup: %v", err)
	}
	setUp(t, m)
	var v *ValidationError
	if _, err := m.UpgradeRspamd(ctx, UpgradeSpec{}); !errors.As(err, &v) {
		t.Errorf("on the current version: %v", err)
	}
	if err := m.UpdateRspamd(ctx, nil); err != nil {
		t.Errorf("update: %v", err)
	}
}
