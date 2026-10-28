package database

import (
	"compress/gzip"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
	"slices"
	"sort"
	"strconv"
	"strings"
	"syscall"
	"time"

	"github.com/calltech-app/serfex/agent/internal/aptcache"
	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/i18n"
	"github.com/calltech-app/serfex/agent/internal/txn"
)

// Major-version upgrades of the customers' database servers.
//
// A server stays on the major version it was installed in until the
// administrator upgrades it to a newer one Serfex offers (Releases). An
// upgrade never loses data, so it goes like this:
//
//  1. Checks: the server answers, the new version is not there yet, and
//     the disk has room for a second copy of the data and a dump.
//  2. Maintenance: customers can no longer change data (PostgreSQL lets
//     only the postgres account in; MySQL and MariaDB go read-only).
//  3. Every table's rows are counted and every customer database is
//     dumped to BackupDir/upgrade-…, with the users' password hashes.
//  4. The new version is installed beside the data, which is kept:
//     PostgreSQL gets a new cluster (pg_upgradecluster, copy mode) and
//     the old one stays, stopped; MySQL and MariaDB upgrade their data
//     directory in place after a copy of it is taken, with the old
//     version's packages saved for going back.
//  5. Every database and user the panel manages must exist again and
//     every table must have the same number of rows; then customers get
//     in again.
//
// Anything that fails on the way puts the old version back as it was.
// The old cluster or data directory is kept for UpgradeKeep and can be
// removed earlier (RemoveOld); the dumps follow BackupRetention.

const (
	upgradeFile = stateDir + "/upgrade.json"
	// UpgradeKeep is how long the previous version stays after an
	// upgrade.
	UpgradeKeep = 7 * 24 * time.Hour
	// upgradeMargin is the free space an upgrade leaves on top of the
	// copy and the dump.
	upgradeMargin = 1 << 30
)

// ErrUpgrading is returned for a server while its major version is being
// upgraded.
var ErrUpgrading = errors.New("the database server is being upgraded to a new major version; try again when it has finished")

// UpgradeOffer is a major version a server can be upgraded to.
type UpgradeOffer struct {
	From string `json:"from"`
	To   string `json:"to"`
}

// Kept is the previous version a server keeps after an upgrade.
type Kept struct {
	Product string `json:"product"`
	From    string `json:"from"`
	To      string `json:"to"`
	// Old is the old PostgreSQL cluster ("16/main") or the copy of the
	// MySQL or MariaDB data directory.
	Old string `json:"old"`
	// Dump is the directory with the dumps taken before the upgrade.
	Dump string `json:"dump"`
	// Packages holds the old MySQL or MariaDB packages.
	Packages string    `json:"packages,omitempty"`
	Upgraded time.Time `json:"upgraded"`
	Until    time.Time `json:"until"`
}

// MajorOf is the major version of a server version, as dpkg or the server
// reports it: "1:11.8.3+maria~ubu2404" and "11.8.3-MariaDB" are 11.8,
// "16.10-0ubuntu0.24.04.1" is 16.
func MajorOf(product, version string) string {
	v := aptcache.Upstream(version)
	v, _, _ = strings.Cut(v, "+")
	parts := strings.Split(v, ".")
	if len(parts) == 0 || parts[0] == "" {
		return ""
	}
	for _, p := range parts {
		if _, err := strconv.Atoi(p); err != nil {
			return ""
		}
	}
	if product == PostgreSQL {
		return parts[0]
	}
	if len(parts) < 2 {
		return ""
	}
	return parts[0] + "." + parts[1]
}

// newerMajor reports whether major version a is newer than b.
func newerMajor(a, b string) bool {
	pa, pb := strings.Split(a, "."), strings.Split(b, ".")
	for i := 0; i < max(len(pa), len(pb)); i++ {
		var x, y int
		if i < len(pa) {
			x, _ = strconv.Atoi(pa[i])
		}
		if i < len(pb) {
			y, _ = strconv.Atoi(pb[i])
		}
		if x != y {
			return x > y
		}
	}
	return false
}

// UpgradeTarget is the major version a server in major version from is
// upgraded to, "" when there is none. PostgreSQL goes straight to the
// newest Serfex offers (pg_upgrade reads any older version); MySQL and
// MariaDB take one offered version at a time (MySQL supports upgrades
// from one LTS release to the next only).
func UpgradeTarget(product, from string) string {
	list := Releases[product]
	if from == "" || len(list) == 0 {
		return ""
	}
	if product == PostgreSQL {
		if newerMajor(list[0], from) {
			return list[0]
		}
		return ""
	}
	for i := len(list) - 1; i >= 0; i-- {
		if newerMajor(list[i], from) {
			return list[i]
		}
	}
	return ""
}

// Offer is the upgrade a product installed in version (as dpkg reports
// it) can have, nil when there is none.
func (m *Manager) Offer(product, version string) *UpgradeOffer {
	from := MajorOf(product, version)
	if to := UpgradeTarget(product, from); to != "" {
		return &UpgradeOffer{From: from, To: to}
	}
	return nil
}

// KeptVersion is the previous version a product keeps after an upgrade,
// nil when there is none.
func (m *Manager) KeptVersion(product string) *Kept {
	kept := m.loadKept()
	if k, ok := kept[product]; ok {
		return &k
	}
	return nil
}

// Upgrading is the server being upgraded, "" when none is.
func (m *Manager) Upgrading() string {
	m.umu.Lock()
	defer m.umu.Unlock()
	return m.upgrading
}

// startUpgrading marks server as being upgraded unless another one is;
// "" clears the mark.
func (m *Manager) startUpgrading(server string) bool {
	m.umu.Lock()
	defer m.umu.Unlock()
	if server != "" && m.upgrading != "" {
		return false
	}
	m.upgrading = server
	return true
}

func (m *Manager) loadKept() map[string]Kept {
	out := map[string]Kept{}
	if b, err := os.ReadFile(m.h.Path(upgradeFile)); err == nil {
		_ = json.Unmarshal(b, &out)
	}
	return out
}

func (m *Manager) saveKept(kept map[string]Kept) error {
	if len(kept) == 0 {
		err := os.Remove(m.h.Path(upgradeFile))
		if errors.Is(err, os.ErrNotExist) {
			return nil
		}
		return err
	}
	if err := os.MkdirAll(m.h.Path(stateDir), 0o755); err != nil {
		return err
	}
	b, _ := json.MarshalIndent(kept, "", "  ")
	tmp := m.h.Path(upgradeFile + ".tmp")
	if err := os.WriteFile(tmp, b, 0o600); err != nil {
		return err
	}
	return os.Rename(tmp, m.h.Path(upgradeFile))
}

// UpgradeSpec asks for a major-version upgrade.
type UpgradeSpec struct {
	// Product is mysql, mariadb or postgresql.
	Product string `json:"product"`
	// To is the major version the panel offered; the upgrade is refused
	// when the server would get another one.
	To string `json:"to,omitempty"`
	// Log gets the progress; nil drops it.
	Log io.Writer `json:"-"`
	// Fail, when set, is asked before each step; an error makes the step
	// fail (tests).
	Fail func(step string) error `json:"-"`
}

// UpgradeResult describes a finished upgrade.
type UpgradeResult struct {
	Product string `json:"product"`
	From    string `json:"from"`
	To      string `json:"to"`
	Version string `json:"version"`
	Kept    Kept   `json:"kept"`
}

// Upgrade upgrades a database server to the next major version Serfex
// offers, as the package comment describes. The server's databases cannot
// be used while it runs; other database calls for it return
// ErrUpgrading.
func (m *Manager) Upgrade(ctx context.Context, spec UpgradeSpec) (res UpgradeResult, err error) {
	p, ok := products[spec.Product]
	if !ok {
		return res, invalid("product must be mysql, mariadb or postgresql")
	}
	log := spec.Log
	if log == nil {
		log = io.Discard
	}
	m.mu.Lock()
	have, ok := m.installedProduct(p.server)
	if !ok || have.name != p.name {
		m.mu.Unlock()
		return res, invalid("%s is not installed", spec.Product)
	}
	if k, ok := m.loadKept()[p.name]; ok {
		m.mu.Unlock()
		return res, invalid("%s %s is still kept from the last upgrade; remove it first", k.Product, k.From)
	}
	if !m.startUpgrading(p.server) {
		m.mu.Unlock()
		return res, ErrUpgrading
	}
	st, err := m.loadState()
	m.mu.Unlock()
	defer m.startUpgrading("")
	if err != nil {
		return res, opError(err)
	}

	u := &upgrader{ctx: ctx, m: m, p: have, st: st, log: log, fail: spec.Fail, t: txn.New(m.h)}
	defer func() {
		if err != nil {
			u.rollback()
			err = opError(err)
		}
	}()
	if err := u.check(spec.To); err != nil {
		return res, err
	}
	if p.server == PostgreSQL {
		err = u.postgres()
	} else {
		err = u.mysql()
	}
	if err != nil {
		return res, err
	}
	kept := m.loadKept()
	kept[p.name] = u.kept
	if err := m.saveKept(kept); err != nil {
		// The upgrade itself is done; only the reminder to remove the
		// old version is missing.
		fmt.Fprintf(log, "  %s: %v\n", upgradeFile, err)
	}
	return UpgradeResult{Product: p.name, From: u.from, To: u.to, Version: u.version, Kept: u.kept}, nil
}

// upgrader is one upgrade on its way; the flags say how far it got, for
// the rollback.
type upgrader struct {
	ctx  context.Context
	m    *Manager
	p    product
	st   *state
	log  io.Writer
	fail func(string) error
	// t holds Serfex's files the upgrade changes (pg_hba.conf, apt
	// sources, the read-only setting).
	t *txn.Tx

	from, to, version string
	dataDir           string
	dir               string
	kept              Kept

	maintenance bool
	// PostgreSQL: the old cluster's configuration files, the packages
	// added and whether the new cluster exists.
	checksums  bool
	oldConf    map[string][]byte
	added      []string
	newCluster bool
	// MySQL and MariaDB: the copy of the data directory, the saved
	// packages and how far the server got.
	debsDir    string
	debs       []string
	stopped    bool
	copyDir    string
	copied     bool
	installing bool
}

func (u *upgrader) step(format string, a ...any) {
	fmt.Fprintln(u.log, "▸ "+i18n.T(u.ctx, format, a...))
}

func (u *upgrader) note(format string, a ...any) {
	fmt.Fprintln(u.log, "  "+i18n.T(u.ctx, format, a...))
}

func (u *upgrader) failpoint(step string) error {
	if u.fail != nil {
		return u.fail(step)
	}
	return nil
}

// run runs a command with its output in the log.
func (u *upgrader) run(c host.Command) (string, error) {
	fmt.Fprintf(u.log, "$ %s\n", c)
	c.Stream = u.log
	return u.m.h.Run(u.ctx, c)
}

// quiet runs a command, logging only a failure.
func (u *upgrader) quiet(ctx context.Context, c host.Command) (string, error) {
	out, err := u.m.h.Run(ctx, c)
	if err != nil {
		fmt.Fprintf(u.log, "$ %s\n%s\n", c, strings.TrimSpace(out))
	}
	return out, err
}

func (u *upgrader) engine() engine { return u.m.engines[u.p.server] }

// check is step 1: what is installed, what it becomes and whether the
// disk has room.
func (u *upgrader) check(want string) error {
	u.step("Ön denetimler yapılıyor")
	ctx, cancel := context.WithTimeout(u.ctx, 10*time.Second)
	v, err := u.engine().version(ctx)
	cancel()
	if err != nil {
		return fmt.Errorf("%s does not answer, so it cannot be upgraded safely: %w", u.p.unit, err)
	}
	if u.p.server == PostgreSQL {
		u.from = u.m.pgMainVersion()
	} else {
		v, _, _ = strings.Cut(v, "-")
		u.from = MajorOf(u.p.name, v)
	}
	u.to = UpgradeTarget(u.p.name, u.from)
	switch {
	case u.from == "":
		return fmt.Errorf("the version of %s (%q) is not understood", u.p.name, v)
	case u.to == "":
		return invalid("%s %s is the newest version Serfex offers", u.p.name, u.from)
	case want != "" && want != u.to:
		return invalid("%s %s is upgraded to %s, not %s", u.p.name, u.from, u.to, want)
	}
	fmt.Fprintf(u.log, "  %s %s → %s\n", label(u.p.name), u.from, u.to)

	if u.p.server == PostgreSQL {
		u.dataDir = u.m.pgDataDir(u.from)
		ctx, cancel := context.WithTimeout(u.ctx, 10*time.Second)
		out := "off"
		if e, ok := u.engine().(*pgEngine); ok {
			out, err = e.exec(ctx, "postgres", "SHOW data_checksums;")
		}
		cancel()
		if err != nil {
			return err
		}
		u.checksums = strings.TrimSpace(out) == "on"
		if u.m.exists("/etc/postgresql/" + u.to + "/main") {
			return invalid("a PostgreSQL %s cluster named main exists already; Serfex does not upgrade over it", u.to)
		}
	} else {
		u.dataDir = u.p.dataDir
		u.copyDir = u.p.dataDir + "-" + u.from
		if u.m.exists(u.copyDir) {
			return invalid("%s exists already; move it away first", u.copyDir)
		}
		if osCodename(u.m.h.Path("/etc/os-release")) == "" {
			return errors.New("the release's codename is unknown, so the new version's archive cannot be added")
		}
	}

	if !u.m.exists(u.dataDir) {
		return fmt.Errorf("the data directory %s was not found; Serfex does not upgrade a server it cannot copy", u.dataDir)
	}
	size, err := dirSize(u.m.h.Path(u.dataDir))
	if err != nil {
		return fmt.Errorf("measuring %s: %w", u.dataDir, err)
	}
	if err := os.MkdirAll(u.m.h.Path(BackupDir), 0o700); err != nil {
		return err
	}
	// The second copy of the data goes beside it, the dump to BackupDir;
	// the dump is at most as big as the data.
	need := map[uint64]int64{}
	free := map[uint64]int64{}
	for _, d := range []string{filepath.Dir(u.dataDir), BackupDir} {
		dev, avail, err := u.m.freeSpace(d)
		if err != nil {
			return fmt.Errorf("free space of %s: %w", d, err)
		}
		need[dev] += size
		free[dev] = avail
	}
	for dev, n := range need {
		if n += upgradeMargin; free[dev] < n {
			return invalid("not enough disk space: the upgrade needs %s free (twice the %s of data, plus 1 GB), %s is free", humanBytes(n), humanBytes(size), humanBytes(free[dev]))
		}
	}
	u.note("Veri: %s, gereken boş alan var", humanBytes(size))
	return u.failpoint("check")
}

// pgDataDir is a PostgreSQL cluster's data directory.
func (m *Manager) pgDataDir(version string) string {
	dir := "/var/lib/postgresql/" + version + "/main"
	b, err := os.ReadFile(m.h.Path("/etc/postgresql/" + version + "/main/postgresql.conf"))
	if err != nil {
		return dir
	}
	for _, line := range strings.Split(string(b), "\n") {
		k, v, ok := strings.Cut(strings.TrimSpace(line), "=")
		if !ok || strings.TrimSpace(k) != "data_directory" {
			continue
		}
		v, _, _ = strings.Cut(v, "#")
		if v = strings.Trim(strings.TrimSpace(v), `'"`); strings.HasPrefix(v, "/") {
			dir = v
		}
	}
	return dir
}

// freeSpace returns the device of the file system holding dir and the
// bytes free on it for root.
func (m *Manager) freeSpace(dir string) (uint64, int64, error) {
	if m.FreeSpace != nil {
		return m.FreeSpace(dir)
	}
	var fs syscall.Statfs_t
	if err := syscall.Statfs(m.h.Path(dir), &fs); err != nil {
		return 0, 0, err
	}
	fi, err := os.Stat(m.h.Path(dir))
	if err != nil {
		return 0, 0, err
	}
	var dev uint64
	if s, ok := fi.Sys().(*syscall.Stat_t); ok {
		dev = uint64(s.Dev)
	}
	return dev, int64(fs.Bfree) * int64(fs.Bsize), nil
}

// dirSize adds up the sizes of the files under dir.
func dirSize(dir string) (int64, error) {
	var n int64
	err := filepath.WalkDir(dir, func(_ string, d fs.DirEntry, err error) error {
		if err != nil {
			if errors.Is(err, fs.ErrNotExist) {
				return nil
			}
			return err
		}
		if d.Type().IsRegular() {
			if fi, err := d.Info(); err == nil {
				n += fi.Size()
			}
		}
		return nil
	})
	return n, err
}

func humanBytes(n int64) string {
	switch {
	case n >= 1<<30:
		return fmt.Sprintf("%.1f GB", float64(n)/(1<<30))
	case n >= 1<<20:
		return fmt.Sprintf("%.0f MB", float64(n)/(1<<20))
	default:
		return fmt.Sprintf("%d KB", (n+1023)/1024)
	}
}

func label(product string) string {
	return map[string]string{MySQL: "MySQL", MariaDB: "MariaDB", PostgreSQL: "PostgreSQL"}[product]
}

// manifest is what the upgrade checks the new version against: the rows
// of every table of every customer database.
type manifest struct {
	Product   string                      `json:"product"`
	From      string                      `json:"from"`
	To        string                      `json:"to"`
	Taken     time.Time                   `json:"taken"`
	Databases map[string]map[string]int64 `json:"databases"`
	Users     []string                    `json:"users"`
}

// backup is step 3: row counts and dumps, taken while nothing changes.
func (u *upgrader) backup() (*manifest, error) {
	m := u.m
	u.dir = fmt.Sprintf("%s/upgrade-%s-%s-to-%s-%s", BackupDir, u.p.name, u.from, u.to, m.now().UTC().Format("20060102-150405"))
	if err := os.MkdirAll(m.h.Path(u.dir), 0o700); err != nil {
		return nil, err
	}
	man := &manifest{Product: u.p.name, From: u.from, To: u.to, Taken: m.now().UTC(), Databases: map[string]map[string]int64{}, Users: u.users()}
	dbs, err := u.databases()
	if err != nil {
		return nil, err
	}
	u.step("Tablolardaki satırlar sayılıyor (%d veritabanı)", len(dbs))
	for _, db := range dbs {
		counts, err := u.engine().rowCounts(u.ctx, db)
		if err != nil {
			return nil, fmt.Errorf("counting the rows of %s: %w", db, err)
		}
		man.Databases[db] = counts
	}
	u.step("Veritabanları yedekleniyor: %s", u.dir)
	var total int64
	for _, db := range dbs {
		path := u.dir + "/" + db + ".sql.gz"
		n, err := u.dumpTo(db, path)
		if err != nil {
			return nil, fmt.Errorf("dumping %s: %w", db, err)
		}
		total += n
	}
	if err := u.dumpUsers(); err != nil {
		return nil, fmt.Errorf("saving the users: %w", err)
	}
	b, _ := json.MarshalIndent(man, "", "  ")
	if err := os.WriteFile(m.h.Path(u.dir+"/manifest.json"), b, 0o600); err != nil {
		return nil, err
	}
	u.note("%d veritabanı, %s sıkıştırılmış döküm", len(dbs), humanBytes(total))
	return man, u.failpoint("backup")
}

// databases lists the customer databases on the server (the panel's and
// any others).
func (u *upgrader) databases() ([]string, error) {
	l, err := u.engine().list(u.ctx)
	if err != nil {
		return nil, err
	}
	var out []string
	for _, d := range l.Databases {
		out = append(out, d.Name)
	}
	for k := range u.st.Databases {
		if server, name, _ := strings.Cut(k, "/"); server == u.p.server && !slices.Contains(out, name) {
			return nil, fmt.Errorf("the database %s the panel manages is missing on the server; nothing was changed", name)
		}
	}
	sort.Strings(out)
	return out, nil
}

// users are the server's users the panel manages.
func (u *upgrader) users() []string {
	var out []string
	for k := range u.st.Users {
		if server, name, _ := strings.Cut(k, "/"); server == u.p.server {
			out = append(out, name)
		}
	}
	sort.Strings(out)
	return out
}

func (u *upgrader) dumpTo(db, path string) (int64, error) {
	f, err := os.OpenFile(u.m.h.Path(path), os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0o600)
	if err != nil {
		return 0, err
	}
	zw := gzip.NewWriter(f)
	err = u.engine().dump(u.ctx, db, zw, func(c host.Command) host.Command { return c })
	if cerr := zw.Close(); err == nil {
		err = cerr
	}
	if cerr := f.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		return 0, err
	}
	fi, err := os.Stat(u.m.h.Path(path))
	if err != nil {
		return 0, err
	}
	return fi.Size(), nil
}

// dumpUsers keeps the users' password hashes beside the dumps:
// PostgreSQL's roles as pg_dumpall writes them, MySQL's and MariaDB's as
// Yedekler restores them.
func (u *upgrader) dumpUsers() error {
	if u.p.server == PostgreSQL {
		f, err := os.OpenFile(u.m.h.Path(u.dir+"/globals.sql"), os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0o600)
		if err != nil {
			return err
		}
		_, err = u.m.h.Run(u.ctx, host.AsUser("postgres", host.Command{Name: "pg_dumpall", Args: []string{"--globals-only"}, Stdout: f}))
		if cerr := f.Close(); err == nil {
			err = cerr
		}
		return err
	}
	var users []UserExport
	for _, name := range u.users() {
		c, found, err := u.engine().credential(u.ctx, name)
		if err != nil {
			return err
		}
		if found {
			users = append(users, UserExport{Server: u.p.server, Name: name, Credential: c})
		}
	}
	b, _ := json.MarshalIndent(users, "", "  ")
	return os.WriteFile(u.m.h.Path(u.dir+"/users.json"), b, 0o600)
}

// verify is step 5: the new version holds what the old one did.
func (u *upgrader) verify(man *manifest) error {
	u.step("Doğrulanıyor: veritabanları, kullanıcılar ve satır sayıları")
	ctx, cancel := context.WithTimeout(u.ctx, 10*time.Second)
	v, err := u.engine().version(ctx)
	cancel()
	if err != nil {
		return fmt.Errorf("the new version does not answer: %w", err)
	}
	if got := MajorOf(u.p.name, strings.SplitN(v, "-", 2)[0]); got != u.to {
		return fmt.Errorf("the server reports version %s, not %s", v, u.to)
	}
	u.version = v
	l, err := u.engine().list(u.ctx)
	if err != nil {
		return err
	}
	have := map[string]bool{}
	for _, d := range l.Databases {
		have[d.Name] = true
	}
	for db := range man.Databases {
		if !have[db] {
			return fmt.Errorf("the database %s is missing after the upgrade", db)
		}
	}
	for _, name := range man.Users {
		if _, found, err := u.engine().credential(u.ctx, name); err != nil {
			return err
		} else if !found {
			return fmt.Errorf("the user %s is missing after the upgrade", name)
		}
	}
	tables := 0
	var rows int64
	for _, db := range sortedKeys(man.Databases) {
		counts, err := u.engine().rowCounts(u.ctx, db)
		if err != nil {
			return fmt.Errorf("counting the rows of %s: %w", db, err)
		}
		want := man.Databases[db]
		for _, t := range sortedKeys(want) {
			got, ok := counts[t]
			if !ok {
				return fmt.Errorf("the table %s.%s is missing after the upgrade", db, t)
			}
			if got != want[t] {
				return fmt.Errorf("the table %s.%s has %d rows after the upgrade, %d before", db, t, got, want[t])
			}
			tables++
			rows += got
		}
		for t := range counts {
			if _, ok := want[t]; !ok {
				return fmt.Errorf("the table %s.%s appeared during the upgrade", db, t)
			}
		}
	}
	u.note("%d veritabanı, %d kullanıcı, %d tablo ve %d satır yerinde", len(man.Databases), len(man.Users), tables, rows)
	return u.failpoint("verify")
}

// PostgreSQL.

// postgres upgrades the customers' cluster with pg_upgradecluster (copy
// mode): the old cluster's files are not touched, it only moves to
// another port and stays stopped.
func (u *upgrader) postgres() error {
	m := u.m
	if err := u.pgMaintenance(); err != nil {
		return err
	}
	man, err := u.backup()
	if err != nil {
		return err
	}

	newP := pgProduct(u.to)
	if !m.exists(newP.marker) {
		u.step("PostgreSQL %s kuruluyor", u.to)
		if err := m.addArchive(u.ctx, u.t, m.archiveFor(PostgreSQL, u.to), u.to, u.log); err != nil {
			return err
		}
		// Recorded first: a half-finished install is purged too.
		u.added = newP.packages
		if err := installPackages(u.ctx, m.h, newP.packages, "", u.log); err != nil {
			return err
		}
	}
	// pg_upgrade needs every extension a database uses in the new
	// version too.
	var exts []string
	for _, x := range pgExtensions {
		if m.exists(controlFile(u.from, x.name)) && !m.exists(controlFile(u.to, x.name)) {
			exts = append(exts, x.pkg(u.to))
		}
	}
	if len(exts) > 0 {
		u.added = append(u.added, exts...)
		if err := installPackages(u.ctx, m.h, exts, "", u.log); err != nil {
			return err
		}
	}
	// The package may have made an empty main cluster of its own.
	if m.exists("/etc/postgresql/" + u.to + "/main") {
		if _, err := u.run(host.Command{Name: "pg_dropcluster", Args: []string{"--stop", u.to, "main"}}); err != nil {
			return err
		}
	}
	if err := u.failpoint("install"); err != nil {
		return err
	}

	// pg_upgradecluster moves the old cluster to another port and makes
	// it start by hand only; a rollback puts its files back.
	if u.oldConf, err = readDir(m.h.Path("/etc/postgresql/" + u.from + "/main")); err != nil {
		return err
	}
	u.step("Veriler PostgreSQL %s kümesine aktarılıyor (pg_upgrade, eski küme korunur)", u.to)
	env := []string{"LC_ALL=C.UTF-8"}
	if dir, err := u.pgCommonConf(); err != nil {
		return err
	} else if dir != "" {
		env = append(env, "PGSYSCONFDIR="+dir)
		defer os.RemoveAll(m.h.Path(dir))
	}
	u.newCluster = true
	up := host.Command{Name: "pg_upgradecluster", Args: []string{"-v", u.to, "--method=upgrade", "--no-start", u.from, "main"}, Env: env}
	if out, err := u.run(up); err != nil {
		return fmt.Errorf("pg_upgradecluster failed: %s", pgUpgradeReason(out))
	}
	if err := u.failpoint("upgrade"); err != nil {
		return err
	}

	// The new cluster reads Serfex's pg_hba.conf, still the maintenance
	// one, before it starts.
	if err := u.writePGConf(newP, "localhost"); err != nil {
		return err
	}
	u.step("PostgreSQL %s başlatılıyor", u.to)
	if _, err := u.run(host.Command{Name: "systemctl", Args: []string{"start", newP.unit}}); err != nil {
		return fmt.Errorf("%s did not start (see journalctl -u %s): %w", newP.unit, newP.unit, err)
	}
	if _, err := m.waitFor(u.ctx, PostgreSQL); err != nil {
		return err
	}
	u.step("Sorgu planlayıcısının istatistikleri yenileniyor")
	if _, err := u.run(host.AsUser("postgres", host.Command{Name: "vacuumdb", Args: []string{"--all", "--analyze-only", "--quiet"}})); err != nil {
		// Queries are slower until autovacuum catches up; no data is at
		// stake.
		u.note("İstatistikler yenilenemedi (autovacuum zamanla yeniler): %v", lastLine(err.Error()))
	}
	if err := u.verify(man); err != nil {
		return err
	}

	u.step("Müşteri bağlantıları açılıyor")
	if err := u.finish(newP); err != nil {
		return err
	}
	// The old cluster stays stopped, also after a reboot.
	_, _ = u.quiet(u.ctx, host.Command{Name: "systemctl", Args: []string{"disable", u.p.unit}})
	now := m.now().UTC()
	u.kept = Kept{Product: u.p.name, From: u.from, To: u.to, Old: u.from + "/main", Dump: u.dir, Upgraded: now, Until: now.Add(UpgradeKeep)}
	u.note("Eski küme (PostgreSQL %s main) durduruldu ve %s tarihine kadar saklanıyor", u.from, u.kept.Until.Format("2006-01-02"))
	return nil
}

// pgCommonConf returns a copy of postgresql-common's settings for
// pg_upgradecluster when the new cluster must be made without data
// checksums: initdb turns them on from PostgreSQL 18, and pg_upgrade
// refuses to copy a cluster without them into one with them. Older
// postgresql-common releases (Ubuntu 24.04's) do not ask for that
// themselves. "" means none is needed.
func (u *upgrader) pgCommonConf() (string, error) {
	if u.checksums || !newerMajor(u.to, "17") {
		return "", nil
	}
	const src = "/etc/postgresql-common"
	// The upgrade hooks run as postgres, so not beside the dumps.
	dir := "/run/serfex-pg-upgrade"
	if err := os.RemoveAll(u.m.h.Path(dir)); err != nil {
		return "", err
	}
	if !u.m.exists(src) {
		if err := os.MkdirAll(u.m.h.Path(dir), 0o755); err != nil {
			return "", err
		}
	} else if _, err := u.m.h.Run(u.ctx, host.Command{Name: "cp", Args: []string{"-a", src, dir}}); err != nil {
		return "", fmt.Errorf("copying %s: %w", src, err)
	}
	conf := u.m.h.Path(dir + "/createcluster.conf")
	b, err := os.ReadFile(conf)
	if err != nil && !errors.Is(err, os.ErrNotExist) {
		return "", err
	}
	var lines []string
	opts := "--no-data-checksums"
	for _, line := range strings.Split(string(b), "\n") {
		k, v, ok := strings.Cut(line, "=")
		if ok && strings.TrimSpace(k) == "initdb_options" {
			opts = strings.Trim(strings.TrimSpace(v), `'"`) + " " + opts
			continue
		}
		lines = append(lines, line)
	}
	lines = append(lines, "# Serfex: the old cluster has no data checksums.", "initdb_options = '"+strings.TrimSpace(opts)+"'", "")
	return dir, os.WriteFile(conf, []byte(strings.Join(lines, "\n")), 0o644)
}

// pgUpgradeReason picks the reason out of pg_upgradecluster's output:
// pg_upgrade writes it just before "Failure, exiting".
func pgUpgradeReason(out string) string {
	lines := strings.Split(strings.TrimSpace(out), "\n")
	for i, l := range lines {
		if strings.TrimSpace(l) == "Failure, exiting" {
			for j := i - 1; j >= 0; j-- {
				if r := strings.TrimSpace(lines[j]); r != "" {
					return r + " (pg_upgrade)"
				}
			}
		}
	}
	for i := len(lines) - 1; i >= 0; i-- {
		if r := strings.TrimSpace(lines[i]); r != "" && !strings.HasPrefix(r, "Starting old cluster") && r != "Cluster is not running." {
			return r
		}
	}
	return "see /var/log/postgresql"
}

// pgMaintenance lets only the postgres account in and ends the
// customers' connections.
func (u *upgrader) pgMaintenance() error {
	u.step("Bakım kipi: müşteri bağlantıları kapatılıyor")
	pg, err := u.m.h.LookupUser("postgres")
	if err != nil {
		return fmt.Errorf("postgres account: %w", err)
	}
	hba := fmt.Sprintf("# %s. Serfex is upgrading PostgreSQL; only the postgres account may connect.\nlocal   all             postgres                                peer\n", txn.Marker)
	if err := u.t.WriteFileMode(pgHBA, []byte(hba), 0o640, 0, pg.GID); err != nil {
		return err
	}
	u.maintenance = true
	if _, err := u.run(host.Command{Name: "systemctl", Args: []string{"reload", u.p.unit}}); err != nil {
		return err
	}
	return u.engine().maintenance(u.ctx, true)
}

// writePGConf writes Serfex's settings of a cluster that is not running.
func (u *upgrader) writePGConf(p product, bind string) error {
	if err := os.MkdirAll(u.m.h.Path(filepath.Dir(p.conf)), 0o755); err != nil {
		return err
	}
	return os.WriteFile(u.m.h.Path(p.conf), render(pgConfTmpl, map[string]any{"Marker": txn.Marker, "Bind": bind, "HBA": pgHBA}), 0o644)
}

// finish lets the customers in: Serfex's network settings (pg_hba.conf
// and the bind address of the users' hosts) on the new version.
func (u *upgrader) finish(p product) error {
	m := u.m
	m.mu.Lock()
	st, err := m.loadState()
	m.mu.Unlock()
	if err != nil {
		return err
	}
	if p.server == MySQL {
		if err := u.t.RemoveFile(readOnlyConf(p)); err != nil {
			return err
		}
	}
	if err := m.writeNetwork(u.t, p, st); err != nil {
		return err
	}
	if _, err := u.run(host.Command{Name: "systemctl", Args: []string{"enable", p.unit}}); err != nil {
		return err
	}
	if _, err := u.run(host.Command{Name: "systemctl", Args: []string{"restart", p.unit}}); err != nil {
		return fmt.Errorf("%s did not start (see journalctl -u %s): %w", p.unit, p.unit, err)
	}
	if _, err := m.waitFor(u.ctx, p.server); err != nil {
		return err
	}
	if p.server == MySQL {
		// It restarted without the read-only setting; this only makes
		// sure.
		if err := u.engine().maintenance(u.ctx, false); err != nil {
			return err
		}
	}
	if err := u.failpoint("finish"); err != nil {
		return err
	}
	u.maintenance = false
	return nil
}

// readDir reads the regular files of a directory.
func readDir(dir string) (map[string][]byte, error) {
	out := map[string][]byte{}
	entries, err := os.ReadDir(dir)
	if err != nil {
		return nil, err
	}
	for _, e := range entries {
		if !e.Type().IsRegular() {
			continue
		}
		b, err := os.ReadFile(filepath.Join(dir, e.Name()))
		if err != nil {
			return nil, err
		}
		out[e.Name()] = b
	}
	return out, nil
}

// MySQL and MariaDB.

// readOnlyConf keeps the server read-only while it is upgraded, also
// when the new packages start it.
func readOnlyConf(p product) string {
	return filepath.Dir(p.conf) + "/zz-serfex-upgrade.cnf"
}

// mysql upgrades MySQL or MariaDB in place: the data directory is copied
// first and the old packages are saved, so going back puts both back.
func (u *upgrader) mysql() error {
	m := u.m
	a := m.archiveFor(u.p.name, u.to)
	if err := u.saveDebs(); err != nil {
		return err
	}
	if err := u.mysqlMaintenance(); err != nil {
		return err
	}
	man, err := u.backup()
	if err != nil {
		return err
	}

	u.step("%s durduruluyor", u.p.unit)
	u.stopped = true
	if _, err := u.run(host.Command{Name: "systemctl", Args: []string{"stop", u.p.unit}}); err != nil {
		return err
	}
	u.step("Veri klasörü kopyalanıyor: %s → %s", u.p.dataDir, u.copyDir)
	if _, err := u.run(host.Command{Name: "cp", Args: []string{"-a", u.p.dataDir, u.copyDir}}); err != nil {
		_ = os.RemoveAll(u.m.h.Path(u.copyDir))
		return fmt.Errorf("copying the data directory: %w", err)
	}
	u.copied = true
	if err := u.failpoint("copy"); err != nil {
		return err
	}

	u.step("%s %s kuruluyor", label(u.p.name), u.to)
	if err := m.addArchive(u.ctx, u.t, a, u.to, u.log); err != nil {
		return err
	}
	pkgs := u.p.packages
	u.installing = true
	inst := host.Command{Name: "apt-get", Args: append([]string{"install", "-y", "-q", "--no-install-recommends", "-o", "Dpkg::Options::=--force-confold"}, pkgs...), Env: aptEnv}
	if _, err := u.run(inst); err != nil {
		return fmt.Errorf("installing %s %s: %w", label(u.p.name), u.to, err)
	}
	if err := u.failpoint("install"); err != nil {
		return err
	}
	p, _ := m.installedProduct(u.p.server)
	u.step("%s başlatılıyor (veri sözlüğü yeni sürüme yükseltilir)", p.unit)
	if _, err := u.run(host.Command{Name: "systemctl", Args: []string{"restart", p.unit}}); err != nil {
		return fmt.Errorf("%s did not start (see journalctl -u %s): %w", p.unit, p.unit, err)
	}
	if _, err := m.waitFor(u.ctx, MySQL); err != nil {
		return err
	}
	if p.name == MariaDB {
		u.step("Sistem tabloları yükseltiliyor (mariadb-upgrade)")
		if _, err := u.run(host.Command{Name: "mariadb-upgrade"}); err != nil {
			return fmt.Errorf("mariadb-upgrade failed: %w", err)
		}
	}
	if err := u.failpoint("upgrade"); err != nil {
		return err
	}
	if err := u.verify(man); err != nil {
		return err
	}
	u.step("Müşteri bağlantıları açılıyor")
	if err := u.finish(p); err != nil {
		return err
	}
	now := m.now().UTC()
	u.kept = Kept{Product: u.p.name, From: u.from, To: u.to, Old: u.copyDir, Dump: u.dir, Packages: u.debsDir, Upgraded: now, Until: now.Add(UpgradeKeep)}
	u.note("Eski veri klasörü (%s) %s tarihine kadar saklanıyor", u.copyDir, u.kept.Until.Format("2006-01-02"))
	return nil
}

// mysqlMaintenance makes the server read-only: customers keep reading,
// but nothing changes until the new version is verified.
func (u *upgrader) mysqlMaintenance() error {
	u.step("Bakım kipi: veritabanları salt okunur yapılıyor")
	conf := fmt.Sprintf("# %s. Serfex is upgrading the server; customers cannot change data until it is done.\n[mysqld]\nread_only = ON\n", txn.Marker)
	if err := u.t.WriteFile(readOnlyConf(u.p), []byte(conf)); err != nil {
		return err
	}
	u.maintenance = true
	return u.engine().maintenance(u.ctx, true)
}

// saveDebs downloads the installed version's packages, which a rollback
// installs again (a newer major version's data directory cannot be read
// by the old one, and apt does not keep old versions).
func (u *upgrader) saveDebs() error {
	u.step("Geri dönüş için kurulu sürümün paketleri saklanıyor")
	pkgs, err := u.installedPackages()
	if err != nil {
		return err
	}
	if len(pkgs) == 0 {
		return errors.New("the installed packages could not be listed")
	}
	dir := fmt.Sprintf("%s/upgrade-%s-%s-packages-%s", BackupDir, u.p.name, u.from, u.m.now().UTC().Format("20060102-150405"))
	if err := os.MkdirAll(u.m.h.Path(dir), 0o700); err != nil {
		return err
	}
	// apt-get download fetches the candidate, which is the newest minor
	// release of the installed major version (the archive is not
	// switched yet).
	if _, err := u.run(host.Command{Name: "env", Args: append([]string{"-C", dir, "apt-get", "download", "-q"}, pkgs...), Env: aptEnv}); err != nil {
		_ = os.RemoveAll(u.m.h.Path(dir))
		return fmt.Errorf("the packages of %s %s could not be downloaded, so there would be no way back; nothing was changed: %w", label(u.p.name), u.from, err)
	}
	matches, _ := filepath.Glob(u.m.h.Path(dir + "/*.deb"))
	for _, f := range matches {
		u.debs = append(u.debs, dir+"/"+filepath.Base(f))
	}
	if len(u.debs) == 0 && !u.sim() {
		_ = os.RemoveAll(u.m.h.Path(dir))
		return errors.New("no packages were downloaded, so there would be no way back; nothing was changed")
	}
	u.debsDir = dir
	return nil
}

func (u *upgrader) sim() bool {
	_, ok := u.m.h.(*host.Simulator)
	return ok
}

// installedPackages lists the installed packages of the product.
func (u *upgrader) installedPackages() ([]string, error) {
	if sim, ok := u.m.h.(*host.Simulator); ok {
		var out []string
		for _, p := range u.p.packages {
			if sim.PackageInstalled(p) || u.m.exists(u.p.marker) {
				out = append(out, p)
			}
		}
		return out, nil
	}
	patterns := []string{"mysql-*", "mariadb-*", "libmariadb*", "libmysqlclient*", "galera-*"}
	out, err := u.m.h.Run(u.ctx, host.Command{Name: "dpkg-query", Args: append([]string{"-W", "-f=${Package}\t${db:Status-Status}\n"}, patterns...)})
	if err != nil && strings.TrimSpace(out) == "" {
		return nil, err
	}
	var pkgs []string
	for _, line := range strings.Split(out, "\n") {
		f := strings.Split(line, "\t")
		if len(f) == 2 && f[1] == "installed" {
			pkgs = append(pkgs, f[0])
		}
	}
	return pkgs, nil
}

// Rollback.

// rollback puts the old version back as it was before the upgrade. A
// refusal in the checks changed nothing.
func (u *upgrader) rollback() {
	ctx := context.WithoutCancel(u.ctx)
	u.ctx = ctx
	if !u.maintenance && len(u.added) == 0 && u.debsDir == "" {
		_ = u.t.Rollback(ctx)
		return
	}
	fmt.Fprintln(u.log, "↺ "+i18n.T(ctx, "Eski sürüme dönülüyor"))
	var errs []error
	if u.p.server == PostgreSQL {
		errs = u.pgRollback(ctx)
	} else {
		errs = u.mysqlRollback(ctx)
	}
	if u.dir != "" && len(errs) == 0 {
		u.note("Yükseltme öncesi döküm saklanıyor: %s", u.dir)
	}
	for _, err := range errs {
		fmt.Fprintf(u.log, "  %s\n", i18n.T(ctx, "geri alma: %v", err))
	}
}

func (u *upgrader) pgRollback(ctx context.Context) []error {
	var errs []error
	m := u.m
	newP := pgProduct(u.to)
	if u.newCluster {
		_, _ = u.quiet(ctx, host.Command{Name: "systemctl", Args: []string{"stop", newP.unit}})
	}
	// Serfex's pg_hba.conf (and the archive, if it was added) come back
	// while the new cluster's files are still there.
	if err := u.t.Rollback(ctx); err != nil {
		errs = append(errs, err)
	}
	if u.newCluster && m.exists("/etc/postgresql/"+u.to+"/main") {
		if _, err := u.run(host.Command{Name: "pg_dropcluster", Args: []string{"--stop", u.to, "main"}}); err != nil {
			errs = append(errs, err)
		}
		m.pgForget(u.to, "main")
	}
	if len(u.added) > 0 {
		fmt.Fprintf(u.log, "↺ %s\n", i18n.T(ctx, "Kurulan paketler kaldırılıyor: %s", strings.Join(u.added, " ")))
		_, _ = u.run(host.Command{Name: "apt-get", Args: append([]string{"purge", "-y", "-q"}, u.added...), Env: aptEnv})
	}
	// The old cluster's port and start mode come back.
	dir := m.h.Path("/etc/postgresql/" + u.from + "/main")
	changed := false
	for name, b := range u.oldConf {
		if cur, err := os.ReadFile(filepath.Join(dir, name)); err == nil && string(cur) == string(b) {
			continue
		}
		changed = true
		if err := os.WriteFile(filepath.Join(dir, name), b, 0o644); err != nil {
			errs = append(errs, err)
		}
	}
	if _, had := u.oldConf["start.conf"]; u.oldConf != nil && !had {
		// Without one the cluster starts with the machine again.
		if err := os.Remove(filepath.Join(dir, "start.conf")); err == nil {
			changed = true
		}
	}
	verb := "reload"
	if u.newCluster || changed {
		// pg_upgradecluster stopped it.
		verb = "restart"
		_, _ = u.quiet(ctx, host.Command{Name: "systemctl", Args: []string{"daemon-reload"}})
		_, _ = u.quiet(ctx, host.Command{Name: "systemctl", Args: []string{"enable", u.p.unit}})
	}
	if _, err := u.run(host.Command{Name: "systemctl", Args: []string{verb, u.p.unit}}); err != nil {
		errs = append(errs, err)
	} else if _, err := m.waitFor(ctx, PostgreSQL); err != nil {
		errs = append(errs, err)
	}
	return errs
}

func (u *upgrader) mysqlRollback(ctx context.Context) []error {
	var errs []error
	m := u.m
	if !u.stopped {
		// Nothing was replaced: the server only becomes writable again.
		if err := u.t.Rollback(ctx); err != nil {
			errs = append(errs, err)
		}
		if u.maintenance {
			if err := u.engine().maintenance(ctx, false); err != nil {
				errs = append(errs, err)
			}
		}
		u.dropDebs()
		return errs
	}
	unit := u.p.unit
	if p, ok := m.installedProduct(u.p.server); ok {
		unit = p.unit
	}
	_, _ = u.quiet(ctx, host.Command{Name: "systemctl", Args: []string{"stop", unit}})
	failed := ""
	if u.copied && m.exists(u.copyDir) {
		// The copy is the data as it was; the upgraded one goes aside
		// until the old version runs again.
		failed = fmt.Sprintf("%s-failed-%s", u.p.dataDir, u.to)
		_ = os.RemoveAll(m.h.Path(failed))
		if _, err := u.run(host.Command{Name: "mv", Args: []string{"-T", u.p.dataDir, failed}}); err != nil {
			errs = append(errs, err)
			failed = ""
		} else if _, err := u.run(host.Command{Name: "mv", Args: []string{"-T", u.copyDir, u.p.dataDir}}); err != nil {
			errs = append(errs, err)
		}
	}
	// Serfex's files and the old archive come back, then the old
	// packages, which start the old version on its own data.
	if err := u.t.Rollback(ctx); err != nil {
		errs = append(errs, err)
	}
	if u.installing && len(u.debs) > 0 {
		fmt.Fprintf(u.log, "↺ %s\n", i18n.T(ctx, "%s %s paketleri yeniden kuruluyor", label(u.p.name), u.from))
		args := append([]string{"install", "-y", "-q", "--allow-downgrades", "-o", "Dpkg::Options::=--force-confold"}, u.debs...)
		if _, err := u.run(host.Command{Name: "apt-get", Args: args, Env: aptEnv}); err != nil {
			errs = append(errs, err)
		}
	}
	p, _ := m.installedProduct(u.p.server)
	if p.unit == "" {
		p = u.p
	}
	if _, err := u.run(host.Command{Name: "systemctl", Args: []string{"restart", p.unit}}); err != nil {
		errs = append(errs, err)
	} else if _, err := m.waitFor(ctx, MySQL); err != nil {
		errs = append(errs, err)
	} else if err := u.engine().maintenance(ctx, false); err != nil {
		errs = append(errs, err)
	} else if failed != "" && len(errs) == 0 {
		// Customers could not write to the upgraded copy: nothing is lost
		// with it.
		_ = os.RemoveAll(m.h.Path(failed))
	}
	if len(errs) == 0 {
		u.dropDebs()
	} else if failed != "" {
		u.note("Yükseltilmiş veri klasörü incelemek için saklandı: %s", failed)
	}
	return errs
}

func (u *upgrader) dropDebs() {
	if u.debsDir != "" {
		_ = os.RemoveAll(u.m.h.Path(u.debsDir))
	}
}

// Removing the old version.

// RemoveOld deletes the old version an upgrade kept: PostgreSQL's old
// cluster (and its packages when no other cluster uses them), or the
// copy of MySQL's or MariaDB's data directory and the saved packages.
// The dumps stay until BackupRetention.
func (m *Manager) RemoveOld(ctx context.Context, product string, log io.Writer) error {
	if log == nil {
		log = io.Discard
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	kept := m.loadKept()
	k, ok := kept[product]
	if !ok {
		return invalid("no old version of %s is kept", product)
	}
	if err := m.removeOld(ctx, k, log); err != nil {
		return opError(err)
	}
	delete(kept, product)
	return opError(m.saveKept(kept))
}

func (m *Manager) removeOld(ctx context.Context, k Kept, log io.Writer) error {
	run := func(c host.Command) error {
		fmt.Fprintf(log, "$ %s\n", c)
		c.Stream = log
		_, err := m.h.Run(ctx, c)
		return err
	}
	if k.Product == PostgreSQL {
		v, name, _ := strings.Cut(k.Old, "/")
		if v == m.pgMainVersion() {
			return fmt.Errorf("PostgreSQL %s is the version in use", v)
		}
		if m.exists("/etc/postgresql/" + v + "/" + name) {
			fmt.Fprintln(log, "▸ "+i18n.T(ctx, "Eski küme siliniyor (PostgreSQL %s %s)", v, name))
			if err := run(host.Command{Name: "pg_dropcluster", Args: []string{"--stop", v, name}}); err != nil {
				return err
			}
			m.pgForget(v, name)
		}
		// Serfex's own cluster may run on the same version.
		if entries, _ := os.ReadDir(m.h.Path("/etc/postgresql/" + v)); len(entries) == 0 {
			pkgs := pgProduct(v).packages
			fmt.Fprintln(log, "▸ "+i18n.T(ctx, "Paketler kaldırılıyor: %s", strings.Join(pkgs, " ")))
			if err := run(host.Command{Name: "apt-get", Args: append([]string{"purge", "-y", "-q"}, pkgs...), Env: aptEnv}); err != nil {
				return err
			}
		}
		return nil
	}
	for _, dir := range []string{k.Old, k.Packages} {
		if dir == "" || !m.exists(dir) {
			continue
		}
		fmt.Fprintln(log, "▸ "+i18n.T(ctx, "Siliniyor: %s", dir))
		if err := os.RemoveAll(m.h.Path(dir)); err != nil {
			return err
		}
	}
	return nil
}

// pgForget removes what pg_dropcluster leaves of a cluster: Serfex's
// own settings and the directories they were in.
func (m *Manager) pgForget(version, name string) {
	dir := "/etc/postgresql/" + version + "/" + name
	if m.exists(dir + "/postgresql.conf") {
		return
	}
	_ = os.Remove(m.h.Path(dir + "/conf.d/serfex.conf"))
	for _, d := range []string{dir + "/conf.d", dir, "/etc/postgresql/" + version} {
		// Only empty directories go.
		_ = os.Remove(m.h.Path(d))
	}
}

// keeps reports whether a kept old version still needs dir.
func (m *Manager) keeps(dir string) bool {
	for _, k := range m.loadKept() {
		if k.Dump == dir || k.Packages == dir || k.Old == dir {
			return true
		}
	}
	return false
}

// PruneKept removes old versions kept longer than UpgradeKeep.
func (m *Manager) PruneKept(ctx context.Context, log io.Writer) {
	if log == nil {
		log = io.Discard
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	kept := m.loadKept()
	changed := false
	for product, k := range kept {
		if m.now().Before(k.Until) {
			continue
		}
		if err := m.removeOld(ctx, k, log); err != nil {
			continue
		}
		delete(kept, product)
		changed = true
	}
	if changed {
		_ = m.saveKept(kept)
	}
}
