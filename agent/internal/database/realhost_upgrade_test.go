//go:build realhost

// This test upgrades a real PostgreSQL cluster to the newest major
// version Serfex offers: once with a failure on purpose (everything must
// be as before), then for real, then removes the old cluster. Serfex's
// own cluster (port 5433, same old version) must stay untouched. It
// needs root, an older customers' cluster ("main" on 5432, made here
// from Ubuntu's PostgreSQL 16 when missing) and changes the machine:
//
//	sudo SERFEX_DB_UPGRADE=postgresql go test -tags realhost -run TestRealUpgrade ./internal/database
//
// The new version comes from the PostgreSQL archive, unless its binaries
// are there already (/usr/lib/postgresql/18/bin).
package database

import (
	"bytes"
	"context"
	"errors"
	"os"
	"strings"
	"testing"
)

func TestRealUpgradePostgreSQL(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("needs root")
	}
	if os.Getenv("SERFEX_DB_UPGRADE") != PostgreSQL {
		t.Skip("set SERFEX_DB_UPGRADE=postgresql")
	}
	h := serviceHost{}
	m := NewManager(h)
	ctx := context.Background()
	sh := func(script string) string {
		t.Helper()
		out, err := h.sh(ctx, script)
		if err != nil {
			t.Fatalf("%s: %v\n%s", script, err, out)
		}
		return strings.TrimSpace(out)
	}

	old := m.pgMainVersion()
	if old == "" {
		sh("DEBIAN_FRONTEND=noninteractive apt-get install -y -q postgresql-16 && (pg_lsclusters -h | grep -q '^16 main' || pg_createcluster --port 5432 16 main) && pg_ctlcluster 16 main start || true")
		old = m.pgMainVersion()
	}
	to := UpgradeTarget(PostgreSQL, old)
	if to == "" {
		t.Skipf("the customers' cluster is on %q already", old)
	}
	// Serfex's own cluster runs on the same version; the upgrade must
	// not touch it.
	if !m.exists("/etc/postgresql/" + old + "/serfex") {
		sh("pg_createcluster --port 5433 " + old + " serfex")
	}
	sh("pg_ctlcluster " + old + " serfex start || true")
	panelSQL := func(q string) string {
		return sh("runuser -u postgres -- psql -X -At -p 5433 -d postgres -c \"" + q + "\"")
	}
	panelSQL("DROP TABLE IF EXISTS canary; CREATE TABLE canary AS SELECT generate_series(1, 3) AS id")

	if _, err := m.Install(ctx, InstallSpec{Product: PostgreSQL}); err != nil {
		t.Fatal(err)
	}
	const db, user = "acme_upgrade", "acme_upgrade_app"
	_, _ = m.DeleteUser(ctx, UserRef{Server: PostgreSQL, Name: user})
	_, _ = m.DeleteDatabase(ctx, DeleteSpec{Server: PostgreSQL, Name: db})
	if _, err := m.CreateDatabase(ctx, DatabaseSpec{Server: PostgreSQL, Name: db, Site: "acme.test"}); err != nil {
		t.Fatal(err)
	}
	if _, err := m.ApplyUser(ctx, UserSpec{Server: PostgreSQL, Name: user, Password: realPassword, Grants: []Grant{{db, AccessAll}}}); err != nil {
		t.Fatal(err)
	}
	q := func(sql string) (string, error) { return client(PostgreSQL, "127.0.0.1", user, realPassword, db, sql) }
	if _, err := q(`CREATE TABLE orders (id bigserial PRIMARY KEY, note text);
INSERT INTO orders (note) SELECT 'sipariş ' || g FROM generate_series(1, 120000) g;
CREATE TABLE events (at date NOT NULL, kind text) PARTITION BY RANGE (at);
CREATE TABLE events_2025 PARTITION OF events FOR VALUES FROM ('2025-01-01') TO ('2026-01-01');
CREATE TABLE events_2026 PARTITION OF events FOR VALUES FROM ('2026-01-01') TO ('2027-01-01');
INSERT INTO events SELECT DATE '2025-06-01' + (g % 400), 'k' || g FROM generate_series(1, 5000) g;
CREATE SCHEMA rapor;
CREATE TABLE rapor."Özet Tablo" (v int);
INSERT INTO rapor."Özet Tablo" VALUES (1), (2);
CREATE UNLOGGED TABLE cache (k text);
INSERT INTO cache VALUES ('a');`); err != nil {
		t.Fatal(err)
	}
	const count = "SELECT (SELECT count(*) FROM orders) || '/' || (SELECT count(*) FROM events) || '/' || (SELECT count(*) FROM rapor.\"Özet Tablo\") || '/' || (SELECT max(id) FROM orders)"
	before, err := q(count)
	if err != nil {
		t.Fatal(err)
	}

	// A failure at the last check puts everything back.
	var log bytes.Buffer
	_, err = m.Upgrade(ctx, UpgradeSpec{Product: PostgreSQL, Log: &log, Fail: func(step string) error {
		if step == "verify" {
			return errors.New("broken on purpose")
		}
		return nil
	}})
	t.Logf("failed upgrade:\n%s", log.String())
	if err == nil || !strings.Contains(err.Error(), "broken on purpose") {
		t.Fatalf("error %v", err)
	}
	if got := m.pgMainVersion(); got != old || m.exists("/etc/postgresql/"+to+"/main") {
		t.Fatalf("after the rollback the main cluster is %s", got)
	}
	if got, err := q(count); err != nil || got != before {
		t.Fatalf("after the rollback: %q %v (before %q)", got, err, before)
	}
	if _, err := q("INSERT INTO orders (note) VALUES ('geri alındıktan sonra')"); err != nil {
		t.Fatalf("not writable after the rollback: %v", err)
	}
	before, _ = q(count)

	log.Reset()
	res, err := m.Upgrade(ctx, UpgradeSpec{Product: PostgreSQL, To: to, Log: &log})
	t.Logf("upgrade:\n%s", log.String())
	if err != nil {
		t.Fatal(err)
	}
	if res.From != old || res.To != to || !strings.HasPrefix(res.Version, to+".") {
		t.Errorf("result %+v", res)
	}
	if got, err := q("SELECT current_setting('server_version_num')::int / 10000"); err != nil || got != to {
		t.Fatalf("customers reach version %q %v", got, err)
	}
	if got, err := q(count); err != nil || got != before {
		t.Fatalf("after the upgrade: %q %v (before %q)", got, err, before)
	}
	if _, err := q("INSERT INTO orders (note) VALUES ('yükseltmeden sonra')"); err != nil {
		t.Fatalf("not writable after the upgrade: %v", err)
	}
	if st := sh("pg_lsclusters -h " + old + " main"); !strings.Contains(st, "down") {
		t.Errorf("old cluster: %s", st)
	}
	if got := panelSQL("SELECT count(*) FROM canary"); got != "3" {
		t.Errorf("Serfex's own cluster: %q", got)
	}
	if !m.exists(res.Kept.Dump+"/"+db+".sql.gz") || !m.exists(res.Kept.Dump+"/globals.sql") {
		t.Errorf("dumps missing in %s", res.Kept.Dump)
	}

	if err := m.RemoveOld(ctx, PostgreSQL, nil); err != nil {
		t.Fatal(err)
	}
	if m.exists("/etc/postgresql/" + old + "/main") || !m.exists("/usr/lib/postgresql/"+old+"/bin/postgres") {
		t.Error("old cluster left, or the packages Serfex's own cluster needs removed")
	}
	if got := panelSQL("SELECT count(*) FROM canary"); got != "3" {
		t.Errorf("Serfex's own cluster after the removal: %q", got)
	}
	_, _ = m.DeleteUser(ctx, UserRef{Server: PostgreSQL, Name: user})
	_, _ = m.DeleteDatabase(ctx, DeleteSpec{Server: PostgreSQL, Name: db})
}
