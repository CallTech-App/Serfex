package dns

import (
	"context"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"

	"github.com/calltech-app/serfex/agent/internal/aptcache"
	"github.com/calltech-app/serfex/agent/internal/aptrepo"
	"github.com/calltech-app/serfex/agent/internal/txn"
)

// Series is the PowerDNS Authoritative Server release series Serfex
// installs. It comes from PowerDNS's own apt archive, pinned, because
// Ubuntu keeps one version per release: 24.04 ships 4.8.3, which PowerDNS
// stopped supporting in June 2026. Serfex decides the series, as it does
// for database servers: a new one is adopted by a Serfex release once it
// has been tested, never by PowerDNS publishing it.
const Series = "5.1"

// archive is PowerDNS's own apt archive. Its signing key is the one of the
// 5.x stable series.
var archive = aptrepo.Archive{
	Name: "serfex-powerdns", Title: "PowerDNS Authoritative Server " + Series + " for Serfex's DNS server",
	URI: "https://repo.powerdns.com/ubuntu", Host: "repo.powerdns.com", Component: "main",
	KeyURL: "https://repo.powerdns.com/FD380FBB-pub.asc", Fingerprint: "9FAAA5577E8FCF62093D036C1B0C6205FD380FBB",
	Packages: "pdns-*", Why: "PowerDNS packages come from PowerDNS's own archive, never Ubuntu's.",
}

// The archive's files.
var (
	archiveSources = archive.Sources()
	archivePin     = archive.Pin()
	archiveKeyring = archive.Keyring()
)

var aptEnv = aptrepo.Env

// suite is the archive's suite for this Ubuntu release: "noble-auth-51".
func (m *Manager) suite() (string, error) {
	codename, err := aptrepo.Codename(m.h)
	if err != nil {
		return "", err
	}
	return codename + "-auth-" + strings.ReplaceAll(Series, ".", ""), nil
}

// archiveListed reports whether apt reads PowerDNS's archive.
func (m *Manager) archiveListed() bool { return archive.Listed(m.h) }

// addArchive lists PowerDNS's archive for apt, pinned, refreshes the
// package lists and checks apt would install the Series from it. Undoing
// t takes the archive out again.
func (m *Manager) addArchive(ctx context.Context, t *txn.Tx, log io.Writer) error {
	suite, err := m.suite()
	if err != nil {
		return err
	}
	key, err := archive.FetchKey(ctx, m.h, m.Fetch, m.sim)
	if err != nil {
		return err
	}
	if err := archive.Add(ctx, m.h, t, suite, key, log); err != nil {
		return err
	}
	c := m.candidate(ctx)
	if !OfSeries(c.Version) || !archive.Provides(c) {
		return fmt.Errorf("apt offers pdns-server %q from %q, not %s from %s; is %s reachable?", c.Version, c.From, Series, archive.Host, archive.Host)
	}
	return nil
}

// candidate is the pdns-server apt would install.
func (m *Manager) candidate(ctx context.Context) aptcache.Candidate {
	return aptrepo.Candidate(ctx, m.h, "pdns-server")
}

// OfSeries reports whether a pdns-server package version belongs to the
// Series: "5.1.5-1pdns.noble" does, "4.8.3-4build3" does not.
func OfSeries(version string) bool {
	v := aptcache.Upstream(version)
	return v == Series || strings.HasPrefix(v, Series+".")
}

// PackageVersion is the installed pdns-server package version, "" when it
// is not installed.
func (m *Manager) PackageVersion(ctx context.Context) string {
	return m.packageVersion(ctx, "pdns-server")
}

func (m *Manager) packageVersion(ctx context.Context, pkg string) string {
	if m.sim {
		if !m.packagesInstalled() {
			return ""
		}
		// A dry run has Ubuntu's 4.8.3 until it installs another.
		if b, err := os.ReadFile(m.h.Path(simVersionFile)); err == nil {
			return strings.TrimSpace(string(b))
		}
		return simUbuntuVersion
	}
	return aptrepo.PackageVersion(ctx, m.h, pkg)
}

// The versions a dry run reports, and where it notes the installed one.
const (
	simUbuntuVersion = "4.8.3-4build3"
	simSeriesVersion = "5.1.5-1pdns.noble"
	simVersionFile   = "/var/lib/dpkg/serfex-sim-pdns-version"
)

// simInstalled notes the version a dry run installed: the Series while
// PowerDNS's archive is listed, Ubuntu's otherwise.
func (m *Manager) simInstalled() {
	if !m.sim {
		return
	}
	v := simUbuntuVersion
	if m.archiveListed() {
		v = simSeriesVersion
	}
	_ = os.MkdirAll(filepath.Dir(m.h.Path(simVersionFile)), 0o755)
	_ = os.WriteFile(m.h.Path(simVersionFile), []byte(v+"\n"), 0o644)
}
