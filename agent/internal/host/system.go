package host

import (
	"bytes"
	"context"
	"io"
	"os"
	"os/exec"
	"syscall"
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
	if c.Input != nil {
		cmd.Stdin = c.Input
	}
	cmd.Env = append([]string{"PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin", "LC_ALL=C"}, c.Env...)
	if c.Stdout != nil {
		tail := &TailBuffer{Max: 64 << 10}
		cmd.Stdout, cmd.Stderr = c.Stdout, tail
		if err := cmd.Run(); err != nil {
			return tail.String(), commandError(c, tail.String(), err)
		}
		return tail.String(), nil
	}
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

// Start runs the command in the background, its output going to output.
func (System) Start(c Command, output io.Writer) (Process, error) {
	cmd := exec.Command(c.Name, c.Args...)
	cmd.Env = append([]string{"PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin", "LC_ALL=C"}, c.Env...)
	cmd.Stdout, cmd.Stderr = output, output
	// Its own process group, so Kill reaches what runuser started too.
	cmd.SysProcAttr = &syscall.SysProcAttr{Setpgid: true}
	in, err := cmd.StdinPipe()
	if err != nil {
		return nil, err
	}
	if err := cmd.Start(); err != nil {
		return nil, err
	}
	return &process{cmd: cmd, in: in}, nil
}

type process struct {
	cmd *exec.Cmd
	in  io.WriteCloser
}

func (p *process) Write(b []byte) (int, error) { return p.in.Write(b) }
func (p *process) Wait() error                 { return p.cmd.Wait() }
func (p *process) Kill() error {
	_ = p.in.Close()
	return syscall.Kill(-p.cmd.Process.Pid, syscall.SIGKILL)
}
