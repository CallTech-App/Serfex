package component

import (
	"context"
	"errors"
	"os"
	"strings"
	"testing"
)

func pkgRow(t *testing.T, p Packages, name string) Package {
	t.Helper()
	for _, r := range p.Packages {
		if r.Name == name {
			return r
		}
	}
	t.Fatalf("no package %s in %+v", name, p.Packages)
	return Package{}
}

func hasPkg(p Packages, name string) bool {
	for _, r := range p.Packages {
		if r.Name == name {
			return true
		}
	}
	return false
}

func TestPackagesListsUpdates(t *testing.T) {
	m, _, _ := newTest(t)
	p, err := m.Packages(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	if r := pkgRow(t, p, "libssl3t64"); !r.Security || r.Installed == "" || r.Version == r.Installed || r.Origin != "Ubuntu" || !r.selectable() {
		t.Errorf("libssl3t64 = %+v", r)
	}
	if r := pkgRow(t, p, "curl"); r.Security {
		t.Errorf("curl is not a security update: %+v", r)
	}
	if r := pkgRow(t, p, "linux-image-6.8.0-90-generic"); !r.New || r.selectable() {
		t.Errorf("the new kernel comes with linux-generic: %+v", r)
	}
	if r := pkgRow(t, p, "python3-distupgrade"); !r.Phased || r.selectable() {
		t.Errorf("phased = %+v", r)
	}
	if r := pkgRow(t, p, "ubuntu-drivers-common"); !r.KeptBack || !r.selectable() {
		t.Errorf("kept back = %+v", r)
	}
	if r := pkgRow(t, p, "cloud-init"); !r.Held || r.KeptBack || r.selectable() || r.Version == "" {
		t.Errorf("held = %+v", r)
	}
	if got := pkgRow(t, p, "nginx-common").Component; got != "nginx" {
		t.Errorf("nginx-common belongs to %q", got)
	}
	for _, n := range []string{"php8.3-fpm", "php8.3-common"} {
		if got := pkgRow(t, p, n).Component; got != "php8.3" {
			t.Errorf("%s belongs to %q", n, got)
		}
	}
	if pkgRow(t, p, "curl").Component != "" {
		t.Error("curl is no component's")
	}
	if p.Reboot.Required || p.Busy {
		t.Errorf("state = %+v", p)
	}
	if !p.Auto.Installed || !p.Auto.Enabled || !p.Auto.SecurityOnly || p.Auto.Reboot {
		t.Errorf("auto = %+v", p.Auto)
	}
}

func TestUpgradeChosenPackages(t *testing.T) {
	m, _, _ := newTest(t)
	ctx := context.Background()
	p, err := m.UpgradePackages(ctx, UpgradeSpec{Run: "p1", Packages: []string{"curl", "tzdata"}})
	if err != nil {
		t.Fatal(err)
	}
	if hasPkg(p, "curl") || hasPkg(p, "tzdata") || !hasPkg(p, "libcurl4t64") || !hasPkg(p, "libssl3t64") {
		t.Errorf("after the upgrade: %+v", p.Packages)
	}
	if p.Reboot.Required {
		t.Error("curl needs no restart")
	}
	log, _ := m.ReadRun("p1", 0)
	if !strings.Contains(log.Data, "▸ Güncellenecek paketler belirleniyor") || !strings.Contains(log.Data, "install --only-upgrade") || !strings.Contains(log.Data, "curl tzdata") || !strings.HasSuffix(log.Data, "✓ Güncellendi\n") || !log.Done {
		t.Errorf("log:\n%s", log.Data)
	}
}

func TestUpgradeAllChecksComponents(t *testing.T) {
	m, _, _ := newTest(t)
	ctx := context.Background()
	if status(t, m, "nginx").Latest == "" {
		t.Fatal("the dry run's nginx update is missing")
	}
	p, err := m.UpgradePackages(ctx, UpgradeSpec{Run: "p2"})
	if err != nil {
		t.Fatal(err)
	}
	for _, n := range []string{"libssl3t64", "linux-generic", "linux-image-6.8.0-90-generic", "nginx", "php8.3-fpm"} {
		if hasPkg(p, n) {
			t.Errorf("%s was not upgraded", n)
		}
	}
	// Left out: held, phased and kept back.
	for _, n := range []string{"cloud-init", "python3-distupgrade", "ubuntu-drivers-common"} {
		if !hasPkg(p, n) {
			t.Errorf("%s should still wait", n)
		}
	}
	if !p.Reboot.Required || p.Reboot.Since == "" || len(p.Reboot.Packages) == 0 {
		t.Errorf("reboot = %+v", p.Reboot)
	}
	if status(t, m, "nginx").Latest != "" {
		t.Error("nginx still shows an update")
	}
	log, _ := m.ReadRun("p2", 0)
	for _, want := range []string{"upgrade -y -q", "--with-new-pkgs", "▸ nginx denetleniyor", "▸ php8.3 denetleniyor", "yeniden başlatılması gerekiyor", "✓ Güncellendi"} {
		if !strings.Contains(log.Data, want) {
			t.Errorf("log lacks %q:\n%s", want, log.Data)
		}
	}
	// Nothing left that "update all" installs.
	if _, err := m.UpgradePackages(ctx, UpgradeSpec{Run: "p3"}); err != nil {
		t.Fatal(err)
	}
	if log, _ := m.ReadRun("p3", 0); !strings.Contains(log.Data, "Güncellenecek paket yok.") {
		t.Errorf("log:\n%s", log.Data)
	}
}

func TestUpgradeRefusals(t *testing.T) {
	m, _, _ := newTest(t)
	ctx := context.Background()
	for name, spec := range map[string]UpgradeSpec{
		"held":    {Run: "r1", Packages: []string{"cloud-init"}},
		"phased":  {Run: "r2", Packages: []string{"python3-distupgrade"}},
		"new":     {Run: "r3", Packages: []string{"linux-image-6.8.0-90-generic"}},
		"unknown": {Run: "r4", Packages: []string{"bash"}},
		"removes": {Run: "r5", Packages: []string{"ubuntu-drivers-common"}},
		"name":    {Run: "r6", Packages: []string{"curl; rm -rf /"}},
		"run":     {Run: "../x"},
	} {
		_, err := m.UpgradePackages(ctx, spec)
		var v *ValidationError
		if !errors.As(err, &v) {
			t.Errorf("%s: %v", name, err)
		}
	}
	if log, _ := m.ReadRun("r5", 0); !strings.Contains(log.Data, "never removes packages") || !strings.Contains(log.Data, "ubuntu-drivers-common-legacy") {
		t.Errorf("log:\n%s", log.Data)
	}
	p, _ := m.Packages(ctx)
	if !hasPkg(p, "ubuntu-drivers-common") || !hasPkg(p, "curl") {
		t.Error("a refused upgrade changed something")
	}

	m.op.Lock()
	_, err := m.UpgradePackages(ctx, UpgradeSpec{Run: "r7"})
	m.op.Unlock()
	if !errors.Is(err, ErrBusy) {
		t.Errorf("while busy: %v", err)
	}
}

func TestUpgradeComponentCheckFails(t *testing.T) {
	m, sim, _ := newTest(t)
	m.Fail = func(id, step string) error {
		if id == "nginx" && step == "check" {
			return errors.New("nginx -t failed")
		}
		return nil
	}
	_, err := m.UpgradePackages(context.Background(), UpgradeSpec{Run: "f1", Packages: []string{"nginx", "nginx-common", "curl"}})
	if err == nil || !strings.Contains(err.Error(), "nginx did not pass its check") {
		t.Fatalf("err = %v", err)
	}
	log, _ := m.ReadRun("f1", 0)
	if !strings.Contains(log.Data, "↺ Yapılandırma geri alınıyor") || !strings.Contains(log.Data, "✗ ") || !log.Done {
		t.Errorf("log:\n%s", log.Data)
	}
	if !ran(sim, "systemctl restart nginx") {
		t.Error("nginx was not restarted with its previous configuration")
	}
	// The packages themselves are in.
	p, _ := m.Packages(context.Background())
	if hasPkg(p, "curl") || hasPkg(p, "nginx") {
		t.Errorf("packages = %+v", p.Packages)
	}
}

func TestListShowsPackagesBusy(t *testing.T) {
	m, _, _ := newTest(t)
	m.setBusy(packagesBusy, "upgrade")
	if got := m.List(context.Background()).PackagesBusy; got != "upgrade" {
		t.Errorf("packages_busy = %q", got)
	}
	p, err := m.Packages(context.Background())
	if err != nil || !p.Busy {
		t.Errorf("busy = %v, %v", p.Busy, err)
	}
	if _, err := m.Reboot(context.Background(), RebootSpec{}); !errors.Is(err, ErrBusy) {
		t.Errorf("reboot while upgrading: %v", err)
	}
}

func TestPackagesNoticeOutsideChanges(t *testing.T) {
	m, sim, _ := newTest(t)
	ctx := context.Background()
	if _, err := m.Packages(ctx); err != nil {
		t.Fatal(err)
	}
	// unattended-upgrades installed curl meanwhile.
	m.simInstall(nil)
	if err := os.WriteFile(sim.Path(simUpgradedFile), []byte(`["curl"]`), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(sim.Path(dpkgStatus), []byte("changed"), 0o644); err != nil {
		t.Fatal(err)
	}
	p, _ := m.Packages(ctx)
	if hasPkg(p, "curl") {
		t.Error("the list was not worked out again")
	}
}

func TestAutoUpdates(t *testing.T) {
	m, sim, _ := newTest(t)
	ctx := context.Background()
	a, err := m.SetAutoUpdates(ctx, false)
	if err != nil || a.Enabled {
		t.Fatalf("off: %+v, %v", a, err)
	}
	if got := read(t, sim, AutoUpgradesFile); !strings.Contains(got, `APT::Periodic::Unattended-Upgrade "0";`) {
		t.Errorf("file:\n%s", got)
	}
	a, err = m.SetAutoUpdates(ctx, true)
	if err != nil || !a.Enabled || !a.SecurityOnly {
		t.Fatalf("on: %+v, %v", a, err)
	}
	if got := read(t, sim, AutoUpgradesFile); !strings.Contains(got, `APT::Periodic::Update-Package-Lists "1";`) {
		t.Errorf("file:\n%s", got)
	}
}

func TestAptConfig(t *testing.T) {
	c := aptConfig(`APT::Periodic::Unattended-Upgrade "1";
APT::Periodic::Unattended-Upgrade "0";
Unattended-Upgrade::Allowed-Origins "";
Unattended-Upgrade::Allowed-Origins:: "${distro_id}:${distro_codename}-updates";
`)
	if c.on("APT::Periodic::Unattended-Upgrade") || c.on("Missing") {
		t.Error("the last value counts; missing is off")
	}
	if got := c["Unattended-Upgrade::Allowed-Origins::"]; len(got) != 1 || got[0] != "${distro_id}:${distro_codename}-updates" {
		t.Errorf("origins = %v", got)
	}
}

func TestReboot(t *testing.T) {
	m, _, _ := newTest(t)
	ctx := context.Background()
	r, err := m.Reboot(ctx, RebootSpec{})
	if err != nil || r.Scheduled == "" {
		t.Fatalf("reboot = %+v, %v", r, err)
	}
	r, err = m.Reboot(ctx, RebootSpec{Cancel: true})
	if err != nil || r.Scheduled != "" {
		t.Fatalf("cancel = %+v, %v", r, err)
	}
}
