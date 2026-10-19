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

// localHosts are the hosts every MySQL user gets: the socket and TCP on
// the loopback address (which Laravel's DB_HOST=127.0.0.1 uses when name
// resolution is off).
var localHosts = []string{"localhost", "127.0.0.1"}

// mysqlPrivileges are the privileges of each access level.
var mysqlPrivileges = map[string]string{
	AccessAll:       "ALL PRIVILEGES",
	AccessReadWrite: "SELECT, INSERT, UPDATE, DELETE, EXECUTE, LOCK TABLES, CREATE TEMPORARY TABLES, SHOW VIEW",
	AccessReadOnly:  "SELECT, LOCK TABLES, SHOW VIEW",
}

// mysqlEngine runs MySQL or MariaDB through the mysql client as root,
// which both authenticate through the Unix socket on Ubuntu.
type mysqlEngine struct {
	h host.Host
}

// sqlString quotes a string literal. The script sets a sql_mode without
// NO_BACKSLASH_ESCAPES first, so backslashes are escapes.
func sqlString(s string) string {
	r := strings.NewReplacer(`\`, `\\`, `'`, `\'`, "\x00", `\0`, "\x1a", `\Z`)
	return "'" + r.Replace(s) + "'"
}

func mysqlUser(name, host string) string { return sqlString(name) + "@" + sqlString(host) }

// grantTarget names a database in GRANT and REVOKE, where _ and % are
// wildcards unless escaped.
func grantTarget(db string) string {
	return "`" + strings.NewReplacer("_", `\_`, "%", `\%`).Replace(db) + "`.*"
}

const mysqlPrelude = "SET SESSION sql_mode = REPLACE(@@sql_mode, 'NO_BACKSLASH_ESCAPES', '');\n"

func (e *mysqlEngine) exec(ctx context.Context, script string) (string, error) {
	return run(ctx, e.h, host.Command{Name: "mysql", Args: []string{"--batch", "--skip-column-names"}, Stdin: []byte(mysqlPrelude + script)})
}

func (e *mysqlEngine) version(ctx context.Context) (string, error) {
	out, err := e.exec(ctx, "SELECT VERSION();")
	return strings.TrimSpace(out), err
}

func (e *mysqlEngine) mariadb(ctx context.Context) bool {
	v, _ := e.version(ctx)
	return strings.Contains(strings.ToLower(v), "mariadb")
}

func (e *mysqlEngine) createDatabase(ctx context.Context, name string) error {
	_, err := e.exec(ctx, fmt.Sprintf("CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;", name))
	return err
}

func (e *mysqlEngine) dropDatabase(ctx context.Context, name string) error {
	_, err := e.exec(ctx, fmt.Sprintf("DROP DATABASE IF EXISTS `%s`;", name))
	return err
}

func (e *mysqlEngine) dump(ctx context.Context, name string, w io.Writer) error {
	_, err := e.h.Run(ctx, host.Command{Name: "mysqldump", Args: []string{
		"--single-transaction", "--routines", "--triggers", "--events", "--no-tablespaces", "--default-character-set=utf8mb4", name,
	}, Stdout: w})
	return err
}

// hostsOf lists the hosts user exists for.
func (e *mysqlEngine) hostsOf(ctx context.Context, user string) ([]string, error) {
	out, err := e.exec(ctx, "SELECT Host FROM mysql.user WHERE User = "+sqlString(user)+";")
	if err != nil {
		return nil, err
	}
	var hosts []string
	for _, l := range strings.Split(out, "\n") {
		if l = strings.TrimSpace(l); l != "" {
			hosts = append(hosts, l)
		}
	}
	return hosts, nil
}

// cloneStatement returns the CREATE USER statement of an existing
// user@from, for creating user@host with the same password without
// knowing it.
func (e *mysqlEngine) cloneStatement(ctx context.Context, user, from string) (string, error) {
	q := "SHOW CREATE USER " + mysqlUser(user, from) + ";"
	if !e.mariadb(ctx) {
		// MySQL's caching_sha2_password hashes are binary.
		q = "SET SESSION print_identified_with_as_hex = ON;\n" + q
	}
	out, err := e.exec(ctx, q)
	if err != nil {
		return "", err
	}
	stmt := strings.TrimSpace(out)
	prefix := "CREATE USER `" + user + "`@`" + from + "`"
	if !strings.HasPrefix(stmt, prefix) {
		return "", fmt.Errorf("unexpected SHOW CREATE USER output for %s@%s", user, from)
	}
	return stmt, nil
}

func (e *mysqlEngine) applyUser(ctx context.Context, name, password string, hosts []string, grants []Grant, _ *userState) error {
	current, err := e.hostsOf(ctx, name)
	if err != nil {
		return err
	}
	if len(current) == 0 && password == "" {
		return invalid("a password is required for the new user %s", name)
	}
	want := slices.Clone(localHosts)
	for _, h := range hosts {
		if mh := mysqlHost(h); !slices.Contains(want, mh) {
			want = append(want, mh)
		}
	}

	var b strings.Builder
	var clone, clonePrefix string
	for _, h := range want {
		switch {
		case slices.Contains(current, h) && password != "":
			fmt.Fprintf(&b, "ALTER USER %s IDENTIFIED BY %s;\n", mysqlUser(name, h), sqlString(password))
		case slices.Contains(current, h):
		case password != "":
			fmt.Fprintf(&b, "CREATE USER %s IDENTIFIED BY %s;\n", mysqlUser(name, h), sqlString(password))
		default:
			// A new host keeps the password the user already has.
			if clone == "" {
				from := current[0]
				if slices.Contains(current, "localhost") {
					from = "localhost"
				}
				if clone, err = e.cloneStatement(ctx, name, from); err != nil {
					return err
				}
				clonePrefix = "CREATE USER `" + name + "`@`" + from + "`"
			}
			b.WriteString(strings.Replace(clone, clonePrefix, "CREATE USER "+mysqlUser(name, h), 1) + ";\n")
		}
	}
	for _, h := range current {
		if !slices.Contains(want, h) {
			fmt.Fprintf(&b, "DROP USER %s;\n", mysqlUser(name, h))
		}
	}
	for _, h := range want {
		fmt.Fprintf(&b, "REVOKE ALL PRIVILEGES, GRANT OPTION FROM %s;\n", mysqlUser(name, h))
		for _, g := range grants {
			fmt.Fprintf(&b, "GRANT %s ON %s TO %s;\n", mysqlPrivileges[g.Access], grantTarget(g.Database), mysqlUser(name, h))
		}
	}
	_, err = e.exec(ctx, b.String())
	return err
}

func (e *mysqlEngine) dropUser(ctx context.Context, name string, _ userState) error {
	current, err := e.hostsOf(ctx, name)
	if err != nil || len(current) == 0 {
		return err
	}
	var b strings.Builder
	for _, h := range current {
		fmt.Fprintf(&b, "DROP USER %s;\n", mysqlUser(name, h))
	}
	_, err = e.exec(ctx, b.String())
	return err
}

func (e *mysqlEngine) list(ctx context.Context) (Listing, error) {
	q := "SELECT s.SCHEMA_NAME, IFNULL(SUM(t.DATA_LENGTH + t.INDEX_LENGTH), 0), COUNT(t.TABLE_NAME) FROM information_schema.SCHEMATA s " +
		"LEFT JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = s.SCHEMA_NAME GROUP BY s.SCHEMA_NAME;\n" +
		"SELECT User, Host FROM mysql.user ORDER BY User, Host;"
	if !e.mariadb(ctx) {
		// MySQL caches table statistics for a day by default.
		q = "SET SESSION information_schema_stats_expiry = 0;\n" + q
	}
	out, err := e.exec(ctx, q)
	if err != nil {
		return Listing{}, err
	}
	l := Listing{Databases: []DatabaseInfo{}, Users: []UserInfo{}}
	users := map[string]int{}
	for _, line := range strings.Split(out, "\n") {
		f := strings.Split(line, "\t")
		switch len(f) {
		case 3:
			if !isCustomerName(f[0]) {
				continue
			}
			size, _ := strconv.ParseInt(f[1], 10, 64)
			tables, _ := strconv.Atoi(f[2])
			l.Databases = append(l.Databases, DatabaseInfo{Name: f[0], Size: size, Tables: tables})
		case 2:
			if !isCustomerName(f[0]) {
				continue
			}
			i, ok := users[f[0]]
			if !ok {
				i = len(l.Users)
				users[f[0]] = i
				l.Users = append(l.Users, UserInfo{Name: f[0], Hosts: []string{}})
			}
			if !slices.Contains(localHosts, f[1]) {
				l.Users[i].Hosts = append(l.Users[i].Hosts, f[1])
			}
		}
	}
	return l, nil
}

func (e *mysqlEngine) createSession(ctx context.Context, user, password, database string, _ time.Time) error {
	u := mysqlUser(user, "localhost")
	_, err := e.exec(ctx, fmt.Sprintf("CREATE USER %s IDENTIFIED BY %s;\nGRANT ALL PRIVILEGES ON %s TO %s;\n", u, sqlString(password), grantTarget(database), u))
	return err
}

func (e *mysqlEngine) dropSession(ctx context.Context, user, _ string) error {
	_, err := e.exec(ctx, "DROP USER IF EXISTS "+mysqlUser(user, "localhost")+";")
	return err
}
