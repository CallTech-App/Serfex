package cron

import (
	"bytes"
	"context"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
	"testing"
	"time"
)

// These tests run real commands with /bin/sh, as whoever runs the tests,
// in a simulated site.

func execServer(t *testing.T, list []Task) (*Manager, int) {
	t.Helper()
	m, sim := newServer(t)
	if _, err := m.Apply(context.Background(), Spec{Domain: domain, User: user, PHP: "8.3", Tasks: list}); err != nil {
		t.Fatal(err)
	}
	u, err := sim.LookupUser(user)
	if err != nil {
		t.Fatal(err)
	}
	return m, u.UID
}

func runExec(t *testing.T, m *Manager, uid int, args ...string) (int, string) {
	t.Helper()
	var stderr bytes.Buffer
	code := execute(m.h, uid, args, &stderr)
	return code, stderr.String()
}

func lastRun(t *testing.T, m *Manager, id int) Record {
	t.Helper()
	res, err := m.Runs(context.Background(), RunsSpec{Domain: domain, ID: id})
	if err != nil || len(res.Runs) == 0 {
		t.Fatalf("no run of task %d: %+v %v", id, res, err)
	}
	if res.Running {
		t.Fatalf("task %d still running", id)
	}
	return res.Runs[0]
}

func TestExecRunsAndRecords(t *testing.T) {
	m, uid := execServer(t, []Task{
		{ID: 1, Schedule: "* * * * *", Command: `pwd; echo "$SERFEX_SITE $USER $LOGNAME $SHELL $LANG"; echo "$PATH"; echo "home=$HOME"; env | grep -cv '^PWD='; echo oops >&2`, Enabled: true},
		{ID: 2, Schedule: "* * * * *", Command: "echo failing; exit 3", Enabled: true},
		{ID: 3, Schedule: "* * * * *", Command: "echo never", Enabled: false},
		{ID: 4, Schedule: "* * * * *", Command: `i=0; while [ $i -lt 1100 ]; do printf '%0100d\n' $i; i=$((i+1)); done; echo END`, Enabled: true},
	})
	h := m.h

	if code, out := runExec(t, m, uid, domain, "1"); code != 0 {
		t.Fatalf("exit %d: %s", code, out)
	}
	r := lastRun(t, m, 1)
	lines := strings.Split(r.Output, "\n")
	if r.ExitCode != 0 || r.Trigger != TriggerSchedule || r.Skipped || r.TimedOut || r.Truncated || r.FinishedAt.Before(r.StartedAt) || r.StartedAt.Location() != time.UTC {
		t.Fatalf("record %+v", r)
	}
	realSite, _ := filepath.EvalSymlinks(h.Path(siteDir(user, domain)))
	if lines[0] != realSite || lines[1] != domain+" kahve kahve /bin/sh C.UTF-8" ||
		lines[2] != h.Path(ShimDir("8.3"))+":/usr/local/bin:/usr/bin:/bin" || lines[3] != "home="+h.Path("/home/kahve") ||
		strings.TrimSpace(lines[4]) != "7" || !strings.Contains(r.Output, "oops") {
		t.Fatalf("output %q", r.Output)
	}
	if fi, err := os.Stat(h.Path(TaskDir(user, domain, 1) + "/runs")); err != nil || fi.Mode().Perm() != 0o700 {
		t.Fatalf("runs folder %v %v", fi, err)
	}
	if _, err := os.Stat(h.Path(TaskDir(user, domain, 1) + "/running.json")); !os.IsNotExist(err) {
		t.Fatal("running marker left")
	}

	if code, _ := runExec(t, m, uid, "--manual", domain, "2"); code != 0 {
		t.Fatalf("a failing command is a recorded run, exit %d", code)
	}
	if r := lastRun(t, m, 2); r.ExitCode != 3 || r.Trigger != TriggerManual || r.Output != "failing\n" {
		t.Fatalf("record %+v", r)
	}

	// Disabled or unknown tasks do nothing on schedule; by hand a
	// disabled one runs.
	for _, id := range []string{"3", "99"} {
		if code, out := runExec(t, m, uid, domain, id); code != 0 || out != "" {
			t.Fatalf("task %s: %d %q", id, code, out)
		}
	}
	if _, err := os.Stat(h.Path(TaskDir(user, domain, 3))); !os.IsNotExist(err) {
		t.Fatal("a disabled task ran on schedule")
	}
	if code, _ := runExec(t, m, uid, "--manual", domain, "3"); code != 0 || lastRun(t, m, 3).Output != "never\n" {
		t.Fatal("a disabled task did not run by hand")
	}
	if code, _ := runExec(t, m, uid, "--manual", domain, "99"); code != 1 {
		t.Fatal("an unknown task ran by hand")
	}

	// Only the last MaxOutput bytes stay.
	runExec(t, m, uid, domain, "4")
	r = lastRun(t, m, 4)
	if !r.Truncated || len(r.Output) != MaxOutput || !strings.HasSuffix(r.Output, "END\n") {
		t.Fatalf("truncated %v, %d bytes, ends %q", r.Truncated, len(r.Output), r.Output[len(r.Output)-10:])
	}

	// Another user, bad arguments.
	if code, out := runExec(t, m, uid+1, domain, "1"); code != 1 || !strings.Contains(out, "site's user") {
		t.Fatalf("wrong user: %d %q", code, out)
	}
	for _, args := range [][]string{{}, {domain}, {domain, "x"}, {"../etc", "1"}, {domain, "0"}} {
		if code, _ := runExec(t, m, uid, args...); code != 2 {
			t.Errorf("%q: exit %d", args, code)
		}
	}
	if code, _ := runExec(t, m, uid, "gone.com", "1"); code != 0 {
		t.Fatal("a removed site's task must exit quietly")
	}
}

func TestExecTimeoutOverlapAndPruning(t *testing.T) {
	old := killGrace
	killGrace = 500 * time.Millisecond
	t.Cleanup(func() { killGrace = old })
	m, uid := execServer(t, []Task{
		// The shell ignores SIGTERM, so the group gets SIGKILL.
		{ID: 1, Schedule: "* * * * *", Command: `trap '' TERM; echo started; sleep 30; echo not reached`, Enabled: true, Timeout: 1},
		{ID: 2, Schedule: "* * * * *", Command: "echo quick", Enabled: true},
	})
	h := m.h

	start := time.Now()
	runExec(t, m, uid, domain, "1")
	if took := time.Since(start); took > 10*time.Second {
		t.Fatalf("the timeout took %s", took)
	}
	r := lastRun(t, m, 1)
	if !r.TimedOut || r.ExitCode != 128+int(syscall.SIGKILL) || !strings.HasPrefix(r.Output, "started\n") || strings.Contains(r.Output, "not reached") || !strings.Contains(r.Output, "timeout of 1s") {
		t.Fatalf("record %+v", r)
	}

	// A run while the lock is held is recorded as skipped.
	dir := h.Path(TaskDir(user, domain, 2))
	_ = os.MkdirAll(dir, 0o700)
	lock, err := os.OpenFile(filepath.Join(dir, "lock"), os.O_RDWR|os.O_CREATE, 0o600)
	if err != nil {
		t.Fatal(err)
	}
	if err := syscall.Flock(int(lock.Fd()), syscall.LOCK_EX|syscall.LOCK_NB); err != nil {
		t.Fatal(err)
	}
	runExec(t, m, uid, domain, "2")
	if r := lastRun(t, m, 2); !r.Skipped || r.Output == "quick\n" {
		t.Fatalf("record %+v", r)
	}
	lock.Close()

	for i := 0; i < RunsKept+3; i++ {
		runExec(t, m, uid, domain, "2")
	}
	entries, _ := os.ReadDir(filepath.Join(dir, "runs"))
	if len(entries) != RunsKept {
		t.Fatalf("%d records kept", len(entries))
	}
	res, _ := m.Runs(context.Background(), RunsSpec{Domain: domain, ID: 2})
	for _, r := range res.Runs {
		if r.Skipped {
			t.Fatal("the oldest record was not pruned")
		}
	}
	for i := 1; i < len(res.Runs); i++ {
		a, _ := strconv.ParseInt(res.Runs[i-1].ID, 10, 64)
		b, _ := strconv.ParseInt(res.Runs[i].ID, 10, 64)
		if a <= b {
			t.Fatal("records not newest first")
		}
	}
}

func TestTail(t *testing.T) {
	tl := &tail{max: 4}
	tl.Write([]byte("ab"))
	if tl.String() != "ab" || tl.truncated {
		t.Fatal(tl.String())
	}
	tl.Write([]byte("cdé"))
	// The last four bytes are c d 0xC3 0xA9: "cdé", whole.
	if tl.String() != "cdé" || !tl.truncated {
		t.Fatalf("%q", tl.String())
	}
	tl.Write([]byte("xé"))
	// The last four bytes start inside é; the partial character goes.
	if got := tl.String(); got != "xé" {
		t.Fatalf("%q", got)
	}
}
