package backup

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/json"
	"encoding/pem"
	"math/big"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/calltech-app/serfex/agent/internal/database"
	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/laravel"
	"github.com/calltech-app/serfex/agent/internal/site"
)

// cpEntry is a member of a made-up cPanel archive.
type cpEntry struct {
	name, body, link string
	dir              bool
}

// cpanelArchive writes a cpmove archive like WHM's transfer tool makes.
func cpanelArchive(t testing.TB, file string, now time.Time) {
	t.Helper()
	chain, key := testCertificate(t, []string{"magaza.com.tr", "www.magaza.com.tr"}, now)
	entries := []cpEntry{
		{name: "version", body: "pkgacct version: 10\narchive version: 4\n"},
		{name: "cp/magaza", body: "DNS=magaza.com.tr\nDNS1=blogum.net\nDNS2=magaza.net\nIP=198.51.100.7\nCONTACTEMAIL=ayse@magaza.com.tr\nPLAN=gold\nUSER=magaza\n"},
		{name: "userdata/main", body: "---\naddon_domains:\n  blogum.net: blogum.magaza.com.tr\ncp_php_magic_include_path.conf: 0\nmain_domain: magaza.com.tr\nparked_domains:\n  - magaza.net\nsub_domains:\n  - blogum.magaza.com.tr\n  - shop.magaza.com.tr\n  - api.magaza.com.tr\n"},
		{name: "userdata/magaza.com.tr", body: "---\ndocumentroot: /home/magaza/public_html\nhomedir: /home/magaza\nip: 198.51.100.7\nphpversion: ea-php81\nserveralias: mail.magaza.com.tr www.magaza.com.tr\nservername: magaza.com.tr\n"},
		{name: "userdata/blogum.magaza.com.tr", body: "---\ndocumentroot: /home/magaza/blogum.net\nhomedir: /home/magaza\nip: 198.51.100.7\nphpversion: ea-php74\nserveralias: blogum.net www.blogum.net\n"},
		{name: "userdata/shop.magaza.com.tr", body: "---\ndocumentroot: /home/magaza/public_html/shop\nhomedir: /home/magaza\n"},
		{name: "userdata/api.magaza.com.tr", body: "---\ndocumentroot: /home/magaza/api\nhomedir: /home/magaza\n"},
		{name: "homedir", dir: true},
		{name: "homedir/public_html", dir: true},
		{name: "homedir/public_html/index.php", body: "<?php require __DIR__.'/wp-blog-header.php';"},
		{name: "homedir/public_html/wp-config.php", body: "<?php\ndefine( 'DB_NAME', 'magaza_wp' );\ndefine( 'DB_USER', 'magaza_wpu' );\ndefine( 'DB_PASSWORD', 'eski-parola' );\ndefine( 'DB_HOST', 'localhost' );\n"},
		{name: "homedir/public_html/shop/index.html", body: "shop"},
		{name: "homedir/public_html/.htaccess", body: "AuthUserFile /home/magaza/.htpasswds/public_html/passwd\n"},
		{name: "homedir/blogum.net/index.php", body: "<?php require __DIR__.'/../laravel/vendor/autoload.php';"},
		{name: "homedir/laravel/.env", body: "APP_NAME=Blog\nDB_CONNECTION=mysql\nDB_DATABASE=magaza_blog\nDB_USERNAME=magaza_blog\nDB_PASSWORD=\"eski\"\n"},
		{name: "homedir/laravel/artisan", body: "#!/usr/bin/env php"},
		{name: "homedir/api/index.php", body: "api"},
		{name: "homedir/.bashrc", body: "# bash"},
		{name: "homedir/.htpasswds/public_html/passwd", body: "admin:$apr1$x"},
		{name: "homedir/www", link: "public_html"},
		{name: "homedir/web/old.txt", body: "a folder named web"},
		{name: "homedir/cpmove-magaza.tar.gz", body: "an old transfer"},
		{name: "homedir/.cpanel/datastore/x", body: "cpanel"},
		{name: "homedir/tmp/session", body: "tmp"},
		{name: "homedir/mail/magaza.com.tr/info/cur/1.eml", body: "Subject: merhaba"},
		{name: "homedir/mail/magaza.com.tr/info/.Sent/cur/2.eml", body: "Subject: sent"},
		{name: "homedir/mail/magaza.com.tr/info/dovecot.index", body: "index"},
		{name: "homedir/mail/magaza.com.tr/info/maildirsize", body: "1024S"},
		{name: "homedir/mail/magaza.com.tr/satis/storage/m.1", body: "mdbox"},
		{name: "homedir/mail/blogum.net/yazar/new/3.eml", body: "Subject: blog"},
		{name: "homedir/mail/cur/0.eml", body: "the account's own mailbox"},
		{name: "homedir/etc/magaza.com.tr/passwd", body: "info:x:1001:1001::/home/magaza/mail/magaza.com.tr/info:/home/magaza\nsatis:x:1001:1001::/home/magaza/mail/magaza.com.tr/satis:/home/magaza\n"},
		{name: "homedir/etc/magaza.com.tr/shadow", body: "info:$6$abcdefgh$WkzUUN9e4Xr3l6sQ7I8J2h6ZqU2pN1y8m7k3l1t0v5w4x3y2z1a0b9c8d7e6f5g4h3i2j1k0l9m8n7o6p5q4r3:19000::::::\nsatis:$1$abcdefgh$q2w3e4r5t6y7u8i9o0p1a2:19000::::::\n"},
		{name: "homedir/etc/magaza.com.tr/quota", body: "info:1073741824\n"},
		{name: "homedir/etc/blogum.net/passwd", body: "yazar:x:1001:1001::/home/magaza/mail/blogum.net/yazar:/home/magaza\n"},
		{name: "homedir/etc/blogum.net/shadow", body: "yazar:DESHASH12345:19000::::::\n"},
		{name: "mysql.sql", body: "-- cPanel mysql backup\nGRANT USAGE ON *.* TO 'magaza'@'localhost' IDENTIFIED BY PASSWORD '*AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';\nGRANT ALL PRIVILEGES ON `magaza\\_%`.* TO 'magaza'@'localhost';\n" +
			"CREATE USER 'magaza_wpu'@'localhost' IDENTIFIED WITH 'mysql_native_password' AS '*BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';\nGRANT USAGE ON *.* TO `magaza_wpu`@`localhost`;\nGRANT ALL PRIVILEGES ON `magaza\\_wp`.* TO `magaza_wpu`@`localhost`;\n" +
			"CREATE USER 'magaza_blog'@'localhost' IDENTIFIED WITH 'caching_sha2_password' AS 0x2441243030350102;\nGRANT SELECT, INSERT, UPDATE, DELETE ON `magaza\\_blog`.* TO `magaza_blog`@`localhost`;\n" +
			"GRANT USAGE ON *.* TO 'magaza_blog'@'203.0.113.9' IDENTIFIED BY PASSWORD '*CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC';\n"},
		{name: "mysql/magaza_wp.create", body: "CREATE DATABASE `magaza_wp`;"},
		{name: "mysql/magaza_wp.sql", body: "CREATE TABLE wp_posts (id int);\n"},
		{name: "mysql/magaza_blog.sql", body: "CREATE TABLE posts (id int);\n"},
		{name: "mysql/magaza_eski.sql", body: "CREATE TABLE t (id int);\n"},
		{name: "dnszones/magaza.com.tr.db", body: "; cPanel first:11.40\n$TTL 14400\nmagaza.com.tr.\t86400\tIN\tSOA\tns1.eskihost.com.\troot.eskihost.com.\t(\n\t\t2024010101 ;Serial\n\t\t3600\n\t\t1800\n\t\t1209600\n\t\t86400 )\n" +
			"magaza.com.tr.\t86400\tIN\tNS\tns1.eskihost.com.\nmagaza.com.tr.\t86400\tIN\tNS\tns2.eskihost.com.\nmagaza.com.tr.\t14400\tIN\tA\t198.51.100.7\n" +
			"www\t14400\tIN\tCNAME\tmagaza.com.tr.\nmail\t14400\tIN\tCNAME\tmagaza.com.tr.\nftp\t14400\tIN\tCNAME\tmagaza.com.tr.\ncpanel\t14400\tIN\tA\t198.51.100.7\nwebmail\t14400\tIN\tA\t198.51.100.7\n" +
			"magaza.com.tr.\t14400\tIN\tMX\t0\tmagaza.com.tr.\nmagaza.com.tr.\t14400\tIN\tTXT\t\"v=spf1 +a +mx +ip4:198.51.100.7 ~all\"\n" +
			"default._domainkey\t14400\tIN\tTXT\t( \"v=DKIM1; k=rsa; \" \"p=MIIB\" )\n_cpanel-dcv-test-record\t300\tIN\tTXT\t\"_cpanel-dcv-test-record=x\"\n" +
			"shop\t14400\tIN\tA\t198.51.100.7\napi\t14400\tIN\tA\t198.51.100.7\nkampanya\t3600\tIN\tCNAME\tmagaza.shopify.com.\n_dmarc\t14400\tIN\tTXT\t\"v=DMARC1; p=quarantine\"\n" +
			"magaza.com.tr.\t14400\tIN\tCAA\t0 issue \"letsencrypt.org\"\n_sip._tcp\t14400\tIN\tSRV\t10 5 5060 sip.magaza.com.tr.\n"},
		{name: "dnszones/magaza.net.db", body: "$TTL 14400\n@\tIN\tSOA\tns1.eskihost.com. root.eskihost.com. 1 3600 1800 1209600 86400\n@\tIN\tNS\tns1.eskihost.com.\n@\tIN\tA\t198.51.100.7\nwww\tIN\tCNAME\tmagaza.net.\n"},
		{name: "dnszones/blogum.net.db", body: "$TTL 14400\n@ 86400 IN SOA ns1.eskihost.com. root.eskihost.com. 1 3600 1800 1209600 86400\n@ IN NS ns1.eskihost.com.\n@ IN A 198.51.100.7\n@ IN MX 10 aspmx.l.google.com.\n"},
		{name: "va/magaza.com.tr", body: "info@magaza.com.tr: ayse@gmail.com\nwebmaster@magaza.com.tr: info@magaza.com.tr\nsiparis@magaza.com.tr: \"|/home/magaza/bin/pipe.php\"\n*: :fail: No Such User Here\n"},
		{name: "apache_tls/magaza.com.tr", body: key + chain},
		{name: "cron/magaza", body: "MAILTO=\"\"\n*/5 * * * * /usr/local/bin/php /home/magaza/laravel/artisan schedule:run\n"},
		{name: "proftpdpasswd", body: "magaza:$6$x:1001:1001::/home/magaza:/bin/bash\ntasarimci@magaza.com.tr:$6$y:1001:1001::/home/magaza/public_html:/bin/ftpsh\n"},
	}
	var buf bytes.Buffer
	gz := gzip.NewWriter(&buf)
	tw := tar.NewWriter(gz)
	for _, e := range entries {
		h := &tar.Header{Name: "cpmove-magaza/" + e.name, Mode: 0o644, ModTime: now, Typeflag: tar.TypeReg, Size: int64(len(e.body)), Uid: 1001, Gid: 1001}
		switch {
		case e.dir:
			h.Typeflag, h.Size, h.Mode, h.Name = tar.TypeDir, 0, 0o755, h.Name+"/"
		case e.link != "":
			h.Typeflag, h.Size, h.Linkname = tar.TypeSymlink, 0, e.link
		}
		if err := tw.WriteHeader(h); err != nil {
			t.Fatal(err)
		}
		if h.Typeflag == tar.TypeReg {
			if _, err := tw.Write([]byte(e.body)); err != nil {
				t.Fatal(err)
			}
		}
	}
	if err := tw.Close(); err != nil {
		t.Fatal(err)
	}
	if err := gz.Close(); err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(filepath.Dir(file), 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(file, buf.Bytes(), 0o600); err != nil {
		t.Fatal(err)
	}
}

// testCertificate returns a certificate signed by a test CA, with its
// chain, and its key, in PEM.
func testCertificate(t testing.TB, names []string, now time.Time) (chain, key string) {
	t.Helper()
	caKey, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	ca := &x509.Certificate{SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: "Test CA", Organization: []string{"Sectigo Limited"}}, NotBefore: now.Add(-time.Hour), NotAfter: now.Add(400 * 24 * time.Hour), IsCA: true, BasicConstraintsValid: true, KeyUsage: x509.KeyUsageCertSign}
	caDER, err := x509.CreateCertificate(rand.Reader, ca, ca, &caKey.PublicKey, caKey)
	if err != nil {
		t.Fatal(err)
	}
	caCert, _ := x509.ParseCertificate(caDER)
	leafKey, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	leaf := &x509.Certificate{SerialNumber: big.NewInt(2), Subject: pkix.Name{CommonName: names[0]}, DNSNames: names, NotBefore: now.Add(-time.Hour), NotAfter: now.Add(60 * 24 * time.Hour)}
	leafDER, err := x509.CreateCertificate(rand.Reader, leaf, caCert, &leafKey.PublicKey, caKey)
	if err != nil {
		t.Fatal(err)
	}
	kb, _ := x509.MarshalECPrivateKey(leafKey)
	return string(pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: leafDER})) + string(pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: caDER})),
		string(pem.EncodeToMemory(&pem.Block{Type: "EC PRIVATE KEY", Bytes: kb}))
}

func newMigrateTest(t *testing.T) (*Manager, *host.Simulator) {
	t.Helper()
	sim, err := host.NewSimulator(t.TempDir(), "8.4")
	if err != nil {
		t.Fatal(err)
	}
	db := database.NewSimulated(sim)
	if _, err := db.Install(context.Background(), database.InstallSpec{Product: database.MySQL}); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(sim.Path("/etc/passwd"), append(must(os.ReadFile(sim.Path("/etc/passwd"))), "vmail:x:5000:5000::/var/vmail:/usr/sbin/nologin\n"...), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(sim.Path("/var/vmail"), 0o755); err != nil {
		t.Fatal(err)
	}
	m := NewManager(sim, db, laravel.NewManager(sim))
	m.now = func() time.Time { return time.Now().UTC() }
	return m, sim
}

func notes(p []MigrationNote) []string {
	var out []string
	for _, n := range p {
		out = append(out, n.Code+":"+n.Subject)
	}
	return out
}

func TestCPanelArchiveIsDescribed(t *testing.T) {
	m, sim := newMigrateTest(t)
	cpanelArchive(t, sim.Path(ImportDir+"/cpmove-magaza.tar.gz"), time.Now())
	files, err := m.MigrateScan()
	if err != nil || len(files) != 1 || files[0].File != "cpmove-magaza.tar.gz" {
		t.Fatalf("scan = %+v, %v", files, err)
	}
	in, err := m.MigrateInspect(context.Background(), MigrateSource{File: "cpmove-magaza.tar.gz"})
	if err != nil {
		t.Fatal(err)
	}
	p := in.Plan
	if p.User != "magaza" || p.MainDomain != "magaza.com.tr" || p.Contact != "ayse@magaza.com.tr" || !slices.Equal(p.OldIPs, []string{"198.51.100.7"}) {
		t.Fatalf("account = %+v", p)
	}
	if len(p.Sites) != 2 {
		t.Fatalf("sites = %+v", p.Sites)
	}
	main, blog := p.Sites[0], p.Sites[1]
	if main.Domain != "magaza.com.tr" || main.Kind != "main" || main.DocRoot != "public_html" || main.PHP != "8.1" || !slices.Equal(main.Aliases, []string{"magaza.net"}) {
		t.Fatalf("main = %+v", main)
	}
	if main.Certificate == nil || main.Certificate.Issuer != "Sectigo Limited" {
		t.Fatalf("certificate = %+v", main.Certificate)
	}
	wantSubs := []MigrationSubdomain{{Name: "api.magaza.com.tr", DocRoot: "api", WebRoot: "subdomains/api"}, {Name: "shop.magaza.com.tr", DocRoot: "public_html/shop", WebRoot: "public_html/shop"}}
	if !slices.Equal(main.Subdomains, wantSubs) {
		t.Fatalf("subdomains = %+v", main.Subdomains)
	}
	if blog.Domain != "blogum.net" || blog.DocRoot != "blogum.net" || blog.PHP != "7.4" || blog.Certificate != nil {
		t.Fatalf("addon = %+v", blog)
	}
	// The Laravel application's .env is outside every site: its database
	// goes with the account's main site.
	wantDBs := []MigrationDatabase{{Name: "magaza_blog", Size: 29, Site: ""}, {Name: "magaza_eski", Size: 25, Site: ""}, {Name: "magaza_wp", Size: 32, Site: "magaza.com.tr"}}
	if !slices.EqualFunc(p.Databases, wantDBs, func(a, b MigrationDatabase) bool { return a == b }) {
		t.Fatalf("databases = %+v", p.Databases)
	}
	// MySQL 8.4 here: the native password cannot be used, caching_sha2 can.
	if len(p.DatabaseUsers) != 2 || p.DatabaseUsers[0].Name != "magaza_wpu" || p.DatabaseUsers[0].KeepsPassword ||
		p.DatabaseUsers[1].Name != "magaza_blog" || !p.DatabaseUsers[1].KeepsPassword || !slices.Equal(p.DatabaseUsers[1].RemoteHosts, []string{"203.0.113.9"}) {
		t.Fatalf("users = %+v", p.DatabaseUsers)
	}
	if len(p.Mail) != 2 || p.Mail[1].Domain != "magaza.com.tr" || p.Mail[1].Forwarders != 2 {
		t.Fatalf("mail = %+v", p.Mail)
	}
	boxes := p.Mail[1].Mailboxes
	if len(boxes) != 2 || boxes[0].Name != "info" || boxes[0].QuotaMB != 1024 || !boxes[0].KeepsPassword || !boxes[1].Mdbox || !boxes[1].KeepsPassword {
		t.Fatalf("mailboxes = %+v", boxes)
	}
	if p.Mail[0].Mailboxes[0].KeepsPassword {
		t.Fatal("a DES password was taken as usable")
	}
	if len(p.Zones) != 3 || len(p.Cron) != 1 || !slices.Equal(p.FTP, []string{"tasarimci@magaza.com.tr"}) {
		t.Fatalf("zones %+v cron %v ftp %v", p.Zones, p.Cron, p.FTP)
	}
	// The archive copied by hand stays where it is.
	if _, err := os.Stat(sim.Path(ImportDir + "/cpmove-magaza.tar.gz")); err != nil {
		t.Fatal(err)
	}
}

func TestUploadedArchiveIsKeptUntilDiscarded(t *testing.T) {
	m, sim := newMigrateTest(t)
	if err := m.dirs(); err != nil {
		t.Fatal(err)
	}
	cpanelArchive(t, sim.Path(uploadsDir+"/up-1.part"), time.Now())
	in, err := m.MigrateInspect(context.Background(), MigrateSource{Upload: "up-1"})
	if err != nil || in.Source != "up-1" {
		t.Fatalf("inspect = %+v, %v", in.Source, err)
	}
	if _, err := m.MigrateInspect(context.Background(), MigrateSource{Source: "up-1"}); err != nil {
		t.Fatal(err)
	}
	if err := m.MigrateDiscard(MigrateSource{Source: "up-1"}); err != nil {
		t.Fatal(err)
	}
	if _, err := m.MigrateInspect(context.Background(), MigrateSource{Source: "up-1"}); err == nil {
		t.Fatal("a discarded archive was read")
	}
}

func TestOtherArchivesAreRefused(t *testing.T) {
	m, sim := newMigrateTest(t)
	write(t, sim, ImportDir+"/random.tar.gz", "not a tar")
	if _, err := m.MigrateInspect(context.Background(), MigrateSource{File: "random.tar.gz"}); err == nil || !strings.Contains(err.Error(), "not a cPanel account") {
		t.Fatalf("err = %v", err)
	}
}

func TestCPanelAccountMovesAndRestores(t *testing.T) {
	m, sim := newMigrateTest(t)
	ctx := context.Background()
	cpanelArchive(t, sim.Path(ImportDir+"/cpmove-magaza.tar.gz"), time.Now())
	res, err := m.MigrateConvert(ctx, MigrateSpec{
		MigrateSource: MigrateSource{File: "cpmove-magaza.tar.gz"},
		Key:           "mig-1", Run: "mig-run", Account: "magaza",
		Sites: []string{"magaza.com.tr", "blogum.net"}, Parts: migrateParts, IPv4: "203.0.113.50",
	})
	if err != nil {
		t.Fatal(err)
	}
	if len(res.Items) != 2 || res.Items[0].Name != "magaza.com.tr" {
		t.Fatalf("items = %+v", res.Items)
	}
	for _, p := range []string{"files", "home", "mail", "databases", "certificates"} {
		if _, ok := res.Items[0].Parts[p]; !ok {
			t.Fatalf("main site has no %s: %+v", p, res.Items[0].Parts)
		}
	}
	if len(res.Passwords) != 1 || res.Passwords[0].Name != "magaza_wpu" || !slices.Equal(res.Passwords[0].Files, []string{"/home/magaza/web/magaza.com.tr/public_html/wp-config.php"}) {
		t.Fatalf("passwords = %+v", res.Passwords)
	}
	newPW := res.Passwords[0].Password

	// The site description the panel creates the site from.
	in, err := m.inspectFile(sim.Path(archivePath("mig-1", "magaza.com.tr")))
	if err != nil {
		t.Fatal(err)
	}
	var meta siteMeta
	if err := json.Unmarshal(in.Meta, &meta); err != nil {
		t.Fatal(err)
	}
	if got := meta.Site["aliases"]; !slices.Equal(toStrings(got), []string{"www.magaza.com.tr", "magaza.net", "www.magaza.net"}) {
		t.Fatalf("aliases = %v", got)
	}
	recs := map[string]string{}
	for _, r := range meta.DNS["records"].([]any) {
		r := r.(map[string]any)
		recs[r["name"].(string)+" "+r["type"].(string)] = r["content"].(string)
	}
	want := map[string]string{
		"@ A": "203.0.113.50", "www CNAME": "magaza.com.tr.", "ftp CNAME": "magaza.com.tr.", "@ TXT": "v=spf1 +a +mx +ip4:203.0.113.50 ~all",
		"shop A": "203.0.113.50", "api A": "203.0.113.50", "kampanya CNAME": "magaza.shopify.com.", "_dmarc TXT": "v=DMARC1; p=quarantine",
		"@ CAA": `0 issue "letsencrypt.org"`, "_sip._tcp SRV": "5 5060 sip.magaza.com.tr.",
	}
	if len(recs) != len(want) {
		t.Fatalf("records = %v", recs)
	}
	for k, v := range want {
		if recs[k] != v {
			t.Fatalf("%s = %q, want %q (all %v)", k, recs[k], v, recs)
		}
	}
	mail := meta.Mail
	boxes := mail["mailboxes"].([]any)
	info := boxes[0].(map[string]any)
	if !strings.HasPrefix(info["password_hash"].(string), "{SHA512-CRYPT}$6$") || !slices.Equal(toStrings(info["forward_to"]), []string{"ayse@gmail.com"}) {
		t.Fatalf("info = %v", info)
	}
	if fw := mail["forwarders"].([]any); len(fw) != 1 || fw[0].(map[string]any)["name"] != "webmaster" {
		t.Fatalf("forwarders = %v", fw)
	}
	if len(meta.Certificates) != 1 || meta.Certificates[0]["issuer"] != "Sectigo Limited" {
		t.Fatalf("certificates = %v", meta.Certificates)
	}
	if zones := meta.Migration["zones"].([]any); len(zones) != 1 || zones[0].(map[string]any)["name"] != "magaza.net" {
		t.Fatalf("alias zones = %v", meta.Migration["zones"])
	}

	// Restore both the way the panel does: the site is set up first.
	sm := site.NewManager(sim, "www-data")
	for _, d := range []string{"magaza.com.tr", "blogum.net"} {
		if _, err := sm.Apply(ctx, site.Spec{Domain: d, User: "magaza", PHPVersion: "8.4"}); err != nil {
			t.Fatal(err)
		}
	}
	r, err := m.Restore(ctx, RestoreSpec{
		Chain: []ChainRef{{Key: "mig-1", Name: "magaza.com.tr"}}, Parts: []string{"files", "home", "databases", "mail", "certificates"},
		Site:      SiteRef{Domain: "magaza.com.tr", User: "magaza"},
		Databases: []DatabaseRef{{Server: "mysql", Name: "magaza_blog"}, {Server: "mysql", Name: "magaza_eski"}, {Server: "mysql", Name: "magaza_wp"}},
		DatabaseUsers: []database.UserSpec{
			{Server: "mysql", Name: "magaza_blog", Grants: []database.Grant{{Database: "magaza_blog", Access: "readwrite"}}},
			{Server: "mysql", Name: "magaza_wpu", Grants: []database.Grant{{Database: "magaza_wp", Access: "all"}}},
		},
	})
	if err != nil {
		t.Fatal(err)
	}
	if _, skipped := r.Skipped["databases"]; skipped || slices.Contains(r.Restored, "certificates") {
		// Certificates need the panel's installer, which this test has not.
		t.Fatalf("restore = %+v", r)
	}
	web := "/home/magaza/web/magaza.com.tr"
	if got := read(t, sim, web+"/public_html/shop/index.html"); got != "shop" {
		t.Fatalf("shop = %q", got)
	}
	if got := read(t, sim, web+"/public_html/wp-config.php"); !strings.Contains(got, "define( 'DB_PASSWORD', '"+newPW+"' );") || !strings.Contains(got, "'DB_USER', 'magaza_wpu'") {
		t.Fatalf("wp-config.php = %q", got)
	}
	if got := read(t, sim, web+"/subdomains/api/index.php"); got != "api" {
		t.Fatalf("api = %q", got)
	}
	for p, want := range map[string]string{
		"/home/magaza/public_html": web + "/public_html", "/home/magaza/api": web + "/subdomains/api",
		"/home/magaza/blogum.net": "/home/magaza/web/blogum.net/public_html", web + "/laravel": "/home/magaza/laravel",
	} {
		if got, err := os.Readlink(sim.Path(p)); err != nil || got != want {
			t.Fatalf("%s -> %q (%v), want %s", p, got, err, want)
		}
	}
	if got := read(t, sim, "/home/magaza/laravel/artisan"); got != "#!/usr/bin/env php" {
		t.Fatalf("artisan = %q", got)
	}
	if got := read(t, sim, "/home/magaza/web.cpanel/old.txt"); got != "a folder named web" {
		t.Fatalf("web.cpanel = %q", got)
	}
	for _, gone := range []string{"/home/magaza/.cpanel", "/home/magaza/cpmove-magaza.tar.gz", "/home/magaza/tmp/session", "/home/magaza/mail"} {
		if _, err := os.Lstat(sim.Path(gone)); err == nil {
			t.Fatalf("%s was moved", gone)
		}
	}
	if got := read(t, sim, "/var/vmail/magaza.com.tr/info/Maildir/.Sent/cur/2.eml"); got != "Subject: sent" {
		t.Fatalf("mail = %q", got)
	}
	for _, gone := range []string{"/var/vmail/magaza.com.tr/info/Maildir/dovecot.index", "/var/vmail/magaza.com.tr/info/Maildir/maildirsize", "/var/vmail/magaza.com.tr/satis"} {
		if _, err := os.Lstat(sim.Path(gone)); err == nil {
			t.Fatalf("%s was moved", gone)
		}
	}

	// The addon domain, whose Laravel application sat next to its folder.
	if _, err := m.Restore(ctx, RestoreSpec{
		Chain: []ChainRef{{Key: "mig-1", Name: "blogum.net"}}, Parts: []string{"files", "mail"},
		Site: SiteRef{Domain: "blogum.net", User: "magaza"},
	}); err != nil {
		t.Fatal(err)
	}
	if got := read(t, sim, "/home/magaza/web/blogum.net/public_html/index.php"); !strings.Contains(got, "../laravel/") {
		t.Fatalf("blog = %q", got)
	}
	if got, _ := os.Readlink(sim.Path("/home/magaza/web/blogum.net/laravel")); got != "/home/magaza/laravel" {
		t.Fatalf("blog laravel link = %q", got)
	}
}

func TestOnlyChosenSitesAndPartsMove(t *testing.T) {
	m, sim := newMigrateTest(t)
	cpanelArchive(t, sim.Path(ImportDir+"/cpmove-magaza.tar.gz"), time.Now())
	res, err := m.MigrateConvert(context.Background(), MigrateSpec{
		MigrateSource: MigrateSource{File: "cpmove-magaza.tar.gz"},
		Key:           "mig-2", Account: "magaza", Sites: []string{"blogum.net"}, Parts: []string{"files", "dns"},
	})
	if err != nil {
		t.Fatal(err)
	}
	if len(res.Items) != 1 || res.Items[0].Name != "blogum.net" {
		t.Fatalf("items = %+v", res.Items)
	}
	got := members(t, sim.Path(archivePath("mig-2", "blogum.net")))
	if !slices.Equal(got, []string{"manifest.json", "files.tar.gz", "home.tar.gz", "site.json"}) {
		t.Fatalf("members = %v", got)
	}
	// Without the main site, its document root stays behind.
	home := tarNames(t, sim, archivePath("mig-2", "blogum.net"), "home.tar.gz")
	if slices.Contains(home, "public_html/") || slices.Contains(home, "public_html/index.php") || !slices.Contains(home, "laravel/artisan") {
		t.Fatalf("home = %v", home)
	}
	in, _ := m.inspectFile(sim.Path(archivePath("mig-2", "blogum.net")))
	var meta siteMeta
	_ = json.Unmarshal(in.Meta, &meta)
	// Mail hosted elsewhere keeps its MX record.
	found := false
	for _, r := range meta.DNS["records"].([]any) {
		if r := r.(map[string]any); r["type"] == "MX" && r["content"] == "aspmx.l.google.com." {
			found = true
		}
	}
	if !found || meta.Mail != nil || len(meta.Databases) != 0 {
		t.Fatalf("meta = %+v", meta)
	}
	// No IPv4 given: records of the old server are left out.
	for _, r := range meta.DNS["records"].([]any) {
		if r := r.(map[string]any); r["type"] == "A" {
			t.Fatalf("A record %v kept", r)
		}
	}
}

func TestDatabasePasswordsAreWrittenIntoSettings(t *testing.T) {
	resets := map[string]string{"shop_u": "NEWpass123456789"}
	for name, c := range map[string]struct{ in, want string }{
		"wordpress": {"define('DB_USER', 'shop_u');\ndefine('DB_PASSWORD', \"o'ld\\\"\");", "define('DB_USER', 'shop_u');\ndefine('DB_PASSWORD', \"NEWpass123456789\");"},
		"env":       {"DB_USERNAME=shop_u\nDB_PASSWORD=\"x y\"\n", "DB_USERNAME=shop_u\nDB_PASSWORD=NEWpass123456789\n"},
		"joomla":    {"public $user = 'shop_u';\n\tpublic $password = 'abc';", "public $user = 'shop_u';\n\tpublic $password = 'NEWpass123456789';"},
		"array":     {"'username' => 'shop_u',\n'password' => 'p\\'w',", "'username' => 'shop_u',\n'password' => 'NEWpass123456789',"},
	} {
		got, user, ok := rewriteDBPassword([]byte(c.in), resets)
		if !ok || user != "shop_u" || string(got) != c.want {
			t.Errorf("%s: %q (%v)", name, got, ok)
		}
	}
	// Another user's file, or a file naming two users, is left alone.
	for _, in := range []string{"define('DB_USER', 'other');\ndefine('DB_PASSWORD', 'x');", "'username' => 'shop_u', 'password' => 'a', 'username' => 'b', 'password' => 'c'"} {
		if _, _, ok := rewriteDBPassword([]byte(in), resets); ok {
			t.Errorf("rewrote %q", in)
		}
	}
}

func TestZoneFilesAreRead(t *testing.T) {
	rrs := parseZone(strings.NewReader("$TTL 1h\n$ORIGIN ornek.com.\n@ IN SOA ns1 root ( 1 2 3 4 5 )\n  IN NS ns1\nwww 300 IN A 192.0.2.1\n\tIN AAAA 2001:db8::1\nsub.ornek.com. IN TXT \"a;b\" \"c\" ; comment\n"), "ornek.com")
	if len(rrs) != 5 {
		t.Fatalf("rrs = %+v", rrs)
	}
	if rrs[1].Name != "ornek.com" || rrs[1].TTL != 3600 || rrs[1].Data[0] != "ns1" {
		t.Fatalf("ns = %+v", rrs[1])
	}
	if rrs[3].Name != "www.ornek.com" || rrs[3].Type != "AAAA" || rrs[3].TTL != 3600 {
		t.Fatalf("aaaa = %+v", rrs[3])
	}
	if !slices.Equal(rrs[4].Data, []string{"a;b", "c"}) {
		t.Fatalf("txt = %+v", rrs[4])
	}
}

func toStrings(v any) []string {
	var out []string
	for _, s := range v.([]any) {
		out = append(out, s.(string))
	}
	return out
}

// tarNames lists a .tar.gz member of a site archive.
func tarNames(t *testing.T, sim *host.Simulator, archive, member string) []string {
	t.Helper()
	dir := t.TempDir()
	m := &Manager{}
	if _, err := m.unpack(sim.Path(archive), dir, func(n string) bool { return n == member || n == "manifest.json" }); err != nil {
		t.Fatal(err)
	}
	f, err := os.Open(filepath.Join(dir, member))
	if err != nil {
		t.Fatal(err)
	}
	defer f.Close()
	gz, err := gzip.NewReader(f)
	if err != nil {
		t.Fatal(err)
	}
	var out []string
	tr := tar.NewReader(gz)
	for {
		h, err := tr.Next()
		if err != nil {
			return out
		}
		out = append(out, h.Name)
	}
}
