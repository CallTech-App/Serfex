package host

import (
	"crypto/sha1"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"time"
)

// Tools the Laravel toolkit runs as the site's user, simulated well
// enough for dry runs: file commands act under Root, "git clone" and
// "composer create-project" write a small Laravel application, Artisan's
// down and up switch maintenance mode, and the panel's probe script
// answers from the application's .servik-sim directory.

var phpBinary = regexp.MustCompile(`^php[5-9]\.[0-9]{1,2}$`)

// runTool simulates the commands not handled in Run. Unknown commands
// succeed without output.
func (s *Simulator) runTool(c Command, cwd string, env map[string]string) (string, error) {
	args := c.Args
	switch {
	case c.Name == "mkdir":
		for _, d := range s.abs(cwd, args) {
			if !strings.HasPrefix(d, "-") {
				if err := os.MkdirAll(s.Path(d), 0o755); err != nil {
					return "", err
				}
			}
		}
	case c.Name == "ln":
		// ln -s TARGET LINK
		if len(args) != 3 || args[0] != "-s" {
			return "", fmt.Errorf("ln: unsupported arguments %v", args)
		}
		link := s.abs(cwd, args[2:])[0]
		return "", os.Symlink(args[1], s.Path(link))
	case c.Name == "mv":
		// mv -T SRC DST
		if len(args) != 3 || args[0] != "-T" {
			return "", fmt.Errorf("mv: unsupported arguments %v", args)
		}
		p := s.abs(cwd, args[1:])
		return "", os.Rename(s.Path(p[0]), s.Path(p[1]))
	case c.Name == "cp":
		// cp -a SRC DST
		if len(args) != 3 || args[0] != "-a" {
			return "", fmt.Errorf("cp: unsupported arguments %v", args)
		}
		p := s.abs(cwd, args[1:])
		return "", copyTree(s.Path(p[0]), s.Path(p[1]))
	case c.Name == "chmod":
		if len(args) != 2 {
			return "", fmt.Errorf("chmod: unsupported arguments %v", args)
		}
		mode, err := strconv.ParseUint(args[0], 8, 32)
		if err != nil {
			return "", err
		}
		return "", os.Chmod(s.Path(s.abs(cwd, args[1:])[0]), os.FileMode(mode))
	case c.Name == "cat":
		if len(args) != 1 {
			return "", fmt.Errorf("cat: unsupported arguments %v", args)
		}
		b, err := os.ReadFile(s.Path(s.abs(cwd, args)[0]))
		if c.Stdout != nil && err == nil {
			_, err = c.Stdout.Write(b)
			return "", err
		}
		return string(b), err
	case c.Name == "tar":
		return s.tar(c, cwd)
	case c.Name == "mysqldump" || c.Name == "pg_dump":
		if c.Stdout != nil {
			fmt.Fprintf(c.Stdout, "-- Simulated %s output\nCREATE TABLE users (id integer primary key, name text);\nINSERT INTO users VALUES (1, 'Servik');\n", c.Name)
		}
	case c.Name == "mysql" || c.Name == "psql":
		if c.Input != nil {
			_, _ = io.Copy(io.Discard, c.Input)
		}
	case c.Name == "tail" || c.Name == "head":
		// tail -c N FILE, head -c N FILE
		if len(args) != 3 || args[0] != "-c" {
			return "", fmt.Errorf("%s: unsupported arguments %v", c.Name, args)
		}
		n, err := strconv.Atoi(args[1])
		if err != nil {
			return "", err
		}
		b, err := os.ReadFile(s.Path(s.abs(cwd, args[2:])[0]))
		if err != nil {
			return "", err
		}
		if len(b) > n {
			if c.Name == "tail" {
				b = b[len(b)-n:]
			} else {
				b = b[:n]
			}
		}
		return string(b), nil
	case c.Name == "git":
		return s.git(args, cwd)
	case c.Name == "dd":
		// dd of=FILE status=none (stdin is written to FILE)
		for _, a := range args {
			if f, ok := strings.CutPrefix(a, "of="); ok {
				data := c.Stdin
				if c.Input != nil {
					var err error
					if data, err = io.ReadAll(c.Input); err != nil {
						return "", err
					}
				}
				return "", os.WriteFile(s.Path(s.abs(cwd, []string{f})[0]), data, 0o644)
			}
		}
		return "", fmt.Errorf("dd: unsupported arguments %v", args)
	case phpBinary.MatchString(filepath.Base(c.Name)):
		return s.php(c, cwd, env)
	case c.Name == "systemctl" && len(args) > 1 && (args[0] == "enable" || args[0] == "disable"):
		// Enabling a unit links it into its target's .wants directory.
		for _, u := range args[1:] {
			if strings.HasPrefix(u, "-") {
				continue
			}
			wants := "/etc/systemd/system/multi-user.target.wants/"
			if strings.HasSuffix(u, ".timer") {
				wants = "/etc/systemd/system/timers.target.wants/"
			}
			if !strings.HasSuffix(u, ".service") && !strings.HasSuffix(u, ".timer") {
				u += ".service"
			}
			link := s.Path(wants + u)
			_ = os.Remove(link)
			if args[0] == "enable" {
				template := u
				if at := strings.Index(u, "@"); at >= 0 {
					template = u[:at+1] + u[strings.LastIndex(u, "."):]
				}
				if err := os.MkdirAll(filepath.Dir(link), 0o755); err != nil {
					return "", err
				}
				if err := os.Symlink("../"+template, link); err != nil {
					return "", err
				}
			}
		}
	case c.Name == "systemctl" && len(args) > 1 && args[0] == "is-active":
		// Servik's own Laravel units are always running in a dry run.
		var b strings.Builder
		for _, u := range args[1:] {
			switch {
			case strings.HasPrefix(u, "servik-queue-") || strings.HasPrefix(u, "servik-schedule-"):
				b.WriteString("active\n")
			case strings.HasPrefix(u, "servik-proc-"):
				// A process runs while it is enabled.
				if _, err := os.Lstat(s.Path("/etc/systemd/system/multi-user.target.wants/" + u)); err == nil {
					b.WriteString("active\n")
				} else {
					b.WriteString("inactive\n")
				}
			}
		}
		return b.String(), nil
	}
	return "", nil
}

func (s *Simulator) git(args []string, cwd string) (string, error) {
	if len(args) > 1 && args[0] == "-C" {
		cwd, args = args[1], args[2:]
	}
	if len(args) == 0 {
		return "", nil
	}
	switch args[0] {
	case "clone":
		dir := s.abs(cwd, args[len(args)-1:])[0]
		return "Cloning into '" + filepath.Base(dir) + "'...\n", s.writeLaravelApp(dir)
	case "rev-parse":
		sum := sha1.Sum([]byte(cwd))
		return hex.EncodeToString(sum[:]) + "\n", nil
	case "log":
		return "Update dependencies\n", nil
	}
	return "", nil
}

func (s *Simulator) php(c Command, cwd string, env map[string]string) (string, error) {
	args := c.Args
	if len(args) == 0 {
		// The panel's probe script on stdin: answer from .servik-sim.
		b, err := os.ReadFile(s.Path(filepath.Join(cwd, ".servik-sim", env["SERVIK_PROBE"]+".json")))
		if err != nil {
			b = []byte("null")
		}
		return "SERVIK-PROBE:" + strings.TrimSpace(string(b)) + "\n", nil
	}
	if strings.HasSuffix(args[0], "composer.phar") {
		if len(args) > 1 && args[1] == "create-project" {
			dir := s.abs(cwd, args[len(args)-1:])[0]
			return "Creating a project at \"" + dir + "\"\n", s.writeLaravelApp(dir)
		}
		if len(args) > 1 && args[1] == "--version" {
			return "Composer version 2.8.12 2025-09-19 13:41:59\n", nil
		}
		return "Installing dependencies from lock file\nGenerating optimized autoload files\n", nil
	}
	if args[0] != "artisan" || len(args) < 2 {
		return "", nil
	}
	down := s.Path(filepath.Join(cwd, "storage/framework/down"))
	switch args[1] {
	case "down":
		state := map[string]any{"except": []string{}, "retry": nil, "secret": nil, "status": 503}
		for _, a := range args[2:] {
			if v, ok := strings.CutPrefix(a, "--secret="); ok {
				state["secret"] = v
			}
			if v, ok := strings.CutPrefix(a, "--retry="); ok {
				n, _ := strconv.Atoi(v)
				state["retry"] = n
			}
		}
		b, _ := json.Marshal(state)
		if err := os.MkdirAll(filepath.Dir(down), 0o755); err != nil {
			return "", err
		}
		return "   INFO  Application is now in maintenance mode.\n", os.WriteFile(down, b, 0o644)
	case "up":
		_ = os.Remove(down)
		return "   INFO  Application is now live.\n", nil
	case "migrate":
		return "   INFO  Nothing to migrate.\n", nil
	case "optimize":
		return "   INFO  Caching framework bootstrap, configuration, and metadata.\n\n  config .... 12.40ms DONE\n  events ..... 1.20ms DONE\n  routes ..... 8.10ms DONE\n  views ..... 21.30ms DONE\n", nil
	case "--version":
		return "Laravel Framework 13.6.0\n", nil
	}
	return "", nil
}

// writeLaravelApp writes a minimal Laravel 13 application to dir.
func (s *Simulator) writeLaravelApp(dir string) error {
	now := time.Now().UTC()
	files := map[string]string{
		"artisan":                    "#!/usr/bin/env php\n<?php\n",
		"composer.json":              `{"name":"laravel/laravel","require":{"php":"^8.3","laravel/framework":"^13.0"}}`,
		"composer.lock":              `{"packages":[{"name":"laravel/framework","version":"v13.6.0"},{"name":"laravel/horizon","version":"v5.40.1"},{"name":"laravel/reverb","version":"v1.6.2"}],"packages-dev":[]}`,
		"package.json":               `{"private":true,"type":"module","scripts":{"build":"vite build"}}`,
		"package-lock.json":          `{}`,
		"public/index.php":           "<?php\n",
		"routes/console.php":         "<?php\n",
		"bootstrap/app.php":          "<?php\n",
		"bootstrap/cache/.gitignore": "*\n",
		".env.example": "APP_NAME=Laravel\nAPP_ENV=local\nAPP_KEY=\nAPP_DEBUG=true\nAPP_URL=http://localhost\n\nLOG_CHANNEL=stack\nLOG_STACK=single\nLOG_LEVEL=debug\n\n" +
			"DB_CONNECTION=sqlite\n# DB_HOST=127.0.0.1\n# DB_PORT=3306\n# DB_DATABASE=laravel\n# DB_USERNAME=root\n# DB_PASSWORD=\n\n" +
			"SESSION_DRIVER=database\nQUEUE_CONNECTION=database\nCACHE_STORE=database\n\nMAIL_MAILER=log\n",
		"storage/logs/laravel.log": "[2026-10-18 23:38:41] production.INFO: Deployment finished.\n" +
			"[2026-10-18 23:47:12] production.ERROR: Illuminate\\Http\\Client\\ConnectionException: cURL error 28: Connection timed out after 10001 ms {\"exception\":\"[object] (Illuminate\\\\Http\\\\Client\\\\ConnectionException(code: 0))\"}\n" +
			"[stacktrace]\n#0 app/Services/Carrier/GatewayClient.php(84): Illuminate\\Http\\Client\\PendingRequest->send()\n#1 app/Jobs/SyncCarrierStatus.php(41): App\\Services\\Carrier\\GatewayClient->status()\n" +
			"[2026-10-18 23:48:03] production.WARNING: Slow query (1840 ms): select * from shipments where carrier_id = ?\n",
		"storage/framework/views/.gitignore": "*\n",
		".servik-sim/about.json":             `{"laravel":"13.6.0","php":"8.4.0","environment":"production","debug":false,"queue":"database","cache":"database","database":"sqlite","session":"database"}`,
		".servik-sim/queues.json":            `{"sizes":[{"connection":"","queue":"default","size":3}],"failed":2}`,
		".servik-sim/failed.json": `[{"id":"9b2f1c3e-6a1d-4f5e-8a7b-0c9d8e7f6a5b","connection":"database","queue":"default","job":"App\\Jobs\\SyncCarrierStatus","error":"Illuminate\\Http\\Client\\ConnectionException: Connection timed out","failed_at":"2026-10-18 23:12:04"},` +
			`{"id":"1e4a7b0c-3d6f-4a2b-9c8d-7e6f5a4b3c2d","connection":"database","queue":"default","job":"App\\Jobs\\GenerateManifestPdf","error":"Allowed memory size of 268435456 bytes exhausted","failed_at":"2026-10-18 22:47:55"}]`,
		".servik-sim/schedule.json": fmt.Sprintf(`[{"command":"inspire","description":"","expression":"0 * * * *","next":%q,"timezone":"UTC"},`+
			`{"command":"shipments:sync-carriers","description":"","expression":"*/5 * * * *","next":%q,"timezone":"UTC"},`+
			`{"command":"reports:daily","description":"Günlük rapor","expression":"0 2 * * *","next":%q,"timezone":"UTC"}]`,
			now.Truncate(time.Hour).Add(time.Hour).Format(time.RFC3339), now.Truncate(5*time.Minute).Add(5*time.Minute).Format(time.RFC3339),
			time.Date(now.Year(), now.Month(), now.Day()+1, 2, 0, 0, 0, time.UTC).Format(time.RFC3339)),
	}
	for name, content := range files {
		p := s.Path(filepath.Join(dir, name))
		if err := os.MkdirAll(filepath.Dir(p), 0o755); err != nil {
			return err
		}
		if err := os.WriteFile(p, []byte(content), 0o644); err != nil {
			return err
		}
	}
	return nil
}

func copyTree(src, dst string) error {
	return filepath.Walk(src, func(p string, fi os.FileInfo, err error) error {
		if err != nil {
			return err
		}
		rel, _ := filepath.Rel(src, p)
		target := filepath.Join(dst, rel)
		switch {
		case fi.IsDir():
			return os.MkdirAll(target, fi.Mode().Perm())
		case fi.Mode()&os.ModeSymlink != 0:
			l, err := os.Readlink(p)
			if err != nil {
				return err
			}
			return os.Symlink(l, target)
		default:
			b, err := os.ReadFile(p)
			if err != nil {
				return err
			}
			return os.WriteFile(target, b, fi.Mode().Perm())
		}
	})
}

// Start simulates a Tinker session: every line of input gets an answer.
func (s *Simulator) Start(c Command, output io.Writer) (Process, error) {
	s.mu.Lock()
	s.commands = append(s.commands, c)
	s.mu.Unlock()
	if s.Fail != nil {
		if err := s.Fail(c); err != nil {
			return nil, err
		}
	}
	p := &simProcess{out: output, done: make(chan struct{})}
	fmt.Fprint(p.out, "Psy Shell v0.12.24 (PHP 8.4.0 — cli) by Justin Hileman\n")
	return p, nil
}

type simProcess struct {
	mu   sync.Mutex
	out  io.Writer
	done chan struct{}
	once sync.Once
}

// simAnswers are the dry run's answers to a few typical expressions.
var simAnswers = []struct{ match, answer string }{
	{"::count()", "=>  1284"},
	{"config('queue.default')", `=>  "database"`},
	{"config(", `=>  "production"`},
	{"now()", "=>  Illuminate\\Support\\Carbon @1792364400 {#6201\n     date: 2026-10-18 23:40:00.0 UTC (+00:00),\n   }"},
	{"app()->version()", `=>  "13.6.0"`},
}

func (p *simProcess) Write(b []byte) (int, error) {
	p.mu.Lock()
	defer p.mu.Unlock()
	select {
	case <-p.done:
		return 0, io.ErrClosedPipe
	default:
	}
	for _, line := range strings.Split(strings.TrimSpace(string(b)), "\n") {
		answer := "=>  null"
		for _, a := range simAnswers {
			if strings.Contains(line, a.match) {
				answer = a.answer
				break
			}
		}
		fmt.Fprintln(p.out, answer)
	}
	return len(b), nil
}

func (p *simProcess) Wait() error {
	<-p.done
	return nil
}

func (p *simProcess) Kill() error {
	p.once.Do(func() { close(p.done) })
	return nil
}

// tar runs the real tar on the simulated tree: "-C DIR" is mapped under
// Root, the archive is read from Input or written to Stdout.
func (s *Simulator) tar(c Command, cwd string) (string, error) {
	args := append([]string(nil), c.Args...)
	for i := 0; i < len(args)-1; i++ {
		if args[i] == "-C" {
			args[i+1] = s.Path(s.abs(cwd, args[i+1:i+2])[0])
		}
	}
	cmd := exec.Command("tar", args...)
	cmd.Stdin, cmd.Stdout = c.Input, c.Stdout
	var stderr strings.Builder
	cmd.Stderr = &stderr
	if err := cmd.Run(); err != nil {
		return stderr.String(), err
	}
	return stderr.String(), nil
}
