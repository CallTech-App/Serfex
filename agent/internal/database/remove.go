package database

import (
	"context"
	"fmt"
	"io"
	"os"
	"strings"

	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/i18n"
	"github.com/winchester37/servik/agent/internal/txn"
)

// removePackages are what removing a product purges: the server and the
// version packages it pulled in. PostgreSQL keeps its packages, which
// other clusters on the machine may use; only the customers' cluster
// goes.
var removePackages = map[string][]string{
	MySQL:   {"mysql-server*", "mysql-client*"},
	MariaDB: {"mariadb-server*", "mariadb-client*"},
}

// ComponentBackups keeps what a removed server left behind.
const ComponentBackups = "/var/lib/servik/backups/components"

// Remove uninstalls a database server that holds no customer database or
// user. MySQL's or MariaDB's data directory (only the system databases by
// then) is moved to ComponentBackups, so the other product can be
// installed afterwards; PostgreSQL's customer cluster is dropped.
func (m *Manager) Remove(ctx context.Context, productName string, w io.Writer) (err error) {
	p, ok := products[productName]
	if !ok {
		return invalid("product must be mysql, mariadb or postgresql")
	}
	m.mu.Lock()
	defer m.mu.Unlock()

	have, ok := m.installedProduct(p.server)
	if !ok || have.name != p.name {
		return invalid("%s is not installed", productName)
	}
	p = have
	st, err := m.loadState()
	if err != nil {
		return opError(err)
	}
	var dbs, users int
	for k := range st.Databases {
		if strings.HasPrefix(k, p.server+"/") {
			dbs++
		}
	}
	for k := range st.Users {
		if strings.HasPrefix(k, p.server+"/") {
			users++
		}
	}
	if dbs > 0 || users > 0 {
		return invalid("%s still has %d customer databases and %d users; delete them first", productName, dbs, users)
	}

	t := txn.New(m.h)
	stopped := false
	defer func() {
		if err == nil {
			return
		}
		bg := context.WithoutCancel(ctx)
		if rbErr := t.Rollback(bg); rbErr != nil {
			err = fmt.Errorf("%w (rollback also failed: %v)", err, rbErr)
		}
		if stopped {
			_, _ = m.h.Run(bg, host.Command{Name: "systemctl", Args: []string{"enable", "--now", p.unit}})
		}
		err = opError(err)
	}()

	fmt.Fprintln(w, "▸ "+i18n.T(ctx, "%s durduruluyor", p.unit))
	if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"disable", "--now", p.unit}, Stream: w}); err != nil {
		return err
	}
	stopped = true
	if err := t.RemoveFile(p.conf); err != nil {
		return err
	}
	if p.server == PostgreSQL {
		if err := t.RemoveFile(pgHBA); err != nil {
			return err
		}
		v := strings.TrimSuffix(strings.TrimPrefix(p.unit, "postgresql@"), "-main")
		fmt.Fprintf(w, "▸ %s\n", i18n.T(ctx, "Müşteri kümesi kaldırılıyor (PostgreSQL %s main)", v))
		if _, err := m.h.Run(ctx, host.Command{Name: "pg_dropcluster", Args: []string{"--stop", v, "main"}, Stream: w}); err != nil {
			return err
		}
	} else {
		fmt.Fprintf(w, "▸ %s\n", i18n.T(ctx, "Paketler kaldırılıyor: %s", strings.Join(removePackages[p.name], " ")))
		purge := host.Command{Name: "apt-get", Args: append([]string{"purge", "-y", "-q"}, removePackages[p.name]...), Env: aptEnv, Stream: w}
		if _, err := m.h.Run(ctx, purge); err != nil {
			return err
		}
		_, _ = m.h.Run(ctx, host.Command{Name: "apt-get", Args: []string{"autoremove", "-y", "-q"}, Env: aptEnv, Stream: w})
		// From here on the server is gone; its system databases are kept
		// aside rather than left where the other product would trip over
		// them.
		if m.exists(p.dataDir) {
			dest := fmt.Sprintf("%s/%s-%s", ComponentBackups, p.name, m.now().UTC().Format("20060102-150405"))
			fmt.Fprintf(w, "▸ %s\n", i18n.T(ctx, "Veri klasörü %s klasörüne taşınıyor", dest))
			if err := os.MkdirAll(m.h.Path(ComponentBackups), 0o700); err == nil {
				_ = os.Rename(m.h.Path(p.dataDir), m.h.Path(dest))
			}
		}
		st.Flavor = ""
	}
	// Nothing is left to roll back to once the server is gone.
	_ = m.saveState(st)
	return nil
}

// AdminPHP is the PHP version the web database manager runs on, "" when
// it is not set up.
func (m *Manager) AdminPHP() string { return m.adminPHP() }

// Installed maps the installed servers to their product and systemd unit:
// "mysql" → ("mariadb", "mariadb").
func (m *Manager) Installed() map[string][2]string {
	out := map[string][2]string{}
	for _, server := range []string{MySQL, PostgreSQL} {
		if p, ok := m.installedProduct(server); ok {
			out[server] = [2]string{p.name, p.unit}
		}
	}
	return out
}
