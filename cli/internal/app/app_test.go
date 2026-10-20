package app

import (
	"bytes"
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"runtime"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/winchester37/servik/cli/internal/api"
	"github.com/winchester37/servik/cli/internal/config"
)

// panel is a fake Servik panel: handlers by "METHOD /path" and every
// request it got.
type panel struct {
	t        *testing.T
	mu       sync.Mutex
	handlers map[string]http.HandlerFunc
	requests []*recorded
}

type recorded struct {
	Method, Path, Query, Auth, Lang, Agent string
	Body                                   map[string]any
}

func newPanel(t *testing.T) *panel {
	return &panel{t: t, handlers: map[string]http.HandlerFunc{}}
}

func (p *panel) on(route string, h http.HandlerFunc) { p.handlers[route] = h }

// json answers with status and v.
func reply(status int, v any) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(status)
		_ = json.NewEncoder(w).Encode(v)
	}
}

func (p *panel) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	rec := &recorded{Method: r.Method, Path: r.URL.Path, Query: r.URL.RawQuery, Auth: r.Header.Get("Authorization"), Lang: r.Header.Get("Accept-Language"), Agent: r.Header.Get("User-Agent")}
	data, _ := io.ReadAll(r.Body)
	_ = json.Unmarshal(data, &rec.Body)
	p.mu.Lock()
	p.requests = append(p.requests, rec)
	h := p.handlers[r.Method+" "+r.URL.Path]
	p.mu.Unlock()
	if h == nil {
		reply(404, map[string]string{"message": "no route " + r.Method + " " + r.URL.Path})(w, r)
		return
	}
	h(w, r)
}

func (p *panel) last(method, path string) *recorded {
	p.mu.Lock()
	defer p.mu.Unlock()
	for i := len(p.requests) - 1; i >= 0; i-- {
		if p.requests[i].Method == method && p.requests[i].Path == path {
			return p.requests[i]
		}
	}
	p.t.Fatalf("no %s %s", method, path)
	return nil
}

type result struct {
	out, err string
	code     int
}

// run runs servik with vars as the environment.
func run(t *testing.T, vars map[string]string, stdin string, args ...string) result {
	t.Helper()
	var out, errOut bytes.Buffer
	if _, ok := vars["SERVIK_CONFIG"]; !ok {
		t.Fatal("SERVIK_CONFIG not set")
	}
	env := &Env{
		Stdin:    strings.NewReader(stdin),
		Stdout:   &out,
		Stderr:   &errOut,
		Getenv:   func(k string) string { return vars[k] },
		Geteuid:  func() int { return 1000 },
		Hostname: func() (string, error) { return "dizustu", nil },
		ReadFile: os.ReadFile,
		Interval: time.Millisecond,
		Command: func(ctx context.Context, name string, args ...string) ([]byte, error) {
			return nil, os.ErrPermission
		},
	}
	if vars["interactive"] == "1" {
		env.Interactive = true
	}
	if vars["browser"] != "" {
		env.OpenBrowser = func(u string) error {
			vars["opened"] = u
			return nil
		}
	}
	code := Main(args, env)
	return result{out.String(), errOut.String(), code}
}

func vars(t *testing.T) map[string]string {
	return map[string]string{"SERVIK_CONFIG": filepath.Join(t.TempDir(), "config.json"), "LANG": "tr_TR.UTF-8"}
}

var adminMe = map[string]any{"data": map[string]any{
	"kind": "key", "name": "Sercan", "email": "sercan@ornek.com.tr", "account": "admin", "role": "full", "role_name": "Tam yetkili yönetici",
	"key":    map[string]any{"name": "Komut satırı: dizustu · Linux", "kind": "cli", "scope": "write", "expires_at": "2027-10-20T10:00:00+03:00"},
	"server": map[string]any{"hostname": "web1", "version": "1.4.0"},
}}

func signedIn(t *testing.T, p *panel) (map[string]string, *httptest.Server) {
	t.Helper()
	srv := httptest.NewServer(p)
	t.Cleanup(srv.Close)
	p.on("GET /api/v1/me", reply(200, adminMe))
	v := vars(t)
	if r := run(t, v, "", "login", srv.URL, "--token", "svk_panel_abc"); r.code != 0 {
		t.Fatalf("login: %d %s %s", r.code, r.out, r.err)
	}
	return v, srv
}

func TestLoginWithATokenKeepsAProfile(t *testing.T) {
	p := newPanel(t)
	v, srv := signedIn(t, p)
	cfg, err := config.Load(v["SERVIK_CONFIG"])
	if err != nil {
		t.Fatal(err)
	}
	name, prof := cfg.Get("")
	if name != "127.0.0.1" || prof.Token != "svk_panel_abc" || prof.Account != "admin" || prof.URL != srv.URL {
		t.Fatalf("profile %s %+v", name, prof)
	}
	if st, _ := os.Stat(v["SERVIK_CONFIG"]); st.Mode().Perm() != 0o600 {
		t.Fatalf("config mode %v", st.Mode().Perm())
	}
	me := p.last("GET", "/api/v1/me")
	if me.Auth != "Bearer svk_panel_abc" || me.Lang != "tr" || !strings.HasPrefix(me.Agent, "servik/") {
		t.Fatalf("headers %+v", me)
	}

	r := run(t, v, "", "whoami")
	if r.code != 0 || !strings.Contains(r.out, "Sercan <sercan@ornek.com.tr>") || !strings.Contains(r.out, "Servik 1.4.0") {
		t.Fatalf("whoami: %+v", r)
	}
	r = run(t, v, "", "whoami", "--lang", "en")
	if p.last("GET", "/api/v1/me").Lang != "en" || !strings.Contains(r.out, "Account") {
		t.Fatalf("english whoami: %+v", r)
	}
}

func TestLoginThroughTheBrowser(t *testing.T) {
	p := newPanel(t)
	srv := httptest.NewServer(p)
	defer srv.Close()
	asked := 0
	p.on("POST /api/v1/cli/login", reply(201, map[string]any{"code": "ABCD-EFGH", "device": "secret", "url": srv.URL + "/cli/onay?kod=ABCD-EFGH", "interval": 1, "expires_in": 600}))
	p.on("POST /api/v1/cli/login/token", func(w http.ResponseWriter, r *http.Request) {
		asked++
		if asked < 3 {
			reply(202, map[string]string{"status": "pending"})(w, r)
			return
		}
		reply(200, map[string]string{"status": "approved", "key": "svk_panel_new"})(w, r)
	})
	p.on("GET /api/v1/me", reply(200, adminMe))
	v := vars(t)
	v["browser"] = "1"
	r := run(t, v, "", "login", srv.URL, "--profile", "web1")
	if r.code != 0 {
		t.Fatalf("%+v", r)
	}
	if v["opened"] != srv.URL+"/cli/onay?kod=ABCD-EFGH" || !strings.Contains(r.err, "ABCD-EFGH") {
		t.Fatalf("browser %q, stderr %s", v["opened"], r.err)
	}
	if got := p.last("POST", "/api/v1/cli/login").Body["client"]; got != "dizustu · "+map[string]string{"linux": "Linux", "darwin": "macOS"}[runtime.GOOS] {
		t.Fatalf("client %v", got)
	}
	if p.last("POST", "/api/v1/cli/login/token").Body["device"] != "secret" || asked != 3 {
		t.Fatalf("collected after %d", asked)
	}
	cfg, _ := config.Load(v["SERVIK_CONFIG"])
	if cfg.Current != "web1" || cfg.Profiles["web1"].Token != "svk_panel_new" {
		t.Fatalf("%+v", cfg)
	}
	if !strings.Contains(r.out, "Sercan olarak giriş yapıldı") {
		t.Fatalf("out %s", r.out)
	}
}

func TestARefusedSignInSaysWhy(t *testing.T) {
	p := newPanel(t)
	srv := httptest.NewServer(p)
	defer srv.Close()
	p.on("POST /api/v1/cli/login", reply(201, map[string]any{"code": "ABCD-EFGH", "device": "d", "url": srv.URL, "interval": 1}))
	p.on("POST /api/v1/cli/login/token", reply(403, map[string]string{"status": "denied", "message": "Giriş tarayıcıda reddedildi."}))
	v := vars(t)
	r := run(t, v, "", "login", srv.URL, "--no-browser")
	if r.code != 1 || !strings.Contains(r.err, "Giriş tarayıcıda reddedildi.") {
		t.Fatalf("%+v", r)
	}
	if _, err := os.Stat(v["SERVIK_CONFIG"]); err == nil {
		t.Fatal("a refused sign-in saved a profile")
	}
}

func TestASelfSignedPanelIsPinnedByItsFingerprint(t *testing.T) {
	p := newPanel(t)
	p.on("GET /api/v1/me", reply(200, adminMe))
	p.on("GET /api/v1/sites", reply(200, map[string]any{"data": []any{}}))
	srv := httptest.NewTLSServer(p)
	defer srv.Close()
	fp := api.Fingerprint(srv.Certificate())
	v := vars(t)

	// Not asked: no terminal, so the fingerprint must be given.
	r := run(t, v, "", "login", srv.URL, "--token", "svk_panel_abc")
	if r.code != 2 || !strings.Contains(r.err, api.Format(fp)) || !strings.Contains(r.err, "--fingerprint "+fp) {
		t.Fatalf("%+v", r)
	}
	r = run(t, v, "", "login", srv.URL, "--token", "svk_panel_abc", "--fingerprint", strings.Repeat("ab", 32))
	if r.code != 1 || !strings.Contains(r.err, "beklenen değil") {
		t.Fatalf("%+v", r)
	}
	// Asked at a terminal.
	v["interactive"] = "1"
	r = run(t, v, "e\n", "login", srv.URL, "--token", "svk_panel_abc")
	if r.code != 0 {
		t.Fatalf("%+v", r)
	}
	cfg, _ := config.Load(v["SERVIK_CONFIG"])
	if _, prof := cfg.Get(""); prof.Fingerprint != fp {
		t.Fatalf("pinned %q, want %q", prof.Fingerprint, fp)
	}
	if r := run(t, v, "", "sites", "list"); r.code != 0 {
		t.Fatalf("%+v", r)
	}

	// A certificate other than the pinned one is refused.
	cfg.Profiles[cfg.Current].Fingerprint = strings.Repeat("cd", 32)
	_ = cfg.Save()
	r = run(t, v, "", "sites", "list")
	if r.code != 1 || !strings.Contains(r.err, "sertifikası değişti") {
		t.Fatalf("%+v", r)
	}
}

func TestCIUsesTheEnvironmentWithoutAProfile(t *testing.T) {
	p := newPanel(t)
	srv := httptest.NewServer(p)
	defer srv.Close()
	p.on("GET /api/v1/me", reply(200, map[string]any{"data": map[string]any{"kind": "token", "name": "GitHub Actions", "account": "token", "role": "deploy", "role_name": "Dağıtım", "app": "api.ornek.com.tr"}}))
	p.on("POST /api/v1/apps/api.ornek.com.tr/deploy", reply(202, map[string]any{"message": "Dağıtım sıraya alındı.", "deployment": 7, "status": "queued", "run": "deploy-7"}))
	v := vars(t)
	v["SERVIK_URL"], v["SERVIK_TOKEN"], v["LANG"] = srv.URL, "svk_tok", "C.UTF-8"
	r := run(t, v, "", "deploy", "--ref", "v1.2.0", "--no-migrate")
	if r.code != 0 || !strings.Contains(r.out, "Deployment 7") {
		t.Fatalf("%+v", r)
	}
	d := p.last("POST", "/api/v1/apps/api.ornek.com.tr/deploy")
	if d.Auth != "Bearer svk_tok" || d.Body["ref"] != "v1.2.0" || d.Body["migrate"] != false || d.Lang != "en" {
		t.Fatalf("%+v", d)
	}
}

func deployPanel(t *testing.T, final string) (*panel, map[string]string) {
	p := newPanel(t)
	v, _ := signedIn(t, p)
	p.on("POST /api/v1/apps/api.ornek.com.tr/deploy", reply(202, map[string]any{"deployment": 8, "status": "queued", "run": "deploy-8"}))
	polls := 0
	p.on("GET /api/v1/apps/api.ornek.com.tr/deployments/8", func(w http.ResponseWriter, r *http.Request) {
		polls++
		status, done := "running", false
		if polls >= 2 {
			status, done = final, true
		}
		reply(200, map[string]any{"deployment": 8, "status": status, "done": done, "commit": "abcdef123456", "error": "Sağlık kontrolü 500 döndü", "run": "deploy-8"})(w, r)
	})
	log := "$ git fetch\n$ composer install\n$ php artisan migrate --force\n"
	p.on("GET /api/v1/apps/api.ornek.com.tr/runs/deploy-8", func(w http.ResponseWriter, r *http.Request) {
		offset, _ := strconv.Atoi(r.URL.Query().Get("offset"))
		end := min(offset+12, len(log))
		reply(200, map[string]any{"data": map[string]any{"data": log[offset:end], "offset": end, "more": end < len(log), "done": polls >= 2}})(w, r)
	})
	return p, v
}

func TestDeployWaitStreamsTheLogAndFailsWithTheDeploy(t *testing.T) {
	_, v := deployPanel(t, "succeeded")
	r := run(t, v, "", "deploy", "-a", "api.ornek.com.tr", "--wait")
	if r.code != 0 || !strings.Contains(r.out, "$ composer install\n$ php artisan migrate --force\n") || !strings.Contains(r.out, "Dağıtım 8 yayında · abcdef1") {
		t.Fatalf("%+v", r)
	}
	_, v = deployPanel(t, "rolled_back")
	r = run(t, v, "", "deploy", "-a", "api.ornek.com.tr", "--wait")
	if r.code != 1 || !strings.Contains(r.err, "önceki sürüm yayında: Sağlık kontrolü 500 döndü") {
		t.Fatalf("%+v", r)
	}
}

func TestArtisanPassesItsArgumentsAndExitCode(t *testing.T) {
	p := newPanel(t)
	v, _ := signedIn(t, p)
	p.on("POST /api/v1/apps/api.ornek.com.tr/commands", reply(202, map[string]any{"data": map[string]any{"id": 3, "run": "cmd-3", "status": "queued", "done": false}}))
	p.on("GET /api/v1/apps/api.ornek.com.tr/commands/3", reply(200, map[string]any{"data": map[string]any{"id": 3, "run": "cmd-3", "status": "failed", "exit_code": 3, "done": true}}))
	p.on("GET /api/v1/apps/api.ornek.com.tr/runs/cmd-3", reply(200, map[string]any{"data": map[string]any{"data": "", "offset": 0, "more": false, "done": true}}))
	r := run(t, v, "", "artisan", "-a", "api.ornek.com.tr", "db:seed", "--class", "Kullanıcı Seeder", "--force")
	if r.code != 3 {
		t.Fatalf("%+v", r)
	}
	body := p.last("POST", "/api/v1/apps/api.ornek.com.tr/commands").Body
	if body["tool"] != "artisan" || body["command"] != "db:seed --class 'Kullanıcı Seeder' --force" {
		t.Fatalf("%v", body)
	}
}

func TestEnvMasksSecretsAndSetsKeys(t *testing.T) {
	p := newPanel(t)
	v, _ := signedIn(t, p)
	p.on("GET /api/v1/apps", reply(200, map[string]any{"data": []any{map[string]any{"domain": "api.ornek.com.tr"}}}))
	p.on("GET /api/v1/apps/api.ornek.com.tr/env", reply(200, map[string]any{"data": map[string]any{"exists": true, "values": map[string]string{"APP_NAME": "Kargo", "DB_PASSWORD": "gizli"}, "content": "APP_NAME=Kargo\nDB_PASSWORD=gizli\n", "missing": []string{}}}))
	p.on("PATCH /api/v1/apps/api.ornek.com.tr/env", reply(200, map[string]any{"message": ".env kaydedildi.", "data": map[string]any{"changed": true, "summary": "1 değişti"}}))
	r := run(t, v, "", "env")
	if r.code != 0 || !strings.Contains(r.out, "APP_NAME=Kargo") || strings.Contains(r.out, "gizli") {
		t.Fatalf("%+v", r)
	}
	if r := run(t, v, "", "env", "get", "db_password"); r.out != "gizli\n" {
		t.Fatalf("%+v", r)
	}
	r = run(t, v, "", "env", "set", "APP_DEBUG=false", "mail_from=a=b")
	if r.code != 0 || !strings.Contains(r.out, ".env kaydedildi.") {
		t.Fatalf("%+v", r)
	}
	set := p.last("PATCH", "/api/v1/apps/api.ornek.com.tr/env").Body["set"].(map[string]any)
	if set["APP_DEBUG"] != "false" || set["MAIL_FROM"] != "a=b" {
		t.Fatalf("%v", set)
	}
	if r := run(t, v, "", "env", "set", "NOVALUE"); r.code != 2 {
		t.Fatalf("%+v", r)
	}
}

func TestListsAreTablesOrTheJSONAsItCame(t *testing.T) {
	p := newPanel(t)
	v, _ := signedIn(t, p)
	sites := map[string]any{"data": []any{
		map[string]any{"domain": "kahveatolyesi.com.tr", "status": "active", "php_version": "8.4", "https": true, "disk_bytes": 1288490188, "customer": map[string]any{"name": "Kahve Atölyesi"}, "extra": "kept"},
	}}
	p.on("GET /api/v1/sites", reply(200, sites))
	r := run(t, v, "", "sites", "list")
	if r.code != 0 || !strings.Contains(r.out, "kahveatolyesi.com.tr") || !strings.Contains(r.out, "Etkin") || !strings.Contains(r.out, "1,2 GB") || !strings.Contains(r.out, "SİTE") {
		t.Fatalf("%+v", r)
	}
	r = run(t, v, "", "sites", "--json", "ls")
	var got []map[string]any
	if err := json.Unmarshal([]byte(r.out), &got); err != nil || got[0]["extra"] != "kept" {
		t.Fatalf("%v %+v", err, r)
	}
}

func TestAServiceShowsWhatStoppingItWouldAffect(t *testing.T) {
	p := newPanel(t)
	v, _ := signedIn(t, p)
	p.on("GET /api/v1/services/nginx", reply(200, map[string]any{"data": map[string]any{
		"unit": "nginx", "name": "Nginx", "state": "active", "sub_state": "running", "enabled": "enabled", "affects": "Tüm siteler (2 site) yanıt vermez.",
	}}))
	r := run(t, v, "", "services", "status", "nginx")
	for _, want := range []string{"Çalışıyor", "Açılışta başlar:  evet", "Tüm siteler (2 site) yanıt vermez."} {
		if r.code != 0 || !strings.Contains(r.out, want) {
			t.Fatalf("no %q in %+v", want, r)
		}
	}
}

func TestColoredCellsStayAligned(t *testing.T) {
	var out bytes.Buffer
	c := &Ctx{env: &Env{Stdout: &out}, color: true}
	c.table([]string{"Site", "Durum"}, [][]string{{"a.com", c.green("Etkin")}, {"uzun-ad.com.tr", "x"}})
	lines := strings.Split(strings.TrimSpace(ansi.ReplaceAllString(out.String(), "")), "\n")
	if len(lines) != 3 || strings.Index(lines[1], "Etkin") != strings.Index(lines[2], "x") {
		t.Fatalf("%q", lines)
	}
}

func TestPanelRefusalsAreShownAsTheySaid(t *testing.T) {
	p := newPanel(t)
	v, _ := signedIn(t, p)
	p.on("POST /api/v1/sites", reply(422, map[string]any{"message": "Bu alan adı zaten bir siteye ait.", "errors": map[string][]string{"domain": {"Bu alan adı zaten bir siteye ait."}}}))
	r := run(t, v, "", "sites", "create", "ornek.com.tr")
	if r.code != 1 || r.err != "servik: Bu alan adı zaten bir siteye ait.\n" {
		t.Fatalf("%+v", r)
	}
	p.on("GET /api/v1/sites", reply(401, map[string]any{"message": "Bu API anahtarı iptal edildi."}))
	r = run(t, v, "", "sites", "list")
	if r.code != 1 || !strings.Contains(r.err, "Yeniden giriş yapın: servik login http://127.0.0.1") {
		t.Fatalf("%+v", r)
	}
	if r := run(t, v, "", "sites", "list", "--bilinmeyen"); r.code != 2 || !strings.Contains(r.err, "Bilinmeyen seçenek: --bilinmeyen") {
		t.Fatalf("%+v", r)
	}
	if r := run(t, v, "", "sites", "show"); r.code != 2 {
		t.Fatalf("%+v", r)
	}
	if r := run(t, v, "", "yok"); r.code != 2 || !strings.Contains(r.err, "Bilinmeyen komut: yok") {
		t.Fatalf("%+v", r)
	}
}

func TestDeletingWithoutATerminalNeedsYes(t *testing.T) {
	p := newPanel(t)
	v, _ := signedIn(t, p)
	p.on("DELETE /api/v1/sites/ornek.com.tr", reply(202, map[string]any{"message": "ornek.com.tr siliniyor."}))
	if r := run(t, v, "", "sites", "delete", "ornek.com.tr"); r.code != 2 || !strings.Contains(r.err, "--yes") {
		t.Fatalf("%+v", r)
	}
	v["interactive"] = "1"
	if r := run(t, v, "yanlis.com\n", "sites", "delete", "ornek.com.tr"); r.code != 1 {
		t.Fatalf("%+v", r)
	}
	if r := run(t, v, "ornek.com.tr\n", "sites", "delete", "ornek.com.tr", "--keep-dns"); r.code != 0 {
		t.Fatalf("%+v", r)
	}
	if q := p.last("DELETE", "/api/v1/sites/ornek.com.tr").Query; q != "keep_dns=1" {
		t.Fatalf("query %q", q)
	}
}

func TestAMailboxPasswordIsShownOnce(t *testing.T) {
	p := newPanel(t)
	v, _ := signedIn(t, p)
	p.on("POST /api/v1/mail/domains/ornek.com.tr/mailboxes", reply(201, map[string]any{"data": map[string]any{"address": "info@ornek.com.tr", "name": "info", "password": "Gk7-pQ2x-W9mR"}}))
	r := run(t, v, "", "mail", "create", "info@ornek.com.tr", "--quota", "2048")
	if r.code != 0 || !strings.Contains(r.out, "Gk7-pQ2x-W9mR") {
		t.Fatalf("%+v", r)
	}
	if b := p.last("POST", "/api/v1/mail/domains/ornek.com.tr/mailboxes").Body; b["name"] != "info" || b["quota_mb"] != float64(2048) || b["password"] != nil {
		t.Fatalf("%v", b)
	}
	r = run(t, v, "kendi-parolam-123\n", "mail", "create", "satis@ornek.com.tr", "--password-stdin")
	if b := p.last("POST", "/api/v1/mail/domains/ornek.com.tr/mailboxes").Body; b["password"] != "kendi-parolam-123" {
		t.Fatalf("%v %+v", b, r)
	}
}

func TestCompletionListsCommandsFlagsAndSites(t *testing.T) {
	p := newPanel(t)
	v, _ := signedIn(t, p)
	p.on("GET /api/v1/sites", reply(200, map[string]any{"data": []any{map[string]any{"domain": "kahveatolyesi.com.tr"}}}))
	words := func(args ...string) []string {
		r := run(t, v, "", append([]string{"__complete", "--"}, args...)...)
		return strings.Fields(r.out)
	}
	if got := words("si"); !contains(got, "sites") || contains(got, "__complete") {
		t.Fatalf("%v", got)
	}
	if got := words("sites", ""); !contains(got, "create") || !contains(got, "list") {
		t.Fatalf("%v", got)
	}
	if got := words("sites", "show", ""); !contains(got, "kahveatolyesi.com.tr") {
		t.Fatalf("%v", got)
	}
	if got := words("deploy", "--"); !contains(got, "--wait") || !contains(got, "--json") {
		t.Fatalf("%v", got)
	}
	for _, sh := range []string{"bash", "zsh", "fish"} {
		if r := run(t, v, "", "completion", sh); r.code != 0 || !strings.Contains(r.out, "__complete --") {
			t.Fatalf("%s: %+v", sh, r)
		}
	}
}

func TestLogoutClosesTheKey(t *testing.T) {
	p := newPanel(t)
	v, _ := signedIn(t, p)
	p.on("DELETE /api/v1/me", func(w http.ResponseWriter, r *http.Request) { w.WriteHeader(204) })
	r := run(t, v, "", "logout")
	if r.code != 0 || !strings.Contains(r.out, "anahtar panelde kapatıldı") {
		t.Fatalf("%+v", r)
	}
	cfg, _ := config.Load(v["SERVIK_CONFIG"])
	if len(cfg.Profiles) != 0 {
		t.Fatalf("%+v", cfg)
	}
	if r := run(t, v, "", "sites", "list"); r.code != 1 || !strings.Contains(r.err, "servik login") {
		t.Fatalf("%+v", r)
	}
}

func TestHelpIsInTheChosenLanguage(t *testing.T) {
	v := vars(t)
	r := run(t, v, "", "--help")
	if r.code != 0 || !strings.Contains(r.out, "Kullanım:") || !strings.Contains(r.out, "Barındırma:") || strings.Contains(r.out, "__complete") {
		t.Fatalf("%+v", r)
	}
	r = run(t, v, "", "deploy", "--help", "--lang", "en")
	if r.code != 0 || !strings.Contains(r.out, "Usage: servik deploy") || !strings.Contains(r.out, "--no-migrate") {
		t.Fatalf("%+v", r)
	}
	if r := run(t, v, "", "sites"); r.code != 0 || !strings.Contains(r.out, "create") {
		t.Fatalf("%+v", r)
	}
	if r := run(t, v, "", "help", "env", "set"); r.code != 0 || !strings.Contains(r.out, "servik env set") {
		t.Fatalf("%+v", r)
	}
}

func TestShellQuote(t *testing.T) {
	cases := map[string][]string{
		"migrate --force":             {"migrate", "--force"},
		"tinker --execute 'echo 1;'":  {"tinker", "--execute", "echo 1;"},
		`about ''`:                    {"about", ""},
		`x 'it'\''s'`:                 {"x", "it's"},
		"route:list --path=api/v1/*x": nil,
	}
	for want, args := range cases {
		if args == nil {
			continue
		}
		if got := shellQuote(args); got != want {
			t.Errorf("shellQuote(%q) = %s, want %s", args, got, want)
		}
	}
}

func contains(list []string, s string) bool {
	for _, x := range list {
		if x == s {
			return true
		}
	}
	return false
}
