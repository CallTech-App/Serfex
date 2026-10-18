package host

import (
	"context"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
)

// Simulator is a Host that keeps every file under Root and fakes commands.
//
// Account commands (useradd, userdel) edit Root/etc/passwd and
// Root/etc/group. File commands run through runuser (install -d, rm -rf,
// tee) are applied under Root. Everything else (nginx -t, systemctl reload)
// succeeds without doing anything. Every command is recorded.
type Simulator struct {
	Root string
	// Fail, when set, is consulted before each command; a non-nil error
	// makes the command fail with it.
	Fail func(Command) error

	mu       sync.Mutex
	commands []Command
}

// NewSimulator returns a simulator rooted at root, with a minimal system
// (root and www-data accounts, Nginx directories and PHP-FPM pool
// directories for phpVersions) created where missing.
func NewSimulator(root string, phpVersions ...string) (*Simulator, error) {
	s := &Simulator{Root: root}
	dirs := []string{"/etc/nginx/sites-available", "/etc/nginx/sites-enabled", "/home", "/run/php", "/var/log"}
	for _, v := range phpVersions {
		dirs = append(dirs, "/etc/php/"+v+"/fpm/pool.d")
	}
	for _, d := range dirs {
		if err := os.MkdirAll(s.Path(d), 0o755); err != nil {
			return nil, err
		}
	}
	for file, seed := range map[string]string{
		"/etc/passwd": "root:x:0:0:root:/root:/bin/bash\nwww-data:x:33:33:www-data:/var/www:/usr/sbin/nologin\n",
		"/etc/group":  "root:x:0:\nwww-data:x:33:\n",
	} {
		if _, err := os.Stat(s.Path(file)); errors.Is(err, os.ErrNotExist) {
			if err := os.WriteFile(s.Path(file), []byte(seed), 0o644); err != nil {
				return nil, err
			}
		}
	}
	return s, nil
}

// Path maps abs under Root.
func (s *Simulator) Path(abs string) string { return joinRoot(s.Root, abs) }

// Chown is a no-op: the simulator runs unprivileged.
func (s *Simulator) Chown(string, int, int) error { return nil }

// LookupUser reads Root/etc/passwd.
func (s *Simulator) LookupUser(name string) (User, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	return lookupPasswd(s.Path("/etc/passwd"), name)
}

// Commands returns the commands run so far.
func (s *Simulator) Commands() []Command {
	s.mu.Lock()
	defer s.mu.Unlock()
	return append([]Command(nil), s.commands...)
}

// Ran reports whether a command whose rendering starts with prefix was run.
func (s *Simulator) Ran(prefix string) bool {
	for _, c := range s.Commands() {
		if strings.HasPrefix(c.String(), prefix) {
			return true
		}
	}
	return false
}

// Run records the command and simulates its effect.
func (s *Simulator) Run(_ context.Context, c Command) (string, error) {
	s.mu.Lock()
	s.commands = append(s.commands, c)
	s.mu.Unlock()

	if s.Fail != nil {
		if err := s.Fail(c); err != nil {
			return err.Error(), commandError(c, err.Error(), errors.New("exit status 1"))
		}
	}

	inner := c
	if c.Name == "runuser" {
		// runuser -u USER -- NAME ARGS...
		if len(c.Args) < 4 || c.Args[0] != "-u" || c.Args[2] != "--" {
			return "", fmt.Errorf("simulator: malformed runuser: %s", c)
		}
		inner = Command{Name: c.Args[3], Args: c.Args[4:], Stdin: c.Stdin}
	}

	var err error
	switch inner.Name {
	case "useradd":
		err = s.useradd(inner.Args)
	case "userdel":
		err = s.userdel(inner.Args)
	case "install":
		err = s.install(inner.Args)
	case "rm":
		err = s.rm(inner.Args)
	case "tee":
		err = s.tee(inner.Args, inner.Stdin)
	}
	if err != nil {
		return err.Error(), commandError(c, err.Error(), errors.New("exit status 1"))
	}
	return "", nil
}

func (s *Simulator) useradd(args []string) error {
	opts := map[string]string{}
	var name string
	for i := 0; i < len(args); i++ {
		a := args[i]
		switch a {
		case "--home-dir", "--shell", "--comment":
			if i+1 >= len(args) {
				return fmt.Errorf("useradd: %s needs a value", a)
			}
			opts[a] = args[i+1]
			i++
		case "--user-group", "--no-create-home":
		default:
			if strings.HasPrefix(a, "-") {
				return fmt.Errorf("useradd: unsupported option %s", a)
			}
			name = a
		}
	}

	s.mu.Lock()
	defer s.mu.Unlock()
	passwd, err := os.ReadFile(s.Path("/etc/passwd"))
	if err != nil {
		return err
	}
	uid := 1000
	for _, line := range strings.Split(string(passwd), "\n") {
		f := strings.Split(line, ":")
		if len(f) != 7 {
			continue
		}
		if f[0] == name {
			return fmt.Errorf("useradd: user '%s' already exists", name)
		}
		if n, _ := strconv.Atoi(f[2]); n >= uid && n < 60000 {
			uid = n + 1
		}
	}
	entry := fmt.Sprintf("%s:x:%d:%d:%s:%s:%s\n", name, uid, uid, opts["--comment"], opts["--home-dir"], opts["--shell"])
	if err := appendFile(s.Path("/etc/passwd"), entry); err != nil {
		return err
	}
	return appendFile(s.Path("/etc/group"), fmt.Sprintf("%s:x:%d:\n", name, uid))
}

func (s *Simulator) userdel(args []string) error {
	if len(args) != 1 {
		return fmt.Errorf("userdel: expected one user, got %v", args)
	}
	name := args[0]
	s.mu.Lock()
	defer s.mu.Unlock()
	found := false
	for _, file := range []string{"/etc/passwd", "/etc/group"} {
		data, err := os.ReadFile(s.Path(file))
		if err != nil {
			return err
		}
		var keep []string
		for _, line := range strings.Split(strings.TrimRight(string(data), "\n"), "\n") {
			if strings.HasPrefix(line, name+":") {
				found = true
				continue
			}
			keep = append(keep, line)
		}
		if err := os.WriteFile(s.Path(file), []byte(strings.Join(keep, "\n")+"\n"), 0o644); err != nil {
			return err
		}
	}
	if !found {
		return fmt.Errorf("userdel: user '%s' does not exist", name)
	}
	return nil
}

// install -d -m MODE DIR...
func (s *Simulator) install(args []string) error {
	if len(args) < 4 || args[0] != "-d" || args[1] != "-m" {
		return fmt.Errorf("install: unsupported arguments %v", args)
	}
	mode, err := strconv.ParseUint(args[2], 8, 32)
	if err != nil {
		return fmt.Errorf("install: bad mode %q", args[2])
	}
	for _, d := range args[3:] {
		if err := os.MkdirAll(s.Path(d), 0o755); err != nil {
			return err
		}
		if err := os.Chmod(s.Path(d), os.FileMode(mode)); err != nil {
			return err
		}
	}
	return nil
}

// rm -rf --one-file-system PATH...
func (s *Simulator) rm(args []string) error {
	if len(args) < 3 || args[0] != "-rf" || args[1] != "--one-file-system" {
		return fmt.Errorf("rm: unsupported arguments %v", args)
	}
	for _, p := range args[2:] {
		if !filepath.IsAbs(p) {
			return fmt.Errorf("rm: relative path %q", p)
		}
		if err := os.RemoveAll(s.Path(p)); err != nil {
			return err
		}
	}
	return nil
}

// tee PATH (stdin is written to PATH)
func (s *Simulator) tee(args []string, stdin []byte) error {
	if len(args) != 1 {
		return fmt.Errorf("tee: expected one path, got %v", args)
	}
	return os.WriteFile(s.Path(args[0]), stdin, 0o644)
}

func appendFile(path, data string) error {
	f, err := os.OpenFile(path, os.O_APPEND|os.O_WRONLY, 0o644)
	if err != nil {
		return err
	}
	if _, err := f.WriteString(data); err != nil {
		f.Close()
		return err
	}
	return f.Close()
}
