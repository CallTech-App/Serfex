package database

import (
	"bufio"
	"context"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"strconv"
	"strings"
	"time"

	"github.com/winchester37/servik/agent/internal/aptcache"
	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/i18n"
	"github.com/winchester37/servik/agent/internal/txn"
)

// PostgreSQL comes from the PostgreSQL project's own archive
// (apt.postgresql.org, "PGDG"): Ubuntu ships one major version per
// release (16 on 24.04) for its whole life, the archive every supported
// one. The customers' server is the newest stable major the archive has
// when it is installed; it then stays on that major and gets its minor
// updates from Yazılım Yönetimi. Servik's own cluster (port 5433) keeps
// the version install.sh gave it.

// pgdgSources is where the archive is listed; the file name is the one
// postgresql-common's apt.postgresql.org.sh writes, so either finds the
// other's.
const pgdgSources = "/etc/apt/sources.list.d/pgdg.sources"

// pgdgKeyrings are the archive's signing key as postgresql-common ships
// it, preferred first.
var pgdgKeyrings = []string{
	"/usr/share/postgresql-common/pgdg/apt.postgresql.org.gpg",
	"/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc",
}

// pgCustomerPort is the customers' cluster's port; Servik's own cluster
// listens on 5433.
const pgCustomerPort = "5432"

var pgServerPkg = regexp.MustCompile(`^postgresql-(\d+)$`)

// pgMainVersion is the version of the customers' cluster ("main"), ""
// when there is none. Its binaries must be installed too.
func (m *Manager) pgMainVersion() string {
	confs, _ := filepath.Glob(m.h.Path("/etc/postgresql/*/main/postgresql.conf"))
	best := 0
	for _, c := range confs {
		v := filepath.Base(filepath.Dir(filepath.Dir(c)))
		n, err := strconv.Atoi(v)
		if err != nil || !m.exists("/usr/lib/postgresql/"+v+"/bin/postgres") {
			continue
		}
		best = max(best, n)
	}
	if best == 0 {
		return ""
	}
	return strconv.Itoa(best)
}

// pgTarget picks the major version a new customers' cluster gets: the
// newest one the PostgreSQL archive offers, which it adds to apt first.
// Without the archive (no network to it, an unknown release) it is the
// newest version Ubuntu's own packages offer. log gets the progress.
func (m *Manager) pgTarget(ctx context.Context, t *txn.Tx, log io.Writer) (string, error) {
	if err := m.ensurePGDG(ctx, t, log); err != nil {
		fmt.Fprintf(log, "  %v\n", err)
	}
	return m.pgNewest(ctx, nil)
}

// pgNewest is the newest PostgreSQL major version apt offers; opts point
// apt at other package lists.
func (m *Manager) pgNewest(ctx context.Context, opts []string) (string, error) {
	out, err := m.h.Run(ctx, host.Command{Name: "apt-cache", Args: append(slices.Clone(opts), "pkgnames", "postgresql-")})
	if err != nil {
		return "", fmt.Errorf("listing PostgreSQL versions: %w", err)
	}
	best := 0
	s := bufio.NewScanner(strings.NewReader(out))
	for s.Scan() {
		if mm := pgServerPkg.FindStringSubmatch(strings.TrimSpace(s.Text())); mm != nil {
			n, _ := strconv.Atoi(mm[1])
			best = max(best, n)
		}
	}
	if best == 0 {
		return "", errors.New("apt offers no PostgreSQL server package")
	}
	return strconv.Itoa(best), nil
}

// pgProbeDir holds a copy of the PostgreSQL archive's package lists, so
// the version an install would bring is known before the archive is
// listed for apt.
const pgProbeDir = "/var/lib/servik/components/pgdg-probe"

func (m *Manager) pgdgListed() bool {
	return m.exists(pgdgSources) || m.exists("/etc/apt/sources.list.d/pgdg.list")
}

// PGCandidate is what a new customers' cluster would get now: the newest
// major version and the package version apt would install. When the
// PostgreSQL archive is not listed for apt yet and probe is set, its
// lists are read into a directory of their own, leaving apt as it is;
// without probe the answer is then unknown (no version). An archive that
// cannot be read leaves Ubuntu's version, as Install does.
func (m *Manager) PGCandidate(ctx context.Context, probe bool) (aptcache.Candidate, error) {
	var opts []string
	if !m.pgdgListed() {
		if !probe {
			return aptcache.Candidate{}, nil
		}
		o, err := m.probePGDG(ctx)
		if err == nil {
			opts = o
		}
	}
	v, err := m.pgNewest(ctx, opts)
	if err != nil {
		return aptcache.Candidate{}, err
	}
	pkg := "postgresql-" + v
	out, _ := m.h.Run(ctx, host.Command{Name: "apt-cache", Args: append(slices.Clone(opts), "policy", pkg), Env: []string{"LC_ALL=C"}})
	return aptcache.Policy(out)[pkg], nil
}

// probePGDG refreshes the probe's copy of the archive's lists and returns
// the apt options that read it instead of apt's own lists.
func (m *Manager) probePGDG(ctx context.Context) ([]string, error) {
	codename := osCodename(m.h.Path("/etc/os-release"))
	keyring := m.pgdgKeyring()
	if codename == "" || keyring == "" {
		// Servik's own PostgreSQL brings postgresql-common and its key,
		// so this is a release Servik does not know.
		return nil, errors.New("the PostgreSQL archive cannot be read here")
	}
	parts := pgProbeDir + "/sources.list.d"
	if err := os.MkdirAll(m.h.Path(parts), 0o755); err != nil {
		return nil, err
	}
	if err := os.WriteFile(m.h.Path(parts+"/pgdg.sources"), []byte(pgdgSource(codename, keyring)), 0o644); err != nil {
		return nil, err
	}
	opts := []string{
		"-o", "Dir::Etc::SourceList=/dev/null",
		"-o", "Dir::Etc::SourceParts=" + parts,
		"-o", "Dir::State::Lists=" + pgProbeDir + "/lists",
		"-o", "Dir::Cache::pkgcache=", "-o", "Dir::Cache::srcpkgcache=",
	}
	ctx, cancel := context.WithTimeout(ctx, 2*time.Minute)
	defer cancel()
	if _, err := m.h.Run(ctx, host.Command{Name: "apt-get", Args: append(slices.Clone(opts), "update", "-q", "--error-on=any"), Env: aptEnv}); err != nil {
		return nil, err
	}
	return opts, nil
}

func (m *Manager) pgdgKeyring() string {
	for _, k := range pgdgKeyrings {
		if m.exists(k) {
			return k
		}
	}
	return ""
}

func pgdgSource(codename, keyring string) string {
	return fmt.Sprintf("# %s. Added for the customers' PostgreSQL.\nTypes: deb\nURIs: https://apt.postgresql.org/pub/repos/apt\nSuites: %s-pgdg\nComponents: main\nSigned-By: %s\n", txn.Marker, codename, keyring)
}

// ensurePGDG lists the PostgreSQL archive for apt when it is not listed
// yet, and refreshes the package lists. A failure leaves apt as it was.
func (m *Manager) ensurePGDG(ctx context.Context, t *txn.Tx, log io.Writer) error {
	if m.pgdgListed() {
		return nil
	}
	codename := osCodename(m.h.Path("/etc/os-release"))
	if codename == "" {
		return errors.New("the PostgreSQL archive is skipped: the release's codename is unknown")
	}
	keyring := m.pgdgKeyring()
	if keyring == "" {
		// postgresql-common carries the archive's key.
		if err := installPackages(ctx, m.h, []string{"postgresql-common"}, "", log); err != nil {
			return err
		}
		keyring = m.pgdgKeyring()
	}
	if keyring == "" {
		return errors.New("the PostgreSQL archive is skipped: postgresql-common has no key for it")
	}
	fmt.Fprintln(log, "▸ "+i18n.T(ctx, "PostgreSQL paket deposu ekleniyor (apt.postgresql.org)"))
	src := pgdgSource(codename, keyring)
	if err := os.MkdirAll(m.h.Path(filepath.Dir(pgdgSources)), 0o755); err != nil {
		return err
	}
	if err := t.WriteFile(pgdgSources, []byte(src)); err != nil {
		return err
	}
	update := host.Command{Name: "apt-get", Args: []string{"update", "-q"}, Env: aptEnv, Stream: log}
	t.OnUndo(func(ctx context.Context) error {
		_, err := m.h.Run(ctx, update)
		return err
	})
	// apt-get update only warns about a list it could not fetch;
	// --error-on=any makes that a failure.
	strict := update
	strict.Args = []string{"update", "-q", "--error-on=any"}
	if _, err := m.h.Run(ctx, strict); err != nil {
		// Unreachable or refused: Ubuntu's own packages serve instead.
		_ = os.Remove(m.h.Path(pgdgSources))
		_, _ = m.h.Run(ctx, update)
		return fmt.Errorf("the PostgreSQL archive could not be read, Ubuntu's packages are used: %w", err)
	}
	return nil
}

// osCodename reads VERSION_CODENAME from os-release(5).
func osCodename(path string) string {
	b, err := os.ReadFile(path)
	if err != nil {
		return ""
	}
	for _, line := range strings.Split(string(b), "\n") {
		if v, ok := strings.CutPrefix(line, "VERSION_CODENAME="); ok {
			return strings.Trim(strings.TrimSpace(v), `"'`)
		}
	}
	return ""
}
