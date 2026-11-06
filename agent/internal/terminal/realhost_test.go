//go:build realhost

// This test opens a real shell as a real site user. It needs root and
// changes the machine (an account sfxterm1), so it only builds with
// -tags realhost:
//
//	sudo go test -tags realhost -run TestRealHost ./internal/terminal
package terminal

import (
	"context"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"testing"

	"github.com/calltech-app/serfex/agent/internal/host"
)

func TestRealHostShellAsSiteUser(t *testing.T) {
	const name = "sfxterm1"
	_ = exec.Command("userdel", "-r", name).Run()
	if out, err := exec.Command("useradd", "--create-home", "--shell", "/bin/bash", name).CombinedOutput(); err != nil {
		t.Fatalf("useradd: %v %s", err, out)
	}
	t.Cleanup(func() { _ = exec.Command("userdel", "-r", name).Run() })
	_ = exec.Command("groupadd", "--system", groupShell).Run()
	if out, err := exec.Command("usermod", "--append", "--groups", groupShell, name).CombinedOutput(); err != nil {
		t.Fatalf("usermod: %v %s", err, out)
	}
	site := filepath.Join("/home", name, "example.com")
	if err := os.MkdirAll(site, 0o755); err != nil {
		t.Fatal(err)
	}

	m := NewManager(host.System{})
	sock := filepath.Join(t.TempDir(), "terminal.sock")
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	go m.Listen(ctx, sock, "")
	for i := 0; i < 100; i++ {
		if _, err := os.Stat(sock); err == nil {
			break
		}
		exec.Command("sleep", "0.01").Run()
	}
	tk, err := m.Open(ctx, OpenSpec{User: name, Dir: site})
	if err != nil {
		t.Fatal(err)
	}
	c, code := dial(t, sock, tk.Token, http.Header{})
	if code != 101 {
		t.Fatalf("status %d", code)
	}
	c.until(t, `"t":"ready"`)
	c.send(opBinary, []byte("echo \"[$(id -un)|$(pwd)|$(stat -c %U \"$(tty)\")|$HOME]\"\r"))
	c.until(t, "["+name+"|"+site+"|"+name+"|/home/"+name+"]")
	// Not root: root's files stay closed.
	c.send(opBinary, []byte("cat /etc/shadow >/dev/null 2>&1 || echo de''nied\r"))
	c.until(t, "denied")
	c.send(opBinary, []byte("exit\r"))
	c.until(t, `"reason":"exited"`)
}
