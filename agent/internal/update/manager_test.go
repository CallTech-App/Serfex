package update

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/panel"
)

func TestCompare(t *testing.T) {
	for _, c := range []struct {
		a, b string
		want int
	}{
		{"1.0.0", "1.0.0", 0},
		{"1.0.1", "1.0.0", 1},
		{"1.10.0", "1.9.9", 1},
		{"1.0.0-beta.1", "1.0.0", -1},
		{"1.0.0-beta.2", "1.0.0-beta.10", -1},
		{"1.0.0-alpha", "1.0.0-alpha.1", -1},
		{"1.0.0-rc.1", "1.0.0-beta.9", 1},
		{"1.0.0-1", "1.0.0-alpha", -1},
		{"1.0.0+build.5", "1.0.0", 0},
		{"dev", "0.0.1", -1},
		{"0.0.1", "dev", 1},
	} {
		if got := Compare(c.a, c.b); got != c.want {
			t.Errorf("Compare(%s, %s) = %d, want %d", c.a, c.b, got, c.want)
		}
	}
}

// fakeSource serves a GitHub-style release list and the archives. With a
// token it acts as a private repository: it answers only requests that
// carry the token, and serves files only at their API URLs.
type fakeSource struct {
	srv      *httptest.Server
	releases []map[string]any
	files    map[string][]byte
	token    string
}

func newSource(t *testing.T) *fakeSource {
	f := &fakeSource{files: map[string][]byte{}}
	f.srv = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		path := r.URL.Path
		if f.token != "" {
			api, found := strings.CutPrefix(path, "/api")
			if r.Header.Get("Authorization") != "Bearer "+f.token || (!found && path != "/releases") {
				http.NotFound(w, r)
				return
			}
			path = api
		}
		if path == "/releases" {
			_ = json.NewEncoder(w).Encode(f.releases)
			return
		}
		data, ok := f.files[path]
		if !ok {
			http.NotFound(w, r)
			return
		}
		_, _ = w.Write(data)
	}))
	t.Cleanup(f.srv.Close)
	return f
}

// publish adds a release whose archive holds files (path => content) on
// top of a minimal release of version.
func (f *fakeSource) publish(t *testing.T, version string, prerelease bool, extra map[string]string) {
	t.Helper()
	files := map[string]string{
		"VERSION":                          version + "\n",
		"bin/servik-agent":                 "#!/bin/sh\necho " + version + "\n",
		"panel/artisan":                    "#!/usr/bin/env php\n<?php\n",
		"panel/public/index.php":           "<?php\n",
		"panel/vendor/autoload.php":        "<?php\n",
		"panel/bootstrap/cache/.gitignore": "*\n",
	}
	for k, v := range extra {
		files[k] = v
	}
	archive := tarGz(t, files)
	name := ArchiveName(version, "amd64")
	sum := sha256.Sum256(archive)
	f.files["/dl/"+version+"/"+name] = archive
	f.files["/dl/"+version+"/SHA256SUMS"] = []byte(hex.EncodeToString(sum[:]) + "  " + name + "\n")
	f.releases = append(f.releases, map[string]any{
		"tag_name": "v" + version, "name": "Servik " + version, "body": "- Notes of " + version,
		"draft": false, "prerelease": prerelease, "published_at": "2026-10-19T08:00:00Z",
		"html_url": f.srv.URL + "/releases/v" + version,
		"assets": []map[string]any{
			{"name": name, "browser_download_url": f.srv.URL + "/dl/" + version + "/" + name, "url": f.srv.URL + "/api/dl/" + version + "/" + name, "size": len(archive)},
			{"name": "SHA256SUMS", "browser_download_url": f.srv.URL + "/dl/" + version + "/SHA256SUMS", "url": f.srv.URL + "/api/dl/" + version + "/SHA256SUMS"},
		},
	})
}

func tarGz(t *testing.T, files map[string]string) []byte {
	t.Helper()
	var buf bytes.Buffer
	gz := gzip.NewWriter(&buf)
	tw := tar.NewWriter(gz)
	for name, content := range files {
		mode := int64(0o644)
		if strings.HasPrefix(name, "bin/") || strings.HasSuffix(name, "artisan") {
			mode = 0o755
		}
		if err := tw.WriteHeader(&tar.Header{Name: "./" + name, Mode: mode, Size: int64(len(content)), Typeflag: tar.TypeReg}); err != nil {
			t.Fatal(err)
		}
		if _, err := tw.Write([]byte(content)); err != nil {
			t.Fatal(err)
		}
	}
	if err := tw.Close(); err != nil {
		t.Fatal(err)
	}
	if err := gz.Close(); err != nil {
		t.Fatal(err)
	}
	return buf.Bytes()
}

// installed returns a simulator with release 0.1.0 installed as
// install.sh leaves it, and a Manager for it.
func installed(t *testing.T, src *fakeSource) (*host.Simulator, *Manager) {
	t.Helper()
	sim, err := host.NewSimulator(t.TempDir(), "8.5")
	if err != nil {
		t.Fatal(err)
	}
	write := func(abs, content string) {
		t.Helper()
		if err := os.MkdirAll(filepath.Dir(sim.Path(abs)), 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(sim.Path(abs), []byte(content), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	rel := panel.ReleasesDir + "/0.1.0"
	write(rel+"/VERSION", "0.1.0\n")
	write(rel+"/bin/servik-agent", "")
	write(rel+"/panel/artisan", "")
	write(rel+"/panel/public/index.php", "<?php\n")
	if err := os.Symlink("releases/0.1.0", sim.Path(panel.CurrentLink)); err != nil {
		t.Fatal(err)
	}
	cfg, _ := json.Marshal(panel.Config{PHP: "8.5", Source: src.srv.URL})
	write(panel.ConfigFile, string(cfg))
	write(panel.EnvFile, "APP_NAME=Servik\nDB_CONNECTION=pgsql\nDB_HOST=127.0.0.1\nDB_PORT=5433\nDB_DATABASE=servik\nDB_USERNAME=servik\nDB_PASSWORD=\"s3cret\"\n")
	if err := os.MkdirAll(sim.Path(panel.StorageDir), 0o755); err != nil {
		t.Fatal(err)
	}
	f, err := os.OpenFile(sim.Path("/etc/passwd"), os.O_APPEND|os.O_WRONLY, 0)
	if err != nil {
		t.Fatal(err)
	}
	fmt.Fprintln(f, "servik:x:998:998:Servik panel:/var/lib/servik/panel:/usr/sbin/nologin")
	f.Close()

	m := NewManager(sim, "0.1.0", "amd64")
	m.wait = time.Millisecond
	return sim, m
}

// finish waits for a run started in-process to end.
func finish(t *testing.T, m *Manager, run string) string {
	t.Helper()
	deadline := time.Now().Add(10 * time.Second)
	for time.Now().Before(deadline) {
		chunk, err := m.ReadRun(run, 0)
		if err != nil {
			t.Fatal(err)
		}
		if chunk.Done {
			// The outcome is saved right before the last line.
			st, _ := m.Status()
			if st.Running == "" {
				return chunk.Data
			}
		}
		time.Sleep(10 * time.Millisecond)
	}
	chunk, _ := m.ReadRun(run, 0)
	t.Fatalf("run %s did not finish:\n%s", run, chunk.Data)
	return ""
}

func current(t *testing.T, sim *host.Simulator) string {
	t.Helper()
	v, err := panel.CurrentVersion(sim)
	if err != nil {
		t.Fatal(err)
	}
	return v
}

func TestCheckFollowsTheChannel(t *testing.T) {
	src := newSource(t)
	src.publish(t, "0.1.0", false, nil)
	src.publish(t, "0.2.0", false, nil)
	src.publish(t, "0.3.0-beta.1", true, nil)
	// A release without this server's archive is not offered.
	src.releases = append(src.releases, map[string]any{"tag_name": "v0.2.5", "draft": false, "assets": []any{}})
	_, m := installed(t, src)
	ctx := context.Background()

	st, err := m.Check(ctx, CheckSpec{Channel: Stable})
	if err != nil {
		t.Fatal(err)
	}
	if !st.Installed || st.Version != "0.1.0" || len(st.Releases) != 1 || st.Releases[0].Version != "0.2.0" {
		t.Fatalf("stable: %+v", st)
	}
	if st.Releases[0].Notes != "- Notes of 0.2.0" || st.CheckedAt == "" {
		t.Errorf("release: %+v", st.Releases[0])
	}
	st, err = m.Check(ctx, CheckSpec{Channel: Beta})
	if err != nil {
		t.Fatal(err)
	}
	if len(st.Releases) != 2 || st.Releases[0].Version != "0.3.0-beta.1" || st.Channel != Beta {
		t.Fatalf("beta: %+v", st.Releases)
	}
	if _, err := m.Check(ctx, CheckSpec{Channel: "nightly"}); err == nil {
		t.Error("an unknown channel was accepted")
	}

	// When the source cannot be reached, what was found stays offered.
	src.srv.Close()
	st, err = m.Check(ctx, CheckSpec{Channel: Beta})
	var op *OperationError
	if !errors.As(err, &op) || len(st.Releases) != 2 || st.CheckError == "" {
		t.Fatalf("unreachable source: err %v, status %+v", err, st)
	}
}

func TestUpgradeSwitchesAndKeepsThePreviousRelease(t *testing.T) {
	src := newSource(t)
	src.publish(t, "0.2.0", false, map[string]string{"bin/servik": "#!/bin/sh\n"})
	sim, m := installed(t, src)
	ctx := context.Background()
	var applied int
	m.ApplyPanel = func(context.Context) error { applied++; return nil }
	if _, err := m.Check(ctx, CheckSpec{}); err != nil {
		t.Fatal(err)
	}
	started, err := m.Start(ctx, StartSpec{Version: "0.2.0", Run: "u1"})
	if err != nil {
		t.Fatal(err)
	}
	if started.From != "0.1.0" || started.To != "0.2.0" {
		t.Fatalf("started %+v", started)
	}
	log := finish(t, m, "u1")
	for _, want := range []string{"▸ İndiriliyor", "▸ Doğrulanıyor", "▸ Panel bakım moduna alınıyor", "▸ Veritabanı güncelleniyor", "▸ Servik 0.2.0 etkinleştiriliyor", "▸ Denetleniyor", "✓ Servik 0.2.0 kuruldu."} {
		if !strings.Contains(log, want) {
			t.Errorf("log lacks %q:\n%s", want, log)
		}
	}
	if v := current(t, sim); v != "0.2.0" {
		t.Fatalf("current is %s", v)
	}
	st, err := m.Status()
	if err != nil {
		t.Fatal(err)
	}
	if st.Previous != "0.1.0" || st.Last == nil || st.Last.Status != "succeeded" || len(st.Releases) != 0 {
		t.Fatalf("status %+v / last %+v", st, st.Last)
	}
	if applied != 1 {
		t.Errorf("panel setup applied %d times", applied)
	}
	// Shared settings and storage are linked into the new release.
	if target, err := os.Readlink(sim.Path(panel.ReleasesDir + "/0.2.0/panel/.env")); err != nil || !strings.HasSuffix(target, "etc/servik/panel.env") {
		t.Errorf(".env link %q, %v", target, err)
	}
	if _, err := os.Stat(sim.Path(panel.ReleasesDir + "/0.2.0/panel/storage")); err != nil {
		t.Errorf("storage link: %v", err)
	}
	// The command line is on the PATH and follows current.
	if target, err := os.Readlink(sim.Path(panel.CLILink)); err != nil || target != "/opt/servik/current/bin/servik" {
		t.Errorf("servik link %q, %v", target, err)
	}
	// Maintenance, migrations with the new code, workers restarted.
	for _, want := range []string{
		"runuser -u servik -- env -C /opt/servik/releases/0.1.0/panel php8.5 artisan down",
		"systemctl stop servik-panel-queue servik-panel-backups servik-panel-scheduler",
		"pg_dump --host=127.0.0.1 --port=5433 --username=servik",
		"runuser -u servik -- env -C /opt/servik/releases/0.2.0/panel php8.5 artisan migrate --force",
		"systemctl start servik-panel-queue servik-panel-backups servik-panel-scheduler",
		"runuser -u servik -- env -C /opt/servik/releases/0.2.0/panel php8.5 artisan up",
	} {
		if !sim.Ran(want) {
			t.Errorf("did not run %q", want)
		}
	}
	for _, c := range sim.Commands() {
		if c.Name == "pg_dump" && !contains(c.Env, "PGPASSWORD=s3cret") {
			t.Errorf("pg_dump without the panel's password: %v", c.Env)
		}
	}
	if _, err := m.Start(ctx, StartSpec{Version: "0.2.0", Run: "u2"}); err == nil {
		t.Error("the running release was installed again")
	}
}

func TestTokenReadsAPrivateRepository(t *testing.T) {
	src := newSource(t)
	src.token = "ghp_test"
	src.publish(t, "0.2.0", false, nil)
	sim, m := installed(t, src)
	ctx := context.Background()
	m.ApplyPanel = func(context.Context) error { return nil }

	if _, err := m.Check(ctx, CheckSpec{}); err == nil {
		t.Fatal("a private repository was read without the token")
	}
	if err := os.WriteFile(sim.Path(panel.TokenFile), []byte("ghp_test\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	st, err := m.Check(ctx, CheckSpec{})
	if err != nil || len(st.Releases) != 1 {
		t.Fatalf("check with the token: %v, %+v", err, st)
	}
	if _, err := m.Start(ctx, StartSpec{Version: "0.2.0", Run: "p1"}); err != nil {
		t.Fatal(err)
	}
	if log := finish(t, m, "p1"); !strings.Contains(log, "✓ Servik 0.2.0 kuruldu.") {
		t.Fatalf("update with the token:\n%s", log)
	}
	if v := current(t, sim); v != "0.2.0" {
		t.Fatalf("current is %s", v)
	}
}

func contains(list []string, s string) bool {
	for _, x := range list {
		if x == s {
			return true
		}
	}
	return false
}

func TestFailedHealthCheckRollsBack(t *testing.T) {
	src := newSource(t)
	src.publish(t, "0.2.0", false, nil)
	sim, m := installed(t, src)
	ctx := context.Background()
	healthy := map[string]bool{"0.1.0": true}
	m.Health = func(context.Context) error {
		if v := current(t, sim); !healthy[v] {
			return fmt.Errorf("https://127.0.0.1:8443/up answered HTTP 500")
		}
		return nil
	}
	if _, err := m.Check(ctx, CheckSpec{}); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Start(ctx, StartSpec{Version: "0.2.0", Run: "bad"}); err != nil {
		t.Fatal(err)
	}
	log := finish(t, m, "bad")
	for _, want := range []string{"↺ Servik 0.1.0 sürümüne geri dönülüyor", "↺ Panel veritabanı güncelleme öncesine döndürülüyor", "↺ Servik 0.1.0 yeniden çalışıyor", "✗ health check failed"} {
		if !strings.Contains(log, want) {
			t.Errorf("log lacks %q:\n%s", want, log)
		}
	}
	if !strings.Contains(log, "Servik 0.1.0 ile çalışmaya devam ediyor.") {
		t.Errorf("the result does not say what runs:\n%s", log)
	}
	if v := current(t, sim); v != "0.1.0" {
		t.Fatalf("current is %s after the rollback", v)
	}
	if _, err := os.Stat(sim.Path(panel.ReleasesDir + "/0.2.0")); !os.IsNotExist(err) {
		t.Errorf("the failed release was kept: %v", err)
	}
	if !sim.Ran("pg_restore --host=127.0.0.1 --port=5433 --username=servik --no-password --clean") || !sim.Ran("psql --host=127.0.0.1 --port=5433 --username=servik --no-password -X -q -v ON_ERROR_STOP=1 --single-transaction") {
		t.Error("the database snapshot was not restored")
	}
	st, _ := m.Status()
	if st.Last == nil || st.Last.Status != "rolled_back" || st.Previous != "" || len(st.Releases) != 1 {
		t.Fatalf("status %+v / last %+v", st, st.Last)
	}
}

func TestBadChecksumChangesNothing(t *testing.T) {
	src := newSource(t)
	src.publish(t, "0.2.0", false, nil)
	src.files["/dl/0.2.0/SHA256SUMS"] = []byte(strings.Repeat("0", 64) + "  " + ArchiveName("0.2.0", "amd64") + "\n")
	sim, m := installed(t, src)
	ctx := context.Background()
	if _, err := m.Check(ctx, CheckSpec{}); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Start(ctx, StartSpec{Version: "0.2.0", Run: "sum"}); err != nil {
		t.Fatal(err)
	}
	log := finish(t, m, "sum")
	if !strings.Contains(log, "does not match its SHA-256 sum") || strings.Contains(log, "↺") {
		t.Errorf("log:\n%s", log)
	}
	if sim.Ran("runuser") || sim.Ran("systemctl") {
		t.Error("something was changed before the archive was verified")
	}
	if v := current(t, sim); v != "0.1.0" {
		t.Fatalf("current is %s", v)
	}
	st, _ := m.Status()
	if st.Last == nil || st.Last.Status != "failed" {
		t.Fatalf("last %+v", st.Last)
	}
}

func TestFailedMigrationRestoresTheDatabase(t *testing.T) {
	src := newSource(t)
	src.publish(t, "0.2.0", false, nil)
	sim, m := installed(t, src)
	ctx := context.Background()
	m.Fail = func(step string) error {
		if step == "migrate" {
			return errors.New("SQLSTATE[42P07]: relation already exists")
		}
		return nil
	}
	if _, err := m.Check(ctx, CheckSpec{}); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Start(ctx, StartSpec{Version: "0.2.0", Run: "mig"}); err != nil {
		t.Fatal(err)
	}
	log := finish(t, m, "mig")
	if !strings.Contains(log, "↺ Panel veritabanı güncelleme öncesine döndürülüyor") || sim.Ran("systemctl restart") {
		t.Errorf("log:\n%s", log)
	}
	if v := current(t, sim); v != "0.1.0" {
		t.Fatalf("current is %s", v)
	}
}

func TestRollbackUndoesTheReleasesMigrations(t *testing.T) {
	src := newSource(t)
	src.publish(t, "0.2.0", false, nil)
	sim, m := installed(t, src)
	ctx := context.Background()
	if _, err := m.Rollback(ctx, RollbackSpec{Run: "r0"}); err == nil {
		t.Error("rolled back without a previous release")
	}
	if _, err := m.Check(ctx, CheckSpec{}); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Start(ctx, StartSpec{Version: "0.2.0", Run: "u1"}); err != nil {
		t.Fatal(err)
	}
	finish(t, m, "u1")
	// The upgrade added migration batch 7.
	st, _ := m.loadState()
	st.Batch = 7
	if err := m.saveState(st); err != nil {
		t.Fatal(err)
	}

	if _, err := m.Rollback(ctx, RollbackSpec{Run: "r1"}); err != nil {
		t.Fatal(err)
	}
	log := finish(t, m, "r1")
	if !strings.Contains(log, "✓ Servik 0.1.0 sürümüne dönüldü.") {
		t.Errorf("log:\n%s", log)
	}
	if !sim.Ran("runuser -u servik -- env -C /opt/servik/releases/0.2.0/panel php8.5 artisan migrate:rollback --batch=7 --force") {
		t.Error("the release's migrations were not rolled back with its own code")
	}
	if v := current(t, sim); v != "0.1.0" {
		t.Fatalf("current is %s", v)
	}
	status, _ := m.Status()
	if status.Previous != "" || len(status.Releases) != 1 || status.Last.Kind != "rollback" {
		t.Fatalf("status %+v", status)
	}
	if _, err := os.Stat(sim.Path(panel.ReleasesDir + "/0.2.0/VERSION")); err != nil {
		t.Errorf("the release rolled back from was removed while its code may still run: %v", err)
	}
}

func TestOneRunAtATime(t *testing.T) {
	src := newSource(t)
	src.publish(t, "0.2.0", false, nil)
	_, m := installed(t, src)
	ctx := context.Background()
	if _, err := m.Check(ctx, CheckSpec{}); err != nil {
		t.Fatal(err)
	}
	unlock, err := m.lock()
	if err != nil {
		t.Fatal(err)
	}
	if _, err := m.Start(ctx, StartSpec{Version: "0.2.0", Run: "u1"}); !errors.Is(err, ErrBusy) {
		t.Errorf("started while another run held the lock: %v", err)
	}
	unlock()
	m.Spawn = func(context.Context, string) error { return nil } // started, never runs
	if _, err := m.Start(ctx, StartSpec{Version: "0.2.0", Run: "u2"}); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Start(ctx, StartSpec{Version: "0.2.0", Run: "u3"}); !errors.Is(err, ErrBusy) {
		t.Errorf("started while another run was starting: %v", err)
	}
	// A run whose process never took the lock ends as interrupted.
	m.now = func() time.Time { return time.Now().Add(time.Hour) }
	st, err := m.Status()
	if err != nil {
		t.Fatal(err)
	}
	if st.Running != "" || st.Last == nil || st.Last.Status != "failed" || st.Last.Run != "u2" {
		t.Fatalf("status %+v / last %+v", st, st.Last)
	}
}

func TestUnpackStaysInside(t *testing.T) {
	dir := t.TempDir()
	for _, hd := range []*tar.Header{
		{Name: "../evil", Typeflag: tar.TypeReg, Mode: 0o644},
		{Name: "panel/link", Typeflag: tar.TypeSymlink, Linkname: "../../../etc/passwd"},
		{Name: "panel/abs", Typeflag: tar.TypeSymlink, Linkname: "/etc/passwd"},
		{Name: "dev", Typeflag: tar.TypeChar},
	} {
		var buf bytes.Buffer
		gz := gzip.NewWriter(&buf)
		tw := tar.NewWriter(gz)
		_ = tw.WriteHeader(hd)
		_ = tw.Close()
		_ = gz.Close()
		archive := filepath.Join(dir, "a.tar.gz")
		if err := os.WriteFile(archive, buf.Bytes(), 0o600); err != nil {
			t.Fatal(err)
		}
		if err := unpack(archive, filepath.Join(dir, "out")); err == nil {
			t.Errorf("%s (%c) was unpacked", hd.Name, hd.Typeflag)
		}
	}
}

func TestSetupWritesThePanelUnits(t *testing.T) {
	src := newSource(t)
	sim, m := installed(t, src)
	ctx := context.Background()
	webs := 0
	m.Web = func(context.Context) (any, error) { webs++; return map[string]int{"port": 8443}, nil }
	if err := os.MkdirAll(sim.Path("/etc/needrestart"), 0o755); err != nil {
		t.Fatal(err)
	}
	res, err := m.Setup(ctx)
	if err != nil {
		t.Fatal(err)
	}
	if len(res.Changed) != 3 || webs != 1 {
		t.Fatalf("setup %+v, web %d", res, webs)
	}
	unit, err := os.ReadFile(sim.Path("/etc/systemd/system/servik-panel-backups.service"))
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(string(unit), "ExecStart=/usr/bin/php8.5 /opt/servik/current/panel/artisan queue:work backups --queue=backups") || !strings.Contains(string(unit), "User=servik") {
		t.Errorf("unit:\n%s", unit)
	}
	// needrestart leaves the workers alone: a restart would kill a job
	// waiting on the agent.
	conf, err := os.ReadFile(sim.Path("/etc/needrestart/conf.d/servik.conf"))
	if err != nil || !strings.Contains(string(conf), "$nrconf{override_rc}->{qr(^servik-panel-)} = 0;") {
		t.Errorf("needrestart: %v\n%s", err, conf)
	}
	res, err = m.Setup(ctx)
	if err != nil {
		t.Fatal(err)
	}
	if len(res.Changed) != 0 {
		t.Errorf("a second setup changed %v", res.Changed)
	}
}
