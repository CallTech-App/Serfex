//go:build realhost

package ftp

// Signs in as a site's own user with real OpenSSH: SFTP locked in its
// home with a key and a password, then a shell, then nothing. Needs root,
// OpenSSH (server and the sftp client) and systemd for the bind mount:
//
//	sudo go test -tags realhost -run TestRealHostSiteUser ./internal/ftp

import (
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/pem"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"testing"

	"golang.org/x/crypto/ssh"

	"github.com/calltech-app/serfex/agent/internal/host"
)

const (
	siteUser = "serfexs"
	sitePass = "Ssh-Pass-2026-serfex"
)

func TestRealHostSiteUser(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("needs root")
	}
	ctx := context.Background()
	h := host.System{}
	m := NewManager(h)
	home := "/home/" + siteUser

	// A site user as the site manager makes it.
	if out, err := exec.Command("useradd", "--home-dir", home, "--no-create-home", "--shell", nologin, "--user-group", "--comment", siteUserComment, siteUser).CombinedOutput(); err != nil {
		t.Fatalf("useradd: %v %s", err, out)
	}
	t.Cleanup(func() {
		_, _ = m.ApplyUser(ctx, UserSpec{User: siteUser, Access: AccessOff})
		_ = exec.Command("userdel", siteUser).Run()
		_ = os.RemoveAll(home)
	})
	u, err := h.LookupUser(siteUser)
	if err != nil {
		t.Fatal(err)
	}
	docroot := home + "/web/serfex-ssh.example/public_html"
	if err := os.MkdirAll(docroot, 0o755); err != nil {
		t.Fatal(err)
	}
	_ = exec.Command("chown", "-R", siteUser+":"+siteUser, home).Run()
	_ = os.Chmod(home, 0o710)

	pub, priv, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	sshPub, _ := ssh.NewPublicKey(pub)
	block, err := ssh.MarshalPrivateKey(priv, "")
	if err != nil {
		t.Fatal(err)
	}
	keyFile := filepath.Join(t.TempDir(), "id_ed25519")
	if err := os.WriteFile(keyFile, pem.EncodeToMemory(block), 0o600); err != nil {
		t.Fatal(err)
	}
	signer, _ := ssh.NewSignerFromKey(priv)
	authorized := strings.TrimSpace(string(ssh.MarshalAuthorizedKey(sshPub))) + " realhost"

	// --- SFTP, locked in the home ---
	res, err := m.ApplyUser(ctx, UserSpec{User: siteUser, Access: AccessSFTP, Keys: []string{authorized}, Password: sitePass, PasswordLogin: true})
	if err != nil {
		t.Fatal(err)
	}
	waitFor(t, "SFTP with the key", func() error {
		_, err := sftpBatch(t, keyFile, res.Port, "pwd")
		return err
	})
	local := filepath.Join(t.TempDir(), "up.txt")
	_ = os.WriteFile(local, []byte("from sftp\n"), 0o644)
	out, err := sftpBatch(t, keyFile, res.Port, "pwd", "ls /", "ls /home", "cd web/serfex-ssh.example/public_html", "put "+local+" up.txt")
	if err != nil {
		t.Fatalf("sftp: %v %s", err, out)
	}
	if !strings.Contains(out, "Remote working directory: "+home) {
		t.Fatalf("does not start in the home:\n%s", out)
	}
	if strings.Contains(out, "etc") || strings.Contains(out, "root") {
		t.Fatalf("the chroot shows the server:\n%s", out)
	}
	assertOwner(t, docroot+"/up.txt", u.UID)
	if fi, err := os.Stat(docroot + "/up.txt"); err != nil || fi.Mode().Perm() != 0o644 {
		t.Fatalf("upload mode %v (%v)", fi.Mode(), err)
	}
	// No commands over SFTP-only access.
	if got, _ := sshRun(t, ssh.PublicKeys(signer), res.Port, "id -un"); strings.Contains(got, siteUser) {
		t.Fatalf("ran a command with SFTP-only access: %q", got)
	}
	// The password works too, and stops working when turned off.
	if _, err := sshRun(t, ssh.Password(sitePass), res.Port, ""); err != nil {
		t.Fatalf("password: %v", err)
	}
	if _, err := m.ApplyUser(ctx, UserSpec{User: siteUser, Access: AccessSFTP, Keys: []string{authorized}}); err != nil {
		t.Fatal(err)
	}
	if _, err := sshRun(t, ssh.Password(sitePass), res.Port, ""); err == nil {
		t.Fatal("password still signs in after password logins were turned off")
	}
	if _, err := sshRun(t, ssh.PublicKeys(signer), res.Port, ""); err != nil {
		t.Fatalf("key after password off: %v", err)
	}

	// --- A shell, in the real home ---
	if _, err := m.ApplyUser(ctx, UserSpec{User: siteUser, Access: AccessShell, Keys: []string{authorized}, PasswordLogin: true}); err != nil {
		t.Fatal(err)
	}
	got, err := sshRun(t, ssh.PublicKeys(signer), res.Port, "id -un; pwd; test -f /etc/passwd && echo server")
	if err != nil || strings.Fields(got)[0] != siteUser || !strings.Contains(got, home) || !strings.Contains(got, "server") {
		t.Fatalf("shell: %q (%v)", got, err)
	}
	if _, err := sshRun(t, ssh.Password(sitePass), res.Port, "true"); err != nil {
		t.Fatalf("the kept password did not come back: %v", err)
	}
	if _, err := os.Stat(UserJailRoot + "/" + siteUser); !os.IsNotExist(err) {
		t.Fatalf("a shell user keeps a chroot: %v", err)
	}

	// --- Locked (suspended), then off ---
	if _, err := m.ApplyUser(ctx, UserSpec{User: siteUser, Access: AccessShell, Keys: []string{authorized}, PasswordLogin: true, Locked: true}); err != nil {
		t.Fatal(err)
	}
	if _, err := sshRun(t, ssh.PublicKeys(signer), res.Port, "true"); err == nil {
		t.Fatal("a locked user signed in")
	}
	if _, err := m.ApplyUser(ctx, UserSpec{User: siteUser, Access: AccessOff, Keys: []string{authorized}}); err != nil {
		t.Fatal(err)
	}
	if _, err := sshRun(t, ssh.PublicKeys(signer), res.Port, "true"); err == nil {
		t.Fatal("signed in with access off")
	}
	if _, err := os.Stat(docroot + "/up.txt"); err != nil {
		t.Fatal("turning access off touched the site's files")
	}
}

func sftpBatch(t *testing.T, key string, port int, commands ...string) (string, error) {
	t.Helper()
	batch := filepath.Join(t.TempDir(), "batch")
	_ = os.WriteFile(batch, []byte(strings.Join(commands, "\n")+"\n"), 0o600)
	cmd := exec.Command("sftp", "-b", batch, "-i", key, "-P", strconv.Itoa(port), "-o", "StrictHostKeyChecking=no", "-o", "UserKnownHostsFile=/dev/null",
		"-o", "BatchMode=yes", "-o", "IdentitiesOnly=yes", siteUser+"@127.0.0.1")
	out, err := cmd.CombinedOutput()
	return string(out), err
}

// sshRun signs in and runs command; an empty command only signs in.
func sshRun(t *testing.T, auth ssh.AuthMethod, port int, command string) (string, error) {
	t.Helper()
	c, err := ssh.Dial("tcp", "127.0.0.1:"+strconv.Itoa(port), &ssh.ClientConfig{User: siteUser, Auth: []ssh.AuthMethod{auth}, HostKeyCallback: ssh.InsecureIgnoreHostKey()})
	if err != nil {
		return "", err
	}
	defer c.Close()
	if command == "" {
		return "", nil
	}
	s, err := c.NewSession()
	if err != nil {
		return "", err
	}
	defer s.Close()
	out, err := s.CombinedOutput(command)
	return string(out), err
}
