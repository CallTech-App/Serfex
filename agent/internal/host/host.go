// Package host is the boundary between Serfex's provisioning logic and the
// machine it changes.
//
// Everything that touches the system (files, commands, user accounts) goes
// through a Host. Production uses System, which acts on the real machine.
// Tests and dry runs use Simulator, which keeps all files under a scratch
// directory and fakes the commands, so provisioning can be exercised without
// root, Nginx or PHP-FPM.
package host

import (
	"bufio"
	"context"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
)

// ErrUserNotFound is returned by LookupUser when the account does not exist.
var ErrUserNotFound = errors.New("user not found")

// Command is a program to run, with optional standard input and extra
// environment variables (NAME=value).
type Command struct {
	Name  string
	Args  []string
	Stdin []byte
	Env   []string
	// Stream, when set, receives the output while the command runs (for
	// live deploy logs). Run still returns the tail of the output.
	Stream io.Writer
	// Stdout, when set, receives the standard output alone (an archive or
	// a database dump); Run then returns the standard error.
	Stdout io.Writer
	// Input, when set, is the standard input instead of Stdin, for data
	// too big to hold in memory.
	Input io.Reader
}

// String renders the command for logs and error messages.
func (c Command) String() string {
	return strings.TrimSpace(c.Name + " " + strings.Join(c.Args, " "))
}

// AsUser wraps a command so that it runs with the privileges of user.
// Anything inside a customer's home directory is changed this way, so a
// symlink planted by the customer can never redirect a root write.
func AsUser(user string, c Command) Command {
	return Command{
		Name:   "runuser",
		Args:   append([]string{"-u", user, "--", c.Name}, c.Args...),
		Stdin:  c.Stdin,
		Stream: c.Stream,
		Stdout: c.Stdout,
		Input:  c.Input,
	}
}

// Process is a command started with Start that keeps running.
type Process interface {
	// Write sends data to the command's standard input.
	Write(p []byte) (int, error)
	// Wait waits for the command to exit.
	Wait() error
	// Kill stops the command.
	Kill() error
}

// Starter is a Host that can start commands that keep running, writing
// their output to a file while the caller feeds their input (Tinker).
type Starter interface {
	Start(c Command, output io.Writer) (Process, error)
}

// User is an entry of the system account database.
type User struct {
	Name    string
	UID     int
	GID     int
	Comment string
	Home    string
	Shell   string
}

// Host performs system changes.
type Host interface {
	// Path maps an absolute system path to the path to open on disk.
	Path(abs string) string
	// Run executes a command and returns its combined output.
	Run(ctx context.Context, c Command) (string, error)
	// Chown changes ownership without following symlinks.
	Chown(abs string, uid, gid int) error
	// LookupUser reads an account from the passwd database.
	LookupUser(name string) (User, error)
}

// lookupPasswd reads name from a passwd(5) formatted file.
func lookupPasswd(file, name string) (User, error) {
	f, err := os.Open(file)
	if err != nil {
		return User{}, err
	}
	defer f.Close()

	s := bufio.NewScanner(f)
	for s.Scan() {
		fields := strings.Split(s.Text(), ":")
		if len(fields) != 7 || fields[0] != name {
			continue
		}
		uid, err1 := strconv.Atoi(fields[2])
		gid, err2 := strconv.Atoi(fields[3])
		if err1 != nil || err2 != nil {
			return User{}, fmt.Errorf("malformed passwd entry for %q", name)
		}
		return User{
			Name:    fields[0],
			UID:     uid,
			GID:     gid,
			Comment: fields[4],
			Home:    fields[5],
			Shell:   fields[6],
		}, nil
	}
	if err := s.Err(); err != nil {
		return User{}, err
	}
	return User{}, ErrUserNotFound
}

// LookupGroup returns the ID of a group in h's group(5) file.
func LookupGroup(h Host, name string) (int, error) {
	data, err := os.ReadFile(h.Path("/etc/group"))
	if err != nil {
		return 0, err
	}
	for _, line := range strings.Split(string(data), "\n") {
		fields := strings.Split(line, ":")
		if len(fields) == 4 && fields[0] == name {
			gid, err := strconv.Atoi(fields[2])
			if err != nil {
				return 0, fmt.Errorf("malformed group entry for %q", name)
			}
			return gid, nil
		}
	}
	return 0, fmt.Errorf("group %s not found", name)
}

// commandError formats a failed command with the tail of its output.
func commandError(c Command, out string, err error) error {
	out = strings.TrimSpace(out)
	if len(out) > 2000 {
		out = "..." + out[len(out)-2000:]
	}
	if out == "" {
		return fmt.Errorf("%s: %w", c, err)
	}
	return fmt.Errorf("%s: %w: %s", c, err, out)
}

// joinRoot places abs under root. abs must be absolute.
func joinRoot(root, abs string) string {
	if root == "" {
		return filepath.Clean(abs)
	}
	return filepath.Join(root, filepath.Clean("/"+abs))
}

// ExitCode returns the exit status of a failed command: 0 for no error and
// -1 when the command did not run at all.
func ExitCode(err error) int {
	if err == nil {
		return 0
	}
	var ee *exec.ExitError
	if errors.As(err, &ee) {
		return ee.ExitCode()
	}
	var se *ExitStatus
	if errors.As(err, &se) {
		return se.Code
	}
	return -1
}
