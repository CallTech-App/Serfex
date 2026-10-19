package ftp

import (
	"context"
	"errors"
	"os"
	"strings"
	"testing"

	"github.com/winchester37/servik/agent/internal/host"
)

func newServer(t *testing.T) (*Manager, *host.Simulator) {
	t.Helper()
	sim, err := host.NewSimulator(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	if _, err := sim.Run(context.Background(), host.Command{Name: "useradd", Args: []string{"--home-dir", "/home/kahve", "--shell", "/usr/sbin/nologin", "kahve"}}); err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(sim.Path("/home/kahve/web/kahve-shop.com/public_html"), 0o755); err != nil {
		t.Fatal(err)
	}
	_ = os.MkdirAll(sim.Path("/usr/sbin"), 0o755)
	_ = os.WriteFile(sim.Path("/usr/sbin/sshd"), nil, 0o755)
	return NewManager(sim), sim
}

func TestUnitName(t *testing.T) {
	if got := unitName("/var/lib/servik/sftp/kahve_ftp/kahve-shop.com"); got != `var-lib-servik-sftp-kahve_ftp-kahve\x2dshop.com.mount` {
		t.Fatal(got)
	}
}

func TestApplyLockDelete(t *testing.T) {
	m, sim := newServer(t)
	ctx := context.Background()
	res, err := m.Apply(ctx, AccountSpec{Name: "kahve_deploy", User: "kahve", Domain: "kahve-shop.com", Dir: "public_html", Password: "s3cret-pass"})
	if err != nil {
		t.Fatal(err)
	}
	if !res.Created || res.Folder != "/public_html" || res.Port != 22 {
		t.Fatalf("result = %+v", res)
	}
	owner, _ := sim.LookupUser("kahve")
	acc, err := sim.LookupUser("kahve_deploy")
	if err != nil || acc.UID != owner.UID || acc.GID != owner.GID || acc.Shell != "/usr/sbin/nologin" {
		t.Fatalf("account = %+v (%v)", acc, err)
	}
	conf, _ := os.ReadFile(sim.Path(ConfigFile))
	if !strings.Contains(string(conf), "ChrootDirectory "+JailRoot+"/%u") || !strings.Contains(string(conf), "ForceCommand internal-sftp") {
		t.Fatalf("sshd config = %s", conf)
	}
	unit, err := os.ReadFile(sim.Path("/etc/systemd/system/" + unitName(JailRoot+"/kahve_deploy/public_html")))
	if err != nil || !strings.Contains(string(unit), "What=/home/kahve/web/kahve-shop.com/public_html") {
		t.Fatalf("unit = %s (%v)", unit, err)
	}
	if !sim.Ran("chpasswd") || !sim.Ran("usermod --unlock") {
		t.Fatal("password not set or account not unlocked")
	}

	// Another folder: the old mount goes, the new one comes.
	if _, err := m.Apply(ctx, AccountSpec{Name: "kahve_deploy", User: "kahve", Domain: "kahve-shop.com", Dir: "", Locked: true}); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(sim.Path("/etc/systemd/system/" + unitName(JailRoot+"/kahve_deploy/public_html"))); !errors.Is(err, os.ErrNotExist) {
		t.Fatal("old mount unit left behind")
	}
	if _, err := os.Stat(sim.Path("/etc/systemd/system/" + unitName(JailRoot+"/kahve_deploy/kahve-shop.com"))); err != nil {
		t.Fatal(err)
	}
	if !sim.Ran("usermod --lock --expiredate 1 kahve_deploy") {
		t.Fatal("not locked")
	}
	list, _ := m.List()
	if len(list) != 1 || !list[0].Locked {
		t.Fatalf("list = %+v", list)
	}

	// Names taken by other accounts, folders outside the site and the
	// site's own user are refused.
	for _, spec := range []AccountSpec{
		{Name: "www-data", User: "kahve", Domain: "kahve-shop.com", Password: "x"},
		{Name: "kahve", User: "kahve", Domain: "kahve-shop.com", Password: "x"},
		{Name: "kahve_x", User: "kahve", Domain: "kahve-shop.com", Dir: "../..", Password: "x"},
		{Name: "kahve_y", User: "kahve", Domain: "kahve-shop.com", Password: "a:b"},
	} {
		var v *ValidationError
		if _, err := m.Apply(ctx, spec); !errors.As(err, &v) {
			t.Fatalf("%+v: %v", spec, err)
		}
	}
	_ = os.Symlink(sim.Path("/etc"), sim.Path("/home/kahve/web/kahve-shop.com/etc"))
	if _, err := m.Apply(ctx, AccountSpec{Name: "kahve_z", User: "kahve", Domain: "kahve-shop.com", Dir: "etc", Password: "x"}); err == nil || !strings.Contains(err.Error(), "outside") {
		t.Fatalf("symlink out: %v", err)
	}
	if _, err := sim.LookupUser("kahve_z"); !errors.Is(err, host.ErrUserNotFound) {
		t.Fatal("refused account left a user behind")
	}

	if err := m.Delete(ctx, "kahve_deploy"); err != nil {
		t.Fatal(err)
	}
	if _, err := sim.LookupUser("kahve_deploy"); !errors.Is(err, host.ErrUserNotFound) {
		t.Fatal("user left")
	}
	if _, err := os.Stat(sim.Path(JailRoot + "/kahve_deploy")); !errors.Is(err, os.ErrNotExist) {
		t.Fatal("chroot left")
	}
	if _, err := os.Stat(sim.Path("/home/kahve/web/kahve-shop.com/public_html")); err != nil {
		t.Fatal("site files touched")
	}
}
