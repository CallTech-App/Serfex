package laravel

import (
	"archive/tar"
	"context"
	"errors"
	"io"
	"os"
	"strings"
	"testing"
	"time"

	"github.com/calltech-app/serfex/agent/internal/host"
)

var app = App{Target: target, Path: CurrentPath}

func deployed(t *testing.T) (*Manager, *host.Simulator) {
	t.Helper()
	m, sim := newTest(t)
	if _, err := m.Deploy(context.Background(), newApp("1")); err != nil {
		t.Fatal(err)
	}
	return m, sim
}

func TestProcesses(t *testing.T) {
	m, sim := deployed(t)
	ctx := context.Background()
	procs := []Process{
		{Name: "horizon", Kind: "horizon", Enabled: true},
		{Name: "octane", Kind: "octane", Enabled: true, Port: 20001, Workers: 4},
		{Name: "reverb", Kind: "reverb", Enabled: false, Port: 20002},
		{Name: "pulse", Kind: "custom", Enabled: true, Command: []string{"artisan", "pulse:check", "--once=no"}},
	}
	states, err := m.ApplyProcesses(ctx, ProcessSpec{App: app, Processes: procs})
	if err != nil {
		t.Fatal(err)
	}
	unit := func(name string) string {
		return read(t, sim, "/etc/systemd/system/serfex-proc-api.hizlikargo.app_"+name+".service")
	}
	for name, want := range map[string]string{
		"horizon": `ExecStart="/usr/bin/php8.4" "artisan" "horizon"`,
		"octane":  `ExecStart="/usr/bin/php8.4" "artisan" "octane:start" "--server=frankenphp" "--host=127.0.0.1" "--port=20001" "--max-requests=500" "--workers=4" "--admin-port=40001"`,
		"reverb":  `ExecStart="/usr/bin/php8.4" "artisan" "reverb:start" "--host=127.0.0.1" "--port=20002"`,
		"pulse":   `ExecStart="/usr/bin/php8.4" "artisan" "pulse:check" "--once=no"`,
	} {
		u := unit(name)
		for _, w := range []string{want, "User=hizlikargo", "WorkingDirectory=" + appDir + "/current", "StandardOutput=append:/var/log/serfex/laravel/api.hizlikargo.app/proc-" + name + ".log", "Restart=always"} {
			if !strings.Contains(u, w) {
				t.Errorf("%s unit lacks %q:\n%s", name, w, u)
			}
		}
	}
	if !strings.Contains(unit("horizon"), "TimeoutStopSec=300") {
		t.Error("Horizon needs time to finish its jobs")
	}
	got := map[string]ProcessState{}
	for _, s := range states {
		got[s.Name] = s
	}
	if !got["horizon"].Enabled || got["horizon"].State != "active" || got["reverb"].Enabled || got["reverb"].State != "inactive" || len(got) != 4 {
		t.Fatalf("states %+v", states)
	}

	// A deploy restarts the running processes.
	if _, err := m.Deploy(ctx, gitDeploy("2")); err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(commands(sim), "systemctl try-restart serfex-proc-api.hizlikargo.app_horizon.service serfex-proc-api.hizlikargo.app_octane.service serfex-proc-api.hizlikargo.app_pulse.service") {
		t.Errorf("processes not restarted after the deploy:\n%s", commands(sim))
	}
	if log, _ := m.ReadRun(target.Domain, "deploy-2", 0); !strings.Contains(log.Data, "süreçler yeniden başlatıldı") {
		t.Errorf("deploy log:\n%s", log.Data)
	}

	if _, err := m.RestartProcess(ctx, ProcessAction{Domain: target.Domain, Name: "reverb"}); err == nil {
		t.Error("a stopped process cannot be restarted")
	}
	if _, err := m.RestartProcess(ctx, ProcessAction{Domain: target.Domain, Name: "octane"}); err != nil {
		t.Error(err)
	}

	// Logs are read from the end.
	if err := os.WriteFile(sim.Path("/var/log/serfex/laravel/api.hizlikargo.app/proc-octane.log"), []byte(strings.Repeat("x", 70<<10)+"\nINFO  Server running…\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	chunk, err := m.ReadProcessLog(ProcessAction{Domain: target.Domain, Name: "octane"}, -1)
	if err != nil || !strings.HasSuffix(chunk.Data, "Server running…\n") || len(chunk.Data) > tailStart {
		t.Fatalf("log tail %d bytes: %v", len(chunk.Data), err)
	}

	// Dropping a process stops and removes its unit.
	states, err = m.ApplyProcesses(ctx, ProcessSpec{App: app, Processes: procs[:1]})
	if err != nil || len(states) != 1 {
		t.Fatalf("%+v %v", states, err)
	}
	if exists(sim, "/etc/systemd/system/serfex-proc-api.hizlikargo.app_octane.service") || !strings.Contains(commands(sim), "systemctl disable --now serfex-proc-api.hizlikargo.app_octane.service serfex-proc-api.hizlikargo.app_pulse.service") {
		t.Error("octane unit should be gone")
	}

	for _, bad := range []Process{
		{Name: "x", Kind: "octane", Port: 80},
		{Name: "x", Kind: "octane", Port: 20001, Server: "nginx"},
		{Name: "x", Kind: "horizon", Port: 20001},
		{Name: "x", Kind: "custom"},
		{Name: "x", Kind: "custom", Command: []string{"../bin/sh"}},
		{Name: "x", Kind: "custom", Command: []string{"sh", "-c", "a\nb"}},
		{Name: "Bad", Kind: "horizon"},
		{Name: "x", Kind: "cron"},
	} {
		var v *ValidationError
		if _, err := m.ApplyProcesses(ctx, ProcessSpec{App: app, Processes: []Process{bad}}); !errors.As(err, &v) {
			t.Errorf("%+v: %v", bad, err)
		}
	}

	// Removing the application removes the process units too.
	if _, err := m.Remove(ctx, RemoveSpec{Target: target}); err != nil {
		t.Fatal(err)
	}
	if exists(sim, "/etc/systemd/system/serfex-proc-api.hizlikargo.app_horizon.service") {
		t.Error("units left behind")
	}
}

func TestSystemdQuote(t *testing.T) {
	if got := systemdQuote(`50% "of" $HOME\x`); got != `"50%% \"of\" $$HOME\\x"` {
		t.Fatal(got)
	}
}

func TestTinkerSession(t *testing.T) {
	m, _ := deployed(t)
	ctx := context.Background()
	if _, err := m.StartTinker(ctx, TinkerSpec{App: app, Session: "tinker-abc"}); err != nil {
		t.Fatal(err)
	}
	if !m.TinkerRunning(target.Domain, "tinker-abc") {
		t.Fatal("session should run")
	}
	if st, err := m.TinkerSend(TinkerInput{Domain: target.Domain, Session: "tinker-abc", Code: "App\\Models\\User::count()"}); err != nil || !st.Running {
		t.Fatalf("%+v %v", st, err)
	}
	eventually(t, func() bool {
		log, _ := m.ReadRun(target.Domain, "tinker-abc", 0)
		return strings.Contains(log.Data, ">>> App\\Models\\User::count()\n=>  1284\n")
	})
	if _, err := m.StopTinker(TinkerInput{Domain: target.Domain, Session: "tinker-abc"}); err != nil {
		t.Fatal(err)
	}
	eventually(t, func() bool { return !m.TinkerRunning(target.Domain, "tinker-abc") })
	if st, _ := m.TinkerSend(TinkerInput{Domain: target.Domain, Session: "tinker-abc", Code: "1"}); st.Running {
		t.Error("a stopped session takes no input")
	}
	log, _ := m.ReadRun(target.Domain, "tinker-abc", 0)
	if !strings.Contains(log.Data, "Tinker · api.hizlikargo.app · PHP 8.4") || !strings.Contains(log.Data, "■ Oturum kapatıldı.") {
		t.Errorf("log:\n%s", log.Data)
	}
	for i := 0; i < tinkerPerSite; i++ {
		if _, err := m.StartTinker(ctx, TinkerSpec{App: app, Session: "s" + string(rune('a'+i))}); err != nil {
			t.Fatal(err)
		}
	}
	var v *ValidationError
	if _, err := m.StartTinker(ctx, TinkerSpec{App: app, Session: "one-too-many"}); !errors.As(err, &v) {
		t.Errorf("session limit: %v", err)
	}
}

func eventually(t *testing.T, ok func() bool) {
	t.Helper()
	for i := 0; i < 100; i++ {
		if ok() {
			return
		}
		time.Sleep(20 * time.Millisecond)
	}
	t.Fatal("timed out")
}

func TestBackupAndRestore(t *testing.T) {
	m, sim := deployed(t)
	ctx := context.Background()
	shared := appDir + "/shared"
	if err := os.WriteFile(sim.Path(shared+"/database/database.sqlite"), []byte("SQLite format 3\x00 v1"), 0o600); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(sim.Path(shared+"/storage/app/invoice.pdf"), []byte("%PDF v1"), 0o600); err != nil {
		t.Fatal(err)
	}
	res, err := m.Backup(ctx, BackupSpec{App: app, ID: "b1", Parts: backupParts, Run: "backup-b1"})
	if err != nil {
		t.Fatal(err)
	}
	if res.Release != "1" || res.Database != "sqlite" || len(res.Parts) != 4 || res.Size == 0 || len(res.Skipped) != 0 {
		t.Fatalf("%+v", res)
	}
	file := sim.Path(backupFile(target.Domain, "b1"))
	fi, err := os.Stat(file)
	if err != nil || fi.Mode().Perm() != 0o600 {
		t.Fatalf("backup file: %v %v", fi, err)
	}
	members := tarMembers(t, file)
	if strings.Join(members, " ") != "manifest.json code.tar.gz env storage.tar.gz database.sqlite.gz" {
		t.Errorf("members %v", members)
	}
	if log, _ := m.ReadRun(target.Domain, "backup-b1", 0); !strings.Contains(log.Data, "✓ Yedek alındı") {
		t.Errorf("log:\n%s", log.Data)
	}
	if _, err := m.Backup(ctx, BackupSpec{App: app, ID: "b1", Parts: []string{"env"}}); err == nil {
		t.Error("a backup id is used once")
	}

	// Things change, then the backup comes back.
	_ = os.WriteFile(sim.Path(shared+"/database/database.sqlite"), []byte("SQLite format 3\x00 v2"), 0o600)
	_ = os.WriteFile(sim.Path(shared+"/storage/app/invoice.pdf"), []byte("%PDF v2"), 0o600)
	_ = os.WriteFile(sim.Path(shared+"/storage/app/new.txt"), []byte("new"), 0o600)
	_ = os.WriteFile(sim.Path(shared+"/.env"), []byte("APP_KEY=changed\n"), 0o600)
	if _, err := m.Deploy(ctx, gitDeploy("2")); err != nil {
		t.Fatal(err)
	}
	// Release 1 is gone from disk, so the code is unpacked again.
	if err := os.RemoveAll(sim.Path(appDir + "/releases/1")); err != nil {
		t.Fatal(err)
	}
	r, err := m.Restore(ctx, RestoreSpec{App: app, ID: "b1", Parts: []string{"code", "env", "storage", "database"}, Run: "restore-b1"})
	if err != nil {
		t.Fatal(err)
	}
	if r.Current != "1" || len(r.Restored) != 4 {
		t.Fatalf("%+v", r)
	}
	if !exists(sim, appDir+"/releases/1/artisan") {
		t.Error("release 1 not unpacked")
	}
	if got := read(t, sim, shared+"/storage/app/invoice.pdf"); got != "%PDF v1" || exists(sim, shared+"/storage/app/new.txt") {
		t.Errorf("storage not restored: %q", got)
	}
	if !exists(sim, shared+"/storage/framework/sessions") {
		t.Error("storage directories missing after restore")
	}
	if got := read(t, sim, shared+"/database/database.sqlite"); got != "SQLite format 3\x00 v1" {
		t.Errorf("database not restored: %q", got)
	}
	if env := read(t, sim, shared+"/.env"); !strings.Contains(env, "DB_CONNECTION=sqlite") {
		t.Errorf(".env not restored:\n%s", env)
	}
	for _, c := range sim.Commands() {
		if s := c.String(); (strings.Contains(s, "tar ") || strings.Contains(s, " dd ")) && !strings.HasPrefix(s, "runuser -u hizlikargo -- env") {
			t.Errorf("archive work must run as the site's account: %s", s)
		}
	}

	// Download in chunks.
	chunk, err := m.ReadBackup(BackupRef{Domain: target.Domain, ID: "b1"}, 0)
	if err != nil || int64(len(chunk.Data)) != chunk.Size || chunk.Offset != chunk.Size {
		t.Fatalf("read: %d of %d, %v", len(chunk.Data), chunk.Size, err)
	}
	if _, err := m.DeleteBackup(BackupRef{Domain: target.Domain, ID: "b1"}); err != nil || exists(sim, backupFile(target.Domain, "b1")) {
		t.Fatalf("delete: %v", err)
	}
	var v *ValidationError
	if _, err := m.Restore(ctx, RestoreSpec{App: app, ID: "b1", Parts: []string{"env"}}); !errors.As(err, &v) {
		t.Errorf("restore of a deleted backup: %v", err)
	}
	if _, err := m.Backup(ctx, BackupSpec{App: app, ID: "b2", Parts: []string{"files"}}); !errors.As(err, &v) {
		t.Errorf("unknown part: %v", err)
	}
}

func TestBackupDatabaseDump(t *testing.T) {
	m, sim := deployed(t)
	env := "DB_CONNECTION=mysql\nDB_HOST=localhost\nDB_DATABASE=kargo\nDB_USERNAME=kargo\nDB_PASSWORD='s3cr et'\n"
	if err := os.WriteFile(sim.Path(appDir+"/shared/.env"), []byte(env), 0o600); err != nil {
		t.Fatal(err)
	}
	res, err := m.Backup(context.Background(), BackupSpec{App: app, ID: "db", Parts: []string{"database"}})
	if err != nil || res.Database != "mysql" || res.Parts["database"] == 0 {
		t.Fatalf("%+v %v", res, err)
	}
	for _, c := range sim.Commands() {
		s := c.String()
		if strings.Contains(s, "mysqldump") {
			if !strings.Contains(s, "--host=127.0.0.1 --port=3306 --user=kargo -- kargo") || strings.Contains(s, "s3cr") {
				t.Errorf("dump command: %s", s)
			}
			if len(c.Env) != 1 || c.Env[0] != "MYSQL_PWD=s3cr et" {
				t.Errorf("password must travel in the environment: %v", c.Env)
			}
		}
	}
	if _, err := m.Restore(context.Background(), RestoreSpec{App: app, ID: "db", Parts: []string{"database"}}); err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(commands(sim), "runuser -u hizlikargo -- env -C / ") || !sim.Ran("runuser -u hizlikargo -- env -C /") {
		t.Error("mysql not run as the site's account")
	}

	_ = os.WriteFile(sim.Path(appDir+"/shared/.env"), []byte("DB_CONNECTION=mysql\nDB_DATABASE=--all-databases\n"), 0o600)
	res, err = m.Backup(context.Background(), BackupSpec{App: app, ID: "bad", Parts: []string{"database", "env"}})
	if err != nil || res.Skipped["database"] == "" {
		t.Fatalf("an option-like database name must be refused: %+v %v", res, err)
	}
}

func tarMembers(t *testing.T, file string) []string {
	t.Helper()
	f, err := os.Open(file)
	if err != nil {
		t.Fatal(err)
	}
	defer f.Close()
	var out []string
	tr := tar.NewReader(f)
	for {
		h, err := tr.Next()
		if errors.Is(err, io.EOF) {
			return out
		}
		if err != nil {
			t.Fatal(err)
		}
		out = append(out, h.Name)
	}
}
