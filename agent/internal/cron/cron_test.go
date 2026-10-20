package cron

import (
	"context"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
	"time"

	"github.com/winchester37/servik/agent/internal/host"
)

const (
	domain = "kahve-shop.com"
	user   = "kahve"
	exe    = "/opt/servik/current/bin/servik-agent"
)

func newServer(t *testing.T) (*Manager, *host.Simulator) {
	t.Helper()
	sim, err := host.NewSimulator(t.TempDir(), "8.3")
	if err != nil {
		t.Fatal(err)
	}
	if _, err := sim.Run(context.Background(), host.Command{Name: "useradd", Args: []string{"--home-dir", "/home/" + user, "--shell", "/usr/sbin/nologin", "--user-group", user}}); err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(sim.Path(siteDir(user, domain)+"/public_html"), 0o755); err != nil {
		t.Fatal(err)
	}
	_ = os.MkdirAll(sim.Path("/usr/bin"), 0o755)
	_ = os.WriteFile(sim.Path("/usr/bin/php8.3"), nil, 0o755)
	m := NewManager(sim)
	m.Exe = exe
	return m, sim
}

func tasks() []Task {
	return []Task{
		{ID: 3, Schedule: "*/5  *  * * *", Command: "php artisan schedule:run", Enabled: true},
		{ID: 7, Schedule: "30 3 * * MON-fri", Command: "curl -s https://kahve-shop.com/cron.php?key=s3cret", Enabled: true, Timeout: 120},
		{ID: 9, Schedule: "0 0 1 jan *", Command: "echo off", Enabled: false},
	}
}

func TestNormalizeSchedule(t *testing.T) {
	for in, want := range map[string]string{
		"* * * * *":                  "* * * * *",
		" */5\t* *  * * ":            "*/5 * * * *",
		"0,15,30,45 0-23/2 1-31 * *": "0,15,30,45 0-23/2 1-31 * *",
		"30 3 * JAN-Mar sun,SAT":     "30 3 * jan-mar sun,sat",
		"59 23 31 12 7":              "59 23 31 12 7",
		"00 05 01 01 0":              "00 05 01 01 0",
		"0 0 * * mon-fri":            "0 0 * * mon-fri",
	} {
		got, err := NormalizeSchedule(in)
		if err != nil || got != want {
			t.Errorf("%q: %q %v, want %q", in, got, err, want)
		}
	}
	for _, in := range []string{
		"", "* * * *", "* * * * * *", "60 * * * *", "* 24 * * *", "* * 0 * *", "* * 32 * *", "* * * 13 *",
		"* * * 0 *", "* * * * 8", "*/0 * * * *", "5/10 * * * *", "10-5 * * * *", "1,,2 * * * *", "a * * * *",
		"* * * * mon-", "* * * jan/2 *", "@daily", "-1 * * * *", "* * * * monday", "*/100 * * * *", "1-2-3 * * * *",
		"% * * * *", "1.5 * * * *", "* * * * jan", "*/ * * * *",
	} {
		var v *ValidationError
		if _, err := NormalizeSchedule(in); !errors.As(err, &v) {
			t.Errorf("%q accepted: %v", in, err)
		}
	}
}

func TestApplyWritesStateCrontabAndPHP(t *testing.T) {
	m, sim := newServer(t)
	ctx := context.Background()
	res, err := m.Apply(ctx, Spec{Domain: domain, User: user, PHP: "8.3", Tasks: tasks()})
	if err != nil {
		t.Fatal(err)
	}
	if res != (Applied{Tasks: 3, Enabled: 2}) {
		t.Fatalf("result %+v", res)
	}
	if CronFile(domain) != "/etc/cron.d/servik-kahve-shop_com" {
		t.Fatal(CronFile(domain))
	}
	crontab, err := os.ReadFile(sim.Path(CronFile(domain)))
	if err != nil {
		t.Fatal(err)
	}
	want := `# Managed by Servik. Manual changes are overwritten.
# Scheduled tasks of kahve-shop.com: each runs as kahve through servik-agent cron-exec.
SHELL=/bin/sh
MAILTO=""

*/5 * * * * kahve /opt/servik/current/bin/servik-agent cron-exec kahve-shop.com 3
30 3 * * mon-fri kahve /opt/servik/current/bin/servik-agent cron-exec kahve-shop.com 7
`
	if string(crontab) != want {
		t.Fatalf("crontab:\n%s\nwant:\n%s", crontab, want)
	}
	for abs, mode := range map[string]os.FileMode{CronFile(domain): 0o644, StateFile(domain): 0o640, StateDir: 0o711 | os.ModeDir} {
		fi, err := os.Stat(sim.Path(abs))
		if err != nil || fi.Mode() != mode {
			t.Errorf("%s: mode %v, want %v (%v)", abs, fi.Mode(), mode, err)
		}
	}
	st, err := m.load(domain)
	if err != nil || st.User != user || st.PHP != "8.3" || len(st.Tasks) != 3 || st.Tasks[0].Schedule != "*/5 * * * *" || st.Tasks[0].Timeout != DefaultTimeout || st.Tasks[1].Timeout != 120 {
		t.Fatalf("state %+v %v", st, err)
	}
	if target, err := os.Readlink(sim.Path(ShimDir("8.3") + "/php")); err != nil || target != "/usr/bin/php8.3" {
		t.Fatalf("php shim %q %v", target, err)
	}
	// Seeding is for dry runs only.
	if _, err := os.Stat(sim.Path(TaskDir(user, domain, 3))); !errors.Is(err, os.ErrNotExist) {
		t.Fatal("records seeded outside a dry run")
	}

	// A suspended site: every task off, the crontab goes, the state stays.
	off := tasks()
	for i := range off {
		off[i].Enabled = false
	}
	if res, err := m.Apply(ctx, Spec{Domain: domain, User: user, PHP: "8.3", Tasks: off}); err != nil || res.Enabled != 0 {
		t.Fatalf("suspend %+v %v", res, err)
	}
	if _, err := os.Stat(sim.Path(CronFile(domain))); !errors.Is(err, os.ErrNotExist) {
		t.Fatal("crontab left with nothing enabled")
	}
	if st, err := m.load(domain); err != nil || len(st.Tasks) != 3 {
		t.Fatalf("state after suspending %+v %v", st, err)
	}

	// Another user's site of the same name is refused.
	if _, err := sim.Run(ctx, host.Command{Name: "useradd", Args: []string{"--home-dir", "/home/other", "--user-group", "other"}}); err != nil {
		t.Fatal(err)
	}
	_ = os.MkdirAll(sim.Path(siteDir("other", domain)), 0o755)
	var v *ValidationError
	if _, err := m.Apply(ctx, Spec{Domain: domain, User: "other", Tasks: tasks()}); !errors.As(err, &v) {
		t.Fatalf("taken over by another user: %v", err)
	}
}

func TestApplyValidation(t *testing.T) {
	m, sim := newServer(t)
	ctx := context.Background()
	long := strings.Repeat("x", MaxCommand+1)
	many := make([]Task, MaxTasks+1)
	for i := range many {
		many[i] = Task{ID: i + 1, Schedule: "* * * * *", Command: "true"}
	}
	for name, spec := range map[string]Spec{
		"domain":         {Domain: "kahve", User: user},
		"user":           {Domain: domain, User: "root"},
		"no such user":   {Domain: domain, User: "nobody2"},
		"php":            {Domain: domain, User: user, PHP: "eight"},
		"no site folder": {Domain: "baska.com", User: user},
		"id":             {Domain: domain, User: user, Tasks: []Task{{ID: 0, Schedule: "* * * * *", Command: "true"}}},
		"twice":          {Domain: domain, User: user, Tasks: []Task{{ID: 1, Schedule: "* * * * *", Command: "true"}, {ID: 1, Schedule: "* * * * *", Command: "true"}}},
		"schedule":       {Domain: domain, User: user, Tasks: []Task{{ID: 1, Schedule: "* * *", Command: "true"}}},
		"empty command":  {Domain: domain, User: user, Tasks: []Task{{ID: 1, Schedule: "* * * * *", Command: "  "}}},
		"long command":   {Domain: domain, User: user, Tasks: []Task{{ID: 1, Schedule: "* * * * *", Command: long}}},
		"newline":        {Domain: domain, User: user, Tasks: []Task{{ID: 1, Schedule: "* * * * *", Command: "true\n* * * * * root id"}}},
		"nul":            {Domain: domain, User: user, Tasks: []Task{{ID: 1, Schedule: "* * * * *", Command: "true\x00"}}},
		"timeout":        {Domain: domain, User: user, Tasks: []Task{{ID: 1, Schedule: "* * * * *", Command: "true", Timeout: MaxTimeout + 1}}},
		"negative":       {Domain: domain, User: user, Tasks: []Task{{ID: 1, Schedule: "* * * * *", Command: "true", Timeout: -1}}},
		"too many":       {Domain: domain, User: user, Tasks: many},
	} {
		var v *ValidationError
		if _, err := m.Apply(ctx, spec); !errors.As(err, &v) {
			t.Errorf("%s: %v", name, err)
		}
	}
	if _, err := os.Stat(sim.Path(StateDir)); !errors.Is(err, os.ErrNotExist) {
		t.Fatal("a refused spec left state behind")
	}

	// An agent path that cannot go into a crontab fails and rolls back.
	m.Exe = "/tmp/my agent"
	var op *OperationError
	if _, err := m.Apply(ctx, Spec{Domain: domain, User: user, Tasks: tasks()}); !errors.As(err, &op) {
		t.Fatalf("err = %v", err)
	}
	if _, err := os.Stat(sim.Path(StateFile(domain))); !errors.Is(err, os.ErrNotExist) {
		t.Fatal("state left after a failed apply")
	}
}

func TestRemoveAndReconcile(t *testing.T) {
	m, sim := newServer(t)
	ctx := context.Background()
	if _, err := m.Apply(ctx, Spec{Domain: domain, User: user, Tasks: tasks()}); err != nil {
		t.Fatal(err)
	}

	// After an update the agent lives elsewhere: Reconcile rewrites the
	// lines, and removes Servik's crontabs of sites without state.
	m.Exe = "/opt/servik/releases/0.2.0/bin/servik-agent"
	orphan := CronDir + "/servik-gone_com"
	foreign := CronDir + "/servik-handmade"
	_ = os.WriteFile(sim.Path(orphan), []byte("# Managed by Servik.\n* * * * * gone /x cron-exec gone.com 1\n"), 0o644)
	_ = os.WriteFile(sim.Path(foreign), []byte("* * * * * root true\n"), 0o644)
	if err := m.Reconcile(ctx); err != nil {
		t.Fatal(err)
	}
	crontab, _ := os.ReadFile(sim.Path(CronFile(domain)))
	if !strings.Contains(string(crontab), "kahve /opt/servik/releases/0.2.0/bin/servik-agent cron-exec kahve-shop.com 3\n") {
		t.Fatalf("crontab not rewritten:\n%s", crontab)
	}
	if _, err := os.Stat(sim.Path(orphan)); !errors.Is(err, os.ErrNotExist) {
		t.Fatal("orphan crontab kept")
	}
	if _, err := os.Stat(sim.Path(foreign)); err != nil {
		t.Fatal("a crontab Servik did not write was removed")
	}

	// Dropping a task drops its history.
	if _, err := m.Run(ctx, RunSpec{Domain: domain, ID: 7}); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Apply(ctx, Spec{Domain: domain, User: user, Tasks: tasks()[:1]}); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(sim.Path(TaskDir(user, domain, 7))); !errors.Is(err, os.ErrNotExist) {
		t.Fatal("history of a removed task kept")
	}

	if _, err := m.Run(ctx, RunSpec{Domain: domain, ID: 3}); err != nil {
		t.Fatal(err)
	}
	for i := 0; i < 2; i++ {
		res, err := m.Remove(ctx, domain)
		if err != nil || !res["removed"] {
			t.Fatalf("remove %v %v", res, err)
		}
	}
	for _, abs := range []string{CronFile(domain), StateFile(domain), SitesDir(user, domain)} {
		if _, err := os.Stat(sim.Path(abs)); !errors.Is(err, os.ErrNotExist) {
			t.Errorf("%s left after remove", abs)
		}
	}
	var v *ValidationError
	if _, err := m.Runs(ctx, RunsSpec{Domain: domain, ID: 3}); !errors.As(err, &v) {
		t.Fatalf("runs of a removed site: %v", err)
	}
}

func writeRecord(t *testing.T, sim *host.Simulator, id int, name string, rec any) {
	t.Helper()
	dir := sim.Path(TaskDir(user, domain, id) + "/runs")
	if err := os.MkdirAll(dir, 0o700); err != nil {
		t.Fatal(err)
	}
	var data []byte
	switch r := rec.(type) {
	case string:
		data = []byte(r)
	default:
		data, _ = json.Marshal(r)
	}
	if err := os.WriteFile(filepath.Join(dir, name), data, 0o600); err != nil {
		t.Fatal(err)
	}
}

func TestRunsReadsSafely(t *testing.T) {
	m, sim := newServer(t)
	ctx := context.Background()
	if _, err := m.Apply(ctx, Spec{Domain: domain, User: user, Tasks: tasks()}); err != nil {
		t.Fatal(err)
	}
	if res, err := m.Runs(ctx, RunsSpec{Domain: domain, ID: 3}); err != nil || len(res.Runs) != 0 || res.Running || res.Runs == nil {
		t.Fatalf("no runs yet: %+v %v", res, err)
	}
	base := time.Date(2026, 10, 19, 12, 0, 0, 0, time.UTC)
	for i := 0; i < 25; i++ {
		at := base.Add(time.Duration(i) * time.Minute)
		writeRecord(t, sim, 3, strconv.FormatInt(at.UnixNano(), 10)+".json", Record{StartedAt: at, FinishedAt: at.Add(time.Second), DurationMS: 1000, ExitCode: i % 2, Trigger: TriggerSchedule})
	}
	newest := base.Add(30 * time.Minute)
	// Junk the user may leave: not JSON, a link to a root-only file, a
	// file that is too big, a name that is not a record.
	writeRecord(t, sim, 3, strconv.FormatInt(newest.UnixNano(), 10)+".json", "{not json")
	secret := sim.Path("/etc/shadow-test")
	_ = os.WriteFile(secret, []byte(`{"started_at":"2026-10-19T13:00:00Z","output":"root:secret"}`), 0o600)
	_ = os.Symlink(secret, sim.Path(TaskDir(user, domain, 3)+"/runs/"+strconv.FormatInt(newest.Add(time.Minute).UnixNano(), 10)+".json"))
	writeRecord(t, sim, 3, strconv.FormatInt(newest.Add(2*time.Minute).UnixNano(), 10)+".json", `{"started_at":"2026-10-19T13:00:00Z","output":"`+strings.Repeat("a", maxRecord)+`"}`)
	writeRecord(t, sim, 3, "notes.json", Record{StartedAt: newest.Add(time.Hour)})

	res, err := m.Runs(ctx, RunsSpec{Domain: domain, ID: 3, Limit: 5})
	if err != nil || len(res.Runs) != 5 {
		t.Fatalf("runs %+v %v", res, err)
	}
	for i, r := range res.Runs {
		want := base.Add(time.Duration(24-i) * time.Minute)
		if !r.StartedAt.Equal(want) || r.ID != strconv.FormatInt(want.UnixNano(), 10) || strings.Contains(r.Output, "secret") {
			t.Errorf("run %d: %+v, want start %v", i, r, want)
		}
	}
	if res, _ := m.Runs(ctx, RunsSpec{Domain: domain, ID: 3}); len(res.Runs) != RunsKept {
		t.Fatalf("default limit gave %d", len(res.Runs))
	}
	var v *ValidationError
	for _, spec := range []RunsSpec{{Domain: domain, ID: 3, Limit: 21}, {Domain: domain, ID: 0}, {Domain: "yok.com", ID: 3}, {Domain: "..", ID: 3}} {
		if _, err := m.Runs(ctx, spec); !errors.As(err, &v) {
			t.Errorf("%+v: %v", spec, err)
		}
	}

	// A folder swapped for a link is not followed.
	elsewhere := t.TempDir()
	writeRecord(t, sim, 7, "1.json", Record{StartedAt: base})
	_ = os.Rename(sim.Path(TaskDir(user, domain, 7)+"/runs/1.json"), filepath.Join(elsewhere, "1.json"))
	_ = os.RemoveAll(sim.Path(TaskDir(user, domain, 7) + "/runs"))
	if err := os.Symlink(elsewhere, sim.Path(TaskDir(user, domain, 7)+"/runs")); err != nil {
		t.Fatal(err)
	}
	if res, err := m.Runs(ctx, RunsSpec{Domain: domain, ID: 7}); err != nil || len(res.Runs) != 0 {
		t.Fatalf("followed a linked folder: %+v %v", res, err)
	}
	_ = os.RemoveAll(sim.Path(SitesDir(user, domain)))
	if err := os.Symlink(elsewhere, sim.Path(SitesDir(user, domain))); err != nil {
		t.Fatal(err)
	}
	if res, err := m.Runs(ctx, RunsSpec{Domain: domain, ID: 7}); err != nil || len(res.Runs) != 0 {
		t.Fatalf("followed a linked site folder: %+v %v", res, err)
	}
}

func TestRunInDryRun(t *testing.T) {
	m, sim := newServer(t)
	ctx := context.Background()
	if _, err := m.Apply(ctx, Spec{Domain: domain, User: user, Tasks: tasks()}); err != nil {
		t.Fatal(err)
	}
	var v *ValidationError
	if _, err := m.Run(ctx, RunSpec{Domain: domain, ID: 42}); !errors.As(err, &v) {
		t.Fatalf("unknown task: %v", err)
	}
	// Disabled tasks can be run by hand.
	res, err := m.Run(ctx, RunSpec{Domain: domain, ID: 9})
	if err != nil || !res.Started || res.Running {
		t.Fatalf("run %+v %v", res, err)
	}
	runs, err := m.Runs(ctx, RunsSpec{Domain: domain, ID: 9})
	if err != nil || len(runs.Runs) != 1 || runs.Runs[0].Trigger != TriggerManual || runs.Runs[0].Output != SimulatedOutput || runs.Runs[0].ExitCode != 0 {
		t.Fatalf("runs %+v %v", runs, err)
	}

	// While a run goes (its process is alive), no other starts.
	marker := `{"pid":` + strconv.Itoa(os.Getpid()) + `,"started_at":"2026-10-19T12:00:00Z","trigger":"schedule"}`
	_ = os.WriteFile(sim.Path(TaskDir(user, domain, 9)+"/running.json"), []byte(marker), 0o600)
	if res, err := m.Run(ctx, RunSpec{Domain: domain, ID: 9}); err != nil || res.Started || !res.Running {
		t.Fatalf("second run %+v %v", res, err)
	}
	runs, _ = m.Runs(ctx, RunsSpec{Domain: domain, ID: 9})
	if !runs.Running || runs.RunningSince == nil || !runs.RunningSince.Equal(time.Date(2026, 10, 19, 12, 0, 0, 0, time.UTC)) {
		t.Fatalf("running %+v", runs)
	}
	// A marker left by a killed run does not count.
	_ = os.WriteFile(sim.Path(TaskDir(user, domain, 9)+"/running.json"), []byte(`{"pid":2147483000,"started_at":"2026-10-19T12:00:00Z"}`), 0o600)
	if res, err := m.Run(ctx, RunSpec{Domain: domain, ID: 9}); err != nil || !res.Started {
		t.Fatalf("stale marker %+v %v", res, err)
	}
	if runs, _ := m.Runs(ctx, RunsSpec{Domain: domain, ID: 9}); runs.Running || len(runs.Runs) != 2 {
		t.Fatalf("after a stale marker %+v", runs)
	}
}

func TestDemoSeedsHistory(t *testing.T) {
	m, _ := newServer(t)
	m.Demo = true
	now := time.Date(2026, 10, 19, 12, 30, 0, 0, time.UTC)
	m.now = func() time.Time { return now }
	ctx := context.Background()
	if _, err := m.Apply(ctx, Spec{Domain: domain, User: user, Tasks: tasks()}); err != nil {
		t.Fatal(err)
	}
	runs, err := m.Runs(ctx, RunsSpec{Domain: domain, ID: 3})
	if err != nil || len(runs.Runs) != 3 {
		t.Fatalf("seeded %+v %v", runs, err)
	}
	failed := 0
	for i, r := range runs.Runs {
		if r.Trigger != TriggerSchedule || r.StartedAt.After(now) || now.Sub(r.StartedAt) < time.Duration(i+1)*time.Hour-time.Minute {
			t.Errorf("record %d: %+v", i, r)
		}
		if r.ExitCode == 1 && strings.HasPrefix(r.Output, "PHP Fatal error:") {
			failed++
		}
	}
	if failed != 1 {
		t.Fatalf("%d failed runs", failed)
	}
	// Applying again adds nothing to tasks that have a history.
	if _, err := m.Apply(ctx, Spec{Domain: domain, User: user, Tasks: tasks()}); err != nil {
		t.Fatal(err)
	}
	if runs, _ := m.Runs(ctx, RunsSpec{Domain: domain, ID: 3}); len(runs.Runs) != 3 {
		t.Fatalf("seeded twice: %d", len(runs.Runs))
	}
}
