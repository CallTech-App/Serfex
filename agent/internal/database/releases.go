package database

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"time"

	"github.com/calltech-app/serfex/agent/internal/aptcache"
	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/i18n"
	"github.com/calltech-app/serfex/agent/internal/txn"
)

// Releases are the major versions Serfex installs, newest first; the
// first is the default. Serfex decides them, as Plesk and cPanel do: a new
// major version (PostgreSQL 19, say) is added by a Serfex release once it
// has been tested, never by the vendor publishing it. Each comes from its
// vendor's own apt archive. A server installed earlier, in a version no
// longer listed (Ubuntu's MySQL 8.0, MariaDB 10.11, PostgreSQL 16), keeps
// running in it and gets its minor updates.
var Releases = map[string][]string{
	MySQL:      {"9.7", "8.4"},
	MariaDB:    {"12.3", "11.8"},
	PostgreSQL: {"18"},
}

// Release is one major version a product can be installed in, and the
// package version apt would install for it ("" until known).
type Release struct {
	Major   string `json:"major"`
	Default bool   `json:"default,omitempty"`
	Version string `json:"version,omitempty"`
	// From is the archive as host and path (aptcache.Candidate).
	From string `json:"from,omitempty"`
}

// archive is the apt archive a major version comes from.
type archive struct {
	// id names the probe's directory.
	id string
	// sources is the file under /etc/apt/sources.list.d.
	sources                string
	uri, suite, components string
	// keyring is the archive's signing key (Signed-By); keyURLs, when set,
	// are where it is downloaded from (over HTTPS, from the vendor). Keys
	// that are not there (404) are skipped, so a rotated key can be listed
	// before it is published.
	keyring string
	keyURLs []string
	// pkg is the package whose candidate is the version.
	pkg string
}

const keyringDir = "/etc/apt/keyrings"

func (m *Manager) archiveFor(product, major string) archive {
	codename := osCodename(m.h.Path("/etc/os-release"))
	switch product {
	case MySQL:
		return archive{
			id: "mysql-" + major, sources: "/etc/apt/sources.list.d/serfex-mysql.sources",
			uri: "https://repo.mysql.com/apt/ubuntu", suite: codename, components: "mysql-" + major + "-lts",
			keyring: keyringDir + "/serfex-mysql.gpg",
			keyURLs: []string{"https://repo.mysql.com/RPM-GPG-KEY-mysql-2025", "https://repo.mysql.com/RPM-GPG-KEY-mysql-2023"},
			pkg:     "mysql-server",
		}
	case MariaDB:
		return archive{
			id: "mariadb-" + major, sources: "/etc/apt/sources.list.d/serfex-mariadb.sources",
			uri: "https://dlm.mariadb.com/repo/mariadb-server/" + major + "/repo/ubuntu", suite: codename, components: "main",
			keyring: keyringDir + "/serfex-mariadb.gpg",
			keyURLs: []string{"https://supplychain.mariadb.com/mariadb-keyring-2019.gpg"},
			pkg:     "mariadb-server",
		}
	}
	return archive{
		id: "pgdg", sources: pgdgSources,
		uri: "https://apt.postgresql.org/pub/repos/apt", suite: codename + "-pgdg", components: "main",
		keyring: m.pgdgKeyring(), pkg: "postgresql-" + major,
	}
}

func (a archive) source() string {
	return fmt.Sprintf("# %s. Added for the customers' database server.\nTypes: deb\nURIs: %s\nSuites: %s\nComponents: %s\nSigned-By: %s\n", txn.Marker, a.uri, a.suite, a.components, a.keyring)
}

// listed reports whether apt reads exactly this archive.
func (m *Manager) listed(a archive) bool {
	if a.id == "pgdg" {
		return m.pgdgListed()
	}
	b, err := os.ReadFile(m.h.Path(a.sources))
	return err == nil && string(b) == a.source()
}

// pickRelease checks a requested major version; "" is the default.
func pickRelease(product, major string) (string, error) {
	list := Releases[product]
	if major == "" {
		return list[0], nil
	}
	if !slices.Contains(list, major) {
		return "", invalid("%s %s is not offered; choose one of %s", product, major, strings.Join(list, ", "))
	}
	return major, nil
}

// Choices lists the versions a product can be installed in, with what apt
// would install for each. Archives apt does not list are read only with
// probe (component.check), into directories of their own, leaving apt as
// it is; otherwise their version stays unknown.
func (m *Manager) Choices(ctx context.Context, product string, probe bool) []Release {
	var out []Release
	for i, major := range Releases[product] {
		r := Release{Major: major, Default: i == 0}
		a := m.archiveFor(product, major)
		var opts []string
		ok := m.listed(a)
		if !ok && probe {
			if o, err := m.probe(ctx, a); err == nil {
				opts, ok = o, true
			}
		}
		if ok {
			out, _ := m.h.Run(ctx, host.Command{Name: "apt-cache", Args: append(slices.Clone(opts), "policy", a.pkg), Env: []string{"LC_ALL=C"}})
			if c := aptcache.Policy(out)[a.pkg]; ofMajor(c.Version, major) {
				r.Version, r.From = c.Version, c.From
			}
		}
		out = append(out, r)
	}
	return out
}

// ofMajor reports whether a package version belongs to a major version:
// "1:11.8.3+maria~ubu2404" to 11.8, "18.1-1.pgdg24.04+2" to 18.
func ofMajor(version, major string) bool {
	v := aptcache.Upstream(version)
	return v == major || strings.HasPrefix(v, major+".")
}

// probeDir holds the probes' copies of archives' package lists.
const probeDir = "/var/lib/serfex/components/probe"

// probe refreshes a copy of an archive's package lists and returns the apt
// options that read it instead of apt's own lists.
func (m *Manager) probe(ctx context.Context, a archive) ([]string, error) {
	if a.suite == "" || (a.keyring == "" && a.keyURLs == nil) {
		// Serfex's own PostgreSQL brings postgresql-common and the
		// PostgreSQL archive's key, so this is a release Serfex does not
		// know.
		return nil, errors.New("the archive cannot be read here")
	}
	dir := probeDir + "/" + a.id
	if err := os.MkdirAll(m.h.Path(dir+"/sources.list.d"), 0o755); err != nil {
		return nil, err
	}
	if a.keyURLs != nil {
		key, err := m.fetchKeys(ctx, a.keyURLs)
		if err != nil {
			return nil, err
		}
		a.keyring = dir + "/keyring.gpg"
		if err := os.WriteFile(m.h.Path(a.keyring), key, 0o644); err != nil {
			return nil, err
		}
	}
	if err := os.WriteFile(m.h.Path(dir+"/sources.list.d/archive.sources"), []byte(a.source()), 0o644); err != nil {
		return nil, err
	}
	opts := []string{
		"-o", "Dir::Etc::SourceList=/dev/null",
		"-o", "Dir::Etc::SourceParts=" + dir + "/sources.list.d",
		"-o", "Dir::State::Lists=" + dir + "/lists",
		"-o", "Dir::Cache::pkgcache=", "-o", "Dir::Cache::srcpkgcache=",
	}
	ctx, cancel := context.WithTimeout(ctx, 2*time.Minute)
	defer cancel()
	if _, err := m.h.Run(ctx, host.Command{Name: "apt-get", Args: append(slices.Clone(opts), "update", "-q", "--error-on=any"), Env: aptEnv}); err != nil {
		return nil, err
	}
	return opts, nil
}

// addArchive lists the archive of a product's major version for apt
// (replacing another major's) and refreshes the package lists, then
// checks apt would install that major version. Undoing t takes it out
// again.
func (m *Manager) addArchive(ctx context.Context, t *txn.Tx, a archive, major string, log io.Writer) error {
	if a.id == "pgdg" {
		if err := m.ensurePGDG(ctx, t, log); err != nil {
			return err
		}
	} else if !m.listed(a) {
		if a.suite == "" {
			return errors.New("the release's codename is unknown")
		}
		fmt.Fprintln(log, "▸ "+i18n.T(ctx, "Paket deposu ekleniyor (%s)", strings.TrimPrefix(a.uri, "https://")))
		key, err := m.fetchKeys(ctx, a.keyURLs)
		if err != nil {
			return err
		}
		update := host.Command{Name: "apt-get", Args: []string{"update", "-q"}, Env: aptEnv, Stream: log}
		// Undone last: the lists are refreshed once the files are back.
		t.OnUndo(func(ctx context.Context) error {
			_, err := m.h.Run(ctx, update)
			return err
		})
		for _, d := range []string{keyringDir, filepath.Dir(a.sources)} {
			if err := t.RootDir(d, 0o755, 0, 0); err != nil {
				return err
			}
		}
		if err := t.WriteOwned(a.keyring, key, 0o644, 0, 0); err != nil {
			return err
		}
		if err := t.WriteFile(a.sources, []byte(a.source())); err != nil {
			return err
		}
		strict := update
		strict.Args = []string{"update", "-q", "--error-on=any"}
		if _, err := m.h.Run(ctx, strict); err != nil {
			return fmt.Errorf("the archive %s could not be read: %w", a.uri, err)
		}
	}
	out, _ := m.h.Run(ctx, host.Command{Name: "apt-cache", Args: []string{"policy", a.pkg}, Env: []string{"LC_ALL=C"}})
	if c := aptcache.Policy(out)[a.pkg]; !ofMajor(c.Version, major) {
		return fmt.Errorf("apt offers %s %q, not version %s; is %s reachable?", a.pkg, c.Version, major, a.uri)
	}
	return nil
}

// dropArchive takes a removed product's archive out of apt.
func (m *Manager) dropArchive(ctx context.Context, product string) {
	a := m.archiveFor(product, Releases[product][0])
	if a.id == "pgdg" || !m.exists(a.sources) {
		return
	}
	t := txn.New(m.h)
	if err := t.RemoveFile(a.sources); err == nil {
		_ = os.Remove(m.h.Path(a.keyring))
		_, _ = m.h.Run(ctx, host.Command{Name: "apt-get", Args: []string{"update", "-q"}, Env: aptEnv})
	}
}

// fetchKeys downloads an archive's signing keys into one binary keyring.
func (m *Manager) fetchKeys(ctx context.Context, urls []string) ([]byte, error) {
	fetch := m.Fetch
	if fetch == nil {
		fetch = httpFetch
		if _, sim := m.h.(*host.Simulator); sim {
			fetch = func(_ context.Context, url string) ([]byte, error) { return []byte("simulated key from " + url), nil }
		}
	}
	var ring []byte
	var errs []error
	for _, u := range urls {
		b, err := fetch(ctx, u)
		if err != nil {
			errs = append(errs, err)
			continue
		}
		if b == nil {
			continue // not published (404)
		}
		if bytes.HasPrefix(bytes.TrimSpace(b), []byte("-----BEGIN PGP")) {
			var bin bytes.Buffer
			if _, err := m.h.Run(ctx, host.Command{Name: "gpg", Args: []string{"--dearmor"}, Stdin: b, Stdout: &bin}); err != nil || bin.Len() == 0 {
				errs = append(errs, fmt.Errorf("%s is not a usable key: %v", u, err))
				continue
			}
			b = bin.Bytes()
		}
		ring = append(ring, b...)
	}
	if len(ring) == 0 {
		return nil, fmt.Errorf("no signing key could be downloaded: %w", errors.Join(errs...))
	}
	return ring, nil
}

// httpFetch downloads a small file; a missing one (404) is nil.
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
	if res.StatusCode == http.StatusNotFound {
		return nil, nil
	}
	if res.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("%s: %s", url, res.Status)
	}
	return io.ReadAll(io.LimitReader(res.Body, 1<<20))
}
