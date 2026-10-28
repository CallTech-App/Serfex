// Package quota caps how much disk each hosting account may fill, with
// the file system's own user quotas, so a customer cannot write past their
// package however the data arrives (file manager, SFTP, PHP, Git).
//
// Quotas are turned on once for the file system that holds /home:
//
//   - ext4: the quota tools are installed, the kernel's quota_v2 module is
//     loaded (linux-modules-extra on cloud kernels that lack it), the
//     /etc/fstab entry gets usrjquota=aquota.user,jqfmt=vfsv1 (the old
//     file is kept as /etc/fstab.before-serfex-quota), the file system is
//     remounted with the same options, quotacheck counts what every user
//     has and quotaon starts enforcing. Nothing needs a restart, and
//     systemd turns quotas back on at boot from the fstab options.
//   - XFS: quotas can only be switched on when the file system is
//     mounted, so they work only if it already carries uquota/usrquota.
//   - Anything else (btrfs, ZFS, overlay) has no user quotas Serfex can
//     use; the panel keeps the package limit as a warning.
//
// Every step is undone if a later one fails, and a kernel without quota
// support is remembered so the panel stops offering it until the kernel
// changes.
//
// Limits are set with setquota, as hard block limits only: no soft limit
// and no grace period, so a write that would cross the limit fails with
// "Disk quota exceeded" at once. Usage is read with repquota.
//
// Dry runs keep the switch and the limits in the state file and measure
// home directories to report usage.
package quota

import (
	"bufio"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/site"
)

const (
	// StateFile remembers when quotas were turned on, or why they cannot be.
	StateFile = "/etc/serfex/quota.json"
	// FstabBackup is /etc/fstab as it was before Serfex first changed it.
	FstabBackup = "/etc/fstab.before-serfex-quota"
	// ModulesFile loads the quota format module at boot.
	ModulesFile = "/etc/modules-load.d/serfex-quota.conf"
	// ext4Options are the mount options that turn on journaled user quotas.
	ext4Options = "usrjquota=aquota.user,jqfmt=vfsv1"
	// module is the kernel module for the vfsv1 quota format.
	module = "quota_v2"
)

// States of Status.State.
const (
	On          = "on"
	Off         = "off"
	Unsupported = "unsupported"
)

// Reasons of Status.Reason.
const (
	// ReasonNotEnabled: the file system can have quotas; Enable turns them on.
	ReasonNotEnabled = "not_enabled"
	// ReasonFSType: the file system type has no user quotas Serfex uses.
	ReasonFSType = "fstype"
	// ReasonXFSMount: XFS was mounted without uquota; that needs a restart.
	ReasonXFSMount = "xfs_mount"
	// ReasonNoFstab: the file system has no /etc/fstab entry to keep the
	// options in.
	ReasonNoFstab = "no_fstab"
	// ReasonKernel: the running kernel has no quota_v2 module.
	ReasonKernel = "kernel"
)

// Status says whether account disk limits are enforced.
type Status struct {
	// State is on, off (Enable can turn quotas on) or unsupported.
	State string `json:"state"`
	// Reason is a stable code for off and unsupported.
	Reason string `json:"reason,omitempty"`
	// Detail is what went wrong, in English, for unsupported.
	Detail string `json:"detail,omitempty"`
	// Mount is the mount point of the file system holding /home.
	Mount  string `json:"mount"`
	Device string `json:"device"`
	FSType string `json:"fstype"`
	// EnabledAt is when Serfex turned quotas on (RFC 3339), if it did.
	EnabledAt string `json:"enabled_at,omitempty"`
}

// ValidationError reports a request that can never succeed as given.
type ValidationError struct{ msg string }

func (e *ValidationError) Error() string { return e.msg }

func invalid(format string, a ...any) error { return &ValidationError{fmt.Sprintf(format, a...)} }

// OperationError reports a valid request that failed on the server.
type OperationError struct{ Err error }

func (e *OperationError) Error() string { return e.Err.Error() }
func (e *OperationError) Unwrap() error { return e.Err }

// Manager turns quotas on and sets and reads the accounts' limits.
type Manager struct {
	h host.Host
	// Dir is the directory whose file system carries the quotas.
	Dir string
	// Fstab and Mountinfo are read from (and Fstab written to) these
	// system paths; tests point them elsewhere.
	Fstab     string
	Mountinfo string
	mu        sync.Mutex
}

// NewManager returns a manager for the file system holding /home.
func NewManager(h host.Host) *Manager {
	return &Manager{h: h, Dir: "/home", Fstab: "/etc/fstab", Mountinfo: "/proc/self/mountinfo"}
}

func (m *Manager) sim() bool {
	_, ok := m.h.(*host.Simulator)
	return ok
}

type state struct {
	EnabledAt string `json:"enabled_at,omitempty"`
	// Unsupported and Detail record why Enable gave up; Kernel is the
	// kernel release a kernel reason applies to.
	Unsupported string `json:"unsupported,omitempty"`
	Detail      string `json:"detail,omitempty"`
	Kernel      string `json:"kernel,omitempty"`
	// Limits are a dry run's limits in bytes, by user.
	Limits map[string]int64 `json:"limits,omitempty"`
}

func (m *Manager) readState() state {
	var s state
	if b, err := os.ReadFile(m.h.Path(StateFile)); err == nil {
		_ = json.Unmarshal(b, &s)
	}
	return s
}

func (m *Manager) writeState(s state) error {
	b, err := json.MarshalIndent(s, "", "  ")
	if err != nil {
		return err
	}
	p := m.h.Path(StateFile)
	if err := os.MkdirAll(filepath.Dir(p), 0o755); err != nil {
		return err
	}
	tmp := p + ".tmp"
	if err := os.WriteFile(tmp, append(b, '\n'), 0o644); err != nil {
		return err
	}
	return os.Rename(tmp, p)
}

// mount is one line of /proc/self/mountinfo.
type mount struct {
	Point, FSType, Source string
	// Options are the per-mount and the super block options together.
	Options []string
}

func (mt mount) has(prefixes ...string) bool {
	for _, o := range mt.Options {
		for _, p := range prefixes {
			if o == p || (strings.HasSuffix(p, "=") && strings.HasPrefix(o, p)) {
				return true
			}
		}
	}
	return false
}

// unescapeMount decodes the octal escapes (\040 for a space) the kernel
// and fstab use in paths.
func unescapeMount(s string) string {
	if !strings.Contains(s, `\`) {
		return s
	}
	var b strings.Builder
	for i := 0; i < len(s); i++ {
		if s[i] == '\\' && i+3 < len(s) {
			if n, err := strconv.ParseUint(s[i+1:i+4], 8, 8); err == nil {
				b.WriteByte(byte(n))
				i += 3
				continue
			}
		}
		b.WriteByte(s[i])
	}
	return b.String()
}

// findMount returns the mount that holds dir: the longest mount point it
// lies under (the last one mounted there wins).
func findMount(mountinfo, dir string) (mount, bool) {
	var best mount
	found := false
	s := bufio.NewScanner(strings.NewReader(mountinfo))
	for s.Scan() {
		left, right, ok := strings.Cut(s.Text(), " - ")
		if !ok {
			continue
		}
		lf, rf := strings.Fields(left), strings.Fields(right)
		if len(lf) < 6 || len(rf) < 2 {
			continue
		}
		point := unescapeMount(lf[4])
		if point != "/" && dir != point && !strings.HasPrefix(dir, point+"/") {
			continue
		}
		if found && len(point) < len(best.Point) {
			continue
		}
		opts := strings.Split(lf[5], ",")
		if len(rf) >= 3 {
			opts = append(opts, strings.Split(rf[2], ",")...)
		}
		best = mount{Point: point, FSType: rf[0], Source: unescapeMount(rf[1]), Options: opts}
		found = true
	}
	return best, found
}

// fstabWithQuota returns fstab with ext4Options added to the entry
// mounted at point. changed is false when the entry already has quota
// options; found is false when no entry mounts there.
func fstabWithQuota(fstab, point string) (out string, changed, found bool) {
	lines := strings.SplitAfter(fstab, "\n")
	for i, line := range lines {
		trimmed := strings.TrimSpace(line)
		if trimmed == "" || strings.HasPrefix(trimmed, "#") {
			continue
		}
		fields := strings.Fields(trimmed)
		if len(fields) < 4 || unescapeMount(fields[1]) != point {
			continue
		}
		found = true
		opts := strings.Split(fields[3], ",")
		for _, o := range opts {
			if o == "usrquota" || o == "quota" || strings.HasPrefix(o, "usrjquota=") {
				return fstab, false, true
			}
		}
		// Replace the fourth field in place, keeping the line's spacing.
		loc := fieldSpan(line, 3)
		lines[i] = line[:loc[0]] + fields[3] + "," + ext4Options + line[loc[1]:]
		changed = true
	}
	return strings.Join(lines, ""), changed, found
}

var fieldRe = regexp.MustCompile(`\S+`)

// fieldSpan returns where the n-th (from 0) whitespace separated field
// of line starts and ends.
func fieldSpan(line string, n int) []int {
	all := fieldRe.FindAllStringIndex(line, n+1)
	return all[n]
}

// Status reports whether quotas are enforced on the file system holding
// Dir. It changes nothing.
func (m *Manager) Status(ctx context.Context) (Status, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	return m.status(ctx)
}

func (m *Manager) status(ctx context.Context) (Status, error) {
	st := m.readState()
	if m.sim() {
		out := Status{State: Off, Reason: ReasonNotEnabled, Mount: "/", Device: "/dev/vda1", FSType: "ext4"}
		if st.EnabledAt != "" {
			out.State, out.Reason, out.EnabledAt = On, "", st.EnabledAt
		}
		return out, nil
	}
	info, err := os.ReadFile(m.h.Path(m.Mountinfo))
	if err != nil {
		return Status{}, &OperationError{fmt.Errorf("reading the mount table: %w", err)}
	}
	mt, ok := findMount(string(info), m.Dir)
	if !ok {
		return Status{}, &OperationError{fmt.Errorf("no file system holds %s", m.Dir)}
	}
	out := Status{Mount: mt.Point, Device: mt.Source, FSType: mt.FSType, EnabledAt: st.EnabledAt}
	switch mt.FSType {
	case "ext4", "ext3":
		if st.Unsupported == ReasonKernel && st.Kernel == m.kernel() {
			out.State, out.Reason, out.Detail = Unsupported, ReasonKernel, st.Detail
			return out, nil
		}
		if st.Unsupported == ReasonNoFstab && !m.inFstab(mt.Point) {
			out.State, out.Reason, out.Detail = Unsupported, ReasonNoFstab, st.Detail
			return out, nil
		}
	case "xfs":
		if !mt.has("usrquota", "uquota", "quota") {
			out.State, out.Reason = Unsupported, ReasonXFSMount
			out.Detail = "XFS turns user quotas on only when it is mounted: add uquota to its /etc/fstab options (rootflags=uquota on the kernel command line for /) and restart the server"
			return out, nil
		}
	default:
		out.State, out.Reason = Unsupported, ReasonFSType
		out.Detail = fmt.Sprintf("%s file systems have no user quotas Serfex can use", mt.FSType)
		return out, nil
	}
	if m.quotaOn(ctx, mt.Point) {
		out.State = On
		return out, nil
	}
	out.State, out.Reason = Off, ReasonNotEnabled
	return out, nil
}

func (m *Manager) kernel() string {
	b, _ := os.ReadFile(m.h.Path("/proc/sys/kernel/osrelease"))
	return strings.TrimSpace(string(b))
}

func (m *Manager) inFstab(point string) bool {
	b, err := os.ReadFile(m.h.Path(m.Fstab))
	if err != nil {
		return false
	}
	_, _, found := fstabWithQuota(string(b), point)
	return found
}

func (m *Manager) haveTools() bool {
	for _, t := range []string{"/usr/sbin/setquota", "/usr/sbin/repquota", "/usr/sbin/quotaon", "/usr/sbin/quotacheck"} {
		if _, err := os.Stat(m.h.Path(t)); err != nil {
			return false
		}
	}
	return true
}

// quotaOn asks quotaon whether user quotas are enforced on point.
func (m *Manager) quotaOn(ctx context.Context, point string) bool {
	if !m.haveTools() {
		return false
	}
	out, err := m.h.Run(ctx, host.Command{Name: "quotaon", Args: []string{"-p", "-u", point}})
	// quotaon -p exits with 1 when quotas are on and 0 when they are off
	// (it counts the file systems that have them), so read the text.
	_ = err
	// XFS mounted with uqnoenforce counts usage but enforces nothing.
	return strings.Contains(out, " is on") && !strings.Contains(out, "(accounting)")
}

var aptEnv = []string{"DEBIAN_FRONTEND=noninteractive"}

func (m *Manager) aptInstall(ctx context.Context, pkgs ...string) error {
	inst := host.Command{Name: "apt-get", Args: append([]string{"install", "-y", "-q", "--no-install-recommends"}, pkgs...), Env: aptEnv}
	if _, err := m.h.Run(ctx, inst); err == nil {
		return nil
	}
	if _, err := m.h.Run(ctx, host.Command{Name: "apt-get", Args: []string{"update", "-q"}, Env: aptEnv}); err != nil {
		return fmt.Errorf("apt-get update failed: %w", err)
	}
	_, err := m.h.Run(ctx, inst)
	return err
}

// kernelSupport loads the quota format module, installing the kernel's
// extra modules when they are missing.
func (m *Manager) kernelSupport(ctx context.Context) error {
	rel := m.kernel()
	if b, err := os.ReadFile(m.h.Path("/lib/modules/" + rel + "/modules.builtin")); err == nil && strings.Contains(string(b), "/"+module+".ko") {
		return nil
	}
	if _, err := m.h.Run(ctx, host.Command{Name: "modprobe", Args: []string{module}}); err == nil {
		return nil
	}
	if rel != "" {
		if err := m.aptInstall(ctx, "linux-modules-extra-"+rel); err == nil {
			if _, err := m.h.Run(ctx, host.Command{Name: "modprobe", Args: []string{module}}); err == nil {
				return nil
			}
		}
	}
	return fmt.Errorf("the running kernel (%s) has no %s module for disk quotas", rel, module)
}

// Enable turns user quotas on for the file system holding Dir. A file
// system that cannot have them is reported in the status, not as an
// error; a step that fails undoes the earlier ones.
func (m *Manager) Enable(ctx context.Context) (Status, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	st := m.readState()
	if m.sim() {
		if st.EnabledAt == "" {
			st.EnabledAt = time.Now().UTC().Format(time.RFC3339)
		}
		if err := m.writeState(st); err != nil {
			return Status{}, &OperationError{err}
		}
		return m.status(ctx)
	}
	// A kernel or fstab found wanting last time is tried again.
	st.Unsupported, st.Detail, st.Kernel = "", "", ""
	if err := m.writeState(st); err != nil {
		return Status{}, &OperationError{err}
	}
	cur, err := m.status(ctx)
	if err != nil {
		return cur, err
	}
	if cur.State == Unsupported {
		return cur, nil
	}
	if cur.State == On {
		return m.markEnabled(ctx, st)
	}

	if !m.haveTools() {
		if err := m.aptInstall(ctx, "quota"); err != nil {
			return cur, &OperationError{fmt.Errorf("installing the quota tools failed: %w", err)}
		}
	}
	if cur.FSType == "xfs" {
		// Mounted with quotas (status checked), so only the tools were missing.
		if m.quotaOn(ctx, cur.Mount) {
			return m.markEnabled(ctx, st)
		}
		return cur, &OperationError{fmt.Errorf("XFS on %s is mounted with quotas but quotaon reports them off", cur.Mount)}
	}

	fstabPath := m.h.Path(m.Fstab)
	before, err := os.ReadFile(fstabPath)
	if err != nil {
		return cur, &OperationError{fmt.Errorf("reading %s: %w", m.Fstab, err)}
	}
	after, changed, found := fstabWithQuota(string(before), cur.Mount)
	if !found {
		st.Unsupported, st.Detail = ReasonNoFstab, fmt.Sprintf("%s has no entry for %s, so quota options would not survive a restart", m.Fstab, cur.Mount)
		return m.giveUp(ctx, st)
	}
	if err := m.kernelSupport(ctx); err != nil {
		st.Unsupported, st.Detail, st.Kernel = ReasonKernel, err.Error(), m.kernel()
		return m.giveUp(ctx, st)
	}
	_ = os.MkdirAll(filepath.Dir(m.h.Path(ModulesFile)), 0o755)
	_ = os.WriteFile(m.h.Path(ModulesFile), []byte("# Written by Serfex: the disk quota format, loaded at boot.\n"+module+"\n"), 0o644)

	restore := func() {}
	if changed {
		if _, err := os.Stat(m.h.Path(FstabBackup)); errors.Is(err, fs.ErrNotExist) {
			if err := os.WriteFile(m.h.Path(FstabBackup), before, 0o644); err != nil {
				return cur, &OperationError{fmt.Errorf("keeping a copy of %s: %w", m.Fstab, err)}
			}
		}
		if err := writeKeepingMode(fstabPath, []byte(after)); err != nil {
			return cur, &OperationError{fmt.Errorf("writing %s: %w", m.Fstab, err)}
		}
		restore = func() { _ = writeKeepingMode(fstabPath, before) }
	}
	fail := func(err error) (Status, error) {
		restore()
		return cur, &OperationError{err}
	}

	if !m.mountHasQuota(cur.Mount) {
		if _, err := m.h.Run(ctx, host.Command{Name: "mount", Args: []string{"-o", "remount," + ext4Options, cur.Mount}}); err != nil {
			return fail(fmt.Errorf("remounting %s with quotas failed: %w", cur.Mount, err))
		}
	}
	// -c writes fresh quota files, -u for users, -m without remounting
	// read-only (the file system is in use).
	if _, err := m.h.Run(ctx, host.Command{Name: "quotacheck", Args: []string{"-c", "-u", "-m", cur.Mount}}); err != nil {
		return fail(fmt.Errorf("counting disk usage (quotacheck) failed: %w", err))
	}
	if out, err := m.h.Run(ctx, host.Command{Name: "quotaon", Args: []string{"-u", cur.Mount}}); err != nil {
		if strings.Contains(err.Error()+out, "not supported in kernel") {
			restore()
			st.Unsupported, st.Detail, st.Kernel = ReasonKernel, "the running kernel cannot read the vfsv1 quota format", m.kernel()
			return m.giveUp(ctx, st)
		}
		return fail(fmt.Errorf("turning quotas on failed: %w", err))
	}
	return m.markEnabled(ctx, st)
}

func (m *Manager) mountHasQuota(point string) bool {
	info, err := os.ReadFile(m.h.Path(m.Mountinfo))
	if err != nil {
		return false
	}
	mt, ok := findMount(string(info), point)
	return ok && mt.Point == point && mt.has("usrjquota=", "usrquota", "quota")
}

func (m *Manager) markEnabled(ctx context.Context, st state) (Status, error) {
	if st.EnabledAt == "" {
		st.EnabledAt = time.Now().UTC().Format(time.RFC3339)
	}
	st.Unsupported, st.Detail, st.Kernel = "", "", ""
	if err := m.writeState(st); err != nil {
		return Status{}, &OperationError{err}
	}
	return m.status(ctx)
}

func (m *Manager) giveUp(ctx context.Context, st state) (Status, error) {
	if err := m.writeState(st); err != nil {
		return Status{}, &OperationError{err}
	}
	return m.status(ctx)
}

func writeKeepingMode(path string, data []byte) error {
	mode := os.FileMode(0o644)
	if fi, err := os.Stat(path); err == nil {
		mode = fi.Mode().Perm()
	}
	tmp := path + ".serfex-tmp"
	if err := os.WriteFile(tmp, data, mode); err != nil {
		return err
	}
	return os.Rename(tmp, path)
}

// Usage is what one account holds on the file system and its limit, in
// bytes; Limit 0 means none.
type Usage struct {
	Bytes int64 `json:"bytes"`
	Limit int64 `json:"limit"`
}

// UsersSpec names accounts.
type UsersSpec struct {
	Users []string `json:"users"`
}

const maxUsers = 5000

func validUsers(users []string) error {
	if len(users) > maxUsers {
		return invalid("at most %d accounts at once", maxUsers)
	}
	for _, u := range users {
		if err := site.ValidateUser(u); err != nil {
			return invalid("%s", err.Error())
		}
	}
	return nil
}

// Report returns the usage and limit of each account that exists. It
// needs quotas to be on.
func (m *Manager) Report(ctx context.Context, spec UsersSpec) (map[string]Usage, error) {
	if err := validUsers(spec.Users); err != nil {
		return nil, err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	out := map[string]Usage{}
	if m.sim() {
		st := m.readState()
		for _, u := range spec.Users {
			out[u] = Usage{Bytes: simUsage(m.h.Path("/home/" + u)), Limit: st.Limits[u]}
		}
		return out, nil
	}
	cur, err := m.status(ctx)
	if err != nil {
		return nil, err
	}
	if cur.State != On {
		return nil, &OperationError{fmt.Errorf("disk quotas are not on for %s", m.Dir)}
	}
	res, err := m.h.Run(ctx, host.Command{Name: "repquota", Args: []string{"-u", "-n", cur.Mount}})
	if err != nil {
		return nil, &OperationError{fmt.Errorf("reading quotas failed: %w", err)}
	}
	byUID := parseRepquota(res)
	for _, u := range spec.Users {
		acct, err := m.h.LookupUser(u)
		if err != nil {
			continue
		}
		out[u] = byUID[acct.UID]
	}
	return out, nil
}

// parseRepquota reads `repquota -u -n` output: "#UID -- used soft hard
// [grace] files soft hard [grace]", blocks in KiB.
func parseRepquota(out string) map[int]Usage {
	res := map[int]Usage{}
	s := bufio.NewScanner(strings.NewReader(out))
	for s.Scan() {
		f := strings.Fields(s.Text())
		if len(f) < 5 || !strings.HasPrefix(f[0], "#") {
			continue
		}
		uid, err := strconv.Atoi(f[0][1:])
		if err != nil {
			continue
		}
		used, err1 := strconv.ParseInt(f[2], 10, 64)
		hard, err2 := strconv.ParseInt(f[4], 10, 64)
		if err1 != nil || err2 != nil {
			continue
		}
		res[uid] = Usage{Bytes: used * 1024, Limit: hard * 1024}
	}
	return res
}

func simUsage(root string) int64 {
	var n int64
	_ = filepath.WalkDir(root, func(_ string, d fs.DirEntry, err error) error {
		if err == nil && !d.IsDir() {
			if fi, err := d.Info(); err == nil {
				n += fi.Size()
			}
		}
		return nil
	})
	return n
}

// LimitsSpec sets each account's limit in bytes; 0 removes it.
type LimitsSpec struct {
	Limits map[string]int64 `json:"limits"`
}

// SetResult lists the accounts that were skipped because they do not
// exist on the server.
type SetResult struct {
	Set     int      `json:"set"`
	Missing []string `json:"missing"`
}

// Set applies hard block limits. A limit is rounded up to whole KiB, and
// a limit below 1 KiB (an account with no room left) becomes 1 KiB so it
// still means "full" rather than "no limit".
func (m *Manager) Set(ctx context.Context, spec LimitsSpec) (SetResult, error) {
	users := make([]string, 0, len(spec.Limits))
	for u, b := range spec.Limits {
		if b < 0 {
			return SetResult{}, invalid("the limit of %s is negative", u)
		}
		users = append(users, u)
	}
	if err := validUsers(users); err != nil {
		return SetResult{}, err
	}
	sort.Strings(users)
	m.mu.Lock()
	defer m.mu.Unlock()
	res := SetResult{Missing: []string{}}
	if m.sim() {
		st := m.readState()
		if st.Limits == nil {
			st.Limits = map[string]int64{}
		}
		for _, u := range users {
			if spec.Limits[u] == 0 {
				delete(st.Limits, u)
			} else {
				st.Limits[u] = spec.Limits[u]
			}
			res.Set++
		}
		if err := m.writeState(st); err != nil {
			return res, &OperationError{err}
		}
		return res, nil
	}
	cur, err := m.status(ctx)
	if err != nil {
		return res, err
	}
	if cur.State != On {
		return res, &OperationError{fmt.Errorf("disk quotas are not on for %s", m.Dir)}
	}
	var batch strings.Builder
	for _, u := range users {
		if _, err := m.h.LookupUser(u); err != nil {
			res.Missing = append(res.Missing, u)
			continue
		}
		fmt.Fprintf(&batch, "%s 0 %d 0 0\n", u, kib(spec.Limits[u]))
		res.Set++
	}
	if res.Set == 0 {
		return res, nil
	}
	if _, err := m.h.Run(ctx, host.Command{Name: "setquota", Args: []string{"-u", "-b", cur.Mount}, Stdin: []byte(batch.String())}); err != nil {
		return res, &OperationError{fmt.Errorf("setting disk limits failed: %w", err)}
	}
	return res, nil
}

// kib converts a byte limit to setquota's KiB blocks.
func kib(b int64) int64 {
	if b == 0 {
		return 0
	}
	return max(1, (b+1023)/1024)
}
