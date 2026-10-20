//go:build realhost

// This test provisions a real site and runs its scheduled tasks for
// real: by hand through the agent (runuser) and on schedule through the
// system's cron daemon. It needs root, Nginx, PHP-FPM and cron, and
// changes the machine, so it only builds with -tags realhost:
//
//	sudo go test -tags realhost -run TestRealHost ./internal/cron
package cron

import (
	"context"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"syscall"
	"testing"
	"time"

	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/site"
)

const (
	rhDomain = "servik-cron.example"
	rhOwner  = "servikc"
)

func TestRealHost(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("needs root")
	}
	ctx := context.Background()
	h := host.System{}

	// cron-exec is the agent binary itself, run as the site's user; it
	// must be somewhere every user may execute it.
	bin := "/usr/local/lib/servik-test-cron/servik-agent"
	if out, err := exec.Command("go", "build", "-o", bin, "../../cmd/servik-agent").CombinedOutput(); err != nil {
		t.Fatalf("building the agent: %v\n%s", err, out)
	}
	_ = os.Chmod(filepath.Dir(bin), 0o755)

	sites := site.NewManager(h, "www-data")
	versions, err := sites.PHPVersions()
	if err != nil || len(versions) == 0 {
		t.Fatalf("no PHP-FPM installed: %v", err)
	}
	php := versions[len(versions)-1]
	m := NewManager(h)
	m.Exe = bin
	t.Cleanup(func() {
		_, _ = m.Remove(ctx, rhDomain)
		_, _ = sites.Delete(ctx, site.DeleteSpec{Domain: rhDomain, User: rhOwner, RemoveFiles: true, RemoveUser: true})
	})
	if _, err := sites.Apply(ctx, site.Spec{Domain: rhDomain, User: rhOwner, PHPVersion: php}); err != nil {
		t.Fatal(err)
	}
	siteFolder := "/home/" + rhOwner + "/web/" + rhDomain

	big := `head -c 100000 /dev/zero | tr '\0' a; echo; echo END`
	tasks := []Task{
		{ID: 1, Schedule: "0 0 1 1 *", Command: `id -un; pwd; echo "$SERVIK_SITE"; command -v php`},
		{ID: 2, Schedule: "0 0 1 1 *", Command: "echo failing >&2; exit 3"},
		{ID: 3, Schedule: "0 0 1 1 *", Command: "sleep 30", Timeout: 2},
		{ID: 4, Schedule: "0 0 1 1 *", Command: "sleep 5; echo slept"},
		{ID: 5, Schedule: "0 0 1 1 *", Command: big},
		{ID: 6, Schedule: "* * * * *", Command: "echo scheduled", Enabled: true},
	}
	spec := Spec{Domain: rhDomain, User: rhOwner, PHP: php, Tasks: tasks}
	if res, err := m.Apply(ctx, spec); err != nil || res.Enabled != 1 {
		t.Fatalf("apply %+v %v", res, err)
	}
	fi, err := os.Stat(CronFile(rhDomain))
	if err != nil || fi.Mode().Perm() != 0o644 || fi.Sys().(*syscall.Stat_t).Uid != 0 {
		t.Fatalf("crontab %v %v", fi, err)
	}
	if fi, err := os.Stat(StateFile(rhDomain)); err != nil || fi.Mode().Perm() != 0o640 {
		t.Fatalf("state %v %v", fi, err)
	}

	// A manual run, as the site's user, in the site folder.
	r := runNow(t, m, 1)
	lines := strings.Split(strings.TrimSpace(r.Output), "\n")
	if r.ExitCode != 0 || r.Trigger != TriggerManual || len(lines) < 3 || lines[0] != rhOwner || lines[1] != siteFolder || lines[2] != rhDomain {
		t.Fatalf("record %+v", r)
	}
	if _, err := os.Stat("/usr/bin/php" + php); err == nil && (len(lines) < 4 || lines[3] != ShimDir(php)+"/php") {
		t.Errorf("php is %q, not the site's version", lines[3:])
	}
	assertOwned(t, TaskDir(rhOwner, rhDomain, 1)+"/runs", rhOwner)

	if r := runNow(t, m, 2); r.ExitCode != 3 || r.Output != "failing\n" {
		t.Fatalf("failing run %+v", r)
	}

	start := time.Now()
	if r := runNow(t, m, 3); !r.TimedOut || r.ExitCode == 0 {
		t.Fatalf("timed out run %+v", r)
	}
	if took := time.Since(start); took > 20*time.Second {
		t.Fatalf("the 2 s timeout took %s", took)
	}

	// While a run goes, another is not started: the agent says so, and
	// a second cron-exec (as cron would start it) records a skipped run.
	if res, err := m.Run(ctx, RunSpec{Domain: rhDomain, ID: 4}); err != nil || !res.Started {
		t.Fatalf("run %+v %v", res, err)
	}
	waitFor(t, 20*time.Second, "the run to start", func() bool {
		res, err := m.Runs(ctx, RunsSpec{Domain: rhDomain, ID: 4})
		return err == nil && res.Running
	})
	if res, err := m.Run(ctx, RunSpec{Domain: rhDomain, ID: 4}); err != nil || res.Started || !res.Running {
		t.Fatalf("second run %+v %v", res, err)
	}
	if out, err := exec.Command("runuser", "-u", rhOwner, "--", bin, "cron-exec", "--manual", rhDomain, "4").CombinedOutput(); err != nil {
		t.Fatalf("cron-exec: %v %s", err, out)
	}
	runs := waitRuns(t, m, 4, 2)
	// Newest first: the skipped run started last.
	if !runs[0].Skipped || runs[1].Skipped || runs[1].Output != "slept\n" {
		t.Fatalf("overlapping runs %+v", runs)
	}

	if r := runNow(t, m, 5); !r.Truncated || len(r.Output) != MaxOutput || !strings.HasSuffix(r.Output, "END\n") {
		t.Fatalf("big output: truncated %v, %d bytes", r.Truncated, len(r.Output))
	}

	// The cron daemon runs the enabled task within two minutes.
	waitFor(t, 130*time.Second, "a scheduled run", func() bool {
		res, err := m.Runs(ctx, RunsSpec{Domain: rhDomain, ID: 6})
		for _, r := range res.Runs {
			if err == nil && r.Trigger == TriggerSchedule && r.Output == "scheduled\n" && r.ExitCode == 0 {
				return true
			}
		}
		return false
	})

	// Nothing enabled (a suspended site): the crontab goes.
	tasks[5].Enabled = false
	if _, err := m.Apply(ctx, spec); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(CronFile(rhDomain)); !os.IsNotExist(err) {
		t.Fatalf("crontab left: %v", err)
	}
	if res, err := m.Remove(ctx, rhDomain); err != nil || !res["removed"] {
		t.Fatalf("remove %v %v", res, err)
	}
	for _, p := range []string{StateFile(rhDomain), SitesDir(rhOwner, rhDomain)} {
		if _, err := os.Stat(p); !os.IsNotExist(err) {
			t.Errorf("%s left: %v", p, err)
		}
	}
}

// runNow runs a task by hand and returns its record.
func runNow(t *testing.T, m *Manager, id int) Record {
	t.Helper()
	before := len(waitRuns(t, m, id, 0))
	if res, err := m.Run(context.Background(), RunSpec{Domain: rhDomain, ID: id}); err != nil || !res.Started {
		t.Fatalf("run %d: %+v %v", id, res, err)
	}
	return waitRuns(t, m, id, before+1)[0]
}

// waitRuns waits until a task has n records and no run going.
func waitRuns(t *testing.T, m *Manager, id, n int) []Record {
	t.Helper()
	var res Runs
	waitFor(t, 60*time.Second, "the run's record", func() bool {
		var err error
		res, err = m.Runs(context.Background(), RunsSpec{Domain: rhDomain, ID: id})
		return err == nil && len(res.Runs) >= n && !res.Running
	})
	return res.Runs
}

func waitFor(t *testing.T, d time.Duration, what string, f func() bool) {
	t.Helper()
	for end := time.Now().Add(d); time.Now().Before(end); time.Sleep(250 * time.Millisecond) {
		if f() {
			return
		}
	}
	t.Fatalf("timed out waiting for %s", what)
}

func assertOwned(t *testing.T, p, user string) {
	t.Helper()
	u, err := host.System{}.LookupUser(user)
	if err != nil {
		t.Fatal(err)
	}
	fi, err := os.Stat(p)
	if err != nil {
		t.Fatal(err)
	}
	if st := fi.Sys().(*syscall.Stat_t); int(st.Uid) != u.UID || fi.Mode().Perm() != 0o700 {
		t.Fatalf("%s: uid %d mode %v, want %s's and 0700", p, st.Uid, fi.Mode(), user)
	}
}
