//go:build realhost

// This test provisions a real site, works on its files through the file
// manager's helper and signs in over SFTP with real OpenSSH. It needs
// root, Nginx, PHP-FPM, OpenSSH and curl, and changes the machine, so it
// only builds with -tags realhost:
//
//	sudo go test -tags realhost -run TestRealHost ./internal/ftp
package ftp

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"syscall"
	"testing"
	"time"

	"github.com/winchester37/servik/agent/internal/files"
	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/site"
)

const (
	domain = "servik-ftp.example"
	owner  = "servikf"
	acct   = "servikf_deploy"
	pass   = "Sftp-Pass-2026"
)

func TestRealHost(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("needs root")
	}
	ctx := context.Background()
	h := host.System{}

	// The file helper is the agent binary itself, run as the site's user;
	// it must be somewhere every user may execute it.
	bin := "/usr/local/lib/servik-test/servik-agent"
	if out, err := exec.Command("go", "build", "-o", bin, "../../cmd/servik-agent").CombinedOutput(); err != nil {
		t.Fatalf("building the agent: %v\n%s", err, out)
	}
	_ = os.Chmod(filepath.Dir(bin), 0o755)

	sites := site.NewManager(h, "www-data")
	versions, err := sites.PHPVersions()
	if err != nil || len(versions) == 0 {
		t.Fatalf("no PHP-FPM installed: %v", err)
	}
	m := NewManager(h)
	t.Cleanup(func() {
		_ = m.Delete(ctx, acct)
		_, _ = sites.Delete(ctx, site.DeleteSpec{Domain: domain, User: owner, RemoveFiles: true, RemoveUser: true})
	})
	res, err := sites.Apply(ctx, site.Spec{Domain: domain, User: owner, PHPVersion: versions[len(versions)-1]})
	if err != nil {
		t.Fatal(err)
	}
	ownerUser, err := h.LookupUser(owner)
	if err != nil {
		t.Fatal(err)
	}

	// --- The file manager, as the site's user ---
	fm := files.NewManager(h, bin)
	call := func(r files.Request) map[string]any {
		t.Helper()
		out, err := fm.Do(ctx, files.Spec{User: owner, Domain: domain, Request: r})
		if err != nil {
			t.Fatalf("%s: %v", r.Op, err)
		}
		var m map[string]any
		b, _ := json.Marshal(out)
		_ = json.Unmarshal(b, &m)
		return m
	}
	call(files.Request{Op: "upload", Path: "public_html", Name: "hello.php", Upload: "up-realhost1", Data: []byte(`<?php echo "from ", posix_getpwuid(posix_geteuid())['name'];`), Final: true})
	if got := get(t, "/hello.php"); got != "from "+owner {
		t.Fatalf("uploaded PHP answered %q", got)
	}
	text := call(files.Request{Op: "text", Path: "public_html/hello.php"})
	call(files.Request{Op: "save", Path: "public_html/hello.php", Data: []byte(`<?php echo "edited";`), MTime: int64(text["mtime"].(float64))})
	if got := get(t, "/hello.php"); got != "edited" {
		t.Fatalf("edited PHP answered %q", got)
	}
	assertOwner(t, filepath.Join(res.DocumentRoot, "hello.php"), ownerUser.UID)
	del := call(files.Request{Op: "delete", Paths: []string{"public_html/hello.php"}})
	call(files.Request{Op: "trash.restore", IDs: []string{del["ids"].([]any)[0].(string)}})
	if got := get(t, "/hello.php"); got != "edited" {
		t.Fatalf("restored PHP answered %q", got)
	}
	// The helper cannot read what the site's user cannot.
	if _, err := fm.Do(ctx, files.Spec{User: owner, Domain: domain, Request: files.Request{Op: "list", Path: "../../../../root"}}); err == nil {
		t.Fatal("the file manager left the site")
	}
	if err := os.Symlink("/root", filepath.Join(filepath.Dir(res.DocumentRoot), "rootlink")); err != nil {
		t.Fatal(err)
	}
	if _, err := fm.Do(ctx, files.Spec{User: owner, Domain: domain, Request: files.Request{Op: "list", Path: "rootlink"}}); err == nil {
		t.Fatal("the file manager followed a link out of the site")
	}
	_ = os.Remove(filepath.Join(filepath.Dir(res.DocumentRoot), "rootlink"))

	// --- SFTP ---
	if _, err := m.Apply(ctx, AccountSpec{Name: acct, User: owner, Domain: domain, Dir: "public_html", Password: pass}); err != nil {
		t.Fatal(err)
	}
	waitFor(t, "SFTP sign-in", func() error {
		_, err := sftp(t, pass, "sftp://127.0.0.1/")
		return err
	})
	// The chroot shows only the account's folder.
	top, err := names(sftp(t, pass, "sftp://127.0.0.1/", "-l"))
	if err != nil || top != "public_html" {
		t.Fatalf("chroot lists %q (%v)", top, err)
	}
	if out, err := sftp(t, pass, "sftp://127.0.0.1/etc/passwd"); err == nil {
		t.Fatalf("read /etc/passwd through SFTP: %q", out)
	}
	// Uploads belong to the site's user and are served at once.
	local := filepath.Join(t.TempDir(), "up.php")
	_ = os.WriteFile(local, []byte(`<?php echo "sftp";`), 0o644)
	if out, err := sftp(t, pass, "sftp://127.0.0.1/public_html/up.php", "-T", local); err != nil {
		t.Fatalf("upload: %v %s", err, out)
	}
	assertOwner(t, filepath.Join(res.DocumentRoot, "up.php"), ownerUser.UID)
	if got := get(t, "/up.php"); got != "sftp" {
		t.Fatalf("SFTP upload answered %q", got)
	}
	if fi, err := os.Stat(filepath.Join(res.DocumentRoot, "up.php")); err != nil || fi.Mode().Perm() != 0o644 {
		t.Fatalf("upload mode %v (%v)", fi.Mode(), err)
	}

	// A suspended customer's account is locked: the password no longer works.
	if _, err := m.Apply(ctx, AccountSpec{Name: acct, User: owner, Domain: domain, Dir: "public_html", Locked: true}); err != nil {
		t.Fatal(err)
	}
	if out, err := sftp(t, pass, "sftp://127.0.0.1/"); err == nil {
		t.Fatalf("locked account signed in: %q", out)
	}
	if _, err := m.Apply(ctx, AccountSpec{Name: acct, User: owner, Domain: domain, Dir: "public_html"}); err != nil {
		t.Fatal(err)
	}
	if _, err := sftp(t, pass, "sftp://127.0.0.1/"); err != nil {
		t.Fatalf("unlocked account: %v", err)
	}
	// A new password replaces the old one.
	if _, err := m.Apply(ctx, AccountSpec{Name: acct, User: owner, Domain: domain, Dir: "public_html", Password: pass + "-2"}); err != nil {
		t.Fatal(err)
	}
	if _, err := sftp(t, pass, "sftp://127.0.0.1/"); err == nil {
		t.Fatal("old password still works")
	}
	if _, err := sftp(t, pass+"-2", "sftp://127.0.0.1/"); err != nil {
		t.Fatalf("new password: %v", err)
	}

	// The whole site folder instead of public_html.
	if _, err := m.Apply(ctx, AccountSpec{Name: acct, User: owner, Domain: domain}); err != nil {
		t.Fatal(err)
	}
	top, err = names(sftp(t, pass+"-2", "sftp://127.0.0.1/", "-l"))
	if err != nil || top != domain {
		t.Fatalf("after moving the folder the chroot lists %q (%v)", top, err)
	}

	// Deleting the account keeps the site's files.
	if err := m.Delete(ctx, acct); err != nil {
		t.Fatal(err)
	}
	if _, err := sftp(t, pass+"-2", "sftp://127.0.0.1/"); err == nil {
		t.Fatal("deleted account signed in")
	}
	if _, err := os.Stat(filepath.Join(res.DocumentRoot, "up.php")); err != nil {
		t.Fatal("deleting the account removed site files")
	}
	if _, err := os.Stat(JailRoot + "/" + acct); !os.IsNotExist(err) {
		t.Fatalf("chroot left: %v", err)
	}
}

func sftp(t *testing.T, password, url string, extra ...string) (string, error) {
	t.Helper()
	args := append([]string{"-sS", "--insecure", "--max-time", "20", "-u", acct + ":" + password}, extra...)
	args = append(args, url)
	out, err := exec.Command("curl", args...).CombinedOutput()
	if err != nil {
		return string(out), fmt.Errorf("curl %s: %w: %s", url, err, out)
	}
	return string(out), nil
}

// names turns a listing into its names without . and .., comma-separated.
func names(out string, err error) (string, error) {
	var keep []string
	for _, n := range strings.Fields(out) {
		if n != "." && n != ".." {
			keep = append(keep, n)
		}
	}
	return strings.Join(keep, ","), err
}

func get(t *testing.T, path string) string {
	t.Helper()
	req, _ := http.NewRequest("GET", "http://127.0.0.1"+path, nil)
	req.Host = domain
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		t.Fatal(err)
	}
	defer resp.Body.Close()
	b, _ := io.ReadAll(resp.Body)
	return strings.TrimSpace(string(b))
}

func assertOwner(t *testing.T, p string, uid int) {
	t.Helper()
	fi, err := os.Stat(p)
	if err != nil {
		t.Fatal(err)
	}
	if st := fi.Sys().(*syscall.Stat_t); int(st.Uid) != uid {
		t.Fatalf("%s belongs to uid %d, not the site's %d", p, st.Uid, uid)
	}
}

func waitFor(t *testing.T, what string, f func() error) {
	t.Helper()
	var err error
	for i := 0; i < 20; i++ {
		if err = f(); err == nil {
			return
		}
		time.Sleep(500 * time.Millisecond)
	}
	t.Fatalf("%s: %v", what, err)
}
