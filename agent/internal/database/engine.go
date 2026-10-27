package database

import (
	"context"
	"io"
	"slices"
	"time"

	"github.com/calltech-app/serfex/agent/internal/host"
)

// engine runs one kind of database server.
type engine interface {
	// version is the running server's version, which also tells whether
	// it answers.
	version(ctx context.Context) (string, error)
	createDatabase(ctx context.Context, name string) error
	dropDatabase(ctx context.Context, name string) error
	// dump writes the database as SQL.
	dump(ctx context.Context, name string, w io.Writer, wrap func(host.Command) host.Command) error
	// applyUser creates or changes a user: the password when not empty
	// (required for a new user), the hosts it may connect from besides
	// this machine, and its grants, taking back the ones in prev that are
	// no longer wanted.
	applyUser(ctx context.Context, name, password string, hosts []string, grants []Grant, prev *userState) error
	dropUser(ctx context.Context, name string, prev userState) error
	list(ctx context.Context) (Listing, error)
	// createSession makes a temporary user with full access to one
	// database for a manager sign-in; it stops working at expires.
	createSession(ctx context.Context, user, password, database string, expires time.Time) error
	dropSession(ctx context.Context, user, database string) error
	// credential returns a user's stored password, and whether the user
	// exists.
	credential(ctx context.Context, name string) (Credential, bool, error)
	// createWithCredential creates a user (on this machine's hosts) with a
	// stored password.
	createWithCredential(ctx context.Context, name string, c Credential) error
	// loadDump runs the SQL read from r in an empty database.
	loadDump(ctx context.Context, name string, r io.Reader, wrap func(host.Command) host.Command) error
}

// Listing is what a server holds: customer databases with their size and
// customer users with the hosts they may connect from.
type Listing struct {
	Databases []DatabaseInfo `json:"databases"`
	Users     []UserInfo     `json:"users"`
}

// DatabaseInfo describes a database.
type DatabaseInfo struct {
	Name string `json:"name"`
	// Size is in bytes.
	Size   int64 `json:"size"`
	Tables int   `json:"tables"`
}

// UserInfo describes a user.
type UserInfo struct {
	Name  string   `json:"name"`
	Hosts []string `json:"hosts"`
}

// isCustomerName tells customer databases and users from the servers'
// own and Servik's temporary ones.
func isCustomerName(n string) bool {
	return nameRe.MatchString(n) && !slices.Contains(reserved, n) && !hasPrefixAny(n, "pg_", "mysql")
}

func hasPrefixAny(s string, prefixes ...string) bool {
	for _, p := range prefixes {
		if len(s) >= len(p) && s[:len(p)] == p {
			return true
		}
	}
	return false
}

// grantsOn returns the databases of grants.
func grantsOn(grants []Grant) []string {
	out := make([]string, 0, len(grants))
	for _, g := range grants {
		out = append(out, g.Database)
	}
	return out
}
