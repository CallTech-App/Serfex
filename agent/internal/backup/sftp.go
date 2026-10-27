package backup

import (
	"context"
	"errors"
	"fmt"
	"io"
	"os"
	"path"
	"sort"
	"strconv"
	"strings"
	"time"

	"golang.org/x/crypto/ssh"

	"github.com/calltech-app/serfex/agent/internal/host"
)

// sftpTarget stores files on an SFTP server with OpenSSH's sftp client in
// batch mode. It signs in with the agent's own key (KeyFile), which the
// administrator adds to the server's authorized_keys, and accepts only
// the host key recorded when the target was first tested.
type sftpTarget struct {
	h       host.Host
	spec    TargetSpec
	keyFile string
	tmpDir  string
}

const sftpTimeout = 6 * time.Hour

func (s *sftpTarget) port() int {
	if s.spec.Port == 0 {
		return 22
	}
	return s.spec.Port
}

// knownHostsLine is the host key in known_hosts form.
func knownHostsLine(host string, port int, key string) string {
	name := host
	if port != 22 {
		name = "[" + host + "]:" + strconv.Itoa(port)
	}
	return name + " " + strings.TrimSpace(key) + "\n"
}

func parseHostKey(key string) (ssh.PublicKey, error) {
	pk, _, _, rest, err := ssh.ParseAuthorizedKey([]byte(key))
	if err != nil {
		return nil, err
	}
	if len(strings.TrimSpace(string(rest))) > 0 {
		return nil, errors.New("only one key is allowed")
	}
	return pk, nil
}

// Fingerprint is a host key's SHA256 fingerprint, as ssh shows it.
func Fingerprint(key string) string {
	pk, err := parseHostKey(key)
	if err != nil {
		return ""
	}
	return ssh.FingerprintSHA256(pk)
}

// batch runs sftp commands (one per line); a line starting with "-" may
// fail without stopping the batch.
func (s *sftpTarget) batch(ctx context.Context, commands []string) (string, error) {
	if s.spec.HostKey == "" {
		return "", errors.New("the server's host key is not known yet; test the target first")
	}
	kh, err := os.CreateTemp(s.tmpDir, "known_hosts-")
	if err != nil {
		return "", err
	}
	defer os.Remove(kh.Name())
	if _, err := kh.WriteString(knownHostsLine(s.spec.Host, s.port(), s.spec.HostKey)); err != nil {
		kh.Close()
		return "", err
	}
	kh.Close()
	ctx, cancel := context.WithTimeout(ctx, sftpTimeout)
	defer cancel()
	out, err := s.h.Run(ctx, host.Command{Name: "sftp", Args: []string{
		"-b", "-", "-q",
		"-i", s.keyFile, "-P", strconv.Itoa(s.port()),
		"-o", "BatchMode=yes", "-o", "IdentitiesOnly=yes",
		"-o", "StrictHostKeyChecking=yes", "-o", "UserKnownHostsFile=" + kh.Name(), "-o", "GlobalKnownHostsFile=/dev/null",
		"-o", "ConnectTimeout=20", "-o", "ServerAliveInterval=15", "-o", "ServerAliveCountMax=4",
		"--", s.spec.User + "@" + s.spec.Host,
	}, Stdin: []byte(strings.Join(commands, "\n") + "\n")})
	if err != nil {
		return out, errors.New(sftpReason(out))
	}
	return out, nil
}

// sftpReason picks the line of sftp's output that says what went wrong.
func sftpReason(out string) string {
	lines := strings.Split(strings.TrimSpace(out), "\n")
	for i := len(lines) - 1; i >= 0; i-- {
		l := strings.TrimSpace(lines[i])
		switch {
		case l == "" || strings.HasPrefix(l, "sftp>"):
			continue
		case strings.Contains(l, "Permission denied"):
			return "the server refused the key: add Serfex's public key to the user's authorized_keys (" + l + ")"
		case strings.Contains(l, "Host key verification failed") || strings.Contains(l, "REMOTE HOST IDENTIFICATION HAS CHANGED"):
			return "the server's host key changed since the target was tested; test the target again if this is expected"
		}
		return l
	}
	return "sftp failed"
}

// mkdirs creates every parent of name, ignoring the ones that exist.
func mkdirs(name string) []string {
	var cmds []string
	dir := path.Dir(name)
	if dir == "." || dir == "/" {
		return nil
	}
	parts := strings.Split(dir, "/")
	for i := range parts {
		cmds = append(cmds, "-mkdir "+strings.Join(parts[:i+1], "/"))
	}
	return cmds
}

func (s *sftpTarget) Put(ctx context.Context, name, file string) error {
	if err := validName(name); err != nil {
		return err
	}
	if strings.ContainsAny(file, " \t\n\"'\\") {
		return fmt.Errorf("invalid local path %q", file)
	}
	cmds := append(mkdirs(name), "-rm "+name+".part", "put "+file+" "+name+".part", "-rm "+name, "rename "+name+".part "+name)
	_, err := s.batch(ctx, cmds)
	return err
}

func (s *sftpTarget) Get(ctx context.Context, name string, w io.Writer) error {
	if err := validName(name); err != nil {
		return err
	}
	f, err := os.CreateTemp(s.tmpDir, "get-")
	if err != nil {
		return err
	}
	defer os.Remove(f.Name())
	defer f.Close()
	if _, err := s.batch(ctx, []string{"get " + name + " " + f.Name()}); err != nil {
		return err
	}
	if _, err := f.Seek(0, io.SeekStart); err != nil {
		return err
	}
	_, err = io.Copy(w, f)
	return err
}

func (s *sftpTarget) Delete(ctx context.Context, name string) error {
	if err := validName(name); err != nil {
		return err
	}
	cmds := []string{"-rm " + name}
	// Empty folders go too; a folder with files in it stays.
	for dir := path.Dir(name); dir != "." && dir != "/" && dir != s.spec.Prefix; dir = path.Dir(dir) {
		cmds = append(cmds, "-rmdir "+dir)
	}
	_, err := s.batch(ctx, cmds)
	return err
}

func (s *sftpTarget) List(ctx context.Context, dir string) ([]Object, error) {
	if dir != "" {
		if err := validName(dir); err != nil {
			return nil, err
		}
	} else {
		dir = "."
	}
	var out []Object
	level := []string{dir}
	for depth := 0; depth < 6 && len(level) > 0; depth++ {
		cmds := make([]string, 0, len(level))
		for _, d := range level {
			cmds = append(cmds, "-ls -ln "+d)
		}
		res, err := s.batch(ctx, cmds)
		if err != nil {
			return nil, err
		}
		var next []string
		for _, e := range parseSFTPList(res) {
			name := strings.TrimPrefix(e.name, "./")
			if e.dir {
				next = append(next, name)
				continue
			}
			if !strings.HasSuffix(name, ".part") {
				out = append(out, Object{Name: name, Size: e.size})
			}
		}
		level = next
	}
	sort.Slice(out, func(i, j int) bool { return out[i].Name < out[j].Name })
	return out, nil
}

type sftpEntry struct {
	name string
	size int64
	dir  bool
}

// parseSFTPList reads "ls -ln" output:
//
//	-rw-r--r--    1 1000     1000         1234 Oct 19 03:00 serfex/a/b.tar
func parseSFTPList(out string) []sftpEntry {
	var entries []sftpEntry
	for _, l := range strings.Split(out, "\n") {
		f := strings.Fields(l)
		if len(f) < 9 || strings.HasPrefix(l, "sftp>") || (f[0][0] != '-' && f[0][0] != 'd') {
			continue
		}
		size, err := strconv.ParseInt(f[4], 10, 64)
		if err != nil {
			continue
		}
		name := strings.Join(f[8:], " ")
		if base := path.Base(name); base == "." || base == ".." || validName(strings.TrimPrefix(name, "./")) != nil {
			continue
		}
		entries = append(entries, sftpEntry{name: name, size: size, dir: f[0][0] == 'd'})
	}
	return entries
}

// scanHostKey asks the server for its host key (ed25519 preferred).
func scanHostKey(ctx context.Context, h host.Host, hostName string, port int) (string, error) {
	ctx, cancel := context.WithTimeout(ctx, 30*time.Second)
	defer cancel()
	out, err := h.Run(ctx, host.Command{Name: "ssh-keyscan", Args: []string{"-T", "15", "-p", strconv.Itoa(port), "-t", "ed25519,ecdsa,rsa", "--", hostName}})
	keys := map[string]string{}
	for _, l := range strings.Split(out, "\n") {
		if strings.HasPrefix(l, "#") {
			continue
		}
		f := strings.Fields(l)
		if len(f) != 3 {
			continue
		}
		key := f[1] + " " + f[2]
		if _, err := parseHostKey(key); err == nil {
			keys[f[1]] = key
		}
	}
	for _, t := range []string{"ssh-ed25519", "ecdsa-sha2-nistp256", "ecdsa-sha2-nistp384", "ecdsa-sha2-nistp521", "rsa-sha2-512", "ssh-rsa"} {
		if k, ok := keys[t]; ok {
			return k, nil
		}
	}
	if err != nil {
		return "", fmt.Errorf("could not reach %s:%d over SSH", hostName, port)
	}
	return "", fmt.Errorf("%s:%d did not offer an SSH host key", hostName, port)
}
