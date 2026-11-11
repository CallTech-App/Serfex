package component

import (
	"bytes"
	"compress/gzip"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"

	"github.com/calltech-app/serfex/agent/internal/aptcache"
	"github.com/calltech-app/serfex/agent/internal/database"
	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/i18n"
	"github.com/calltech-app/serfex/agent/internal/pinned"
	"github.com/calltech-app/serfex/agent/internal/txn"
)

// ---- OWASP Core Rule Set ----
//
// ModSecurity's rules come from the Core Rule Set project's own release,
// checked against its SHA-256, unpacked under crsRoot: Ubuntu 24.04 ships
// CRS 3.3.5 and never updates it. Servers set up before keep Ubuntu's
// package until the administrator upgrades (Yazılım Yönetimi's "Sürümü
// yükselt"); for crsKeepFor afterwards the rules before can be gone back
// to, then Ubuntu's package is purged.

// CRS is the release Serfex installs (its minimal archive). 4.25 is CRS's long-term release
// line; 4.25.1 and newer need libmodsecurity 3.0.16 (their rule 901181
// does not load in Ubuntu 24.04's 3.0.12), so Serfex stays on 4.25.0
// until it brings that engine. A new release comes with a Serfex release.
var CRS = pinned.Release{
	Version: "4.25.0",
	URL:     "https://github.com/coreruleset/coreruleset/releases/download/v4.25.0/coreruleset-4.25.0-minimal.tar.gz",
	SHA256:  "409a0da1f4daed0719150fcee5173c351e08ffa33b5b2f8936f30968b3ad4ff0",
}

const (
	// crsRoot holds the releases: <version>/rules/*.conf.
	crsRoot = "/opt/serfex/crs"
	// crsKeptFile says what an upgrade of the rules keeps, until when.
	crsKeptFile = stateDir + "/crs-kept.json"
	// crsPackage is Ubuntu's rule set, used where the release could not
	// be downloaded and on servers set up before.
	crsPackage = "modsecurity-crs"
	crsFrom    = "github.com/coreruleset/coreruleset"
	crsKeepFor = 7 * 24 * time.Hour
	// crsMaxSize limits what an archive may unpack to.
	crsMaxSize = 64 << 20
)

func crsDir(version string) string { return crsRoot + "/" + version }

// crsRules is the rules directory main.conf includes: the release in use,
// or Ubuntu's package's.
func crsRules(s Settings) string {
	if s.CRS != "" {
		return crsDir(s.CRS) + "/rules"
	}
	return modsecCRSRule
}

// crsUnpacked reports whether a release is unpacked under crsRoot.
func (m *Manager) crsUnpacked(version string) bool {
	return m.exists(crsDir(version) + "/rules/REQUEST-901-INITIALIZATION.conf")
}

// crsVersion is the version of the rules in use: the release's ("4.25.0")
// or Ubuntu's package's ("3.3.5-2").
func (m *Manager) crsVersion(ctx context.Context) string {
	if s := m.loadSettings(); s.CRS != "" && m.crsUnpacked(s.CRS) {
		return s.CRS
	}
	return m.versions(ctx, []string{crsPackage})[crsPackage]
}

// crsOlder reports whether version a (a release or a package version) is
// older than release b.
func crsOlder(a, b string) bool {
	pa, pb := strings.Split(aptcache.Upstream(a), "."), strings.Split(b, ".")
	for i := 0; i < len(pa) || i < len(pb); i++ {
		var x, y int
		if i < len(pa) {
			x, _ = strconv.Atoi(pa[i])
		}
		if i < len(pb) {
			y, _ = strconv.Atoi(pb[i])
		}
		if x != y {
			return x < y
		}
	}
	return false
}

// crsOffer is the upgrade the rules in version can have, nil for none.
func crsOffer(version string) *database.UpgradeOffer {
	if version == "" || !crsOlder(version, CRS.Version) {
		return nil
	}
	return &database.UpgradeOffer{From: aptcache.Upstream(version), To: CRS.Version}
}

// fetchCRS downloads release r, checks its SHA-256 and unpacks it under
// crsRoot, unless it is there already. A dry run without a Download
// writes a stand-in.
func (m *Manager) fetchCRS(ctx context.Context, r pinned.Release) error {
	if m.crsUnpacked(r.Version) {
		return nil
	}
	if m.Download == nil {
		if !m.sim {
			return errors.New("downloads are not set up")
		}
		return m.simCRS(r.Version)
	}
	data, err := r.Fetch(ctx, m.Download)
	if err != nil {
		return err
	}
	return m.unpackCRS(data, r.Version)
}

// unpackCRS unpacks a release's tar.gz into crsDir(version): the files
// under its one top directory, nothing outside it, no links.
func (m *Manager) unpackCRS(data []byte, version string) error {
	tmp := m.h.Path(crsRoot + "/.unpack-" + version)
	_ = os.RemoveAll(tmp)
	if err := os.MkdirAll(tmp, 0o755); err != nil {
		return err
	}
	defer os.RemoveAll(tmp)
	gz, err := gzip.NewReader(bytes.NewReader(data))
	if err != nil {
		return err
	}
	if err := pinned.UnpackTar(gz, tmp, crsMaxSize); err != nil {
		return err
	}
	if _, err := os.Stat(filepath.Join(tmp, "rules", "REQUEST-901-INITIALIZATION.conf")); err != nil {
		return errors.New("the archive holds no CRS rules")
	}
	dst := m.h.Path(crsDir(version))
	if err := os.RemoveAll(dst); err != nil {
		return err
	}
	return os.Rename(tmp, dst)
}

// simCRS writes a stand-in release for a dry run.
func (m *Manager) simCRS(version string) error {
	dir := m.h.Path(crsDir(version) + "/rules")
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return err
	}
	for _, f := range []string{"REQUEST-901-INITIALIZATION.conf", "REQUEST-942-APPLICATION-ATTACK-SQLI.conf", "REQUEST-949-BLOCKING-EVALUATION.conf"} {
		if err := os.WriteFile(filepath.Join(dir, f), []byte("# OWASP CRS "+version+" (dry run)\n"), 0o644); err != nil {
			return err
		}
	}
	return nil
}

// pruneCRS deletes unpacked releases nothing uses or keeps.
func (m *Manager) pruneCRS(keep ...string) {
	entries, _ := os.ReadDir(m.h.Path(crsRoot))
	for _, e := range entries {
		used := false
		for _, k := range keep {
			used = used || e.Name() == k
		}
		if !used && e.IsDir() {
			_ = os.RemoveAll(m.h.Path(crsRoot + "/" + e.Name()))
		}
	}
}

// crsKept is what an upgrade of the rules keeps.
type crsKept struct {
	// From and To are the versions before and after; FromCRS is the
	// release in use before, "" for Ubuntu's package.
	From     string    `json:"from"`
	To       string    `json:"to"`
	FromCRS  string    `json:"from_crs,omitempty"`
	Upgraded time.Time `json:"upgraded"`
	Until    time.Time `json:"until"`
}

func (m *Manager) loadCRSKept() *crsKept {
	b, err := os.ReadFile(m.h.Path(crsKeptFile))
	if err != nil {
		return nil
	}
	var k crsKept
	if json.Unmarshal(b, &k) != nil || k.To == "" {
		return nil
	}
	return &k
}

func (m *Manager) saveCRSKept(k *crsKept) error {
	if k == nil {
		if err := os.Remove(m.h.Path(crsKeptFile)); err != nil && !errors.Is(err, os.ErrNotExist) {
			return err
		}
		return nil
	}
	if err := os.MkdirAll(m.h.Path(stateDir), 0o755); err != nil {
		return err
	}
	b, _ := json.MarshalIndent(k, "", "  ")
	return os.WriteFile(m.h.Path(crsKeptFile), b, 0o600)
}

// crsSeries connects the rules' upgrade to the Software page.
func crsSeries() *series {
	return &series{
		offer: func(_ context.Context, _ *Manager, version string) *database.UpgradeOffer { return crsOffer(version) },
		kept: func(m *Manager) *database.Kept {
			k := m.loadCRSKept()
			if k == nil {
				return nil
			}
			old := modsecCRSRule
			if k.FromCRS != "" {
				old = crsDir(k.FromCRS) + "/rules"
			}
			return &database.Kept{Product: "modsecurity", From: aptcache.Upstream(k.From), To: k.To, Old: old, Upgraded: k.Upgraded, Until: k.Until}
		},
		upgrade: upgradeCRS,
		revert:  revertCRS,
		removeOld: func(o *op) error {
			if o.m.loadCRSKept() == nil {
				return invalid("no upgrade is kept")
			}
			return o.m.removeOldCRS(o.ctx, o.w)
		},
		prune: func(ctx context.Context, m *Manager) {
			if k := m.loadCRSKept(); k != nil && m.now().After(k.Until) {
				_ = m.removeOldCRS(ctx, nil)
			}
		},
	}
}

// switchCRS writes the configuration for release (Ubuntu's package when
// "") and reloads Nginx, then checks a plain request still gets through.
// A failure puts the previous configuration back.
func (o *op) switchCRS(release string) (err error) {
	m := o.m
	o.t = txn.New(m.h)
	defer func() {
		if err == nil {
			return
		}
		fmt.Fprintln(o.w, "↺ "+i18n.T(o.ctx, "Önceki kurallara dönülüyor"))
		bg := context.WithoutCancel(o.ctx)
		if rbErr := o.t.Rollback(bg); rbErr != nil {
			fmt.Fprintf(o.w, "  %s\n", i18n.T(o.ctx, "geri alma: %v", rbErr))
		}
		_, _ = m.h.Run(bg, host.Command{Name: "systemctl", Args: []string{"reload", "nginx"}})
	}()
	s := m.loadSettings()
	s.CRS = release
	if err := m.writeSettings(o.t, s); err != nil {
		return err
	}
	if err := writeModsec(o, s); err != nil {
		return err
	}
	if err := o.failpoint("configure"); err != nil {
		return err
	}
	if err := reloadNginx(o); err != nil {
		return err
	}
	o.step("Sitelerin yanıt verdiği denetleniyor")
	if err := o.failpoint("check"); err != nil {
		return err
	}
	return m.wafServes(o.ctx)
}

// upgradeCRS moves ModSecurity to the CRS release, keeping the rules
// before for crsKeepFor.
func upgradeCRS(o *op) error {
	m := o.m
	from, prev := m.crsVersion(o.ctx), m.loadSettings().CRS
	if crsOffer(from) == nil {
		return invalid("ModSecurity already has the newest rules Serfex offers")
	}
	if m.loadCRSKept() != nil {
		return invalid("the previous upgrade is still kept; remove it first")
	}
	o.step("OWASP CRS %s indiriliyor ve doğrulanıyor", CRS.Version)
	err := o.failpoint("download")
	if err == nil {
		err = m.fetchCRS(o.ctx, CRS)
	}
	if err != nil {
		return fmt.Errorf("CRS %s could not be downloaded: %w", CRS.Version, err)
	}
	o.step("Kurallar CRS %s ile değiştiriliyor", CRS.Version)
	if err := o.switchCRS(CRS.Version); err != nil {
		if prev != CRS.Version {
			_ = os.RemoveAll(m.h.Path(crsDir(CRS.Version)))
		}
		return err
	}
	now := m.now().UTC()
	return m.saveCRSKept(&crsKept{From: from, To: CRS.Version, FromCRS: prev, Upgraded: now, Until: now.Add(crsKeepFor)})
}

// revertCRS goes back to the rules an upgrade kept.
func revertCRS(o *op) error {
	m := o.m
	k := m.loadCRSKept()
	if k == nil {
		return invalid("no upgrade is kept to go back from")
	}
	switch {
	case k.FromCRS == "":
		if !m.packageInstalled(o.ctx, crsPackage) {
			return &OperationError{fmt.Errorf("Ubuntu's %s package is no longer installed", crsPackage)}
		}
	case !m.crsUnpacked(k.FromCRS):
		return &OperationError{fmt.Errorf("CRS %s is no longer in %s", k.FromCRS, crsRoot)}
	}
	o.step("CRS %s kurallarına dönülüyor", aptcache.Upstream(k.From))
	if err := o.switchCRS(k.FromCRS); err != nil {
		return err
	}
	if k.To != k.FromCRS {
		_ = os.RemoveAll(m.h.Path(crsDir(k.To)))
	}
	return m.saveCRSKept(nil)
}

// removeOldCRS lets the rules an upgrade kept go: Ubuntu's package is
// purged, an older release deleted.
func (m *Manager) removeOldCRS(ctx context.Context, log io.Writer) error {
	k := m.loadCRSKept()
	if k == nil {
		return nil
	}
	if log == nil {
		log = io.Discard
	}
	if k.FromCRS == "" {
		fmt.Fprintln(log, "▸ "+i18n.T(ctx, "Ubuntu'nun CRS paketi kaldırılıyor: %s", crsPackage))
		if _, err := m.h.Run(ctx, host.Command{Name: "apt-get", Args: []string{"purge", "-y", "-q", crsPackage}, Env: aptEnv, Stream: log}); err != nil {
			return err
		}
	} else {
		fmt.Fprintln(log, "▸ "+i18n.T(ctx, "Saklanan eski sürüm siliniyor: %s", crsDir(k.FromCRS)))
		if err := os.RemoveAll(m.h.Path(crsDir(k.FromCRS))); err != nil {
			return err
		}
	}
	return m.saveCRSKept(nil)
}

// wafServes asks Nginx's default server for a missing validation file,
// as any visitor could, and fails when it is refused (403) or Nginx errs
// (CRS without its settings answers every request with 500). The
// validation path is served from disk, so ModSecurity checks the whole
// request there; the default server's other paths answer before.
func (m *Manager) wafServes(ctx context.Context) error {
	if m.sim {
		return nil
	}
	ctx, cancel := context.WithTimeout(ctx, 10*time.Second)
	defer cancel()
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, "http://127.0.0.1/.well-known/acme-challenge/serfex-check", nil)
	if err != nil {
		return err
	}
	req.Host = "serfex-check.invalid"
	req.Header.Set("User-Agent", "Mozilla/5.0 (compatible; serfex-agent)")
	req.Header.Set("Accept", "text/html")
	res, err := http.DefaultClient.Do(req)
	if err != nil {
		return fmt.Errorf("Nginx does not answer after the change: %w", err)
	}
	res.Body.Close()
	if res.StatusCode == http.StatusForbidden || res.StatusCode >= 500 {
		return fmt.Errorf("a plain request gets HTTP %d with the new rules", res.StatusCode)
	}
	return nil
}
