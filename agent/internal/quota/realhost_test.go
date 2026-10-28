//go:build realhost

// This test turns user quotas on for a small ext4 file system in a loop
// device, with its own fstab, and writes past a limit. It needs root,
// loop devices and mkfs.ext4, so it only builds with -tags realhost:
//
//	sudo go test -tags realhost -run TestRealHost ./internal/quota
package quota

import (
	"context"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"

	"github.com/calltech-app/serfex/agent/internal/host"
)

func run(t *testing.T, name string, args ...string) string {
	t.Helper()
	out, err := exec.Command(name, args...).CombinedOutput()
	if err != nil {
		t.Fatalf("%s %s: %v\n%s", name, strings.Join(args, " "), err, out)
	}
	return string(out)
}

func TestRealHost(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("needs root")
	}
	if _, err := exec.LookPath("mkfs.ext4"); err != nil {
		t.Skip("needs mkfs.ext4")
	}
	ctx := context.Background()
	dir := t.TempDir()
	img, mnt, fstab := filepath.Join(dir, "fs.img"), filepath.Join(dir, "mnt"), filepath.Join(dir, "fstab")
	run(t, "truncate", "-s", "64M", img)
	run(t, "mkfs.ext4", "-q", "-F", img)
	if err := os.Mkdir(mnt, 0o755); err != nil {
		t.Fatal(err)
	}
	if out, err := exec.Command("mount", "-o", "loop", img, mnt).CombinedOutput(); err != nil {
		t.Skipf("cannot mount a loop device here: %v %s", err, out)
	}
	t.Cleanup(func() {
		_ = exec.Command("quotaoff", "-u", mnt).Run()
		_ = exec.Command("umount", mnt).Run()
	})
	before := "# test fstab\n" + img + "\t" + mnt + "\text4\tloop,defaults\t0 0\n"
	if err := os.WriteFile(fstab, []byte(before), 0o644); err != nil {
		t.Fatal(err)
	}

	m := NewManager(host.System{})
	m.Dir, m.Fstab = mnt, fstab
	// The state file is the real one; keep what was there.
	saved, savedErr := os.ReadFile(StateFile)
	t.Cleanup(func() {
		if savedErr == nil {
			_ = os.WriteFile(StateFile, saved, 0o644)
		} else {
			_ = os.Remove(StateFile)
		}
	})

	st, err := m.Status(ctx)
	if err != nil || st.Mount != mnt || st.FSType != "ext4" || st.State != Off {
		t.Fatalf("status = %+v, %v", st, err)
	}

	st, err = m.Enable(ctx)
	if err != nil {
		t.Fatal(err)
	}
	t.Logf("after enable: %+v", st)
	if st.State == Unsupported {
		if st.Reason != ReasonKernel {
			t.Fatalf("unsupported for another reason: %+v", st)
		}
		if b, _ := os.ReadFile(fstab); string(b) != before {
			t.Fatalf("fstab changed although quotas cannot work:\n%s", b)
		}
		t.Skipf("this kernel cannot enforce quotas: %s", st.Detail)
	}
	if st.State != On {
		t.Fatalf("quotas are not on: %+v", st)
	}
	if b, _ := os.ReadFile(fstab); !strings.Contains(string(b), "loop,defaults,"+ext4Options) {
		t.Fatalf("fstab:\n%s", b)
	}

	const user = "serfexquota"
	_ = exec.Command("userdel", user).Run()
	run(t, "useradd", "--no-create-home", "--shell", "/usr/sbin/nologin", user)
	t.Cleanup(func() { _ = exec.Command("userdel", user).Run() })
	home := filepath.Join(mnt, "home")
	run(t, "install", "-d", "-o", user, home)

	if _, err := m.Set(ctx, LimitsSpec{Limits: map[string]int64{user: 1 << 20}}); err != nil {
		t.Fatal(err)
	}
	out, err := exec.Command("runuser", "-u", user, "--", "dd", "if=/dev/zero", "of="+home+"/big", "bs=64k", "count=64", "conv=fsync").CombinedOutput()
	if err == nil || !strings.Contains(string(out), "quota exceeded") {
		t.Fatalf("a 4 MiB write under a 1 MiB limit: %v\n%s", err, out)
	}
	rep, err := m.Report(ctx, UsersSpec{Users: []string{user}})
	if err != nil {
		t.Fatal(err)
	}
	if u := rep[user]; u.Limit != 1<<20 || u.Bytes <= 0 || u.Bytes > 1<<20 {
		t.Fatalf("report = %+v", rep)
	}

	// No limit: the write goes through.
	if _, err := m.Set(ctx, LimitsSpec{Limits: map[string]int64{user: 0}}); err != nil {
		t.Fatal(err)
	}
	run(t, "runuser", "-u", user, "--", "dd", "if=/dev/zero", "of="+home+"/big", "bs=64k", "count=64", "conv=fsync")
}
