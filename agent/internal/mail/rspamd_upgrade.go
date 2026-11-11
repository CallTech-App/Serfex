package mail

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"time"

	"github.com/calltech-app/serfex/agent/internal/aptcache"
	"github.com/calltech-app/serfex/agent/internal/aptrepo"
	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/i18n"
	"github.com/calltech-app/serfex/agent/internal/txn"
)

// Upgrading Rspamd to RspamdMajor (Yazılım Yönetimi's "Sürümü yükselt").
//
// Before anything changes, the upgrade keeps the old version's package
// and Rspamd's configuration directory. It then adds Rspamd's archive,
// installs the new version, checks the configuration with it, restarts it
// and waits for the milter Postfix uses. A failed step puts the old
// package and configuration back and takes the archive out. For KeepFor
// afterwards RevertRspamd does the same on request, keeping Serfex's
// settings (local.d) and the administrator's (override.d) as they are
// then. Rspamd's data in Redis (rate limits, history) stays as it is.
//
// While Rspamd restarts, Postfix passes mail on unchecked and unsigned
// (milter_default_action = accept), for a few seconds.
//
// Layout:
//
//	/var/lib/serfex/rspamd/upgrade/<time>/packages/  the old .deb
//	/var/lib/serfex/rspamd/upgrade/<time>/etc/       /etc/rspamd as it was
//	/var/lib/serfex/rspamd/kept.json                 what is kept, until when

const (
	rspamdUpgradeDir = "/var/lib/serfex/rspamd/upgrade"
	rspamdKeptFile   = "/var/lib/serfex/rspamd/kept.json"
	rspamdEtc        = "/etc/rspamd"
	// KeepFor is how long an upgrade can be undone.
	KeepFor = 7 * 24 * time.Hour
)

// rspamdOwnDirs are the parts of /etc/rspamd that are Serfex's and the
// administrator's, not the package's: a revert keeps them as they are.
var rspamdOwnDirs = []string{"local.d", "override.d"}

// Offer is the release series Rspamd can be upgraded to.
type Offer struct {
	From string `json:"from"`
	To   string `json:"to"`
}

// Kept is what an Rspamd upgrade keeps so it can be undone.
type Kept struct {
	// From and To are the package versions before and after.
	From     string    `json:"from"`
	To       string    `json:"to"`
	Dir      string    `json:"dir"`
	Upgraded time.Time `json:"upgraded"`
	Until    time.Time `json:"until"`
}

// UpgradeSpec configures UpgradeRspamd and RevertRspamd.
type UpgradeSpec struct {
	// Log receives the progress: "▸ " steps, "  " details, "↺ " the
	// rollback.
	Log io.Writer
	// Fail, when set, is asked before each step; an error makes the step
	// fail (tests).
	Fail func(step string) error
}

// RspamdKept is what the last upgrade keeps, nil when nothing is kept.
func (m *Manager) RspamdKept() *Kept {
	b, err := os.ReadFile(m.h.Path(rspamdKeptFile))
	if err != nil {
		return nil
	}
	var k Kept
	if json.Unmarshal(b, &k) != nil || k.Dir == "" {
		return nil
	}
	return &k
}

func (m *Manager) saveRspamdKept(k *Kept) error {
	if k == nil {
		if err := os.Remove(m.h.Path(rspamdKeptFile)); err != nil && !errors.Is(err, os.ErrNotExist) {
			return err
		}
		return nil
	}
	if err := os.MkdirAll(filepath.Dir(m.h.Path(rspamdKeptFile)), 0o700); err != nil {
		return err
	}
	b, _ := json.MarshalIndent(k, "", "  ")
	return os.WriteFile(m.h.Path(rspamdKeptFile), b, 0o600)
}

type rspamdUpgrader struct {
	m    *Manager
	ctx  context.Context
	spec UpgradeSpec
	dir  string
}

func (u *rspamdUpgrader) step(format string, a ...any) {
	fmt.Fprintln(u.spec.Log, "▸ "+i18n.T(u.ctx, format, a...))
}

func (u *rspamdUpgrader) failpoint(step string) error {
	if u.spec.Fail != nil {
		return u.spec.Fail(step)
	}
	return nil
}

// UpgradeRspamd moves Rspamd to RspamdMajor, keeping what RevertRspamd
// needs for KeepFor. Any failure puts the old version back.
func (m *Manager) UpgradeRspamd(ctx context.Context, spec UpgradeSpec) (kept *Kept, err error) {
	if spec.Log == nil {
		spec.Log = io.Discard
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	if !m.Configured() {
		return nil, ErrNotSetUp
	}
	from := m.RspamdVersion(ctx)
	if from == "" {
		return nil, invalid("Rspamd is not installed")
	}
	if RspamdOffer(from) == nil {
		return nil, invalid("Rspamd is already on the newest version Serfex offers")
	}
	if m.RspamdKept() != nil {
		return nil, invalid("the previous upgrade is still kept; remove it first")
	}

	u := &rspamdUpgrader{m: m, ctx: ctx, spec: spec, dir: rspamdUpgradeDir + "/" + time.Now().UTC().Format("20060102-150405")}
	if err := os.MkdirAll(m.h.Path(u.dir), 0o700); err != nil {
		return nil, &OperationError{err}
	}
	fail := func(t *txn.Tx, err error) (*Kept, error) {
		if t != nil {
			fmt.Fprintln(spec.Log, "↺ "+i18n.T(ctx, "Rspamd paket deposu kaldırılıyor"))
			_ = t.Rollback(context.WithoutCancel(ctx))
		}
		_ = os.RemoveAll(m.h.Path(u.dir))
		return nil, &OperationError{err}
	}

	// 1. Rspamd's archive. Nothing has changed yet when this fails.
	t := txn.New(m.h)
	u.step("Rspamd paket deposu ekleniyor (%s)", rspamdArchive.Host)
	err = u.failpoint("archive")
	if err == nil {
		err = m.addRspamdArchive(ctx, t, spec.Log)
	}
	if err != nil {
		return fail(t, fmt.Errorf("Rspamd's archive could not be read: %w", err))
	}

	// 2. What a rollback needs.
	u.step("Eski sürümün paketi ve ayarları saklanıyor (%s)", aptcache.Upstream(from))
	if err := aptrepo.SaveDebs(ctx, m.h, u.dir+"/packages", map[string]string{rspamdPackage: from}, spec.Log); err != nil {
		return fail(t, fmt.Errorf("the old package could not be kept, so the upgrade could not be undone: %w", err))
	}
	if _, err := m.h.Run(ctx, host.Command{Name: "cp", Args: []string{"-a", rspamdEtc, u.dir + "/etc"}}); err != nil {
		return fail(t, fmt.Errorf("keeping %s: %w", rspamdEtc, err))
	}

	// 3. From here on the old version is put back on failure.
	defer func() {
		if err == nil {
			return
		}
		fmt.Fprintln(spec.Log, "↺ "+i18n.T(ctx, "Eski sürüme dönülüyor"))
		if rbErr := u.restore(t, from, false); rbErr != nil {
			err = fmt.Errorf("%w; going back to the old version also failed: %v (the old package and settings are in %s)", err, rbErr, u.dir)
			return
		}
		_ = os.RemoveAll(m.h.Path(u.dir))
	}()
	u.step("Rspamd %s kuruluyor", RspamdSeries)
	if err = u.failpoint("install"); err != nil {
		return nil, &OperationError{err}
	}
	args := []string{"install", "-y", "-q", "--no-install-recommends", "-o", "Dpkg::Options::=--force-confdef", "-o", "Dpkg::Options::=--force-confold", rspamdPackage}
	if _, err = m.h.Run(ctx, host.Command{Name: "apt-get", Args: args, Env: aptrepo.Env, Stream: spec.Log}); err != nil {
		return nil, &OperationError{err}
	}
	m.simRspamdInstalled()
	to := m.RspamdVersion(ctx)
	if !RspamdCurrent(to) {
		err = fmt.Errorf("rspamd %q was installed, not %s.x", to, RspamdMajor)
		return nil, &OperationError{err}
	}

	u.step("Rspamd %s ayarlarla denetleniyor ve başlatılıyor", aptcache.Upstream(to))
	if err = u.failpoint("start"); err != nil {
		return nil, &OperationError{err}
	}
	if err = m.restartRspamd(ctx, spec.Log); err != nil {
		return nil, &OperationError{err}
	}

	now := time.Now().UTC()
	kept = &Kept{From: from, To: to, Dir: u.dir, Upgraded: now, Until: now.Add(KeepFor)}
	if err = m.saveRspamdKept(kept); err != nil {
		return nil, &OperationError{err}
	}
	return kept, nil
}

// restore takes Rspamd's archive out (undoing t, or dropping it when t is
// nil), installs the kept package and configuration again and restarts
// Rspamd. keepOwn keeps local.d and override.d as they are now.
func (u *rspamdUpgrader) restore(t *txn.Tx, version string, keepOwn bool) error {
	ctx := context.WithoutCancel(u.ctx)
	m := u.m
	// The archive goes first: a dependency apt removed meanwhile comes
	// from Ubuntu's.
	if t != nil {
		if err := t.Rollback(ctx); err != nil {
			return err
		}
	} else if err := rspamdArchive.Drop(ctx, m.h, u.spec.Log); err != nil {
		return err
	}
	if err := aptrepo.InstallDebs(ctx, m.h, u.dir+"/packages", u.spec.Log); err != nil {
		return fmt.Errorf("installing the old package: %w", err)
	}
	m.simRspamdInstalled()
	if v := m.RspamdVersion(ctx); v != version {
		return fmt.Errorf("rspamd %q is installed after putting back %s", v, version)
	}
	if err := m.restoreRspamdEtc(ctx, u.dir, keepOwn); err != nil {
		return err
	}
	return m.restartRspamd(ctx, u.spec.Log)
}

// restoreRspamdEtc puts /etc/rspamd back as the upgrade kept it in dir,
// with local.d and override.d as they are now when keepOwn is set.
func (m *Manager) restoreRspamdEtc(ctx context.Context, dir string, keepOwn bool) error {
	saved := dir + "/etc"
	if _, err := os.Stat(m.h.Path(saved + "/rspamd.conf")); err != nil {
		return fmt.Errorf("no settings kept in %s", saved)
	}
	now := dir + "/etc-now"
	_ = os.RemoveAll(m.h.Path(now))
	if keepOwn {
		if err := os.MkdirAll(m.h.Path(now), 0o700); err != nil {
			return err
		}
		for _, d := range rspamdOwnDirs {
			if !m.exists(rspamdEtc + "/" + d) {
				continue
			}
			if _, err := m.h.Run(ctx, host.Command{Name: "cp", Args: []string{"-a", rspamdEtc + "/" + d, now + "/" + d}}); err != nil {
				return err
			}
		}
	}
	if err := os.RemoveAll(m.h.Path(rspamdEtc)); err != nil {
		return err
	}
	if _, err := m.h.Run(ctx, host.Command{Name: "cp", Args: []string{"-a", saved, rspamdEtc}}); err != nil {
		return err
	}
	if keepOwn {
		for _, d := range rspamdOwnDirs {
			if !m.exists(now + "/" + d) {
				continue
			}
			if err := os.RemoveAll(m.h.Path(rspamdEtc + "/" + d)); err != nil {
				return err
			}
			if _, err := m.h.Run(ctx, host.Command{Name: "cp", Args: []string{"-a", now + "/" + d, rspamdEtc + "/" + d}}); err != nil {
				return err
			}
		}
		_ = os.RemoveAll(m.h.Path(now))
	}
	return nil
}

// RevertRspamd puts back the version an upgrade kept: its package and
// Rspamd's own configuration files as they were before the upgrade.
// Serfex's settings and the administrator's overrides stay as they are
// now, so changes made since the upgrade stay.
func (m *Manager) RevertRspamd(ctx context.Context, spec UpgradeSpec) error {
	if spec.Log == nil {
		spec.Log = io.Discard
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	k := m.RspamdKept()
	if k == nil {
		return invalid("no upgrade is kept to go back from")
	}
	for _, f := range []string{"/packages", "/etc/rspamd.conf"} {
		if _, err := os.Stat(m.h.Path(k.Dir + f)); err != nil {
			return &OperationError{fmt.Errorf("the kept old version is incomplete: %w", err)}
		}
	}
	u := &rspamdUpgrader{m: m, ctx: ctx, spec: spec, dir: k.Dir}
	u.step("Rspamd %s ve yükseltme öncesi ayarları geri yükleniyor", aptcache.Upstream(k.From))
	if err := u.failpoint("restore"); err != nil {
		return &OperationError{err}
	}
	if err := u.restore(nil, k.From, true); err != nil {
		// Whatever is installed now is started again.
		_, _ = m.h.Run(context.WithoutCancel(ctx), host.Command{Name: "systemctl", Args: []string{"restart", "rspamd"}})
		return &OperationError{fmt.Errorf("%w (the old package and settings are in %s)", err, k.Dir)}
	}
	_ = os.RemoveAll(m.h.Path(k.Dir))
	if err := m.saveRspamdKept(nil); err != nil {
		return &OperationError{err}
	}
	return nil
}

// RemoveOldRspamd deletes what the last upgrade kept; it cannot be undone
// afterwards.
func (m *Manager) RemoveOldRspamd(ctx context.Context, log io.Writer) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	k := m.RspamdKept()
	if k == nil {
		return invalid("no upgrade is kept")
	}
	if log != nil {
		fmt.Fprintln(log, "▸ "+i18n.T(ctx, "Saklanan eski sürüm siliniyor: %s", k.Dir))
	}
	if err := os.RemoveAll(m.h.Path(k.Dir)); err != nil {
		return &OperationError{err}
	}
	return m.saveRspamdKept(nil)
}

// PruneRspamd deletes what an upgrade kept once KeepFor has passed.
func (m *Manager) PruneRspamd(ctx context.Context) {
	if k := m.RspamdKept(); k != nil && time.Now().After(k.Until) {
		_ = m.RemoveOldRspamd(ctx, nil)
	}
}
