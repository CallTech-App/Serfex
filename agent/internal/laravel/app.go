package laravel

import (
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/json"
	"encoding/pem"
	"fmt"
	"os"
	"sort"
	"strings"
	"time"

	"golang.org/x/crypto/ssh"
)

// Status is everything the panel shows about an application at a glance.
type Status struct {
	Exists      bool             `json:"exists"`
	Current     string           `json:"current"`
	Releases    []Release        `json:"releases"`
	Info        AppInfo          `json:"info"`
	Maintenance *MaintenanceInfo `json:"maintenance"`
	Workers     []WorkerState    `json:"workers"`
	Schedule    ScheduleState    `json:"schedule"`
	Processes   []ProcessState   `json:"processes"`
	DeployKey   string           `json:"deploy_key"`
}

// MaintenanceInfo is the content of storage/framework/down.
type MaintenanceInfo struct {
	Secret string `json:"secret"`
	Retry  int    `json:"retry"`
}

// Status reports an application's releases, version and processes.
func (m *Manager) Status(ctx context.Context, a App) (Status, error) {
	if err := a.validate(); err != nil {
		return Status{}, err
	}
	st := Status{Releases: m.releases(a.Target), Current: m.currentRelease(a.Target), Workers: m.workerStates(ctx, a.Domain), Schedule: m.scheduleState(a.Domain), Processes: m.processStates(ctx, a.Domain)}
	st.Exists = m.exists(a.dir() + "/artisan")
	if st.Exists {
		st.Info = m.appInfo(ctx, a)
		down := a.dir() + "/storage/framework/down"
		if m.exists(down) {
			st.Maintenance = &MaintenanceInfo{}
			if b, err := m.readFile(ctx, a.Target, down, 64<<10); err == nil {
				var d struct {
					Secret *string `json:"secret"`
					Retry  *int    `json:"retry"`
				}
				if json.Unmarshal(b, &d) == nil {
					if d.Secret != nil {
						st.Maintenance.Secret = *d.Secret
					}
					if d.Retry != nil {
						st.Maintenance.Retry = *d.Retry
					}
				}
			}
		}
	}
	if pub := baseDir(a.Target) + "/.ssh/id_ed25519.pub"; m.exists(pub) {
		if b, err := m.readFile(ctx, a.Target, pub, 4096); err == nil {
			st.DeployKey = strings.TrimSpace(string(b))
		}
	}
	return st, nil
}

// MaintenanceSpec puts an application into maintenance mode or out of it.
type MaintenanceSpec struct {
	App
	Down bool `json:"down"`
	// Secret is the path that lets the team in during maintenance
	// (https://<domain>/<secret>).
	Secret string `json:"secret"`
	// Retry is the Retry-After header, in seconds; 0 leaves it out.
	Retry int `json:"retry"`
}

// Maintenance runs php artisan down or up.
func (m *Manager) Maintenance(ctx context.Context, spec MaintenanceSpec) (RunResult, error) {
	if err := spec.App.validate(); err != nil {
		return RunResult{}, err
	}
	args := []string{"up"}
	if spec.Down {
		if spec.Secret != "" && !idRe.MatchString(spec.Secret) {
			return RunResult{}, invalid("secret must be letters, digits, - or _")
		}
		if spec.Retry < 0 || spec.Retry > 86400 {
			return RunResult{}, invalid("retry must be between 0 and 86400 seconds")
		}
		args = []string{"down"}
		if spec.Secret != "" {
			args = append(args, "--secret="+spec.Secret)
		}
		if spec.Retry > 0 {
			args = append(args, fmt.Sprintf("--retry=%d", spec.Retry))
		}
	}
	res, err := m.Run(ctx, RunSpec{App: spec.App, Tool: "artisan", Args: args})
	if err != nil {
		return res, err
	}
	if res.ExitCode != 0 {
		return res, failed("php artisan %s failed: %s", args[0], firstLine(res.Output))
	}
	return res, nil
}

// LogsSpec asks for an application's log files.
type LogsSpec struct {
	App
	// File is a file name in storage/logs; empty picks the newest.
	File  string `json:"file"`
	Bytes int    `json:"bytes"`
}

// LogFile is a file in storage/logs.
type LogFile struct {
	Name     string    `json:"name"`
	Size     int64     `json:"size"`
	Modified time.Time `json:"modified"`
}

// Logs is the list of log files and the end of one of them.
type Logs struct {
	Files   []LogFile `json:"files"`
	File    string    `json:"file"`
	Content string    `json:"content"`
	// Truncated is true when Content starts in the middle of the file.
	Truncated bool `json:"truncated"`
}

// Logs lists storage/logs/*.log, newest first, and returns the last
// bytes of one of them.
func (m *Manager) Logs(ctx context.Context, spec LogsSpec) (Logs, error) {
	if err := spec.App.validate(); err != nil {
		return Logs{}, err
	}
	if spec.Bytes <= 0 || spec.Bytes > 4<<20 {
		spec.Bytes = 256 << 10
	}
	out := Logs{Files: []LogFile{}}
	dir := spec.dir() + "/storage/logs"
	entries, err := os.ReadDir(m.h.Path(dir))
	if err != nil {
		return out, nil
	}
	for _, e := range entries {
		fi, err := e.Info()
		if err != nil || !fi.Mode().IsRegular() || !strings.HasSuffix(e.Name(), ".log") || !idRe.MatchString(strings.TrimSuffix(e.Name(), ".log")) {
			continue
		}
		out.Files = append(out.Files, LogFile{Name: e.Name(), Size: fi.Size(), Modified: fi.ModTime().UTC()})
	}
	sort.Slice(out.Files, func(i, j int) bool { return out.Files[i].Modified.After(out.Files[j].Modified) })
	if len(out.Files) == 0 {
		return out, nil
	}
	file := out.Files[0]
	if spec.File != "" {
		found := false
		for _, f := range out.Files {
			if f.Name == spec.File {
				file, found = f, true
			}
		}
		if !found {
			return Logs{}, invalid("no log file %q", spec.File)
		}
	}
	b, err := m.readFile(ctx, spec.Target, dir+"/"+file.Name, spec.Bytes)
	if err != nil {
		return Logs{}, &OperationError{err}
	}
	out.File, out.Content, out.Truncated = file.Name, string(b), file.Size > int64(spec.Bytes)
	return out, nil
}

// ScanSite is a site to look for applications on.
type ScanSite struct {
	Domain string `json:"domain"`
	User   string `json:"user"`
}

// Found is an application found on a site.
type Found struct {
	Domain string `json:"domain"`
	// Path is the application directory, relative to the site directory.
	Path string `json:"path"`
	AppInfo
}

// Scan looks for Laravel applications in the usual places of each site:
// public_html, a directory below it, a directory next to it, and Servik's
// own laravel/current.
func (m *Manager) Scan(ctx context.Context, sites []ScanSite) ([]Found, error) {
	out := []Found{}
	for _, s := range sites {
		t := Target{Domain: s.Domain, User: s.User}
		if err := t.validate(); err != nil {
			return nil, err
		}
		dir := siteDir(t)
		candidates := []string{CurrentPath, "public_html"}
		for _, parent := range []string{"", "public_html/"} {
			entries, _ := os.ReadDir(m.h.Path(dir + "/" + parent))
			for _, e := range entries {
				if e.IsDir() && e.Name() != Base && e.Name() != "logs" && !strings.HasPrefix(e.Name(), ".") {
					candidates = append(candidates, parent+e.Name())
				}
			}
		}
		seen := map[string]bool{}
		for _, c := range candidates {
			if seen[c] || validRel(c) != nil {
				continue
			}
			seen[c] = true
			if fi, err := os.Lstat(m.h.Path(dir + "/" + c + "/artisan")); err != nil || !fi.Mode().IsRegular() {
				continue
			}
			b, err := m.readFile(ctx, t, dir+"/"+c+"/composer.json", 1<<20)
			if err != nil || !requiresLaravel(b) {
				continue
			}
			out = append(out, Found{Domain: s.Domain, Path: c, AppInfo: m.appInfo(ctx, App{Target: t, Path: c})})
		}
	}
	return out, nil
}

// requiresLaravel tells whether a composer.json requires laravel/framework.
func requiresLaravel(composerJSON []byte) bool {
	var c struct {
		Require map[string]any `json:"require"`
	}
	if json.Unmarshal(composerJSON, &c) != nil {
		return false
	}
	_, ok := c.Require["laravel/framework"]
	return ok
}

// ProbeSpec asks the application itself, through a PHP script that boots
// it like Artisan does.
type ProbeSpec struct {
	App
	// Kind is about, queues, failed or schedule.
	Kind string `json:"kind"`
	// Queues are [connection, queue] pairs whose sizes to report.
	Queues [][2]string `json:"queues"`
}

// probeScript boots the application from its directory and prints one
// JSON answer after a marker, so warnings printed while booting do not
// break it.
const probeScript = `<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
$dir = getcwd();
require $dir.'/vendor/autoload.php';
$app = require $dir.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$kind = getenv('SERVIK_PROBE');
$out = null;
$line = fn ($s) => trim(strtok((string) $s, "\n"));
if ($kind === 'about') {
    $out = ['laravel' => $app->version(), 'php' => PHP_VERSION, 'environment' => $app->environment(), 'debug' => (bool) config('app.debug'),
        'queue' => config('queue.default'), 'cache' => config('cache.default'), 'database' => config('database.default'), 'session' => config('session.driver')];
} elseif ($kind === 'queues') {
    $sizes = [];
    foreach (json_decode((string) getenv('SERVIK_PROBE_ARGS'), true) ?: [] as [$conn, $queue]) {
        try {
            $sizes[] = ['connection' => $conn, 'queue' => $queue, 'size' => $app['queue']->connection($conn ?: null)->size($queue)];
        } catch (Throwable $e) {
            $sizes[] = ['connection' => $conn, 'queue' => $queue, 'size' => null, 'error' => $line($e->getMessage())];
        }
    }
    try { $failed = count($app['queue.failer']->all()); } catch (Throwable $e) { $failed = null; }
    $out = ['sizes' => $sizes, 'failed' => $failed];
} elseif ($kind === 'failed') {
    $out = [];
    foreach (array_slice($app['queue.failer']->all(), 0, 200) as $job) {
        $job = (array) $job;
        $payload = json_decode($job['payload'] ?? '', true) ?: [];
        $out[] = ['id' => (string) ($job['uuid'] ?? $job['id']), 'connection' => $job['connection'] ?? '', 'queue' => $job['queue'] ?? '',
            'job' => $payload['displayName'] ?? '', 'error' => $line($job['exception'] ?? ''), 'failed_at' => (string) ($job['failed_at'] ?? '')];
    }
} elseif ($kind === 'schedule') {
    $out = [];
    $schedule = $app->make(Illuminate\Console\Scheduling\Schedule::class);
    foreach ($schedule->events() as $event) {
        $command = $event->command ?? '';
        $command = $command === '' ? $event->getSummaryForDisplay() : trim(preg_replace("/^.*?'artisan'\s*/", '', str_replace(PHP_BINARY, '', $command)));
        $out[] = ['command' => $command, 'description' => (string) $event->description, 'expression' => $event->expression,
            'next' => $event->nextRunDate()->format(DATE_ATOM), 'timezone' => (string) ($event->timezone ?: config('app.timezone'))];
    }
}
echo "\nSERVIK-PROBE:", json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
`

// Probe asks the application for its queue sizes, failed jobs, schedule
// or basic configuration.
func (m *Manager) Probe(ctx context.Context, spec ProbeSpec) (json.RawMessage, error) {
	if err := spec.App.validate(); err != nil {
		return nil, err
	}
	switch spec.Kind {
	case "about", "queues", "failed", "schedule":
	default:
		return nil, invalid("kind must be about, queues, failed or schedule")
	}
	if len(spec.Queues) > 50 {
		return nil, invalid("at most 50 queues can be probed")
	}
	if err := m.requireApp(spec.App); err != nil {
		return nil, err
	}
	if !m.exists(spec.dir()+"/vendor/autoload.php") && !m.sim {
		return nil, invalid("the application has no vendor/ directory; run composer install")
	}
	queues, _ := json.Marshal(spec.Queues)
	ctx, cancel := context.WithTimeout(ctx, 30*time.Second)
	defer cancel()
	c := m.cmdEnv(spec.Target, spec.dir(), nil, []string{"SERVIK_PROBE=" + spec.Kind, "SERVIK_PROBE_ARGS=" + string(queues)}, phpBin(spec.PHPVersion))
	c.Stdin = []byte(probeScript)
	out, err := m.h.Run(ctx, c)
	_, answer, found := strings.Cut(out, "SERVIK-PROBE:")
	if !found {
		msg := firstLine(out)
		if err != nil && msg == "" {
			msg = err.Error()
		}
		return nil, failed("the application could not be started: %s", msg)
	}
	answer = firstLine(answer)
	if !json.Valid([]byte(answer)) {
		return nil, failed("the application gave an unreadable answer")
	}
	return json.RawMessage(answer), nil
}

// DeployKey returns the application's SSH deploy key, creating it first.
// The key belongs to the site's account, like the code it fetches.
func (m *Manager) DeployKey(ctx context.Context, t Target) (string, error) {
	if err := t.validate(); err != nil {
		return "", err
	}
	if !m.isDir(siteDir(t)) {
		return "", invalid("site %s does not exist", t.Domain)
	}
	return m.deployKey(ctx, t, baseDir(t)+"/.ssh")
}

// deployKey returns the public half of the key in dir, creating it first.
func (m *Manager) deployKey(ctx context.Context, t Target, dir string) (string, error) {
	if m.exists(dir+"/id_ed25519") && m.exists(dir+"/id_ed25519.pub") {
		b, err := m.readFile(ctx, t, dir+"/id_ed25519.pub", 4096)
		if err != nil {
			return "", &OperationError{err}
		}
		return strings.TrimSpace(string(b)), nil
	}
	pub, priv, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		return "", err
	}
	block, err := ssh.MarshalPrivateKey(priv, "servik@"+t.Domain)
	if err != nil {
		return "", err
	}
	sshPub, err := ssh.NewPublicKey(pub)
	if err != nil {
		return "", err
	}
	line := strings.TrimSpace(string(ssh.MarshalAuthorizedKey(sshPub))) + " servik@" + t.Domain
	if _, err := m.userRun(ctx, t, "/", "mkdir", "-p", dir); err != nil {
		return "", failed("creating %s failed", dir)
	}
	if _, err := m.userRun(ctx, t, "/", "chmod", "700", dir); err != nil {
		return "", failed("chmod %s failed", dir)
	}
	if err := m.writeFile(ctx, t, dir+"/id_ed25519", pem.EncodeToMemory(block), "600"); err != nil {
		return "", &OperationError{err}
	}
	if err := m.writeFile(ctx, t, dir+"/id_ed25519.pub", []byte(line+"\n"), "644"); err != nil {
		return "", &OperationError{err}
	}
	return line, nil
}

// RemoveSpec removes an application from a site.
type RemoveSpec struct {
	Target
	// RemoveFiles deletes laravel/ (releases, shared .env and storage)
	// and the application's backups.
	RemoveFiles bool `json:"remove_files"`
}

// Remove stops the application's queue workers and scheduler and forgets
// its logs; with RemoveFiles its laravel/ directory goes too.
func (m *Manager) Remove(ctx context.Context, spec RemoveSpec) (map[string]bool, error) {
	if err := spec.Target.validate(); err != nil {
		return nil, err
	}
	unlock := m.lock(spec.Domain)
	defer unlock()
	if err := m.removeUnits(ctx, spec.Domain); err != nil {
		return nil, &OperationError{err}
	}
	_ = os.RemoveAll(m.h.Path(stateDir + "/" + spec.Domain))
	_ = os.RemoveAll(m.h.Path(logDir + "/" + spec.Domain))
	removed := false
	if spec.RemoveFiles {
		// The application's backups go with its files.
		_ = os.RemoveAll(m.h.Path(backupDir + "/" + spec.Domain))
	}
	if spec.RemoveFiles && m.exists(baseDir(spec.Target)) {
		if _, err := m.userRun(ctx, spec.Target, "/", "rm", "-rf", "--one-file-system", baseDir(spec.Target)); err != nil {
			return nil, failed("removing %s failed", baseDir(spec.Target))
		}
		removed = true
	}
	return map[string]bool{"removed": true, "files_removed": removed}, nil
}
