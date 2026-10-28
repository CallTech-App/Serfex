package quota

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"

	"github.com/calltech-app/serfex/agent/internal/host"
)

// fakeHost keeps files under a scratch root and answers commands from a
// function, like a real server would.
type fakeHost struct {
	root  string
	ran   []string
	stdin map[string]string
	users map[string]int
	run   func(c host.Command) (string, error)
}

func (f *fakeHost) Path(abs string) string       { return filepath.Join(f.root, filepath.Clean("/"+abs)) }
func (f *fakeHost) Chown(string, int, int) error { return nil }
func (f *fakeHost) LookupUser(n string) (host.User, error) {
	if uid, ok := f.users[n]; ok {
		return host.User{Name: n, UID: uid}, nil
	}
	return host.User{}, host.ErrUserNotFound
}

func (f *fakeHost) Run(_ context.Context, c host.Command) (string, error) {
	f.ran = append(f.ran, c.String())
	if c.Stdin != nil {
		f.stdin[c.Name] = string(c.Stdin)
	}
	if f.run == nil {
		return "", nil
	}
	return f.run(c)
}

func (f *fakeHost) write(t *testing.T, abs, data string) {
	t.Helper()
	p := f.Path(abs)
	if err := os.MkdirAll(filepath.Dir(p), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(p, []byte(data), 0o644); err != nil {
		t.Fatal(err)
	}
}

func (f *fakeHost) read(t *testing.T, abs string) string {
	t.Helper()
	b, err := os.ReadFile(f.Path(abs))
	if err != nil {
		t.Fatal(err)
	}
	return string(b)
}

const (
	rootMount = "25 1 252:1 / / rw,relatime shared:1 - ext4 /dev/vda1 rw,discard,errors=remount-ro\n" +
		"30 25 0:5 / /proc rw,nosuid shared:12 - proc proc rw\n"
	cloudFstab = "# /etc/fstab: static file system information.\n" +
		"LABEL=cloudimg-rootfs\t/\t ext4\tdiscard,errors=remount-ro\t0 1\n" +
		"LABEL=UEFI\t/boot/efi\tvfat\tumask=0077\t0 1\n"
)

// ext4Server is an Ubuntu cloud server whose kernel needs the extra
// modules for quotas. Quotas come on once quotaon runs.
func ext4Server(t *testing.T) (*Manager, *fakeHost, *bool) {
	h := &fakeHost{root: t.TempDir(), stdin: map[string]string{}, users: map[string]int{"kahve": 1001, "cay": 1002}}
	h.write(t, "/proc/self/mountinfo", rootMount)
	h.write(t, "/proc/sys/kernel/osrelease", "6.8.0-85-generic\n")
	h.write(t, "/etc/fstab", cloudFstab)
	h.write(t, "/lib/modules/6.8.0-85-generic/modules.builtin", "kernel/fs/ext4/ext4.ko\n")
	on := false
	extra := false
	h.run = func(c host.Command) (string, error) {
		switch {
		case c.Name == "apt-get" && len(c.Args) > 0 && c.Args[0] == "install":
			for _, a := range c.Args {
				switch a {
				case "quota":
					for _, tool := range []string{"setquota", "repquota", "quotaon", "quotacheck"} {
						h.write(t, "/usr/sbin/"+tool, "")
					}
				case "linux-modules-extra-6.8.0-85-generic":
					extra = true
				}
			}
		case c.Name == "modprobe":
			if !extra {
				return "modprobe: FATAL: Module quota_v2 not found", errors.New("exit status 1")
			}
		case c.Name == "mount":
			h.write(t, "/proc/self/mountinfo", strings.Replace(rootMount, "errors=remount-ro\n", "errors=remount-ro,usrjquota=aquota.user,jqfmt=vfsv1\n", 1))
		case c.Name == "quotaon" && c.Args[0] == "-p":
			if on {
				return "user quota on / (/dev/vda1) is on\n", errors.New("exit status 1")
			}
			return "user quota on / (/dev/vda1) is off\n", nil
		case c.Name == "quotaon":
			on = true
		}
		return "", nil
	}
	m := NewManager(h)
	return m, h, &on
}

func TestFindMount(t *testing.T) {
	info := rootMount +
		"40 25 8:17 / /home rw,relatime shared:2 - xfs /dev/sdb1 rw,attr2,inode64,usrquota\n" +
		"41 40 8:18 / /home/backup rw shared:3 - ext4 /dev/sdb2 rw\n" +
		"42 25 8:19 / /home\\040old rw shared:4 - ext4 /dev/sdb3 rw\n"
	cases := map[string]string{"/home": "/home", "/home/kahve": "/home", "/home/backup/x": "/home/backup", "/var": "/", "/home old": "/home old", "/homes": "/"}
	for dir, want := range cases {
		mt, ok := findMount(info, dir)
		if !ok || mt.Point != want {
			t.Errorf("findMount(%q) = %q, %v; want %q", dir, mt.Point, ok, want)
		}
	}
	mt, _ := findMount(info, "/home")
	if mt.FSType != "xfs" || mt.Source != "/dev/sdb1" || !mt.has("usrquota") || mt.has("usrjquota=") {
		t.Errorf("home mount = %+v", mt)
	}
}

func TestFstabWithQuota(t *testing.T) {
	out, changed, found := fstabWithQuota(cloudFstab, "/")
	if !changed || !found {
		t.Fatalf("changed %v found %v", changed, found)
	}
	want := strings.Replace(cloudFstab, "discard,errors=remount-ro\t0 1", "discard,errors=remount-ro,usrjquota=aquota.user,jqfmt=vfsv1\t0 1", 1)
	if out != want {
		t.Fatalf("fstab:\n%s\nwant:\n%s", out, want)
	}
	if again, changed, _ := fstabWithQuota(out, "/"); changed || again != out {
		t.Fatal("options were added twice")
	}
	if _, _, found := fstabWithQuota(cloudFstab, "/home"); found {
		t.Fatal("found an entry for /home")
	}
	if _, changed, _ := fstabWithQuota("UUID=1 / ext4 defaults,usrquota 0 1\n", "/"); changed {
		t.Fatal("usrquota was not taken as quota options")
	}
}

func TestEnableExt4(t *testing.T) {
	m, h, _ := ext4Server(t)
	ctx := context.Background()

	st, err := m.Status(ctx)
	if err != nil || st.State != Off || st.Reason != ReasonNotEnabled || st.FSType != "ext4" || st.Mount != "/" {
		t.Fatalf("status before = %+v, %v", st, err)
	}

	st, err = m.Enable(ctx)
	if err != nil {
		t.Fatal(err)
	}
	if st.State != On || st.EnabledAt == "" {
		t.Fatalf("status after = %+v", st)
	}
	want := []string{
		"apt-get install -y -q --no-install-recommends quota",
		"modprobe quota_v2",
		"apt-get install -y -q --no-install-recommends linux-modules-extra-6.8.0-85-generic",
		"modprobe quota_v2",
		"mount -o remount,usrjquota=aquota.user,jqfmt=vfsv1 /",
		"quotacheck -c -u -m /",
		"quotaon -u /",
	}
	var got []string
	for _, c := range h.ran {
		if !strings.HasPrefix(c, "quotaon -p") {
			got = append(got, c)
		}
	}
	if !reflect.DeepEqual(got, want) {
		t.Fatalf("ran:\n%s\nwant:\n%s", strings.Join(got, "\n"), strings.Join(want, "\n"))
	}
	if !strings.Contains(h.read(t, "/etc/fstab"), "discard,errors=remount-ro,usrjquota=aquota.user,jqfmt=vfsv1\t0 1") {
		t.Errorf("fstab:\n%s", h.read(t, "/etc/fstab"))
	}
	if h.read(t, FstabBackup) != cloudFstab {
		t.Error("the old fstab was not kept")
	}
	if !strings.Contains(h.read(t, ModulesFile), "quota_v2") {
		t.Error("the module is not loaded at boot")
	}

	// Again: nothing to do.
	h.ran = nil
	if st, err := m.Enable(ctx); err != nil || st.State != On {
		t.Fatalf("second enable = %+v, %v", st, err)
	}
	for _, c := range h.ran {
		if !strings.HasPrefix(c, "quotaon -p") {
			t.Errorf("second enable ran %s", c)
		}
	}
}

func TestEnableWithoutKernelSupport(t *testing.T) {
	m, h, _ := ext4Server(t)
	inner := h.run
	h.run = func(c host.Command) (string, error) {
		if c.Name == "apt-get" && strings.Contains(c.String(), "linux-modules-extra") {
			return "E: Unable to locate package", errors.New("exit status 100")
		}
		return inner(c)
	}
	ctx := context.Background()
	st, err := m.Enable(ctx)
	if err != nil {
		t.Fatal(err)
	}
	if st.State != Unsupported || st.Reason != ReasonKernel || !strings.Contains(st.Detail, "6.8.0-85-generic") {
		t.Fatalf("status = %+v", st)
	}
	if h.read(t, "/etc/fstab") != cloudFstab {
		t.Error("fstab changed although quotas cannot work")
	}
	for _, c := range h.ran {
		if strings.HasPrefix(c, "mount") || strings.HasPrefix(c, "quotacheck") {
			t.Errorf("ran %s", c)
		}
	}
	// Remembered for this kernel, forgotten for the next one.
	if st, _ := m.Status(ctx); st.State != Unsupported || st.Reason != ReasonKernel {
		t.Fatalf("status later = %+v", st)
	}
	h.write(t, "/proc/sys/kernel/osrelease", "6.8.0-90-generic\n")
	if st, _ := m.Status(ctx); st.State != Off {
		t.Fatalf("status on a new kernel = %+v", st)
	}
}

func TestEnableUndoesFstabWhenQuotaonFails(t *testing.T) {
	m, h, _ := ext4Server(t)
	inner := h.run
	h.run = func(c host.Command) (string, error) {
		if c.String() == "quotaon -u /" {
			return "quotaon: using //aquota.user on /dev/vda1 [/]: No such process\nquotaon: Quota format not supported in kernel.", errors.New("exit status 1")
		}
		return inner(c)
	}
	st, err := m.Enable(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	if st.State != Unsupported || st.Reason != ReasonKernel {
		t.Fatalf("status = %+v", st)
	}
	if h.read(t, "/etc/fstab") != cloudFstab {
		t.Errorf("fstab was not restored:\n%s", h.read(t, "/etc/fstab"))
	}

	// Any other failure is an error, also with fstab restored.
	h.run = func(c host.Command) (string, error) {
		if c.Name == "quotacheck" {
			return "quotacheck: Cannot stat", errors.New("exit status 1")
		}
		return inner(c)
	}
	if _, err := m.Enable(context.Background()); err == nil || !strings.Contains(err.Error(), "quotacheck") {
		t.Fatalf("err = %v", err)
	}
	if h.read(t, "/etc/fstab") != cloudFstab {
		t.Error("fstab was not restored after quotacheck failed")
	}
}

func TestEnableWithoutFstabEntry(t *testing.T) {
	m, h, _ := ext4Server(t)
	h.write(t, "/etc/fstab", "# empty\n")
	st, err := m.Enable(context.Background())
	if err != nil || st.State != Unsupported || st.Reason != ReasonNoFstab {
		t.Fatalf("status = %+v, %v", st, err)
	}
}

func TestUnsupportedFileSystems(t *testing.T) {
	for fs, reason := range map[string]string{"btrfs": ReasonFSType, "xfs": ReasonXFSMount, "zfs": ReasonFSType} {
		m, h, _ := ext4Server(t)
		h.write(t, "/proc/self/mountinfo", strings.Replace(rootMount, "- ext4", "- "+fs, 1))
		st, err := m.Enable(context.Background())
		if err != nil || st.State != Unsupported || st.Reason != reason {
			t.Errorf("%s: %+v, %v", fs, st, err)
		}
		if len(h.ran) != 0 {
			t.Errorf("%s: ran %v", fs, h.ran)
		}
	}
}

func TestXFSMountedWithQuotas(t *testing.T) {
	m, h, on := ext4Server(t)
	h.write(t, "/proc/self/mountinfo", strings.Replace(rootMount, "- ext4 /dev/vda1 rw,discard", "- xfs /dev/vda1 rw,usrquota", 1))
	*on = true
	st, err := m.Enable(context.Background())
	if err != nil || st.State != On {
		t.Fatalf("status = %+v, %v", st, err)
	}
	if h.read(t, "/etc/fstab") != cloudFstab {
		t.Error("fstab changed on XFS")
	}
}

func TestSetAndReport(t *testing.T) {
	m, h, _ := ext4Server(t)
	ctx := context.Background()
	if _, err := m.Set(ctx, LimitsSpec{Limits: map[string]int64{"kahve": 1 << 30}}); err == nil {
		t.Fatal("limits were set while quotas are off")
	}
	if _, err := m.Enable(ctx); err != nil {
		t.Fatal(err)
	}
	res, err := m.Set(ctx, LimitsSpec{Limits: map[string]int64{"kahve": 1<<30 + 1, "cay": 0, "yok": 5 << 20, "dolu": 0}})
	if err != nil {
		t.Fatal(err)
	}
	if res.Set != 2 || !reflect.DeepEqual(res.Missing, []string{"dolu", "yok"}) {
		t.Fatalf("result = %+v", res)
	}
	if got, want := h.stdin["setquota"], "cay 0 0 0 0\nkahve 0 1048577 0 0\n"; got != want {
		t.Fatalf("setquota input = %q, want %q", got, want)
	}
	if !strings.Contains(strings.Join(h.ran, "\n"), "setquota -u -b /") {
		t.Fatalf("ran %v", h.ran)
	}
	if kib(1) != 1 || kib(1024) != 1 || kib(1025) != 2 || kib(0) != 0 {
		t.Fatal("kib rounding")
	}

	inner := h.run
	h.run = func(c host.Command) (string, error) {
		if c.Name == "repquota" {
			return "*** Report for user quotas on device /dev/vda1\n" +
				"Block grace time: 7days; Inode grace time: 7days\n" +
				"                        Block limits                File limits\n" +
				"User            used    soft    hard  grace    used  soft  hard  grace\n" +
				"----------------------------------------------------------------------\n" +
				"#0        --  4321000       0       0         98765     0     0       \n" +
				"#1001     +-  1048577       0 1048577  none     120     0     0       \n" +
				"#1002     --       16       0       0              4     0     0       \n", nil
		}
		return inner(c)
	}
	rep, err := m.Report(ctx, UsersSpec{Users: []string{"kahve", "cay", "yok"}})
	if err != nil {
		t.Fatal(err)
	}
	want := map[string]Usage{"kahve": {Bytes: 1048577 * 1024, Limit: 1048577 * 1024}, "cay": {Bytes: 16 * 1024}}
	if !reflect.DeepEqual(rep, want) {
		t.Fatalf("report = %+v", rep)
	}
	if _, err := m.Report(ctx, UsersSpec{Users: []string{"../etc"}}); err == nil {
		t.Fatal("an invalid user was accepted")
	}
}

func TestDryRun(t *testing.T) {
	sim, err := host.NewSimulator(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	m := NewManager(sim)
	ctx := context.Background()
	if st, _ := m.Status(ctx); st.State != Off {
		t.Fatalf("status = %+v", st)
	}
	if st, err := m.Enable(ctx); err != nil || st.State != On {
		t.Fatalf("enable = %+v, %v", st, err)
	}
	if err := os.MkdirAll(sim.Path("/home/kahve/web"), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(sim.Path("/home/kahve/web/index.html"), make([]byte, 3000), 0o644); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Set(ctx, LimitsSpec{Limits: map[string]int64{"kahve": 1 << 20}}); err != nil {
		t.Fatal(err)
	}
	rep, err := m.Report(ctx, UsersSpec{Users: []string{"kahve"}})
	if err != nil || rep["kahve"] != (Usage{Bytes: 3000, Limit: 1 << 20}) {
		t.Fatalf("report = %+v, %v", rep, err)
	}
}
