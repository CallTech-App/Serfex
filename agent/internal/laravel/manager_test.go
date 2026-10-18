package laravel

import (
	"context"
	"encoding/json"
	"errors"
	"os"
	"strings"
	"testing"

	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/site"
)

var target = Target{Domain: "api.hizlikargo.app", User: "hizlikargo", PHPVersion: "8.4"}

const appDir = "/home/hizlikargo/web/api.hizlikargo.app/laravel"

func newTest(t *testing.T) (*Manager, *host.Simulator) {
	t.Helper()
	sim, err := host.NewSimulator(t.TempDir(), "8.3", "8.4")
	if err != nil {
		t.Fatal(err)
	}
	if _, err := site.NewManager(sim, "www-data").Apply(context.Background(), site.Spec{Domain: target.Domain, User: target.User, PHPVersion: "8.4"}); err != nil {
		t.Fatal(err)
	}
	return NewManager(sim), sim
}

func read(t *testing.T, sim *host.Simulator, abs string) string {
	t.Helper()
	b, err := os.ReadFile(sim.Path(abs))
	if err != nil {
		t.Fatal(err)
	}
	return string(b)
}

func exists(sim *host.Simulator, abs string) bool {
	_, err := os.Lstat(sim.Path(abs))
	return err == nil
}

func newApp(release string) DeploySpec {
	return DeploySpec{
		Target:  target,
		Run:     "deploy-" + release,
		Release: release,
		Source:  Source{Type: "create", Package: "laravel/laravel"},
		Build:   Build{Frontend: true, Migrate: true},
		Env: map[string]string{
			"APP_ENV": "production", "APP_DEBUG": "false", "APP_KEY": "base64:c2VydmlrLXRlc3Qta2V5LXRoaXJ0eS10d28tYnl0ZXM=",
			"APP_URL": "https://api.hizlikargo.app", "DB_CONNECTION": "sqlite", "DB_DATABASE": appDir + "/shared/database/database.sqlite",
		},
		Health:   &HealthSpec{Path: "/up"},
		Rollback: true,
	}
}

func gitDeploy(release string) DeploySpec {
	s := newApp(release)
	s.Source = Source{Type: "git", Repository: "https://github.com/hizlikargo/api.git", Ref: "main"}
	s.Env = nil
	return s
}

func TestDeployCreatesTheFirstRelease(t *testing.T) {
	m, sim := newTest(t)
	res, err := m.Deploy(context.Background(), newApp("1"))
	if err != nil {
		t.Fatal(err)
	}
	if res.Current != "1" || res.Laravel != "13.6.0" || res.PHPRequirement != "^8.3" || res.PackageManager != "npm" || !res.Health.OK || res.RolledBack {
		t.Fatalf("unexpected result %+v", res)
	}
	if target, _ := os.Readlink(sim.Path(appDir + "/current")); target != "releases/1" {
		t.Fatalf("current -> %q", target)
	}
	for link, want := range map[string]string{"/releases/1/storage": "../../shared/storage", "/releases/1/.env": "../../shared/.env"} {
		if got, _ := os.Readlink(sim.Path(appDir + link)); got != want {
			t.Errorf("%s -> %q, want %q", link, got, want)
		}
	}
	env := read(t, sim, appDir+"/shared/.env")
	for _, want := range []string{"APP_ENV=production\n", "APP_DEBUG=false\n", "DB_CONNECTION=sqlite\n", "DB_DATABASE=" + appDir + "/shared/database/database.sqlite\n", "QUEUE_CONNECTION=database\n"} {
		if !strings.Contains(env, want) {
			t.Errorf(".env lacks %q:\n%s", want, env)
		}
	}
	if !exists(sim, appDir+"/shared/database/database.sqlite") || !exists(sim, appDir+"/shared/storage/logs/laravel.log") {
		t.Error("shared database or storage missing")
	}
	// Tools were set up first.
	for _, want := range []string{"apt-get install -y -q --no-install-recommends php8.4-cli", "/opt/servik/composer/composer.phar"} {
		if !strings.Contains(commands(sim), want) {
			t.Errorf("no command with %q", want)
		}
	}
	if !exists(sim, "/opt/servik/php/8.4/bin/composer") || !exists(sim, "/opt/servik/node/current") {
		t.Error("tool shims missing")
	}
	// The build ran as the site's account, in order.
	order := []string{"create-project --no-install", "composer.phar install --no-dev", "npm ci", "npm run build", "artisan migrate --force", "artisan optimize", "systemctl reload php8.4-fpm"}
	all := commands(sim)
	rest := all[strings.Index(all, "create-project"):]
	for _, step := range order {
		i := strings.Index(rest, step)
		if i < 0 {
			t.Fatalf("step %q missing or out of order in:\n%s", step, all)
		}
		rest = rest[i:]
	}
	for _, c := range sim.Commands() {
		if strings.Contains(c.String(), "artisan") && !strings.HasPrefix(c.String(), "runuser -u hizlikargo -- env -C "+appDir+"/releases/1 ") {
			t.Errorf("artisan not run as the site user in the release: %s", c)
		}
	}
	log, err := m.ReadRun(target.Domain, "deploy-1", 0)
	if err != nil {
		t.Fatal(err)
	}
	for _, want := range []string{"▸ Kurulum başladı · laravel/laravel", "$ php artisan migrate --force", "Sağlık kontrolü: GET /up → 200", "✓ releases/1 yayında."} {
		if !strings.Contains(log.Data, want) {
			t.Errorf("log lacks %q:\n%s", want, log.Data)
		}
	}
}

func commands(sim *host.Simulator) string {
	var b strings.Builder
	for _, c := range sim.Commands() {
		b.WriteString(c.String() + "\n")
	}
	return b.String()
}

func TestFailedHealthCheckSwitchesBack(t *testing.T) {
	m, sim := newTest(t)
	ctx := context.Background()
	if _, err := m.Deploy(ctx, newApp("1")); err != nil {
		t.Fatal(err)
	}
	m.Health = func(context.Context, string, string) HealthResult { return HealthResult{Status: 500} }
	res, err := m.Deploy(ctx, gitDeploy("2"))
	if err != nil {
		t.Fatal(err)
	}
	if !res.RolledBack || res.Current != "1" || res.Previous != "1" || res.Problem == "" || res.Commit == "" {
		t.Fatalf("unexpected result %+v", res)
	}
	if exists(sim, appDir+"/releases/2") {
		t.Error("the failed release was kept")
	}
	// shared/.env was not touched by the second deploy.
	if !strings.Contains(read(t, sim, appDir+"/shared/.env"), "APP_ENV=production") {
		t.Error("shared .env changed")
	}
	log, _ := m.ReadRun(target.Domain, "deploy-2", 0)
	if !strings.Contains(log.Data, "Sürüm geri alındı: current → releases/1") {
		t.Errorf("log lacks the rollback:\n%s", log.Data)
	}

	// Without automatic rollback the new release stays live.
	spec := gitDeploy("3")
	spec.Rollback = false
	res, err = m.Deploy(ctx, spec)
	if err != nil || res.RolledBack || res.Current != "3" || res.Problem == "" {
		t.Fatalf("res=%+v err=%v", res, err)
	}
}

func TestFailedBuildLeavesTheLiveRelease(t *testing.T) {
	m, sim := newTest(t)
	ctx := context.Background()
	if _, err := m.Deploy(ctx, newApp("1")); err != nil {
		t.Fatal(err)
	}
	sim.Fail = func(c host.Command) error {
		if strings.Contains(c.String(), "artisan migrate") {
			return errors.New("SQLSTATE[HY000]: no such table")
		}
		return nil
	}
	_, err := m.Deploy(ctx, gitDeploy("2"))
	var op *OperationError
	if !errors.As(err, &op) || !strings.Contains(err.Error(), "php artisan migrate --force failed with exit code 1") {
		t.Fatalf("err = %v", err)
	}
	if m.currentRelease(target) != "1" || exists(sim, appDir+"/releases/2") {
		t.Fatal("the failed release went live or stayed on disk")
	}
	log, _ := m.ReadRun(target.Domain, "deploy-2", 0)
	if !strings.Contains(log.Data, "no such table") || !strings.Contains(log.Data, "✗ php artisan migrate --force failed") {
		t.Errorf("log lacks the failure:\n%s", log.Data)
	}
}

func TestOldReleasesArePruned(t *testing.T) {
	m, _ := newTest(t)
	ctx := context.Background()
	if _, err := m.Deploy(ctx, newApp("1")); err != nil {
		t.Fatal(err)
	}
	for _, r := range []string{"2", "3", "4"} {
		spec := gitDeploy(r)
		spec.Keep = 2
		if _, err := m.Deploy(ctx, spec); err != nil {
			t.Fatal(err)
		}
	}
	var names []string
	for _, r := range m.releases(target) {
		names = append(names, r.Name)
	}
	if strings.Join(names, ",") != "4,3" {
		t.Fatalf("releases = %v", names)
	}

	res, err := m.Rollback(ctx, RollbackSpec{Target: target, Release: "3"})
	if err != nil || res.Previous != "4" || res.Current != "3" {
		t.Fatalf("rollback: %+v %v", res, err)
	}
	if _, err := m.Rollback(ctx, RollbackSpec{Target: target, Release: "1"}); err == nil {
		t.Fatal("rolled back to a pruned release")
	}
	if _, err := m.Deploy(ctx, gitDeploy("3")); err == nil {
		t.Fatal("deployed over the live release")
	}
}

func TestDeployValidation(t *testing.T) {
	m, _ := newTest(t)
	for name, mutate := range map[string]func(*DeploySpec){
		"repository": func(s *DeploySpec) { s.Source.Repository = "file:///etc" },
		"ref":        func(s *DeploySpec) { s.Source.Ref = "--upload-pack=sh" },
		"release":    func(s *DeploySpec) { s.Release = "../x" },
		"seed":       func(s *DeploySpec) { s.SeedFrom = "../../other" },
		"health":     func(s *DeploySpec) { s.Health = &HealthSpec{Path: "up"} },
		"pm":         func(s *DeploySpec) { s.Build.PackageManager = "make" },
	} {
		spec := gitDeploy("2")
		mutate(&spec)
		var v *ValidationError
		if _, err := m.Deploy(context.Background(), spec); !errors.As(err, &v) {
			t.Errorf("%s: err = %v", name, err)
		}
	}
}

func TestExistingApplicationMovesToReleases(t *testing.T) {
	m, sim := newTest(t)
	ctx := context.Background()
	// An application uploaded into public_html by hand.
	old := "/home/hizlikargo/web/api.hizlikargo.app/public_html"
	if _, err := sim.Run(ctx, host.Command{Name: "git", Args: []string{"clone", "x", old}}); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(sim.Path(old+"/.env"), []byte("APP_NAME=Kargo\nAPP_KEY=base64:eGVzdA==\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	found, err := m.Scan(ctx, []ScanSite{{Domain: target.Domain, User: target.User}})
	if err != nil || len(found) != 1 || found[0].Path != "public_html" || found[0].Laravel != "13.6.0" {
		t.Fatalf("scan: %+v %v", found, err)
	}
	spec := gitDeploy("1")
	spec.SeedFrom = "public_html"
	if _, err := m.Deploy(ctx, spec); err != nil {
		t.Fatal(err)
	}
	if env := read(t, sim, appDir+"/shared/.env"); !strings.Contains(env, "APP_NAME=Kargo") {
		t.Fatalf("shared .env not seeded: %s", env)
	}
	if !exists(sim, appDir+"/shared/storage/logs/laravel.log") || !exists(sim, old+"/storage/logs/laravel.log") {
		t.Fatal("storage was not copied (or the original was moved)")
	}
}

func TestEnvSaveRebuildsTheConfigCache(t *testing.T) {
	m, sim := newTest(t)
	ctx := context.Background()
	if _, err := m.Deploy(ctx, newApp("1")); err != nil {
		t.Fatal(err)
	}
	app := App{Target: target, Path: CurrentPath}
	files, err := m.GetEnv(ctx, app)
	if err != nil || files.Env == nil || files.Example == nil || !strings.Contains(*files.Env, "APP_ENV=production") {
		t.Fatalf("get: %+v %v", files, err)
	}
	if err := os.WriteFile(sim.Path(appDir+"/releases/1/bootstrap/cache/config.php"), []byte("<?php return [];"), 0o644); err != nil {
		t.Fatal(err)
	}
	res, err := m.SetEnv(ctx, EnvSpec{App: app, Content: "APP_NAME=Yeni\nAPP_KEY=x"})
	if err != nil || !res.Cached {
		t.Fatalf("set: %+v %v", res, err)
	}
	if got := read(t, sim, appDir+"/shared/.env"); got != "APP_NAME=Yeni\nAPP_KEY=x\n" {
		t.Fatalf(".env = %q", got)
	}
	if !sim.Ran("runuser -u hizlikargo -- env -C " + appDir + "/current") || !strings.Contains(commands(sim), "dd of="+appDir+"/current/.env status=none") {
		t.Fatalf("not written as the site user:\n%s", commands(sim))
	}
	for _, c := range sim.Commands() {
		if strings.Contains(c.String(), "APP_NAME=Yeni") {
			t.Fatalf("the .env content appeared in a command line: %s", c)
		}
	}
}

func TestQueueWorkers(t *testing.T) {
	m, sim := newTest(t)
	ctx := context.Background()
	if _, err := m.Deploy(ctx, newApp("1")); err != nil {
		t.Fatal(err)
	}
	app := App{Target: target, Path: CurrentPath}
	states, err := m.ApplyQueues(ctx, QueueSpec{App: app, Workers: []Worker{
		{Name: "default", Processes: 2},
		{Name: "reports", Queue: "reports,default", Processes: 1, Timeout: 900, Connection: "redis"},
	}})
	if err != nil {
		t.Fatal(err)
	}
	if len(states) != 3 || states[0].Unit != "servik-queue-api.hizlikargo.app_default@1.service" || states[0].State != "active" {
		t.Fatalf("states = %+v", states)
	}
	unit := read(t, sim, "/etc/systemd/system/servik-queue-api.hizlikargo.app_reports@.service")
	for _, want := range []string{
		"User=hizlikargo",
		"WorkingDirectory=" + appDir + "/current",
		"ExecStart=/usr/bin/php8.4 artisan queue:work redis --queue=reports,default --sleep=3 --tries=1 --timeout=900 --memory=128",
		"TimeoutStopSec=930",
		"StandardOutput=append:/var/log/servik/laravel/api.hizlikargo.app/queue.log",
	} {
		if !strings.Contains(unit, want) {
			t.Errorf("unit lacks %q:\n%s", want, unit)
		}
	}
	// A new deploy restarts the workers.
	if _, err := m.Deploy(ctx, gitDeploy("2")); err != nil {
		t.Fatal(err)
	}
	if !sim.Ran("systemctl try-restart servik-queue-api.hizlikargo.app_default@1.service") {
		t.Error("workers were not restarted after the deploy")
	}
	// Scaling down and removing a group.
	states, err = m.ApplyQueues(ctx, QueueSpec{App: app, Workers: []Worker{{Name: "default", Processes: 1}}})
	if err != nil || len(states) != 1 {
		t.Fatalf("states = %+v err=%v", states, err)
	}
	if !sim.Ran("systemctl disable --now servik-queue-api.hizlikargo.app_default@2.service servik-queue-api.hizlikargo.app_reports@1.service") {
		t.Errorf("workers not stopped:\n%s", commands(sim))
	}
	if exists(sim, "/etc/systemd/system/servik-queue-api.hizlikargo.app_reports@.service") {
		t.Error("removed worker group kept its unit")
	}
	var v *ValidationError
	if _, err := m.ApplyQueues(ctx, QueueSpec{App: app, Workers: []Worker{{Name: "x", Processes: 99}}}); !errors.As(err, &v) {
		t.Errorf("99 processes: %v", err)
	}
}

func TestScheduler(t *testing.T) {
	m, sim := newTest(t)
	ctx := context.Background()
	if _, err := m.Deploy(ctx, newApp("1")); err != nil {
		t.Fatal(err)
	}
	app := App{Target: target, Path: CurrentPath}
	st, err := m.ApplySchedule(ctx, ScheduleSpec{App: app, Enabled: true})
	if err != nil || !st.Enabled {
		t.Fatalf("st=%+v err=%v", st, err)
	}
	if svc := read(t, sim, "/etc/systemd/system/servik-schedule-api.hizlikargo.app.service"); !strings.Contains(svc, "ExecStart=/usr/bin/php8.4 artisan schedule:run") {
		t.Errorf("service:\n%s", svc)
	}
	if err := os.WriteFile(sim.Path(scheduleLog(target.Domain)),
		[]byte("  2026-10-18 21:00:00 Running ['artisan' inspire] ............ 4.21ms DONE\n   INFO  No scheduled commands are ready to run.\n  2026-10-18 21:05:00 Running ['artisan' shipments:sync-carriers --force] ... 1,204.10ms FAIL\n  2026-10-18 22:00:00 Running ['artisan' inspire] ............ 3.90ms DONE\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	status, _ := m.Status(ctx, app)
	if !status.Schedule.Enabled || status.Schedule.LastRun == nil || len(status.Schedule.Tasks) != 2 {
		t.Fatalf("schedule = %+v", status.Schedule)
	}
	if got := status.Schedule.Tasks[0]; got.Command != "inspire" || got.At != "2026-10-18 22:00:00" || got.Duration != "3.90ms" || !got.OK {
		t.Errorf("task 0 = %+v", got)
	}
	if got := status.Schedule.Tasks[1]; got.Command != "shipments:sync-carriers --force" || got.OK {
		t.Errorf("task 1 = %+v", got)
	}
	st, err = m.ApplySchedule(ctx, ScheduleSpec{App: app})
	if err != nil || st.Enabled {
		t.Fatalf("off: %+v %v", st, err)
	}
}

func TestMaintenanceStatusLogsAndProbe(t *testing.T) {
	m, _ := newTest(t)
	ctx := context.Background()
	if _, err := m.Deploy(ctx, newApp("1")); err != nil {
		t.Fatal(err)
	}
	app := App{Target: target, Path: CurrentPath}
	if _, err := m.Maintenance(ctx, MaintenanceSpec{App: app, Down: true, Secret: "bakim-a7f3k9", Retry: 60}); err != nil {
		t.Fatal(err)
	}
	st, err := m.Status(ctx, app)
	if err != nil || st.Maintenance == nil || st.Maintenance.Secret != "bakim-a7f3k9" || st.Maintenance.Retry != 60 || st.Current != "1" || st.Info.Laravel != "13.6.0" {
		t.Fatalf("status = %+v %v", st, err)
	}
	if _, err := m.Maintenance(ctx, MaintenanceSpec{App: app}); err != nil {
		t.Fatal(err)
	}
	if st, _ := m.Status(ctx, app); st.Maintenance != nil {
		t.Fatal("still in maintenance")
	}

	logs, err := m.Logs(ctx, LogsSpec{App: app})
	if err != nil || logs.File != "laravel.log" || !strings.Contains(logs.Content, "production.ERROR") {
		t.Fatalf("logs = %+v %v", logs, err)
	}
	if _, err := m.Logs(ctx, LogsSpec{App: app, File: "../../../.env"}); err == nil {
		t.Fatal("read a file outside storage/logs")
	}

	raw, err := m.Probe(ctx, ProbeSpec{App: app, Kind: "failed"})
	if err != nil {
		t.Fatal(err)
	}
	var failed []map[string]string
	if err := json.Unmarshal(raw, &failed); err != nil || len(failed) != 2 || failed[0]["job"] != `App\Jobs\SyncCarrierStatus` {
		t.Fatalf("failed jobs = %s %v", raw, err)
	}

	res, err := m.Run(ctx, RunSpec{App: app, Tool: "artisan", Args: []string{"--version"}})
	if err != nil || res.ExitCode != 0 || !strings.Contains(res.Output, "Laravel Framework") {
		t.Fatalf("run = %+v %v", res, err)
	}
	if _, err := m.Run(ctx, RunSpec{App: app, Tool: "sh", Args: []string{"-c", "id"}}); err == nil {
		t.Fatal("ran an arbitrary program")
	}
	if _, err := m.Run(ctx, RunSpec{App: App{Target: target, Path: "../../../../etc"}, Tool: "artisan"}); err == nil {
		t.Fatal("ran outside the site")
	}
}

func TestRunLogStreamsAndReadsInChunks(t *testing.T) {
	m, _ := newTest(t)
	ctx := context.Background()
	if _, err := m.Deploy(ctx, newApp("1")); err != nil {
		t.Fatal(err)
	}
	app := App{Target: target, Path: CurrentPath}
	res, err := m.Run(ctx, RunSpec{App: app, Run: "cmd-1", Tool: "artisan", Args: []string{"migrate", "--force"}})
	if err != nil || res.ExitCode != 0 {
		t.Fatalf("run = %+v %v", res, err)
	}
	chunk, err := m.ReadRun(target.Domain, "cmd-1", 0)
	if err != nil || !strings.HasPrefix(chunk.Data, "$ php artisan migrate --force\n") || !strings.Contains(chunk.Data, "Nothing to migrate") {
		t.Fatalf("chunk = %+v %v", chunk, err)
	}
	next, _ := m.ReadRun(target.Domain, "cmd-1", chunk.Offset)
	if next.Data != "" || next.Offset != chunk.Offset {
		t.Fatalf("second read = %+v", next)
	}
	if none, err := m.ReadRun(target.Domain, "nope", 0); err != nil || none.Data != "" {
		t.Fatalf("missing run = %+v %v", none, err)
	}
}

func TestDeployKeyAndRemove(t *testing.T) {
	m, sim := newTest(t)
	ctx := context.Background()
	key, err := m.DeployKey(ctx, target)
	if err != nil || !strings.HasPrefix(key, "ssh-ed25519 ") || !strings.HasSuffix(key, " servik@api.hizlikargo.app") {
		t.Fatalf("key = %q %v", key, err)
	}
	again, _ := m.DeployKey(ctx, target)
	if again != key {
		t.Fatal("the key changed")
	}
	if !strings.Contains(read(t, sim, appDir+"/.ssh/id_ed25519"), "OPENSSH PRIVATE KEY") {
		t.Fatal("private key missing")
	}
	// Git uses it.
	if _, err := m.Deploy(ctx, gitDeploy("1")); err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(commands(sim), "GIT_SSH_COMMAND=ssh -i "+appDir+"/.ssh/id_ed25519") {
		t.Error("git clone did not use the deploy key")
	}
	app := App{Target: target, Path: CurrentPath}
	if _, err := m.ApplyQueues(ctx, QueueSpec{App: app, Workers: []Worker{{Name: "default", Processes: 1}}}); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Remove(ctx, RemoveSpec{Target: target, RemoveFiles: true}); err != nil {
		t.Fatal(err)
	}
	if exists(sim, appDir) || exists(sim, "/etc/systemd/system/servik-queue-api.hizlikargo.app_default@.service") {
		t.Fatal("application not removed")
	}
	if !exists(sim, "/home/hizlikargo/web/api.hizlikargo.app/public_html") {
		t.Fatal("the site's own files went too")
	}
}

func TestSetEnv(t *testing.T) {
	in := "APP_NAME=Laravel\nAPP_KEY=\n# DB_HOST=127.0.0.1\nDB_CONNECTION=sqlite\n"
	got := setEnv(in, map[string]string{"APP_KEY": "base64:x=", "DB_HOST": "10.0.0.5", "APP_NAME": "Hızlı Kargo", "NEW_ONE": "a b"})
	want := "APP_NAME='Hızlı Kargo'\nAPP_KEY=base64:x=\nDB_HOST=10.0.0.5\nDB_CONNECTION=sqlite\n\nNEW_ONE='a b'\n"
	if got != want {
		t.Fatalf("got:\n%s\nwant:\n%s", got, want)
	}
	env := parseEnv(got + "QUOTED=\"a \\\"b\\\"\"\nCOMMENT=x # note\n")
	if env["APP_NAME"] != "Hızlı Kargo" || env["QUOTED"] != `a "b"` || env["COMMENT"] != "x" || env["DB_HOST"] != "10.0.0.5" {
		t.Fatalf("parsed %v", env)
	}
}
