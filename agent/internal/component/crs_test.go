package component

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"os"
	"strings"
	"testing"
	"time"

	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/pinned"
)

// crsArchive is a release archive with these files under its top
// directory, as CRS publishes them.
func crsArchive(t *testing.T, files map[string]string) []byte {
	t.Helper()
	var buf bytes.Buffer
	gz := gzip.NewWriter(&buf)
	tw := tar.NewWriter(gz)
	if err := tw.WriteHeader(&tar.Header{Name: "coreruleset-9.9.0/", Typeflag: tar.TypeDir, Mode: 0o755}); err != nil {
		t.Fatal(err)
	}
	for name, body := range files {
		if err := tw.WriteHeader(&tar.Header{Name: name, Typeflag: tar.TypeReg, Mode: 0o644, Size: int64(len(body))}); err != nil {
			t.Fatal(err)
		}
		if _, err := tw.Write([]byte(body)); err != nil {
			t.Fatal(err)
		}
	}
	if err := tw.Close(); err != nil {
		t.Fatal(err)
	}
	if err := gz.Close(); err != nil {
		t.Fatal(err)
	}
	return buf.Bytes()
}

// withRelease makes CRS a test release whose archive m.Download serves.
func withRelease(t *testing.T, m *Manager, data []byte) {
	t.Helper()
	sum := sha256.Sum256(data)
	old := CRS
	CRS = pinned.Release{Version: "9.9.0", URL: "https://example.org/coreruleset-9.9.0-minimal.tar.gz", SHA256: hex.EncodeToString(sum[:])}
	t.Cleanup(func() { CRS = old })
	m.Download = func(_ context.Context, url string) ([]byte, error) {
		if url != CRS.URL {
			return nil, errors.New("unknown URL " + url)
		}
		return data, nil
	}
}

var goodRelease = map[string]string{
	"coreruleset-9.9.0/rules/REQUEST-901-INITIALIZATION.conf":          "# init\n",
	"coreruleset-9.9.0/rules/REQUEST-942-APPLICATION-ATTACK-SQLI.conf": "# sqli\n",
	"coreruleset-9.9.0/rules/sql-errors.data":                          "error\n",
	"coreruleset-9.9.0/crs-setup.conf.example":                         "# example\n",
}

// oldWAF is ModSecurity installed while CRS's release could not be
// downloaded: Ubuntu's 3.3.5, in block mode at paranoia level 2.
func oldWAF(t *testing.T) (*Manager, *host.Simulator) {
	t.Helper()
	m, sim, _ := newTest(t)
	m.Download = func(context.Context, string) ([]byte, error) {
		return nil, errors.New("github.com: connection timed out")
	}
	if _, err := m.Install(context.Background(), OpSpec{ID: "modsecurity", Run: "i"}); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Configure(context.Background(), ConfigureSpec{ID: "modsecurity", Settings: Settings{ModSecurityMode: "block", ModSecurityParanoia: 2}}); err != nil {
		t.Fatal(err)
	}
	return m, sim
}

func TestModSecurityFallsBackToUbuntusRules(t *testing.T) {
	m, sim := oldWAF(t)
	if chunk, _ := m.ReadRun("i", 0); !strings.Contains(chunk.Data, "indirilemedi") {
		t.Errorf("run log:\n%s", chunk.Data)
	}
	if !ran(sim, "apt-get install -y -q --no-install-recommends -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold libnginx-mod-http-modsecurity modsecurity-crs") {
		t.Errorf("Ubuntu's rules not installed: %v", sim.Commands())
	}
	if main := read(t, sim, modsecMain); !strings.Contains(main, "Include "+modsecCRSRule+"/*.conf") {
		t.Errorf("main.conf:\n%s", main)
	}
	if crs := read(t, sim, modsecCRS); !strings.Contains(crs, "tx.paranoia_level=2") || !strings.Contains(crs, "tx.crs_setup_version=335") {
		t.Errorf("crs-setup.conf:\n%s", crs)
	}
	s := status(t, m, "modsecurity")
	if s.Version != "3.3.5-2" || s.Upgrade == nil || s.Upgrade.From != "3.3.5" || s.Upgrade.To != CRS.Version || s.Kept != nil {
		t.Errorf("status = %+v upgrade %+v", s, s.Upgrade)
	}
}

func TestModSecurityRulesUpgradeAndRevert(t *testing.T) {
	ctx := context.Background()
	m, sim := oldWAF(t)
	withRelease(t, m, crsArchive(t, goodRelease))

	res, err := m.Upgrade(ctx, OpSpec{ID: "modsecurity", Run: "u"})
	if err != nil {
		t.Fatalf("%v\n%s", err, read(t, sim, runDir+"/u.log"))
	}
	if res.Status.Version != "9.9.0" || res.Status.Upgrade != nil || res.Status.Kept == nil || res.Status.Kept.From != "3.3.5" || res.Status.Kept.To != "9.9.0" {
		t.Errorf("after: %+v kept %+v", res.Status, res.Status.Kept)
	}
	if got := read(t, sim, crsDir("9.9.0")+"/rules/REQUEST-942-APPLICATION-ATTACK-SQLI.conf"); got != "# sqli\n" {
		t.Errorf("unpacked rule: %q", got)
	}
	if main := read(t, sim, modsecMain); !strings.Contains(main, "Include /opt/serfex/crs/9.9.0/rules/*.conf") || !strings.Contains(main, "SecRuleEngine On") {
		t.Errorf("main.conf:\n%s", main)
	}
	if crs := read(t, sim, modsecCRS); !strings.Contains(crs, "tx.blocking_paranoia_level=2") || !strings.Contains(crs, "tx.crs_setup_version=990") {
		t.Errorf("crs-setup.conf:\n%s", crs)
	}
	if chunk, _ := m.ReadRun("u", 0); !strings.Contains(chunk.Data, "▸ OWASP CRS 9.9.0 indiriliyor ve doğrulanıyor") || !strings.HasSuffix(chunk.Data, "✓ Yükseltildi\n") {
		t.Errorf("run log:\n%s", chunk.Data)
	}
	// Ubuntu's rules stay installed for the week; updates leave them.
	if !m.packageInstalled(ctx, crsPackage) {
		t.Error("Ubuntu's rules were removed")
	}
	if _, err := m.Upgrade(ctx, OpSpec{ID: "modsecurity", Run: "u-again"}); err == nil {
		t.Error("upgraded twice")
	}

	res, err = m.Revert(ctx, OpSpec{ID: "modsecurity", Run: "r"})
	if err != nil {
		t.Fatalf("%v\n%s", err, read(t, sim, runDir+"/r.log"))
	}
	if res.Status.Version != "3.3.5-2" || res.Status.Kept != nil || res.Status.Upgrade == nil {
		t.Errorf("after revert: %+v", res.Status)
	}
	if main := read(t, sim, modsecMain); !strings.Contains(main, "Include "+modsecCRSRule+"/*.conf") {
		t.Errorf("main.conf after revert:\n%s", main)
	}
	if exists(sim, crsDir("9.9.0")) {
		t.Error("the release stayed after the revert")
	}
	if m.loadSettings().ModSecurityMode != "block" || m.loadSettings().ModSecurityParanoia != 2 {
		t.Error("mode and paranoia level changed")
	}

	// Upgraded again, the old rules can go: Ubuntu's package is purged.
	if _, err := m.Upgrade(ctx, OpSpec{ID: "modsecurity", Run: "u2"}); err != nil {
		t.Fatal(err)
	}
	if _, err := m.RemoveOld(ctx, OpSpec{ID: "modsecurity", Run: "o"}); err != nil {
		t.Fatal(err)
	}
	if !ran(sim, "apt-get purge -y -q modsecurity-crs") {
		t.Error("Ubuntu's rules not purged")
	}
	if s := status(t, m, "modsecurity"); s.Kept != nil || s.Upgrade != nil || s.Version != "9.9.0" {
		t.Errorf("after removing the old rules: %+v", s)
	}
	if _, err := m.Revert(ctx, OpSpec{ID: "modsecurity", Run: "r2"}); err == nil {
		t.Error("reverted with nothing kept")
	}
	// Updates and repairs keep the release and leave Ubuntu's rules out.
	if _, err := m.Update(ctx, OpSpec{ID: "modsecurity", Run: "up"}); err != nil {
		t.Fatal(err)
	}
	if strings.Contains(read(t, sim, runDir+"/up.log"), "modsecurity-crs") {
		t.Errorf("update touched Ubuntu's rules:\n%s", read(t, sim, runDir+"/up.log"))
	}
	if main := read(t, sim, modsecMain); !strings.Contains(main, "Include /opt/serfex/crs/9.9.0/rules/*.conf") {
		t.Errorf("main.conf after the update:\n%s", main)
	}
}

func TestModSecurityRulesUpgradeFailureChangesNothing(t *testing.T) {
	ctx := context.Background()
	for name, c := range map[string]struct {
		data []byte
		fail string
	}{
		"wrong checksum":   {data: nil, fail: ""},
		"no rules inside":  {data: crsArchive(t, map[string]string{"coreruleset-9.9.0/README.md": "x"}), fail: ""},
		"nginx rejects it": {data: crsArchive(t, goodRelease), fail: "nginx"},
		"blocked check":    {data: crsArchive(t, goodRelease), fail: "check"},
	} {
		t.Run(name, func(t *testing.T) {
			m, sim := oldWAF(t)
			before := read(t, sim, modsecMain)
			withRelease(t, m, crsArchive(t, goodRelease))
			if c.data != nil {
				data := c.data
				m.Download = func(context.Context, string) ([]byte, error) { return data, nil }
				if name == "no rules inside" {
					sum := sha256.Sum256(data)
					CRS.SHA256 = hex.EncodeToString(sum[:])
				}
			} else {
				m.Download = func(context.Context, string) ([]byte, error) { return []byte("tampered"), nil }
			}
			switch c.fail {
			case "nginx":
				reloads := 0
				sim.Fail = func(cmd host.Command) error {
					if cmd.String() == "nginx -t" {
						reloads++
						if reloads == 1 {
							return errors.New("nginx: [emerg] Rules error")
						}
					}
					return nil
				}
			case "check":
				m.Fail = func(id, step string) error {
					if step == "check" {
						return errors.New("HTTP 403")
					}
					return nil
				}
			}
			if _, err := m.Upgrade(ctx, OpSpec{ID: "modsecurity", Run: "u"}); err == nil {
				t.Fatal("no error")
			}
			if got := read(t, sim, modsecMain); got != before {
				t.Errorf("main.conf changed:\n%s", got)
			}
			if s := m.loadSettings(); s.CRS != "" || s.ModSecurityMode != "block" {
				t.Errorf("settings = %+v", s)
			}
			if exists(sim, crsDir("9.9.0")) || m.loadCRSKept() != nil {
				t.Error("the release or a kept version stayed")
			}
			if s := status(t, m, "modsecurity"); s.Upgrade == nil || s.Version != "3.3.5-2" {
				t.Errorf("status = %+v", s)
			}
		})
	}
}

func TestCRSKeptRulesGoAfterAWeek(t *testing.T) {
	ctx := context.Background()
	m, sim := oldWAF(t)
	withRelease(t, m, crsArchive(t, goodRelease))
	if _, err := m.Upgrade(ctx, OpSpec{ID: "modsecurity", Run: "u"}); err != nil {
		t.Fatal(err)
	}
	m.now = func() time.Time { return time.Now().Add(crsKeepFor + time.Hour) }
	if _, err := m.Check(ctx); err != nil {
		t.Fatal(err)
	}
	if m.loadCRSKept() != nil || !ran(sim, "apt-get purge -y -q modsecurity-crs") {
		t.Error("the old rules stayed after their week")
	}
}

func TestUnpackCRSStaysInItsDirectory(t *testing.T) {
	m, sim, _ := newTest(t)
	for name, files := range map[string]map[string]string{
		"parent":   {"coreruleset-9.9.0/../../etc/cron.d/x": "* * * * * root id\n", "coreruleset-9.9.0/rules/REQUEST-901-INITIALIZATION.conf": "#\n"},
		"no rules": {"coreruleset-9.9.0/README.md": "x"},
	} {
		if err := m.unpackCRS(crsArchive(t, files), "9.9.0"); err == nil {
			t.Errorf("%s: unpacked", name)
		}
		if exists(sim, "/etc/cron.d/x") || exists(sim, crsDir("9.9.0")) {
			t.Errorf("%s: wrote outside or left the release", name)
		}
	}
	if err := m.unpackCRS(crsArchive(t, goodRelease), "9.9.0"); err != nil {
		t.Fatal(err)
	}
	if entries, _ := os.ReadDir(sim.Path(crsRoot)); len(entries) != 1 {
		t.Errorf("left in %s: %v", crsRoot, entries)
	}
}

func TestCRSOlder(t *testing.T) {
	for _, c := range []struct {
		a, b string
		want bool
	}{
		{"3.3.5-2", "4.25.0", true}, {"4.25.0", "4.25.0", false}, {"4.25.0", "4.25.2", true}, {"4.26.0", "4.25.2", false}, {"4.9.0", "4.25.0", true},
	} {
		if got := crsOlder(c.a, c.b); got != c.want {
			t.Errorf("crsOlder(%s, %s) = %v", c.a, c.b, got)
		}
	}
}
