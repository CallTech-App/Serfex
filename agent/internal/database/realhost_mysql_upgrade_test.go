//go:build realhost

// This test upgrades a real MySQL data directory from one major version
// to the next with Serfex's upgrade: once with a failure on purpose (the
// old version must run on the old data again), then for real. The two
// versions are unpacked builds (conda-forge's mysql-server, say), so apt
// is played by the test: "installing" a version switches the binaries
// the server is started from. It needs root and uses /var/lib/mysql and
// /etc/mysql, so it only runs on a throwaway machine without MySQL:
//
//	sudo SERFEX_MYSQL_UPGRADE=/opt/mysql84:/opt/mysql97 go test -tags realhost -run TestRealUpgradeMySQL ./internal/database
package database

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/calltech-app/serfex/agent/internal/host"
)

// mysqlBuilds plays apt and systemd for unpacked MySQL builds.
type mysqlBuilds struct {
	host.System
	old, new string
	// current is the build "installed".
	current string
	cnf     string
}

const testSocket = "/run/mysqld/mysqld.sock"

func (h *mysqlBuilds) major(dir string) string {
	out, _ := exec.Command(dir+"/bin/mysqld", "--version").CombinedOutput()
	f := strings.Fields(string(out))
	for i, w := range f {
		if w == "Ver" && i+1 < len(f) {
			return MajorOf(MySQL, f[i+1])
		}
	}
	return ""
}

// archived is the build the apt sources point at.
func (h *mysqlBuilds) archived() string {
	b, _ := os.ReadFile("/etc/apt/sources.list.d/serfex-mysql.sources")
	if strings.Contains(string(b), "mysql-"+h.major(h.new)+"-lts") {
		return h.new
	}
	return h.old
}

func (h *mysqlBuilds) start(ctx context.Context) error {
	_ = h.stop()
	cmd := exec.Command(h.current+"/bin/mysqld", "--defaults-file="+h.cnf, "--user=root", "--basedir="+h.current)
	log, _ := os.OpenFile("/var/log/mysql-test.log", os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0o644)
	cmd.Stdout, cmd.Stderr = log, log
	if err := cmd.Start(); err != nil {
		return err
	}
	go func() { _ = cmd.Wait() }()
	for i := 0; i < 240; i++ {
		if _, err := os.Stat(testSocket); err == nil {
			if out, err := exec.Command(h.current+"/bin/mysqladmin", "--socket="+testSocket, "-uroot", "ping").CombinedOutput(); err == nil && strings.Contains(string(out), "alive") {
				return nil
			}
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(500 * time.Millisecond):
		}
	}
	return errors.New("mysqld did not start; see /var/log/mysql-test.log")
}

func (h *mysqlBuilds) stop() error {
	_ = exec.Command(h.current+"/bin/mysqladmin", "--socket="+testSocket, "-uroot", "shutdown").Run()
	for i := 0; i < 120; i++ {
		if _, err := os.Stat(testSocket); err != nil {
			return nil
		}
		time.Sleep(500 * time.Millisecond)
	}
	return errors.New("mysqld did not stop")
}

func (h *mysqlBuilds) Run(ctx context.Context, c host.Command) (string, error) {
	switch c.Name {
	case "mysql", "mysqldump":
		c.Name = h.current + "/bin/" + c.Name
		c.Args = append([]string{"--socket=" + testSocket, "-uroot"}, c.Args...)
	case "systemctl":
		if len(c.Args) < 2 || c.Args[len(c.Args)-1] != "mysql" {
			return "", nil
		}
		switch c.Args[0] {
		case "stop":
			return "", h.stop()
		case "start", "restart":
			return "", h.start(ctx)
		}
		return "", nil
	case "apt-get":
		switch {
		case len(c.Args) > 0 && c.Args[0] == "install" && strings.HasSuffix(c.Args[len(c.Args)-1], ".deb"):
			// The saved packages: the old version again.
			h.current = h.old
			return "", h.start(ctx)
		case len(c.Args) > 0 && c.Args[0] == "install":
			h.current = h.archived()
			return "", h.start(ctx)
		}
		return "", nil
	case "apt-cache":
		v := h.major(h.archived()) + ".4-1ubuntu24.04"
		return fmt.Sprintf("mysql-server:\n  Installed: (none)\n  Candidate: %s\n  Version table:\n     %s 500\n        500 https://repo.mysql.com/apt/ubuntu noble/mysql-%s-lts amd64 Packages\n", v, v, h.major(h.archived())), nil
	case "env":
		// env -C DIR apt-get download PACKAGES
		if len(c.Args) > 3 && c.Args[2] == "apt-get" {
			return "", os.WriteFile(c.Args[1]+"/mysql-server_"+h.major(h.old)+".deb", []byte("saved"), 0o644)
		}
	case "dpkg-query":
		return "mysql-server\tinstalled\nmysql-community-server\tinstalled\n", nil
	}
	return h.System.Run(ctx, c)
}

func TestRealUpgradeMySQL(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("needs root")
	}
	old, newer, ok := strings.Cut(os.Getenv("SERFEX_MYSQL_UPGRADE"), ":")
	if !ok {
		t.Skip("set SERFEX_MYSQL_UPGRADE=OLD_BUILD:NEW_BUILD")
	}
	ctx := context.Background()
	h := &mysqlBuilds{old: old, new: newer, current: old, cnf: filepath.Join(t.TempDir(), "my.cnf")}
	from, to := h.major(old), h.major(newer)
	if UpgradeTarget(MySQL, from) != to {
		t.Fatalf("Serfex does not upgrade %s to %s", from, to)
	}
	_ = h.stop()
	for _, d := range []string{"/var/lib/mysql", "/var/lib/mysql-" + from, "/var/lib/mysql-failed-" + to, "/etc/mysql", stateDir} {
		_ = os.RemoveAll(d)
	}
	_ = os.Remove("/etc/apt/sources.list.d/serfex-mysql.sources")
	for _, d := range []string{"/etc/mysql/mysql.conf.d", "/run/mysqld", "/etc/apt/sources.list.d", keyringDir} {
		if err := os.MkdirAll(d, 0o755); err != nil {
			t.Fatal(err)
		}
	}
	if err := os.WriteFile(h.cnf, []byte("[mysqld]\ndatadir=/var/lib/mysql\nsocket="+testSocket+"\nport=3306\nmysqlx=OFF\n!includedir /etc/mysql/mysql.conf.d/\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	if out, err := exec.Command(old+"/bin/mysqld", "--no-defaults", "--initialize-insecure", "--user=root", "--basedir="+old, "--datadir=/var/lib/mysql").CombinedOutput(); err != nil {
		t.Fatalf("initialize: %v\n%s", err, out)
	}
	// The packages' marker, and the archive of the installed version.
	if err := os.MkdirAll("/usr/sbin", 0o755); err != nil {
		t.Fatal(err)
	}
	_ = os.Symlink(old+"/bin/mysqld", "/usr/sbin/mysqld")
	m := NewManager(h)
	m.Fetch = func(context.Context, string) ([]byte, error) { return []byte("key"), nil }
	if err := os.WriteFile("/etc/apt/sources.list.d/serfex-mysql.sources", []byte(m.archiveFor(MySQL, from).source()), 0o644); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Install(ctx, InstallSpec{Product: MySQL}); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = h.stop() })

	const db, user = "acme_upgrade", "acme_upgrade_app"
	if _, err := m.CreateDatabase(ctx, DatabaseSpec{Server: MySQL, Name: db, Site: "acme.test"}); err != nil {
		t.Fatal(err)
	}
	if _, err := m.ApplyUser(ctx, UserSpec{Server: MySQL, Name: user, Password: realPassword, Grants: []Grant{{db, AccessAll}}}); err != nil {
		t.Fatal(err)
	}
	q := func(sql string) (string, error) {
		cmd := exec.Command(h.current+"/bin/mysql", "--batch", "--skip-column-names", "-h", "127.0.0.1", "-u", user, db, "-e", sql)
		cmd.Env = append(os.Environ(), "MYSQL_PWD="+realPassword)
		out, err := cmd.CombinedOutput()
		if err != nil {
			return string(out), fmt.Errorf("%v: %s", err, out)
		}
		return strings.TrimSpace(string(out)), nil
	}
	if _, err := q("SET SESSION cte_max_recursion_depth = 100000;" +
		"CREATE TABLE orders (id BIGINT AUTO_INCREMENT PRIMARY KEY, note VARCHAR(40)) ENGINE=InnoDB;" +
		"INSERT INTO orders (note) WITH RECURSIVE g(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM g WHERE n < 50000) SELECT CONCAT('sipariş ', n) FROM g;" +
		"CREATE TABLE `Özet ``Tablo`` 2026` (v INT); INSERT INTO `Özet ``Tablo`` 2026` VALUES (1), (2);" +
		"CREATE TABLE logs (msg TEXT) ENGINE=MyISAM; INSERT INTO logs VALUES ('a'), ('b'), ('c');"); err != nil {
		t.Fatal(err)
	}
	const count = "SELECT CONCAT((SELECT COUNT(*) FROM orders), '/', (SELECT COUNT(*) FROM `Özet ``Tablo`` 2026`), '/', (SELECT COUNT(*) FROM logs), '/', (SELECT MAX(id) FROM orders))"
	before, err := q(count)
	if err != nil {
		t.Fatal(err)
	}

	var log bytes.Buffer
	_, err = m.Upgrade(ctx, UpgradeSpec{Product: MySQL, Log: &log, Fail: func(step string) error {
		if step == "verify" {
			return errors.New("broken on purpose")
		}
		return nil
	}})
	t.Logf("failed upgrade:\n%s", log.String())
	if err == nil || !strings.Contains(err.Error(), "broken on purpose") {
		t.Fatalf("error %v", err)
	}
	if h.current != old {
		t.Fatal("the old version was not installed again")
	}
	if got, err := q(count); err != nil || got != before {
		t.Fatalf("after the rollback: %q %v (before %q)", got, err, before)
	}
	if _, err := q("INSERT INTO orders (note) VALUES ('geri alındıktan sonra')"); err != nil {
		t.Fatalf("not writable after the rollback: %v", err)
	}
	before, _ = q(count)

	log.Reset()
	res, err := m.Upgrade(ctx, UpgradeSpec{Product: MySQL, To: to, Log: &log})
	t.Logf("upgrade:\n%s", log.String())
	if err != nil {
		t.Fatal(err)
	}
	if h.current != newer || res.From != from || res.To != to || !strings.HasPrefix(res.Version, to+".") {
		t.Fatalf("result %+v (running %s)", res, h.current)
	}
	if got, err := q(count); err != nil || got != before {
		t.Fatalf("after the upgrade: %q %v (before %q)", got, err, before)
	}
	if _, err := q("INSERT INTO orders (note) VALUES ('yükseltmeden sonra')"); err != nil {
		t.Fatalf("not writable after the upgrade: %v", err)
	}
	if _, err := os.Stat("/var/lib/mysql-" + from + "/" + db); err != nil {
		t.Errorf("old data directory not kept: %v", err)
	}
	if err := m.RemoveOld(ctx, MySQL, nil); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat("/var/lib/mysql-" + from); err == nil {
		t.Error("old data directory not removed")
	}
}
