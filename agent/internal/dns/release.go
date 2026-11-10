package dns

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/calltech-app/serfex/agent/internal/aptcache"
	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/txn"
)

// Series is the PowerDNS Authoritative Server release series Serfex
// installs. It comes from PowerDNS's own apt archive, pinned, because
// Ubuntu keeps one version per release: 24.04 ships 4.8.3, which PowerDNS
// stopped supporting in June 2026. Serfex decides the series, as it does
// for database servers: a new one is adopted by a Serfex release once it
// has been tested, never by PowerDNS publishing it.
const Series = "5.1"

const (
	archiveURI     = "https://repo.powerdns.com/ubuntu"
	archiveHost    = "repo.powerdns.com"
	archiveSources = "/etc/apt/sources.list.d/serfex-powerdns.sources"
	archivePin     = "/etc/apt/preferences.d/serfex-powerdns"
	archiveKeyring = "/etc/apt/keyrings/serfex-powerdns.asc"
	// archiveKeyURL is the signing key of the 5.x stable series; its
	// fingerprint must end in archiveKeyID.
	archiveKeyURL = "https://repo.powerdns.com/FD380FBB-pub.asc"
	archiveKeyID  = "FD380FBB"
)

var aptEnv = []string{"DEBIAN_FRONTEND=noninteractive", "LC_ALL=C"}

// suite is the archive's suite for this Ubuntu release: "noble-auth-51".
func (m *Manager) suite() (string, error) {
	b, err := os.ReadFile(m.h.Path("/etc/os-release"))
	if err != nil {
		return "", err
	}
	for _, line := range strings.Split(string(b), "\n") {
		if v, ok := strings.CutPrefix(line, "VERSION_CODENAME="); ok {
			if v = strings.Trim(strings.TrimSpace(v), `"'`); v != "" {
				return v + "-auth-" + strings.ReplaceAll(Series, ".", ""), nil
			}
		}
	}
	return "", errors.New("the Ubuntu release's codename is unknown")
}

func archiveSource(suite string) []byte {
	return []byte(fmt.Sprintf("# %s. PowerDNS Authoritative Server %s for Serfex's DNS server.\nTypes: deb\nURIs: %s\nSuites: %s\nComponents: main\nSigned-By: %s\n",
		txn.Marker, Series, archiveURI, suite, archiveKeyring))
}

// archivePinning makes apt take PowerDNS's packages from PowerDNS's
// archive only, even when Ubuntu's has a higher version number.
var archivePinning = []byte("Explanation: " + txn.Marker + ".\nExplanation: PowerDNS packages come from PowerDNS's own archive, never Ubuntu's.\nPackage: pdns-*\nPin: origin " + archiveHost + "\nPin-Priority: 600\n")

// archiveListed reports whether apt reads PowerDNS's archive.
func (m *Manager) archiveListed() bool {
	_, err := os.Stat(m.h.Path(archiveSources))
	return err == nil
}

// addArchive lists PowerDNS's archive for apt, pinned, refreshes the
// package lists and checks apt would install the Series from it. Undoing
// t takes the archive out again.
func (m *Manager) addArchive(ctx context.Context, t *txn.Tx, log io.Writer) error {
	suite, err := m.suite()
	if err != nil {
		return err
	}
	key, err := m.fetchKey(ctx)
	if err != nil {
		return err
	}
	update := host.Command{Name: "apt-get", Args: []string{"update", "-q"}, Env: aptEnv, Stream: log}
	// Undone last: the lists are refreshed once the files are gone.
	t.OnUndo(func(ctx context.Context) error {
		_, err := m.h.Run(ctx, update)
		return err
	})
	for _, d := range []string{filepath.Dir(archiveKeyring), filepath.Dir(archiveSources), filepath.Dir(archivePin)} {
		if err := t.RootDir(d, 0o755, 0, 0); err != nil {
			return err
		}
	}
	if err := t.WriteOwned(archiveKeyring, key, 0o644, 0, 0); err != nil {
		return err
	}
	if err := t.WriteOwned(archivePin, archivePinning, 0o644, 0, 0); err != nil {
		return err
	}
	if err := t.WriteOwned(archiveSources, archiveSource(suite), 0o644, 0, 0); err != nil {
		return err
	}
	// apt only warns about a list it could not fetch; the candidate below
	// tells whether this one came.
	if _, err := m.h.Run(ctx, update); err != nil {
		return fmt.Errorf("apt-get update: %w", err)
	}
	c := m.candidate(ctx)
	if !OfSeries(c.Version) || !strings.HasPrefix(c.From, archiveHost) {
		return fmt.Errorf("apt offers pdns-server %q from %q, not %s from %s; is %s reachable?", c.Version, c.From, Series, archiveHost, archiveHost)
	}
	return nil
}

// candidate is the pdns-server apt would install.
func (m *Manager) candidate(ctx context.Context) aptcache.Candidate {
	out, _ := m.h.Run(ctx, host.Command{Name: "apt-cache", Args: []string{"policy", "pdns-server"}, Env: []string{"LC_ALL=C"}})
	return aptcache.Policy(out)["pdns-server"]
}

// OfSeries reports whether a pdns-server package version belongs to the
// Series: "5.1.5-1pdns.noble" does, "4.8.3-4build3" does not.
func OfSeries(version string) bool {
	v := aptcache.Upstream(version)
	return v == Series || strings.HasPrefix(v, Series+".")
}

// fetchKey downloads the archive's signing key and checks it is the one
// expected.
func (m *Manager) fetchKey(ctx context.Context) ([]byte, error) {
	if m.sim {
		return []byte("simulated key from " + archiveKeyURL + "\n"), nil
	}
	fetch := m.Fetch
	if fetch == nil {
		fetch = httpFetch
	}
	key, err := fetch(ctx, archiveKeyURL)
	if err != nil {
		return nil, err
	}
	if !bytes.HasPrefix(bytes.TrimSpace(key), []byte("-----BEGIN PGP PUBLIC KEY BLOCK-----")) {
		return nil, fmt.Errorf("%s is not a PGP public key", archiveKeyURL)
	}
	out, err := m.h.Run(ctx, host.Command{Name: "gpg", Args: []string{"--batch", "--with-colons", "--show-keys"}, Stdin: key})
	if err != nil {
		return nil, fmt.Errorf("reading %s: %w", archiveKeyURL, err)
	}
	for _, line := range strings.Split(out, "\n") {
		if f := strings.Split(line, ":"); len(f) > 9 && f[0] == "fpr" && strings.HasSuffix(strings.ToUpper(f[9]), archiveKeyID) {
			return key, nil
		}
	}
	return nil, fmt.Errorf("%s is not the PowerDNS key %s", archiveKeyURL, archiveKeyID)
}

// httpFetch downloads a small file.
func httpFetch(ctx context.Context, url string) ([]byte, error) {
	ctx, cancel := context.WithTimeout(ctx, 30*time.Second)
	defer cancel()
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return nil, err
	}
	res, err := http.DefaultClient.Do(req)
	if err != nil {
		return nil, err
	}
	defer res.Body.Close()
	if res.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("%s: %s", url, res.Status)
	}
	return io.ReadAll(io.LimitReader(res.Body, 1<<20))
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
	out, err := m.h.Run(ctx, host.Command{Name: "dpkg-query", Args: []string{"-W", "-f=${db:Status-Status} ${Version}", pkg}})
	if status, v, ok := strings.Cut(strings.TrimSpace(out), " "); err == nil && ok && status == "installed" {
		return v
	}
	return ""
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
