//go:build realhost

// This test writes the rules for a made-up site on the real machine and
// has logrotate rotate it, then removes everything. It needs root and
// logrotate, so it only builds with -tags realhost:
//
//	sudo go test -tags realhost -run TestRealHost ./internal/logrotate
package logrotate

import (
	"context"
	"os"
	"os/exec"
	"path/filepath"
	"syscall"
	"testing"

	"github.com/calltech-app/serfex/agent/internal/host"
)

func TestRealHost(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("needs root")
	}
	if _, err := exec.LookPath("logrotate"); err != nil {
		t.Skip("needs logrotate")
	}
	ctx := context.Background()
	const domain, user = "logrotate-test.serfex.test", "sfxlrtest"
	h := host.System{}
	if _, err := h.LookupUser(user); err != nil {
		if out, err := exec.Command("useradd", "--home-dir", "/home/"+user, "--no-create-home", "--shell", "/usr/sbin/nologin", "--user-group", user).CombinedOutput(); err != nil {
			t.Fatalf("useradd: %v %s", err, out)
		}
	}
	pool := "/etc/php/8.4/fpm/pool.d/" + domain + ".conf"
	siteLogs := sitesLogs + "/" + domain
	phpDir := "/home/" + user + "/web/" + domain + "/logs"
	existed := map[string]bool{}
	for _, f := range []string{File, SitesRules, PHPRules, WebRules, WebmailRules} {
		_, err := os.Stat(f)
		existed[f] = err == nil
	}
	before := Load(h)
	t.Cleanup(func() {
		os.Remove(pool)
		os.RemoveAll(siteLogs)
		os.RemoveAll("/home/" + user)
		exec.Command("userdel", user).Run()
		m := NewManager(h)
		if existed[File] {
			m.Set(ctx, before)
		} else {
			os.Remove(File)
		}
		m.Sync(ctx)
		for f, ok := range existed {
			if !ok {
				os.Remove(f)
			}
		}
	})
	if err := os.MkdirAll(filepath.Dir(pool), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(pool, []byte("; Managed by Serfex.\n["+domain+"]\nuser = "+user+"\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(siteLogs, 0o750); err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(phpDir, 0o750); err != nil {
		t.Fatal(err)
	}
	u, _ := h.LookupUser(user)
	for _, d := range []string{"/home/" + user, "/home/" + user + "/web", "/home/" + user + "/web/" + domain, phpDir} {
		os.Chown(d, u.UID, u.GID)
	}
	os.Chmod("/home/"+user, 0o755)
	if err := os.WriteFile(siteLogs+"/access.log", []byte("GET /\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(phpDir+"/php-error.log", []byte("PHP Warning\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	os.Chown(phpDir+"/php-error.log", u.UID, u.GID)

	m := NewManager(h)
	if err := m.Sync(ctx); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Set(ctx, Settings{KeepDays: 7}); err != nil {
		t.Fatal(err)
	}
	state := filepath.Join(t.TempDir(), "status")
	for _, f := range []string{SitesRules, PHPRules} {
		if out, err := exec.Command("logrotate", "--force", "--state", state, f).CombinedOutput(); err != nil {
			t.Fatalf("logrotate %s: %v\n%s", f, err, out)
		}
	}
	if _, err := os.Stat(siteLogs + "/access.log.1"); err != nil {
		t.Errorf("access log not rotated: %v", err)
	}
	if fi, err := os.Stat(siteLogs + "/access.log"); err != nil || fi.Size() != 0 {
		t.Errorf("access log not created again: %v", err)
	}
	fi, err := os.Stat(phpDir + "/php-error.log.1")
	if err != nil {
		t.Fatalf("PHP log not rotated: %v", err)
	}
	if st, ok := fi.Sys().(*syscall.Stat_t); ok && int(st.Uid) != u.UID {
		t.Errorf("PHP log owned by %d, want the site's user", st.Uid)
	}
}
