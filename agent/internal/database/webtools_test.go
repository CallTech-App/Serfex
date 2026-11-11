package database

import (
	"archive/tar"
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"testing"

	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/pinned"
)

// toolReleases pins test releases of phpMyAdmin (a .tar.xz made with the
// real xz) and Adminer, served by the Download it sets on m. It counts
// the downloads; fail makes them fail.
type toolReleases struct {
	downloads int
	fail      error
	files     map[string][]byte
}

func withToolReleases(t *testing.T, m *Manager) *toolReleases {
	t.Helper()
	if _, err := exec.LookPath("xz"); err != nil {
		t.Skip("xz is not installed")
	}
	var buf bytes.Buffer
	tw := tar.NewWriter(&buf)
	for name, body := range map[string]string{
		"phpMyAdmin-9.9.0-all-languages/index.php":                     "<?php // phpMyAdmin 9.9.0\n",
		"phpMyAdmin-9.9.0-all-languages/vendor/autoload.php":           "<?php\n",
		"phpMyAdmin-9.9.0-all-languages/libraries/classes/Version.php": "<?php\n",
		"phpMyAdmin-9.9.0-all-languages/setup/index.php":               "<?php // the setup wizard\n",
		"phpMyAdmin-9.9.0-all-languages/examples/signon.php":           "<?php // an example\n",
		"phpMyAdmin-9.9.0-all-languages/config.sample.inc.php":         "<?php\n",
	} {
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
	cmd := exec.Command("xz", "-c")
	cmd.Stdin = &buf
	pma, err := cmd.Output()
	if err != nil {
		t.Fatal(err)
	}
	adminer := []byte("<?php // Adminer 9.9.0\n")
	r := &toolReleases{files: map[string][]byte{
		"https://example.org/phpMyAdmin-9.9.0-all-languages.tar.xz": pma,
		"https://example.org/adminer-9.9.0.php":                     adminer,
	}}
	sum := func(b []byte) string { s := sha256.Sum256(b); return hex.EncodeToString(s[:]) }
	oldPMA, oldAdminer := PhpMyAdminRelease, AdminerRelease
	t.Cleanup(func() { PhpMyAdminRelease, AdminerRelease = oldPMA, oldAdminer })
	PhpMyAdminRelease = pinned.Release{Version: "9.9.0", URL: "https://example.org/phpMyAdmin-9.9.0-all-languages.tar.xz", SHA256: sum(pma)}
	AdminerRelease = pinned.Release{Version: "9.9.0", URL: "https://example.org/adminer-9.9.0.php", SHA256: sum(adminer)}
	m.Download = func(_ context.Context, url string) ([]byte, error) {
		r.downloads++
		if r.fail != nil {
			return nil, r.fail
		}
		b, ok := r.files[url]
		if !ok {
			return nil, errors.New("404 " + url)
		}
		return b, nil
	}
	return r
}

// bothServers installs MySQL and PostgreSQL.
func bothServers(t *testing.T, m *Manager) {
	t.Helper()
	for _, p := range []string{MySQL, PostgreSQL} {
		if _, err := m.Install(context.Background(), InstallSpec{Product: p}); err != nil {
			t.Fatal(err)
		}
	}
}

func TestToolsComeFromTheirReleases(t *testing.T) {
	m, sim := newSim(t)
	ctx := context.Background()
	rel := withToolReleases(t, m)
	var checked []webCheck
	m.checkTools = func(_ context.Context, port int, c webCheck) error {
		if port != DefaultPort {
			t.Errorf("checked port %d", port)
		}
		checked = append(checked, c)
		return nil
	}
	bothServers(t, m)
	res, err := m.SetupManager(ctx, ManagerSpec{})
	if err != nil {
		t.Fatal(err)
	}
	if res.PhpMyAdminVersion != "9.9.0" || res.AdminerVersion != "9.9.0" || res.Note != "" {
		t.Errorf("setup %+v", res)
	}
	if len(checked) != 1 || checked[0] != (webCheck{phpMyAdmin: true, adminer: true}) {
		t.Errorf("checks %+v", checked)
	}
	// Neither of Ubuntu's packages came in.
	for _, c := range sim.Commands() {
		if s := c.String(); strings.HasPrefix(s, "apt-get install") && (strings.Contains(s, "phpmyadmin") || strings.Contains(s, "adminer")) {
			t.Errorf("ran %s", s)
		}
	}
	dir := "/opt/serfex/phpmyadmin/9.9.0/phpmyadmin"
	for _, f := range []string{dir + "/index.php", dir + "/vendor/autoload.php", "/opt/serfex/adminer/9.9.0/adminer.php"} {
		if !m.exists(f) {
			t.Errorf("%s is missing", f)
		}
	}
	for _, f := range []string{dir + "/setup", dir + "/examples", "/opt/serfex/phpmyadmin/.unpack-9.9.0"} {
		if m.exists(f) {
			t.Errorf("%s was kept", f)
		}
	}
	conf := dir + "/config.inc.php"
	if fi, err := os.Stat(sim.Path(conf)); err != nil || fi.Mode().Perm() != 0o640 {
		t.Fatalf("%s: %v %v", conf, fi, err)
	}
	pma := read(t, sim, conf)
	contains(t, "phpMyAdmin", pma, "'auth_type'] = 'signon'", "$cfg['TempDir'] = '/var/lib/serfex/dbadmin/tmp';")
	contains(t, "nginx", read(t, sim, webConf), "root /opt/serfex/phpmyadmin/9.9.0;", "location ~ ^/phpmyadmin/(libraries|templates|setup|sql|vendor|doc|locale|examples|tmp)/")
	contains(t, "php-fpm", read(t, sim, phpConf), "open_basedir] = /opt/serfex/phpmyadmin/:/opt/serfex/adminer/:")
	wrapper := read(t, sim, adminWWW+"/adminer.php")
	contains(t, "Adminer", wrapper, `class SerfexAdminer extends \Adminer\Adminer`, "include '/opt/serfex/adminer/9.9.0/adminer.php';", adminerExpired)
	if st := m.managerStatus(ctx); st.PhpMyAdminVersion != "9.9.0" || st.AdminerVersion != "9.9.0" || !st.PhpMyAdmin || !st.Adminer {
		t.Errorf("status %+v", st)
	}

	// The sign-in links open the releases.
	for _, server := range []string{MySQL, PostgreSQL} {
		if _, err := m.CreateDatabase(ctx, DatabaseSpec{Server: server, Name: "acme_shop"}); err != nil {
			t.Fatal(err)
		}
		if _, err := m.Login(ctx, LoginSpec{Server: server, Database: "acme_shop"}); err != nil {
			t.Errorf("%s: %v", server, err)
		}
	}

	// Set up again: nothing is downloaded, restarted or checked, and
	// phpMyAdmin keeps its secret.
	before, downloads := len(sim.Commands()), rel.downloads
	if _, err := m.SetupManager(ctx, ManagerSpec{}); err != nil {
		t.Fatal(err)
	}
	if rel.downloads != downloads || len(checked) != 1 || read(t, sim, conf) != pma {
		t.Errorf("repeat: %d downloads, %d checks", rel.downloads-downloads, len(checked)-1)
	}
	for _, c := range sim.Commands()[before:] {
		if strings.HasPrefix(c.String(), "systemctl restart") {
			t.Errorf("repeat ran %s", c)
		}
	}
}

// ubuntuTools sets the manager up the way servers before the releases
// were: on Ubuntu's phpMyAdmin and Adminer.
func ubuntuTools(t *testing.T, m *Manager, rel *toolReleases) string {
	t.Helper()
	rel.fail = errors.New("files.phpmyadmin.net: connection timed out")
	res, err := m.SetupManager(context.Background(), ManagerSpec{})
	if err != nil {
		t.Fatal(err)
	}
	rel.fail = nil
	if res.PhpMyAdminVersion != "" || res.AdminerVersion != "" || !strings.Contains(res.Note, "phpMyAdmin 9.9.0 could not be downloaded: files.phpmyadmin.net: connection timed out") || !strings.Contains(res.Note, "Adminer 9.9.0 could not be downloaded") {
		t.Fatalf("setup without the releases %+v", res)
	}
	if !m.exists(pmaIndex) || !m.exists(adminerIndex) {
		t.Fatal("Ubuntu's packages were not installed")
	}
	data, err := os.ReadFile(m.h.Path(pmaConf))
	if err != nil {
		t.Fatal(err)
	}
	return string(data)
}

func TestToolsMoveFromUbuntusPackages(t *testing.T) {
	m, sim := newSim(t)
	ctx := context.Background()
	rel := withToolReleases(t, m)
	m.checkTools = func(context.Context, int, webCheck) error { return nil }
	bothServers(t, m)
	ubuntuConf := ubuntuTools(t, m, rel)
	contains(t, "nginx", read(t, sim, webConf), "root /usr/share;")
	contains(t, "Adminer", read(t, sim, adminWWW+"/adminer.php"), "class SerfexAdminer extends Adminer\n", "include '/usr/share/adminer/adminer.php';")
	if st := m.managerStatus(ctx); st.PhpMyAdminVersion != "" || !st.PhpMyAdmin || st.Note == "" {
		t.Errorf("status %+v", st)
	}

	// An update keeps them there: it never downloads.
	m.Download = func(context.Context, string) ([]byte, error) {
		t.Error("SetupPanelWeb downloaded")
		return nil, errors.New("no")
	}
	installPanel(t, m)
	if _, err := m.SetupPanelWeb(ctx); err != nil {
		t.Fatal(err)
	}
	contains(t, "nginx", read(t, sim, webConf), "root /usr/share;")

	// db.manager.setup moves them, keeping phpMyAdmin's secret, and
	// purges Ubuntu's packages.
	withToolReleases(t, m)
	res, err := m.SetupManager(ctx, ManagerSpec{})
	if err != nil {
		t.Fatal(err)
	}
	if res.PhpMyAdminVersion != "9.9.0" || res.AdminerVersion != "9.9.0" || res.Note != "" {
		t.Errorf("setup %+v", res)
	}
	secret := func(conf string) string {
		_, rest, _ := strings.Cut(conf, "sodium_hex2bin('")
		s, _, _ := strings.Cut(rest, "'")
		return s
	}
	if s := secret(read(t, sim, pmaConfFile("9.9.0"))); s == "" || s != secret(ubuntuConf) {
		t.Errorf("phpMyAdmin's secret %q, before %q", s, secret(ubuntuConf))
	}
	if !sim.Ran("apt-get purge -y -q phpmyadmin adminer") || m.exists(pmaIndex) || m.exists(adminerIndex) || m.exists(pmaConf) {
		t.Error("Ubuntu's packages were not purged")
	}
	contains(t, "nginx", read(t, sim, webConf), "root /opt/serfex/phpmyadmin/9.9.0;")
	if st := m.managerStatus(ctx); st.PhpMyAdminVersion != "9.9.0" || st.AdminerVersion != "9.9.0" || st.Note != "" {
		t.Errorf("status %+v", st)
	}

	// A release whose files went missing is served from Ubuntu's
	// package again, without a download.
	m.Download = func(context.Context, string) ([]byte, error) {
		t.Error("SetupPanelWeb downloaded")
		return nil, errors.New("no")
	}
	if err := os.RemoveAll(sim.Path("/opt/serfex/adminer")); err != nil {
		t.Fatal(err)
	}
	if _, err := m.SetupPanelWeb(ctx); err != nil {
		t.Fatal(err)
	}
	if st, _ := m.loadState(); st.Tools != (tools{PhpMyAdmin: "9.9.0"}) || !m.exists(adminerIndex) {
		t.Errorf("tools %+v", st.Tools)
	}
	contains(t, "Adminer", read(t, sim, adminWWW+"/adminer.php"), "include '/usr/share/adminer/adminer.php';")
}

func TestToolsStayWhereTheReleaseDoesNotServe(t *testing.T) {
	m, sim := newSim(t)
	ctx := context.Background()
	rel := withToolReleases(t, m)
	bothServers(t, m)
	ubuntuTools(t, m, rel)
	before := map[string]string{}
	for _, f := range []string{webConf, phpConf, pmaConf, adminWWW + "/adminer.php"} {
		before[f] = read(t, sim, f)
	}

	m.checkTools = func(_ context.Context, _ int, c webCheck) error {
		if c.phpMyAdmin {
			return errors.New("phpMyAdmin answers HTTP 500")
		}
		return nil
	}
	res, err := m.SetupManager(ctx, ManagerSpec{})
	if err != nil {
		t.Fatal(err)
	}
	if res.PhpMyAdminVersion != "" || res.AdminerVersion != "" || !strings.Contains(res.Note, "serfex-web did not serve the new release (phpMyAdmin answers HTTP 500)") {
		t.Errorf("setup %+v", res)
	}
	for f, want := range before {
		if got := read(t, sim, f); got != want {
			t.Errorf("%s changed:\n%s", f, got)
		}
	}
	if sim.Ran("apt-get purge -y -q phpmyadmin adminer") || !m.exists(pmaIndex) || !m.exists(adminerIndex) {
		t.Error("Ubuntu's packages went")
	}
	if m.exists(pmaRoot+"/9.9.0") || m.exists(adminerRoot+"/9.9.0") {
		t.Error("the releases not served were kept")
	}
	if st := m.managerStatus(ctx); !strings.Contains(st.Note, "did not serve") {
		t.Errorf("status %+v", st)
	}

	// A file that is not the pinned one is never served.
	m.checkTools = nil
	rel.files[PhpMyAdminRelease.URL] = []byte("not phpMyAdmin")
	res, err = m.SetupManager(ctx, ManagerSpec{})
	if err != nil {
		t.Fatal(err)
	}
	if res.PhpMyAdminVersion != "" || res.AdminerVersion != "9.9.0" || !strings.Contains(res.Note, "has SHA-256") {
		t.Errorf("setup %+v", res)
	}
	if !sim.Ran("apt-get purge -y -q adminer") || !m.exists(pmaIndex) {
		t.Error("only Ubuntu's Adminer should go")
	}
}

// The sign-in page for Adminer (adminerPHP), run by PHP with a stand-in
// for each Adminer: a class with the signatures of Adminer 6.3.0's
// (namespaced, typed) or of Ubuntu's 4.8.1, and the bootstrap's call of
// adminer_object(). The stand-in prints what Adminer would get.
func TestAdminerSignInPage(t *testing.T) {
	php, err := exec.LookPath("php")
	if err != nil {
		t.Skip("php is not installed")
	}
	stubs := map[bool]string{
		false: `<?php
namespace Adminer;

function get_password() { return 'session-password'; }

class Adminer {
	static $instance;
	public string $error = '';
	function name(): string { return 'Adminer'; }
	function credentials(): array { return array('', $_GET["username"], get_password()); }
	function permanentLogin(bool $create = false): string { return 'key'; }
	function verifyLoginToken(): bool { return true; }
	function manifest(): array { return array('name' => 'Adminer'); }
	function loginForm(): void { echo 'form'; }
}

Adminer::$instance = (function_exists('adminer_object') ? adminer_object() : new Adminer);
$a = Adminer::$instance;
ob_start();
$a->loginForm();
echo json_encode(array('class' => get_class($a), 'name' => $a->name(), 'credentials' => $a->credentials(), 'permanent' => $a->permanentLogin(true),
	'verify' => $a->verifyLoginToken(), 'manifest' => $a->manifest(), 'form' => ob_get_clean(), 'post' => $_POST, 'get' => $_GET, 'uri' => $_SERVER['REQUEST_URI']));
`,
		true: `<?php
function get_password() { return 'session-password'; }

class Adminer {
	var $operators;
	function name() { return 'Adminer'; }
	function credentials() { return array('', $_GET["username"], get_password()); }
	function permanentLogin($create = false) { return 'key'; }
	function loginForm() { echo 'form'; }
}

$adminer = (function_exists('adminer_object') ? adminer_object() : new Adminer);
ob_start();
$adminer->loginForm();
echo json_encode(array('class' => get_class($adminer), 'name' => $adminer->name(), 'credentials' => $adminer->credentials(), 'permanent' => $adminer->permanentLogin(true),
	'verify' => null, 'manifest' => null, 'form' => ob_get_clean(), 'post' => $_POST, 'get' => $_GET, 'uri' => $_SERVER['REQUEST_URI']));
`,
	}
	type result struct {
		Class       string
		Name        string
		Credentials []string
		Permanent   string
		Verify      *bool
		Manifest    []any
		Form        string
		Post, Get   json.RawMessage
		URI         string
	}
	for ubuntu, stub := range stubs {
		dir := t.TempDir()
		tokens := filepath.Join(dir, "tokens")
		if err := os.Mkdir(tokens, 0o700); err != nil {
			t.Fatal(err)
		}
		data := webData{Marker: "m", Tokens: tokens, AdminerFile: filepath.Join(dir, "stub.php"), AdminerUbuntu: ubuntu}
		files := map[string]string{"token.php": string(render(tokenPHP, data)), "adminer.php": string(render(adminerPHP, data)), "stub.php": stub, "driver.php": `<?php
$_GET = json_decode(getenv('SERFEX_GET'), true);
$_SERVER['REQUEST_URI'] = '/adminer/?' . http_build_query($_GET);
$_SERVER['QUERY_STRING'] = http_build_query($_GET);
include __DIR__ . '/adminer.php';
`}
		for name, body := range files {
			if err := os.WriteFile(filepath.Join(dir, name), []byte(body), 0o600); err != nil {
				t.Fatal(err)
			}
		}
		token := strings.Repeat("ab", 32)
		link, _ := json.Marshal(map[string]any{"server": "postgresql", "user": "serfex-s-0a1b2c", "password": "secret", "database": "acme_shop", "expires": 4102444800})
		if err := os.WriteFile(filepath.Join(tokens, token+".json"), link, 0o600); err != nil {
			t.Fatal(err)
		}
		open := func(get map[string]string) (string, string) {
			t.Helper()
			q, _ := json.Marshal(get)
			cmd := exec.Command(php, "-n", "-d", "error_reporting=-1", "-d", "display_errors=stderr", filepath.Join(dir, "driver.php"))
			cmd.Env = append(os.Environ(), "SERFEX_GET="+string(q))
			var stdout, stderr bytes.Buffer
			cmd.Stdout, cmd.Stderr = &stdout, &stderr
			if err := cmd.Run(); err != nil {
				t.Fatalf("ubuntu=%v %v: %v\n%s%s", ubuntu, get, err, stdout.String(), stderr.String())
			}
			return stdout.String(), stderr.String()
		}
		adminer := func(get map[string]string) result {
			t.Helper()
			out, errs := open(get)
			var r result
			if errs != "" || json.Unmarshal([]byte(out), &r) != nil {
				t.Fatalf("ubuntu=%v %v:\n%s\n%s", ubuntu, get, out, errs)
			}
			if r.Class != "SerfexAdminer" || r.Name != "Serfex" || r.Permanent != "" || !strings.Contains(r.Form, adminerExpired) {
				t.Errorf("ubuntu=%v: %+v", ubuntu, r)
			}
			return r
		}

		// The one-time link signs in as its temporary user, without a
		// CSRF token, and leaves no trace of itself in the request.
		r := adminer(map[string]string{"serfex_token": token})
		var post struct{ Auth map[string]string }
		if err := json.Unmarshal(r.Post, &post); err != nil {
			t.Fatalf("ubuntu=%v: %s", ubuntu, r.Post)
		}
		if a := post.Auth; a["driver"] != "pgsql" || a["server"] != "127.0.0.1" || a["username"] != "serfex-s-0a1b2c" || a["password"] != "secret" || a["db"] != "acme_shop" || a["permanent"] != "" {
			t.Errorf("ubuntu=%v: sign-in %s", ubuntu, r.Post)
		}
		if string(r.Get) != "[]" || r.URI != "/adminer/" {
			t.Errorf("ubuntu=%v: the link stays in %s %q", ubuntu, r.Get, r.URI)
		}
		if !ubuntu && (r.Verify == nil || *r.Verify || len(r.Manifest) != 0) {
			t.Errorf("Adminer 6: verifyLoginToken %v, manifest %v", r.Verify, r.Manifest)
		}

		// Afterwards Adminer connects only to this server's PostgreSQL,
		// and a sign-in form posted from elsewhere needs a CSRF token.
		r = adminer(map[string]string{"pgsql": "db.example.org", "username": "serfex-s-0a1b2c"})
		if strings.Join(r.Credentials, " ") != "127.0.0.1 serfex-s-0a1b2c session-password" {
			t.Errorf("ubuntu=%v: credentials %v", ubuntu, r.Credentials)
		}
		if !ubuntu && (r.Verify == nil || !*r.Verify) {
			t.Error("Adminer 6: a sign-in without the link skips the CSRF token")
		}

		for _, c := range []struct {
			get  map[string]string
			page string
		}{
			{map[string]string{"serfex_token": token}, "Oturum geçersiz"}, // used
			{map[string]string{"serfex_token": "../../etc/passwd"}, "Oturum geçersiz"},
			{map[string]string{}, "Oturum yok"},
			{map[string]string{"server": "db.example.org", "username": "root"}, "Desteklenmiyor"},
			{map[string]string{"sqlite": "", "username": ""}, "Desteklenmiyor"},
		} {
			if out, errs := open(c.get); !strings.Contains(out, c.page) || errs != "" {
				t.Errorf("ubuntu=%v %v:\n%s\n%s", ubuntu, c.get, out, errs)
			}
		}
	}
}

// toolsServe against a stand-in serfex-web.
func TestToolsServeCheck(t *testing.T) {
	var pma, adminer func(w http.ResponseWriter)
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case r.URL.Path == "/phpmyadmin/index.php":
			pma(w)
		case r.URL.Path == "/adminer/" && r.URL.Query().Get("pgsql") == "127.0.0.1" && r.URL.Query().Get("username") == "serfex-check":
			adminer(w)
		default:
			http.NotFound(w, r)
		}
	}))
	defer srv.Close()
	u, _ := url.Parse(srv.URL)
	port, _ := strconv.Atoi(u.Port())
	m := &Manager{h: host.System{}}
	both := webCheck{phpMyAdmin: true, adminer: true}

	signon := func(w http.ResponseWriter) {
		w.Header().Set("Location", "/phpmyadmin/serfex-sso.php")
		w.WriteHeader(http.StatusFound)
	}
	expired := func(w http.ResponseWriter) {
		w.WriteHeader(http.StatusForbidden)
		_, _ = w.Write([]byte(`<form method='post'><p id="serfex-expired">Oturumun süresi doldu.</p></form>`))
	}
	pma, adminer = signon, expired
	if err := m.toolsServe(context.Background(), port, both); err != nil {
		t.Fatal(err)
	}
	for what, c := range map[string]struct {
		pma, adminer func(w http.ResponseWriter)
		want         string
	}{
		"phpMyAdmin's own sign-in form": {func(w http.ResponseWriter) { _, _ = w.Write([]byte("<form>Log in</form>")) }, expired, "phpMyAdmin answers HTTP 200"},
		"phpMyAdmin fails":              {func(w http.ResponseWriter) { w.WriteHeader(http.StatusInternalServerError) }, expired, "phpMyAdmin answers HTTP 500"},
		"Adminer without pgsql":         {signon, func(w http.ResponseWriter) { _, _ = w.Write([]byte("No extension")) }, "Adminer answers HTTP 200"},
		"Adminer fails":                 {signon, func(w http.ResponseWriter) { w.WriteHeader(http.StatusBadGateway) }, "Adminer answers HTTP 502"},
	} {
		pma, adminer = c.pma, c.adminer
		if err := m.toolsServe(context.Background(), port, both); err == nil || !strings.Contains(err.Error(), c.want) {
			t.Errorf("%s: %v", what, err)
		}
	}
	// Only the tools asked for are checked.
	pma, adminer = signon, func(w http.ResponseWriter) { w.WriteHeader(http.StatusBadGateway) }
	if err := m.toolsServe(context.Background(), port, webCheck{phpMyAdmin: true}); err != nil {
		t.Error(err)
	}
}
