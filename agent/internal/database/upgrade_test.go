package database

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/calltech-app/serfex/agent/internal/host"
)

func TestMajorVersions(t *testing.T) {
	for _, c := range []struct{ product, version, major, target string }{
		{PostgreSQL, "16.10-0ubuntu0.24.04.1", "16", "18"},
		{PostgreSQL, "16.10", "16", "18"},
		{PostgreSQL, "18.1-1.pgdg24.04+2", "18", ""},
		{MySQL, "8.0.43-0ubuntu0.24.04.2", "8.0", "8.4"},
		{MySQL, "8.4.6-1ubuntu24.04", "8.4", "9.7"},
		{MySQL, "9.7.4", "9.7", ""},
		{MariaDB, "1:10.11.13-0ubuntu0.24.04.1", "10.11", "11.8"},
		{MariaDB, "1:11.8.3+maria~ubu2404", "11.8", "12.3"},
		{MariaDB, "12.3.1-MariaDB-ubu2404", "12.3", ""},
		{MySQL, "", "", ""},
		{MySQL, "garbage", "", ""},
	} {
		v := strings.SplitN(c.version, "-MariaDB", 2)[0]
		if got := MajorOf(c.product, v); got != c.major {
			t.Errorf("MajorOf(%s, %q) = %q, want %q", c.product, c.version, got, c.major)
		}
		if got := UpgradeTarget(c.product, c.major); got != c.target {
			t.Errorf("UpgradeTarget(%s, %q) = %q, want %q", c.product, c.major, got, c.target)
		}
	}
	if !newerMajor("12.3", "11.8") || !newerMajor("8.4", "8.0") || newerMajor("8.4", "9.7") || newerMajor("18", "18") {
		t.Error("newerMajor")
	}
}

// pg16 makes the simulator a server whose customers' cluster is
// PostgreSQL 16 (installed before Serfex offered 18), next to Serfex's
// own cluster on 16, with a database, a user and a remote host.
func pg16(t *testing.T) (*Manager, *host.Simulator) {
	t.Helper()
	ctx := context.Background()
	m, sim := newSim(t)
	m.FreeSpace = func(string) (uint64, int64, error) { return 1, 100 << 30, nil }
	for _, c := range []host.Command{
		{Name: "apt-get", Args: []string{"install", "postgresql"}},
		{Name: "pg_createcluster", Args: []string{"--port", "5433", "16", "serfex"}},
	} {
		if _, err := sim.Run(ctx, c); err != nil {
			t.Fatal(err)
		}
	}
	if err := os.WriteFile(sim.Path("/etc/postgresql/16/main/start.conf"), []byte("auto\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Install(ctx, InstallSpec{Product: PostgreSQL}); err != nil {
		t.Fatal(err)
	}
	if _, err := m.CreateDatabase(ctx, DatabaseSpec{Server: PostgreSQL, Name: "acme_shop", Site: "acme.test"}); err != nil {
		t.Fatal(err)
	}
	if _, err := m.ApplyUser(ctx, UserSpec{Server: PostgreSQL, Name: "acme_app", Password: "Gizli-parola-42", Hosts: []string{"88.247.120.10"}, Grants: []Grant{{"acme_shop", AccessAll}}}); err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(sim.Path("/var/lib/postgresql/16/main/base"), 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(sim.Path("/var/lib/postgresql/16/main/base/1"), bytes.Repeat([]byte("x"), 4096), 0o600); err != nil {
		t.Fatal(err)
	}
	return m, sim
}

func TestPostgreSQLUpgrade(t *testing.T) {
	ctx := context.Background()
	m, sim := pg16(t)
	hba := read(t, sim, pgHBA)
	if o := m.Offer(PostgreSQL, "16.10-0ubuntu0.24.04.1"); o == nil || o.From != "16" || o.To != "18" {
		t.Fatalf("offer %+v", o)
	}

	var log bytes.Buffer
	var during []error
	res, err := m.Upgrade(ctx, UpgradeSpec{Product: PostgreSQL, To: "18", Log: &log, Fail: func(step string) error {
		if step == "backup" {
			// Customers are out and the panel's calls are refused at once.
			contains(t, "maintenance pg_hba.conf", read(t, sim, pgHBA), "only the postgres account may connect")
			_, err := m.CreateDatabase(ctx, DatabaseSpec{Server: PostgreSQL, Name: "acme_new"})
			during = append(during, err)
			if st := m.Status(ctx).Servers[PostgreSQL]; !st.Upgrading || st.Error != ErrUpgrading.Error() {
				t.Errorf("status while upgrading: %+v", st)
			}
		}
		return nil
	}})
	if err != nil {
		t.Fatalf("%v\n%s", err, log.String())
	}
	if len(during) != 1 || !errors.Is(during[0], ErrUpgrading) {
		t.Errorf("create during the upgrade: %v", during)
	}
	if res.From != "16" || res.To != "18" || res.Version != "18.1" || res.Kept.Old != "16/main" {
		t.Errorf("result %+v", res)
	}
	for _, want := range []string{
		"systemctl reload postgresql@16-main",
		"apt-get install -y -q --no-install-recommends postgresql-18 postgresql-client-18",
		// The old version had pgvector, so the new one gets it first.
		"apt-get install -y -q --no-install-recommends postgresql-18-pgvector",
		"pg_upgradecluster -v 18 --method=upgrade --no-start 16 main",
		"systemctl start postgresql@18-main",
		"vacuumdb --all --analyze-only --quiet",
		"systemctl restart postgresql@18-main",
		"systemctl disable postgresql@16-main",
	} {
		if !sim.Ran(want) && !sim.Ran("runuser -u postgres -- "+want) {
			t.Errorf("did not run %q", want)
		}
	}
	// The new cluster serves the customers on 5432 with Serfex's settings
	// and their remote host; the old one is kept, stopped.
	if got := m.pgMainVersion(); got != "18" {
		t.Errorf("main cluster %s", got)
	}
	contains(t, "new cluster conf", read(t, sim, "/etc/postgresql/18/main/conf.d/serfex.conf"), "listen_addresses = '*'", "hba_file = '/etc/serfex/databases/pg_hba.conf'")
	if got := read(t, sim, pgHBA); got != hba {
		t.Errorf("pg_hba.conf after the upgrade:\n%s\nwant\n%s", got, hba)
	}
	contains(t, "old cluster", read(t, sim, "/etc/postgresql/16/main/start.conf"), "manual")
	for _, f := range []string{"acme_shop.sql.gz", "globals.sql", "manifest.json"} {
		if !m.exists(res.Kept.Dump + "/" + f) {
			t.Errorf("missing %s in %s", f, res.Kept.Dump)
		}
	}
	var man manifest
	if err := json.Unmarshal([]byte(read(t, sim, res.Kept.Dump+"/manifest.json")), &man); err != nil || len(man.Databases["acme_shop"]) == 0 || man.Users[0] != "acme_app" {
		t.Errorf("manifest %+v %v", man, err)
	}
	contains(t, "log", log.String(), "▸ Ön denetimler yapılıyor", "PostgreSQL 16 → 18", "▸ Doğrulanıyor", "1 veritabanı, 1 kullanıcı")
	if strings.Contains(log.String(), "↺") {
		t.Errorf("rolled back:\n%s", log.String())
	}

	// Kept for a week; no second upgrade until it is gone.
	k := m.KeptVersion(PostgreSQL)
	if k == nil || k.From != "16" || k.Until.Sub(k.Upgraded) != UpgradeKeep {
		t.Fatalf("kept %+v", k)
	}
	if _, err := m.Upgrade(ctx, UpgradeSpec{Product: PostgreSQL}); err == nil {
		t.Error("upgraded again")
	}
	if err := m.Remove(ctx, PostgreSQL, nil); err == nil || !strings.Contains(err.Error(), "kept") {
		t.Errorf("remove while the old version is kept: %v", err)
	}
	// Removing the old cluster keeps PostgreSQL 16's packages, which
	// Serfex's own cluster runs on.
	if err := m.RemoveOld(ctx, PostgreSQL, nil); err != nil {
		t.Fatal(err)
	}
	if m.exists("/etc/postgresql/16/main") || !m.exists("/etc/postgresql/16/serfex") || sim.Ran("apt-get purge -y -q postgresql-16 postgresql-client-16") {
		t.Errorf("old cluster removal: %v", sim.Commands())
	}
	if m.KeptVersion(PostgreSQL) != nil || !m.exists(res.Kept.Dump) {
		t.Error("the record should go, the dump stay")
	}
}

func TestPostgreSQLUpgradeRollsBack(t *testing.T) {
	ctx := context.Background()
	for _, step := range []string{"backup", "install", "upgrade", "verify", "finish"} {
		t.Run(step, func(t *testing.T) {
			m, sim := pg16(t)
			hba := read(t, sim, pgHBA)
			conf := read(t, sim, "/etc/postgresql/16/main/postgresql.conf")
			var log bytes.Buffer
			_, err := m.Upgrade(ctx, UpgradeSpec{Product: PostgreSQL, Log: &log, Fail: func(s string) error {
				if s == step {
					return errors.New("broken on purpose")
				}
				return nil
			}})
			if err == nil || !strings.Contains(err.Error(), "broken on purpose") {
				t.Fatalf("error %v", err)
			}
			if m.exists("/etc/postgresql/18/main") || m.pgMainVersion() != "16" {
				t.Errorf("new cluster left behind (main %s)", m.pgMainVersion())
			}
			if got := read(t, sim, "/etc/postgresql/16/main/postgresql.conf"); got != conf {
				t.Errorf("old postgresql.conf:\n%s", got)
			}
			contains(t, "start.conf", read(t, sim, "/etc/postgresql/16/main/start.conf"), "auto")
			if got := read(t, sim, pgHBA); got != hba {
				t.Errorf("pg_hba.conf not restored:\n%s", got)
			}
			if step != "backup" && !sim.Ran("apt-get purge -y -q postgresql-18 postgresql-client-18") {
				t.Error("the new packages stayed")
			}
			if m.KeptVersion(PostgreSQL) != nil || m.Upgrading() != "" {
				t.Error("kept or still upgrading")
			}
			contains(t, "log", log.String(), "↺ Eski sürüme dönülüyor", "Yükseltme öncesi döküm saklanıyor")
			if _, err := m.CreateDatabase(ctx, DatabaseSpec{Server: PostgreSQL, Name: "acme_after"}); err != nil {
				t.Errorf("after the rollback: %v", err)
			}
		})
	}
}

func TestUpgradeCatchesLostRows(t *testing.T) {
	ctx := context.Background()
	m, sim := pg16(t)
	var log bytes.Buffer
	_, err := m.Upgrade(ctx, UpgradeSpec{Product: PostgreSQL, Log: &log, Fail: func(step string) error {
		if step == "upgrade" {
			// The new cluster comes up with a row less.
			e := &simEngine{h: sim, server: PostgreSQL}
			d, _ := e.load()
			d.Rows = map[string]map[string]int64{"acme_shop": {"users": 1, "orders": 2}}
			return e.save(d)
		}
		return nil
	}})
	if err == nil || !strings.Contains(err.Error(), "rows after the upgrade") {
		t.Fatalf("error %v\n%s", err, log.String())
	}
	if m.pgMainVersion() != "16" {
		t.Error("not rolled back")
	}
}

func TestUpgradeRefusals(t *testing.T) {
	ctx := context.Background()
	m, sim := pg16(t)
	hba := read(t, sim, pgHBA)

	// Too little room changes nothing.
	m.FreeSpace = func(string) (uint64, int64, error) { return 1, 10 << 20, nil }
	var log bytes.Buffer
	_, err := m.Upgrade(ctx, UpgradeSpec{Product: PostgreSQL, Log: &log})
	var v *ValidationError
	if !errors.As(err, &v) || !strings.Contains(err.Error(), "not enough disk space") {
		t.Fatalf("no room: %v", err)
	}
	if strings.Contains(log.String(), "↺") || read(t, sim, pgHBA) != hba || sim.Ran("systemctl reload postgresql@16-main") {
		t.Errorf("a refusal changed something:\n%s", log.String())
	}

	// The panel offered another version.
	m.FreeSpace = nil
	m.FreeSpace = func(string) (uint64, int64, error) { return 1, 100 << 30, nil }
	if _, err := m.Upgrade(ctx, UpgradeSpec{Product: PostgreSQL, To: "17"}); !errors.As(err, &v) {
		t.Errorf("stale offer: %v", err)
	}
	// Not installed, newest already.
	if _, err := m.Upgrade(ctx, UpgradeSpec{Product: MySQL}); !errors.As(err, &v) {
		t.Errorf("not installed: %v", err)
	}
	m2, _ := newSim(t)
	m2.FreeSpace = m.FreeSpace
	if _, err := m2.Install(ctx, InstallSpec{Product: PostgreSQL}); err != nil {
		t.Fatal(err)
	}
	if _, err := m2.Upgrade(ctx, UpgradeSpec{Product: PostgreSQL}); err == nil || !strings.Contains(err.Error(), "newest") {
		t.Errorf("on 18: %v", err)
	}
}

// mysql84 is a server with MySQL 8.4 from Oracle's archive, a database
// and a user.
func mysql84(t *testing.T, product, major string) (*Manager, *host.Simulator) {
	t.Helper()
	ctx := context.Background()
	m, sim := newSim(t)
	m.FreeSpace = func(string) (uint64, int64, error) { return 1, 100 << 30, nil }
	if _, err := m.Install(ctx, InstallSpec{Product: product, Version: major}); err != nil {
		t.Fatal(err)
	}
	if _, err := m.CreateDatabase(ctx, DatabaseSpec{Server: MySQL, Name: "acme_shop"}); err != nil {
		t.Fatal(err)
	}
	if _, err := m.ApplyUser(ctx, UserSpec{Server: MySQL, Name: "acme_app", Password: "Gizli-parola-42", Grants: []Grant{{"acme_shop", AccessAll}}}); err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(sim.Path("/var/lib/mysql/acme_shop"), 0o750); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(sim.Path("/var/lib/mysql/acme_shop/users.ibd"), []byte("rows of "+major), 0o640); err != nil {
		t.Fatal(err)
	}
	return m, sim
}

func TestMySQLUpgrade(t *testing.T) {
	ctx := context.Background()
	for _, c := range []struct{ product, from, to, sources, want string }{
		{MySQL, "8.4", "9.7", "/etc/apt/sources.list.d/serfex-mysql.sources", "Components: mysql-9.7-lts"},
		{MariaDB, "11.8", "12.3", "/etc/apt/sources.list.d/serfex-mariadb.sources", "mariadb-server/12.3/repo"},
	} {
		t.Run(c.product, func(t *testing.T) {
			m, sim := mysql84(t, c.product, c.from)
			p := products[c.product]
			if o := m.Offer(c.product, SimVersion(sim, c.product)); o == nil || o.To != c.to {
				t.Fatalf("offer %+v", o)
			}
			var log bytes.Buffer
			res, err := m.Upgrade(ctx, UpgradeSpec{Product: c.product, To: c.to, Log: &log, Fail: func(step string) error {
				if step == "copy" {
					e := &simEngine{h: sim, server: MySQL}
					if d, _ := e.load(); !d.ReadOnly {
						t.Error("not read-only during the upgrade")
					}
					contains(t, "read-only setting", read(t, sim, readOnlyConf(p)), "read_only = ON")
				}
				return nil
			}})
			if err != nil {
				t.Fatalf("%v\n%s", err, log.String())
			}
			if res.From != c.from || res.To != c.to || !strings.HasPrefix(res.Version, c.to+".") {
				t.Errorf("result %+v", res)
			}
			contains(t, "archive", read(t, sim, c.sources), c.want)
			copyDir := "/var/lib/mysql-" + c.from
			if got := read(t, sim, copyDir+"/acme_shop/users.ibd"); got != "rows of "+c.from {
				t.Errorf("copy of the data: %q", got)
			}
			if m.exists(readOnlyConf(p)) {
				t.Error("still read-only")
			}
			for _, want := range []string{"systemctl stop " + p.unit, "cp -a /var/lib/mysql " + copyDir, "systemctl restart " + p.unit} {
				if !sim.Ran(want) {
					t.Errorf("did not run %q", want)
				}
			}
			if (c.product == MariaDB) != sim.Ran("mariadb-upgrade") {
				t.Error("mariadb-upgrade")
			}
			k := m.KeptVersion(c.product)
			if k == nil || k.Old != copyDir || k.Packages == "" || !m.exists(k.Packages) || !m.exists(k.Dump+"/users.json") {
				t.Fatalf("kept %+v", k)
			}
			if err := m.RemoveOld(ctx, c.product, nil); err != nil {
				t.Fatal(err)
			}
			if m.exists(copyDir) || m.exists(k.Packages) || !m.exists(k.Dump) {
				t.Error("old version not removed, or the dump with it")
			}
		})
	}
}

func TestMySQLUpgradeRollsBack(t *testing.T) {
	ctx := context.Background()
	for _, step := range []string{"backup", "copy", "install", "upgrade", "verify"} {
		t.Run(step, func(t *testing.T) {
			m, sim := mysql84(t, MySQL, "8.4")
			p := products[MySQL]
			sources := read(t, sim, "/etc/apt/sources.list.d/serfex-mysql.sources")
			var log bytes.Buffer
			_, err := m.Upgrade(ctx, UpgradeSpec{Product: MySQL, Log: &log, Fail: func(s string) error {
				if s == "install" || s == "upgrade" || s == "verify" {
					// The new version touched the data.
					_ = os.WriteFile(sim.Path("/var/lib/mysql/acme_shop/users.ibd"), []byte("rows of 9.7"), 0o640)
				}
				if s == step {
					return errors.New("broken on purpose")
				}
				return nil
			}})
			if err == nil || !strings.Contains(err.Error(), "broken on purpose") {
				t.Fatalf("error %v", err)
			}
			if got := read(t, sim, "/var/lib/mysql/acme_shop/users.ibd"); got != "rows of 8.4" {
				t.Errorf("data after the rollback: %q", got)
			}
			if got := read(t, sim, "/etc/apt/sources.list.d/serfex-mysql.sources"); got != sources {
				t.Errorf("archive after the rollback:\n%s", got)
			}
			for _, gone := range []string{"/var/lib/mysql-8.4", "/var/lib/mysql-failed-9.7", readOnlyConf(p)} {
				if m.exists(gone) {
					t.Errorf("%s left behind", gone)
				}
			}
			e := &simEngine{h: sim, server: MySQL}
			if d, _ := e.load(); d.ReadOnly {
				t.Error("still read-only")
			}
			if v, _ := e.version(ctx); !strings.HasPrefix(v, "8.4.") {
				t.Errorf("version after the rollback %s", v)
			}
			matches, _ := filepath.Glob(sim.Path(BackupDir + "/upgrade-mysql-8.4-packages-*"))
			if m.KeptVersion(MySQL) != nil || len(matches) != 0 {
				t.Errorf("kept or saved packages left: %v", matches)
			}
			contains(t, "log", log.String(), "↺ Eski sürüme dönülüyor")
		})
	}
}

func TestPruneKeptAndOldDumps(t *testing.T) {
	ctx := context.Background()
	m, sim := pg16(t)
	start := time.Now()
	m.now = func() time.Time { return start }
	res, err := m.Upgrade(ctx, UpgradeSpec{Product: PostgreSQL})
	if err != nil {
		t.Fatal(err)
	}
	m.PruneKept(ctx, nil)
	if m.KeptVersion(PostgreSQL) == nil {
		t.Fatal("pruned too early")
	}
	m.now = func() time.Time { return start.Add(UpgradeKeep + time.Hour) }
	m.PruneKept(ctx, nil)
	if m.KeptVersion(PostgreSQL) != nil || m.exists("/etc/postgresql/16/main") {
		t.Fatal("not pruned after a week")
	}
	// The dumps go with the other database backups.
	old := start.Add(-BackupRetention - time.Hour)
	if err := os.Chtimes(sim.Path(res.Kept.Dump), old, old); err != nil {
		t.Fatal(err)
	}
	m.now = time.Now
	m.Sweep(ctx)
	if m.exists(res.Kept.Dump) {
		t.Error("old upgrade dump not swept")
	}
}
