//go:build realhost

// This test turns on the WAF, protected folders, blocked paths, the scan
// and its fixes, the port filter, SSH hardening and Fail2ban on a real
// server. It needs root, systemd and apt, so it only builds with
// -tags realhost:
//
//	sudo go test -tags realhost -run TestRealHost ./internal/security
//
// CI runs it on a throwaway runner with Nginx, PHP-FPM and OpenSSH.
package security

import (
	"context"
	"io"
	"net/http"
	"net/url"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"syscall"
	"testing"
	"time"

	"github.com/winchester37/servik/agent/internal/component"
	"github.com/winchester37/servik/agent/internal/database"
	"github.com/winchester37/servik/agent/internal/files"
	"github.com/winchester37/servik/agent/internal/firewall"
	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/laravel"
	"github.com/winchester37/servik/agent/internal/site"
)

const (
	rhDomain = "servik-sec.example"
	rhUser   = "serviksec"
	// attack is a classic SQL injection the Core Rule Set catches at
	// paranoia level 1 (rule 942100).
	attack = "/?id=" + "1%27%20OR%20%271%27=%271"
)

func TestRealHost(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("needs root")
	}
	ctx := context.Background()
	h := host.System{}
	bin := "/usr/local/lib/servik-test/servik-agent"
	if out, err := exec.Command("go", "build", "-o", bin, "../../cmd/servik-agent").CombinedOutput(); err != nil {
		t.Fatalf("building the agent: %v\n%s", err, out)
	}
	_ = os.Chmod(filepath.Dir(bin), 0o755)

	sites := site.NewManager(h, "www-data")
	versions, err := sites.PHPVersions()
	if err != nil || len(versions) == 0 {
		t.Fatalf("no PHP-FPM installed: %v", err)
	}
	comps := component.NewManager(h, sites, database.NewManager(h), laravel.NewManager(h))
	m := NewManager(h, files.NewManager(h, bin))
	fw := firewall.NewManager(h)
	t.Cleanup(func() {
		_, _ = fw.ApplyRules(ctx, firewall.RulesSpec{})
		_ = os.Remove(SSHConfig)
		_, _ = sites.Delete(ctx, site.DeleteSpec{Domain: rhDomain, User: rhUser, RemoveFiles: true, RemoveUser: true})
	})
	res, err := sites.Apply(ctx, site.Spec{Domain: rhDomain, User: rhUser, PHPVersion: versions[len(versions)-1]})
	if err != nil {
		t.Fatal(err)
	}
	u, err := h.LookupUser(rhUser)
	if err != nil {
		t.Fatal(err)
	}
	writeAs(t, h, rhUser, res.DocumentRoot+"/index.php", "<?php echo 'php-ok';\n")
	expect(t, "/", "", 200, "php-ok")

	// --- WAF ---
	if _, err := m.ApplyWAF(ctx, WAFSpec{Domain: rhDomain, Mode: ModeBlock}); err != ErrWAFNotInstalled {
		t.Fatalf("WAF before ModSecurity: %v", err)
	}
	if _, err := comps.Install(ctx, component.OpSpec{ID: "modsecurity", Run: "rh-modsec"}); err != nil {
		t.Fatalf("install modsecurity: %v", err)
	}
	// The server is in detection mode: the attack is logged, not refused.
	expect(t, attack, "", 200, "php-ok")
	ev := waitEvent(t, m, func(e Event) bool { return !e.Blocked && hasRule(e, 942100) })
	if ev.IP != "127.0.0.1" || !strings.Contains(ev.URI, "id=1") {
		t.Fatalf("detected event = %+v", ev)
	}

	if _, err := m.ApplyWAF(ctx, WAFSpec{Domain: rhDomain, Mode: ModeBlock}); err != nil {
		t.Fatal(err)
	}
	expect(t, attack, "", 403, "")
	expect(t, "/", "", 200, "php-ok")
	waitEvent(t, m, func(e Event) bool { return e.Blocked && e.Status == 403 && hasRule(e, 942100) && e.Score >= 5 })

	// An exclusion under a path lets the request through there only.
	writeAs(t, h, rhUser, res.DocumentRoot+"/api/index.php", "<?php echo 'api-ok';\n")
	if _, err := m.ApplyWAF(ctx, WAFSpec{Domain: rhDomain, Mode: ModeBlock, Exclusions: []Exclusion{{RuleID: 942100, Path: "/api/"}}}); err != nil {
		t.Fatal(err)
	}
	expect(t, "/api/"+attack[1:], "", 200, "api-ok")
	expect(t, attack, "", 403, "")

	if _, err := m.ApplyWAF(ctx, WAFSpec{Domain: rhDomain, Mode: ModeOff}); err != nil {
		t.Fatal(err)
	}
	expect(t, attack, "", 200, "php-ok")

	// --- Protected folder ---
	hash, err := exec.Command("openssl", "passwd", "-6", "gizli-parola").Output()
	if err != nil {
		t.Fatal(err)
	}
	writeAs(t, h, rhUser, res.DocumentRoot+"/yonetim/index.php", "<?php echo 'admin-php-ok';\n")
	if _, err := m.ApplyProtect(ctx, ProtectSpec{Domain: rhDomain, Dirs: []ProtectedDir{{Path: "/yonetim/", Realm: "Yönetim", Users: []Login{{Name: "ali", Hash: strings.TrimSpace(string(hash))}}}}}); err != nil {
		t.Fatal(err)
	}
	expect(t, "/yonetim/", "", 401, "")
	expect(t, "/yonetim/", "ali:yanlis", 401, "")
	expect(t, "/yonetim/", "ali:gizli-parola", 200, "admin-php-ok")
	expect(t, "/", "", 200, "php-ok")

	// --- Scan, fixes and blocked paths ---
	writeAs(t, h, rhUser, res.DocumentRoot+"/yedek.sql", "CREATE TABLE musteriler (id int);\n")
	writeAs(t, h, rhUser, res.DocumentRoot+"/x.php", "<?php system($_GET['c']); ?>\n")
	writeAs(t, h, rhUser, res.DocumentRoot+"/.env", "DB_PASSWORD=gizli\n")
	if err := os.Chmod(res.DocumentRoot+"/.env", 0o666); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(res.DocumentRoot+"/root-owned.txt", []byte("x"), 0o644); err != nil {
		t.Fatal(err)
	}
	scan := func() map[string]Finding {
		t.Helper()
		r, err := m.Scan(ctx, ScanSpec{Sites: []ScanSite{{Domain: rhDomain, User: rhUser, PHPVersion: versions[len(versions)-1]}}})
		if err != nil || len(r.Sites) != 1 || r.Sites[0].Error != "" {
			t.Fatalf("scan: %+v %v", r, err)
		}
		out := map[string]Finding{}
		for _, f := range r.Sites[0].Findings {
			out[f.Check+" "+f.Path] = f
		}
		return out
	}
	found := scan()
	for k, sev := range map[string]string{
		"webshell public_html/x.php":       SeverityCritical,
		"exposed public_html/yedek.sql":    SeverityHigh, // Nginx sends it
		"exposed public_html/.env":         SeverityLow,  // Nginx refuses dot files
		"writable public_html/.env":        SeverityMedium,
		"owner public_html/root-owned.txt": SeverityLow,
	} {
		if f, ok := found[k]; !ok || f.Severity != sev {
			t.Errorf("%s: %+v (want %s)", k, f, sev)
		}
	}
	if t.Failed() {
		t.Fatalf("findings: %+v", found)
	}

	if _, err := m.ApplyDeny(ctx, DenySpec{Domain: rhDomain, Paths: []string{"/yedek.sql"}}); err != nil {
		t.Fatal(err)
	}
	expect(t, "/yedek.sql", "", 403, "")
	if f := scan()["exposed public_html/yedek.sql"]; f.Severity != SeverityLow {
		t.Fatalf("blocked backup still reported as served: %+v", f)
	}

	expect(t, "/x.php?c=id", "", 200, "uid=")
	q, err := m.Fix(ctx, FixSpec{Domain: rhDomain, User: rhUser, Path: "public_html/x.php", Action: FixQuarantine})
	if err != nil || q.TrashID == "" {
		t.Fatalf("quarantine: %+v %v", q, err)
	}
	expect(t, "/x.php?c=id", "", 404, "")
	if _, err := m.Files.Do(ctx, files.Spec{User: rhUser, Domain: rhDomain, Request: files.Request{Op: "trash.restore", IDs: []string{q.TrashID}}}); err != nil {
		t.Fatal(err)
	}
	expect(t, "/x.php?c=id", "", 200, "uid=")

	p, err := m.Fix(ctx, FixSpec{Domain: rhDomain, User: rhUser, Path: "public_html/.env", Action: FixPermissions})
	if err != nil || p.Changed != 1 {
		t.Fatalf("permissions: %+v %v", p, err)
	}
	if fi, _ := os.Stat(res.DocumentRoot + "/.env"); fi.Mode().Perm() != 0o664 {
		t.Fatalf(".env mode %v", fi.Mode())
	}
	o, err := m.Fix(ctx, FixSpec{Domain: rhDomain, User: rhUser, Path: "public_html/root-owned.txt", Action: FixOwner})
	if err != nil || o.Changed != 1 {
		t.Fatalf("owner: %+v %v", o, err)
	}
	if fi, _ := os.Stat(res.DocumentRoot + "/root-owned.txt"); int(fi.Sys().(*syscall.Stat_t).Uid) != u.UID {
		t.Fatal("owner not fixed")
	}
	if _, err := m.Fix(ctx, FixSpec{Domain: rhDomain, User: rhUser, Path: "public_html/root-owned.txt", Action: FixOwner, Undo: o.Undo}); err != nil {
		t.Fatal(err)
	}
	if fi, _ := os.Stat(res.DocumentRoot + "/root-owned.txt"); fi.Sys().(*syscall.Stat_t).Uid != 0 {
		t.Fatal("owner undo did not give it back to root")
	}
	if f, err := m.ScanFiles(ctx, FileSpec{Domain: rhDomain, User: rhUser, Paths: []string{"public_html/x.php", "public_html/index.php"}}); err != nil || len(f) != 1 || f[0].Path != "public_html/x.php" {
		t.Fatalf("upload check: %+v %v", f, err)
	}

	// --- Port filter, seen from another network namespace ---
	portFilter(t, fw)

	// --- SSH hardening ---
	if st, err := m.SSH(ctx); err != nil || !st.Installed {
		t.Fatalf("ssh status: %+v %v", st, err)
	}
	if _, err := m.ApplySSH(ctx, SSHSettings{RootLogin: RootKey, PasswordAuth: true}); err != nil {
		t.Fatal(err)
	}
	out, err := exec.Command("sshd", "-T").CombinedOutput()
	if err != nil || !strings.Contains(string(out), "permitrootlogin prohibit-password") || !strings.Contains(string(out), "passwordauthentication yes") {
		t.Fatalf("sshd -T after hardening: %v\n%s", err, out)
	}

	// --- Fail2ban ---
	if _, err := comps.Install(ctx, component.OpSpec{ID: "fail2ban", Run: "rh-f2b"}); err != nil {
		t.Fatalf("install fail2ban: %v", err)
	}
	if err := comps.Fail2banBan(ctx, component.BanSpec{Jail: "sshd", Address: "192.0.2.77"}); err != nil {
		t.Fatal(err)
	}
	st, err := comps.Fail2ban(ctx)
	if err != nil || !st.Running || !st.Current || !banned(st, "sshd", "192.0.2.77") || !hasJail(st, "servik-panel") {
		t.Fatalf("fail2ban: %+v %v", st, err)
	}
	if err := comps.Fail2banUnban(ctx, component.BanSpec{Jail: "sshd", Address: "192.0.2.77"}); err != nil {
		t.Fatal(err)
	}
	// The panel logs failed logins to the journal; ten of them close the
	// panel's port to that address.
	for i := 0; i < 10; i++ {
		if out, err := exec.Command("logger", "-t", "servik-panel", "Failed panel login from 192.0.2.88 for ali@example.com").CombinedOutput(); err != nil {
			t.Fatalf("logger: %v %s", err, out)
		}
	}
	waitFor(t, "the panel jail bans 192.0.2.88", func() bool {
		st, _ := comps.Fail2ban(ctx)
		return banned(st, "servik-panel", "192.0.2.88") && !banned(st, "sshd", "192.0.2.77")
	})
}

func portFilter(t *testing.T, fw *firewall.Manager) {
	t.Helper()
	ctx := context.Background()
	for _, c := range [][]string{
		{"ip", "netns", "add", "svk"},
		{"ip", "link", "add", "svk0", "type", "veth", "peer", "name", "svk1"},
		{"ip", "link", "set", "svk1", "netns", "svk"},
		{"ip", "addr", "add", "10.99.0.1/24", "dev", "svk0"},
		{"ip", "link", "set", "svk0", "up"},
		{"ip", "netns", "exec", "svk", "ip", "addr", "add", "10.99.0.2/24", "dev", "svk1"},
		{"ip", "netns", "exec", "svk", "ip", "link", "set", "svk1", "up"},
	} {
		if out, err := exec.Command(c[0], c[1:]...).CombinedOutput(); err != nil {
			t.Fatalf("%v: %v %s", c, err, out)
		}
	}
	t.Cleanup(func() {
		_ = exec.Command("ip", "link", "del", "svk0").Run()
		_ = exec.Command("ip", "netns", "del", "svk").Run()
	})
	srv := exec.Command("python3", "-m", "http.server", "8099", "--bind", "0.0.0.0", "--directory", "/tmp")
	if err := srv.Start(); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = srv.Process.Kill(); _ = srv.Wait() })
	reach := func(port string) bool {
		err := exec.Command("ip", "netns", "exec", "svk", "curl", "-s", "-o", "/dev/null", "-m", "3", "http://10.99.0.1:"+port+"/").Run()
		return err == nil
	}
	waitFor(t, "port 8099 reachable before the filter", func() bool { return reach("8099") })

	rules := []firewall.PortRule{{ID: "web", Name: "Web", Ports: "80, 443", Protocol: firewall.TCP}}
	// Leaving out the port the admin uses is refused.
	if _, err := fw.ApplyRules(ctx, firewall.RulesSpec{Enabled: true, Rules: rules, Protect: &firewall.Protect{Address: "10.99.0.2", Port: 8099}}); err == nil {
		t.Fatal("a filter closing the admin's port was applied")
	}
	if _, err := fw.ApplyRules(ctx, firewall.RulesSpec{Enabled: true, Rules: rules, Protect: &firewall.Protect{Address: "10.99.0.2", Port: 80}}); err != nil {
		t.Fatal(err)
	}
	if !reach("80") {
		t.Fatal("port 80 closed by the filter")
	}
	if reach("8099") {
		t.Fatal("port 8099 still open with the filter on")
	}
	// A rule for one address opens it to that address.
	rules = append(rules, firewall.PortRule{ID: "dev", Name: "Geliştirme", Ports: "8099", Protocol: firewall.TCP, Sources: []string{"10.99.0.2"}})
	if _, err := fw.ApplyRules(ctx, firewall.RulesSpec{Enabled: true, Rules: rules}); err != nil {
		t.Fatal(err)
	}
	if !reach("8099") {
		t.Fatal("port 8099 closed although its rule allows 10.99.0.2")
	}
	if _, err := fw.ApplyRules(ctx, firewall.RulesSpec{Enabled: false, Rules: rules[:1]}); err != nil {
		t.Fatal(err)
	}
	if out, err := exec.Command("nft", "list", "table", "inet", firewall.PortsTable).CombinedOutput(); err == nil {
		t.Fatalf("table still there after turning the filter off:\n%s", out)
	}
}

func writeAs(t *testing.T, h host.Host, user, path, content string) {
	t.Helper()
	if _, err := h.Run(context.Background(), host.AsUser(user, host.Command{Name: "mkdir", Args: []string{"-p", filepath.Dir(path)}})); err != nil {
		t.Fatal(err)
	}
	if _, err := h.Run(context.Background(), host.AsUser(user, host.Command{Name: "tee", Args: []string{path}, Stdin: []byte(content)})); err != nil {
		t.Fatal(err)
	}
}

// expect fetches path until Nginx (reloaded asynchronously) answers code
// with a body containing want; auth is "user:password".
func expect(t *testing.T, path, auth string, code int, want string) {
	t.Helper()
	var got int
	var body string
	for i := 0; i < 60; i++ {
		req, _ := http.NewRequest("GET", "http://127.0.0.1"+path, nil)
		req.Host = rhDomain
		if auth != "" {
			name, pw, _ := strings.Cut(auth, ":")
			req.SetBasicAuth(name, pw)
		}
		if resp, err := http.DefaultClient.Do(req); err == nil {
			b, _ := io.ReadAll(resp.Body)
			resp.Body.Close()
			got, body = resp.StatusCode, string(b)
			if got == code && strings.Contains(body, want) {
				return
			}
		}
		time.Sleep(250 * time.Millisecond)
	}
	u, _ := url.PathUnescape(path)
	t.Fatalf("GET %s: %d %q, want %d with %q", u, got, body, code, want)
}

func waitEvent(t *testing.T, m *Manager, ok func(Event) bool) Event {
	t.Helper()
	var last Events
	for i := 0; i < 40; i++ {
		last, _ = m.WAFEvents(EventsSpec{Hosts: []string{rhDomain}})
		for _, e := range last.Events {
			if ok(e) {
				return e
			}
		}
		time.Sleep(250 * time.Millisecond)
	}
	t.Fatalf("no matching WAF event in %+v", last)
	return Event{}
}

func hasRule(e Event, id int) bool {
	for _, r := range e.Rules {
		if r.ID == id {
			return true
		}
	}
	return false
}

func banned(st component.Fail2banStatus, jail, ip string) bool {
	for _, j := range st.Jails {
		if j.Name != jail {
			continue
		}
		for _, b := range j.Banned {
			if b.Address == ip {
				return true
			}
		}
	}
	return false
}

func hasJail(st component.Fail2banStatus, name string) bool {
	for _, j := range st.Jails {
		if j.Name == name {
			return true
		}
	}
	return false
}

func waitFor(t *testing.T, what string, ok func() bool) {
	t.Helper()
	for i := 0; i < 60; i++ {
		if ok() {
			return
		}
		time.Sleep(500 * time.Millisecond)
	}
	t.Fatalf("timed out waiting until %s", what)
}
