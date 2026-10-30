package monitor

import (
	"context"
	"os"
	"strconv"
	"strings"
	"time"

	"github.com/calltech-app/serfex/agent/internal/host"
)

const (
	mysqlSocket    = "/run/mysqld/mysqld.sock"
	postgresSocket = "/run/postgresql/.s.PGSQL.5432"
)

// readDatabases lists the connections of the customers' database servers
// that run on this machine. Customer databases and users are named after
// the account (cPanel style: "<account>" or "<account>_<name>"), which is
// how they are attributed to sites.
func readDatabases(ctx context.Context, h host.Host) ([]dbConn, []string) {
	var conns []dbConn
	var servers []string
	if _, err := os.Stat(h.Path(mysqlSocket)); err == nil {
		c, err := query(ctx, h, host.Command{Name: "mysql", Args: []string{"--batch", "--skip-column-names", "-e",
			"SELECT USER, IFNULL(DB, ''), COMMAND, TIME FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID()"}}, "mysql")
		if err == nil {
			conns = append(conns, c...)
			servers = append(servers, "mysql")
		}
	}
	if _, err := os.Stat(h.Path(postgresSocket)); err == nil {
		c, err := query(ctx, h, asPostgres(host.Command{Name: "psql", Args: []string{"-XAtq", "-F", "\t", "-c",
			"SELECT usename, datname, CASE WHEN state = 'active' THEN 'Query' ELSE 'Sleep' END, " +
				"COALESCE(EXTRACT(EPOCH FROM now() - query_start)::int, 0) FROM pg_stat_activity " +
				"WHERE backend_type = 'client backend' AND pid <> pg_backend_pid()"}}), "postgresql")
		if err == nil {
			conns = append(conns, c...)
			servers = append(servers, "postgresql")
		}
	}
	return conns, servers
}

// asPostgres runs c as the postgres account the way runuser would, but
// without a PAM login session: runuser opens and closes one on every
// sample, and every two seconds the agent's journal got a pair of
// "session opened/closed for user postgres" lines.
func asPostgres(c host.Command) host.Command {
	return host.Command{Name: "setpriv", Args: append([]string{"--reuid=postgres", "--regid=postgres", "--init-groups", "--reset-env", "--", c.Name}, c.Args...)}
}

func query(ctx context.Context, h host.Host, c host.Command, server string) ([]dbConn, error) {
	ctx, cancel := context.WithTimeout(ctx, time.Second)
	defer cancel()
	out, err := h.Run(ctx, c)
	if err != nil {
		return nil, err
	}
	var conns []dbConn
	for _, line := range strings.Split(out, "\n") {
		f := strings.Split(line, "\t")
		if len(f) != 4 || f[0] == "" {
			continue
		}
		cmd := f[2]
		if cmd == "Daemon" || cmd == "Binlog Dump" {
			continue
		}
		secs, _ := strconv.Atoi(f[3])
		active := cmd != "Sleep"
		if !active {
			secs = 0
		}
		conns = append(conns, dbConn{Server: server, User: f[0], DB: f[1], Active: active, Seconds: secs})
	}
	return conns, nil
}

// ownsDatabase reports whether a database user or name belongs to account.
func ownsDatabase(account, name string) bool {
	return name == account || strings.HasPrefix(name, account+"_")
}
