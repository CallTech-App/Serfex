// Package database runs the customers' database servers: MySQL or
// MariaDB (one of the two) and PostgreSQL, the databases and users on
// them, and the web database manager (phpMyAdmin and Adminer) the panel
// signs customers into without a password.
//
// These servers are the customers'. The panel keeps its own data in a
// separate PostgreSQL instance that this package never touches.
//
// Names follow cPanel: every database and user starts with the account
// that owns it ("acme_shop", "acme_app"), which is also how live
// monitoring attributes connections. Names Servik makes for itself
// contain a hyphen, which customer names never do: PostgreSQL databases
// are owned by a role "<database>-owner", and one-click manager sign-ins
// use short-lived users "servik-s-<random>".
//
// Layout on the server (Ubuntu 24.04):
//
//	/etc/servik/databases/state.json          users' hosts and grants, databases' sites, manager sessions, root 0600
//	/etc/servik/databases/pg_hba.conf         PostgreSQL client authentication (hba_file points here)
//	/etc/mysql/mysql.conf.d/zz-servik.cnf     MySQL settings (bind address)
//	/etc/mysql/mariadb.conf.d/90-servik.cnf   MariaDB settings (bind address)
//	/etc/postgresql/<v>/main/conf.d/servik.conf  PostgreSQL settings (listen addresses, hba_file)
//	/var/lib/servik/backups/databases/        dumps taken before a database is deleted, root 0700
//	/etc/servik/web/                          servik-web (Nginx) and servik-php (PHP-FPM): Servik's own web stack
//	/var/lib/servik/dbadmin/                  the manager's sign-in pages, sessions and temporary files
//
// The panel keeps databases, users and grants in its own database and
// sends them here. Users are applied whole (password, hosts, grants), so
// sending the same user twice changes nothing.
package database

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"regexp"
	"slices"
	"sort"
	"strings"
	"sync"
	"time"

	"github.com/winchester37/servik/agent/internal/host"
)

const (
	stateDir  = "/etc/servik/databases"
	stateFile = stateDir + "/state.json"
	// BackupDir keeps the dump taken before a database is deleted.
	BackupDir = "/var/lib/servik/backups/databases"
	// BackupRetention is how long those dumps are kept.
	BackupRetention = 30 * 24 * time.Hour

	// MySQL serves MySQL or MariaDB, whichever is installed.
	MySQL = "mysql"
	// PostgreSQL serves PostgreSQL.
	PostgreSQL = "postgresql"
	// MariaDB is the product installed when asked for; it is served as
	// MySQL.
	MariaDB = "mariadb"

	// MaxDatabaseName leaves room for the "-owner" role in PostgreSQL's
	// 63-character identifiers.
	MaxDatabaseName = 54
	// MaxUserName is MySQL's limit for user names.
	MaxUserName = 32
)

// Access levels a user can have on a database.
const (
	AccessAll       = "all"
	AccessReadWrite = "readwrite"
	AccessReadOnly  = "readonly"
)

// ValidationError reports a request that can never succeed as given.
type ValidationError struct{ msg string }

func (e *ValidationError) Error() string { return e.msg }

func invalid(format string, a ...any) error { return &ValidationError{fmt.Sprintf(format, a...)} }

// OperationError reports a valid request that failed on the server.
type OperationError struct{ Err error }

func (e *OperationError) Error() string { return e.Err.Error() }
func (e *OperationError) Unwrap() error { return e.Err }

// ErrNotInstalled is returned by database operations on a server that is
// not installed.
var ErrNotInstalled = errors.New("the database server is not installed")

func opError(err error) error {
	var v *ValidationError
	var o *OperationError
	if err == nil || errors.As(err, &v) || errors.As(err, &o) || errors.Is(err, ErrNotInstalled) {
		return err
	}
	return &OperationError{err}
}

var nameRe = regexp.MustCompile(`^[a-z][a-z0-9_]*$`)

// reserved are names of the servers' own accounts and databases.
var reserved = []string{
	"root", "mysql", "sys", "information_schema", "performance_schema", "debian_sys_maint",
	"postgres", "template0", "template1", "public", "admin", "phpmyadmin",
}

// ValidateDatabaseName checks a database name.
func ValidateDatabaseName(n string) error {
	if !nameRe.MatchString(n) || len(n) > MaxDatabaseName {
		return invalid("database name %q must start with a letter, contain only a-z, 0-9 and _ and be at most %d characters", n, MaxDatabaseName)
	}
	if slices.Contains(reserved, n) || strings.HasPrefix(n, "pg_") {
		return invalid("%q is reserved", n)
	}
	return nil
}

// ValidateUserName checks a database user name.
func ValidateUserName(n string) error {
	if !nameRe.MatchString(n) || len(n) > MaxUserName {
		return invalid("user name %q must start with a letter, contain only a-z, 0-9 and _ and be at most %d characters", n, MaxUserName)
	}
	if slices.Contains(reserved, n) || strings.HasPrefix(n, "pg_") || strings.HasPrefix(n, "mysql") {
		return invalid("%q is reserved", n)
	}
	return nil
}

func validateServer(s string) error {
	if s != MySQL && s != PostgreSQL {
		return invalid("server must be %q or %q", MySQL, PostgreSQL)
	}
	return nil
}

func validatePassword(p string) error {
	switch {
	case len(p) < 8:
		return invalid("password must be at least 8 characters")
	case len(p) > 128:
		return invalid("password must be at most 128 characters")
	case strings.ContainsAny(p, "\x00\n\r"):
		return invalid("password must not contain line breaks")
	}
	return nil
}

// Grant is a user's access to one database.
type Grant struct {
	Database string `json:"database"`
	Access   string `json:"access"`
}

// userState is what the agent remembers about a user: what PostgreSQL's
// client authentication and the bind address are built from, and which
// grants to take back when they change.
type userState struct {
	Hosts  []string `json:"hosts"`
	Grants []Grant  `json:"grants"`
}

// session is a one-click manager sign-in: a temporary user with access to
// one database.
type session struct {
	Server   string    `json:"server"`
	User     string    `json:"user"`
	Database string    `json:"database"`
	Expires  time.Time `json:"expires"`
}

type state struct {
	// Flavor is "mysql" or "mariadb" once Servik set the MySQL server up.
	Flavor string `json:"flavor,omitempty"`
	// Databases maps "<server>/<name>" to the site that uses it.
	Databases map[string]string    `json:"databases"`
	Users     map[string]userState `json:"users"`
	Sessions  []session            `json:"sessions"`
	// Manager is the web manager's setup, once done.
	Manager *ManagerSpec `json:"manager,omitempty"`
	// WebPorts are the ports servik-web listens on, the primary first
	// (panel.web.ports); empty means Manager's port, else DefaultPort.
	WebPorts []int `json:"web_ports,omitempty"`
}

func key(server, name string) string { return server + "/" + name }

// Manager runs the database servers. Operations are serialised.
type Manager struct {
	h       host.Host
	engines map[string]engine
	mu      sync.Mutex
	// now is time.Now, replaced in tests.
	now func() time.Time
	// wrap, when set, wraps dumps and loads (low priority for backups).
	wrap func(host.Command) host.Command
	// Listening, when set, replaces reading the TCP ports other programs
	// listen on (ss), for tests.
	Listening func(context.Context) (map[int]bool, error)
	// Fetch, when set, replaces downloading apt archives' signing keys
	// (nil for a missing one), for tests.
	Fetch func(ctx context.Context, url string) ([]byte, error)
}

// NewManager returns a Manager for the real servers.
func NewManager(h host.Host) *Manager {
	return &Manager{h: h, now: time.Now, engines: map[string]engine{
		MySQL:      &mysqlEngine{h: h},
		PostgreSQL: &pgEngine{h: h},
	}}
}

// NewSimulated returns a Manager whose servers keep their databases and
// users in a file under the host's root, for dry runs.
func NewSimulated(h host.Host) *Manager {
	m := NewManager(h)
	for name := range m.engines {
		m.engines[name] = &simEngine{h: h, server: name}
	}
	return m
}

func (m *Manager) loadState() (*state, error) {
	s := &state{Databases: map[string]string{}, Users: map[string]userState{}}
	data, err := os.ReadFile(m.h.Path(stateFile))
	if errors.Is(err, os.ErrNotExist) {
		return s, nil
	}
	if err != nil {
		return nil, err
	}
	if err := json.Unmarshal(data, s); err != nil {
		return nil, fmt.Errorf("%s: %w", stateFile, err)
	}
	if s.Databases == nil {
		s.Databases = map[string]string{}
	}
	if s.Users == nil {
		s.Users = map[string]userState{}
	}
	return s, nil
}

func (m *Manager) saveState(s *state) error {
	if err := os.MkdirAll(m.h.Path(stateDir), 0o755); err != nil {
		return err
	}
	data, err := json.MarshalIndent(s, "", "  ")
	if err != nil {
		return err
	}
	tmp := m.h.Path(stateFile + ".tmp")
	if err := os.WriteFile(tmp, data, 0o600); err != nil {
		return err
	}
	return os.Rename(tmp, m.h.Path(stateFile))
}

// Sites maps database names (of both servers) to the site that uses them,
// for live monitoring.
func (m *Manager) Sites() map[string]string {
	s, err := m.loadState()
	if err != nil {
		return nil
	}
	out := make(map[string]string, len(s.Databases))
	for k, site := range s.Databases {
		_, name, _ := strings.Cut(k, "/")
		out[name] = site
	}
	return out
}

func (m *Manager) exists(abs string) bool {
	_, err := os.Stat(m.h.Path(abs))
	return err == nil
}

// hasIPv6 reports whether the kernel has IPv6.
func (m *Manager) hasIPv6() bool { return m.exists("/proc/net/if_inet6") }

// remoteHosts lists the remote hosts of server's users, sorted.
func (s *state) remoteHosts(server string) []string {
	seen := map[string]bool{}
	for k, u := range s.Users {
		if !strings.HasPrefix(k, server+"/") {
			continue
		}
		for _, h := range u.Hosts {
			seen[h] = true
		}
	}
	out := make([]string, 0, len(seen))
	for h := range seen {
		out = append(out, h)
	}
	sort.Strings(out)
	return out
}

// run runs a command with a timeout fit for database work.
func run(ctx context.Context, h host.Host, c host.Command) (string, error) {
	ctx, cancel := context.WithTimeout(ctx, 2*time.Minute)
	defer cancel()
	return h.Run(ctx, c)
}
