package cron

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
	"time"

	"github.com/winchester37/servik/agent/internal/host"
)

// The run records live in the site user's home, which the user may
// change at will (a symlink to /etc/shadow instead of a record, a
// folder swapped for a link while the agent reads). The agent reads
// them as root, so it opens every folder below the home relative to its
// parent with O_NOFOLLOW (a link fails instead of being followed, even
// one that appears halfway), checks each file is a regular file the
// user owns, and reads at most maxRecord bytes of it.

const openDirFlags = syscall.O_RDONLY | syscall.O_DIRECTORY | syscall.O_NOFOLLOW | syscall.O_CLOEXEC

// openBelow opens the folder home/parts... without following links.
// Unless owner is negative, every folder below home must belong to it.
func openBelow(home string, parts []string, owner int) (*os.File, error) {
	fd, err := syscall.Open(home, openDirFlags, 0)
	if err != nil {
		return nil, &os.PathError{Op: "open", Path: home, Err: err}
	}
	p := home
	for _, part := range parts {
		p += "/" + part
		next, err := syscall.Openat(fd, part, openDirFlags, 0)
		syscall.Close(fd)
		if err != nil {
			return nil, &os.PathError{Op: "open", Path: p, Err: err}
		}
		fd = next
		if err := checkOwner(fd, p, owner, true); err != nil {
			syscall.Close(fd)
			return nil, err
		}
	}
	return os.NewFile(uintptr(fd), p), nil
}

// checkOwner checks what fd is open on: a folder or a regular file, as
// wanted, belonging to owner (unless owner is negative).
func checkOwner(fd int, p string, owner int, dir bool) error {
	var st syscall.Stat_t
	if err := syscall.Fstat(fd, &st); err != nil {
		return &os.PathError{Op: "stat", Path: p, Err: err}
	}
	kind := uint32(syscall.S_IFREG)
	if dir {
		kind = syscall.S_IFDIR
	}
	if uint32(st.Mode)&syscall.S_IFMT != kind {
		return fmt.Errorf("%s is not a regular file or folder", p)
	}
	if owner >= 0 && int(st.Uid) != owner {
		return fmt.Errorf("%s does not belong to the site's user", p)
	}
	return nil
}

// readBelow reads the file name in the folder dir without following a
// link, up to maxRecord bytes.
func readBelow(dir *os.File, name string, owner int) ([]byte, error) {
	if strings.Contains(name, "/") {
		return nil, fmt.Errorf("bad name %q", name)
	}
	p := dir.Name() + "/" + name
	fd, err := syscall.Openat(int(dir.Fd()), name, syscall.O_RDONLY|syscall.O_NOFOLLOW|syscall.O_CLOEXEC|syscall.O_NONBLOCK, 0)
	if err != nil {
		return nil, &os.PathError{Op: "open", Path: p, Err: err}
	}
	f := os.NewFile(uintptr(fd), p)
	defer f.Close()
	if err := checkOwner(fd, p, owner, false); err != nil {
		return nil, err
	}
	data, err := io.ReadAll(io.LimitReader(f, maxRecord+1))
	if err != nil {
		return nil, err
	}
	if len(data) > maxRecord {
		return nil, fmt.Errorf("%s is too big", p)
	}
	return data, nil
}

// recordOwner is the owner the record files must have: the site's user,
// except in a simulation, where the files belong to whoever runs it.
func (m *Manager) recordOwner(u host.User) int {
	if m.sim() {
		return -1
	}
	return u.UID
}

func taskParts(domain string, id int) []string {
	return []string{".servik", "cron", domain, strconv.Itoa(id)}
}

// records reads up to limit run records of a task, newest first.
func (m *Manager) records(u host.User, domain string, id, limit int) []Record {
	out := []Record{}
	owner := m.recordOwner(u)
	dir, err := openBelow(m.h.Path("/home/"+u.Name), append(taskParts(domain, id), "runs"), owner)
	if err != nil {
		return out
	}
	defer dir.Close()
	names, err := dir.Readdirnames(-1)
	if err != nil {
		return out
	}
	for _, name := range sortRecords(names) {
		if len(out) == limit {
			break
		}
		data, err := readBelow(dir, name, owner)
		if err != nil {
			continue
		}
		var r Record
		if err := json.Unmarshal(data, &r); err != nil || r.StartedAt.IsZero() {
			continue
		}
		r.ID = strings.TrimSuffix(name, ".json")
		out = append(out, r)
	}
	return out
}

// running returns the marker of a run that is going, or nil.
func (m *Manager) running(u host.User, domain string, id int) (*marker, error) {
	owner := m.recordOwner(u)
	dir, err := openBelow(m.h.Path("/home/"+u.Name), taskParts(domain, id), owner)
	if err != nil {
		return nil, err
	}
	defer dir.Close()
	data, err := readBelow(dir, "running.json", owner)
	if err != nil {
		return nil, err
	}
	var r marker
	if err := json.Unmarshal(data, &r); err != nil {
		return nil, err
	}
	if r.PID < 1 || !alive(r.PID) {
		// Left behind by a run that was killed.
		return nil, nil
	}
	return &r, nil
}

func alive(pid int) bool {
	err := syscall.Kill(pid, 0)
	return err == nil || errors.Is(err, syscall.EPERM)
}

// spawn starts "cron-exec --manual" as the site's user and returns
// without waiting. Under systemd the run gets a transient unit of its
// own, so an agent restart (an update) does not stop it.
func (m *Manager) spawn(ctx context.Context, user, domain string, id int) error {
	if m.Spawn != nil {
		return m.Spawn(ctx, user, domain, id)
	}
	if !exeRe.MatchString(m.Exe) || !m.exists(m.Exe) {
		return fmt.Errorf("the agent binary %q is missing", m.Exe)
	}
	args := []string{"-u", user, "--", m.Exe, "cron-exec", "--manual", domain, strconv.Itoa(id)}
	if m.exists("/run/systemd/system") {
		unit := fmt.Sprintf("servik-cron-%d-%d", id, m.now().UnixNano())
		_, err := m.h.Run(ctx, host.Command{Name: "systemd-run", Args: append([]string{
			"--unit", unit, "--collect", "--quiet",
			"--description", fmt.Sprintf("Servik scheduled task %d of %s", id, domain),
			"runuser"}, args...)})
		return err
	}
	cmd := exec.Command("runuser", args...)
	cmd.Env = []string{"PATH=/usr/sbin:/usr/bin:/sbin:/bin"}
	cmd.SysProcAttr = &syscall.SysProcAttr{Setsid: true}
	if err := cmd.Start(); err != nil {
		return err
	}
	go func() { _ = cmd.Wait() }()
	return nil
}

// SimulatedOutput is the output of a manual run in a dry run.
const SimulatedOutput = "Simulated run: the command was not executed (dry run).\n"

// simulateRun records a manual run at once: a dry run executes nothing.
func (m *Manager) simulateRun(user, domain string, id int) error {
	start := m.now().UTC()
	return m.writeSimRecord(user, domain, id, Record{
		StartedAt: start, FinishedAt: start.Add(40 * time.Millisecond), DurationMS: 40,
		Output: SimulatedOutput, Trigger: TriggerManual,
	})
}

func (m *Manager) writeSimRecord(user, domain string, id int, rec Record) error {
	runs := m.h.Path(TaskDir(user, domain, id) + "/runs")
	if err := os.MkdirAll(runs, 0o700); err != nil {
		return err
	}
	return saveRecord(filepath.Clean(runs), rec)
}
