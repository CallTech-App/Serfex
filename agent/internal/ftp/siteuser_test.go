package ftp

import (
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"crypto/rsa"
	"errors"
	"os"
	"strings"
	"testing"

	"golang.org/x/crypto/ssh"

	"github.com/calltech-app/serfex/agent/internal/host"
)

func newSiteUser(t *testing.T) (*Manager, *host.Simulator) {
	t.Helper()
	sim, err := host.NewSimulator(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	if _, err := sim.Run(context.Background(), host.Command{Name: "useradd", Args: []string{"--home-dir", "/home/kahve", "--shell", nologin, "--comment", siteUserComment, "kahve"}}); err != nil {
		t.Fatal(err)
	}
	_ = os.MkdirAll(sim.Path("/home/kahve/web/kahve-shop.com/public_html"), 0o755)
	_ = os.MkdirAll(sim.Path("/usr/sbin"), 0o755)
	_ = os.WriteFile(sim.Path("/usr/sbin/sshd"), nil, 0o755)
	return NewManager(sim), sim
}

func publicKey(t *testing.T, pub any, comment string) string {
	t.Helper()
	k, err := ssh.NewPublicKey(pub)
	if err != nil {
		t.Fatal(err)
	}
	return strings.TrimSpace(string(ssh.MarshalAuthorizedKey(k))) + " " + comment
}

func ed25519Key(t *testing.T) string {
	pub, _, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	return publicKey(t, pub, "sercan@laptop")
}

func TestApplyUserSFTPShellOff(t *testing.T) {
	m, sim := newSiteUser(t)
	ctx := context.Background()
	key := ed25519Key(t)
	res, err := m.ApplyUser(ctx, UserSpec{User: "kahve", Access: AccessSFTP, Keys: []string{key}})
	if err != nil {
		t.Fatal(err)
	}
	if res.Access != AccessSFTP || res.Folder != "/home/kahve" || res.Keys != 1 || res.Port != 22 {
		t.Fatalf("result = %+v", res)
	}
	conf, _ := os.ReadFile(sim.Path(ConfigFile))
	for _, want := range []string{"Match Group " + GroupSFTP, "ChrootDirectory " + UserJailRoot + "/%u", "AuthorizedKeysFile " + KeysDir + "/%u", "Match Group " + GroupShell, "AllowTcpForwarding local"} {
		if !strings.Contains(string(conf), want) {
			t.Fatalf("sshd config misses %q:\n%s", want, conf)
		}
	}
	keys, err := os.ReadFile(sim.Path(KeysDir + "/kahve"))
	if err != nil || !strings.Contains(string(keys), key+"\n") {
		t.Fatalf("keys = %s (%v)", keys, err)
	}
	unit, err := os.ReadFile(sim.Path("/etc/systemd/system/" + unitName(homeMount("kahve"))))
	if err != nil || !strings.Contains(string(unit), "What=/home/kahve\n") || !strings.Contains(string(unit), "Where="+UserJailRoot+"/kahve/home/kahve\n") {
		t.Fatalf("unit = %s (%v)", unit, err)
	}
	if !sim.Ran("usermod --append --groups serfex-ssh kahve") || sim.Ran("usermod --shell") || sim.Ran("chpasswd") {
		t.Fatalf("commands = %v", sim.Commands())
	}

	// A password: set once, then only unlocked or locked.
	_ = os.WriteFile(sim.Path("/etc/group"), []byte("kahve:x:1000:\nserfex-ssh:x:998:kahve\nserfex-shell:x:997:\n"), 0o644)
	if _, err := m.ApplyUser(ctx, UserSpec{User: "kahve", Access: AccessSFTP, Keys: []string{key}, Password: "kahve-parola-2026", PasswordLogin: true}); err != nil {
		t.Fatal(err)
	}
	if !sim.Ran("chpasswd") || sim.Ran("usermod --lock") {
		t.Fatal("password not set")
	}
	_ = os.WriteFile(sim.Path("/etc/shadow"), []byte("kahve:$6$abc$def:20000:0:99999:7:::\n"), 0o640)
	if _, err := m.ApplyUser(ctx, UserSpec{User: "kahve", Access: AccessSFTP, Keys: []string{key}}); err != nil {
		t.Fatal(err)
	}
	if !sim.Ran("usermod --lock kahve") || !sim.Ran("pkill -KILL -f ^sshd(-session)?: kahve(@| |$)") {
		t.Fatalf("password login not locked: %v", sim.Commands())
	}
	_ = os.WriteFile(sim.Path("/etc/shadow"), []byte("kahve:!$6$abc$def:20000:0:99999:7:::\n"), 0o640)
	if _, err := m.ApplyUser(ctx, UserSpec{User: "kahve", Access: AccessSFTP, Keys: []string{key}, PasswordLogin: true}); err != nil {
		t.Fatal(err)
	}
	if !sim.Ran("usermod --unlock kahve") {
		t.Fatal("password login not unlocked")
	}

	// A shell: out of the chroot group, into the shell group, bash.
	if _, err := m.ApplyUser(ctx, UserSpec{User: "kahve", Access: AccessShell, Keys: []string{key}}); err != nil {
		t.Fatal(err)
	}
	if !sim.Ran("gpasswd --delete kahve serfex-ssh") || !sim.Ran("usermod --append --groups serfex-shell kahve") || !sim.Ran("usermod --shell /bin/bash kahve") {
		t.Fatalf("commands = %v", sim.Commands())
	}
	if _, err := os.Stat(sim.Path(UserJailRoot + "/kahve")); !errors.Is(err, os.ErrNotExist) {
		t.Fatal("a shell user keeps a chroot")
	}

	// Off: keys and groups go, the home stays.
	_ = os.WriteFile(sim.Path("/etc/group"), []byte("kahve:x:1000:\nserfex-ssh:x:998:\nserfex-shell:x:997:kahve\n"), 0o644)
	if _, err := m.ApplyUser(ctx, UserSpec{User: "kahve", Access: AccessOff, Keys: []string{key}}); err != nil {
		t.Fatal(err)
	}
	if !sim.Ran("gpasswd --delete kahve serfex-shell") {
		t.Fatal("shell group kept")
	}
	if _, err := os.Stat(sim.Path(KeysDir + "/kahve")); !errors.Is(err, os.ErrNotExist) {
		t.Fatal("keys kept")
	}
	if _, err := os.Stat(sim.Path("/home/kahve/web/kahve-shop.com/public_html")); err != nil {
		t.Fatal("home touched")
	}
}

func TestApplyUserLockedSignsNothingIn(t *testing.T) {
	m, sim := newSiteUser(t)
	_ = os.WriteFile(sim.Path("/etc/group"), []byte("kahve:x:1000:\nserfex-ssh:x:998:kahve\n"), 0o644)
	_ = os.WriteFile(sim.Path("/etc/shadow"), []byte("kahve:$6$abc$def:20000:0:99999:7:::\n"), 0o640)
	res, err := m.ApplyUser(context.Background(), UserSpec{User: "kahve", Access: AccessSFTP, PasswordLogin: true, Locked: true})
	if err != nil {
		t.Fatal(err)
	}
	if res.Access != AccessOff || !sim.Ran("gpasswd --delete kahve serfex-ssh") || !sim.Ran("usermod --lock kahve") || !sim.Ran("pkill") {
		t.Fatalf("result = %+v, commands = %v", res, sim.Commands())
	}
}

func TestApplyUserRefuses(t *testing.T) {
	m, sim := newSiteUser(t)
	ctx := context.Background()
	if _, err := sim.Run(ctx, host.Command{Name: "useradd", Args: []string{"--home-dir", "/home/sercan", "--shell", bash, "sercan"}}); err != nil {
		t.Fatal(err)
	}
	weak, err := rsa.GenerateKey(rand.Reader, 1024)
	if err != nil {
		t.Fatal(err)
	}
	key := ed25519Key(t)
	for name, spec := range map[string]UserSpec{
		"not a site user":    {User: "sercan", Access: AccessSFTP},
		"root":               {User: "root", Access: AccessSFTP},
		"missing":            {User: "yok", Access: AccessSFTP},
		"unknown access":     {User: "kahve", Access: "root"},
		"garbage key":        {User: "kahve", Access: AccessSFTP, Keys: []string{"ssh-ed25519 bm90IGEga2V5"}},
		"key with options":   {User: "kahve", Access: AccessSFTP, Keys: []string{`command="/bin/sh" ` + key}},
		"two keys in one":    {User: "kahve", Access: AccessSFTP, Keys: []string{key + "\n" + key}},
		"weak rsa":           {User: "kahve", Access: AccessSFTP, Keys: []string{publicKey(t, &weak.PublicKey, "eski")}},
		"password with ':'":  {User: "kahve", Access: AccessSFTP, Password: "a:b", PasswordLogin: true},
		"no password to use": {User: "kahve", Access: AccessSFTP, PasswordLogin: true},
	} {
		var v *ValidationError
		if _, err := m.ApplyUser(ctx, spec); !errors.As(err, &v) {
			t.Errorf("%s: %v", name, err)
		}
	}
	if _, err := os.Stat(sim.Path(KeysDir + "/kahve")); !errors.Is(err, os.ErrNotExist) {
		t.Fatal("a refused apply wrote keys")
	}
}
