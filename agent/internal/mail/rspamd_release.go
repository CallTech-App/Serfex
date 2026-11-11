package mail

import (
	"context"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strconv"
	"strings"

	"github.com/calltech-app/serfex/agent/internal/aptcache"
	"github.com/calltech-app/serfex/agent/internal/aptrepo"
	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/txn"
)

// Rspamd comes from Rspamd's own apt archive, pinned: Ubuntu keeps one
// version per release, and 24.04's 3.8.1 is one Rspamd no longer
// updates. Rspamd maintains only its newest release, so the pin follows
// Rspamd's stable releases within RspamdMajor; a new major version, which
// may change the configuration, comes with a Serfex release once it has
// been tested, never by Rspamd publishing it. RspamdSeries is the release
// this Serfex release was tested with.
const (
	RspamdMajor  = "4"
	RspamdSeries = "4.2"
)

// rspamdArchive is Rspamd's stable archive; its suite is the Ubuntu
// release's codename.
var rspamdArchive = aptrepo.Archive{
	Name: "serfex-rspamd", Title: "Rspamd " + RspamdMajor + ".x for Serfex's mail server",
	URI: "https://rspamd.com/apt-stable", Host: "rspamd.com", Component: "main",
	KeyURL: "https://rspamd.com/apt-stable/gpg.key", Fingerprint: "3FA347D5E599BE4595CA2576FFA232EDBF21E25E",
	Packages: "rspamd*", Versions: RspamdMajor + ".*",
	Why: "Rspamd comes from Rspamd's own archive, in the major version Serfex chose.",
}

// rspamdPackage is the package; RspamdVersion reports its version.
const rspamdPackage = "rspamd"

// RspamdCurrent reports whether an rspamd package version is of
// RspamdMajor: "4.2.3-1~50bde75~noble" is, "3.8.1-1ubuntu3" is not.
func RspamdCurrent(version string) bool {
	major, _, _ := strings.Cut(aptcache.Upstream(version), ".")
	return version != "" && major == RspamdMajor
}

// RspamdOffer is the upgrade Rspamd installed in version (as dpkg reports
// it) can have, nil when there is none.
func RspamdOffer(version string) *Offer {
	v := aptcache.Upstream(version)
	parts := strings.Split(v, ".")
	major, err := strconv.Atoi(parts[0])
	want, _ := strconv.Atoi(RspamdMajor)
	if version == "" || err != nil || len(parts) < 2 || major >= want {
		return nil
	}
	return &Offer{From: parts[0] + "." + parts[1], To: RspamdSeries}
}

// rspamdArchiveListed reports whether apt reads Rspamd's archive.
func (m *Manager) rspamdArchiveListed() bool { return rspamdArchive.Listed(m.h) }

// addRspamdArchive lists Rspamd's archive for apt, pinned, refreshes the
// package lists and checks apt would install RspamdMajor from it. Undoing
// t takes the archive out again.
func (m *Manager) addRspamdArchive(ctx context.Context, t *txn.Tx, log io.Writer) error {
	suite, err := aptrepo.Codename(m.h)
	if err != nil {
		return err
	}
	key, err := rspamdArchive.FetchKey(ctx, m.h, m.Download, m.sim)
	if err != nil {
		return err
	}
	if err := rspamdArchive.Add(ctx, m.h, t, suite, key, log); err != nil {
		return err
	}
	c := aptrepo.Candidate(ctx, m.h, rspamdPackage)
	if !RspamdCurrent(c.Version) || !rspamdArchive.Provides(c) {
		return fmt.Errorf("apt offers rspamd %q from %q, not %s.x from %s; is %s reachable?", c.Version, c.From, RspamdMajor, rspamdArchive.Host, rspamdArchive.Host)
	}
	return nil
}

// installMail installs the mail packages (what names them in errors):
// Rspamd from Rspamd's own archive or, when that archive cannot be read,
// Ubuntu's version, which the Software page then offers to upgrade
// (UpgradeRspamd).
func (m *Manager) installMail(ctx context.Context, what string, packages []string) error {
	defer m.simRspamdInstalled()
	t := txn.New(m.h)
	if err := m.addRspamdArchive(ctx, t, io.Discard); err == nil {
		if err := m.install(ctx, what, packages); err == nil {
			return nil
		}
	}
	_ = t.Rollback(context.WithoutCancel(ctx))
	return m.install(ctx, what, packages)
}

// RspamdVersion is the installed rspamd package version, "" when it is
// not installed.
func (m *Manager) RspamdVersion(ctx context.Context) string {
	if m.sim {
		if !m.exists("/usr/bin/rspamd") {
			return ""
		}
		// A dry run has Ubuntu's 3.8.1 until it installs another.
		if b, err := os.ReadFile(m.h.Path(simRspamdFile)); err == nil {
			return strings.TrimSpace(string(b))
		}
		return simRspamdUbuntu
	}
	return aptrepo.PackageVersion(ctx, m.h, rspamdPackage)
}

// Configured reports whether mail.setup has run.
func (m *Manager) Configured() bool {
	_, err := m.loadServer()
	return err == nil
}

// UpdateRspamd installs the newest release apt offers within the
// installed version's line (Rspamd's archive pins RspamdMajor; Ubuntu's
// brings its security fixes), checks the configuration with it and
// restarts Rspamd.
func (m *Manager) UpdateRspamd(ctx context.Context, log io.Writer) error {
	if log == nil {
		log = io.Discard
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	if !m.Configured() {
		return ErrNotSetUp
	}
	args := append([]string{"install", "-y", "-q", "--only-upgrade", "--no-install-recommends", "-o", "Dpkg::Options::=--force-confdef", "-o", "Dpkg::Options::=--force-confold"}, rspamdPackage)
	if _, err := m.h.Run(ctx, host.Command{Name: "apt-get", Args: args, Env: aptrepo.Env, Stream: log}); err != nil {
		return &OperationError{err}
	}
	m.simRspamdInstalled()
	if err := m.restartRspamd(ctx, log); err != nil {
		return &OperationError{err}
	}
	return nil
}

// restartRspamd checks Rspamd's configuration with the installed
// version, restarts it and waits until its milter answers.
func (m *Manager) restartRspamd(ctx context.Context, log io.Writer) error {
	if _, err := m.h.Run(ctx, host.Command{Name: "rspamadm", Args: []string{"configtest"}, Stream: log}); err != nil {
		return fmt.Errorf("Rspamd rejected the configuration: %w", err)
	}
	if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"restart", "rspamd"}}); err != nil {
		return fmt.Errorf("rspamd did not start (see journalctl -u rspamd): %w", err)
	}
	return m.rspamdUp(ctx)
}

// The versions a dry run reports, and where it notes the installed one.
const (
	simRspamdUbuntu = "3.8.1-1ubuntu3"
	simRspamdNew    = "4.2.3-1~50bde75~noble"
	simRspamdFile   = "/var/lib/dpkg/serfex-sim-rspamd-version"
)

// simRspamdInstalled notes the version a dry run installed: RspamdMajor
// while Rspamd's archive is listed, Ubuntu's otherwise.
func (m *Manager) simRspamdInstalled() {
	if !m.sim || !m.exists("/usr/bin/rspamd") {
		return
	}
	v := simRspamdUbuntu
	if m.rspamdArchiveListed() {
		v = simRspamdNew
	}
	_ = os.MkdirAll(filepath.Dir(m.h.Path(simRspamdFile)), 0o755)
	_ = os.WriteFile(m.h.Path(simRspamdFile), []byte(v+"\n"), 0o644)
}
