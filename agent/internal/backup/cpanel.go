package backup

import (
	"archive/tar"
	"bufio"
	"bytes"
	"compress/gzip"
	"crypto/x509"
	"encoding/hex"
	"encoding/pem"
	"errors"
	"io"
	"os"
	"path"
	"regexp"
	"slices"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/calltech-app/serfex/agent/internal/cert"
	"github.com/calltech-app/serfex/agent/internal/database"
	"github.com/calltech-app/serfex/agent/internal/hostname"
	"github.com/calltech-app/serfex/agent/internal/site"
)

// Moving accounts from cPanel. An account archive is what WHM's transfer
// tool and pkgacct make (cpmove-<user>.tar.gz) or cPanel's own full
// backup (backup-<date>_<user>.tar.gz); both hold one directory:
//
//	cp/<user>                  the account (DNS=, IP=, CONTACTEMAIL=, PLAN=)
//	userdata/main              its domains: main, addon, parked, subdomains
//	userdata/<domain>          one domain: documentroot, phpversion, ip
//	homedir/ (or homedir.tar)  the home directory: sites, mail/, etc/
//	mysql/<db>.sql             database dumps; mysql.sql the users and grants
//	dnszones/<domain>.db       DNS zones (BIND)
//	va/<domain>                mail forwarders (valiases)
//	apache_tls/<domain>        SSL key, certificate and chain
//	cron/<user>, proftpdpasswd cron jobs and FTP accounts
//
// homedir/mail/<domain>/<box>/ is a Maildir++ mailbox; its password hash
// is in homedir/etc/<domain>/shadow and its quota in .../quota.

// MigrationNote is something the panel tells the administrator about a
// migration: a code it words itself, and what it is about.
type MigrationNote struct {
	Code    string `json:"code"`
	Subject string `json:"subject,omitempty"`
}

// MigrationPlan is what an account archive holds and how it lands here.
type MigrationPlan struct {
	Source        string                `json:"source"`
	User          string                `json:"user"`
	Contact       string                `json:"contact,omitempty"`
	Package       string                `json:"package,omitempty"`
	MainDomain    string                `json:"main_domain"`
	OldIPs        []string              `json:"old_ips"`
	Sites         []MigrationSite       `json:"sites"`
	Databases     []MigrationDatabase   `json:"databases"`
	DatabaseUsers []MigrationDBUser     `json:"database_users"`
	Mail          []MigrationMailDomain `json:"mail"`
	Zones         []MigrationZone       `json:"zones"`
	Cron          []string              `json:"cron"`
	FTP           []string              `json:"ftp"`
	// Size is the archive's size; Unpacked what it holds, unpacked.
	Size          int64           `json:"size"`
	Unpacked      int64           `json:"unpacked"`
	FilesSize     int64           `json:"files_size"`
	MailSize      int64           `json:"mail_size"`
	DatabasesSize int64           `json:"databases_size"`
	Notes         []MigrationNote `json:"notes"`
}

// MigrationSite is a domain of the account with its own document root:
// the main domain or an addon domain.
type MigrationSite struct {
	Domain string `json:"domain"`
	// Kind is "main" or "addon".
	Kind string `json:"kind"`
	// DocRoot is relative to the home directory, like public_html.
	DocRoot     string               `json:"docroot"`
	PHP         string               `json:"php,omitempty"`
	Aliases     []string             `json:"aliases"`
	Subdomains  []MigrationSubdomain `json:"subdomains"`
	Certificate *MigrationCert       `json:"certificate,omitempty"`
}

// MigrationSubdomain is a subdomain served from its own folder.
type MigrationSubdomain struct {
	Name    string `json:"name"`
	DocRoot string `json:"docroot"`
	// WebRoot is its folder in the Serfex site, relative to the site's.
	WebRoot string `json:"web_root"`
}

// MigrationCert is a certificate that can be used here.
type MigrationCert struct {
	Names    []string  `json:"names"`
	Issuer   string    `json:"issuer"`
	NotAfter time.Time `json:"not_after"`
}

// MigrationDatabase is a MySQL database and the site that uses it.
type MigrationDatabase struct {
	Name string `json:"name"`
	Size int64  `json:"size"`
	Site string `json:"site"`
}

// MigrationDBUser is a database user.
type MigrationDBUser struct {
	Name      string   `json:"name"`
	Databases []string `json:"databases"`
	// KeepsPassword is false when this server cannot use the stored
	// password: the user gets a new one, written into the site's settings.
	KeepsPassword bool     `json:"keeps_password"`
	RemoteHosts   []string `json:"remote_hosts,omitempty"`
}

// MigrationMailDomain is a domain's mail.
type MigrationMailDomain struct {
	Domain string `json:"domain"`
	// Site is the site the domain's mail goes with; empty when Serfex
	// cannot hold it (the mail of an alias or a subdomain).
	Site       string             `json:"site"`
	Mailboxes  []MigrationMailbox `json:"mailboxes"`
	Forwarders int                `json:"forwarders"`
}

// MigrationMailbox is a mailbox.
type MigrationMailbox struct {
	Name          string `json:"name"`
	QuotaMB       int    `json:"quota_mb"`
	Size          int64  `json:"size"`
	KeepsPassword bool   `json:"keeps_password"`
	// Mdbox mailboxes keep their mail in a format Serfex does not read.
	Mdbox bool `json:"mdbox,omitempty"`
}

// MigrationZone is a DNS zone.
type MigrationZone struct {
	Name    string `json:"name"`
	Site    string `json:"site"`
	Records int    `json:"records"`
}

// cpAccount is everything read from an archive, secrets included.
type cpAccount struct {
	file     string
	homedir  string
	user     string
	cp       map[string]string
	main     cpYAML
	userdata map[string]cpYAML
	zones    map[string][]zoneRR
	mysqlSQL []byte
	dbSizes  map[string]int64
	psql     []string
	valiases map[string][]byte
	shadow   map[string]map[string]string
	quota    map[string]map[string]int64
	passwd   map[string][]string
	boxSize  map[string]map[string]int64
	mdbox    map[string]map[string]bool
	tls      map[string][]byte
	sslCerts [][]byte
	sslKeys  [][]byte
	cron     []string
	ftp      []string
	configs  []cpConfig
	size     int64
	unpacked int64
	homeSize int64
	mailSize int64
	dbSize   int64
}

// cpConfig is a settings file of a site (wp-config.php, .env): the words
// in it, to tell which databases the site uses.
type cpConfig struct {
	path  string
	words map[string]bool
}

// configNames are the settings files whose words are read.
var configNames = map[string]bool{
	"wp-config.php": true, ".env": true, "configuration.php": true, "config.php": true, "settings.php": true,
	"env.php": true, "local.xml": true, "parameters.yml": true, "LocalSettings.php": true, "config.inc.php": true,
	"database.php": true, "db.php": true, "connect.php": true, "settings.inc.php": true, "parameters.php": true,
}

const configLimit = 256 << 10

var wordRe = regexp.MustCompile(`[A-Za-z0-9_]{3,64}`)

// errNotCPanel is returned for an archive that is not a cPanel account.
var errNotCPanel = errors.New("the file is not a cPanel account backup (cpmove or full backup)")

// walkArchive calls fn for every entry of a cPanel archive, with its name
// below the archive's top directory. homedir.tar is read as homedir/.
func walkArchive(file string, fn func(name string, hdr *tar.Header, r io.Reader) error) error {
	f, err := os.Open(file)
	if err != nil {
		return err
	}
	defer f.Close()
	br := bufio.NewReaderSize(f, 1<<20)
	var r io.Reader = br
	if head, _ := br.Peek(2); len(head) == 2 && head[0] == 0x1f && head[1] == 0x8b {
		gz, err := gzip.NewReader(br)
		if err != nil {
			return errNotCPanel
		}
		defer gz.Close()
		r = gz
	}
	tr := tar.NewReader(r)
	prefix := ""
	first := true
	for {
		hdr, err := tr.Next()
		if errors.Is(err, io.EOF) {
			if first {
				return errNotCPanel
			}
			return nil
		}
		if err != nil {
			if first {
				return errNotCPanel
			}
			return errors.New("the archive is damaged: " + err.Error())
		}
		name := strings.TrimPrefix(hdr.Name, "./")
		if first {
			first = false
			top, _, _ := strings.Cut(name, "/")
			prefix = top + "/"
		}
		rel, ok := strings.CutPrefix(name, prefix)
		if !ok || rel == "" {
			continue
		}
		if rel == "homedir.tar" && hdr.Typeflag == tar.TypeReg {
			inner := tar.NewReader(tr)
			for {
				ih, err := inner.Next()
				if errors.Is(err, io.EOF) {
					break
				}
				if err != nil {
					return errors.New("the archive's homedir.tar is damaged: " + err.Error())
				}
				in := strings.TrimPrefix(strings.TrimPrefix(ih.Name, "./"), "/")
				if in == "" || in == "." {
					continue
				}
				if err := fn("homedir/"+in, ih, inner); err != nil {
					return err
				}
			}
			continue
		}
		if err := fn(rel, hdr, tr); err != nil {
			return err
		}
	}
}

// readCPanel reads an account archive's description (one pass, without
// unpacking it).
func readCPanel(file string) (*cpAccount, error) {
	a := &cpAccount{
		file: file, cp: map[string]string{}, userdata: map[string]cpYAML{}, zones: map[string][]zoneRR{},
		dbSizes: map[string]int64{}, valiases: map[string][]byte{}, shadow: map[string]map[string]string{},
		quota: map[string]map[string]int64{}, passwd: map[string][]string{}, boxSize: map[string]map[string]int64{},
		mdbox: map[string]map[string]bool{}, tls: map[string][]byte{},
	}
	if fi, err := os.Stat(file); err == nil {
		a.size = fi.Size()
	}
	small := func(r io.Reader, limit int64) []byte {
		b, _ := io.ReadAll(io.LimitReader(r, limit))
		return b
	}
	err := walkArchive(file, func(name string, hdr *tar.Header, r io.Reader) error {
		if hdr.Typeflag == tar.TypeReg {
			a.unpacked += hdr.Size
		}
		dir, base := path.Split(name)
		switch {
		case dir == "cp/" && hdr.Typeflag == tar.TypeReg:
			a.user = base
			for _, line := range strings.Split(string(small(r, 1<<20)), "\n") {
				if k, v, ok := strings.Cut(strings.TrimSpace(line), "="); ok {
					a.cp[k] = v
				}
			}
		case dir == "userdata/" && hdr.Typeflag == tar.TypeReg:
			if base == "main" {
				a.main = parseCPYAML(bytes.NewReader(small(r, 4<<20)))
			} else if hostname.Validate(base) == nil {
				a.userdata[base] = parseCPYAML(bytes.NewReader(small(r, 1<<20)))
			}
		case dir == "dnszones/" && strings.HasSuffix(base, ".db"):
			d := strings.TrimSuffix(base, ".db")
			if hostname.Validate(d) == nil {
				a.zones[d] = parseZone(bytes.NewReader(small(r, 4<<20)), d)
			}
		case name == "mysql.sql":
			a.mysqlSQL = small(r, 16<<20)
		case dir == "mysql/" && strings.HasSuffix(base, ".sql"):
			a.dbSizes[strings.TrimSuffix(base, ".sql")] = hdr.Size
			a.dbSize += hdr.Size
		case dir == "psql/" && hdr.Typeflag == tar.TypeReg:
			a.psql = append(a.psql, strings.TrimSuffix(base, ".tar"))
		case dir == "va/" && hdr.Typeflag == tar.TypeReg:
			a.valiases[base] = small(r, 4<<20)
		case dir == "apache_tls/" && hdr.Typeflag == tar.TypeReg:
			a.tls[base] = small(r, 1<<20)
		case dir == "sslcerts/" && strings.HasSuffix(base, ".crt"):
			a.sslCerts = append(a.sslCerts, small(r, 1<<20))
		case dir == "sslkeys/" && strings.HasSuffix(base, ".key"):
			a.sslKeys = append(a.sslKeys, small(r, 1<<20))
		case dir == "cron/" && hdr.Typeflag == tar.TypeReg:
			for _, line := range strings.Split(string(small(r, 1<<20)), "\n") {
				line = strings.TrimSpace(line)
				if line != "" && !strings.HasPrefix(line, "#") && !strings.Contains(line, "=") {
					a.cron = append(a.cron, line)
				}
			}
		case name == "proftpdpasswd":
			for _, line := range strings.Split(string(small(r, 1<<20)), "\n") {
				if n, _, ok := strings.Cut(line, ":"); ok && n != "" {
					a.ftp = append(a.ftp, n)
				}
			}
		case strings.HasPrefix(name, "homedir/"):
			a.readHome(strings.TrimPrefix(name, "homedir/"), hdr, r)
		}
		return nil
	})
	if err != nil {
		return nil, err
	}
	if a.user == "" || (a.main == nil && len(a.userdata) == 0) {
		return nil, errNotCPanel
	}
	if a.homedir = a.userdataOf(a.mainDomain()).str("homedir"); a.homedir == "" {
		a.homedir = "/home/" + a.user
	}
	// The account's own FTP login is its cPanel login, not an FTP account.
	a.ftp = slices.DeleteFunc(a.ftp, func(n string) bool { return n == a.user || strings.HasPrefix(n, a.user+"_logs") })
	return a, nil
}

// readHome notes what a file of the home directory says about mail and
// sites.
func (a *cpAccount) readHome(rel string, hdr *tar.Header, r io.Reader) {
	parts := strings.Split(strings.TrimSuffix(rel, "/"), "/")
	switch {
	case len(parts) == 3 && parts[0] == "etc" && hdr.Typeflag == tar.TypeReg && hostname.Validate(parts[1]) == nil:
		d := parts[1]
		b, _ := io.ReadAll(io.LimitReader(r, 4<<20))
		for _, line := range strings.Split(string(b), "\n") {
			f := strings.Split(strings.TrimSpace(line), ":")
			if len(f) < 2 || f[0] == "" {
				continue
			}
			switch parts[2] {
			case "shadow":
				if a.shadow[d] == nil {
					a.shadow[d] = map[string]string{}
				}
				a.shadow[d][f[0]] = f[1]
			case "quota":
				if a.quota[d] == nil {
					a.quota[d] = map[string]int64{}
				}
				n, _ := strconv.ParseInt(strings.TrimSpace(f[1]), 10, 64)
				a.quota[d][f[0]] = n
			case "passwd":
				a.passwd[d] = append(a.passwd[d], f[0])
			}
		}
		return
	case len(parts) >= 3 && parts[0] == "mail" && hostname.Validate(parts[1]) == nil && !strings.HasPrefix(parts[1], "."):
		d, box := parts[1], parts[2]
		if hdr.Typeflag == tar.TypeReg {
			if a.boxSize[d] == nil {
				a.boxSize[d] = map[string]int64{}
			}
			a.boxSize[d][box] += hdr.Size
			a.mailSize += hdr.Size
		}
		if len(parts) >= 4 && parts[3] == "storage" {
			if a.mdbox[d] == nil {
				a.mdbox[d] = map[string]bool{}
			}
			a.mdbox[d][box] = true
		}
		return
	}
	if hdr.Typeflag != tar.TypeReg || (len(parts) > 0 && parts[0] == "mail") {
		return
	}
	a.homeSize += hdr.Size
	if configNames[path.Base(rel)] && hdr.Size <= configLimit {
		b, _ := io.ReadAll(io.LimitReader(r, configLimit))
		words := map[string]bool{}
		for _, w := range wordRe.FindAllString(string(b), -1) {
			words[w] = true
		}
		a.configs = append(a.configs, cpConfig{path: rel, words: words})
	}
}

func (a *cpAccount) mainDomain() string {
	if d := a.main.str("main_domain"); d != "" {
		return strings.ToLower(d)
	}
	return strings.ToLower(a.cp["DNS"])
}

func (a *cpAccount) userdataOf(d string) cpYAML {
	if u, ok := a.userdata[d]; ok {
		return u
	}
	return cpYAML{}
}

// relHome makes a path of the old server relative to the home directory;
// false when it is outside.
func (a *cpAccount) relHome(p string) (string, bool) {
	p = path.Clean(p)
	for _, h := range []string{a.homedir, "/home/" + a.user} {
		if p == h {
			return "", true
		}
		if r, ok := strings.CutPrefix(p, h+"/"); ok {
			return r, true
		}
	}
	return "", false
}

// phpVersion turns "ea-php81" or "alt-php74" into "8.1".
func phpVersion(v string) string {
	m := regexp.MustCompile(`php(\d)(\d{1,2})$`).FindStringSubmatch(v)
	if m == nil {
		return ""
	}
	return m[1] + "." + m[2]
}

// plan describes the account as it would land here.
func (a *cpAccount) plan(now time.Time) MigrationPlan {
	p := MigrationPlan{
		Source: "cpanel", User: a.user, Contact: a.cp["CONTACTEMAIL"], Package: a.cp["PLAN"],
		MainDomain: a.mainDomain(), Size: a.size, Unpacked: a.unpacked, FilesSize: a.homeSize, MailSize: a.mailSize,
		DatabasesSize: a.dbSize, OldIPs: a.oldIPs(), Cron: a.cron, FTP: a.ftp,
		Sites: []MigrationSite{}, Databases: []MigrationDatabase{}, DatabaseUsers: []MigrationDBUser{},
		Mail: []MigrationMailDomain{}, Zones: []MigrationZone{}, Notes: []MigrationNote{},
	}
	if p.Cron == nil {
		p.Cron = []string{}
	}
	if p.FTP == nil {
		p.FTP = []string{}
	}
	remark := func(code, subject string) { p.Notes = append(p.Notes, MigrationNote{Code: code, Subject: subject}) }
	if site.ValidateUser(a.user) != nil {
		remark("bad-user", a.user)
	}

	// Sites: the main domain and addon domains.
	addons := a.main.dict("addon_domains")
	linked := map[string]bool{}
	for _, sub := range addons {
		linked[strings.ToLower(sub)] = true
	}
	domains := []string{p.MainDomain}
	addonNames := make([]string, 0, len(addons))
	for d := range addons {
		addonNames = append(addonNames, strings.ToLower(d))
	}
	sort.Strings(addonNames)
	domains = append(domains, addonNames...)
	for i, d := range domains {
		if hostname.Validate(d) != nil {
			remark("bad-domain", d)
			continue
		}
		u := a.userdataOf(d)
		if i > 0 {
			// An addon domain's settings are kept under its linked subdomain.
			if _, ok := a.userdata[d]; !ok {
				u = a.userdataOf(strings.ToLower(addons[d]))
			}
		}
		docroot, ok := a.relHome(u.str("documentroot"))
		if !ok || docroot == "" {
			if i == 0 {
				docroot = "public_html"
			} else {
				remark("no-docroot", d)
				continue
			}
		}
		s := MigrationSite{Domain: d, Kind: "addon", DocRoot: docroot, PHP: phpVersion(u.str("phpversion")), Aliases: []string{}, Subdomains: []MigrationSubdomain{}}
		if i == 0 {
			s.Kind = "main"
			for _, parked := range a.main.list("parked_domains") {
				if parked = strings.ToLower(parked); hostname.Validate(parked) == nil {
					s.Aliases = append(s.Aliases, parked)
				}
			}
		}
		if c := a.certFor(d, now); c != nil {
			s.Certificate = &MigrationCert{Names: c.leaf.DNSNames, Issuer: issuerName(c.leaf), NotAfter: c.leaf.NotAfter.UTC()}
		}
		p.Sites = append(p.Sites, s)
	}

	// Subdomains, under the site whose domain they end in.
	subs := a.main.list("sub_domains")
	sort.Strings(subs)
	for _, sub := range subs {
		sub = strings.ToLower(sub)
		if linked[sub] || hostname.Validate(sub) != nil {
			continue
		}
		owner := -1
		for i, s := range p.Sites {
			if strings.HasSuffix(sub, "."+s.Domain) && (owner < 0 || len(s.Domain) > len(p.Sites[owner].Domain)) {
				owner = i
			}
		}
		if owner < 0 {
			remark("subdomain-without-site", sub)
			continue
		}
		s := &p.Sites[owner]
		docroot, ok := a.relHome(a.userdataOf(sub).str("documentroot"))
		if !ok || docroot == "" || isUnder(s.DocRoot, docroot) && s.DocRoot != docroot {
			remark("subdomain-docroot", sub)
			continue
		}
		webRoot := "subdomains/" + strings.TrimSuffix(sub, "."+s.Domain)
		if rest, ok := under(docroot, s.DocRoot); ok {
			webRoot = path.Join(site.DefaultWebRoot, rest)
		}
		s.Subdomains = append(s.Subdomains, MigrationSubdomain{Name: sub, DocRoot: docroot, WebRoot: webRoot})
	}

	// Databases, with the site whose settings name them.
	users := a.dbUsers()
	names := make([]string, 0, len(a.dbSizes))
	for n := range a.dbSizes {
		names = append(names, n)
	}
	sort.Strings(names)
	for _, n := range names {
		if database.ValidateDatabaseName(n) != nil {
			remark("bad-database", n)
			continue
		}
		p.Databases = append(p.Databases, MigrationDatabase{Name: n, Size: a.dbSizes[n], Site: a.siteUsing(p.Sites, n)})
	}
	for _, n := range a.psql {
		remark("postgresql", n)
	}
	for _, u := range users {
		if database.ValidateUserName(u.name) != nil {
			remark("bad-database-user", u.name)
			continue
		}
		mu := MigrationDBUser{Name: u.name, Databases: []string{}, RemoteHosts: u.remote}
		for _, g := range u.grants(p.Databases) {
			mu.Databases = append(mu.Databases, g.Database)
		}
		// The account's own MySQL login only when a site uses it.
		if u.name == a.user && a.siteUsing(p.Sites, u.name) == "" {
			continue
		}
		if len(mu.Databases) == 0 {
			continue
		}
		p.DatabaseUsers = append(p.DatabaseUsers, mu)
	}

	// Mail.
	mailDomains := map[string]bool{}
	for d := range a.passwd {
		mailDomains[d] = true
	}
	for d := range a.valiases {
		if len(a.forwarders(d)) > 0 {
			mailDomains[d] = true
		}
	}
	mds := make([]string, 0, len(mailDomains))
	for d := range mailDomains {
		mds = append(mds, d)
	}
	sort.Strings(mds)
	for _, d := range mds {
		md := MigrationMailDomain{Domain: d, Mailboxes: []MigrationMailbox{}, Forwarders: len(a.forwarders(d))}
		for _, s := range p.Sites {
			if s.Domain == d {
				md.Site = d
			}
		}
		boxes := slices.Clone(a.passwd[d])
		sort.Strings(boxes)
		for _, b := range boxes {
			if !validLocal(b) {
				remark("bad-mailbox", b+"@"+d)
				continue
			}
			_, keeps := mailHash(a.shadow[d][b])
			md.Mailboxes = append(md.Mailboxes, MigrationMailbox{
				Name: b, QuotaMB: quotaMB(a.quota[d][b]), Size: a.boxSize[d][b], KeepsPassword: keeps, Mdbox: a.mdbox[d][b],
			})
		}
		if md.Site == "" {
			remark("mail-without-site", d)
		}
		p.Mail = append(p.Mail, md)
	}

	// Zones of the sites and their aliases.
	for _, s := range p.Sites {
		for _, z := range append([]string{s.Domain}, s.Aliases...) {
			if rrs, ok := a.zones[z]; ok {
				p.Zones = append(p.Zones, MigrationZone{Name: z, Site: s.Domain, Records: len(rrs)})
			}
		}
	}
	return p
}

// isUnder tells whether p is dir or inside it.
func isUnder(p, dir string) bool {
	_, ok := under(p, dir)
	return ok
}

// under returns p relative to dir.
func under(p, dir string) (string, bool) {
	if p == dir {
		return "", true
	}
	if dir == "" {
		return p, true
	}
	r, ok := strings.CutPrefix(p, dir+"/")
	return r, ok
}

var localRe = regexp.MustCompile(`^[a-z0-9](?:[a-z0-9._-]{0,62}[a-z0-9])?$`)

func validLocal(n string) bool { return localRe.MatchString(n) && !strings.Contains(n, "..") }

func quotaMB(b int64) int {
	if b <= 0 {
		return 0
	}
	return int(max((b+(1<<20)-1)>>20, 1))
}

// mailHash turns a cPanel shadow hash into Dovecot's form; false when it
// cannot be used here.
func mailHash(h string) (string, bool) {
	h = strings.TrimPrefix(h, "!")
	scheme := map[string]string{"$6$": "SHA512-CRYPT", "$5$": "SHA256-CRYPT", "$1$": "MD5-CRYPT", "$2y$": "BLF-CRYPT", "$2b$": "BLF-CRYPT", "$2a$": "BLF-CRYPT"}
	for prefix, s := range scheme {
		if strings.HasPrefix(h, prefix) && len(h) >= 20 && len(h) <= 200 && !strings.ContainsAny(h, " \t:") {
			return "{" + s + "}" + h, true
		}
	}
	return "", false
}

// oldIPs are the addresses the account had on the old server.
func (a *cpAccount) oldIPs() []string {
	seen := map[string]bool{}
	out := []string{}
	add := func(ip string) {
		ip = strings.TrimSpace(ip)
		if ip != "" && !seen[ip] && regexp.MustCompile(`^[0-9a-fA-F:.]+$`).MatchString(ip) {
			seen[ip] = true
			out = append(out, ip)
		}
	}
	add(a.cp["IP"])
	ds := make([]string, 0, len(a.userdata))
	for d := range a.userdata {
		ds = append(ds, d)
	}
	sort.Strings(ds)
	for _, d := range ds {
		add(a.userdata[d].str("ip"))
		for ip := range a.userdata[d].dict("ipv6") {
			add(ip)
		}
	}
	return out
}

// siteUsing is the site whose settings files name word, or "".
func (a *cpAccount) siteUsing(sites []MigrationSite, word string) string {
	best, bestLen := "", -1
	for _, c := range a.configs {
		if !c.words[word] {
			continue
		}
		for _, s := range sites {
			if isUnder(c.path, s.DocRoot) && len(s.DocRoot) > bestLen {
				best, bestLen = s.Domain, len(s.DocRoot)
			}
		}
	}
	return best
}

// forwarders reads a domain's valiases: name and destinations, mail
// addresses only (pipes, :fail: and autoresponders are left out).
func (a *cpAccount) forwarders(d string) [][2]string {
	var out [][2]string
	for _, line := range strings.Split(string(a.valiases[d]), "\n") {
		from, to, ok := strings.Cut(line, ":")
		if !ok {
			continue
		}
		from = strings.ToLower(strings.TrimSpace(from))
		local, dom, ok := strings.Cut(from, "@")
		if !ok || dom != d || !validLocal(local) {
			continue
		}
		var dests []string
		for _, t := range strings.Split(to, ",") {
			t = strings.ToLower(strings.Trim(strings.TrimSpace(t), `"`))
			if strings.Count(t, "@") == 1 && !strings.ContainsAny(t, " |/:") {
				dests = append(dests, t)
			}
		}
		if len(dests) > 0 {
			out = append(out, [2]string{local, strings.Join(dests, ",")})
		}
	}
	return out
}

// cpDBUser is a MySQL user from mysql.sql.
type cpDBUser struct {
	name       string
	credential database.Credential
	remote     []string
	// patterns are the databases granted on, as MySQL patterns, with the
	// privileges.
	patterns [][2]string
}

var (
	userDeclRe = regexp.MustCompile("(?i)(?:GRANT\\s+USAGE\\s+ON\\s+\\*\\.\\*\\s+TO|CREATE\\s+USER(?:\\s+IF\\s+NOT\\s+EXISTS)?)\\s+[`'\"]([^`'\"]+)[`'\"]@[`'\"]([^`'\"]+)[`'\"]\\s+IDENTIFIED\\s+(?:BY\\s+PASSWORD\\s+'([^']*)'|WITH\\s+[`'\"]?(\\w+)[`'\"]?\\s+AS\\s+('(?:[^'\\\\]|\\\\.)*'|0x[0-9A-Fa-f]+)|VIA\\s+(\\w+)\\s+USING\\s+'([^']*)')")
	grantRe    = regexp.MustCompile("(?i)GRANT\\s+(.+?)\\s+ON\\s+[`'\"]?((?:[^`'\"\\s.]|\\\\.)+)[`'\"]?\\.\\*\\s+TO\\s+[`'\"]([^`'\"]+)[`'\"]@[`'\"]([^`'\"]+)[`'\"]")
)

// dbUsers reads mysql.sql.
func (a *cpAccount) dbUsers() []*cpDBUser {
	byName := map[string]*cpDBUser{}
	var order []string
	get := func(n string) *cpDBUser {
		if u, ok := byName[n]; ok {
			return u
		}
		u := &cpDBUser{name: n}
		byName[n] = u
		order = append(order, n)
		return u
	}
	local := func(h string) bool { return h == "localhost" || h == "127.0.0.1" || h == "::1" }
	for _, stmt := range strings.Split(string(a.mysqlSQL), ";\n") {
		stmt = strings.TrimSpace(stmt)
		if m := userDeclRe.FindStringSubmatch(stmt); m != nil {
			u := get(m[1])
			if !local(m[2]) {
				if !slices.Contains(u.remote, m[2]) {
					u.remote = append(u.remote, m[2])
				}
			} else if u.credential.Hash == "" {
				switch {
				case m[3] != "":
					u.credential = database.Credential{Plugin: "mysql_native_password", Hash: strings.ToUpper(hex.EncodeToString([]byte(m[3])))}
				case m[4] != "":
					u.credential = database.Credential{Plugin: strings.ToLower(m[4]), Hash: sqlHash(m[5])}
				case m[6] != "":
					u.credential = database.Credential{Plugin: strings.ToLower(m[6]), Hash: strings.ToUpper(hex.EncodeToString([]byte(m[7])))}
				}
			}
		}
		if m := grantRe.FindStringSubmatch(stmt); m != nil && !strings.EqualFold(strings.TrimSpace(m[1]), "USAGE") && local(m[4]) {
			u := get(m[3])
			u.patterns = append(u.patterns, [2]string{m[2], m[1]})
		}
	}
	out := make([]*cpDBUser, 0, len(order))
	for _, n := range order {
		out = append(out, byName[n])
	}
	return out
}

// sqlHash returns a quoted SQL string or 0x literal as upper hex.
func sqlHash(lit string) string {
	if h, ok := strings.CutPrefix(strings.ToLower(lit), "0x"); ok {
		return strings.ToUpper(h)
	}
	s := strings.TrimSuffix(strings.TrimPrefix(lit, "'"), "'")
	var b strings.Builder
	for i := 0; i < len(s); i++ {
		if s[i] == '\\' && i+1 < len(s) {
			i++
			switch s[i] {
			case '0':
				b.WriteByte(0)
			case 'n':
				b.WriteByte('\n')
			case 'r':
				b.WriteByte('\r')
			case 't':
				b.WriteByte('\t')
			case 'Z':
				b.WriteByte(26)
			default:
				b.WriteByte(s[i])
			}
			continue
		}
		b.WriteByte(s[i])
	}
	return strings.ToUpper(hex.EncodeToString([]byte(b.String())))
}

// grants are the user's grants on the databases moved, with Serfex's
// access levels.
func (u *cpDBUser) grants(dbs []MigrationDatabase) []database.Grant {
	var out []database.Grant
	for _, d := range dbs {
		for _, p := range u.patterns {
			if mysqlLike(p[0], d.Name) {
				out = append(out, database.Grant{Database: d.Name, Access: accessOf(p[1])})
				break
			}
		}
	}
	return out
}

// mysqlLike matches a database name against a GRANT's pattern (% and _
// wildcards, \ escapes).
func mysqlLike(pattern, name string) bool {
	var re strings.Builder
	re.WriteString("^")
	for i := 0; i < len(pattern); i++ {
		c := pattern[i]
		switch {
		case c == '\\' && i+1 < len(pattern):
			i++
			re.WriteString(regexp.QuoteMeta(string(pattern[i])))
		case c == '%':
			re.WriteString(".*")
		case c == '_':
			re.WriteString(".")
		default:
			re.WriteString(regexp.QuoteMeta(string(c)))
		}
	}
	re.WriteString("$")
	ok, _ := regexp.MatchString(re.String(), name)
	return ok
}

// accessOf maps MySQL privileges to Serfex's access levels.
func accessOf(privs string) string {
	p := strings.ToUpper(privs)
	if strings.Contains(p, "ALL") {
		return database.AccessAll
	}
	has := func(s string) bool { return strings.Contains(p, s) }
	if has("CREATE") || has("DROP") || has("ALTER") || has("INDEX") || has("REFERENCES") || has("TRIGGER") {
		return database.AccessAll
	}
	if has("INSERT") || has("UPDATE") || has("DELETE") {
		return database.AccessReadWrite
	}
	return database.AccessReadOnly
}

// cpCert is a certificate with its key.
type cpCert struct {
	leaf  *x509.Certificate
	chain string
	key   string
}

func issuerName(c *x509.Certificate) string {
	if len(c.Issuer.Organization) > 0 {
		return c.Issuer.Organization[0]
	}
	return c.Issuer.CommonName
}

// certFor finds a certificate for domain that browsers trust and that
// is valid for at least another day, with its key.
func (a *cpAccount) certFor(domain string, now time.Time) *cpCert {
	var bundles [][]byte
	if b, ok := a.tls[domain]; ok {
		bundles = append(bundles, b)
	}
	for _, c := range a.sslCerts {
		for _, k := range a.sslKeys {
			bundles = append(bundles, append(append(slices.Clone(k), '\n'), c...))
		}
	}
	for _, b := range bundles {
		c := parseBundle(b)
		if c == nil || c.leaf.VerifyHostname(domain) != nil || now.Add(24*time.Hour).After(c.leaf.NotAfter) || c.leaf.CheckSignatureFrom(c.leaf) == nil {
			continue
		}
		return c
	}
	return nil
}

// parseBundle reads a PEM bundle of a key, a certificate and its chain;
// nil unless the key belongs to the certificate.
func parseBundle(b []byte) *cpCert {
	var key []byte
	var certs []*x509.Certificate
	var pems [][]byte
	for {
		var blk *pem.Block
		blk, b = pem.Decode(b)
		if blk == nil {
			break
		}
		switch {
		case strings.HasSuffix(blk.Type, "PRIVATE KEY"):
			if key == nil {
				key = pem.EncodeToMemory(blk)
			}
		case blk.Type == "CERTIFICATE":
			c, err := x509.ParseCertificate(blk.Bytes)
			if err != nil {
				continue
			}
			certs = append(certs, c)
			pems = append(pems, pem.EncodeToMemory(blk))
		}
	}
	if key == nil || len(certs) == 0 {
		return nil
	}
	for i, c := range certs {
		if !cert.KeyMatches(c, key) {
			continue
		}
		chain := string(pems[i])
		for j, p := range pems {
			if j != i {
				chain += string(p)
			}
		}
		return &cpCert{leaf: c, chain: chain, key: string(key)}
	}
	return nil
}
