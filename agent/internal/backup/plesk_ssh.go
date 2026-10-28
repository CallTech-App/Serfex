package backup

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"io"
	"net"
	"os"
	"strconv"
	"strings"
	"time"

	"golang.org/x/crypto/ssh"
)

// sshRemote runs commands on a server over one SSH connection. A user
// other than root runs them through passwordless sudo.
type sshRemote struct {
	c    *ssh.Client
	sudo bool
	stop chan struct{}
}

// dialSSH signs in with the password, or with the agent's own key without
// one. Without a HostKey any key is taken (and returned); with one, only
// that key.
func (m *Manager) dialSSH(ctx context.Context, c PleskConn) (remote, string, error) {
	var auth []ssh.AuthMethod
	if c.Password != "" {
		pw := c.Password
		auth = append(auth, ssh.Password(pw), ssh.KeyboardInteractive(func(_, _ string, qs []string, _ []bool) ([]string, error) {
			out := make([]string, len(qs))
			for i := range qs {
				out[i] = pw
			}
			return out, nil
		}))
	} else {
		if _, err := m.PublicKey(); err != nil {
			return nil, "", opError(err)
		}
		b, err := os.ReadFile(m.h.Path(keyFile))
		if err != nil {
			return nil, "", opError(err)
		}
		signer, err := ssh.ParsePrivateKey(b)
		if err != nil {
			return nil, "", opError(err)
		}
		auth = append(auth, ssh.PublicKeys(signer))
	}
	return dialSSH(ctx, c, auth)
}

var errHostKeyChanged = errors.New("the server's host key changed since it was first connected to; connect again if this is expected")

func dialSSH(ctx context.Context, c PleskConn, auth []ssh.AuthMethod) (remote, string, error) {
	var seen string
	cfg := &ssh.ClientConfig{
		User: c.User,
		Auth: auth,
		HostKeyCallback: func(_ string, _ net.Addr, key ssh.PublicKey) error {
			seen = strings.TrimSpace(string(ssh.MarshalAuthorizedKey(key)))
			if c.HostKey != "" {
				want, err := parseHostKey(c.HostKey)
				if err != nil || !bytes.Equal(want.Marshal(), key.Marshal()) {
					return errHostKeyChanged
				}
			}
			return nil
		},
		Timeout: 20 * time.Second,
	}
	if c.HostKey != "" {
		if k, err := parseHostKey(c.HostKey); err == nil {
			cfg.HostKeyAlgorithms = hostKeyAlgorithms(k.Type())
		}
	}
	addr := net.JoinHostPort(c.Host, strconv.Itoa(c.Port))
	d := net.Dialer{Timeout: 20 * time.Second}
	conn, err := d.DialContext(ctx, "tcp", addr)
	if err != nil {
		return nil, "", invalid("could not reach %s over SSH: %s", addr, shortNetError(err))
	}
	// The handshake is bounded too.
	_ = conn.SetDeadline(time.Now().Add(30 * time.Second))
	cc, chans, reqs, err := ssh.NewClientConn(conn, addr, cfg)
	if err != nil {
		conn.Close()
		switch {
		case errors.Is(err, errHostKeyChanged):
			return nil, "", invalid("%s", errHostKeyChanged)
		case strings.Contains(err.Error(), "unable to authenticate"):
			if c.Password != "" {
				return nil, "", invalid("the server refused the user name or password")
			}
			return nil, "", invalid("the server refused Serfex's key: add it to the user's ~/.ssh/authorized_keys")
		}
		return nil, "", invalid("could not sign in to %s over SSH: %s", addr, shortNetError(err))
	}
	_ = conn.SetDeadline(time.Time{})
	r := &sshRemote{c: ssh.NewClient(cc, chans, reqs), stop: make(chan struct{})}
	go r.keepAlive()
	var uid bytes.Buffer
	if err := r.run(ctx, "id -u", &uid); err != nil {
		r.close()
		return nil, "", opError(fmt.Errorf("the server did not answer: %w", err))
	}
	if strings.TrimSpace(uid.String()) != "0" {
		if err := r.run(ctx, "sudo -n true", io.Discard); err != nil {
			r.close()
			return nil, "", invalid("%s is not root and cannot use sudo without a password; sign in as root", c.User)
		}
		r.sudo = true
	}
	return r, seen, nil
}

// hostKeyAlgorithms are the algorithms that ask the server for a key of
// type t.
func hostKeyAlgorithms(t string) []string {
	if t == ssh.KeyAlgoRSA {
		return []string{ssh.KeyAlgoRSASHA512, ssh.KeyAlgoRSASHA256, ssh.KeyAlgoRSA}
	}
	return []string{t}
}

func shortNetError(err error) string {
	s := err.Error()
	if i := strings.LastIndex(s, ": "); i >= 0 && i+2 < len(s) {
		return s[i+2:]
	}
	return s
}

func (r *sshRemote) keepAlive() {
	t := time.NewTicker(30 * time.Second)
	defer t.Stop()
	for {
		select {
		case <-r.stop:
			return
		case <-t.C:
			if _, _, err := r.c.SendRequest("keepalive@openssh.com", true, nil); err != nil {
				return
			}
		}
	}
}

func (r *sshRemote) run(ctx context.Context, cmd string, w io.Writer) error {
	s, err := r.c.NewSession()
	if err != nil {
		return err
	}
	defer s.Close()
	if r.sudo {
		cmd = "sudo -n sh -c " + shellQuote(cmd)
	}
	var stderr tailBuffer
	s.Stdout = w
	s.Stderr = &stderr
	done := make(chan error, 1)
	go func() { done <- s.Run(cmd) }()
	select {
	case <-ctx.Done():
		_ = s.Signal(ssh.SIGKILL)
		s.Close()
		return ctx.Err()
	case err := <-done:
		if err != nil {
			if line := stderr.lastLine(); line != "" {
				return fmt.Errorf("%s (%w)", line, err)
			}
			return err
		}
		return nil
	}
}

func (r *sshRemote) close() {
	select {
	case <-r.stop:
	default:
		close(r.stop)
	}
	r.c.Close()
}

// tailBuffer keeps the last 4 KiB written to it.
type tailBuffer struct{ b []byte }

func (t *tailBuffer) Write(p []byte) (int, error) {
	t.b = append(t.b, p...)
	if len(t.b) > 4096 {
		t.b = t.b[len(t.b)-4096:]
	}
	return len(p), nil
}

func (t *tailBuffer) lastLine() string {
	lines := strings.Split(strings.TrimSpace(string(t.b)), "\n")
	return strings.TrimSpace(lines[len(lines)-1])
}
