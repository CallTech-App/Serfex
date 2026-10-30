package backup

import (
	"bytes"
	"context"
	"encoding/binary"
	"encoding/json"
	"errors"
	"io"
	"net"
	"os"
	"regexp"
	"slices"
	"strings"
	"testing"
	"time"

	"golang.org/x/crypto/ssh"

	"github.com/calltech-app/serfex/agent/internal/database"
	"github.com/calltech-app/serfex/agent/internal/site"
)

func TestPleskSubscriptionsAreListed(t *testing.T) {
	m, _ := newMigrateTest(t)
	srv, err := m.MigratePleskConnect(context.Background(), PleskConn{Host: "Plesk.EskiSunucu.com", Password: "x"})
	if err != nil {
		t.Fatal(err)
	}
	if srv.HostKey != demoHostKey() || !strings.HasPrefix(srv.Fingerprint, "SHA256:") || srv.Version != "18.0.63 · Ubuntu 22.04" || srv.Hostname != "plesk.eskisunucu.com" {
		t.Fatalf("server = %+v", srv)
	}
	want := []PleskSubscription{
		{ID: 4, Domain: "atolye.net", User: "atolye", Owner: "admin", Size: 18874368},
		{ID: 1, Domain: "kafe.com.tr", User: "kafeweb", Owner: "mehmet", Size: 268435456, Domains: 1},
	}
	if !slices.Equal(srv.Subscriptions, want) {
		t.Fatalf("subscriptions = %+v", srv.Subscriptions)
	}
	if _, err := m.MigratePleskConnect(context.Background(), PleskConn{Host: "plesk.eskisunucu.com", HostKey: "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl"}); err == nil || !strings.Contains(err.Error(), "host key changed") {
		t.Fatalf("another host key: %v", err)
	}
	for _, c := range []PleskConn{{Host: "bad host"}, {Host: "a.com", Port: 70000}, {Host: "a.com", User: "Root;rm"}} {
		if _, err := m.MigratePleskConnect(context.Background(), c); err == nil {
			t.Fatalf("%+v was taken", c)
		}
	}
}

func TestOnlyPleskServersAreRead(t *testing.T) {
	m, _ := newMigrateTest(t)
	m.dialPlesk = func(context.Context, PleskConn) (remote, string, error) {
		return stubRemote(func(cmd string, w io.Writer) error {
			_, err := io.WriteString(w, "plesk=\nhost=web1\nips=\n")
			return err
		}), demoHostKey(), nil
	}
	if _, err := m.MigratePleskConnect(context.Background(), PleskConn{Host: "web1.com"}); err == nil || !strings.Contains(err.Error(), "not a Plesk server") {
		t.Fatalf("err = %v", err)
	}
}

type stubRemote func(cmd string, w io.Writer) error

func (s stubRemote) run(_ context.Context, cmd string, w io.Writer) error { return s(cmd, w) }
func (s stubRemote) close()                                               {}

func pullKafe(t *testing.T, m *Manager) MigrateInspection {
	t.Helper()
	ctx := context.Background()
	res, err := m.MigratePleskPull(ctx, PleskPullSpec{PleskConn: PleskConn{Host: "plesk.eskisunucu.com", Password: "x", HostKey: demoHostKey()}, Subscription: 1, Source: "pl-1", Run: "pull-1"})
	if err != nil {
		t.Fatal(err)
	}
	if res.Source != "pl-1" || res.Size == 0 {
		t.Fatalf("pull = %+v", res)
	}
	in, err := m.MigrateInspect(ctx, MigrateSource{Source: "pl-1"})
	if err != nil {
		t.Fatal(err)
	}
	return in
}

func TestPleskSubscriptionIsReadLikeAnAccount(t *testing.T) {
	m, sim := newMigrateTest(t)
	p := pullKafe(t, m).Plan
	if p.Source != "plesk" || p.User != "kafeweb" || p.MainDomain != "kafe.com.tr" || p.Contact != "mehmet@kafe.com.tr" || p.Package != "Kurumsal" {
		t.Fatalf("account = %+v", p)
	}
	if !slices.Equal(p.OldIPs, []string{"192.0.2.10", "10.0.0.12", "2001:db8::10"}) {
		t.Fatalf("old ips = %v", p.OldIPs)
	}
	if len(p.Sites) != 2 {
		t.Fatalf("sites = %+v", p.Sites)
	}
	main, menu := p.Sites[0], p.Sites[1]
	if main.Domain != "kafe.com.tr" || main.DocRoot != "httpdocs" || main.PHP != "8.2" || !slices.Equal(main.Aliases, []string{"kafe.net"}) ||
		main.Certificate == nil || main.Certificate.Issuer != "Let's Encrypt" {
		t.Fatalf("main = %+v", main)
	}
	if !slices.Equal(main.Subdomains, []MigrationSubdomain{{Name: "blog.kafe.com.tr", DocRoot: "blog.kafe.com.tr", WebRoot: "subdomains/blog"}}) {
		t.Fatalf("subdomains = %+v", main.Subdomains)
	}
	if menu.Domain != "kafemenu.net" || menu.Kind != "addon" || menu.DocRoot != "kafemenu.net" || menu.PHP != "7.4" || !slices.Equal(menu.Aliases, []string{"kafemenu.com"}) {
		t.Fatalf("additional domain = %+v", menu)
	}
	// kafe_siparis is not the subscription's in Plesk, but the Laravel
	// app's .env uses it.
	wantDBs := []MigrationDatabase{{Name: "kafe_siparis", Size: 187, Site: "kafemenu.net"}, {Name: "kafe_wp", Size: 182, Site: "kafe.com.tr"}, {Name: "kafemenu_db", Size: 186, Site: "kafemenu.net"}}
	if !slices.EqualFunc(p.Databases, wantDBs, func(a, b MigrationDatabase) bool { return a == b }) {
		t.Fatalf("databases = %+v", p.Databases)
	}
	// MySQL 8.4 here: the native password cannot be used, caching_sha2 can.
	if len(p.DatabaseUsers) != 2 || p.DatabaseUsers[0].Name != "kafe_wpu" || p.DatabaseUsers[0].KeepsPassword ||
		p.DatabaseUsers[1].Name != "kafemenu_u" || !p.DatabaseUsers[1].KeepsPassword || !slices.Equal(p.DatabaseUsers[1].RemoteHosts, []string{"%"}) {
		t.Fatalf("users = %+v", p.DatabaseUsers)
	}
	if len(p.Mail) != 2 || p.Mail[0].Domain != "kafe.com.tr" || p.Mail[0].Forwarders != 2 || len(p.Mail[0].Mailboxes) != 1 {
		t.Fatalf("mail = %+v", p.Mail)
	}
	if b := p.Mail[0].Mailboxes[0]; b.Name != "info" || b.QuotaMB != 1024 || !b.KeepsPassword || b.Size == 0 {
		t.Fatalf("info = %+v", b)
	}
	if b := p.Mail[1].Mailboxes[0]; b.Name != "menu" || !b.KeepsPassword {
		t.Fatalf("menu = %+v", b)
	}
	if len(p.Zones) != 3 || !slices.Equal(p.Cron, []string{"*/10 * * * * /opt/plesk/php/8.2/bin/php -f /var/www/vhosts/kafe.com.tr/httpdocs/wp-cron.php"}) || !slices.Equal(p.FTP, []string{"kafe_tasarim"}) {
		t.Fatalf("zones %+v cron %v ftp %v", p.Zones, p.Cron, p.FTP)
	}
	wantNotes := []string{"plesk-no-hosting:eskikafe.com", "alias-mail:kafe.net", "old-path:httpdocs/wp-config.php", "remote-database:kafe_rapor",
		"plesk-missing-database:kafe_eski", "plesk-unlisted-database:kafe_siparis", "postgresql:kafe_analytics"}
	if got := notes(p.Notes); !slices.Equal(got, wantNotes) {
		t.Fatalf("notes = %v", got)
	}
	// The copy holds hashes only, never a mailbox's password.
	b, err := os.ReadFile(sim.Path(migrateDir + "/pl-1.tar"))
	if err != nil {
		t.Fatal(err)
	}
	if bytes.Contains(b, []byte("Kahve.2024")) || !bytes.Contains(b, []byte("info:$6$")) {
		t.Fatal("the mailbox password is in the copy in clear")
	}
	if strings.Contains(string(b), "dovecot.index") == false {
		// Dovecot's files come along in the copy and are left out on conversion.
		t.Fatal("the Maildir was not copied whole")
	}
}

func TestPleskSubscriptionMovesAndRestores(t *testing.T) {
	m, sim := newMigrateTest(t)
	ctx := context.Background()
	pullKafe(t, m)
	res, err := m.MigrateConvert(ctx, MigrateSpec{
		MigrateSource: MigrateSource{Source: "pl-1"}, Key: "mig-p", Account: "kafeweb",
		Sites: []string{"kafe.com.tr", "kafemenu.net"}, Parts: migrateParts, IPv4: "203.0.113.50", IPv6: "2001:db8:1::50",
	})
	if err != nil {
		t.Fatal(err)
	}
	if len(res.Items) != 2 || len(res.Passwords) != 1 || res.Passwords[0].Name != "kafe_wpu" ||
		!slices.Equal(res.Passwords[0].Files, []string{"/home/kafeweb/web/kafe.com.tr/public_html/wp-config.php"}) {
		t.Fatalf("result = %+v", res)
	}
	in, err := m.inspectFile(sim.Path(archivePath("mig-p", "kafe.com.tr")))
	if err != nil {
		t.Fatal(err)
	}
	if in.Manifest.Server != "plesk:kafeweb" {
		t.Fatalf("manifest = %+v", in.Manifest)
	}
	var meta siteMeta
	if err := json.Unmarshal(in.Meta, &meta); err != nil {
		t.Fatal(err)
	}
	if meta.Migration["source"] != "plesk" || meta.Site["php_version"] != "8.2" {
		t.Fatalf("meta = %+v", meta)
	}
	recs := map[string]string{}
	for _, r := range meta.DNS["records"].([]any) {
		r := r.(map[string]any)
		recs[r["name"].(string)+" "+r["type"].(string)] = r["content"].(string)
	}
	want := map[string]string{
		"@ A": "203.0.113.50", "@ AAAA": "2001:db8:1::50", "ns1 A": "203.0.113.50", "www CNAME": "kafe.com.tr.", "ftp CNAME": "kafe.com.tr.",
		"blog A": "203.0.113.50", "@ TXT": "v=spf1 +a +mx +a:plesk.eskisunucu.com +ip4:203.0.113.50 -all", "_dmarc TXT": "v=DMARC1; p=none",
		"siparis CNAME": "kafe.myshopify.com.", "_sip._tcp SRV": "5 5060 sip.kafe.com.tr.", "@ CAA": `0 issue "letsencrypt.org"`,
		"mail A": "203.0.113.50",
	}
	if len(recs) != len(want) {
		t.Fatalf("records = %v", recs)
	}
	for k, v := range want {
		if recs[k] != v {
			t.Fatalf("%s = %q, want %q (all %v)", k, recs[k], v, recs)
		}
	}
	boxes := meta.Mail["mailboxes"].([]any)
	info := boxes[0].(map[string]any)
	hash := strings.TrimPrefix(info["password_hash"].(string), "{SHA512-CRYPT}")
	salt := strings.Split(hash, "$")[2]
	if sha512CryptSalt("Kahve.2024", salt) != hash {
		t.Fatalf("info's hash %s is not its password's", hash)
	}
	fw := map[string][]string{}
	for _, f := range meta.Mail["forwarders"].([]any) {
		f := f.(map[string]any)
		fw[f["name"].(string)] = toStrings(f["destinations"])
	}
	if !slices.Equal(fw["siparis"], []string{"info@kafe.com.tr", "mehmet@gmail.com"}) || !slices.Equal(fw["iletisim"], []string{"info@kafe.com.tr"}) {
		t.Fatalf("forwarders = %v", fw)
	}
	if zones := meta.Migration["zones"].([]any); len(zones) != 1 || zones[0].(map[string]any)["name"] != "kafe.net" {
		t.Fatalf("alias zones = %v", meta.Migration["zones"])
	}
	mailNames := tarNames(t, sim, archivePath("mig-p", "kafe.com.tr"), "mail.tar.gz")
	if !slices.Contains(mailNames, "info/Maildir/.Sent/cur/2.eml") || slices.ContainsFunc(mailNames, func(n string) bool { return strings.Contains(n, "dovecot") || strings.Contains(n, ".qmail") }) {
		t.Fatalf("mail = %v", mailNames)
	}

	sm := site.NewManager(sim, "www-data")
	if _, err := sm.Apply(ctx, site.Spec{Domain: "kafe.com.tr", User: "kafeweb", PHPVersion: "8.4"}); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Restore(ctx, RestoreSpec{
		Chain: []ChainRef{{Key: "mig-p", Name: "kafe.com.tr"}}, Parts: []string{"files", "home", "databases", "mail"},
		Site:      SiteRef{Domain: "kafe.com.tr", User: "kafeweb"},
		Databases: []DatabaseRef{{Server: "mysql", Name: "kafe_wp"}},
		DatabaseUsers: []database.UserSpec{
			{Server: "mysql", Name: "kafe_wpu", Grants: []database.Grant{{Database: "kafe_wp", Access: "all"}}},
		},
	}); err != nil {
		t.Fatal(err)
	}
	web := "/home/kafeweb/web/kafe.com.tr"
	if got := read(t, sim, web+"/public_html/wp-config.php"); !strings.Contains(got, "define( 'DB_PASSWORD', '"+res.Passwords[0].Password+"' );") {
		t.Fatalf("wp-config.php = %q", got)
	}
	if got := read(t, sim, web+"/subdomains/blog/index.html"); got != "<h1>Blog</h1>" {
		t.Fatalf("blog = %q", got)
	}
	if got := read(t, sim, "/home/kafeweb/private/notlar.txt"); got != "backup notes" {
		t.Fatalf("private = %q", got)
	}
	if got, err := os.Readlink(sim.Path("/home/kafeweb/httpdocs")); err != nil || got != web+"/public_html" {
		t.Fatalf("httpdocs -> %q (%v)", got, err)
	}
	for _, gone := range []string{"/home/kafeweb/logs", "/home/kafeweb/tmp/sess_1", "/home/kafeweb/.ssh", "/home/kafeweb/kafemenu.net/index.php"} {
		if _, err := os.Lstat(sim.Path(gone)); err == nil {
			t.Fatalf("%s was moved", gone)
		}
	}
	if got := read(t, sim, "/var/vmail/kafe.com.tr/info/Maildir/cur/1.eml"); !strings.Contains(got, "Rezervasyon") {
		t.Fatalf("mail = %q", got)
	}
}

// failingRemote is the demo server with the commands holding any of fail
// failing, as a missing table or a refused user makes them.
type failingRemote struct {
	*demoPlesk
	fail []string
}

func (f failingRemote) run(ctx context.Context, cmd string, w io.Writer) error {
	for _, s := range f.fail {
		if strings.Contains(cmd, s) {
			return errors.New("ERROR 1146 (42S02) at line 1: Table 'psa.data_bases' doesn't exist (exit status 1)")
		}
	}
	return f.demoPlesk.run(ctx, cmd, w)
}

// plesk db prints MySQL's error on standard output, not on standard
// error: the error tells it.
func TestPleskQueryErrorsOnStandardOutput(t *testing.T) {
	r := stubRemote(func(cmd string, w io.Writer) error {
		io.WriteString(w, "--------------\nSELECT 1\n--------------\n\nERROR 1146 (42S02) at line 1: Table 'psa.x' doesn't exist\n")
		return errors.New("Process exited with status 1")
	})
	if _, err := pleskQuery(context.Background(), r, "x", "SELECT 1"); err == nil || !strings.HasPrefix(err.Error(), "ERROR 1146 (42S02) at line 1: Table 'psa.x' doesn't exist (") {
		t.Fatalf("err = %v", err)
	}
}

func TestPleskReadFailuresAreNoted(t *testing.T) {
	m, _ := newMigrateTest(t)
	m.dialPlesk = func(context.Context, PleskConn) (remote, string, error) {
		return failingRemote{newDemoPlesk(time.Now()), []string{"/*serfex:databases*/", "/*serfex:mail*/", "/*serfex:schemas*/"}}, demoHostKey(), nil
	}
	p := pullKafe(t, m).Plan
	if len(p.Databases) != 0 || len(p.Mail) != 0 {
		t.Fatalf("databases %+v mail %+v", p.Databases, p.Mail)
	}
	var failed []string
	for _, n := range p.Notes {
		if n.Code == "plesk-read-failed" {
			failed = append(failed, n.Subject)
		}
	}
	want := []string{
		"Posta hesapları: ERROR 1146 (42S02) at line 1: Table 'psa.data_bases' doesn't exist (exit status 1)",
		"Veritabanları: ERROR 1146 (42S02) at line 1: Table 'psa.data_bases' doesn't exist (exit status 1)",
		"Veritabanı sunucusundaki veritabanları: ERROR 1146 (42S02) at line 1: Table 'psa.data_bases' doesn't exist (exit status 1)",
	}
	if !slices.Equal(failed, want) {
		t.Fatalf("failed reads = %q", failed)
	}
}

// Plesk 18 has no db_servers table, older ones no DatabaseServers.
func TestPleskDatabaseServersOfEitherVersion(t *testing.T) {
	for _, c := range []struct {
		name      string
		fail      []string
		wantNotes []string
	}{
		{"Plesk 18", []string{" db_servers ", "FROM ip_addresses"}, nil},
		{"Plesk 18 without public addresses", []string{" db_servers ", "public_ip_address", "FROM ip_addresses"}, nil},
		{"older Plesk", []string{" DatabaseServers ", "FROM IP_Addresses"}, nil},
		// Neither: the server itself tells which databases it has; kafe_rapor
		// is not there.
		{"no servers table", []string{" DatabaseServers ", " db_servers "}, []string{"remote-database:kafe_rapor"}},
	} {
		t.Run(c.name, func(t *testing.T) {
			m, _ := newMigrateTest(t)
			m.dialPlesk = func(context.Context, PleskConn) (remote, string, error) {
				return failingRemote{newDemoPlesk(time.Now()), c.fail}, demoHostKey(), nil
			}
			p := pullKafe(t, m).Plan
			var dbs []string
			for _, d := range p.Databases {
				dbs = append(dbs, d.Name)
			}
			if !slices.Equal(dbs, []string{"kafe_siparis", "kafe_wp", "kafemenu_db"}) {
				t.Fatalf("databases = %v", dbs)
			}
			for _, n := range notes(p.Notes) {
				if strings.HasPrefix(n, "plesk-read-failed:") {
					t.Fatalf("note %s", n)
				}
			}
			if c.wantNotes != nil && !slices.Contains(notes(p.Notes), c.wantNotes[0]) {
				t.Fatalf("notes = %v", notes(p.Notes))
			}
		})
	}
}

// Plesk can keep a certificate's key encrypted in its table; the file in
// /usr/local/psa/var/certificates has it in PEM.
func TestPleskCertificateFromItsFile(t *testing.T) {
	m, _ := newMigrateTest(t)
	m.dialPlesk = func(context.Context, PleskConn) (remote, string, error) {
		d := newDemoPlesk(time.Now())
		d.tables["certificates"][0][2] = "$AES-128-CBC$c2VyZmV4$ZW5jcnlwdGVk"
		return d, demoHostKey(), nil
	}
	p := pullKafe(t, m).Plan
	if c := p.Sites[0].Certificate; c == nil || c.Issuer != "Let's Encrypt" {
		t.Fatalf("certificate = %+v", c)
	}
}

func TestPleskSiteSettingsNameDatabases(t *testing.T) {
	p := &pleskPull{hostname: "plesk.eskisunucu.com", ips: []string{"192.0.2.10"}}
	for name, body := range map[string]string{
		"httpdocs/.env":                "APP_ENV=production\n# DB_DATABASE=yorum\nDB_CONNECTION=mysql\nDB_HOST=localhost:3306\nDB_DATABASE='magaza'\n",
		"api/.env":                     "export DB_CONNECTION=pgsql\nDB_DATABASE=api_db # analytics\n",
		"lite/.env":                    "DB_CONNECTION=sqlite\nDB_DATABASE=/var/www/vhosts/a.com/database.sqlite\n",
		"blog/wp-config.php":           "<?php\ndefine('DB_NAME', \"blog_wp\");\ndefine( 'DB_HOST', 'db.baska.net' );\n",
		"httpdocs/vendor/x/y/.env":     "DB_DATABASE=paket\n",
		"httpdocs/node_modules/x/.env": "DB_DATABASE=paket\n",
		"httpdocs/config.php":          "<?php $db = 'eski';",
		"env/.env":                     "DB_DATABASE=${APP_DB}\n",
	} {
		p.configDB(name, []byte(body))
	}
	got := map[string]pleskConfigDB{}
	for _, c := range p.configDBs {
		got[c.file] = c
	}
	want := map[string]pleskConfigDB{
		"httpdocs/.env":      {file: "httpdocs/.env", conn: "mysql", name: "magaza", host: "localhost:3306"},
		"api/.env":           {file: "api/.env", conn: "pgsql", name: "api_db"},
		"blog/wp-config.php": {file: "blog/wp-config.php", conn: "mysql", name: "blog_wp", host: "db.baska.net"},
	}
	// A SQLite file's path, a variable and packages' copies name none.
	if len(got) != len(want) {
		t.Fatalf("databases = %+v", p.configDBs)
	}
	for k, w := range want {
		if got[k] != w {
			t.Fatalf("%s = %+v, want %+v", k, got[k], w)
		}
	}
	for h, local := range map[string]bool{"": true, "localhost": true, "127.0.0.1:3306": true, "[::1]:3306": true, "localhost:/run/mysqld/mysqld.sock": true,
		"192.0.2.10": true, "PLESK.eskisunucu.com": true, "10.0.0.5": false, "db.baska.net:3306": false} {
		if p.localHost(h) != local {
			t.Fatalf("%q local = %v", h, !local)
		}
	}
}

func TestPleskNamesAndRecords(t *testing.T) {
	for in, want := range map[string]string{"kafeweb": "kafeweb", "Kafe.Web": "kafe_web", "9kafe-1": "kafe-1", "_x": "x", "...": "plesk7", strings.Repeat("a", 40): strings.Repeat("a", 32)} {
		if got := pleskUser(in, 7); got != want || site.ValidateUser(got) != nil {
			t.Errorf("pleskUser(%q) = %q, want %q", in, got, want)
		}
	}
	for _, c := range []struct{ typ, host, val, opt, want string }{
		{"A", "Kafe.com.tr.", "192.0.2.1", "", "kafe.com.tr.\tIN\tA\t192.0.2.1"},
		{"CNAME", "www.kafe.com.tr", "kafe.com.tr", "", "www.kafe.com.tr.\tIN\tCNAME\tkafe.com.tr."},
		{"MX", "kafe.com.tr.", "mx.yandex.net.", "", "kafe.com.tr.\tIN\tMX\t10 mx.yandex.net."},
		{"TXT", "kafe.com.tr.", `a "b" c`, "", "kafe.com.tr.\tIN\tTXT\t\"a \\\"b\\\" c\""},
		{"CAA", "kafe.com.tr.", `"letsencrypt.org"`, "0 issue", "kafe.com.tr.\tIN\tCAA\t0 issue \"letsencrypt.org\""},
		{"SRV", "_sip._tcp.kafe.com.tr.", "sip.kafe.com.tr.", "10", ""},
		{"PTR", "1.2.0.192.in-addr.arpa.", "kafe.com.tr.", "", ""},
	} {
		got, ok := pleskRecord(c.typ, c.host, c.val, c.opt)
		if got != c.want || ok != (c.want != "") {
			t.Errorf("%s %s = %q", c.typ, c.host, got)
		}
	}
	long := strings.Repeat("k", 300)
	rrs := parseZone(strings.NewReader("kafe.com.tr.\tIN\tTXT\t"+zoneString(long)+"\n"), "kafe.com.tr")
	if len(rrs) != 1 || strings.Join(rrs[0].Data, "") != long {
		t.Fatalf("long TXT = %+v", rrs)
	}
	rows := parseTSV([]byte("1\ta\\tb\\nc\\\\d\n\n2\t\n"))
	if len(rows) != 2 || rows[0][1] != "a\tb\nc\\d" || rows[1][1] != "" {
		t.Fatalf("rows = %q", rows)
	}
}

// TestPleskOverSSH runs the demo server behind a real SSH server: password
// and key sign-in, the host key kept, a subscription copied.
func TestPleskOverSSH(t *testing.T) {
	m, _ := newMigrateTest(t)
	pub, err := m.PublicKey()
	if err != nil {
		t.Fatal(err)
	}
	agentKey, _, _, _, err := ssh.ParseAuthorizedKey([]byte(pub))
	if err != nil {
		t.Fatal(err)
	}
	addr := sshServer(t, agentKey)
	host, port, _ := net.SplitHostPort(addr)
	m.dialPlesk = m.dialSSH
	conn := PleskConn{Host: host, Port: atoi(port), Password: "dogru-parola"}
	ctx := context.Background()

	if _, err := m.MigratePleskConnect(ctx, PleskConn{Host: host, Port: conn.Port, Password: "yanlis"}); err == nil || !strings.Contains(err.Error(), "refused the user name or password") {
		t.Fatalf("wrong password: %v", err)
	}
	srv, err := m.MigratePleskConnect(ctx, conn)
	if err != nil {
		t.Fatal(err)
	}
	if srv.HostKey != demoHostKey() || len(srv.Subscriptions) != 2 {
		t.Fatalf("server = %+v", srv)
	}
	// Serfex's own key, with the host key known.
	if _, err := m.MigratePleskConnect(ctx, PleskConn{Host: host, Port: conn.Port, HostKey: srv.HostKey}); err != nil {
		t.Fatalf("key sign-in: %v", err)
	}
	other := "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl"
	if _, err := m.MigratePleskConnect(ctx, PleskConn{Host: host, Port: conn.Port, Password: conn.Password, HostKey: other}); err == nil || !strings.Contains(err.Error(), "host key changed") {
		t.Fatalf("another host key: %v", err)
	}
	conn.HostKey = srv.HostKey
	if _, err := m.MigratePleskPull(ctx, PleskPullSpec{PleskConn: conn, Subscription: 1, Source: "pl-ssh"}); err != nil {
		t.Fatal(err)
	}
	in, err := m.MigrateInspect(ctx, MigrateSource{Source: "pl-ssh"})
	if err != nil || len(in.Plan.Sites) != 2 || len(in.Plan.Databases) != 3 {
		t.Fatalf("inspect = %+v, %v", in.Plan, err)
	}
}

// sshServer serves the demo Plesk server over SSH on a local port. It
// takes the password "dogru-parola" and the key given.
func sshServer(t *testing.T, key ssh.PublicKey) string {
	t.Helper()
	cfg := &ssh.ServerConfig{
		PasswordCallback: func(_ ssh.ConnMetadata, pw []byte) (*ssh.Permissions, error) {
			if string(pw) == "dogru-parola" {
				return nil, nil
			}
			return nil, io.EOF
		},
		PublicKeyCallback: func(_ ssh.ConnMetadata, k ssh.PublicKey) (*ssh.Permissions, error) {
			if bytes.Equal(k.Marshal(), key.Marshal()) {
				return nil, nil
			}
			return nil, io.EOF
		},
	}
	cfg.AddHostKey(demoSigner())
	l, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { l.Close() })
	demo := newDemoPlesk(time.Now())
	go func() {
		for {
			c, err := l.Accept()
			if err != nil {
				return
			}
			go func() {
				_, chans, reqs, err := ssh.NewServerConn(c, cfg)
				if err != nil {
					c.Close()
					return
				}
				go ssh.DiscardRequests(reqs)
				for nc := range chans {
					ch, creqs, err := nc.Accept()
					if err != nil {
						continue
					}
					go func() {
						defer ch.Close()
						for r := range creqs {
							if r.Type != "exec" {
								r.Reply(false, nil)
								continue
							}
							var p struct{ Command string }
							ssh.Unmarshal(r.Payload, &p)
							r.Reply(true, nil)
							status := uint32(0)
							if err := demo.run(context.Background(), p.Command, ch); err != nil {
								io.WriteString(ch.Stderr(), err.Error()+"\n")
								status = 1
							}
							b := make([]byte, 4)
							binary.BigEndian.PutUint32(b, status)
							ch.SendRequest("exit-status", false, b)
							return
						}
					}()
				}
			}()
		}
	}()
	return l.Addr().String()
}

// TestWritePleskFixture writes the panel's test answers for a Plesk move
// (panel/tests/Fixtures/plesk-migration.json) when SERFEX_PLESK_FIXTURE
// names the file: what connecting, reading the copy and converting it
// return, and each site's description.
func TestWritePleskFixture(t *testing.T) {
	file := os.Getenv("SERFEX_PLESK_FIXTURE")
	if file == "" {
		t.Skip("SERFEX_PLESK_FIXTURE is not set")
	}
	m, sim := newMigrateTest(t)
	ctx := context.Background()
	srv, err := m.MigratePleskConnect(ctx, PleskConn{Host: "plesk.eskisunucu.com", Password: "x"})
	if err != nil {
		t.Fatal(err)
	}
	in := pullKafe(t, m)
	res, err := m.MigrateConvert(ctx, MigrateSpec{
		MigrateSource: MigrateSource{Source: "pl-1"}, Key: "mig-p", Account: "kafeweb",
		Sites: []string{"kafe.com.tr", "kafemenu.net"}, Parts: migrateParts, IPv4: "203.0.113.50",
	})
	if err != nil {
		t.Fatal(err)
	}
	meta := map[string]json.RawMessage{}
	for _, d := range []string{"kafe.com.tr", "kafemenu.net"} {
		info, err := m.inspectFile(sim.Path(archivePath("mig-p", d)))
		if err != nil {
			t.Fatal(err)
		}
		meta[d] = info.Meta
	}
	b, err := json.MarshalIndent(map[string]any{"connect": srv, "inspect": in, "convert": res, "meta": meta}, "", "  ")
	if err != nil {
		t.Fatal(err)
	}
	// The demo certificate's dates are today's: a fixed date keeps the
	// fixture from expiring.
	b = regexp.MustCompile(`"not_(before|after)": "[^"]+"`).ReplaceAll(b, []byte(`"not_$1": "2036-01-01T00:00:00Z"`))
	if err := os.WriteFile(file, append(b, '\n'), 0o644); err != nil {
		t.Fatal(err)
	}
}
