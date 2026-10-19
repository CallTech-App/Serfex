package mail

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/winchester37/servik/agent/internal/host"
)

const hash = "{SHA512-CRYPT}$6$abcdefgh$0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789abcdefghijklmnopqrstuv"

func newTest(t *testing.T) (*Manager, *host.Simulator) {
	t.Helper()
	sim, err := host.NewSimulator(t.TempDir(), "8.3")
	if err != nil {
		t.Fatal(err)
	}
	return NewManager(sim, "www-data"), sim
}

func setUp(t *testing.T, m *Manager) {
	t.Helper()
	if _, err := m.Setup(context.Background(), SetupSpec{Hostname: "mail.example.net", HourlyLimit: 500, Webmail: true}); err != nil {
		t.Fatal(err)
	}
}

func read(t *testing.T, sim *host.Simulator, abs string) string {
	t.Helper()
	b, err := os.ReadFile(sim.Path(abs))
	if err != nil {
		t.Fatal(err)
	}
	return string(b)
}

func contains(t *testing.T, what, got string, want ...string) {
	t.Helper()
	for _, w := range want {
		if !strings.Contains(got, w) {
			t.Errorf("%s does not contain %q:\n%s", what, w, got)
		}
	}
}

func domainSpec() DomainSpec {
	return DomainSpec{
		Domain:     "example.com",
		SpamFilter: true,
		Mailboxes: []Mailbox{
			{Name: "info", PasswordHash: hash, QuotaMB: 1024},
			{Name: "ali", PasswordHash: hash, ForwardTo: []string{"ali@gmail.com"}, KeepCopy: true},
		},
		Aliases:  []Alias{{Name: "satis", To: []string{"info@example.com", "ali@example.com"}}},
		CatchAll: []string{"info@example.com"},
	}
}

func TestDomainOperationsNeedSetup(t *testing.T) {
	m, _ := newTest(t)
	if _, err := m.ApplyDomain(context.Background(), domainSpec()); !errors.Is(err, ErrNotSetUp) {
		t.Fatalf("err = %v, want ErrNotSetUp", err)
	}
	if st := m.Status(context.Background()); st.Installed || st.Configured || st.Running {
		t.Fatalf("status before setup = %+v", st)
	}
}

func TestSetupInstallsConfiguresAndStarts(t *testing.T) {
	m, sim := newTest(t)
	ctx := context.Background()
	res, err := m.Setup(ctx, SetupSpec{Hostname: "mail.example.net", HourlyLimit: 500, Webmail: true})
	if err != nil {
		t.Fatal(err)
	}
	if !res.Installed || !res.Changed || res.Webmail != "https://mail.example.net/" {
		t.Fatalf("result = %+v", res)
	}
	for _, c := range []string{
		"debconf-set-selections",
		"apt-get install -y -q --no-install-recommends postfix dovecot-core",
		"useradd --system --user-group --no-create-home --home-dir /var/vmail",
		"useradd --system --user-group --no-create-home --home-dir /var/lib/servik/webmail",
		"postfix check", "doveconf -n", "rspamadm configtest",
		"sievec /etc/dovecot/servik/sieve/spam.sieve",
		"runuser -u servik-webmail -- sqlite3 /var/lib/servik/webmail/data/roundcube.db",
		"runuser -u servik-webmail -- env ROUNDCUBE_CONFIG_DIR=/etc/servik/webmail /usr/share/roundcube/bin/updatedb.sh",
		"php-fpm8.3 -t", "nginx -t",
		"systemctl daemon-reload",
		"systemctl enable redis-server@servik-mail rspamd dovecot postfix",
		"systemctl restart redis-server@servik-mail", "systemctl restart postfix",
		"systemctl reload php8.3-fpm", "systemctl reload nginx",
	} {
		if !sim.Ran(c) {
			t.Errorf("did not run %q", c)
		}
	}

	// The package's own files are kept aside, not overwritten.
	if _, err := os.Stat(sim.Path("/etc/postfix/master.cf.before-servik")); err != nil {
		t.Error("stock master.cf was not kept")
	}
	contains(t, "main.cf", read(t, sim, postfixMain),
		"Managed by Servik", "myhostname = mail.example.net", "virtual_transport = lmtp:unix:private/dovecot-lmtp",
		"smtpd_tls_chain_files = /etc/servik/mail/tls/privkey.pem, /etc/servik/mail/tls/fullchain.pem",
		"smtpd_milters = inet:127.0.0.1:11332", "inet_protocols = ipv4")
	contains(t, "master.cf", read(t, sim, postfixMaster), "submission inet", "submissions inet", "smtpd_tls_wrappermode=yes", "reject_sender_login_mismatch")
	contains(t, "dovecot.conf", read(t, sim, dovecotConf), "ssl = required", "first_valid_uid = ", "auth_master_user_separator = *", "quota = count:User quota")
	contains(t, "master users", read(t, sim, dovecotMasters), "servik-webmail:{SSHA512}", "allow_nets=127.0.0.1,::1")
	contains(t, "ratelimit", read(t, sim, rspamdLocal+"/ratelimit.conf"), `rate = "500 / 1h"`)
	contains(t, "redis", read(t, sim, redisConf), "port 0", "unixsocket /run/redis-servik-mail/redis-server.sock")
	contains(t, "vhost", read(t, sim, mailVhost), "server_name mail.example.net ~^autoconfig", "listen 443 ssl http2", "fastcgi_pass unix:/run/php/servik-webmail.sock", "autodiscover")
	contains(t, "roundcube", read(t, sim, webmailConfig+"/config.inc.php"), "'servik_sso'", "sqlite:////var/lib/servik/webmail/data/roundcube.db")
	contains(t, "pool", read(t, sim, webmailPool("8.3")), "env[ROUNDCUBE_CONFIG_DIR] = /etc/servik/webmail", "user = servik-webmail")
	if names, err := certNames([]byte(read(t, sim, selfCert))); err != nil || names[0] != "mail.example.net" {
		t.Fatalf("self-signed certificate names = %v, %v", names, err)
	}
	if target, err := os.Readlink(sim.Path(roundcubeRoot + "/plugins/servik_sso")); err != nil || target != webmailPlugin {
		t.Fatalf("plugin link = %q, %v", target, err)
	}

	st := m.Status(ctx)
	if !st.Installed || !st.Configured || !st.Running || st.Hostname != "mail.example.net" {
		t.Fatalf("status = %+v", st)
	}

	// Running it again changes nothing and restarts nothing.
	before := len(sim.Commands())
	res, err = m.Setup(ctx, SetupSpec{Hostname: "mail.example.net", HourlyLimit: 500, Webmail: true})
	if err != nil {
		t.Fatal(err)
	}
	if res.Changed || res.Installed {
		t.Fatalf("second setup = %+v", res)
	}
	for _, c := range sim.Commands()[before:] {
		if strings.HasPrefix(c.String(), "systemctl restart") || strings.HasPrefix(c.String(), "apt-get") {
			t.Errorf("second setup ran %q", c)
		}
	}
}

func TestSetupChangesHostnameAndCertificate(t *testing.T) {
	m, sim := newTest(t)
	setUp(t, m)
	ctx := context.Background()

	if _, err := m.Setup(ctx, SetupSpec{Hostname: "mx.example.net", Certificate: "mail"}); err == nil {
		t.Fatal("setup with a missing certificate succeeded")
	}
	// The failed attempt left the previous configuration in place.
	contains(t, "main.cf", read(t, sim, postfixMain), "myhostname = mail.example.net")

	for _, f := range []string{"/etc/servik/ssl/certs/mail/fullchain.pem", "/etc/servik/ssl/certs/mail/privkey.pem"} {
		if err := os.MkdirAll(filepath.Dir(sim.Path(f)), 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(sim.Path(f), []byte("pem"), 0o600); err != nil {
			t.Fatal(err)
		}
	}
	if _, err := m.Setup(ctx, SetupSpec{Hostname: "mx.example.net", Certificate: "mail"}); err != nil {
		t.Fatal(err)
	}
	contains(t, "main.cf", read(t, sim, postfixMain), "myhostname = mx.example.net", "/etc/servik/ssl/certs/mail/fullchain.pem")
	contains(t, "dovecot.conf", read(t, sim, dovecotConf), "ssl_cert = </etc/servik/ssl/certs/mail/fullchain.pem")
	contains(t, "ratelimit", read(t, sim, rspamdLocal+"/ratelimit.conf"), "No limit is set")
	if !m.UsesCertificate("mail") || m.UsesCertificate("example.com") {
		t.Fatal("UsesCertificate does not follow the setup")
	}
	// Webmail off: no HTTPS server, no pool, no plugin link.
	vhost := read(t, sim, mailVhost)
	if strings.Contains(vhost, "listen 443") || !strings.Contains(vhost, "return 404") {
		t.Fatalf("vhost without webmail:\n%s", vhost)
	}
	if _, err := os.Stat(sim.Path(webmailPool("8.3"))); !errors.Is(err, os.ErrNotExist) {
		t.Fatal("webmail pool still exists")
	}
	if _, err := os.Lstat(sim.Path(roundcubeRoot + "/plugins/servik_sso")); !errors.Is(err, os.ErrNotExist) {
		t.Fatal("plugin link still exists")
	}
}

func TestApplyDomainWritesMaps(t *testing.T) {
	m, sim := newTest(t)
	setUp(t, m)
	ctx := context.Background()

	res, err := m.ApplyDomain(ctx, domainSpec())
	if err != nil {
		t.Fatal(err)
	}
	if !res.Changed || res.DKIM.Name != "servik._domainkey" || !strings.HasPrefix(res.DKIM.Record, "v=DKIM1; k=rsa; p=MIIBIjAN") {
		t.Fatalf("result = %+v", res)
	}
	contains(t, "domains", read(t, sim, postfixMaps+"/domains"), "example.com OK")
	contains(t, "mailboxes", read(t, sim, postfixMaps+"/mailboxes"), "ali@example.com example.com/ali/\n", "info@example.com example.com/info/\n")
	contains(t, "aliases", read(t, sim, postfixMaps+"/aliases"),
		"info@example.com info@example.com\n",
		"ali@example.com ali@example.com, ali@gmail.com\n",
		"satis@example.com info@example.com, ali@example.com\n",
		"@example.com info@example.com\n")
	contains(t, "senders", read(t, sim, postfixMaps+"/senders"), "satis@example.com info@example.com, ali@example.com\n")
	contains(t, "users", read(t, sim, dovecotUsers),
		"info@example.com:"+hash+"::::::userdb_quota_rule=*:storage=1024M userdb_sieve_before=/etc/dovecot/servik/sieve/spam.sieve\n",
		"ali@example.com:"+hash+"::::::userdb_sieve_before=/etc/dovecot/servik/sieve/spam.sieve\n")
	contains(t, "dkim_signing", read(t, sim, rspamdLocal+"/dkim_signing.conf"), `"example.com" {`, `path = "/etc/servik/mail/dkim/example.com.key";`)
	if fi, err := os.Stat(sim.Path(dkimKey("example.com"))); err != nil || fi.Mode().Perm() != 0o640 {
		t.Fatalf("DKIM key: %v %v", fi, err)
	}
	for _, c := range []string{"systemctl reload postfix", "systemctl reload rspamd", "systemctl reload dovecot"} {
		if !sim.Ran(c) {
			t.Errorf("did not run %q", c)
		}
	}

	// The same domain again (in another order) changes nothing and keeps
	// the DKIM key.
	spec := domainSpec()
	spec.Mailboxes[0], spec.Mailboxes[1] = spec.Mailboxes[1], spec.Mailboxes[0]
	again, err := m.ApplyDomain(ctx, spec)
	if err != nil {
		t.Fatal(err)
	}
	if again.Changed || again.DKIM.Record != res.DKIM.Record {
		t.Fatalf("second apply = %+v", again)
	}
}

func TestSuspendedDomain(t *testing.T) {
	m, sim := newTest(t)
	setUp(t, m)
	ctx := context.Background()
	contains(t, "main.cf", read(t, sim, "/etc/postfix/main.cf"), "reject_unknown_recipient_domain, check_recipient_access texthash:/etc/postfix/servik/suspended, permit_mynetworks,")
	if got := read(t, sim, postfixMaps+"/suspended"); strings.Contains(got, "450") {
		t.Fatalf("suspended map before any domain:\n%s", got)
	}

	spec := domainSpec()
	spec.Suspended = true
	if _, err := m.ApplyDomain(ctx, spec); err != nil {
		t.Fatal(err)
	}
	contains(t, "suspended", read(t, sim, postfixMaps+"/suspended"), "example.com 450 4.2.1 Mailbox temporarily unavailable\n")
	// The mailboxes stay (mail kept, nothing bounces) but cannot sign in.
	contains(t, "mailboxes", read(t, sim, postfixMaps+"/mailboxes"), "info@example.com example.com/info/\n")
	contains(t, "users", read(t, sim, dovecotUsers), "info@example.com:{CRYPT}*::::::nologin=y userdb_quota_rule=*:storage=1024M")

	spec.Suspended = false
	if _, err := m.ApplyDomain(ctx, spec); err != nil {
		t.Fatal(err)
	}
	if got := read(t, sim, postfixMaps+"/suspended") + read(t, sim, dovecotUsers); strings.Contains(got, "450 4.2.1") || strings.Contains(got, "nologin") {
		t.Fatalf("still suspended:\n%s", got)
	}
}

func TestAutoreply(t *testing.T) {
	m, sim := newTest(t)
	setUp(t, m)
	ctx := context.Background()
	spec := domainSpec()
	spec.Mailboxes[0].Autoreply = &Autoreply{Subject: `İzindeyim "yine"`, Body: "27 Ekim'e kadar izindeyim.\nAcil: ali@example.com", Until: "2026-10-27"}
	if _, err := m.ApplyDomain(ctx, spec); err != nil {
		t.Fatal(err)
	}
	script := sieveScript("example.com", "info")
	contains(t, "autoreply", read(t, sim, script),
		`fileinto "Junk";`, `if currentdate :value "le" "date" "2026-10-27"`,
		`:addresses ["info@example.com", "satis@example.com"]`, `:subject "İzindeyim \"yine\""`)
	contains(t, "users", read(t, sim, dovecotUsers), "userdb_sieve_before="+script)
	if !sim.Ran("sievec " + script) {
		t.Error("autoreply was not compiled")
	}

	spec.Mailboxes[0].Autoreply = nil
	if _, err := m.ApplyDomain(ctx, spec); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(sim.Path(script)); !errors.Is(err, os.ErrNotExist) {
		t.Fatal("autoreply script was not removed")
	}
}

func TestApplyDomainValidates(t *testing.T) {
	m, _ := newTest(t)
	setUp(t, m)
	for name, change := range map[string]func(*DomainSpec){
		"bad domain":       func(s *DomainSpec) { s.Domain = "localhost" },
		"bad name":         func(s *DomainSpec) { s.Mailboxes[0].Name = "Info" },
		"plus in name":     func(s *DomainSpec) { s.Mailboxes[0].Name = "a+b" },
		"double dot":       func(s *DomainSpec) { s.Mailboxes[0].Name = "a..b" },
		"duplicate":        func(s *DomainSpec) { s.Mailboxes[1].Name = "info" },
		"alias is mailbox": func(s *DomainSpec) { s.Aliases[0].Name = "info" },
		"plain password":   func(s *DomainSpec) { s.Mailboxes[0].PasswordHash = "secret" },
		"colon in hash":    func(s *DomainSpec) { s.Mailboxes[0].PasswordHash = hash + ":x" },
		"bad forward":      func(s *DomainSpec) { s.Mailboxes[0].ForwardTo = []string{"nobody"} },
		"alias to itself":  func(s *DomainSpec) { s.Aliases[0].To = []string{"satis@example.com"} },
		"empty alias":      func(s *DomainSpec) { s.Aliases[0].To = nil },
		"bad until":        func(s *DomainSpec) { s.Mailboxes[0].Autoreply = &Autoreply{Body: "x", Until: "27.10.2026"} },
		"empty autoreply":  func(s *DomainSpec) { s.Mailboxes[0].Autoreply = &Autoreply{Body: " "} },
		"subject newline":  func(s *DomainSpec) { s.Mailboxes[0].Autoreply = &Autoreply{Subject: "a\nBcc: x", Body: "x"} },
	} {
		spec := domainSpec()
		change(&spec)
		var v *ValidationError
		if _, err := m.ApplyDomain(context.Background(), spec); !errors.As(err, &v) {
			t.Errorf("%s: err = %v, want a validation error", name, err)
		}
	}
}

func TestApplyDomainRollsBack(t *testing.T) {
	m, sim := newTest(t)
	setUp(t, m)
	ctx := context.Background()
	if _, err := m.ApplyDomain(ctx, domainSpec()); err != nil {
		t.Fatal(err)
	}
	users := read(t, sim, dovecotUsers)

	sim.Fail = func(c host.Command) error {
		if c.String() == "postfix check" {
			return errors.New("postfix: fatal: bad map")
		}
		return nil
	}
	spec := domainSpec()
	spec.Mailboxes = append(spec.Mailboxes, Mailbox{Name: "yeni", PasswordHash: hash})
	_, err := m.ApplyDomain(ctx, spec)
	var op *OperationError
	if !errors.As(err, &op) || !strings.Contains(err.Error(), "bad map") {
		t.Fatalf("err = %v", err)
	}
	if got := read(t, sim, dovecotUsers); got != users {
		t.Fatalf("users file was not restored:\n%s", got)
	}
	if strings.Contains(read(t, sim, domainFile("example.com")), "yeni") {
		t.Fatal("stored domain was not restored")
	}
}

func TestDeleteDomainAndPurge(t *testing.T) {
	m, sim := newTest(t)
	setUp(t, m)
	ctx := context.Background()
	if _, err := m.ApplyDomain(ctx, domainSpec()); err != nil {
		t.Fatal(err)
	}
	mailFile := sim.Path(VmailDir + "/example.com/info/Maildir/new/1.msg")
	if err := os.MkdirAll(filepath.Dir(mailFile), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(mailFile, []byte(strings.Repeat("x", 1234)), 0o600); err != nil {
		t.Fatal(err)
	}
	u, err := m.Usage("example.com")
	if err != nil || u.Mailboxes["info"] != 1234 || u.Mailboxes["ali"] != 0 {
		t.Fatalf("usage = %+v, %v", u, err)
	}

	// A mailbox that still exists is never purged.
	if res, err := m.PurgeMailbox(ctx, "example.com", "info"); err != nil || res.Deleted {
		t.Fatalf("purge of an existing mailbox = %+v, %v", res, err)
	}

	res, err := m.DeleteDomain(ctx, "example.com", false)
	if err != nil || !res.Deleted || res.FilesRemoved {
		t.Fatalf("delete = %+v, %v", res, err)
	}
	if strings.Contains(read(t, sim, postfixMaps+"/domains"), "example.com") || strings.Contains(read(t, sim, dovecotUsers), "example.com") {
		t.Fatal("maps still list the domain")
	}
	if _, err := os.Stat(mailFile); err != nil {
		t.Fatal("mail was removed without remove_files")
	}

	if res, err := m.PurgeMailbox(ctx, "example.com", "info"); err != nil || !res.Deleted {
		t.Fatalf("purge = %+v, %v", res, err)
	}
	if _, err := os.Stat(mailFile); !errors.Is(err, os.ErrNotExist) {
		t.Fatal("purged mail still exists")
	}
	if !sim.Ran("runuser -u vmail -- rm -rf --one-file-system /var/vmail/example.com/info") {
		t.Error("mail was not removed as vmail")
	}

	res, err = m.DeleteDomain(ctx, "example.com", true)
	if err != nil || res.Deleted || !res.FilesRemoved {
		t.Fatalf("second delete = %+v, %v", res, err)
	}
	if _, err := os.Stat(sim.Path(dkimKey("example.com"))); !errors.Is(err, os.ErrNotExist) {
		t.Fatal("DKIM key still exists")
	}
}

func TestWebmailLogin(t *testing.T) {
	m, sim := newTest(t)
	setUp(t, m)
	if _, err := m.ApplyDomain(context.Background(), domainSpec()); err != nil {
		t.Fatal(err)
	}
	login, err := m.WebmailLogin("info@example.com")
	if err != nil {
		t.Fatal(err)
	}
	token, ok := strings.CutPrefix(login.URL, "https://mail.example.net/?_task=login&_action=servik-sso&_token=")
	if !ok || len(token) != 64 {
		t.Fatalf("url = %s", login.URL)
	}
	contains(t, "token", read(t, sim, webmailSSO+"/"+token+".json"), `"user":"info@example.com"`, `"expires":`)

	var v *ValidationError
	for _, a := range []string{"nobody@example.com", "satis@example.com", "info@example.org", "bad"} {
		if _, err := m.WebmailLogin(a); !errors.As(err, &v) {
			t.Errorf("login for %s: err = %v", a, err)
		}
	}
}
