//go:build realhost

// This test installs and deploys a real Laravel application behind real
// Nginx and PHP-FPM, with Composer, Node.js, queue workers and the
// scheduler. It needs root and the network, and changes the machine, so
// it only builds with -tags realhost:
//
//	sudo go test -tags realhost -run TestRealHost -timeout 20m ./internal/laravel
//
// CI runs it on a throwaway runner with nginx and php8.3-fpm installed.
// Without systemd (in a container) the queue and scheduler part is skipped.
package laravel

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"io"
	"net/http"
	"os"
	"os/exec"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"testing"
	"time"

	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/site"
)

// serviceHost is the real system; without systemd, reloads of Nginx and
// PHP-FPM are done with signals.
type serviceHost struct{ host.System }

func hasSystemd() bool {
	_, err := os.Stat("/run/systemd/system")
	return err == nil
}

func (h serviceHost) Run(ctx context.Context, c host.Command) (string, error) {
	if !hasSystemd() && c.Name == "systemctl" && len(c.Args) == 2 && c.Args[0] == "reload" {
		unit := c.Args[1]
		if unit == "nginx" {
			return h.System.Run(ctx, host.Command{Name: "nginx", Args: []string{"-s", "reload"}})
		}
		ver := strings.TrimSuffix(strings.TrimPrefix(unit, "php"), "-fpm")
		return h.System.Run(ctx, host.Command{Name: "sh", Args: []string{"-c", fmt.Sprintf("kill -USR2 $(cat /run/php/php%s-fpm.pid)", ver)}})
	}
	return h.System.Run(ctx, c)
}

const realDomain = "servik-laravel.example"

func TestRealHost(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("needs root")
	}
	h := serviceHost{}
	sites := site.NewManager(h, "www-data")
	versions, err := sites.PHPVersions()
	if err != nil || len(versions) == 0 {
		t.Fatalf("no PHP-FPM installed: %v", err)
	}
	php := versions[len(versions)-1]
	if !hasSystemd() {
		startIfStopped(t, "/run/nginx.pid", "nginx")
		startIfStopped(t, "/run/php/php"+php+"-fpm.pid", "php-fpm"+php)
	}

	ctx := context.Background()
	m := NewManager(h)
	// Where getcomposer.org is not reachable, a local composer.phar can
	// stand in for it.
	if phar := os.Getenv("SERVIK_TEST_COMPOSER_PHAR"); phar != "" {
		download := m.Download
		m.Download = func(ctx context.Context, url string) ([]byte, error) {
			if strings.HasPrefix(url, composerURL) {
				data, err := os.ReadFile(phar)
				if err != nil || url == composerURL {
					return data, err
				}
				sum := sha256.Sum256(data)
				return []byte(hex.EncodeToString(sum[:])), nil
			}
			return download(ctx, url)
		}
	}
	tg := Target{Domain: realDomain, User: "servikl", PHPVersion: php}
	siteSpec := site.Spec{Domain: realDomain, User: tg.User, PHPVersion: php}
	t.Cleanup(func() {
		_, _ = m.Remove(ctx, RemoveSpec{Target: tg, RemoveFiles: true})
		_, _ = sites.Delete(ctx, site.DeleteSpec{Domain: realDomain, User: tg.User, RemoveFiles: true, RemoveUser: true})
	})
	if _, err := sites.Apply(ctx, siteSpec); err != nil {
		t.Fatal(err)
	}
	base := baseDir(tg)

	// 1. A new application, with its front end built, on SQLite. Where the
	// build cannot fetch its web fonts (a locked-down network), it can be
	// left out.
	frontend := os.Getenv("SERVIK_TEST_NO_FRONTEND") == ""
	first := DeploySpec{
		Target: tg, Run: "install", Release: "1",
		Source: Source{Type: "create", Package: "laravel/laravel"},
		Build:  Build{Frontend: frontend, Migrate: true},
		Env: map[string]string{
			"APP_ENV": "production", "APP_DEBUG": "false", "APP_URL": "http://" + realDomain,
			"APP_KEY": "base64:c2VydmlrLXJlYWxob3N0LXRlc3Qta2V5LTMyLWJ5dGU=", "DB_CONNECTION": "sqlite",
			"DB_DATABASE": base + "/shared/database/database.sqlite",
		},
	}
	res := deployOK(t, m, first)
	if res.Laravel == "" || res.Current != "1" {
		t.Fatalf("first deploy: %+v", res)
	}
	t.Logf("Laravel %s (PHP %s)", res.Laravel, res.PHPRequirement)
	// As the panel does on a first install: the site serves the release
	// once there is one, then the health check runs.
	siteSpec.WebRoot = CurrentPath + "/public"
	if _, err := sites.Apply(ctx, siteSpec); err != nil {
		t.Fatal(err)
	}
	if h, err := m.CheckHealth(ctx, realDomain, "/up"); err != nil || !h.OK || h.Status != 200 {
		t.Fatalf("health: %+v %v", h, err)
	}
	if body := get(t, "/", 200); !strings.Contains(body, "Laravel") {
		t.Fatalf("welcome page not served: %.300s", body)
	}
	// Vite built the assets into the release.
	if ents, err := os.ReadDir(base + "/releases/1/public/build/assets"); frontend && (err != nil || len(ents) == 0) {
		t.Fatalf("no built assets: %v", err)
	}
	// Everything in the release belongs to the site's account.
	assertOwner(t, base+"/releases/1/vendor", tg.User)
	assertOwner(t, base+"/shared/database/database.sqlite", tg.User)

	app := App{Target: tg, Path: CurrentPath}
	about, err := m.Probe(ctx, ProbeSpec{App: app, Kind: "about"})
	if err != nil || !strings.Contains(string(about), `"environment":"production"`) {
		t.Fatalf("probe: %s %v", about, err)
	}

	// 2. A Git deploy whose after-activate hook fails goes back to release 1.
	repo, ref := "https://github.com/laravel/laravel.git", newestBranch(t, "https://github.com/laravel/laravel.git")
	bad := DeploySpec{Target: tg, Run: "deploy-2", Release: "2",
		Source: Source{Type: "git", Repository: repo, Ref: ref}, Build: Build{Migrate: true},
		Hooks: Hooks{AfterActivate: "echo checking; exit 3"}, Health: &HealthSpec{Path: "/up"}, Rollback: true}
	res = deployOK(t, m, bad)
	if !res.RolledBack || m.currentRelease(tg) != "1" {
		t.Fatalf("not rolled back: %+v", res)
	}
	get(t, "/up", 200)

	// 3. A good Git deploy.
	good := bad
	good.Run, good.Release, good.Hooks = "deploy-3", "3", Hooks{BeforeActivate: "php artisan about --only=environment"}
	res = deployOK(t, m, good)
	if res.RolledBack || res.Current != "3" || len(res.Commit) != 40 {
		t.Fatalf("git deploy: %+v", res)
	}
	get(t, "/up", 200)

	// The .env is shared: a change reaches the live release.
	files, err := m.GetEnv(ctx, app)
	if err != nil || files.Env == nil {
		t.Fatal(err)
	}
	if _, err := m.SetEnv(ctx, EnvSpec{App: app, Content: setEnv(*files.Env, map[string]string{"APP_NAME": "Servik Test"})}); err != nil {
		t.Fatal(err)
	}
	if out, _ := m.Run(ctx, RunSpec{App: app, Tool: "artisan", Args: []string{"tinker", "--execute=echo config('app.name');"}}); !strings.Contains(out.Output, "Servik Test") {
		t.Fatalf("config not updated: %+v", out)
	}

	// Maintenance mode with a bypass link.
	if _, err := m.Maintenance(ctx, MaintenanceSpec{App: app, Down: true, Secret: "servik-bypass", Retry: 30}); err != nil {
		t.Fatal(err)
	}
	get(t, "/", 503)
	if st, _ := m.Status(ctx, app); st.Maintenance == nil || st.Maintenance.Secret != "servik-bypass" {
		t.Fatalf("status: %+v", st.Maintenance)
	}
	get(t, "/servik-bypass", 302)
	if _, err := m.Maintenance(ctx, MaintenanceSpec{App: app}); err != nil {
		t.Fatal(err)
	}
	get(t, "/", 200)

	logs, err := m.Logs(ctx, LogsSpec{App: app})
	if err != nil {
		t.Fatal(err)
	}
	t.Logf("log files: %+v", logs.Files)

	if !hasSystemd() {
		t.Log("no systemd: skipping queue workers and the scheduler")
		return
	}

	// 4. A queue worker runs a job.
	states, err := m.ApplyQueues(ctx, QueueSpec{App: app, Workers: []Worker{{Name: "default", Processes: 1, Timeout: 30}}})
	if err != nil {
		t.Fatal(err)
	}
	waitFor(t, 30*time.Second, func() bool { return activeState(ctx, h, states[0].Unit) == "active" })
	job := "dispatch(function () { file_put_contents(storage_path('servik-queue-ok'), 'ok'); }); echo 'queued';"
	if out, err := m.Run(ctx, RunSpec{App: app, Tool: "artisan", Args: []string{"tinker", "--execute=" + job}}); err != nil || out.ExitCode != 0 {
		t.Fatalf("dispatch: %+v %v", out, err)
	}
	waitFor(t, 60*time.Second, func() bool {
		_, err := os.Stat(base + "/shared/storage/servik-queue-ok")
		return err == nil
	})

	// 5. The scheduler timer runs schedule:run.
	sched, err := m.ApplySchedule(ctx, ScheduleSpec{App: app, Enabled: true})
	if err != nil || !sched.Enabled {
		t.Fatalf("schedule: %+v %v", sched, err)
	}
	if out, err := h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"start", scheduleUnit(realDomain) + ".service"}}); err != nil {
		t.Fatalf("schedule:run: %v %s", err, out)
	}
	if st := m.scheduleState(realDomain); st.LastRun == nil {
		t.Fatal("the scheduler wrote no log")
	}

	// 6. Removing the application stops its workers.
	if _, err := m.Remove(ctx, RemoveSpec{Target: tg, RemoveFiles: true}); err != nil {
		t.Fatal(err)
	}
	if s := activeState(ctx, h, states[0].Unit); s == "active" {
		t.Fatalf("worker still %s", s)
	}
	if _, err := os.Stat(base); !os.IsNotExist(err) {
		t.Fatal("laravel/ still exists")
	}
}

func deployOK(t *testing.T, m *Manager, spec DeploySpec) DeployResult {
	t.Helper()
	res, err := m.Deploy(context.Background(), spec)
	log, _ := m.ReadRun(spec.Domain, spec.Run, 0)
	t.Logf("deploy %s log:\n%s", spec.Release, log.Data)
	if err != nil {
		t.Fatal(err)
	}
	return res
}

func activeState(ctx context.Context, h host.Host, unit string) string {
	out, _ := h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"is-active", unit}})
	return strings.TrimSpace(out)
}

func assertOwner(t *testing.T, path, user string) {
	t.Helper()
	out, err := exec.Command("stat", "-c", "%U", path).Output()
	if err != nil || strings.TrimSpace(string(out)) != user {
		t.Fatalf("%s owned by %q (%v), want %s", path, out, err, user)
	}
}

// newestBranch returns the newest "N.x" branch of a repository.
func newestBranch(t *testing.T, repo string) string {
	t.Helper()
	out, err := exec.Command("git", "ls-remote", "--heads", repo).Output()
	if err != nil {
		t.Fatal(err)
	}
	re := regexp.MustCompile(`refs/heads/(\d+)\.x$`)
	var majors []int
	for _, line := range strings.Split(string(out), "\n") {
		if g := re.FindStringSubmatch(strings.TrimSpace(line)); g != nil {
			n, _ := strconv.Atoi(g[1])
			majors = append(majors, n)
		}
	}
	if len(majors) == 0 {
		t.Fatalf("no N.x branches in %s", repo)
	}
	sort.Ints(majors)
	return fmt.Sprintf("%d.x", majors[len(majors)-1])
}

func get(t *testing.T, path string, want int) string {
	t.Helper()
	client := &http.Client{CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	var body string
	var got int
	for i := 0; i < 50; i++ {
		req, _ := http.NewRequest("GET", "http://127.0.0.1"+path, nil)
		req.Host = realDomain
		resp, err := client.Do(req)
		if err == nil {
			b, _ := io.ReadAll(resp.Body)
			resp.Body.Close()
			body, got = string(b), resp.StatusCode
			if got == want {
				return body
			}
		}
		time.Sleep(200 * time.Millisecond)
	}
	t.Fatalf("GET %s = %d, want %d: %.500s", path, got, want, body)
	return ""
}

func waitFor(t *testing.T, d time.Duration, ok func() bool) {
	t.Helper()
	deadline := time.Now().Add(d)
	for time.Now().Before(deadline) {
		if ok() {
			return
		}
		time.Sleep(500 * time.Millisecond)
	}
	t.Fatal("timed out")
}

func startIfStopped(t *testing.T, pidFile, bin string) {
	t.Helper()
	if _, err := os.Stat(pidFile); err == nil {
		return
	}
	if out, err := exec.Command(bin).CombinedOutput(); err != nil {
		t.Fatalf("start %s: %v: %s", bin, err, out)
	}
}
