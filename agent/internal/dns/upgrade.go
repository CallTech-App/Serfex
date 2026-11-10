package dns

import (
	"context"
	"crypto/rand"
	"encoding/binary"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"syscall"
	"time"

	"github.com/calltech-app/serfex/agent/internal/aptcache"
	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/i18n"
	"github.com/calltech-app/serfex/agent/internal/txn"
)

// Upgrading PowerDNS to the Series (the Software page's "Sürümü yükselt").
//
// PowerDNS 5.0 moves the LMDB database to a new schema the moment it
// starts, and older versions cannot read it again. So the upgrade keeps,
// before anything changes, the old version's packages, a copy of the
// database taken with PowerDNS stopped, and every zone as the API shows
// it. It then installs the Series from PowerDNS's archive, starts it,
// compares every zone, record set and DNSSEC key with the copy and asks
// the server for each zone's SOA over port 53. A failed step puts the old
// packages and the copied database back. For KeepFor afterwards Revert
// does the same on request; the panel then writes its zones again, so
// changes made since the upgrade stay.
//
// Layout:
//
//	/var/lib/serfex/powerdns/upgrade/<time>/packages/   the old .deb files
//	/var/lib/serfex/powerdns/upgrade/<time>/lmdb/       serfex.lmdb* as they were
//	/var/lib/serfex/powerdns/upgrade/<time>/zones.json  zones, record sets and keys
//	/var/lib/serfex/powerdns/kept.json                  what is kept, until when

const (
	upgradeDir = "/var/lib/serfex/powerdns/upgrade"
	keptFile   = "/var/lib/serfex/powerdns/kept.json"
	lmdbDir    = "/var/lib/powerdns"
	lmdbName   = "serfex.lmdb"
	// KeepFor is how long an upgrade can be undone.
	KeepFor = 7 * 24 * time.Hour
)

// ErrNoUpgrade is returned when PowerDNS is already on the Series or
// newer.
var ErrNoUpgrade = errors.New("PowerDNS is already on the newest series Serfex offers")

// Offer is the series PowerDNS can be upgraded to.
type Offer struct {
	From string `json:"from"`
	To   string `json:"to"`
}

// Kept is what an upgrade keeps so it can be undone.
type Kept struct {
	// From and To are the package versions before and after.
	From     string    `json:"from"`
	To       string    `json:"to"`
	Dir      string    `json:"dir"`
	Zones    int       `json:"zones"`
	Upgraded time.Time `json:"upgraded"`
	Until    time.Time `json:"until"`
}

// UpgradeSpec configures Upgrade and Revert.
type UpgradeSpec struct {
	// Log receives the progress: "▸ " steps, "  " details, "↺ " the
	// rollback.
	Log io.Writer
	// Fail, when set, is asked before each step; an error makes the step
	// fail (tests).
	Fail func(step string) error
}

// Series of a package version: "4.8.3-4build3" is 4.8.
func seriesOf(version string) string {
	parts := strings.Split(aptcache.Upstream(version), ".")
	if len(parts) < 2 {
		return ""
	}
	return parts[0] + "." + parts[1]
}

// olderSeries reports whether series a is older than b.
func olderSeries(a, b string) bool {
	var a1, a2, b1, b2 int
	if _, err := fmt.Sscanf(a, "%d.%d", &a1, &a2); err != nil {
		return false
	}
	if _, err := fmt.Sscanf(b, "%d.%d", &b1, &b2); err != nil {
		return false
	}
	return a1 < b1 || a1 == b1 && a2 < b2
}

// UpgradeOffer is the upgrade PowerDNS installed in version (as dpkg
// reports it) can have, nil when there is none.
func UpgradeOffer(version string) *Offer {
	if from := seriesOf(version); from != "" && olderSeries(from, Series) {
		return &Offer{From: from, To: Series}
	}
	return nil
}

// KeptVersion is what the last upgrade keeps, nil when nothing is kept.
func (m *Manager) KeptVersion() *Kept {
	b, err := os.ReadFile(m.h.Path(keptFile))
	if err != nil {
		return nil
	}
	var k Kept
	if json.Unmarshal(b, &k) != nil || k.Dir == "" {
		return nil
	}
	return &k
}

func (m *Manager) saveKept(k *Kept) error {
	if k == nil {
		if err := os.Remove(m.h.Path(keptFile)); err != nil && !errors.Is(err, os.ErrNotExist) {
			return err
		}
		return nil
	}
	if err := os.MkdirAll(filepath.Dir(m.h.Path(keptFile)), 0o700); err != nil {
		return err
	}
	b, _ := json.MarshalIndent(k, "", "  ")
	return os.WriteFile(m.h.Path(keptFile), b, 0o600)
}

type upgrader struct {
	m    *Manager
	ctx  context.Context
	spec UpgradeSpec
	dir  string
}

func (u *upgrader) step(format string, a ...any) {
	fmt.Fprintln(u.spec.Log, "▸ "+i18n.T(u.ctx, format, a...))
}

func (u *upgrader) note(format string, a ...any) {
	fmt.Fprintln(u.spec.Log, "  "+i18n.T(u.ctx, format, a...))
}

func (u *upgrader) failpoint(step string) error {
	if u.spec.Fail != nil {
		return u.spec.Fail(step)
	}
	return nil
}

func (u *upgrader) run(c host.Command) (string, error) {
	c.Stream = u.spec.Log
	return u.m.h.Run(u.ctx, c)
}

// Upgrade moves PowerDNS to the Series, keeping what Revert needs for
// KeepFor. Any failure puts the old version back.
func (m *Manager) Upgrade(ctx context.Context, spec UpgradeSpec) (kept *Kept, err error) {
	if spec.Log == nil {
		spec.Log = io.Discard
	}
	m.mu.Lock()
	defer m.mu.Unlock()

	cfg, err := m.readConfig()
	if err != nil {
		return nil, err
	}
	from := m.PackageVersion(ctx)
	if from == "" {
		return nil, invalid("PowerDNS is not installed")
	}
	if UpgradeOffer(from) == nil {
		return nil, invalid("%s", ErrNoUpgrade)
	}
	if k := m.KeptVersion(); k != nil {
		return nil, invalid("the previous upgrade is still kept; remove it first")
	}
	b := m.newBackend(cfg)
	if _, err := b.Version(ctx); err != nil {
		return nil, invalid("PowerDNS is not running; start it on the Services page first: %v", err)
	}

	u := &upgrader{m: m, ctx: ctx, spec: spec, dir: upgradeDir + "/" + m.now().UTC().Format("20060102-150405")}
	if err := os.MkdirAll(m.h.Path(u.dir+"/packages"), 0o700); err != nil {
		return nil, &OperationError{err}
	}
	fail := func(err error) (*Kept, error) {
		_ = os.RemoveAll(m.h.Path(u.dir))
		var v *ValidationError
		if errors.As(err, &v) {
			return nil, err
		}
		return nil, &OperationError{err}
	}

	// 1. PowerDNS's archive. Nothing has changed yet when this fails.
	t := txn.New(m.h)
	suite, _ := m.suite()
	u.step("PowerDNS paket deposu ekleniyor (%s, %s)", archiveHost, suite)
	err = u.failpoint("archive")
	if err == nil {
		err = m.addArchive(ctx, t, spec.Log)
	}
	if err != nil {
		u.rollbackArchive(t)
		return fail(fmt.Errorf("PowerDNS's archive could not be read: %w", err))
	}

	// 2. What a rollback needs.
	u.step("Eski sürümün paketleri saklanıyor (%s)", aptcache.Upstream(from))
	if err := u.saveDebs(); err != nil {
		u.rollbackArchive(t)
		return fail(fmt.Errorf("the old packages could not be kept, so the upgrade could not be undone: %w", err))
	}
	u.step("Bölgeler kaydediliyor")
	before, err := u.export(b)
	if err != nil {
		u.rollbackArchive(t)
		return fail(err)
	}
	u.note("%d bölge", len(before.Zones))

	// 3. PowerDNS stops and its database is copied; it starts again as it
	// was when that fails.
	u.step("DNS sunucusu durduruluyor, veritabanı kopyalanıyor")
	err = u.failpoint("stop")
	if err == nil {
		_, err = u.run(host.Command{Name: "systemctl", Args: []string{"stop", unit}})
	}
	if err == nil {
		err = m.copyLMDB(lmdbDir, u.dir+"/lmdb")
	}
	if err != nil {
		u.rollbackArchive(t)
		_, _ = m.h.Run(context.WithoutCancel(ctx), host.Command{Name: "systemctl", Args: []string{"restart", unit}})
		return fail(err)
	}

	// 4. From here on the old version is put back on failure.
	defer func() {
		if err == nil {
			return
		}
		fmt.Fprintln(spec.Log, "↺ "+i18n.T(ctx, "Eski sürüme dönülüyor"))
		if rbErr := u.restore(t); rbErr != nil {
			err = fmt.Errorf("%w; going back to the old version also failed: %v (the old packages and database copy are in %s)", err, rbErr, u.dir)
			return
		}
		_ = os.RemoveAll(m.h.Path(u.dir))
	}()
	u.step("PowerDNS %s kuruluyor", Series)
	if err = u.failpoint("install"); err != nil {
		return nil, &OperationError{err}
	}
	if err = u.installPackages(); err != nil {
		return nil, &OperationError{err}
	}
	to := m.PackageVersion(ctx)
	if !OfSeries(to) {
		err = fmt.Errorf("pdns-server %q was installed, not %s", to, Series)
		return nil, &OperationError{err}
	}

	u.step("PowerDNS başlatılıyor; veritabanı yeni biçime geçiyor")
	if err = u.failpoint("start"); err != nil {
		return nil, &OperationError{err}
	}
	if err = m.start(ctx, b, spec.Log); err != nil {
		return nil, &OperationError{err}
	}

	u.step("Bölgeler, kayıtlar ve DNSSEC anahtarları karşılaştırılıyor")
	if err = u.failpoint("verify"); err != nil {
		return nil, &OperationError{err}
	}
	after, err := u.export(b)
	if err != nil {
		return nil, &OperationError{err}
	}
	if err = compareExports(before, after); err != nil {
		return nil, &OperationError{err}
	}
	if err = m.answers(ctx, before); err != nil {
		return nil, &OperationError{err}
	}
	u.note("%d bölge aynı, sorgular yanıtlanıyor", len(after.Zones))

	now := m.now().UTC()
	kept = &Kept{From: from, To: to, Dir: u.dir, Zones: len(before.Zones), Upgraded: now, Until: now.Add(KeepFor)}
	if err = m.saveKept(kept); err != nil {
		return nil, &OperationError{err}
	}
	return kept, nil
}

// rollbackArchive takes PowerDNS's archive out again before anything else
// changed.
func (u *upgrader) rollbackArchive(t *txn.Tx) {
	fmt.Fprintln(u.spec.Log, "↺ "+i18n.T(u.ctx, "PowerDNS paket deposu kaldırılıyor"))
	_ = t.Rollback(context.WithoutCancel(u.ctx))
}

// saveDebs downloads the installed packages' files, so the old version
// can be put back without Ubuntu's archive.
func (u *upgrader) saveDebs() error {
	var args []string
	for _, p := range Packages {
		v := u.m.packageVersion(u.ctx, p)
		if v == "" {
			return fmt.Errorf("%s is not installed", p)
		}
		args = append(args, p+"="+v)
	}
	dir := u.dir + "/packages"
	if _, err := u.run(host.Command{Name: "env", Args: append([]string{"-C", dir, "apt-get", "download", "-q"}, args...), Env: aptEnv}); err != nil {
		return err
	}
	if u.m.sim {
		for _, p := range Packages {
			_ = os.WriteFile(u.m.h.Path(dir+"/"+p+"_"+simUbuntuVersion+"_amd64.deb"), nil, 0o644)
		}
	}
	debs, _ := filepath.Glob(u.m.h.Path(dir + "/*.deb"))
	if len(debs) != len(Packages) {
		return fmt.Errorf("apt-get download left %d package files, want %d", len(debs), len(Packages))
	}
	return nil
}

// installPackages installs the Series from PowerDNS's archive; the
// stock instance, which the packages may start, stays off.
func (u *upgrader) installPackages() error {
	args := append([]string{"install", "-y", "-q", "--no-install-recommends", "-o", "Dpkg::Options::=--force-confdef", "-o", "Dpkg::Options::=--force-confold"}, Packages...)
	if _, err := u.run(host.Command{Name: "apt-get", Args: args, Env: aptEnv}); err != nil {
		return err
	}
	u.m.simInstalled()
	_, _ = u.m.h.Run(u.ctx, host.Command{Name: "systemctl", Args: []string{"disable", "--now", "pdns.service"}})
	return nil
}

// start checks the configuration with the installed PowerDNS, starts the
// Serfex instance and waits for its API.
func (m *Manager) start(ctx context.Context, b Backend, log io.Writer) error {
	if _, err := m.h.Run(ctx, host.Command{Name: pdnsBinary, Args: []string{"--config-name=" + instance, "--config=check"}, Stream: log}); err != nil {
		return fmt.Errorf("PowerDNS rejected the configuration: %w", err)
	}
	if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"restart", unit}}); err != nil {
		return err
	}
	_, err := m.waitForAPI(ctx, b)
	return err
}

// restore puts the old packages and the database copy back and starts
// the old version.
func (u *upgrader) restore(t *txn.Tx) error {
	ctx := context.WithoutCancel(u.ctx)
	m := u.m
	_, _ = m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"stop", unit}})
	debs, _ := filepath.Glob(m.h.Path(u.dir + "/packages/*.deb"))
	if len(debs) == 0 {
		return fmt.Errorf("no old packages in %s", u.dir+"/packages")
	}
	// dpkg installs exactly these files, also when apt could fetch the
	// same version again.
	args := []string{"--force-confdef", "--force-confold", "-i"}
	for _, d := range debs {
		// dpkg takes a path; on a simulated host it is under its root.
		rel, _ := filepath.Rel(m.h.Path("/"), d)
		args = append(args, "/"+rel)
	}
	if _, err := m.h.Run(ctx, host.Command{Name: "dpkg", Args: args, Env: aptEnv, Stream: u.spec.Log}); err != nil {
		return fmt.Errorf("installing the old packages: %w", err)
	}
	_, _ = m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"disable", "--now", "pdns.service"}})
	if t != nil {
		if err := t.Rollback(ctx); err != nil {
			return err
		}
	} else if err := m.dropArchive(ctx); err != nil {
		return err
	}
	m.simInstalled()
	if _, err := os.Stat(m.h.Path(u.dir + "/lmdb/" + lmdbName)); err != nil {
		return fmt.Errorf("no database copy in %s", u.dir+"/lmdb")
	}
	if err := m.removeLMDB(lmdbDir); err != nil {
		return err
	}
	if err := m.copyLMDB(u.dir+"/lmdb", lmdbDir); err != nil {
		return err
	}
	cfg, err := m.readConfig()
	if err != nil {
		return err
	}
	return m.start(ctx, m.newBackend(cfg), u.spec.Log)
}

// dropArchive takes PowerDNS's archive out of apt.
func (m *Manager) dropArchive(ctx context.Context) error {
	for _, f := range []string{archiveSources, archivePin, archiveKeyring} {
		if err := os.Remove(m.h.Path(f)); err != nil && !errors.Is(err, os.ErrNotExist) {
			return err
		}
	}
	_, err := m.h.Run(ctx, host.Command{Name: "apt-get", Args: []string{"update", "-q"}, Env: aptEnv})
	return err
}

// Revert puts back the version an upgrade kept: its packages and its
// database as they were before the upgrade. The panel then writes every
// zone again, so changes made since the upgrade stay. The upgraded
// database is kept beside the copy until RemoveOld.
func (m *Manager) Revert(ctx context.Context, spec UpgradeSpec) (err error) {
	if spec.Log == nil {
		spec.Log = io.Discard
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	k := m.KeptVersion()
	if k == nil {
		return invalid("no upgrade is kept to go back from")
	}
	for _, f := range []string{"/lmdb/" + lmdbName, "/packages"} {
		if _, err := os.Stat(m.h.Path(k.Dir + f)); err != nil {
			return &OperationError{fmt.Errorf("the kept old version is incomplete: %w", err)}
		}
	}
	u := &upgrader{m: m, ctx: ctx, spec: spec, dir: k.Dir}
	u.step("DNS sunucusu durduruluyor")
	if err := u.failpoint("stop"); err != nil {
		return &OperationError{err}
	}
	if _, err := u.run(host.Command{Name: "systemctl", Args: []string{"stop", unit}}); err != nil {
		return &OperationError{err}
	}
	if err := m.copyLMDB(lmdbDir, k.Dir+"/lmdb-"+Series); err != nil {
		return &OperationError{err}
	}
	u.step("PowerDNS %s ve yükseltme öncesi veritabanı geri yükleniyor", aptcache.Upstream(k.From))
	if err := u.restore(nil); err != nil {
		// Whatever is installed now is started again.
		_, _ = m.h.Run(context.WithoutCancel(ctx), host.Command{Name: "systemctl", Args: []string{"restart", unit}})
		return &OperationError{fmt.Errorf("%w (the database copies are in %s)", err, k.Dir)}
	}
	// The panel holds every zone and writes them again; the copies are
	// no longer needed.
	_ = os.RemoveAll(m.h.Path(k.Dir))
	if err := m.saveKept(nil); err != nil {
		return &OperationError{err}
	}
	u.note("Panel bölgeleri yeniden yazacak; yükseltmeden sonra yapılan değişiklikler korunur")
	return nil
}

// Restart checks the configuration with the installed PowerDNS, restarts
// the Serfex instance and waits for it: after apt upgraded the packages,
// which does not restart instances.
func (m *Manager) Restart(ctx context.Context, log io.Writer) error {
	if log == nil {
		log = io.Discard
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	cfg, err := m.readConfig()
	if err != nil {
		return err
	}
	if err := m.start(ctx, m.newBackend(cfg), log); err != nil {
		return &OperationError{err}
	}
	return nil
}

// Update installs the newest release of the installed series (5.1.x from
// PowerDNS's archive, Ubuntu's security fixes otherwise) and restarts the
// server.
func (m *Manager) Update(ctx context.Context, log io.Writer) error {
	if log == nil {
		log = io.Discard
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	cfg, err := m.readConfig()
	if err != nil {
		return err
	}
	args := append([]string{"install", "-y", "-q", "--only-upgrade", "--no-install-recommends", "-o", "Dpkg::Options::=--force-confdef", "-o", "Dpkg::Options::=--force-confold"}, Packages...)
	if _, err := m.h.Run(ctx, host.Command{Name: "apt-get", Args: args, Env: aptEnv, Stream: log}); err != nil {
		return &OperationError{err}
	}
	_, _ = m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"disable", "--now", "pdns.service"}})
	if err := m.start(ctx, m.newBackend(cfg), log); err != nil {
		return &OperationError{err}
	}
	return nil
}

// RemoveOld deletes what the last upgrade kept; it cannot be undone
// afterwards.
func (m *Manager) RemoveOld(ctx context.Context, log io.Writer) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	k := m.KeptVersion()
	if k == nil {
		return invalid("no upgrade is kept")
	}
	if log != nil {
		fmt.Fprintln(log, "▸ "+i18n.T(ctx, "Saklanan eski sürüm siliniyor: %s", k.Dir))
	}
	if err := os.RemoveAll(m.h.Path(k.Dir)); err != nil {
		return &OperationError{err}
	}
	return m.saveKept(nil)
}

// PruneKept deletes what an upgrade kept once KeepFor has passed.
func (m *Manager) PruneKept(ctx context.Context) {
	if k := m.KeptVersion(); k != nil && m.now().After(k.Until) {
		_ = m.RemoveOld(ctx, nil)
	}
}

// copyLMDB copies serfex.lmdb and its shards and lock files from one
// directory to another, keeping owners and modes. PowerDNS is stopped.
func (m *Manager) copyLMDB(from, to string) error {
	if _, err := os.Stat(m.h.Path(from + "/" + lmdbName)); err != nil {
		return fmt.Errorf("no DNS database to copy: %w", err)
	}
	files, err := filepath.Glob(m.h.Path(from + "/" + lmdbName + "*"))
	if err != nil {
		return err
	}
	if err := os.MkdirAll(m.h.Path(to), 0o700); err != nil {
		return err
	}
	for _, f := range files {
		if err := copyFile(f, m.h.Path(to+"/"+filepath.Base(f))); err != nil {
			return err
		}
	}
	return nil
}

// removeLMDB deletes the database files in dir.
func (m *Manager) removeLMDB(dir string) error {
	files, err := filepath.Glob(m.h.Path(dir + "/" + lmdbName + "*"))
	if err != nil {
		return err
	}
	for _, f := range files {
		if err := os.Remove(f); err != nil {
			return err
		}
	}
	return nil
}

func copyFile(from, to string) error {
	in, err := os.Open(from)
	if err != nil {
		return err
	}
	defer in.Close()
	fi, err := in.Stat()
	if err != nil {
		return err
	}
	out, err := os.OpenFile(to, os.O_CREATE|os.O_TRUNC|os.O_WRONLY, fi.Mode().Perm())
	if err != nil {
		return err
	}
	if _, err := io.Copy(out, in); err != nil {
		out.Close()
		return err
	}
	if err := out.Sync(); err != nil {
		out.Close()
		return err
	}
	if err := out.Close(); err != nil {
		return err
	}
	if st, ok := fi.Sys().(*syscall.Stat_t); ok {
		_ = os.Lchown(to, int(st.Uid), int(st.Gid))
	}
	return os.Chmod(to, fi.Mode().Perm())
}

// export is every zone as the API shows it, with its DNSSEC keys.
type export struct {
	Zones map[string]*Zone `json:"zones"`
	Keys  map[string][]Key `json:"keys"`
}

func (u *upgrader) export(b Backend) (*export, error) {
	list, err := b.Zones(u.ctx)
	if err != nil {
		return nil, fmt.Errorf("listing zones: %w", err)
	}
	e := &export{Zones: map[string]*Zone{}, Keys: map[string][]Key{}}
	for _, z := range list {
		full, err := b.Zone(u.ctx, z.Name)
		if err != nil || full == nil {
			return nil, fmt.Errorf("reading zone %s: %v", z.Name, err)
		}
		e.Zones[z.Name] = full
		if full.DNSSEC {
			keys, err := b.Keys(u.ctx, z.Name)
			if err != nil {
				return nil, fmt.Errorf("reading the keys of %s: %w", z.Name, err)
			}
			sort.Slice(keys, func(i, j int) bool { return keys[i].ID < keys[j].ID })
			e.Keys[z.Name] = keys
		}
	}
	data, _ := json.MarshalIndent(e, "", " ")
	name := "/zones.json"
	if _, err := os.Stat(u.m.h.Path(u.dir + name)); err == nil {
		name = "/zones-after.json"
	}
	if err := os.WriteFile(u.m.h.Path(u.dir+name), data, 0o600); err != nil {
		return nil, err
	}
	return e, nil
}

// compareExports reports the first difference that matters between the
// zones before and after: names, kinds, catalogs, signing settings, record
// sets and keys. A catalog zone's SOA is left out: PowerDNS raises its
// serial whenever it recounts the catalog. DS digests are left out too:
// 5.x no longer lists SHA-1, which Serfex never shows.
func compareExports(before, after *export) error {
	if len(before.Zones) != len(after.Zones) {
		return fmt.Errorf("%d zones before the upgrade, %d after", len(before.Zones), len(after.Zones))
	}
	for name, a := range before.Zones {
		b := after.Zones[name]
		if b == nil {
			return fmt.Errorf("zone %s is missing after the upgrade", name)
		}
		if a.Kind != b.Kind || a.Catalog != b.Catalog || a.DNSSEC != b.DNSSEC || a.NSEC3Param != b.NSEC3Param || a.SOAEdit != b.SOAEdit {
			return fmt.Errorf("zone %s changed: kind %q→%q, catalog %q→%q, DNSSEC %v→%v, NSEC3 %q→%q, SOA-EDIT %q→%q",
				name, a.Kind, b.Kind, a.Catalog, b.Catalog, a.DNSSEC, b.DNSSEC, a.NSEC3Param, b.NSEC3Param, a.SOAEdit, b.SOAEdit)
		}
		sets := func(z *Zone) map[string]RRSet {
			out := map[string]RRSet{}
			for _, s := range z.RRSets {
				if z.Kind == kindProducer && s.Type == "SOA" {
					continue
				}
				out[s.key()] = s
			}
			return out
		}
		sa, sb := sets(a), sets(b)
		if len(sa) != len(sb) {
			return fmt.Errorf("zone %s has %d record sets before the upgrade, %d after", name, len(sa), len(sb))
		}
		for k, s := range sa {
			o, ok := sb[k]
			if !ok || !s.equal(o) {
				return fmt.Errorf("zone %s: %s changed in the upgrade", name, k)
			}
		}
		ka, kb := before.Keys[name], after.Keys[name]
		if len(ka) != len(kb) {
			return fmt.Errorf("zone %s has %d DNSSEC keys before the upgrade, %d after", name, len(ka), len(kb))
		}
		for i := range ka {
			if ka[i].ID != kb[i].ID || ka[i].Active != kb[i].Active || strings.Join(strings.Fields(ka[i].DNSKEY), " ") != strings.Join(strings.Fields(kb[i].DNSKEY), " ") {
				return fmt.Errorf("zone %s: DNSSEC key %d changed in the upgrade", name, ka[i].ID)
			}
		}
	}
	return nil
}

// answers asks the server for the SOA of up to five zones over port 53.
func (m *Manager) answers(ctx context.Context, e *export) error {
	if m.query == nil {
		return nil
	}
	var names []string
	for n, z := range e.Zones {
		if z.Kind != kindProducer {
			names = append(names, n)
		}
	}
	sort.Strings(names)
	if len(names) > 5 {
		names = names[:5]
	}
	for _, n := range names {
		if err := m.query(ctx, n); err != nil {
			return fmt.Errorf("%s does not answer over DNS after the upgrade: %w", n, err)
		}
	}
	return nil
}

// querySOA asks 127.0.0.1:53 for a zone's SOA over UDP and wants an
// authoritative answer.
func querySOA(ctx context.Context, zone string) error {
	var id [2]byte
	_, _ = rand.Read(id[:])
	q := append([]byte{}, id[0], id[1], 0, 0, 0, 1, 0, 0, 0, 0, 0, 0)
	for _, l := range strings.Split(strings.TrimSuffix(zone, "."), ".") {
		if len(l) == 0 || len(l) > 63 {
			return fmt.Errorf("bad name %q", zone)
		}
		q = append(q, byte(len(l)))
		q = append(q, l...)
	}
	q = append(q, 0, 0, 6, 0, 1) // SOA, IN
	var d net.Dialer
	ctx, cancel := context.WithTimeout(ctx, 3*time.Second)
	defer cancel()
	var last error
	for try := 0; try < 3; try++ {
		conn, err := d.DialContext(ctx, "udp", "127.0.0.1:53")
		if err != nil {
			return err
		}
		_ = conn.SetDeadline(time.Now().Add(time.Second))
		if _, err := conn.Write(q); err != nil {
			conn.Close()
			return err
		}
		buf := make([]byte, 4096)
		n, err := conn.Read(buf)
		conn.Close()
		if err != nil {
			last = err
			continue
		}
		if n < 12 || buf[0] != id[0] || buf[1] != id[1] || buf[2]&0x80 == 0 {
			last = errors.New("malformed answer")
			continue
		}
		if rcode := buf[3] & 0x0f; rcode != 0 {
			return fmt.Errorf("answer code %d", rcode)
		}
		if buf[2]&0x04 == 0 || binary.BigEndian.Uint16(buf[6:8]) == 0 {
			return errors.New("no authoritative SOA in the answer")
		}
		return nil
	}
	return last
}
