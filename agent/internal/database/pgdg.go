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
	"strconv"
	"strings"

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
	out, err := m.h.Run(ctx, host.Command{Name: "apt-cache", Args: []string{"pkgnames", "postgresql-"}})
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

// ensurePGDG lists the PostgreSQL archive for apt when it is not listed
// yet, and refreshes the package lists. A failure leaves apt as it was.
func (m *Manager) ensurePGDG(ctx context.Context, t *txn.Tx, log io.Writer) error {
	if m.exists(pgdgSources) || m.exists("/etc/apt/sources.list.d/pgdg.list") {
		return nil
	}
	codename := osCodename(m.h.Path("/etc/os-release"))
	if codename == "" {
		return errors.New("the PostgreSQL archive is skipped: the release's codename is unknown")
	}
	keyring := ""
	for i := 0; i < 2 && keyring == ""; i++ {
		for _, k := range pgdgKeyrings {
			if m.exists(k) {
				keyring = k
				break
			}
		}
		if keyring == "" && i == 0 {
			// postgresql-common carries the archive's key.
			if err := installPackages(ctx, m.h, []string{"postgresql-common"}, "", log); err != nil {
				return err
			}
		}
	}
	if keyring == "" {
		return errors.New("the PostgreSQL archive is skipped: postgresql-common has no key for it")
	}
	fmt.Fprintln(log, "▸ "+i18n.T(ctx, "PostgreSQL paket deposu ekleniyor (apt.postgresql.org)"))
	src := fmt.Sprintf("# %s. Added for the customers' PostgreSQL.\nTypes: deb\nURIs: https://apt.postgresql.org/pub/repos/apt\nSuites: %s-pgdg\nComponents: main\nSigned-By: %s\n", txn.Marker, codename, keyring)
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
