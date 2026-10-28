package host

import (
	"bytes"
	"crypto/sha1"
	"encoding/hex"
	"encoding/json"
	"errors"
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
// answers from the application's .serfex-sim directory.

var phpBinary = regexp.MustCompile(`^php[5-9]\.[0-9]{1,2}$`)

var simPGServer = regexp.MustCompile(`^postgresql-(\d+)$`)

var simPGVector = regexp.MustCompile(`^postgresql-(\d+)-pgvector$`)

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
			fmt.Fprintf(c.Stdout, "-- Simulated %s output\nCREATE TABLE users (id integer primary key, name text);\nINSERT INTO users VALUES (1, 'Serfex');\n", c.Name)
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
	case c.Name == "postqueue" || c.Name == "postsuper":
		return s.postfixQueue(c)
	case c.Name == "git":
		return s.git(args, cwd)
	case c.Name == "xargs":
		return s.xargs(c, cwd)
	case c.Name == "apt-cache":
		return s.aptCache(args), nil
	case (c.Name == "pg_createcluster" || c.Name == "pg_dropcluster") && len(args) >= 2:
		// pg_createcluster VERSION NAME, pg_dropcluster [--stop] VERSION NAME
		dir := "/etc/postgresql/" + args[len(args)-2] + "/" + args[len(args)-1]
		if c.Name == "pg_dropcluster" {
			return "", os.RemoveAll(s.Path(dir))
		}
		if err := os.MkdirAll(s.Path(dir+"/conf.d"), 0o755); err != nil {
			return "", err
		}
		return "", os.WriteFile(s.Path(dir+"/postgresql.conf"), nil, 0o644)
	case c.Name == "pg_upgradecluster" && len(args) >= 4 && args[0] == "-v":
		// pg_upgradecluster -v NEW [OPTIONS] OLD NAME: the new cluster gets
		// the old one's settings; the old one moves to another port and
		// starts only by hand.
		oldDir := "/etc/postgresql/" + args[len(args)-2] + "/" + args[len(args)-1]
		newDir := "/etc/postgresql/" + args[1] + "/" + args[len(args)-1]
		conf, err := os.ReadFile(s.Path(oldDir + "/postgresql.conf"))
		if err != nil {
			return "", err
		}
		if _, err := os.Stat(s.Path(newDir)); err == nil {
			return "", fmt.Errorf("target cluster %s already exists", newDir)
		}
		if err := os.MkdirAll(s.Path(newDir+"/conf.d"), 0o755); err != nil {
			return "", err
		}
		if err := os.WriteFile(s.Path(newDir+"/postgresql.conf"), conf, 0o644); err != nil {
			return "", err
		}
		if err := os.WriteFile(s.Path(oldDir+"/postgresql.conf"), append(conf, "port = 5434\n"...), 0o644); err != nil {
			return "", err
		}
		return "", os.WriteFile(s.Path(oldDir+"/start.conf"), []byte("manual\n"), 0o644)
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
		// Serfex's own Laravel units are always running in a dry run.
		var b strings.Builder
		for _, u := range args[1:] {
			switch {
			case strings.HasPrefix(u, "serfex-queue-") || strings.HasPrefix(u, "serfex-schedule-"):
				b.WriteString("active\n")
			case strings.HasPrefix(u, "serfex-proc-"):
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

// SimMailQueue holds the simulated Postfix queue, one "postqueue -j"
// line per message. "postsuper -h -" and "-H -" move the messages named on
// standard input to and from the hold queue.
const SimMailQueue = "/var/spool/postfix/serfex-sim-queue.jsonl"

func (s *Simulator) postfixQueue(c Command) (string, error) {
	b, err := os.ReadFile(s.Path(SimMailQueue))
	if errors.Is(err, os.ErrNotExist) {
		return "", nil
	}
	if err != nil {
		return "", err
	}
	if c.Name == "postqueue" {
		if len(c.Args) > 0 && c.Args[0] == "-j" {
			return string(b), nil
		}
		return "", nil
	}
	if len(c.Args) != 2 || c.Args[1] != "-" || (c.Args[0] != "-h" && c.Args[0] != "-H") {
		return "", fmt.Errorf("postsuper: unsupported arguments %v", c.Args)
	}
	ids := map[string]bool{}
	for _, id := range strings.Fields(string(c.Stdin)) {
		ids[id] = true
	}
	var out []string
	for _, line := range strings.Split(strings.TrimSpace(string(b)), "\n") {
		var m map[string]any
		if json.Unmarshal([]byte(line), &m) != nil {
			continue
		}
		if id, _ := m["queue_id"].(string); ids[id] {
			if c.Args[0] == "-h" {
				m["queue_name"] = "hold"
			} else {
				m["queue_name"] = "deferred"
			}
		}
		nb, _ := json.Marshal(m)
		out = append(out, string(nb))
	}
	return "", os.WriteFile(s.Path(SimMailQueue), []byte(strings.Join(out, "\n")+"\n"), 0o644)
}

func (s *Simulator) git(args []string, cwd string) (string, error) {
	if len(args) > 1 && args[0] == "-C" {
		cwd, args = args[1], args[2:]
	}
	if len(args) > 0 && (strings.HasPrefix(args[0], "--git-dir=") || args[0] == "init") {
		return s.siteGit(args, cwd)
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

// simCommits are the messages of the commits a simulated fetch brings,
// in turn.
var simCommits = []string{"Update the home page", "Fix the contact form on mobile", "Add the autumn campaign banner", "Compress product images"}

// siteGit simulates a Git deploy of a plain site: refs and settings are
// files in the --git-dir, each fetch brings a new commit, and a checkout
// writes a small static site into the --work-tree.
func (s *Simulator) siteGit(args []string, cwd string) (string, error) {
	dir, work := "", ""
opts:
	for len(args) > 0 {
		switch {
		case strings.HasPrefix(args[0], "--git-dir="):
			dir, args = s.Path(args[0][len("--git-dir="):]), args[1:]
		case strings.HasPrefix(args[0], "--work-tree="):
			work, args = s.Path(args[0][len("--work-tree="):]), args[1:]
		case args[0] == "-c" && len(args) > 1:
			args = args[2:]
		default:
			break opts
		}
	}
	if len(args) == 0 {
		return "", nil
	}
	file := func(name string) string {
		return filepath.Join(dir, "sim", strings.NewReplacer("/", "_", "^{commit}", "").Replace(name))
	}
	write := func(name, value string) error {
		if err := os.MkdirAll(filepath.Join(dir, "sim"), 0o755); err != nil {
			return err
		}
		return os.WriteFile(file(name), []byte(value), 0o644)
	}
	read := func(name string) (string, error) {
		b, err := os.ReadFile(file(name))
		return string(b), err
	}
	last := args[len(args)-1]
	switch args[0] {
	case "init":
		repo := s.Path(s.abs(cwd, []string{last})[0])
		if err := os.MkdirAll(repo, 0o755); err != nil {
			return "", err
		}
		return "", os.WriteFile(filepath.Join(repo, "HEAD"), []byte("ref: refs/heads/main\n"), 0o644)
	case "config":
		if args[1] == "--get" {
			v, err := read("config-" + args[2])
			if err != nil {
				return "", errors.New("exit status 1")
			}
			return v + "\n", nil
		}
		return "", write("config-"+args[1], args[2])
	case "fetch":
		n := 0
		if v, err := read("count"); err == nil {
			n, _ = strconv.Atoi(v)
		}
		n++
		sum := sha1.Sum([]byte(fmt.Sprintf("commit-%d-%s", n, dir)))
		sha := hex.EncodeToString(sum[:])
		_, ref, _ := strings.Cut(last, ":")
		for name, value := range map[string]string{"count": strconv.Itoa(n), ref: sha, "commit-" + sha: simCommits[(n-1)%len(simCommits)]} {
			if err := write(name, value); err != nil {
				return "", err
			}
		}
		remote, _ := read("config-remote.origin.url")
		return "From " + remote + "\n * [new branch]      " + strings.TrimPrefix(ref, "refs/remotes/origin/") + " -> origin/" + strings.TrimPrefix(ref, "refs/remotes/origin/") + "\n", nil
	case "rev-parse":
		ref := strings.TrimSuffix(last, "^{commit}")
		if _, err := read("commit-" + ref); err == nil {
			return ref + "\n", nil
		}
		v, err := read(ref)
		if err != nil {
			return "", errors.New("exit status 1")
		}
		return v + "\n", nil
	case "cat-file":
		if _, err := read("commit-" + strings.TrimSuffix(last, "^{commit}")); err != nil {
			return "", errors.New("exit status 1")
		}
	case "update-ref":
		if args[1] == "-d" {
			_ = os.Remove(file(last))
			return "", nil
		}
		return "", write(args[1], args[2])
	case "log":
		msg, _ := read("commit-" + last)
		return msg + "\nSerfex\n", nil
	case "ls-tree":
		return "index.html\x00assets/site.css\x00", nil
	case "diff":
		return " 2 files changed, 6 insertions(+), 2 deletions(-)\n", nil
	case "checkout":
		msg, _ := read("commit-" + last)
		if err := os.MkdirAll(filepath.Join(work, "assets"), 0o755); err != nil {
			return "", err
		}
		page := "<!doctype html>\n<title>" + msg + "</title>\n<link rel=\"stylesheet\" href=\"assets/site.css\">\n<h1>" + msg + "</h1>\n"
		if err := os.WriteFile(filepath.Join(work, "index.html"), []byte(page), 0o644); err != nil {
			return "", err
		}
		return "", os.WriteFile(filepath.Join(work, "assets/site.css"), []byte("body { font-family: sans-serif; }\n"), 0o644)
	}
	return "", nil
}

// xargs simulates "xargs -0 rm -f --" and "xargs -0 rmdir ...", the way
// a Git deploy is undone: the names come on standard input.
func (s *Simulator) xargs(c Command, cwd string) (string, error) {
	if len(c.Args) < 2 || c.Args[0] != "-0" {
		return "", fmt.Errorf("xargs: unsupported arguments %v", c.Args)
	}
	for _, name := range strings.Split(string(c.Stdin), "\x00") {
		if name == "" {
			continue
		}
		p := s.Path(s.abs(cwd, []string{name})[0])
		if c.Args[1] == "rmdir" {
			_ = os.Remove(p)
		} else {
			_ = os.RemoveAll(p)
		}
	}
	return "", nil
}

func (s *Simulator) php(c Command, cwd string, env map[string]string) (string, error) {
	args := c.Args
	for i, a := range args {
		if strings.HasSuffix(a, "wp-cli.phar") {
			return s.wpCLI(c, cwd, args[i+1:])
		}
	}
	if len(args) == 0 {
		// The panel's probe script on stdin: answer from .serfex-sim.
		b, err := os.ReadFile(s.Path(filepath.Join(cwd, ".serfex-sim", env["SERFEX_PROBE"]+".json")))
		if err != nil {
			b = []byte("null")
		}
		return "SERFEX-PROBE:" + strings.TrimSpace(string(b)) + "\n", nil
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
		".serfex-sim/about.json":             `{"laravel":"13.6.0","php":"8.4.0","environment":"production","debug":false,"queue":"database","cache":"database","database":"sqlite","session":"database"}`,
		".serfex-sim/queues.json":            `{"sizes":[{"connection":"","queue":"default","size":3}],"failed":2,"default":"database","connections":["sync","database","beanstalkd","sqs","redis","deferred","background","failover"]}`,
		".serfex-sim/failed.json": `[{"id":"9b2f1c3e-6a1d-4f5e-8a7b-0c9d8e7f6a5b","connection":"database","queue":"default","job":"App\\Jobs\\SyncCarrierStatus","error":"Illuminate\\Http\\Client\\ConnectionException: Connection timed out","failed_at":"2026-10-18 23:12:04"},` +
			`{"id":"1e4a7b0c-3d6f-4a2b-9c8d-7e6f5a4b3c2d","connection":"database","queue":"default","job":"App\\Jobs\\GenerateManifestPdf","error":"Allowed memory size of 268435456 bytes exhausted","failed_at":"2026-10-18 22:47:55"}]`,
		".serfex-sim/schedule.json": fmt.Sprintf(`[{"key":"3f1b2c4d5e6f7a80","command":"inspire","description":"","expression":"0 * * * *","next":%q,"timezone":"UTC"},`+
			`{"key":"9c8b7a6f5e4d3c21","command":"shipments:sync-carriers","description":"","expression":"*/5 * * * *","next":%q,"timezone":"UTC"},`+
			`{"key":"5a4b3c2d1e0f9a8b","command":"reports:daily","description":"Günlük rapor","expression":"0 2 * * *","next":%q,"timezone":"UTC"}]`,
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
	for i := 0; i < len(args); i++ {
		if args[i] == "-C" && i+1 < len(args) {
			args[i+1] = s.Path(s.abs(cwd, args[i+1:i+2])[0])
		}
		if (args[i] == "-cf" || args[i] == "-xf") && i+1 < len(args) && strings.HasPrefix(args[i+1], "/") {
			args[i+1] = s.Path(args[i+1])
		}
		if f, ok := strings.CutPrefix(args[i], "--listed-incremental="); ok && f != "/dev/null" {
			args[i] = "--listed-incremental=" + s.Path(f)
		}
	}
	cmd := exec.Command("tar", args...)
	cmd.Stdin, cmd.Stdout = c.Input, c.Stdout
	if c.Input == nil && c.Stdin != nil {
		cmd.Stdin = bytes.NewReader(c.Stdin)
	}
	var stderr strings.Builder
	cmd.Stderr = &stderr
	if err := cmd.Run(); err != nil {
		return stderr.String(), err
	}
	return stderr.String(), nil
}

// aptCache answers "apt-cache [-o OPT]... pkgnames postgresql-" and
// "apt-cache [-o OPT]... policy PKG..." from the archives listed under
// /etc/apt/sources.list.d (or the directory a probe names through
// Dir::Etc::SourceParts, without Ubuntu's own): Ubuntu 24.04 has MySQL
// 8.0, MariaDB 10.11 and PostgreSQL 16; the PostgreSQL archive every
// version up to 18, Oracle's and MariaDB's archives the major version of
// their listed component.
func (s *Simulator) aptCache(args []string) string {
	parts, ubuntu := "/etc/apt/sources.list.d", true
	for len(args) > 1 && args[0] == "-o" {
		if dir, ok := strings.CutPrefix(args[1], "Dir::Etc::SourceParts="); ok {
			parts, ubuntu = dir, false
		}
		args = args[2:]
	}
	var pgdg bool
	var mysql, mariadb string
	files, _ := filepath.Glob(s.Path(parts + "/*.sources"))
	for _, f := range files {
		b, _ := os.ReadFile(f)
		src := string(b)
		switch {
		case strings.Contains(src, "apt.postgresql.org"):
			pgdg = true
		case strings.Contains(src, "repo.mysql.com"):
			if m := regexp.MustCompile(`Components: mysql-([0-9.]+)-lts`).FindStringSubmatch(src); m != nil {
				mysql = m[1]
			}
		case strings.Contains(src, "dlm.mariadb.com"):
			if m := regexp.MustCompile(`mariadb-server/([0-9.]+)/repo`).FindStringSubmatch(src); m != nil {
				mariadb = m[1]
			}
		}
	}
	if len(args) == 0 {
		return ""
	}
	switch {
	case len(args) == 2 && args[0] == "pkgnames" && args[1] == "postgresql-":
		names := "postgresql-16\npostgresql-client-16\npostgresql-common\n"
		if pgdg {
			names += "postgresql-14\npostgresql-15\npostgresql-17\npostgresql-18\npostgresql-client-18\n"
		}
		return names
	case args[0] == "policy":
		var b strings.Builder
		for _, p := range args[1:] {
			v, from := "", ""
			switch {
			case pgdg && simPGServer.MatchString(p):
				v, from = strings.TrimPrefix(p, "postgresql-")+".1-1.pgdg24.04+2", "https://apt.postgresql.org/pub/repos/apt noble-pgdg/main"
			case p == "mysql-server" && mysql != "":
				v, from = mysql+".0-1ubuntu24.04", "https://repo.mysql.com/apt/ubuntu noble/mysql-"+mysql+"-lts"
			case p == "mariadb-server" && mariadb != "":
				v, from = "1:"+mariadb+".1+maria~ubu2404", "https://dlm.mariadb.com/repo/mariadb-server/"+mariadb+"/repo/ubuntu noble/main"
			case !ubuntu:
			case p == "postgresql-16":
				v, from = "16.10-0ubuntu0.24.04.1", "http://archive.ubuntu.com/ubuntu noble-updates/main"
			case p == "mysql-server":
				v, from = "8.0.46-0ubuntu0.24.04.4", "http://archive.ubuntu.com/ubuntu noble-updates/main"
			case p == "mariadb-server":
				v, from = "1:10.11.14-0ubuntu0.24.04.1", "http://archive.ubuntu.com/ubuntu noble-updates/universe"
			}
			if v == "" {
				fmt.Fprintf(&b, "%s:\n  Installed: (none)\n  Candidate: (none)\n  Version table:\n", p)
				continue
			}
			fmt.Fprintf(&b, "%s:\n  Installed: (none)\n  Candidate: %s\n  Version table:\n     %s 500\n        500 %s amd64 Packages\n", p, v, v, from)
		}
		return b.String()
	}
	return ""
}

func (s *Simulator) exists(abs string) bool {
	_, err := os.Stat(s.Path(abs))
	return err == nil
}
