package database

import (
	"context"
	"fmt"
	"io"
	"slices"
	"strconv"
	"strings"
	"time"

	"github.com/winchester37/servik/agent/internal/host"
)

// pgEngine runs PostgreSQL through psql as the postgres account.
//
// Each customer database is owned by a role "<database>-owner" that
// cannot log in. A user with full access is a member of it and, through a
// per-database role setting, acts as it in that database: tables any of
// them creates belong to the owner role, so every user with full access
// can use them. Read-write and read-only users get table privileges
// instead, also on tables created later (default privileges).
type pgEngine struct {
	h host.Host
}

func pgIdent(n string) string { return `"` + strings.ReplaceAll(n, `"`, `""`) + `"` }

// pgString quotes a string literal; the script turns standard
// conforming strings on first.
func pgString(s string) string { return "'" + strings.ReplaceAll(s, "'", "''") + "'" }

func ownerRole(db string) string { return db + "-owner" }

func (e *pgEngine) exec(ctx context.Context, db, script string) (string, error) {
	return run(ctx, e.h, host.AsUser("postgres", host.Command{
		Name:  "psql",
		Args:  []string{"-X", "-q", "-A", "-t", "-F", "\t", "-v", "ON_ERROR_STOP=1", "-d", db},
		Stdin: []byte("SET standard_conforming_strings = on;\n" + script),
	}))
}

func (e *pgEngine) version(ctx context.Context) (string, error) {
	out, err := e.exec(ctx, "postgres", "SHOW server_version;")
	v, _, _ := strings.Cut(strings.TrimSpace(out), " ")
	return v, err
}

func (e *pgEngine) exists(ctx context.Context, query string) (bool, error) {
	out, err := e.exec(ctx, "postgres", query)
	return strings.TrimSpace(out) == "1", err
}

func (e *pgEngine) createDatabase(ctx context.Context, name string) error {
	owner := ownerRole(name)
	var b strings.Builder
	found, err := e.exists(ctx, "SELECT 1 FROM pg_roles WHERE rolname = "+pgString(owner)+";")
	if err != nil {
		return err
	}
	if !found {
		fmt.Fprintf(&b, "CREATE ROLE %s NOLOGIN;\n", pgIdent(owner))
	}
	if found, err = e.exists(ctx, "SELECT 1 FROM pg_database WHERE datname = "+pgString(name)+";"); err != nil {
		return err
	}
	if !found {
		fmt.Fprintf(&b, "CREATE DATABASE %s OWNER %s TEMPLATE template0 ENCODING 'UTF8';\n", pgIdent(name), pgIdent(owner))
	}
	fmt.Fprintf(&b, "REVOKE ALL ON DATABASE %s FROM PUBLIC;\nGRANT CONNECT, TEMPORARY ON DATABASE %s TO %s;\n", pgIdent(name), pgIdent(name), pgIdent(owner))
	_, err = e.exec(ctx, "postgres", b.String())
	return err
}

func (e *pgEngine) dropDatabase(ctx context.Context, name string) error {
	_, err := e.exec(ctx, "postgres", fmt.Sprintf("DROP DATABASE IF EXISTS %s WITH (FORCE);\nDROP ROLE IF EXISTS %s;\n", pgIdent(name), pgIdent(ownerRole(name))))
	return err
}

func (e *pgEngine) dump(ctx context.Context, name string, w io.Writer) error {
	_, err := e.h.Run(ctx, host.AsUser("postgres", host.Command{Name: "pg_dump", Args: []string{"--format=plain", "--no-acl", "-d", name}, Stdout: w}))
	return err
}

// databases lists the customer databases that exist.
func (e *pgEngine) databases(ctx context.Context) ([]string, error) {
	out, err := e.exec(ctx, "postgres", "SELECT datname FROM pg_database WHERE NOT datistemplate;")
	if err != nil {
		return nil, err
	}
	var dbs []string
	for _, l := range strings.Split(out, "\n") {
		if l = strings.TrimSpace(l); isCustomerName(l) {
			dbs = append(dbs, l)
		}
	}
	return dbs, nil
}

// revoke takes everything user has on db back (run in db).
func revokeIn(owner, user string) string {
	o, u := pgIdent(owner), pgIdent(user)
	return fmt.Sprintf(`REVOKE ALL ON ALL TABLES IN SCHEMA public FROM %[2]s;
REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM %[2]s;
REVOKE ALL ON SCHEMA public FROM %[2]s;
ALTER DEFAULT PRIVILEGES FOR ROLE %[1]s IN SCHEMA public REVOKE ALL ON TABLES FROM %[2]s;
ALTER DEFAULT PRIVILEGES FOR ROLE %[1]s IN SCHEMA public REVOKE ALL ON SEQUENCES FROM %[2]s;
`, o, u)
}

// grantIn gives user table privileges on db (run in db).
func grantIn(owner, user, access string) string {
	tables, seqs := "SELECT, INSERT, UPDATE, DELETE", "USAGE, SELECT, UPDATE"
	if access == AccessReadOnly {
		tables, seqs = "SELECT", "SELECT"
	}
	o, u := pgIdent(owner), pgIdent(user)
	return fmt.Sprintf(`GRANT USAGE ON SCHEMA public TO %[2]s;
GRANT %[3]s ON ALL TABLES IN SCHEMA public TO %[2]s;
GRANT %[4]s ON ALL SEQUENCES IN SCHEMA public TO %[2]s;
ALTER DEFAULT PRIVILEGES FOR ROLE %[1]s IN SCHEMA public GRANT %[3]s ON TABLES TO %[2]s;
ALTER DEFAULT PRIVILEGES FOR ROLE %[1]s IN SCHEMA public GRANT %[4]s ON SEQUENCES TO %[2]s;
`, o, u, tables, seqs)
}

func (e *pgEngine) applyUser(ctx context.Context, name, password string, _ []string, grants []Grant, prev *userState) error {
	found, err := e.exists(ctx, "SELECT 1 FROM pg_roles WHERE rolname = "+pgString(name)+";")
	if err != nil {
		return err
	}
	if !found && password == "" {
		return invalid("a password is required for the new user %s", name)
	}
	existing, err := e.databases(ctx)
	if err != nil {
		return err
	}
	touched := grantsOn(grants)
	if prev != nil {
		touched = append(touched, grantsOn(prev.Grants)...)
	}
	slices.Sort(touched)
	touched = slices.Compact(touched)

	u := pgIdent(name)
	var b strings.Builder
	switch {
	case !found:
		fmt.Fprintf(&b, "CREATE ROLE %s LOGIN PASSWORD %s;\n", u, pgString(password))
	case password != "":
		fmt.Fprintf(&b, "ALTER ROLE %s LOGIN PASSWORD %s;\n", u, pgString(password))
	}
	// Customer users never get to see other customers' databases.
	for _, db := range touched {
		if !slices.Contains(existing, db) {
			continue
		}
		fmt.Fprintf(&b, "REVOKE %s FROM %s;\nALTER ROLE %s IN DATABASE %s RESET role;\nREVOKE ALL ON DATABASE %s FROM %s;\n",
			pgIdent(ownerRole(db)), u, u, pgIdent(db), pgIdent(db), u)
	}
	for _, g := range grants {
		if !slices.Contains(existing, g.Database) {
			return invalid("database %s does not exist", g.Database)
		}
		if g.Access == AccessAll {
			fmt.Fprintf(&b, "GRANT %s TO %s;\nALTER ROLE %s IN DATABASE %s SET role = %s;\n", pgIdent(ownerRole(g.Database)), u, u, pgIdent(g.Database), pgString(ownerRole(g.Database)))
		} else {
			fmt.Fprintf(&b, "GRANT CONNECT ON DATABASE %s TO %s;\n", pgIdent(g.Database), u)
		}
	}
	if _, err := e.exec(ctx, "postgres", b.String()); err != nil {
		return err
	}
	// Table privileges live in each database.
	for _, db := range touched {
		if !slices.Contains(existing, db) {
			continue
		}
		script := revokeIn(ownerRole(db), name)
		for _, g := range grants {
			if g.Database == db && g.Access != AccessAll {
				script += grantIn(ownerRole(db), name, g.Access)
			}
		}
		if _, err := e.exec(ctx, db, script); err != nil {
			return err
		}
	}
	return nil
}

func (e *pgEngine) dropUser(ctx context.Context, name string, prev userState) error {
	found, err := e.exists(ctx, "SELECT 1 FROM pg_roles WHERE rolname = "+pgString(name)+";")
	if err != nil || !found {
		return err
	}
	existing, err := e.databases(ctx)
	if err != nil {
		return err
	}
	// Objects the user owns (it should own none) go to the database's
	// owner role; its privileges go with DROP OWNED.
	for _, db := range existing {
		if !slices.Contains(grantsOn(prev.Grants), db) {
			continue
		}
		if _, err := e.exec(ctx, db, fmt.Sprintf("REASSIGN OWNED BY %s TO %s;\nDROP OWNED BY %s;\n", pgIdent(name), pgIdent(ownerRole(db)), pgIdent(name))); err != nil {
			return err
		}
	}
	_, err = e.exec(ctx, "postgres", fmt.Sprintf("DROP OWNED BY %s;\nDROP ROLE %s;\n", pgIdent(name), pgIdent(name)))
	return err
}

func (e *pgEngine) list(ctx context.Context) (Listing, error) {
	out, err := e.exec(ctx, "postgres", "SELECT 'd', datname, pg_database_size(datname) FROM pg_database WHERE NOT datistemplate;\n"+
		"SELECT 'u', rolname, '' FROM pg_roles WHERE rolcanlogin ORDER BY rolname;")
	if err != nil {
		return Listing{}, err
	}
	l := Listing{Databases: []DatabaseInfo{}, Users: []UserInfo{}}
	for _, line := range strings.Split(out, "\n") {
		f := strings.Split(line, "\t")
		if len(f) != 3 || !isCustomerName(f[1]) {
			continue
		}
		if f[0] == "d" {
			size, _ := strconv.ParseInt(f[2], 10, 64)
			l.Databases = append(l.Databases, DatabaseInfo{Name: f[1], Size: size, Tables: e.tables(ctx, f[1])})
		} else {
			l.Users = append(l.Users, UserInfo{Name: f[1], Hosts: []string{}})
		}
	}
	return l, nil
}

// tables counts the tables of a database (-1 when it cannot be read).
func (e *pgEngine) tables(ctx context.Context, db string) int {
	out, err := e.exec(ctx, db, "SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace "+
		"WHERE c.relkind IN ('r', 'p') AND n.nspname NOT IN ('pg_catalog', 'information_schema') AND n.nspname NOT LIKE 'pg\\_toast%';")
	if err != nil {
		return -1
	}
	n, err := strconv.Atoi(strings.TrimSpace(out))
	if err != nil {
		return -1
	}
	return n
}

func (e *pgEngine) createSession(ctx context.Context, user, password, database string, expires time.Time) error {
	u := pgIdent(user)
	_, err := e.exec(ctx, "postgres", fmt.Sprintf("CREATE ROLE %s LOGIN PASSWORD %s VALID UNTIL %s;\nGRANT %s TO %s;\nALTER ROLE %s IN DATABASE %s SET role = %s;\n",
		u, pgString(password), pgString(expires.UTC().Format(time.RFC3339)), pgIdent(ownerRole(database)), u, u, pgIdent(database), pgString(ownerRole(database))))
	return err
}

func (e *pgEngine) dropSession(ctx context.Context, user, _ string) error {
	found, err := e.exists(ctx, "SELECT 1 FROM pg_roles WHERE rolname = "+pgString(user)+";")
	if err != nil || !found {
		return err
	}
	// Its connections end first; it owns nothing (it acted as the owner
	// role).
	_, err = e.exec(ctx, "postgres", fmt.Sprintf("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE usename = %s;\nDROP ROLE %s;\n", pgString(user), pgIdent(user)))
	return err
}
