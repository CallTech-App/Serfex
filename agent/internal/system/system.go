// Package system reads and changes the server's own settings: its host
// name and time zone, and what the panel shows about the machine
// (operating system, kernel, processors, memory, uptime).
//
// The host name is set with hostnamectl, which writes /etc/hostname;
// /etc/hosts gets a "127.0.1.1 <fqdn> <short>" line so the name resolves
// locally (Debian's convention). The time zone is set with timedatectl,
// which points /etc/localtime at /usr/share/zoneinfo.
//
// Dry runs report a fixed, plausible machine, but remember a host name
// or time zone set during the run (in /etc/hostname and /etc/timezone
// under the simulated root).
package system

import (
	"bufio"
	"context"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"regexp"
	"runtime"
	"slices"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/hostname"
	"github.com/winchester37/servik/agent/internal/txn"
)

const (
	hostsFile    = "/etc/hosts"
	hostnameFile = "/etc/hostname"
	timezoneFile = "/etc/timezone"
	localtime    = "/etc/localtime"
	// ZoneInfo holds the time zone database (tzdata).
	ZoneInfo = "/usr/share/zoneinfo"
)

// ValidationError reports a request that can never succeed as given.
type ValidationError struct{ msg string }

func (e *ValidationError) Error() string { return e.msg }

func invalid(format string, a ...any) error { return &ValidationError{fmt.Sprintf(format, a...)} }

// OperationError reports a valid request that failed on the server.
type OperationError struct{ Err error }

func (e *OperationError) Error() string { return e.Err.Error() }
func (e *OperationError) Unwrap() error { return e.Err }

func opError(err error) error {
	var v *ValidationError
	var o *OperationError
	if err == nil || errors.As(err, &v) || errors.As(err, &o) {
		return err
	}
	return &OperationError{err}
}

// Info describes the machine.
type Info struct {
	Hostname      string `json:"hostname"`
	FQDN          string `json:"fqdn,omitempty"`
	OS            string `json:"os"`
	Kernel        string `json:"kernel"`
	CPUs          int    `json:"cpus"`
	MemoryBytes   int64  `json:"memory_bytes"`
	Timezone      string `json:"timezone"`
	UptimeSeconds int64  `json:"uptime_seconds"`
}

// Manager reads and changes the server's settings.
type Manager struct {
	h       host.Host
	started time.Time
	mu      sync.Mutex
}

// NewManager returns a manager.
func NewManager(h host.Host) *Manager { return &Manager{h: h, started: time.Now()} }

func (m *Manager) sim() bool {
	_, ok := m.h.(*host.Simulator)
	return ok
}

// What a dry run reports.
const (
	simHostname = "srv-ist-01.servik.test"
	simOS       = "Ubuntu 24.04.3 LTS"
	simKernel   = "6.8.0-85-generic"
	simCPUs     = 8
	simMemory   = 32 << 30
	simTimezone = "Europe/Istanbul"
	// simUptime is how long the simulated server ran before the agent
	// started: 12 days, 5 hours.
	simUptime = 12*24*3600 + 5*3600
)

// simZones are the time zones a dry run offers.
var simZones = []string{"America/New_York", "Asia/Dubai", "Asia/Tokyo", "Europe/Berlin", "Europe/Istanbul", "Europe/London", "UTC"}

// Info reports the machine.
func (m *Manager) Info() Info {
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.sim() {
		name := readLine(m.h.Path(hostnameFile))
		if name == "" {
			name = simHostname
		}
		tz := readLine(m.h.Path(timezoneFile))
		if tz == "" {
			tz = simTimezone
		}
		in := Info{Hostname: name, OS: simOS, Kernel: simKernel, CPUs: simCPUs, MemoryBytes: simMemory, Timezone: tz,
			UptimeSeconds: simUptime + int64(time.Since(m.started).Seconds())}
		if strings.Contains(name, ".") {
			in.FQDN = name
		}
		return in
	}
	in := Info{CPUs: runtime.NumCPU(), Timezone: m.timezone()}
	in.Hostname, _ = os.Hostname()
	if in.Hostname == "" {
		in.Hostname = readLine(m.h.Path(hostnameFile))
	}
	in.FQDN = m.fqdn(in.Hostname)
	in.OS = osRelease(m.h.Path("/etc/os-release"))
	in.Kernel = readLine(m.h.Path("/proc/sys/kernel/osrelease"))
	in.MemoryBytes = memTotal(m.h.Path("/proc/meminfo"))
	if f := strings.Fields(readLine(m.h.Path("/proc/uptime"))); len(f) > 0 {
		if s, err := strconv.ParseFloat(f[0], 64); err == nil {
			in.UptimeSeconds = int64(s)
		}
	}
	return in
}

// fqdn is the host's full name: the host name itself when it has dots,
// else the first dotted name /etc/hosts gives it.
func (m *Manager) fqdn(name string) string {
	if strings.Contains(name, ".") {
		return name
	}
	data, err := os.ReadFile(m.h.Path(hostsFile))
	if err != nil || name == "" {
		return ""
	}
	for _, line := range strings.Split(string(data), "\n") {
		line, _, _ = strings.Cut(line, "#")
		f := strings.Fields(line)
		if len(f) < 2 {
			continue
		}
		names := f[1:]
		has := false
		for _, n := range names {
			has = has || n == name
		}
		if !has {
			continue
		}
		for _, n := range names {
			if strings.HasPrefix(n, name+".") {
				return n
			}
		}
	}
	return ""
}

// timezone is the system's time zone: where /etc/localtime points, else
// /etc/timezone, else UTC.
func (m *Manager) timezone() string {
	if target, err := os.Readlink(m.h.Path(localtime)); err == nil {
		if _, zone, ok := strings.Cut(target, "zoneinfo/"); ok && validZoneName(zone) {
			return zone
		}
	}
	if tz := readLine(m.h.Path(timezoneFile)); validZoneName(tz) {
		return tz
	}
	return "UTC"
}

// readLine returns a file's first line, trimmed.
func readLine(p string) string {
	data, err := os.ReadFile(p)
	if err != nil {
		return ""
	}
	line, _, _ := strings.Cut(string(data), "\n")
	return strings.TrimSpace(line)
}

// osRelease reads PRETTY_NAME from os-release(5), without running it.
func osRelease(p string) string {
	f, err := os.Open(p)
	if err != nil {
		return ""
	}
	defer f.Close()
	s := bufio.NewScanner(f)
	for s.Scan() {
		v, ok := strings.CutPrefix(strings.TrimSpace(s.Text()), "PRETTY_NAME=")
		if !ok {
			continue
		}
		if len(v) >= 2 && (v[0] == '"' || v[0] == '\'') && v[len(v)-1] == v[0] {
			v = v[1 : len(v)-1]
		}
		return strings.NewReplacer(`\"`, `"`, `\\`, `\`, `\$`, `$`, "\\`", "`").Replace(v)
	}
	return ""
}

// memTotal reads MemTotal from /proc/meminfo, in bytes.
func memTotal(p string) int64 {
	data, err := os.ReadFile(p)
	if err != nil {
		return 0
	}
	for _, line := range strings.Split(string(data), "\n") {
		if rest, ok := strings.CutPrefix(line, "MemTotal:"); ok {
			f := strings.Fields(rest)
			if len(f) > 0 {
				kb, _ := strconv.ParseInt(f[0], 10, 64)
				return kb * 1024
			}
		}
	}
	return 0
}

var labelRe = regexp.MustCompile(`^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$`)

// ValidateHostname checks a host name: a full name such as
// srv1.example.com, or a single label such as srv1.
func ValidateHostname(name string) error {
	if strings.Contains(name, ".") {
		if err := hostname.Validate(name); err != nil {
			return invalid("%s", strings.Replace(err.Error(), "domain", "host name", 1))
		}
		return nil
	}
	if !labelRe.MatchString(name) {
		return invalid("host name %q may contain only a-z, 0-9 and - (1-63 characters, not starting or ending with -)", name)
	}
	return nil
}

// HostnameSpec sets the host name.
type HostnameSpec struct {
	Hostname string `json:"hostname"`
}

// SetHostname sets the host name and its /etc/hosts line. If hostnamectl
// fails, /etc/hosts goes back to what it was.
func (m *Manager) SetHostname(ctx context.Context, spec HostnameSpec) (map[string]string, error) {
	name := strings.TrimSuffix(strings.ToLower(strings.TrimSpace(spec.Hostname)), ".")
	if err := ValidateHostname(name); err != nil {
		return nil, err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	if err := m.setHostname(ctx, name); err != nil {
		return nil, opError(err)
	}
	return map[string]string{"hostname": name}, nil
}

func (m *Manager) setHostname(ctx context.Context, name string) (err error) {
	t := txn.New(m.h)
	defer func() {
		if err != nil {
			if rbErr := t.Rollback(context.WithoutCancel(ctx)); rbErr != nil {
				err = fmt.Errorf("%w (rollback also failed: %v)", err, rbErr)
			}
		}
	}()
	old, err := os.ReadFile(m.h.Path(hostsFile))
	if err != nil && !errors.Is(err, os.ErrNotExist) {
		return err
	}
	if hosts := HostsWithName(string(old), name); hosts != string(old) {
		if err := t.WriteOwned(hostsFile, []byte(hosts), 0o644, 0, 0); err != nil {
			return err
		}
	}
	if _, err := m.h.Run(ctx, host.Command{Name: "hostnamectl", Args: []string{"set-hostname", name}}); err != nil {
		return fmt.Errorf("setting the host name failed: %w", err)
	}
	if m.sim() {
		// hostnamectl does this on a real server.
		return os.WriteFile(m.h.Path(hostnameFile), []byte(name+"\n"), 0o644)
	}
	return nil
}

// HostsWithName returns hosts (an /etc/hosts) whose 127.0.1.1 line names
// the host: "127.0.1.1 <fqdn> <short>", or "127.0.1.1 <name>" for a
// single label. An existing 127.0.1.1 line is replaced, otherwise the
// line goes after the localhost line (or first).
func HostsWithName(hosts, name string) string {
	entry := "127.0.1.1\t" + name
	if short, _, ok := strings.Cut(name, "."); ok {
		entry += " " + short
	}
	var lines, out []string
	if hosts != "" {
		lines = strings.Split(strings.TrimRight(hosts, "\n"), "\n")
	}
	replaced := false
	for _, line := range lines {
		if f := strings.Fields(line); len(f) > 0 && f[0] == "127.0.1.1" {
			if !replaced {
				out, replaced = append(out, entry), true
			}
			continue
		}
		out = append(out, line)
	}
	if !replaced {
		at := 0
		for i, line := range out {
			if f := strings.Fields(line); len(f) > 1 && f[0] == "127.0.0.1" {
				at = i + 1
				break
			}
		}
		out = slices.Insert(out, at, entry)
	}
	return strings.Join(out, "\n") + "\n"
}

// TimezoneSpec sets the time zone.
type TimezoneSpec struct {
	Timezone string `json:"timezone"`
}

var zoneRe = regexp.MustCompile(`^[A-Za-z0-9_+-]+(/[A-Za-z0-9_+-]+)*$`)

func validZoneName(tz string) bool {
	return len(tz) <= 100 && zoneRe.MatchString(tz) && !strings.Contains(tz, "..")
}

// SetTimezone sets the system's time zone.
func (m *Manager) SetTimezone(ctx context.Context, spec TimezoneSpec) (map[string]string, error) {
	tz := strings.TrimSpace(spec.Timezone)
	if !validZoneName(tz) {
		return nil, invalid("time zone %q is not a valid name such as Europe/Istanbul", tz)
	}
	if !m.zoneExists(tz) {
		return nil, invalid("time zone %s is unknown", tz)
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	if _, err := m.h.Run(ctx, host.Command{Name: "timedatectl", Args: []string{"set-timezone", tz}}); err != nil {
		return nil, opError(fmt.Errorf("setting the time zone failed: %w", err))
	}
	if m.sim() {
		// timedatectl does this on a real server.
		if err := os.MkdirAll(filepath.Dir(m.h.Path(timezoneFile)), 0o755); err != nil {
			return nil, opError(err)
		}
		if err := os.WriteFile(m.h.Path(timezoneFile), []byte(tz+"\n"), 0o644); err != nil {
			return nil, opError(err)
		}
	}
	return map[string]string{"timezone": tz}, nil
}

// zoneExists reports whether the time zone database has tz as a TZif
// file. A dry run without one knows simZones.
func (m *Manager) zoneExists(tz string) bool {
	if _, err := os.Stat(m.h.Path(ZoneInfo)); err != nil && m.sim() {
		return slices.Contains(simZones, tz)
	}
	f, err := os.Open(m.h.Path(ZoneInfo + "/" + tz))
	if err != nil {
		return false
	}
	defer f.Close()
	fi, err := f.Stat()
	if err != nil || !fi.Mode().IsRegular() {
		return false
	}
	magic := make([]byte, 4)
	n, _ := f.Read(magic)
	return n == 4 && string(magic) == "TZif"
}

// zoneAreas are the top folders of the time zone database that hold
// Area/Location names; the others hold old aliases (US/Eastern, Etc/GMT+3)
// or copies (posix/, right/).
var zoneAreas = []string{"Africa", "America", "Antarctica", "Arctic", "Asia", "Atlantic", "Australia", "Europe", "Indian", "Pacific"}

// Timezones lists the time zones the server knows, sorted, UTC included.
func (m *Manager) Timezones() ([]string, error) {
	root := m.h.Path(ZoneInfo)
	if _, err := os.Stat(root); err != nil {
		if m.sim() {
			return append([]string(nil), simZones...), nil
		}
		return nil, opError(fmt.Errorf("the time zone database is missing (%s): %w", ZoneInfo, err))
	}
	out := []string{"UTC"}
	for _, area := range zoneAreas {
		_ = filepath.WalkDir(filepath.Join(root, area), func(p string, d fs.DirEntry, err error) error {
			if err != nil {
				return nil
			}
			if d.IsDir() {
				return nil
			}
			rel, err := filepath.Rel(root, p)
			if err != nil {
				return nil
			}
			rel = filepath.ToSlash(rel)
			if !validZoneName(rel) || !strings.Contains(rel, "/") {
				return nil
			}
			fi, err := os.Stat(p)
			if err != nil || !fi.Mode().IsRegular() {
				return nil
			}
			out = append(out, rel)
			return nil
		})
	}
	sort.Strings(out)
	return out, nil
}
