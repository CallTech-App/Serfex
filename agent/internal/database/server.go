package database

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"text/template"
	"time"

	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/txn"
)

// product is a database server Servik can install.
type product struct {
	name     string
	server   string
	packages []string
	// marker is a file the package installs; for PostgreSQL a glob over
	// its versions.
	marker  string
	unit    string
	conf    string
	dataDir string
}

var products = map[string]product{
	MySQL: {
		name: MySQL, server: MySQL, packages: []string{"mysql-server"}, marker: "/usr/sbin/mysqld",
		unit: "mysql", conf: "/etc/mysql/mysql.conf.d/zz-servik.cnf", dataDir: "/var/lib/mysql",
	},
	MariaDB: {
		name: MariaDB, server: MySQL, packages: []string{"mariadb-server", "mariadb-client"}, marker: "/usr/sbin/mariadbd",
		unit: "mariadb", conf: "/etc/mysql/mariadb.conf.d/90-servik.cnf", dataDir: "/var/lib/mysql",
	},
	PostgreSQL: {
		name: PostgreSQL, server: PostgreSQL, packages: []string{"postgresql"}, marker: "/usr/lib/postgresql/*/bin/postgres",
		dataDir: "/var/lib/postgresql",
	},
}

// pgHBA is PostgreSQL's client authentication file, which Servik owns.
const pgHBA = stateDir + "/pg_hba.conf"

// installedProduct returns the product serving server, if installed.
// MariaDB is checked before MySQL: it also ships a mysqld.
func (m *Manager) installedProduct(server string) (product, bool) {
	order := []string{MariaDB, MySQL}
	if server == PostgreSQL {
		order = []string{PostgreSQL}
	}
	for _, name := range order {
		p := products[name]
		if p.server != server {
			continue
		}
		if name == PostgreSQL {
			v := m.pgVersion()
			if v == "" {
				continue
			}
			p.unit = "postgresql@" + v + "-main"
			p.conf = "/etc/postgresql/" + v + "/main/conf.d/servik.conf"
			return p, true
		}
		if m.exists(p.marker) {
			return p, true
		}
	}
	return product{}, false
}

// pgVersion is the newest PostgreSQL version installed.
func (m *Manager) pgVersion() string {
	matches, _ := filepath.Glob(m.h.Path(products[PostgreSQL].marker))
	var versions []string
	for _, f := range matches {
		versions = append(versions, filepath.Base(filepath.Dir(filepath.Dir(f))))
	}
	sort.Slice(versions, func(i, j int) bool {
		return len(versions[i]) < len(versions[j]) || (len(versions[i]) == len(versions[j]) && versions[i] < versions[j])
	})
	if len(versions) == 0 {
		return ""
	}
	return versions[len(versions)-1]
}

// ServerStatus describes one database server.
type ServerStatus struct {
	Installed bool `json:"installed"`
	// Product is mysql, mariadb or postgresql.
	Product string `json:"product,omitempty"`
	Running bool   `json:"running"`
	Version string `json:"version,omitempty"`
	// Remote is true while it accepts connections from other machines.
	Remote bool   `json:"remote"`
	Error  string `json:"error,omitempty"`
}

// Status describes the database servers and the web manager.
type Status struct {
	Servers map[string]ServerStatus `json:"servers"`
	Manager ManagerStatus           `json:"manager"`
}

// Status reports which servers are installed and whether they answer.
func (m *Manager) Status(ctx context.Context) Status {
	st := Status{Servers: map[string]ServerStatus{}}
	saved, _ := m.loadState()
	for _, server := range []string{MySQL, PostgreSQL} {
		p, ok := m.installedProduct(server)
		if !ok {
			st.Servers[server] = ServerStatus{}
			continue
		}
		s := ServerStatus{Installed: true, Product: p.name}
		ctx, cancel := context.WithTimeout(ctx, 3*time.Second)
		v, err := m.engines[server].version(ctx)
		cancel()
		if err != nil {
			s.Error = lastLine(err.Error())
		} else {
			s.Running, s.Version = true, v
		}
		if saved != nil {
			s.Remote = len(saved.remoteHosts(server)) > 0
		}
		st.Servers[server] = s
	}
	st.Manager = m.managerStatus(ctx)
	return st
}

func lastLine(s string) string {
	s = strings.TrimSpace(s)
	if i := strings.LastIndex(s, "\n"); i >= 0 {
		return s[i+1:]
	}
	return s
}

// InstallSpec asks for a database server.
type InstallSpec struct {
	// Product is mysql, mariadb or postgresql.
	Product string `json:"product"`
}

// InstallResult describes what Install did.
type InstallResult struct {
	Server    string `json:"server"`
	Product   string `json:"product"`
	Installed bool   `json:"installed"`
	Version   string `json:"version"`
}

// Install installs a database server when missing, configures it for
// Servik and starts it. Running it again repairs a stopped server. A
// server installed by a failed call is removed again; one that was
// already there gets its previous configuration back.
func (m *Manager) Install(ctx context.Context, spec InstallSpec) (res InstallResult, err error) {
	p, ok := products[spec.Product]
	if !ok {
		return InstallResult{}, invalid("product must be mysql, mariadb or postgresql")
	}
	m.mu.Lock()
	defer m.mu.Unlock()

	if have, ok := m.installedProduct(p.server); ok && have.name != p.name {
		return InstallResult{}, invalid("%s is installed; MySQL and MariaDB cannot run on the same server", have.name)
	}
	res = InstallResult{Server: p.server, Product: p.name}
	dataExisted := m.exists(p.dataDir)
	t := txn.New(m.h)
	defer func() {
		if err == nil {
			return
		}
		bg := context.WithoutCancel(ctx)
		if rbErr := t.Rollback(bg); rbErr != nil {
			err = fmt.Errorf("%w (rollback also failed: %v)", err, rbErr)
		}
		if res.Installed {
			_, _ = m.h.Run(bg, host.Command{Name: "systemctl", Args: []string{"disable", "--now", p.unit}})
			_, _ = m.h.Run(bg, host.Command{Name: "apt-get", Args: append([]string{"purge", "-y", "-q"}, p.packages...), Env: aptEnv})
			_, _ = m.h.Run(bg, host.Command{Name: "apt-get", Args: []string{"autoremove", "-y", "-q"}, Env: aptEnv})
			if !dataExisted {
				_ = os.RemoveAll(m.h.Path(p.dataDir))
			}
		} else if p.unit != "" {
			_, _ = m.h.Run(bg, host.Command{Name: "systemctl", Args: []string{"restart", p.unit}})
		}
		err = opError(err)
	}()

	if _, ok := m.installedProduct(p.server); !ok {
		if err := installPackages(ctx, m.h, p.packages, ""); err != nil {
			return res, err
		}
		res.Installed = true
	}
	p, _ = m.installedProduct(p.server)
	if p.server == PostgreSQL && !m.exists(filepath.Dir(filepath.Dir(p.conf))+"/postgresql.conf") {
		// The cluster was dropped (or never created).
		if _, err := m.h.Run(ctx, host.Command{Name: "pg_createcluster", Args: []string{strings.TrimSuffix(strings.TrimPrefix(p.unit, "postgresql@"), "-main"), "main"}}); err != nil {
			return res, err
		}
	}

	st, err := m.loadState()
	if err != nil {
		return res, err
	}
	if err := m.writeNetwork(t, p, st); err != nil {
		return res, err
	}
	if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"enable", p.unit}}); err != nil {
		return res, err
	}
	verb := "start"
	if t.Changed || res.Installed {
		verb = "restart"
	}
	if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{verb, p.unit}}); err != nil {
		return res, fmt.Errorf("%s did not start (see journalctl -u %s): %w", p.unit, p.unit, err)
	}
	if res.Version, err = m.waitFor(ctx, p.server); err != nil {
		return res, err
	}
	if p.server == MySQL {
		st.Flavor = p.name
	}
	if err := m.saveState(st); err != nil {
		return res, err
	}
	return res, nil
}

// waitFor waits until server answers queries.
func (m *Manager) waitFor(ctx context.Context, server string) (string, error) {
	var err error
	for i := 0; i < 60; i++ {
		var v string
		if v, err = m.engines[server].version(ctx); err == nil {
			return v, nil
		}
		select {
		case <-ctx.Done():
			return "", ctx.Err()
		case <-time.After(500 * time.Millisecond):
		}
	}
	return "", fmt.Errorf("%s does not answer: %w", server, err)
}

var aptEnv = []string{"DEBIAN_FRONTEND=noninteractive"}

// installPackages installs packages, refreshing the package lists once if
// the first attempt fails. preseed holds debconf answers.
func installPackages(ctx context.Context, h host.Host, packages []string, preseed string) error {
	if preseed != "" {
		if _, err := h.Run(ctx, host.Command{Name: "debconf-set-selections", Stdin: []byte(preseed)}); err != nil {
			return err
		}
	}
	inst := host.Command{Name: "apt-get", Args: append([]string{"install", "-y", "-q", "--no-install-recommends"}, packages...), Env: aptEnv}
	if _, err := h.Run(ctx, inst); err == nil {
		return nil
	}
	if _, err := h.Run(ctx, host.Command{Name: "apt-get", Args: []string{"update", "-q"}, Env: aptEnv}); err != nil {
		return fmt.Errorf("installing %s: %w", strings.Join(packages, ", "), err)
	}
	if _, err := h.Run(ctx, inst); err != nil {
		return fmt.Errorf("installing %s: %w", strings.Join(packages, ", "), err)
	}
	return nil
}

var mysqlConfTmpl = template.Must(template.New("mysql").Parse(`# {{.Marker}}. Manual changes are overwritten.
# Servik listens on other machines only while a database user may
# connect from one (Veritabanları > kullanıcı > Uzak bağlantı).
[mysqld]
bind-address = {{.Bind}}
{{- if .MySQLX}}
mysqlx-bind-address = 127.0.0.1
{{- end}}
local-infile = 0
`))

var pgConfTmpl = template.Must(template.New("pg").Parse(`# {{.Marker}}. Manual changes are overwritten.
listen_addresses = '{{.Bind}}'
hba_file = '{{.HBA}}'
password_encryption = 'scram-sha-256'
`))

var pgHBATmpl = template.Must(template.New("hba").Parse(`# {{.Marker}}. Manual changes are overwritten.
# TYPE  DATABASE        USER            ADDRESS                 METHOD
local   all             postgres                                peer
local   all             all                                     scram-sha-256
host    all             all             127.0.0.1/32            scram-sha-256
host    all             all             ::1/128                 scram-sha-256
local   replication     all                                     peer
host    replication     all             127.0.0.1/32            scram-sha-256
host    replication     all             ::1/128                 scram-sha-256
{{- if .Remote}}

# Users that may connect from other machines.
{{- range .Remote}}
host    all             "{{.User}}"{{.Pad}} {{.Network}}{{.NetPad}} scram-sha-256
{{- end}}
{{- end}}
`))

type hbaLine struct {
	User, Network, Pad, NetPad string
}

func render(t *template.Template, data any) []byte {
	var b bytes.Buffer
	if err := t.Execute(&b, data); err != nil {
		panic(err)
	}
	return b.Bytes()
}

// writeNetwork writes what decides who may connect: the bind address and,
// for PostgreSQL, the client authentication file. It reports whether the
// server must restart (bind address) or reload (pg_hba.conf).
func (m *Manager) writeNetwork(t *txn.Tx, p product, st *state) error {
	remote := st.remoteHosts(p.server)
	bind := "127.0.0.1"
	if len(remote) > 0 {
		bind = "0.0.0.0"
		switch {
		case !m.hasIPv6():
		case p.name == MariaDB:
			// MariaDB's "::" socket takes IPv6 only; it listens on a
			// list instead.
			bind = "0.0.0.0,::"
		default:
			bind = "::"
		}
	}
	if dir := filepath.Dir(p.conf); !m.exists(dir) {
		if err := t.RootDir(dir, 0o755, 0, 0); err != nil {
			return err
		}
	}
	if p.server == MySQL {
		return t.WriteFile(p.conf, render(mysqlConfTmpl, map[string]any{"Marker": txn.Marker, "Bind": bind, "MySQLX": p.name == MySQL}))
	}
	if len(remote) > 0 {
		bind = "*"
	} else {
		bind = "localhost"
	}
	if err := t.RootDir(stateDir, 0o755, 0, 0); err != nil {
		return err
	}
	var lines []hbaLine
	for _, k := range sortedKeys(st.Users) {
		server, user, _ := strings.Cut(k, "/")
		if server != PostgreSQL {
			continue
		}
		for _, h := range st.Users[k].Hosts {
			for _, n := range pgNetworks(h) {
				lines = append(lines, hbaLine{User: user, Network: n, Pad: pad(len(user)+2, 15), NetPad: pad(len(n), 23)})
			}
		}
	}
	pg, err := m.h.LookupUser("postgres")
	if err != nil {
		return fmt.Errorf("postgres account: %w", err)
	}
	if err := t.WriteFileMode(pgHBA, render(pgHBATmpl, map[string]any{"Marker": txn.Marker, "Remote": lines}), 0o640, 0, pg.GID); err != nil {
		return err
	}
	return t.WriteFile(p.conf, render(pgConfTmpl, map[string]any{"Marker": txn.Marker, "Bind": bind, "HBA": pgHBA}))
}

func pad(n, width int) string {
	if n >= width {
		return ""
	}
	return strings.Repeat(" ", width-n)
}

func sortedKeys[V any](m map[string]V) []string {
	keys := make([]string, 0, len(m))
	for k := range m {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	return keys
}

// applyNetwork rewrites the network settings of server after its users'
// hosts changed: PostgreSQL reloads for a new pg_hba.conf, a new bind
// address restarts the server. A server that rejects the change gets the
// previous files back.
func (m *Manager) applyNetwork(ctx context.Context, server string, st *state) (err error) {
	p, ok := m.installedProduct(server)
	if !ok {
		return ErrNotInstalled
	}
	t := txn.New(m.h)
	before, _ := os.ReadFile(m.h.Path(p.conf))
	if err := m.writeNetwork(t, p, st); err != nil {
		_ = t.Rollback(context.WithoutCancel(ctx))
		return err
	}
	if !t.Changed {
		return nil
	}
	after, _ := os.ReadFile(m.h.Path(p.conf))
	verb := "reload"
	if !bytes.Equal(before, after) {
		verb = "restart"
	}
	if _, err = m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{verb, p.unit}}); err == nil {
		_, err = m.waitFor(ctx, server)
	}
	if err != nil {
		bg := context.WithoutCancel(ctx)
		rbErr := t.Rollback(bg)
		_, _ = m.h.Run(bg, host.Command{Name: "systemctl", Args: []string{"restart", p.unit}})
		return errors.Join(fmt.Errorf("%s rejected the new network settings: %w", p.unit, err), rbErr)
	}
	return nil
}
