package database

import (
	"context"
	"encoding/hex"
	"fmt"
	"io"
	"regexp"
	"slices"
	"strings"

	"github.com/calltech-app/serfex/agent/internal/host"
)

// Backups (Yedekler) take dumps of customer databases and the password
// hashes of their users, and put both back: a restored user signs in with
// its old password, so applications keep working without new settings.

// Credential is a user's password as the server stores it: never the
// password itself.
type Credential struct {
	// Plugin is the MySQL or MariaDB authentication plugin.
	Plugin string `json:"plugin,omitempty"`
	// Hash is the hex authentication string (MySQL, MariaDB) or the SCRAM
	// or md5 verifier (PostgreSQL).
	Hash string `json:"hash"`
}

// UserExport is a user's credential, for a backup.
type UserExport struct {
	Server     string     `json:"server"`
	Name       string     `json:"name"`
	Credential Credential `json:"credential"`
}

var (
	mysqlPlugins = []string{"caching_sha2_password", "mysql_native_password", "sha256_password", "ed25519"}
	hexRe        = regexp.MustCompile(`^(?:[0-9A-Fa-f]{2})+$`)
	scramRe      = regexp.MustCompile(`^SCRAM-SHA-256\$[0-9]{1,7}:[A-Za-z0-9+/=]{1,64}\$[A-Za-z0-9+/=]{1,128}:[A-Za-z0-9+/=]{1,128}$`)
	md5Re        = regexp.MustCompile(`^md5[0-9a-f]{32}$`)
)

func (c Credential) validate(server string) error {
	switch {
	case server == PostgreSQL && c.Plugin == "" && (scramRe.MatchString(c.Hash) || md5Re.MatchString(c.Hash)):
		return nil
	case server == MySQL && slices.Contains(mysqlPlugins, c.Plugin) && len(c.Hash) <= 1024 && hexRe.MatchString(c.Hash):
		return nil
	case c.Plugin == "sim":
		return nil
	}
	return invalid("the stored password of the user is not one Serfex can restore")
}

// SetWrap sets how dumps and loads are run (Yedekler runs them at low
// priority).
func (m *Manager) SetWrap(wrap func(host.Command) host.Command) { m.wrap = wrap }

func (m *Manager) wrapped(c host.Command) host.Command {
	if m.wrap == nil {
		return c
	}
	return m.wrap(c)
}

// Dump writes a database as SQL to w.
func (m *Manager) Dump(ctx context.Context, server, name string, w io.Writer) error {
	if err := (DatabaseSpec{Server: server, Name: name}).validate(); err != nil {
		return err
	}
	e, err := m.engineFor(server)
	if err != nil {
		return err
	}
	return opError(e.dump(ctx, name, w, m.wrapped))
}

// Load replaces a database with the dump read from r: the database is
// dropped and created again, the dump loaded, and the grants of its users
// applied again.
func (m *Manager) Load(ctx context.Context, server, name, site string, r io.Reader) error {
	spec := DatabaseSpec{Server: server, Name: name, Site: site}
	if err := spec.validate(); err != nil {
		return err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	e, err := m.engineFor(server)
	if err != nil {
		return err
	}
	st, err := m.loadState()
	if err != nil {
		return opError(err)
	}
	if err := e.dropDatabase(ctx, name); err != nil {
		return opError(err)
	}
	if err := e.createDatabase(ctx, name); err != nil {
		return opError(err)
	}
	st.Databases[key(server, name)] = site
	if err := m.saveState(st); err != nil {
		return opError(err)
	}
	if err := e.loadDump(ctx, name, r, m.wrapped); err != nil {
		return opError(fmt.Errorf("loading the dump of %s failed: %w", name, err))
	}
	for _, k := range sortedKeys(st.Users) {
		u := st.Users[k]
		srv, user, _ := strings.Cut(k, "/")
		if srv != server || !slices.Contains(grantsOn(u.Grants), name) {
			continue
		}
		prev := u
		if err := e.applyUser(ctx, user, "", u.Hosts, u.Grants, &prev); err != nil {
			return opError(err)
		}
	}
	return nil
}

// ExportUsers returns the credentials of users; users that do not exist
// are left out.
func (m *Manager) ExportUsers(ctx context.Context, refs []UserRef) ([]UserExport, error) {
	out := []UserExport{}
	for _, r := range refs {
		if err := validateServer(r.Server); err != nil {
			return nil, err
		}
		if err := ValidateUserName(r.Name); err != nil {
			return nil, err
		}
		e, err := m.engineFor(r.Server)
		if err != nil {
			return nil, err
		}
		c, found, err := e.credential(ctx, r.Name)
		if err != nil {
			return nil, opError(err)
		}
		if found {
			out = append(out, UserExport{Server: r.Server, Name: r.Name, Credential: c})
		}
	}
	return out, nil
}

// RestoreUser makes a user as spec says; one that does not exist yet is
// created with the stored credential instead of a password.
func (m *Manager) RestoreUser(ctx context.Context, spec UserSpec, c Credential) error {
	spec.Password = ""
	if err := spec.validate(); err != nil {
		return err
	}
	if err := c.validate(spec.Server); err != nil {
		return err
	}
	e, err := m.engineFor(spec.Server)
	if err != nil {
		return err
	}
	m.mu.Lock()
	_, found, err := e.credential(ctx, spec.Name)
	if err == nil && !found {
		err = e.createWithCredential(ctx, spec.Name, c)
	}
	m.mu.Unlock()
	if err != nil {
		return opError(err)
	}
	_, err = m.ApplyUser(ctx, spec)
	return err
}

// MySQL and MariaDB.

func (e *mysqlEngine) credential(ctx context.Context, name string) (Credential, bool, error) {
	out, err := e.exec(ctx, "SELECT plugin, HEX(authentication_string) FROM mysql.user WHERE User = "+sqlString(name)+" AND Host = 'localhost';")
	if err != nil {
		return Credential{}, false, err
	}
	line := strings.TrimSpace(out)
	if line == "" {
		return Credential{}, false, nil
	}
	plugin, hash, _ := strings.Cut(line, "\t")
	return Credential{Plugin: plugin, Hash: strings.ToUpper(hash)}, true, nil
}

func (e *mysqlEngine) createWithCredential(ctx context.Context, name string, c Credential) error {
	var b strings.Builder
	maria := e.mariadb(ctx)
	for _, h := range localHosts {
		if maria {
			raw, err := hex.DecodeString(c.Hash)
			if err != nil || strings.ContainsAny(string(raw), "'\\\x00\n") {
				return invalid("the stored password of %s cannot be restored on MariaDB", name)
			}
			fmt.Fprintf(&b, "CREATE USER %s IDENTIFIED VIA %s USING %s;\n", mysqlUser(name, h), c.Plugin, sqlString(string(raw)))
			continue
		}
		fmt.Fprintf(&b, "CREATE USER %s IDENTIFIED WITH %s AS 0x%s;\n", mysqlUser(name, h), c.Plugin, c.Hash)
	}
	_, err := e.exec(ctx, b.String())
	return err
}

func (e *mysqlEngine) loadDump(ctx context.Context, name string, r io.Reader, wrap func(host.Command) host.Command) error {
	_, err := e.h.Run(ctx, wrap(host.Command{Name: "mysql", Args: []string{"--default-character-set=utf8mb4", "--", name}, Input: r}))
	return err
}

// PostgreSQL.

func (e *pgEngine) credential(ctx context.Context, name string) (Credential, bool, error) {
	found, err := e.exists(ctx, "SELECT 1 FROM pg_roles WHERE rolname = "+pgString(name)+";")
	if err != nil || !found {
		return Credential{}, false, err
	}
	out, err := e.exec(ctx, "postgres", "SELECT coalesce(rolpassword, '') FROM pg_authid WHERE rolname = "+pgString(name)+";")
	if err != nil {
		return Credential{}, false, err
	}
	return Credential{Hash: strings.TrimSpace(out)}, true, nil
}

func (e *pgEngine) createWithCredential(ctx context.Context, name string, c Credential) error {
	_, err := e.exec(ctx, "postgres", fmt.Sprintf("CREATE ROLE %s LOGIN PASSWORD %s;\n", pgIdent(name), pgString(c.Hash)))
	return err
}

func (e *pgEngine) loadDump(ctx context.Context, name string, r io.Reader, wrap func(host.Command) host.Command) error {
	_, err := e.h.Run(ctx, wrap(host.AsUser("postgres", host.Command{Name: "psql", Args: []string{"-X", "-q", "-v", "ON_ERROR_STOP=1", "-d", name}, Input: r})))
	return err
}

// Dry runs.

func (e *simEngine) credential(_ context.Context, name string) (Credential, bool, error) {
	d, err := e.load()
	if err != nil {
		return Credential{}, false, err
	}
	if _, ok := d.Users[name]; !ok {
		return Credential{}, false, nil
	}
	return Credential{Plugin: "sim", Hash: hex.EncodeToString([]byte(name))}, true, nil
}

func (e *simEngine) createWithCredential(_ context.Context, name string, _ Credential) error {
	d, err := e.load()
	if err != nil {
		return err
	}
	d.Users[name] = []string{}
	return e.save(d)
}

func (e *simEngine) loadDump(_ context.Context, name string, r io.Reader, _ func(host.Command) host.Command) error {
	_, err := io.Copy(io.Discard, r)
	return err
}
