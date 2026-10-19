package cron

import (
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"syscall"
	"time"
	"unicode/utf8"

	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/site"
)

// Triggers of a run.
const (
	TriggerSchedule = "schedule"
	TriggerManual   = "manual"
)

// Record is one run of a task.
type Record struct {
	// ID names the record (its start, Unix nanoseconds); Runs fills it.
	ID         string    `json:"id,omitempty"`
	StartedAt  time.Time `json:"started_at"`
	FinishedAt time.Time `json:"finished_at"`
	DurationMS int64     `json:"duration_ms"`
	// ExitCode is the command's exit status; 128+N when signal N ended
	// it, -1 when it could not start.
	ExitCode int    `json:"exit_code"`
	Output   string `json:"output"`
	// Truncated is true when only the last MaxOutput bytes were kept.
	Truncated bool `json:"truncated"`
	TimedOut  bool `json:"timed_out"`
	// Skipped is true when the run did not start because the previous
	// one was still going.
	Skipped bool   `json:"skipped"`
	Trigger string `json:"trigger"`
}

// marker is running.json, there while a run goes.
type marker struct {
	PID       int       `json:"pid"`
	StartedAt time.Time `json:"started_at"`
	Trigger   string    `json:"trigger"`
}

// killGrace is how long a timed-out command has between SIGTERM and
// SIGKILL.
var killGrace = 10 * time.Second

// Exec is the command "servik-agent cron-exec [--manual] <domain> <id>".
// The cron daemon starts it as the site's user for scheduled runs, the
// agent (with runuser) for manual ones. It returns the exit status.
func Exec(args []string) int {
	return execute(host.System{}, os.Getuid(), args, os.Stderr)
}

// execute runs a task as uid, the site's user, with files under h.
func execute(h host.Host, uid int, args []string, stderr io.Writer) int {
	manual := false
	if len(args) > 0 && args[0] == "--manual" {
		manual, args = true, args[1:]
	}
	if len(args) != 2 {
		fmt.Fprintln(stderr, "usage: servik-agent cron-exec [--manual] DOMAIN ID")
		return 2
	}
	domain := args[0]
	id, err := strconv.Atoi(args[1])
	if err != nil || id < 1 || site.ValidateDomain(domain) != nil {
		fmt.Fprintln(stderr, "cron-exec: invalid domain or task id")
		return 2
	}
	data, err := os.ReadFile(h.Path(StateFile(domain)))
	if errors.Is(err, os.ErrNotExist) && !manual {
		// The site's tasks were removed after cron read its crontab.
		return 0
	}
	if err != nil {
		fmt.Fprintln(stderr, "cron-exec:", err)
		return 1
	}
	var st Spec
	if err := json.Unmarshal(data, &st); err != nil {
		fmt.Fprintln(stderr, "cron-exec:", err)
		return 1
	}
	u, err := h.LookupUser(st.User)
	if err != nil || u.UID != uid || u.UID == 0 || st.Domain != domain || site.ValidateUser(st.User) != nil {
		fmt.Fprintf(stderr, "cron-exec: runs only as the site's user %s\n", st.User)
		return 1
	}
	task := find(st.Tasks, id)
	if task == nil || !task.Enabled && !manual {
		if manual {
			fmt.Fprintf(stderr, "cron-exec: %s has no task %d\n", domain, id)
			return 1
		}
		return 0
	}
	home := "/home/" + st.User
	path := basePath
	if st.PHP != "" {
		if _, err := os.Stat(h.Path(ShimDir(st.PHP) + "/php")); err == nil {
			path = h.Path(ShimDir(st.PHP)) + ":" + path
		}
	}
	timeout := task.Timeout
	if timeout <= 0 {
		timeout = DefaultTimeout
	}
	j := job{
		dir:     h.Path(TaskDir(st.User, domain, id)),
		cwd:     h.Path(siteDir(st.User, domain)),
		command: task.Command,
		timeout: time.Duration(timeout) * time.Second,
		trigger: TriggerSchedule,
		env: []string{
			"HOME=" + h.Path(home), "USER=" + st.User, "LOGNAME=" + st.User, "SHELL=/bin/sh",
			"LANG=C.UTF-8", "PATH=" + path, "SERVIK_SITE=" + domain,
		},
		now: time.Now,
	}
	if manual {
		j.trigger = TriggerManual
	}
	if err := j.run(); err != nil {
		fmt.Fprintln(stderr, "cron-exec:", err)
		return 1
	}
	return 0
}

// job is one run of a task, with everything on disk paths.
type job struct {
	dir, cwd, command, trigger string
	env                        []string
	timeout                    time.Duration
	now                        func() time.Time
}

// run takes the task's lock (or records a skipped run), runs the
// command and records the run.
func (j job) run() error {
	runs := filepath.Join(j.dir, "runs")
	if err := os.MkdirAll(runs, 0o700); err != nil {
		return err
	}
	lock, err := os.OpenFile(filepath.Join(j.dir, "lock"), os.O_RDWR|os.O_CREATE|syscall.O_NOFOLLOW, 0o600)
	if err != nil {
		return err
	}
	defer lock.Close()
	start := j.now().UTC()
	if err := syscall.Flock(int(lock.Fd()), syscall.LOCK_EX|syscall.LOCK_NB); err != nil {
		if !errors.Is(err, syscall.EWOULDBLOCK) {
			return err
		}
		return saveRecord(runs, Record{
			StartedAt: start, FinishedAt: start, Skipped: true, Trigger: j.trigger,
			Output: "Skipped: the previous run is still going.\n",
		})
	}
	running := filepath.Join(j.dir, "running.json")
	if err := writeJSON(running, marker{PID: os.Getpid(), StartedAt: start, Trigger: j.trigger}); err != nil {
		return err
	}
	defer os.Remove(running)
	rec := j.exec(start)
	return saveRecord(runs, rec)
}

// exec runs the command in its own process group, stopping the whole
// group at the timeout (SIGTERM, SIGKILL after killGrace).
func (j job) exec(start time.Time) Record {
	rec := Record{StartedAt: start, Trigger: j.trigger}
	out := &tail{max: MaxOutput}
	cmd := exec.Command("/bin/sh", "-c", j.command)
	cmd.Dir, cmd.Env = j.cwd, j.env
	cmd.Stdout, cmd.Stderr = out, out
	cmd.SysProcAttr = &syscall.SysProcAttr{Setpgid: true}
	// Background processes the command leaves may keep the output open;
	// the run ends with the command all the same.
	cmd.WaitDelay = time.Second
	finish := func() Record {
		rec.FinishedAt = j.now().UTC()
		rec.DurationMS = rec.FinishedAt.Sub(start).Milliseconds()
		rec.Output, rec.Truncated = out.String(), out.truncated
		return rec
	}
	if err := cmd.Start(); err != nil {
		rec.ExitCode = -1
		fmt.Fprintf(out, "The command could not start: %v\n", err)
		return finish()
	}
	pgid := cmd.Process.Pid
	done := make(chan error, 1)
	go func() { done <- cmd.Wait() }()
	timer := time.NewTimer(j.timeout)
	defer timer.Stop()
	select {
	case <-done:
	case <-timer.C:
		rec.TimedOut = true
		_ = syscall.Kill(-pgid, syscall.SIGTERM)
		deadline := time.Now().Add(killGrace)
		for time.Now().Before(deadline) && syscall.Kill(-pgid, 0) == nil {
			time.Sleep(100 * time.Millisecond)
		}
		_ = syscall.Kill(-pgid, syscall.SIGKILL)
		<-done
	}
	rec.ExitCode = exitCode(cmd.ProcessState)
	if rec.TimedOut {
		fmt.Fprintf(out, "\nStopped after the timeout of %s.\n", j.timeout)
	}
	return finish()
}

func exitCode(ps *os.ProcessState) int {
	if ps == nil {
		return -1
	}
	if ws, ok := ps.Sys().(syscall.WaitStatus); ok && ws.Signaled() {
		return 128 + int(ws.Signal())
	}
	return ps.ExitCode()
}

// tail keeps the last max bytes written to it.
type tail struct {
	max       int
	buf       []byte
	truncated bool
}

func (t *tail) Write(p []byte) (int, error) {
	t.buf = append(t.buf, p...)
	if len(t.buf) > t.max {
		t.buf = append(t.buf[:0], t.buf[len(t.buf)-t.max:]...)
		t.truncated = true
	}
	return len(p), nil
}

// String is the output kept, starting at a whole character.
func (t *tail) String() string {
	b := t.buf
	if t.truncated {
		for i := 0; i < utf8.UTFMax && len(b) > 0 && !utf8.RuneStart(b[0]); i++ {
			b = b[1:]
		}
	}
	return string(b)
}

// saveRecord writes a run record into runs and keeps the newest
// RunsKept.
func saveRecord(runs string, rec Record) error {
	name := strconv.FormatInt(rec.StartedAt.UnixNano(), 10) + ".json"
	if err := writeJSON(filepath.Join(runs, name), rec); err != nil {
		return err
	}
	entries, err := os.ReadDir(runs)
	if err != nil {
		return err
	}
	var names []string
	for _, e := range entries {
		names = append(names, e.Name())
	}
	sorted := sortRecords(names)
	for i := RunsKept; i < len(sorted); i++ {
		_ = os.Remove(filepath.Join(runs, sorted[i]))
	}
	return nil
}

// writeJSON writes v to p atomically, readable by its owner only.
func writeJSON(p string, v any) error {
	data, err := json.Marshal(v)
	if err != nil {
		return err
	}
	tmp, err := os.CreateTemp(filepath.Dir(p), ".tmp-*")
	if err != nil {
		return err
	}
	defer os.Remove(tmp.Name())
	if _, err := tmp.Write(append(data, '\n')); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Chmod(0o600); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Close(); err != nil {
		return err
	}
	return os.Rename(tmp.Name(), p)
}
