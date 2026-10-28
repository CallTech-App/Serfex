package ftp

// SSH access of a site's own user (the Linux account that owns its sites),
// next to the SFTP accounts above. OpenSSH matches the user's group:
//
//	serfex-ssh     SFTP only, chrooted in /var/lib/serfex/ssh/<user>/, where
//	               /home/<user> is a bind mount of the real home, so paths
//	               read the same inside and out
//	serfex-shell   a shell (/bin/bash) in the real home, no chroot
//	neither        no SSH: the user's password is locked and the shell is
//	               /usr/sbin/nologin, as the site manager made it
//
//	/etc/serfex/ssh/keys/<user>   the panel's public keys, root 0644; OpenSSH
//	                              reads only this file for these groups
//
// The panel holds the keys and sends all of them on every apply. The
// password is never stored: the panel passes a new one on once, and the
// hash stays in /etc/shadow, locked whenever password logins are off, SSH
// is off or the customer is suspended, so turning it back on needs no new
// password.

import (
	"context"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"slices"
	"strings"

	"golang.org/x/crypto/ssh"

	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/site"
	"github.com/calltech-app/serfex/agent/internal/txn"
)

const (
	// GroupSFTP is the group of site users with chrooted SFTP.
	GroupSFTP = "serfex-ssh"
	// GroupShell is the group of site users with a shell.
	GroupShell = "serfex-shell"
	// UserJailRoot holds a chroot per site user.
	UserJailRoot = "/var/lib/serfex/ssh"
	// KeysDir holds the authorized keys of site users.
	KeysDir = "/etc/serfex/ssh/keys"

	siteUserComment = "Serfex site user"
	nologin         = "/usr/sbin/nologin"
	bash            = "/bin/bash"
	maxKeys         = 50
)

// Access modes of a site user.
const (
	AccessOff   = "off"
	AccessSFTP  = "sftp"
	AccessShell = "shell"
)

// UserSpec is a site user's SSH access as the panel wants it.
type UserSpec struct {
	User string `json:"user"`
	// Access is off, sftp or shell.
	Access string `json:"access"`
	// Keys are OpenSSH public keys ("ssh-ed25519 AAAA... comment"), all of
	// them; options in front of a key are refused.
	Keys []string `json:"keys"`
	// Password is set when given; otherwise the current one stays.
	Password string `json:"password,omitempty"`
	// PasswordLogin lets the password sign in; off locks it.
	PasswordLogin bool `json:"password_login"`
	// Locked keeps everything but lets nothing sign in (a suspended
	// customer).
	Locked bool `json:"locked"`
}

// UserResult is an applied site user.
type UserResult struct {
	User   string `json:"user"`
	Access string `json:"access"`
	// Folder is where the user starts after signing in.
	Folder string `json:"folder"`
	Keys   int    `json:"keys"`
	Port   int    `json:"port"`
}

// ApplyUser gives a site user the SSH access of spec, or takes it away.
func (m *Manager) ApplyUser(ctx context.Context, spec UserSpec) (UserResult, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	res, err := m.applyUser(ctx, spec)
	return res, opError(err)
}

// authorizedKeys checks the keys and writes them out the way OpenSSH
// reads them, one per line, without options.
func authorizedKeys(keys []string) ([]byte, error) {
	if len(keys) > maxKeys {
		return nil, invalid("at most %d keys", maxKeys)
	}
	var b strings.Builder
	fmt.Fprintf(&b, "# %s: SSH keys from the panel. Changes here are overwritten.\n", txn.Marker)
	for i, k := range keys {
		k = strings.TrimSpace(k)
		if strings.ContainsAny(k, "\r\n") {
			return nil, invalid("key %d holds a line break", i+1)
		}
		pub, comment, options, rest, err := ssh.ParseAuthorizedKey([]byte(k))
		if err != nil || len(rest) > 0 {
			return nil, invalid("key %d is not an OpenSSH public key", i+1)
		}
		if len(options) > 0 {
			return nil, invalid("key %d has options in front of it", i+1)
		}
		if err := keyStrength(pub); err != nil {
			return nil, invalid("key %d: %v", i+1, err)
		}
		line := strings.TrimSpace(string(ssh.MarshalAuthorizedKey(pub)))
		if comment = strings.TrimSpace(comment); comment != "" {
			line += " " + comment
		}
		b.WriteString(line + "\n")
	}
	return []byte(b.String()), nil
}

// keyStrength refuses DSA and RSA keys under 2048 bits.
func keyStrength(pub ssh.PublicKey) error {
	switch pub.Type() {
	case ssh.KeyAlgoED25519, ssh.KeyAlgoECDSA256, ssh.KeyAlgoECDSA384, ssh.KeyAlgoECDSA521,
		ssh.KeyAlgoSKED25519, ssh.KeyAlgoSKECDSA256:
		return nil
	case ssh.KeyAlgoRSA:
		if c, ok := pub.(ssh.CryptoPublicKey); ok {
			if k, ok := c.CryptoPublicKey().(interface{ Size() int }); ok && k.Size()*8 < 2048 {
				return errors.New("RSA keys need at least 2048 bits")
			}
		}
		return nil
	}
	return fmt.Errorf("%s keys are not accepted", pub.Type())
}

func (m *Manager) validateUser(spec UserSpec) (host.User, error) {
	if err := site.ValidateUser(spec.User); err != nil {
		return host.User{}, invalid("%s", err.Error())
	}
	switch spec.Access {
	case AccessOff, AccessSFTP, AccessShell:
	default:
		return host.User{}, invalid("access must be off, sftp or shell")
	}
	if strings.ContainsAny(spec.Password, "\n\r:") || len(spec.Password) > 128 {
		return host.User{}, invalid("the password may not contain line breaks or colons")
	}
	u, err := m.h.LookupUser(spec.User)
	if errors.Is(err, host.ErrUserNotFound) {
		return host.User{}, invalid("site user %s does not exist", spec.User)
	}
	if err != nil {
		return host.User{}, err
	}
	if u.Comment != siteUserComment || u.UID < 1000 || filepath.Clean(u.Home) != "/home/"+spec.User {
		return host.User{}, invalid("%s is not a site user", spec.User)
	}
	return u, nil
}

func (m *Manager) applyUser(ctx context.Context, spec UserSpec) (res UserResult, err error) {
	u, err := m.validateUser(spec)
	if err != nil {
		return UserResult{}, err
	}
	keys, err := authorizedKeys(spec.Keys)
	if err != nil {
		return UserResult{}, err
	}
	access := spec.Access
	if spec.Locked {
		access = AccessOff
	}
	hash, err := m.shadowHash(spec.User)
	if err != nil {
		return UserResult{}, err
	}
	if access != AccessOff && spec.PasswordLogin && spec.Password == "" && !realHash(hash) {
		return UserResult{}, invalid("%s has no password yet; send one with password_login", spec.User)
	}

	t := txn.New(m.h)
	defer func() {
		if err != nil {
			_ = t.Rollback(context.WithoutCancel(ctx))
		}
	}()
	if access != AccessOff || m.exists(ConfigFile) {
		if err := m.setup(ctx, t); err != nil {
			return UserResult{}, err
		}
	}

	// Who signs in is decided first and taken away first: the groups.
	groups, err := m.groupsOf(spec.User)
	if err != nil {
		return UserResult{}, err
	}
	want := map[string]string{AccessSFTP: GroupSFTP, AccessShell: GroupShell}[access]
	changed := false
	for _, g := range []string{GroupSFTP, GroupShell} {
		switch has := slices.Contains(groups, g); {
		case has && g != want:
			if err := m.leaveGroup(ctx, t, spec.User, g); err != nil {
				return UserResult{}, err
			}
			changed = true
		case !has && g == want:
			if err := m.joinGroup(ctx, t, spec.User, g); err != nil {
				return UserResult{}, err
			}
		}
	}

	shell := nologin
	if access == AccessShell {
		shell = bash
	}
	if u.Shell != shell {
		if err := m.setShell(ctx, t, spec.User, u.Shell, shell); err != nil {
			return UserResult{}, err
		}
	}

	keysFile := KeysDir + "/" + spec.User
	if access == AccessOff {
		if err := os.Remove(m.h.Path(keysFile)); err != nil && !errors.Is(err, os.ErrNotExist) {
			return UserResult{}, err
		}
	} else {
		for _, d := range []string{"/etc/serfex", "/etc/serfex/ssh", KeysDir} {
			if err := t.RootDir(d, 0o755, 0, 0); err != nil {
				return UserResult{}, err
			}
		}
		if err := t.WriteFileMode(keysFile, keys, 0o644, 0, 0); err != nil {
			return UserResult{}, err
		}
	}

	// The chroot: only for SFTP.
	if access == AccessSFTP {
		if err := m.mountHome(ctx, t, spec.User); err != nil {
			return UserResult{}, err
		}
	} else if err := m.unmountHome(ctx, spec.User); err != nil {
		return UserResult{}, err
	}

	if spec.Password != "" {
		if _, err := m.h.Run(ctx, host.Command{Name: "chpasswd", Stdin: []byte(spec.User + ":" + spec.Password + "\n")}); err != nil {
			return UserResult{}, errors.New("setting the password failed")
		}
		if hash, err = m.shadowHash(spec.User); err != nil {
			return UserResult{}, err
		}
	}
	open := access != AccessOff && spec.PasswordLogin
	switch {
	case open && strings.HasPrefix(hash, "!") && realHash(hash):
		if _, err := m.h.Run(ctx, host.Command{Name: "usermod", Args: []string{"--unlock", spec.User}}); err != nil {
			return UserResult{}, err
		}
	case !open && !strings.HasPrefix(hash, "!"):
		if _, err := m.h.Run(ctx, host.Command{Name: "usermod", Args: []string{"--lock", spec.User}}); err != nil {
			return UserResult{}, err
		}
		changed = true
	}

	// Sessions opened under the old access end with it.
	if changed || spec.Locked {
		m.endSessions(ctx, spec.User)
	}

	folder := ""
	if access != AccessOff {
		folder = u.Home
	}
	return UserResult{User: spec.User, Access: access, Folder: folder, Keys: len(spec.Keys), Port: m.port(ctx)}, nil
}

// realHash reports whether a shadow entry holds a password behind its
// lock: "!$6$..." does, "!", "!*" and "*" do not.
func realHash(hash string) bool {
	return strings.HasPrefix(strings.TrimLeft(hash, "!"), "$")
}

// shadowHash is the user's password field in /etc/shadow ("!" when the
// file or the entry is missing).
func (m *Manager) shadowHash(user string) (string, error) {
	data, err := os.ReadFile(m.h.Path("/etc/shadow"))
	if errors.Is(err, os.ErrNotExist) {
		return "!", nil
	}
	if err != nil {
		return "", err
	}
	for _, line := range strings.Split(string(data), "\n") {
		if f := strings.Split(line, ":"); len(f) > 1 && f[0] == user {
			return f[1], nil
		}
	}
	return "!", nil
}

// groupsOf lists the supplementary groups of user from /etc/group.
func (m *Manager) groupsOf(user string) ([]string, error) {
	data, err := os.ReadFile(m.h.Path("/etc/group"))
	if err != nil {
		return nil, err
	}
	var out []string
	for _, line := range strings.Split(string(data), "\n") {
		f := strings.Split(line, ":")
		if len(f) == 4 && slices.Contains(strings.Split(f[3], ","), user) {
			out = append(out, f[0])
		}
	}
	return out, nil
}

func (m *Manager) joinGroup(ctx context.Context, t *txn.Tx, user, group string) error {
	if _, err := m.h.Run(ctx, host.Command{Name: "usermod", Args: []string{"--append", "--groups", group, user}}); err != nil {
		return err
	}
	t.OnUndo(func(ctx context.Context) error {
		_, err := m.h.Run(ctx, host.Command{Name: "gpasswd", Args: []string{"--delete", user, group}})
		return err
	})
	return nil
}

func (m *Manager) leaveGroup(ctx context.Context, t *txn.Tx, user, group string) error {
	if _, err := m.h.Run(ctx, host.Command{Name: "gpasswd", Args: []string{"--delete", user, group}}); err != nil {
		return err
	}
	t.OnUndo(func(ctx context.Context) error {
		_, err := m.h.Run(ctx, host.Command{Name: "usermod", Args: []string{"--append", "--groups", group, user}})
		return err
	})
	return nil
}

func (m *Manager) setShell(ctx context.Context, t *txn.Tx, user, from, to string) error {
	if _, err := m.h.Run(ctx, host.Command{Name: "usermod", Args: []string{"--shell", to, user}}); err != nil {
		return err
	}
	t.OnUndo(func(ctx context.Context) error {
		_, err := m.h.Run(ctx, host.Command{Name: "usermod", Args: []string{"--shell", from, user}})
		return err
	})
	return nil
}

// homeMount is where a site user's home is mounted in its chroot.
func homeMount(user string) string { return UserJailRoot + "/" + user + "/home/" + user }

// mountHome makes the user's chroot: root-owned folders down to
// home/<user>, where the real home is bind-mounted.
func (m *Manager) mountHome(ctx context.Context, t *txn.Tx, user string) error {
	jail := UserJailRoot + "/" + user
	where := homeMount(user)
	for _, d := range []string{jail, jail + "/home", where} {
		if err := m.rootDir(t, d); err != nil {
			return err
		}
	}
	what := "/home/" + user
	unit := unitName(where)
	content := mountUnit(user, what, where)
	current, _ := os.ReadFile(m.h.Path(unitDir + "/" + unit))
	if string(current) == content && m.mounted(ctx, where) {
		return nil
	}
	if err := t.WriteFileMode(unitDir+"/"+unit, []byte(content), 0o644, 0, 0); err != nil {
		return err
	}
	for _, args := range [][]string{{"daemon-reload"}, {"enable", unit}, {"restart", unit}} {
		if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: args}); err != nil {
			return err
		}
	}
	t.OnUndo(func(ctx context.Context) error { return m.unmount(ctx, where) })
	return nil
}

// mounted reports whether something is mounted at where (always true
// under the simulator once the unit exists).
func (m *Manager) mounted(ctx context.Context, where string) bool {
	if _, sim := m.h.(*host.Simulator); sim {
		return true
	}
	out, _ := m.h.Run(ctx, host.Command{Name: "findmnt", Args: []string{"-n", "-o", "TARGET", "--mountpoint", where}})
	return strings.TrimSpace(out) != ""
}

// unmountHome removes the user's chroot, if there is one. Folders are
// removed one by one and only when empty.
func (m *Manager) unmountHome(ctx context.Context, user string) error {
	where := homeMount(user)
	jail := UserJailRoot + "/" + user
	if !m.exists(jail) && !m.exists(unitDir+"/"+unitName(where)) {
		return nil
	}
	if err := m.unmount(ctx, where); err != nil {
		return err
	}
	for _, d := range []string{jail + "/home", jail} {
		if err := os.Remove(m.h.Path(d)); err != nil && !errors.Is(err, os.ErrNotExist) {
			return err
		}
	}
	return nil
}
