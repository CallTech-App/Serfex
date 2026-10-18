package host

import (
	"bytes"
	"context"
	"os"
	"os/exec"
)

// System acts on the real machine. It must run as root.
type System struct{}

// Path returns abs unchanged.
func (System) Path(abs string) string { return joinRoot("", abs) }

// Run executes the command.
func (System) Run(ctx context.Context, c Command) (string, error) {
	cmd := exec.CommandContext(ctx, c.Name, c.Args...)
	if c.Stdin != nil {
		cmd.Stdin = bytes.NewReader(c.Stdin)
	}
	cmd.Env = append([]string{"PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin", "LC_ALL=C"}, c.Env...)
	out, err := cmd.CombinedOutput()
	if err != nil {
		return string(out), commandError(c, string(out), err)
	}
	return string(out), nil
}

// Chown changes ownership of abs without following symlinks.
func (System) Chown(abs string, uid, gid int) error { return os.Lchown(abs, uid, gid) }

// LookupUser reads /etc/passwd.
func (System) LookupUser(name string) (User, error) { return lookupPasswd("/etc/passwd", name) }
