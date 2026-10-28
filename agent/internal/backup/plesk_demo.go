package backup

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"crypto/ecdsa"
	"crypto/ed25519"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/pem"
	"errors"
	"fmt"
	"io"
	"math/big"
	"regexp"
	"slices"
	"sort"
	"strings"
	"sync"
	"time"

	"golang.org/x/crypto/ssh"
)

// demoPlesk is a made-up Plesk server that answers the commands the agent
// sends one: dry runs connect to it instead of a real server, and the
// tests read subscriptions from it (also through a real SSH server).
// Subscription 1, kafe.com.tr, has an additional domain, a subdomain,
// aliases, mailboxes, MySQL and PostgreSQL databases, zones and a
// certificate; subscription 4, atolye.net, only a static site.
type demoPlesk struct {
	files  map[string]string
	tables map[string][][]string
	// passwords are what mail_auth_view prints.
	passwords [][2]string
	// sql records the queries asked, by tag.
	mu  sync.Mutex
	sql []string
}

func dialDemoPlesk(_ context.Context, c PleskConn) (remote, string, error) {
	key := demoHostKey()
	if c.HostKey != "" && strings.TrimSpace(c.HostKey) != key {
		return nil, "", invalid("%s", errHostKeyChanged)
	}
	return newDemoPlesk(time.Now()), key, nil
}

var demoSeed = sync.OnceValue(func() ed25519.PrivateKey {
	return ed25519.NewKeyFromSeed(bytes.Repeat([]byte{7}, ed25519.SeedSize))
})

// demoHostKey is the demo server's host key.
func demoHostKey() string {
	pk, _ := ssh.NewPublicKey(demoSeed().Public())
	return strings.TrimSpace(string(ssh.MarshalAuthorizedKey(pk)))
}

// demoSigner signs as the demo server.
func demoSigner() ssh.Signer {
	s, _ := ssh.NewSignerFromKey(demoSeed())
	return s
}

func newDemoPlesk(now time.Time) *demoPlesk {
	chain, key := demoCertificate([]string{"kafe.com.tr", "www.kafe.com.tr"}, now)
	ca := chain[strings.Index(chain, "-----END CERTIFICATE-----")+len("-----END CERTIFICATE-----\n"):]
	leaf := strings.TrimSuffix(chain, ca)
	const v = "/var/www/vhosts/kafe.com.tr"
	d := &demoPlesk{
		files: map[string]string{
			v + "/httpdocs/index.php":                                         "<?php\n// WordPress\nrequire __DIR__ . '/wp-blog-header.php';\n",
			v + "/httpdocs/wp-config.php":                                     "<?php\ndefine( 'DB_NAME', 'kafe_wp' );\ndefine( 'DB_USER', 'kafe_wpu' );\ndefine( 'DB_PASSWORD', 'eski-parola' );\ndefine( 'DB_HOST', 'localhost:3306' );\ndefine( 'WP_CONTENT_DIR', '/var/www/vhosts/kafe.com.tr/httpdocs/wp-content' );\n",
			v + "/httpdocs/wp-content/uploads/menu.jpg":                       "JPEG",
			v + "/httpdocs/.htaccess":                                         "RewriteEngine On\n",
			v + "/kafemenu.net/index.php":                                     "<?php require 'config.php';",
			v + "/kafemenu.net/config.php":                                    "<?php\nreturn ['database' => 'kafemenu_db', 'username' => 'kafemenu_u', 'password' => 'menu-eski'];\n",
			v + "/blog.kafe.com.tr/index.html":                                "<h1>Blog</h1>",
			v + "/private/notlar.txt":                                         "backup notes",
			v + "/error_docs/not_found.html":                                  "<h1>404</h1>",
			v + "/logs":                                                       "->/var/www/vhosts/system/kafe.com.tr/logs",
			v + "/tmp/sess_1":                                                 "session",
			v + "/.ssh/authorized_keys":                                       "ssh-ed25519 AAAA",
			"/var/www/vhosts/atolye.net/httpdocs/index.html":                  "<h1>Atolye</h1>",
			"/var/qmail/mailnames/kafe.com.tr/info/Maildir/cur/1.eml":         "Subject: Rezervasyon\n\nMerhaba",
			"/var/qmail/mailnames/kafe.com.tr/info/Maildir/.Sent/cur/2.eml":   "Subject: Re: Rezervasyon\n\nThank you",
			"/var/qmail/mailnames/kafe.com.tr/info/Maildir/dovecot.index.log": "index",
			"/var/qmail/mailnames/kafe.com.tr/info/.qmail":                    "| /usr/bin/deliver",
			"/var/qmail/mailnames/kafemenu.net/menu/Maildir/new/3.eml":        "Subject: Menu\n\nNew menu",
		},
		tables: map[string][][]string{
			// Every row starts with its key: what the WHERE must name for
			// it to be answered ("" for any).
			"subscriptions": {{"", "4", "atolye.net", "atolye", "admin", "18874368", "0"}, {"", "1", "kafe.com.tr", "kafeweb", "mehmet", "268435456", "1"}},
			// subscription; id, name, parent, htype, www_root, php handler, certificate, size
			"domains": {
				{"1", "1", "kafe.com.tr", "0", "vrt_hst", v + "/httpdocs", "plesk-php82-fpm", "11", "268435456"},
				{"1", "2", "kafemenu.net", "0", "vrt_hst", v + "/kafemenu.net", "plesk-php74-fastcgi", "0", "0"},
				{"1", "3", "blog.kafe.com.tr", "1", "vrt_hst", v + "/blog.kafe.com.tr", "plesk-php82-fpm", "0", "0"},
				{"1", "6", "eskikafe.com", "0", "std_fwd", "", "", "0", "0"},
				{"4", "4", "atolye.net", "0", "vrt_hst", "/var/www/vhosts/atolye.net/httpdocs", "plesk-php83-fpm", "0", "18874368"},
			},
			"account":      {{"1", "kafeweb", "/var/www/vhosts/kafe.com.tr", "101"}, {"4", "atolye", "/var/www/vhosts/atolye.net", "102"}},
			"client":       {{"1", "mehmet@kafe.com.tr", "Mehmet Demir"}, {"4", "admin@plesk.eskisunucu.com", "Administrator"}},
			"plan":         {{"1", "Kurumsal"}, {"4", "Default Domain"}},
			"aliases":      {{"1", "1", "kafe.net", "true"}, {"2", "2", "kafemenu.com", "false"}},
			"ips":          {{"", "192.0.2.10", ""}},
			"mail":         {{"1", "1", "info", "true", "1073741824"}, {"1", "1", "siparis", "false", "-1"}, {"2", "2", "menu", "true", "-1"}},
			"forwards":     {{"1", "1", "siparis", "info@kafe.com.tr"}, {"1", "1", "siparis", "mehmet@gmail.com"}},
			"mailaliases":  {{"1", "1", "info", "iletisim"}},
			"databases":    {{"1", "1", "kafe_analytics", "postgresql", "localhost"}, {"2", "2", "kafe_rapor", "mysql", "10.0.0.5"}, {"2", "2", "kafemenu_db", "mysql", "localhost"}, {"1", "1", "kafe_wp", "mysql", "localhost"}},
			"grants":       {{"", "kafe_wpu", "localhost", "kafe_wp"}, {"", "kafemenu_u", "localhost", `kafemenu\_db`}, {"", "kafemenu_u", "%", `kafemenu\_db`}, {"", "baska", "localhost", "baska_db"}},
			"certificates": {{"1", "1", key, leaf, ca}},
			"ftp":          {{"101", "kafe_tasarim"}},
			"dns": {
				{"kafe.com.tr", "kafe.com.tr", "NS", "kafe.com.tr.", "ns1.kafe.com.tr.", ""},
				{"kafe.com.tr", "kafe.com.tr", "NS", "kafe.com.tr.", "ns2.kafe.com.tr.", ""},
				{"kafe.com.tr", "kafe.com.tr", "A", "kafe.com.tr.", "192.0.2.10", ""},
				{"kafe.com.tr", "kafe.com.tr", "AAAA", "kafe.com.tr.", "2001:db8::10", ""},
				{"kafe.com.tr", "kafe.com.tr", "A", "ns1.kafe.com.tr.", "192.0.2.10", ""},
				{"kafe.com.tr", "kafe.com.tr", "CNAME", "www.kafe.com.tr.", "kafe.com.tr.", ""},
				{"kafe.com.tr", "kafe.com.tr", "A", "mail.kafe.com.tr.", "192.0.2.10", ""},
				{"kafe.com.tr", "kafe.com.tr", "A", "webmail.kafe.com.tr.", "192.0.2.10", ""},
				{"kafe.com.tr", "kafe.com.tr", "CNAME", "ftp.kafe.com.tr.", "kafe.com.tr.", ""},
				{"kafe.com.tr", "kafe.com.tr", "A", "blog.kafe.com.tr.", "192.0.2.10", ""},
				{"kafe.com.tr", "kafe.com.tr", "MX", "kafe.com.tr.", "mail.kafe.com.tr.", "10"},
				{"kafe.com.tr", "kafe.com.tr", "TXT", "kafe.com.tr.", "v=spf1 +a +mx +a:plesk.eskisunucu.com +ip4:192.0.2.10 -all", ""},
				{"kafe.com.tr", "kafe.com.tr", "TXT", "_dmarc.kafe.com.tr.", "v=DMARC1; p=none", ""},
				{"kafe.com.tr", "kafe.com.tr", "CNAME", "siparis.kafe.com.tr.", "kafe.myshopify.com.", ""},
				{"kafe.com.tr", "kafe.com.tr", "SRV", "_sip._tcp.kafe.com.tr.", "sip.kafe.com.tr.", "10 5 5060"},
				{"kafe.com.tr", "kafe.com.tr", "CAA", "kafe.com.tr.", "letsencrypt.org", "0 issue"},
				{"kafe.com.tr", "kafe.com.tr", "PTR", "10.2.0.192.in-addr.arpa.", "kafe.com.tr.", ""},
				{"kafemenu.net", "kafemenu.net", "A", "kafemenu.net.", "192.0.2.10", ""},
				{"kafemenu.net", "kafemenu.net", "CNAME", "www.kafemenu.net.", "kafemenu.net.", ""},
				{"kafemenu.net", "kafemenu.net", "MX", "kafemenu.net.", "mx.yandex.net.", "10"},
				{"kafemenu.net", "kafemenu.net", "TXT", "kafemenu.net.", "google-site-verification=abc123", ""},
				{"kafe.net", "kafe.net", "A", "kafe.net.", "192.0.2.10", ""},
				{"kafe.net", "kafe.net", "CNAME", "www.kafe.net.", "kafe.net.", ""},
				{"atolye.net", "atolye.net", "A", "atolye.net.", "192.0.2.10", ""},
			},
		},
		passwords: [][2]string{{"info@kafe.com.tr", "Kahve.2024"}, {"siparis@kafe.com.tr", "x"}, {"menu@kafemenu.net", "Menu 2026 |yeni|"}},
	}
	return d
}

func (d *demoPlesk) close() {}

var demoUserRe = regexp.MustCompile("SHOW CREATE USER '([^']*)'@'([^']*)'")

func (d *demoPlesk) run(ctx context.Context, cmd string, w io.Writer) error {
	if err := ctx.Err(); err != nil {
		return err
	}
	out := func(s string) error {
		_, err := io.WriteString(w, s)
		return err
	}
	f := shellFields(cmd)
	switch {
	case cmd == "id -u":
		return out("0\n")
	case strings.HasPrefix(cmd, `echo "plesk=`):
		return out("plesk=18.0.63 Ubuntu 22.04 1800240819.10\nhost=plesk.eskisunucu.com\nips=192.0.2.10 10.0.0.12 2001:db8::10\n")
	case strings.Contains(cmd, "mail_auth_view"):
		var b strings.Builder
		b.WriteString("+----------------------+-------+----------------------+\n| address              | flags | password             |\n+----------------------+-------+----------------------+\n")
		for _, p := range d.passwords {
			fmt.Fprintf(&b, "| %-20s |       | %-20s |\n", p[0], p[1])
		}
		b.WriteString("+----------------------+-------+----------------------+\n")
		return out(b.String())
	case len(f) > 4 && f[0] == "plesk" && f[1] == "db" && f[2] == "-B":
		return out(d.query(f[len(f)-1]))
	case strings.HasPrefix(cmd, "(plesk db dump "):
		name := shellFields(strings.TrimPrefix(cmd, "(plesk db dump "))[0]
		return d.gzip(w, func(z io.Writer) error {
			_, err := fmt.Fprintf(z, "-- MySQL dump 10.13  Distrib 8.0.39\n-- Host: localhost    Database: %s\nCREATE TABLE `posts` (`id` int);\nINSERT INTO `posts` VALUES (1);\n-- Dump completed on 2026-10-28 14:00:00\n", name)
			return err
		})
	case len(f) > 2 && f[0] == "tar" && f[1] == "-C":
		return d.tar(w, f)
	case len(f) > 3 && f[0] == "crontab":
		if f[3] == "kafeweb" {
			return out("MAILTO=\"\"\n*/10 * * * * /opt/plesk/php/8.2/bin/php -f /var/www/vhosts/kafe.com.tr/httpdocs/wp-cron.php\n")
		}
		return nil
	case cmd == "sudo -n true":
		return nil
	}
	return fmt.Errorf("sh: 1: %s: not found (exit status 127)", f[0])
}

// query answers a tagged query: the rows of its table whose key appears
// in the query's WHERE.
func (d *demoPlesk) query(sql string) string {
	tag, _, _ := strings.Cut(strings.TrimPrefix(sql, "/*serfex:"), "*/")
	d.mu.Lock()
	d.sql = append(d.sql, tag)
	d.mu.Unlock()
	if tag == "user" {
		m := demoUserRe.FindStringSubmatch(sql)
		if m == nil {
			return ""
		}
		id := "`" + m[1] + "`@`" + m[2] + "`"
		switch m[1] {
		case "kafe_wpu":
			return "CREATE USER " + id + " IDENTIFIED WITH 'mysql_native_password' AS '*0A1B2C3D4E5F60718293A4B5C6D7E8F901234567' REQUIRE NONE PASSWORD EXPIRE DEFAULT ACCOUNT UNLOCK\n" +
				"GRANT USAGE ON *.* TO " + id + "\nGRANT ALL PRIVILEGES ON `kafe_wp`.* TO " + id + "\n"
		case "kafemenu_u":
			return "CREATE USER " + id + " IDENTIFIED WITH 'caching_sha2_password' AS 0x244124303035017A7A7A REQUIRE NONE\n" +
				"GRANT USAGE ON *.* TO " + id + "\nGRANT SELECT, INSERT, UPDATE, DELETE ON `kafemenu\\\\_db`.* TO " + id + "\n"
		}
		return ""
	}
	rows, ok := d.tables[tag]
	if !ok {
		return ""
	}
	keys := map[string]bool{}
	where := ""
	if _, w, ok := strings.Cut(sql, " WHERE "); ok {
		where = w
		for _, m := range regexp.MustCompile(`'([^']*)'|\b(\d+)\b`).FindAllStringSubmatch(w, -1) {
			keys[m[1]+m[2]] = true
		}
	}
	var b strings.Builder
	for _, row := range rows {
		if where != "" && row[0] != "" && !keys[row[0]] {
			continue
		}
		cells := make([]string, len(row)-1)
		for i, c := range row[1:] {
			cells[i] = strings.NewReplacer(`\`, `\\`, "\n", `\n`, "\t", `\t`).Replace(c)
		}
		b.WriteString(strings.Join(cells, "\t") + "\n")
	}
	return b.String()
}

func (d *demoPlesk) gzip(w io.Writer, fn func(io.Writer) error) error {
	z := gzip.NewWriter(w)
	if err := fn(z); err != nil {
		return err
	}
	return z.Close()
}

// tar answers "tar -C <dir> [options] -cf - <members> | gzip -1".
func (d *demoPlesk) tar(w io.Writer, f []string) error {
	dir := strings.TrimSuffix(f[2], "/")
	var excludes, members []string
	for i, a := range f {
		switch {
		case strings.HasPrefix(a, "--exclude=./"):
			excludes = append(excludes, strings.TrimPrefix(a, "--exclude=./"))
		case a == "-" && i > 0 && f[i-1] == "-cf":
		case i > 2 && !strings.HasPrefix(a, "-") && a != "|" && a != "gzip" && f[i-1] != "-C":
			members = append(members, a)
		}
	}
	var names []string
	dirs := map[string]bool{}
	for p := range d.files {
		rel, ok := strings.CutPrefix(p, dir+"/")
		if !ok {
			continue
		}
		top, _, _ := strings.Cut(rel, "/")
		if slices.Contains(excludes, top) || !slices.Contains(members, ".") && !slices.Contains(members, top) {
			continue
		}
		names = append(names, rel)
		for p := rel; strings.Contains(p, "/"); {
			p = p[:strings.LastIndex(p, "/")]
			dirs[p] = true
		}
	}
	for p := range dirs {
		names = append(names, p+"/")
	}
	sort.Strings(names)
	if len(names) == 0 {
		return errors.New("tar: Cannot open: No such file or directory")
	}
	return d.gzip(w, func(z io.Writer) error {
		tw := tar.NewWriter(z)
		prefix := ""
		if slices.Contains(members, ".") {
			prefix = "./"
		}
		for _, n := range names {
			h := &tar.Header{Name: prefix + n, Mode: 0o644, ModTime: time.Date(2026, 10, 1, 9, 0, 0, 0, time.UTC), Uid: 10001, Gid: 1003}
			body := d.files[dir+"/"+n]
			switch {
			case strings.HasSuffix(n, "/"):
				h.Typeflag, h.Mode = tar.TypeDir, 0o755
			case strings.HasPrefix(body, "->"):
				h.Typeflag, h.Linkname = tar.TypeSymlink, strings.TrimPrefix(body, "->")
			default:
				h.Typeflag, h.Size = tar.TypeReg, int64(len(body))
			}
			if err := tw.WriteHeader(h); err != nil {
				return err
			}
			if h.Typeflag == tar.TypeReg {
				if _, err := io.WriteString(tw, body); err != nil {
					return err
				}
			}
		}
		return tw.Close()
	})
}

// shellFields splits a command as sh would, for the commands the agent
// sends: words, and '...' quoting joined with '\”.
func shellFields(s string) []string {
	var out []string
	var cur strings.Builder
	in, word := false, false
	for i := 0; i < len(s); i++ {
		c := s[i]
		switch {
		case in && c == '\'':
			in = false
		case in:
			cur.WriteByte(c)
		case c == '\'':
			in, word = true, true
		case c == '\\' && i+1 < len(s):
			i++
			cur.WriteByte(s[i])
			word = true
		case c == ' ' || c == '\t' || c == '\n':
			if word {
				out = append(out, cur.String())
				cur.Reset()
				word = false
			}
		default:
			cur.WriteByte(c)
			word = true
		}
	}
	if word {
		out = append(out, cur.String())
	}
	if len(out) == 0 {
		out = []string{""}
	}
	return out
}

// demoCertificate is a certificate for names from a made-up "Let's
// Encrypt" authority, with its chain, and its key.
func demoCertificate(names []string, now time.Time) (chain, key string) {
	caKey, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	ca := &x509.Certificate{
		SerialNumber: big.NewInt(1), Subject: pkix.Name{Organization: []string{"Let's Encrypt"}, CommonName: "E6"},
		NotBefore: now.Add(-24 * time.Hour), NotAfter: now.Add(365 * 24 * time.Hour), IsCA: true, BasicConstraintsValid: true,
		KeyUsage: x509.KeyUsageCertSign,
	}
	caDER, _ := x509.CreateCertificate(rand.Reader, ca, ca, &caKey.PublicKey, caKey)
	caCert, _ := x509.ParseCertificate(caDER)
	leafKey, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	leaf := &x509.Certificate{
		SerialNumber: big.NewInt(2), Subject: pkix.Name{CommonName: names[0]}, DNSNames: names,
		NotBefore: now.Add(-24 * time.Hour), NotAfter: now.Add(75 * 24 * time.Hour), KeyUsage: x509.KeyUsageDigitalSignature,
		ExtKeyUsage: []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
	}
	leafDER, _ := x509.CreateCertificate(rand.Reader, leaf, caCert, &leafKey.PublicKey, caKey)
	kb, _ := x509.MarshalECPrivateKey(leafKey)
	return string(pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: leafDER})) + string(pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: caDER})),
		string(pem.EncodeToMemory(&pem.Block{Type: "EC PRIVATE KEY", Bytes: kb}))
}
