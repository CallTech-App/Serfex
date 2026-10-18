package host

import (
	"bytes"
	"context"
	"io"
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
	if c.Stream != nil {
		tail := &TailBuffer{Max: 64 << 10}
		w := io.MultiWriter(c.Stream, tail)
		cmd.Stdout, cmd.Stderr = w, w
		err := cmd.Run()
		if err != nil {
			return tail.String(), commandError(c, tail.String(), err)
		}
		return tail.String(), nil
	}
	out, err := cmd.CombinedOutput()
	if err != nil {
		return string(out), commandError(c, string(out), err)
	}
	return string(out), nil
}

// TailBuffer keeps the last Max bytes written to it.
type TailBuffer struct {
	Max int
	buf []byte
}

func (t *TailBuffer) Write(p []byte) (int, error) {
	t.buf = append(t.buf, p...)
	if len(t.buf) > t.Max {
		t.buf = append(t.buf[:0], t.buf[len(t.buf)-t.Max:]...)
	}
	return len(p), nil
}

func (t *TailBuffer) String() string { return string(t.buf) }

// Chown changes ownership of abs without following symlinks.
func (System) Chown(abs string, uid, gid int) error { return os.Lchown(abs, uid, gid) }

// LookupUser reads /etc/passwd.
func (System) LookupUser(name string) (User, error) { return lookupPasswd("/etc/passwd", name) }
