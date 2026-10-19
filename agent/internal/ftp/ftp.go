// Package ftp manages the SFTP accounts of sites.
//
// Accounts are served by the server's own OpenSSH daemon, SFTP only: no
// shell, no forwarding, each account locked (chroot) in one folder of its
// site. An account is a system user with the site user's UID and group
// (so what it uploads belongs to the site, as if PHP had written it),
// member of the group servik-sftp, with no home and /usr/sbin/nologin as
// its shell. OpenSSH matches that group:
//
//	/etc/ssh/sshd_config.d/50-servik-sftp.conf   Match Group servik-sftp: ChrootDirectory, ForceCommand internal-sftp
//	/var/lib/servik/sftp/<account>/              the chroot, root 0755 (OpenSSH requires root-owned)
//	/var/lib/servik/sftp/<account>/<folder>/     a bind mount of the account's folder in the site
//	/etc/systemd/system/<escaped>.mount          that bind mount, so it comes back after a reboot
//	/etc/servik/ftp/accounts.json                accounts and their folders, root 0600
//
// Never remove /var/lib/servik/sftp recursively: below each chroot is a
// site's folder, mounted. Accounts are removed one by one with Delete,
// which unmounts first and only removes empty folders.
//
// A locked account (suspended customer, deleted with Undo pending) keeps
// its password but cannot sign in: its password is locked and the account
// expired, which also refuses keys.
package ftp

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"sync"

	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/site"
	"github.com/winchester37/servik/agent/internal/txn"
)

const (
	// Group is the group OpenSSH locks into SFTP.
	Group = "servik-sftp"
	// JailRoot holds a chroot per account.
	JailRoot = "/var/lib/servik/sftp"
	// ConfigFile is the OpenSSH drop-in.
	ConfigFile = "/etc/ssh/sshd_config.d/50-servik-sftp.conf"
	stateDir   = "/etc/servik/ftp"
	stateFile  = stateDir + "/accounts.json"
	unitDir    = "/etc/systemd/system"
	// comment marks the accounts Servik made.
	comment = "Servik SFTP"
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

var (
	nameRe = regexp.MustCompile(`^[a-z][a-z0-9_-]{1,31}$`)
	// safePathRe is what may go into a mount unit unquoted.
	safePathRe = regexp.MustCompile(`^/?[A-Za-z0-9._/-]+$`)
)

// AccountSpec is an account as the panel wants it.
type AccountSpec struct {
	Name   string `json:"name"`
	User   string `json:"user"`
	Domain string `json:"domain"`
	// Dir is the folder inside the site the account is locked in ("" is
	// the whole site folder, /home/<user>/web/<domain>).
	Dir string `json:"dir"`
	// Password is set when given; otherwise the current one stays.
	Password string `json:"password,omitempty"`
	Locked   bool   `json:"locked"`
}

// account is what the state file keeps.
type account struct {
	User   string `json:"user"`
	Domain string `json:"domain"`
	Dir    string `json:"dir"`
	Mount  string `json:"mount"`
	Locked bool   `json:"locked"`
}

// Result is an applied account.
type Result struct {
	Name string `json:"name"`
	// Folder is the folder the account sees after signing in.
	Folder  string `json:"folder"`
	Created bool   `json:"created"`
	Port    int    `json:"port"`
}

// Status describes SFTP on this server.
type Status struct {
	// Installed is true when the OpenSSH server is.
	Installed bool `json:"installed"`
	// Ready is true once Servik's configuration is in place.
	Ready    bool `json:"ready"`
	Port     int  `json:"port"`
	Accounts int  `json:"accounts"`
}

// Manager applies accounts.
type Manager struct {
	h  host.Host
	mu sync.Mutex
}

// NewManager returns a manager.
func NewManager(h host.Host) *Manager { return &Manager{h: h} }

func (m *Manager) exists(abs string) bool {
	_, err := os.Stat(m.h.Path(abs))
	return err == nil
}

func (m *Manager) load() (map[string]account, error) {
	out := map[string]account{}
	data, err := os.ReadFile(m.h.Path(stateFile))
	if errors.Is(err, os.ErrNotExist) {
		return out, nil
	}
	if err != nil {
		return nil, err
	}
	return out, json.Unmarshal(data, &out)
}

func (m *Manager) save(t *txn.Tx, state map[string]account) error {
	if err := t.RootDir(stateDir, 0o700, 0, 0); err != nil {
		return err
	}
	data, err := json.MarshalIndent(state, "", "  ")
	if err != nil {
		return err
	}
	return t.WriteOwned(stateFile, append(data, '\n'), 0o600, 0, 0)
}

// Status reports whether SFTP accounts can work here.
func (m *Manager) Status(ctx context.Context) Status {
	m.mu.Lock()
	defer m.mu.Unlock()
	state, _ := m.load()
	return Status{
		Installed: m.exists("/usr/sbin/sshd"),
		Ready:     m.exists(ConfigFile),
		Port:      m.port(ctx),
		Accounts:  len(state),
	}
}

// port is the first port OpenSSH listens on.
func (m *Manager) port(ctx context.Context) int {
	if _, ok := m.h.(*host.Simulator); ok || !m.exists("/usr/sbin/sshd") {
		return 22
	}
	_ = os.MkdirAll(m.h.Path("/run/sshd"), 0o755)
	out, err := m.h.Run(ctx, host.Command{Name: "sshd", Args: []string{"-T"}})
	if err != nil {
		return 22
	}
	for _, line := range strings.Split(out, "\n") {
		if f := strings.Fields(line); len(f) == 2 && f[0] == "port" {
			if p, err := strconv.Atoi(f[1]); err == nil {
				return p
			}
		}
	}
	return 22
}

const sshdConfig = `# ` + txn.Marker + `: SFTP-only accounts of sites, each locked in its own folder.
Match Group ` + Group + `
    ChrootDirectory ` + JailRoot + `/%u
    ForceCommand internal-sftp -u 0022
    PasswordAuthentication yes
    KbdInteractiveAuthentication no
    PubkeyAuthentication no
    AuthorizedKeysFile none
    AllowTcpForwarding no
    AllowAgentForwarding no
    AllowStreamLocalForwarding no
    PermitTunnel no
    PermitTTY no
    PermitUserRC no
    X11Forwarding no
`

// setup installs OpenSSH when missing and puts Servik's configuration in
// place. It is idempotent.
func (m *Manager) setup(ctx context.Context, t *txn.Tx) error {
	if !m.exists("/usr/sbin/sshd") {
		env := []string{"DEBIAN_FRONTEND=noninteractive"}
		if _, err := m.h.Run(ctx, host.Command{Name: "apt-get", Args: []string{"install", "-y", "-q", "--no-install-recommends", "openssh-server"}, Env: env}); err != nil {
			return fmt.Errorf("installing the OpenSSH server: %w", err)
		}
	}
	group, err := os.ReadFile(m.h.Path("/etc/group"))
	if err != nil {
		return err
	}
	if !strings.Contains("\n"+string(group), "\n"+Group+":") {
		if _, err := m.h.Run(ctx, host.Command{Name: "groupadd", Args: []string{"--system", Group}}); err != nil {
			return err
		}
	}
	if err := t.RootDir(JailRoot, 0o755, 0, 0); err != nil {
		return err
	}
	current, _ := os.ReadFile(m.h.Path(ConfigFile))
	if string(current) == sshdConfig {
		return nil
	}
	if err := t.RootDir("/etc/ssh/sshd_config.d", 0o755, 0, 0); err != nil {
		return err
	}
	if err := t.WriteFileMode(ConfigFile, []byte(sshdConfig), 0o644, 0, 0); err != nil {
		return err
	}
	t.OnUndo(func(ctx context.Context) error { return m.reloadSSH(ctx) })
	_ = os.MkdirAll(m.h.Path("/run/sshd"), 0o755)
	if _, err := m.h.Run(ctx, host.Command{Name: "sshd", Args: []string{"-t"}}); err != nil {
		return fmt.Errorf("OpenSSH refused the configuration: %w", err)
	}
	return m.reloadSSH(ctx)
}

// reloadSSH makes a running OpenSSH read its configuration; a stopped
// (socket-activated) one reads it when it starts.
func (m *Manager) reloadSSH(ctx context.Context) error {
	_, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"try-reload-or-restart", "ssh.service"}})
	return err
}

// unitName is systemd's name for the mount unit of a path
// (systemd-escape --path --suffix=mount).
func unitName(p string) string {
	p = strings.Trim(path.Clean(p), "/")
	var b strings.Builder
	for i := 0; i < len(p); i++ {
		c := p[i]
		switch {
		case c == '/':
			b.WriteByte('-')
		case c == '.' && i == 0:
			fmt.Fprintf(&b, `\x%02x`, c)
		case c >= 'a' && c <= 'z', c >= 'A' && c <= 'Z', c >= '0' && c <= '9', c == ':', c == '_', c == '.':
			b.WriteByte(c)
		default:
			fmt.Fprintf(&b, `\x%02x`, c)
		}
	}
	return b.String() + ".mount"
}

func mountUnit(name, what, where string) string {
	return fmt.Sprintf(`# %s
[Unit]
Description=Servik SFTP folder of %s
RequiresMountsFor=%s
After=local-fs.target

[Mount]
What=%s
Where=%s
Type=none
Options=bind,nosuid,nodev

[Install]
WantedBy=multi-user.target
`, txn.Marker, name, what, what, where)
}

// Apply creates or updates an account.
func (m *Manager) Apply(ctx context.Context, spec AccountSpec) (Result, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	res, err := m.apply(ctx, spec)
	return res, opError(err)
}

func (m *Manager) validate(spec AccountSpec) error {
	if !nameRe.MatchString(spec.Name) {
		return invalid("account %q must start with a letter and contain only a-z, 0-9, _ or - (2-32)", spec.Name)
	}
	if err := site.ValidateUser(spec.Name); err != nil {
		return invalid("%s", err.Error())
	}
	if spec.Name == spec.User {
		return invalid("the account cannot be named like the site's own user")
	}
	if err := site.ValidateUser(spec.User); err != nil {
		return invalid("%s", err.Error())
	}
	if err := site.ValidateDomain(spec.Domain); err != nil {
		return invalid("%s", err.Error())
	}
	if spec.Dir != "" {
		if err := site.ValidateRelPath(spec.Dir); err != nil {
			return invalid("%s", err.Error())
		}
	}
	if strings.ContainsAny(spec.Password, "\n\r:") || len(spec.Password) > 128 {
		return invalid("the password may not contain line breaks or colons")
	}
	return nil
}

func (m *Manager) apply(ctx context.Context, spec AccountSpec) (res Result, err error) {
	if err := m.validate(spec); err != nil {
		return Result{}, err
	}
	state, err := m.load()
	if err != nil {
		return Result{}, err
	}
	owner, err := m.h.LookupUser(spec.User)
	if err != nil {
		return Result{}, invalid("site user %s: %v", spec.User, err)
	}
	if owner.UID == 0 || filepath.Clean(owner.Home) != "/home/"+spec.User {
		return Result{}, invalid("%s is not a site user", spec.User)
	}
	siteDir := "/home/" + spec.User + "/web/" + spec.Domain
	if !m.exists(siteDir) {
		return Result{}, invalid("the site folder %s is missing", siteDir)
	}

	existing, err := m.h.LookupUser(spec.Name)
	created := false
	switch {
	case errors.Is(err, host.ErrUserNotFound):
		if spec.Password == "" {
			return Result{}, invalid("a new account needs a password")
		}
		created = true
	case err != nil:
		return Result{}, err
	case !strings.HasPrefix(existing.Comment, comment):
		return Result{}, invalid("the name %s is taken by another system account", spec.Name)
	case existing.UID != owner.UID:
		return Result{}, invalid("account %s belongs to another site user", spec.Name)
	}

	t := txn.New(m.h)
	defer func() {
		if err != nil {
			_ = t.Rollback(context.WithoutCancel(ctx))
		}
	}()
	if err := m.setup(ctx, t); err != nil {
		return Result{}, err
	}

	// The folder: made as the site user when missing, and it must stay
	// inside the site (no symlink out of it).
	target := siteDir
	if spec.Dir != "" {
		target = siteDir + "/" + spec.Dir
		if err := t.UserDirs(ctx, spec.User, "0755", target); err != nil {
			return Result{}, err
		}
	}
	realSite, err := filepath.EvalSymlinks(m.h.Path(siteDir))
	if err != nil {
		return Result{}, err
	}
	realTarget, err := filepath.EvalSymlinks(m.h.Path(target))
	if err != nil {
		return Result{}, err
	}
	if realTarget != realSite && !strings.HasPrefix(realTarget, realSite+"/") {
		return Result{}, invalid("%s leads outside the site folder", spec.Dir)
	}
	if !safePathRe.MatchString(strings.TrimPrefix(realTarget, m.h.Path("/"))) {
		return Result{}, invalid("%s resolves to a folder name the mount cannot use", spec.Dir)
	}
	if fi, err := os.Stat(realTarget); err != nil || !fi.IsDir() {
		return Result{}, invalid("%s is not a folder", spec.Dir)
	}
	what := target
	if _, sim := m.h.(*host.Simulator); !sim {
		what = realTarget
	}

	if created {
		args := []string{"--non-unique", "--uid", strconv.Itoa(owner.UID), "--gid", strconv.Itoa(owner.GID),
			"--groups", Group, "--no-create-home", "--home-dir", "/", "--shell", "/usr/sbin/nologin",
			"--comment", comment + " " + spec.Domain, spec.Name}
		if _, err := m.h.Run(ctx, host.Command{Name: "useradd", Args: args}); err != nil {
			return Result{}, err
		}
		t.OnUndo(func(ctx context.Context) error {
			_, err := m.h.Run(ctx, host.Command{Name: "userdel", Args: []string{"-f", spec.Name}})
			return err
		})
	}

	// The chroot and its one folder.
	folder := path.Base(target)
	jail := JailRoot + "/" + spec.Name
	where := jail + "/" + folder
	for _, d := range []string{jail, where} {
		if err := m.rootDir(t, d); err != nil {
			return Result{}, err
		}
	}
	prev, had := state[spec.Name]
	if had && prev.Mount != "" && prev.Mount != where {
		if err := m.unmount(ctx, prev.Mount); err != nil {
			return Result{}, err
		}
	}
	unit := unitName(where)
	content := mountUnit(spec.Name, what, where)
	current, _ := os.ReadFile(m.h.Path(unitDir + "/" + unit))
	if string(current) != content || !had {
		if err := t.WriteFileMode(unitDir+"/"+unit, []byte(content), 0o644, 0, 0); err != nil {
			return Result{}, err
		}
		if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"daemon-reload"}}); err != nil {
			return Result{}, err
		}
		verb := "start"
		if len(current) > 0 {
			verb = "restart"
		}
		if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"enable", unit}}); err != nil {
			return Result{}, err
		}
		if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{verb, unit}}); err != nil {
			return Result{}, err
		}
		t.OnUndo(func(ctx context.Context) error { return m.unmount(ctx, where) })
	}

	if spec.Password != "" {
		if _, err := m.h.Run(ctx, host.Command{Name: "chpasswd", Stdin: []byte(spec.Name + ":" + spec.Password + "\n")}); err != nil {
			return Result{}, errors.New("setting the password failed")
		}
	}
	if err := m.lock(ctx, spec.Name, spec.Locked); err != nil {
		return Result{}, err
	}

	state[spec.Name] = account{User: spec.User, Domain: spec.Domain, Dir: spec.Dir, Mount: where, Locked: spec.Locked}
	if err := m.save(t, state); err != nil {
		return Result{}, err
	}
	return Result{Name: spec.Name, Folder: "/" + folder, Created: created, Port: m.port(ctx)}, nil
}

// rootDir makes a root-owned 0755 folder. Undo removes it only while it
// is empty (it may hold a mounted site folder).
func (m *Manager) rootDir(t *txn.Tx, abs string) error {
	p := m.h.Path(abs)
	if fi, err := os.Lstat(p); err == nil {
		if !fi.IsDir() {
			return fmt.Errorf("%s exists and is not a folder", abs)
		}
		return nil
	}
	if err := os.Mkdir(p, 0o755); err != nil {
		return err
	}
	if err := m.h.Chown(p, 0, 0); err != nil {
		return err
	}
	t.OnUndo(func(context.Context) error { return os.Remove(p) })
	return nil
}

// lock locks or unlocks an account's sign-in. Locking also ends the
// account's open sessions.
func (m *Manager) lock(ctx context.Context, name string, locked bool) error {
	if locked {
		if _, err := m.h.Run(ctx, host.Command{Name: "usermod", Args: []string{"--lock", "--expiredate", "1", name}}); err != nil {
			return err
		}
		m.endSessions(ctx, name)
		return nil
	}
	_, err := m.h.Run(ctx, host.Command{Name: "usermod", Args: []string{"--unlock", "--expiredate", "", name}})
	return err
}

// endSessions stops the account's OpenSSH sessions. They share the site
// user's UID, so they are found by OpenSSH's process title ("sshd:
// name@notty"), never by UID: that would stop the site's PHP too.
func (m *Manager) endSessions(ctx context.Context, name string) {
	_, _ = m.h.Run(ctx, host.Command{Name: "pkill", Args: []string{"-KILL", "-f", "^sshd(-session)?: " + name + "(@| |$)"}})
}

// unmount stops and removes a mount unit and the empty mount point.
func (m *Manager) unmount(ctx context.Context, where string) error {
	unit := unitName(where)
	_, _ = m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"disable", "--now", unit}})
	if _, sim := m.h.(*host.Simulator); !sim {
		// Make sure nothing is mounted there any more before removing.
		if out, _ := m.h.Run(ctx, host.Command{Name: "findmnt", Args: []string{"-n", "-o", "TARGET", "--mountpoint", where}}); strings.TrimSpace(out) != "" {
			if _, err := m.h.Run(ctx, host.Command{Name: "umount", Args: []string{where}}); err != nil {
				return fmt.Errorf("%s is still mounted: %w", where, err)
			}
		}
	}
	if err := os.Remove(m.h.Path(unitDir + "/" + unit)); err != nil && !errors.Is(err, os.ErrNotExist) {
		return err
	}
	_, _ = m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"daemon-reload"}})
	// os.Remove, never RemoveAll: if anything were still mounted, its
	// content is a site's files.
	if err := os.Remove(m.h.Path(where)); err != nil && !errors.Is(err, os.ErrNotExist) {
		return err
	}
	return nil
}

// Delete removes an account: its sessions, mount, chroot and system user.
// The site's files stay.
func (m *Manager) Delete(ctx context.Context, name string) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	return opError(m.delete(ctx, name))
}

func (m *Manager) delete(ctx context.Context, name string) error {
	if !nameRe.MatchString(name) {
		return invalid("account %q is not a valid name", name)
	}
	state, err := m.load()
	if err != nil {
		return err
	}
	acc, had := state[name]
	u, err := m.h.LookupUser(name)
	userExists := err == nil
	switch {
	case errors.Is(err, host.ErrUserNotFound):
	case err != nil:
		return err
	case !strings.HasPrefix(u.Comment, comment):
		return invalid("%s is not a Servik SFTP account", name)
	default:
		_, _ = m.h.Run(ctx, host.Command{Name: "usermod", Args: []string{"--lock", "--expiredate", "1", name}})
		m.endSessions(ctx, name)
	}
	if had && acc.Mount != "" {
		if err := m.unmount(ctx, acc.Mount); err != nil {
			return err
		}
	}
	if err := os.Remove(m.h.Path(JailRoot + "/" + name)); err != nil && !errors.Is(err, os.ErrNotExist) {
		return err
	}
	if userExists {
		if _, err := m.h.Run(ctx, host.Command{Name: "userdel", Args: []string{"-f", name}}); err != nil {
			return err
		}
	}
	if had {
		delete(state, name)
		t := txn.New(m.h)
		if err := m.save(t, state); err != nil {
			return err
		}
	}
	return nil
}

// Account is an account as List reports it.
type Account struct {
	Name   string `json:"name"`
	User   string `json:"user"`
	Domain string `json:"domain"`
	Dir    string `json:"dir"`
	Locked bool   `json:"locked"`
}

// List returns the accounts on this server.
func (m *Manager) List() ([]Account, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	state, err := m.load()
	if err != nil {
		return nil, err
	}
	out := []Account{}
	for name, a := range state {
		out = append(out, Account{Name: name, User: a.User, Domain: a.Domain, Dir: a.Dir, Locked: a.Locked})
	}
	sort.Slice(out, func(i, j int) bool { return out[i].Name < out[j].Name })
	return out, nil
}
