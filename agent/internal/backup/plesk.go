package backup

import (
	"archive/tar"
	"bufio"
	"bytes"
	"compress/gzip"
	"context"
	"errors"
	"fmt"
	"io"
	"net/netip"
	"os"
	"path"
	"path/filepath"
	"regexp"
	"slices"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/calltech-app/serfex/agent/internal/database"
	"github.com/calltech-app/serfex/agent/internal/hostname"
	"github.com/calltech-app/serfex/agent/internal/i18n"
)

// Moving subscriptions from Plesk. Plesk's own backups encrypt passwords
// with the backup password and split large archives, so the agent signs
// in to the old server over SSH instead (as Plesk Migrator does) and reads
// one subscription from there: its settings from Plesk's database (plesk
// db), mailbox passwords with mail_auth_view, files and mail with tar,
// databases with plesk db dump. Nothing on the old server changes.
//
// What it reads is written as a cPanel-style account archive (cpanel.go)
// at migrate/<source>.tar, which is then read and converted like an
// uploaded cPanel backup:
//
//	cp/<user>                     DNS (main domain), CONTACTEMAIL, PLAN, SERFEX_SOURCE=plesk, SERFEX_IPS
//	userdata/main                 main_domain, addon_domains, parked_domains (the main domain's aliases), sub_domains
//	userdata/<domain>             documentroot, homedir, phpversion, serfex_aliases (an additional domain's aliases)
//	homedir/                      /var/www/vhosts/<domain>, without logs/ and tmp/
//	homedir/etc/<domain>/         passwd, shadow (SHA512-CRYPT of the password read in clear), quota
//	homedir/mail/<domain>/<box>/  /var/qmail/mailnames/<domain>/<box>/Maildir
//	mysql/<db>.sql, mysql.sql     dumps; the users (SHOW CREATE USER, SHOW GRANTS)
//	psql/<db>                     PostgreSQL databases, by name only
//	dnszones/<zone>.db            the zones, as BIND files
//	va/<domain>                   forwarders and mail aliases
//	apache_tls/<domain>           the certificate in use, with its key and chain
//	cron/<user>, proftpdpasswd    cron jobs and additional FTP accounts
//	serfex-notes                  what does not come, a code and a subject per line

// remote runs commands on the old server.
type remote interface {
	// run runs cmd with the account's shell; its output goes to w. A
	// failure carries the last line the command wrote to stderr.
	run(ctx context.Context, cmd string, w io.Writer) error
	close()
}

// PleskConn is how the agent signs in to a Plesk server: as root (or a
// user with passwordless sudo), with a password or, without one, with the
// agent's own key (the one SFTP targets use).
type PleskConn struct {
	Host     string `json:"host"`
	Port     int    `json:"port,omitempty"`
	User     string `json:"user,omitempty"`
	Password string `json:"password,omitempty"`
	// HostKey is the key the server showed when first connected to, in
	// authorized_keys form; a different key is refused.
	HostKey string `json:"host_key,omitempty"`
}

func (c *PleskConn) validate() error {
	c.Host = strings.TrimSuffix(strings.ToLower(strings.TrimSpace(c.Host)), ".")
	if a, err := netip.ParseAddr(c.Host); err == nil {
		c.Host = a.String()
	} else if hostname.Validate(c.Host) != nil {
		return invalid("%q is not a host name or an IP address", c.Host)
	}
	if c.Port == 0 {
		c.Port = 22
	}
	if c.Port < 1 || c.Port > 65535 {
		return invalid("port %d is not valid", c.Port)
	}
	if c.User == "" {
		c.User = "root"
	}
	if !sshUserRe.MatchString(c.User) {
		return invalid("user %q is not valid", c.User)
	}
	if len(c.Password) > 1024 || strings.ContainsAny(c.Password, "\x00\n") {
		return invalid("the password is not valid")
	}
	if c.HostKey != "" {
		if _, err := parseHostKey(c.HostKey); err != nil {
			return invalid("the host key is not valid")
		}
	}
	return nil
}

// PleskServer is what connecting to a Plesk server shows.
type PleskServer struct {
	HostKey       string              `json:"host_key"`
	Fingerprint   string              `json:"fingerprint"`
	Hostname      string              `json:"hostname"`
	Version       string              `json:"version"`
	Subscriptions []PleskSubscription `json:"subscriptions"`
}

// PleskSubscription is a subscription (webspace) on a Plesk server.
type PleskSubscription struct {
	ID     int    `json:"id"`
	Domain string `json:"domain"`
	// User is its system user; Owner the Plesk customer it belongs to.
	User  string `json:"user"`
	Owner string `json:"owner"`
	// Size is the disk space it uses, as Plesk last counted it.
	Size int64 `json:"size"`
	// Domains counts its additional domains.
	Domains int `json:"domains"`
}

// MigratePleskConnect signs in to a Plesk server and lists its
// subscriptions. The first time (no HostKey) any host key is taken and
// returned, for the administrator to compare and for every later call.
func (m *Manager) MigratePleskConnect(ctx context.Context, c PleskConn) (PleskServer, error) {
	if err := c.validate(); err != nil {
		return PleskServer{}, err
	}
	ctx, cancel := context.WithTimeout(ctx, 2*time.Minute)
	defer cancel()
	r, key, err := m.dialPlesk(ctx, c)
	if err != nil {
		return PleskServer{}, err
	}
	defer r.close()
	pr, err := pleskProbe(ctx, r)
	if err != nil {
		return PleskServer{}, err
	}
	rows, err := pleskQuery(ctx, r, "subscriptions", `SELECT d.id, d.name, IFNULL(s.login,''), IFNULL(c.login,''), IFNULL(d.real_size,0),
		(SELECT COUNT(*) FROM domains x WHERE x.webspace_id=d.id AND x.parentDomainId=0)
		FROM domains d JOIN hosting h ON h.dom_id=d.id LEFT JOIN sys_users s ON s.id=h.sys_user_id LEFT JOIN clients c ON c.id=d.cl_id
		WHERE d.webspace_id=0 AND d.parentDomainId=0 ORDER BY d.name`)
	if err != nil {
		return PleskServer{}, opError(fmt.Errorf("Plesk's subscriptions could not be read: %w", err))
	}
	out := PleskServer{HostKey: key, Fingerprint: Fingerprint(key), Hostname: pr.hostname, Version: pr.version, Subscriptions: []PleskSubscription{}}
	for _, row := range rows {
		if len(row) < 6 {
			continue
		}
		out.Subscriptions = append(out.Subscriptions, PleskSubscription{
			ID: atoi(row[0]), Domain: strings.ToLower(row[1]), User: row[2], Owner: row[3], Size: int64(atoi(row[4])), Domains: atoi(row[5]),
		})
	}
	return out, nil
}

// PleskPullSpec copies one subscription to this server.
type PleskPullSpec struct {
	PleskConn
	Subscription int `json:"subscription"`
	// Source names the copy from now on, as an uploaded archive's does.
	Source string `json:"source"`
	Run    string `json:"run"`
}

// PleskPull is the copy made.
type PleskPull struct {
	Source string `json:"source"`
	Size   int64  `json:"size"`
}

// MigratePleskPull reads a subscription from a Plesk server into an
// account archive, which migrate.inspect and migrate.convert then read
// with Source.
func (m *Manager) MigratePleskPull(ctx context.Context, spec PleskPullSpec) (res PleskPull, err error) {
	if err := spec.PleskConn.validate(); err != nil {
		return PleskPull{}, err
	}
	if spec.HostKey == "" {
		return PleskPull{}, invalid("connect to the server first: its host key is not known")
	}
	if spec.Subscription <= 0 {
		return PleskPull{}, invalid("choose a subscription")
	}
	if err := validateKey("source", spec.Source); err != nil {
		return PleskPull{}, err
	}
	if spec.Run != "" {
		if err := validateKey("run", spec.Run); err != nil {
			return PleskPull{}, err
		}
	}
	release, err := m.acquire(ctx)
	if err != nil {
		return PleskPull{}, err
	}
	defer release()
	ctx, cancel := context.WithTimeout(ctx, opTimeout)
	defer cancel()
	log, closeLog := m.logger(spec.Run)
	defer closeLog()
	defer func() {
		if err != nil {
			fmt.Fprintf(log, "✗ %s\n", i18n.T(ctx, "Aktarım durdu: %s", err))
			err = opError(err)
		}
	}()
	if err := m.dirs(); err != nil {
		return PleskPull{}, err
	}
	if err := os.MkdirAll(m.h.Path(migrateDir), 0o700); err != nil {
		return PleskPull{}, err
	}
	out := m.h.Path(migrateDir + "/" + spec.Source + ".tar")
	if _, err := os.Stat(out); err == nil {
		return PleskPull{}, invalid("%s exists already", spec.Source)
	}
	fmt.Fprintf(log, "▸ %s\n", i18n.T(ctx, "%s sunucusuna bağlanılıyor", spec.Host))
	r, _, err := m.dialPlesk(ctx, spec.PleskConn)
	if err != nil {
		return PleskPull{}, err
	}
	defer r.close()
	pr, err := pleskProbe(ctx, r)
	if err != nil {
		return PleskPull{}, err
	}
	p := &pleskPull{r: r, ctx: ctx, log: log, noted: map[string]bool{}}
	if err := p.read(spec.Subscription); err != nil {
		return PleskPull{}, err
	}
	p.ips = pleskIPs(append(p.ips, append(pr.ips, spec.Host)...))
	fmt.Fprintf(log, "  %s\n", i18n.T(ctx, "Abonelik: %s · sistem kullanıcısı %s", p.domains[0].name, p.login))
	if need := p.size + 1<<30; freeSpace(m.h.Path(Dir)) < need {
		return PleskPull{}, invalid("there is not enough free disk space to copy the subscription: %s needed", humanSize(need))
	}
	p.work = m.h.Path(workRoot + "/plesk-" + randomHex(8))
	if err := os.MkdirAll(p.work, 0o700); err != nil {
		return PleskPull{}, err
	}
	defer os.RemoveAll(p.work)

	part := out + ".part"
	f, err := os.OpenFile(part, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o600)
	if err != nil {
		return PleskPull{}, err
	}
	defer func() {
		f.Close()
		if err != nil {
			os.Remove(part)
		}
	}()
	bw := bufio.NewWriterSize(f, 1<<20)
	p.tw = tar.NewWriter(bw)
	if err := p.write(); err != nil {
		return PleskPull{}, err
	}
	if err := p.tw.Close(); err != nil {
		return PleskPull{}, err
	}
	if err := bw.Flush(); err != nil {
		return PleskPull{}, err
	}
	if err := f.Close(); err != nil {
		return PleskPull{}, err
	}
	if err := os.Rename(part, out); err != nil {
		return PleskPull{}, err
	}
	res = PleskPull{Source: spec.Source, Size: fileSize(out)}
	fmt.Fprintln(log, "✓ "+i18n.T(ctx, "Abonelik bu sunucuya alındı (%s).", humanSize(res.Size)))
	return res, nil
}

// pleskProbed is what a first look at the server tells.
type pleskProbed struct {
	version, hostname string
	ips               []string
}

func pleskProbe(ctx context.Context, r remote) (pleskProbed, error) {
	var b bytes.Buffer
	cmd := `echo "plesk=$(head -n 1 /usr/local/psa/version 2>/dev/null)"; echo "host=$(hostname -f 2>/dev/null || hostname)"; echo "ips=$(hostname -I 2>/dev/null)"`
	if err := r.run(ctx, cmd, limitWriter(&b, 64<<10)); err != nil {
		return pleskProbed{}, opError(fmt.Errorf("the server did not answer: %w", err))
	}
	var p pleskProbed
	for _, line := range strings.Split(b.String(), "\n") {
		k, v, _ := strings.Cut(line, "=")
		switch k {
		case "plesk":
			p.version = strings.TrimSpace(v)
		case "host":
			p.hostname = strings.TrimSpace(v)
		case "ips":
			p.ips = strings.Fields(v)
		}
	}
	if p.version == "" {
		return pleskProbed{}, invalid("the server is not a Plesk server: /usr/local/psa/version is missing")
	}
	// "18.0.63 Ubuntu 22.04 1800240819.10" → "18.0.63 · Ubuntu 22.04".
	if f := strings.Fields(p.version); len(f) >= 3 {
		p.version = f[0] + " · " + strings.Join(f[1:len(f)-1], " ")
	}
	return p, nil
}

// pleskQuery runs a query on Plesk's database (psa) as its administrator
// and returns the rows. name tags the query (the demo server answers by it).
func pleskQuery(ctx context.Context, r remote, name, sql string) ([][]string, error) {
	var b bytes.Buffer
	sql = "/*serfex:" + name + "*/ " + strings.Join(strings.Fields(sql), " ")
	if err := r.run(ctx, "plesk db -B -N -e "+shellQuote(sql), limitWriter(&b, 256<<20)); err != nil {
		return nil, err
	}
	return parseTSV(b.Bytes()), nil
}

// parseTSV reads mysql's batch output: rows of tab separated fields, with
// tabs, new lines, NUL and backslashes in values escaped.
func parseTSV(b []byte) [][]string {
	var out [][]string
	for _, line := range strings.Split(string(b), "\n") {
		if line == "" {
			continue
		}
		fields := strings.Split(line, "\t")
		for i, f := range fields {
			fields[i] = unescapeTSV(f)
		}
		out = append(out, fields)
	}
	return out
}

func unescapeTSV(s string) string {
	if !strings.Contains(s, `\`) {
		return s
	}
	var b strings.Builder
	for i := 0; i < len(s); i++ {
		if s[i] == '\\' && i+1 < len(s) {
			i++
			switch s[i] {
			case 'n':
				b.WriteByte('\n')
			case 't':
				b.WriteByte('\t')
			case '0':
				b.WriteByte(0)
			default:
				b.WriteByte(s[i])
			}
			continue
		}
		b.WriteByte(s[i])
	}
	return b.String()
}

// shellQuote quotes s for sh.
func shellQuote(s string) string { return "'" + strings.ReplaceAll(s, "'", `'\''`) + "'" }

// sqlQuote quotes s as a MySQL string.
func sqlQuote(s string) string {
	return "'" + strings.NewReplacer(`\`, `\\`, `'`, `\'`).Replace(s) + "'"
}

func sqlList(names []string) string {
	q := make([]string, len(names))
	for i, n := range names {
		q[i] = sqlQuote(n)
	}
	return strings.Join(q, ",")
}

func atoi(s string) int {
	n, _ := strconv.Atoi(strings.TrimSpace(s))
	return n
}

// pleskIPs keeps the addresses records can point at: no loopback, link
// local or unspecified ones, each once.
func pleskIPs(ips []string) []string {
	var out []string
	for _, s := range ips {
		a, err := netip.ParseAddr(strings.TrimSpace(s))
		if err != nil || a.IsLoopback() || a.IsLinkLocalUnicast() || a.IsUnspecified() {
			continue
		}
		if ip := a.Unmap().String(); !slices.Contains(out, ip) {
			out = append(out, ip)
		}
	}
	return out
}

// pleskDomain is a domain of the subscription: the main domain, an
// additional domain or a subdomain.
type pleskDomain struct {
	id, parent             int
	name, htype, root, php string
	cert                   int
	aliases                []string
}

// pleskBox is a mail name: a mailbox (postbox) or only forwarding.
type pleskBox struct {
	dom      int
	name     string
	postbox  bool
	quota    int64
	forwards []string
	aliases  []string
}

type pleskDB struct {
	dom              int
	name, kind, host string
}

// pleskPull is one subscription being read.
type pleskPull struct {
	r     remote
	ctx   context.Context
	log   io.Writer
	tw    *tar.Writer
	work  string
	top   string
	notes []MigrationNote
	noted map[string]bool

	login, user, home string
	sysID             int
	size              int64
	contact, plan     string
	ips               []string
	// domains[0] is the main domain.
	domains   []*pleskDomain
	boxes     []*pleskBox
	dbs       []pleskDB
	passwords map[string]string
}

func (p *pleskPull) remark(code, subject string) {
	if k := code + "\t" + subject; !p.noted[k] {
		p.noted[k] = true
		p.notes = append(p.notes, MigrationNote{Code: code, Subject: subject})
	}
}

// optional runs a query whose answer the move can do without: a failure
// is written in the log.
func (p *pleskPull) optional(name, what, sql string) [][]string {
	rows, err := pleskQuery(p.ctx, p.r, name, sql)
	if err != nil {
		fmt.Fprintf(p.log, "  ! %s\n", i18n.T(p.ctx, "%s okunamadı: %s", what, err))
		return nil
	}
	return rows
}

func (p *pleskPull) domainByID(id int) *pleskDomain {
	for _, d := range p.domains {
		if d.id == id {
			return d
		}
	}
	return nil
}

func (p *pleskPull) ids() string {
	var s []string
	for _, d := range p.domains {
		s = append(s, strconv.Itoa(d.id))
	}
	return strings.Join(s, ",")
}

var pleskPHPRe = regexp.MustCompile(`php(\d)(\d{1,2})`)

// read reads the subscription's settings.
func (p *pleskPull) read(sub int) error {
	rows, err := pleskQuery(p.ctx, p.r, "domains", fmt.Sprintf(`SELECT d.id, d.name, d.parentDomainId, d.htype, IFNULL(h.www_root,''), IFNULL(h.php_handler_id,''),
		IFNULL(h.certificate_id,0), IFNULL(d.real_size,0) FROM domains d LEFT JOIN hosting h ON h.dom_id=d.id
		WHERE d.id=%d OR d.webspace_id=%d ORDER BY d.id`, sub, sub))
	if err != nil {
		return opError(fmt.Errorf("the subscription could not be read: %w", err))
	}
	var rest []*pleskDomain
	for _, row := range rows {
		if len(row) < 8 {
			continue
		}
		d := &pleskDomain{id: atoi(row[0]), name: strings.ToLower(row[1]), parent: atoi(row[2]), htype: row[3], root: path.Clean("/" + row[4]), cert: atoi(row[6])}
		if m := pleskPHPRe.FindStringSubmatch(row[5]); m != nil {
			d.php = "plesk-php" + m[1] + m[2]
		}
		if d.id == sub {
			p.size = int64(atoi(row[7]))
			if d.htype != "vrt_hst" {
				return invalid("the subscription %s has no web hosting", d.name)
			}
			p.domains = append([]*pleskDomain{d}, p.domains...)
			continue
		}
		if hostname.Validate(d.name) != nil {
			p.remark("bad-domain", d.name)
			continue
		}
		if d.htype != "vrt_hst" || row[4] == "" {
			p.remark("plesk-no-hosting", d.name)
			continue
		}
		rest = append(rest, d)
	}
	if len(p.domains) == 0 {
		return invalid("subscription %d is not on the server", sub)
	}
	p.domains = append(p.domains, rest...)

	rows, err = pleskQuery(p.ctx, p.r, "account", fmt.Sprintf(`SELECT s.login, s.home, s.id FROM hosting h JOIN sys_users s ON s.id=h.sys_user_id WHERE h.dom_id=%d`, sub))
	if err != nil || len(rows) == 0 || len(rows[0]) < 3 {
		return opError(fmt.Errorf("the subscription's system user could not be read: %v", err))
	}
	p.login, p.home, p.sysID = rows[0][0], path.Clean("/"+rows[0][1]), atoi(rows[0][2])
	p.user = pleskUser(p.login, sub)
	if p.user != p.login {
		p.remark("renamed-user", p.login)
	}
	p.top = "plesk-" + p.user + "/"

	if rows := p.optional("client", i18n.T(p.ctx, "Müşteri bilgisi"), fmt.Sprintf(`SELECT IFNULL(c.email,''), IFNULL(c.pname,'') FROM domains d JOIN clients c ON c.id=d.cl_id WHERE d.id=%d`, sub)); len(rows) > 0 && len(rows[0]) > 0 {
		p.contact = rows[0][0]
	}
	if rows := p.optional("plan", i18n.T(p.ctx, "Hizmet planı"), fmt.Sprintf(`SELECT t.name FROM Subscriptions s JOIN PlansSubscriptions ps ON ps.subscription_id=s.id
		JOIN Templates t ON t.id=ps.plan_id WHERE s.object_type='domain' AND s.object_id=%d AND t.type='domain' LIMIT 1`, sub)); len(rows) > 0 && len(rows[0]) > 0 {
		p.plan = rows[0][0]
	}
	for _, row := range p.optional("aliases", i18n.T(p.ctx, "Takma adlar"), `SELECT a.dom_id, a.name, a.mail FROM domain_aliases a WHERE a.dom_id IN (`+p.ids()+`)`) {
		if len(row) < 3 {
			continue
		}
		d, name := p.domainByID(atoi(row[0])), strings.ToLower(row[1])
		if d == nil || hostname.Validate(name) != nil {
			continue
		}
		if d.parent != 0 {
			// A subdomain's alias has nowhere to go.
			p.remark("plesk-no-hosting", name)
			continue
		}
		d.aliases = append(d.aliases, name)
		if row[2] == "true" {
			p.remark("alias-mail", name)
		}
	}
	for _, row := range p.optional("ips", i18n.T(p.ctx, "IP adresleri"), `SELECT ip_address, IFNULL(public_ip_address,'') FROM ip_addresses`) {
		p.ips = append(p.ips, row...)
	}

	// Mail.
	byName := map[string]*pleskBox{}
	for _, row := range p.optional("mail", i18n.T(p.ctx, "Posta hesapları"), `SELECT m.dom_id, m.mail_name, m.postbox, IFNULL(m.mbox_quota,-1) FROM mail m WHERE m.dom_id IN (`+p.ids()+`) ORDER BY m.dom_id, m.mail_name`) {
		if len(row) < 4 {
			continue
		}
		b := &pleskBox{dom: atoi(row[0]), name: strings.ToLower(row[1]), postbox: row[2] == "true", quota: int64(atoi(row[3]))}
		d := p.domainByID(b.dom)
		if d == nil {
			continue
		}
		if !validLocal(b.name) {
			p.remark("bad-mailbox", b.name+"@"+d.name)
			continue
		}
		p.boxes = append(p.boxes, b)
		byName[row[0]+"\t"+b.name] = b
	}
	if len(p.boxes) > 0 {
		for _, row := range p.optional("forwards", i18n.T(p.ctx, "Yönlendirmeler"), `SELECT m.dom_id, m.mail_name, r.address FROM mail_redir r JOIN mail m ON m.id=r.mn_id WHERE m.dom_id IN (`+p.ids()+`)`) {
			if len(row) >= 3 {
				if b := byName[row[0]+"\t"+strings.ToLower(row[1])]; b != nil {
					b.forwards = append(b.forwards, strings.ToLower(strings.TrimSpace(row[2])))
				}
			}
		}
		for _, row := range p.optional("mailaliases", i18n.T(p.ctx, "Posta takma adları"), `SELECT m.dom_id, m.mail_name, a.alias FROM mail_aliases a JOIN mail m ON m.id=a.mn_id WHERE m.dom_id IN (`+p.ids()+`)`) {
			if len(row) >= 3 {
				if b := byName[row[0]+"\t"+strings.ToLower(row[1])]; b != nil && validLocal(strings.ToLower(row[2])) {
					b.aliases = append(b.aliases, strings.ToLower(row[2]))
				}
			}
		}
	}

	for _, row := range p.optional("databases", i18n.T(p.ctx, "Veritabanları"), `SELECT db.dom_id, db.name, db.type, IFNULL(s.host,'localhost') FROM data_bases db LEFT JOIN db_servers s ON s.id=db.db_server_id WHERE db.dom_id IN (`+p.ids()+`) ORDER BY db.name`) {
		if len(row) >= 4 {
			p.dbs = append(p.dbs, pleskDB{dom: atoi(row[0]), name: row[1], kind: row[2], host: strings.ToLower(row[3])})
		}
	}
	return nil
}

// pleskUser makes a Plesk system user's name one this server takes: lower
// case, letters, digits, - and _, starting with a letter, 32 at most.
func pleskUser(login string, sub int) string {
	var b strings.Builder
	for _, c := range strings.ToLower(login) {
		switch {
		case c >= 'a' && c <= 'z', c >= '0' && c <= '9' && b.Len() > 0, (c == '-' || c == '_') && b.Len() > 0:
			b.WriteRune(c)
		case c == '.' && b.Len() > 0:
			b.WriteByte('_')
		}
	}
	u := b.String()
	if len(u) > 32 {
		u = u[:32]
	}
	if u == "" {
		u = "plesk" + strconv.Itoa(sub)
	}
	return u
}

// write writes the account archive.
func (p *pleskPull) write() error {
	main := p.domains[0]
	cp := fmt.Sprintf("USER=%s\nDNS=%s\nCONTACTEMAIL=%s\nPLAN=%s\nSERFEX_SOURCE=plesk\nSERFEX_IPS=%s\n",
		p.user, main.name, oneLine(p.contact), oneLine(p.plan), strings.Join(p.ips, " "))
	if len(p.ips) > 0 {
		cp += "IP=" + p.ips[0] + "\n"
	}
	if err := p.file("cp/"+p.user, []byte(cp)); err != nil {
		return err
	}
	if err := p.userdata(); err != nil {
		return err
	}
	fmt.Fprintf(p.log, "▸ %s\n", i18n.T(p.ctx, "Dosyalar alınıyor: %s", p.home))
	if err := p.files(); err != nil {
		return err
	}
	if err := p.mail(); err != nil {
		return err
	}
	if err := p.databases(); err != nil {
		return err
	}
	fmt.Fprintf(p.log, "▸ %s\n", i18n.T(p.ctx, "DNS, sertifikalar ve zamanlanmış görevler okunuyor"))
	if err := p.zones(); err != nil {
		return err
	}
	if err := p.certificates(); err != nil {
		return err
	}
	if err := p.cronAndFTP(); err != nil {
		return err
	}
	var notes strings.Builder
	for _, n := range p.notes {
		fmt.Fprintf(&notes, "%s\t%s\n", n.Code, oneLine(n.Subject))
	}
	return p.file("serfex-notes", []byte(notes.String()))
}

func oneLine(s string) string { return strings.NewReplacer("\n", " ", "\r", " ", "\t", " ").Replace(s) }

// file adds a file of the archive.
func (p *pleskPull) file(name string, b []byte) error {
	return p.add(name, &tar.Header{Typeflag: tar.TypeReg, Mode: 0o600, Size: int64(len(b)), ModTime: time.Now()}, bytes.NewReader(b))
}

// add adds an entry under a name of the archive: regular files, folders
// and symbolic links only.
func (p *pleskPull) add(name string, hdr *tar.Header, r io.Reader) error {
	h := &tar.Header{Name: p.top + name, Mode: hdr.Mode & 0o1777, ModTime: hdr.ModTime, Typeflag: hdr.Typeflag, Format: tar.FormatPAX}
	switch hdr.Typeflag {
	case tar.TypeReg:
		h.Size = hdr.Size
	case tar.TypeDir:
		h.Name += "/"
	case tar.TypeSymlink:
		h.Linkname = hdr.Linkname
	default:
		return nil
	}
	if h.ModTime.IsZero() {
		h.ModTime = time.Now()
	}
	if err := p.tw.WriteHeader(h); err != nil {
		return err
	}
	if hdr.Typeflag == tar.TypeReg {
		if _, err := io.CopyN(p.tw, r, hdr.Size); err != nil {
			return fmt.Errorf("the copy from the server broke off: %w", err)
		}
	}
	return nil
}

func yamlQuote(s string) string { return "'" + strings.ReplaceAll(s, "'", "''") + "'" }

// userdata writes the domains in cPanel's form.
func (p *pleskPull) userdata() error {
	main := p.domains[0]
	var b strings.Builder
	fmt.Fprintf(&b, "---\nmain_domain: %s\naddon_domains:\n", yamlQuote(main.name))
	for _, d := range p.domains[1:] {
		if d.parent == 0 {
			fmt.Fprintf(&b, "  %s: %s\n", yamlQuote(d.name), yamlQuote(d.name))
		}
	}
	b.WriteString("parked_domains:\n")
	for _, a := range main.aliases {
		fmt.Fprintf(&b, "  - %s\n", yamlQuote(a))
	}
	b.WriteString("sub_domains:\n")
	for _, d := range p.domains[1:] {
		if d.parent != 0 {
			fmt.Fprintf(&b, "  - %s\n", yamlQuote(d.name))
		}
	}
	if err := p.file("userdata/main", []byte(b.String())); err != nil {
		return err
	}
	for i, d := range p.domains {
		var u strings.Builder
		fmt.Fprintf(&u, "---\ndocumentroot: %s\nhomedir: %s\n", yamlQuote(d.root), yamlQuote(p.home))
		if d.php != "" {
			fmt.Fprintf(&u, "phpversion: %s\n", yamlQuote(d.php))
		}
		if i > 0 && d.parent == 0 && len(d.aliases) > 0 {
			u.WriteString("serfex_aliases:\n")
			for _, a := range d.aliases {
				fmt.Fprintf(&u, "  - %s\n", yamlQuote(a))
			}
		}
		if err := p.file("userdata/"+d.name, []byte(u.String())); err != nil {
			return err
		}
	}
	return nil
}

// stream runs a command that writes a gzipped tar and calls fn for each
// entry.
func (p *pleskPull) stream(cmd string, fn func(name string, hdr *tar.Header, r io.Reader) error) error {
	pr, pw := io.Pipe()
	done := make(chan error, 1)
	go func() {
		err := p.r.run(p.ctx, cmd, pw)
		pw.CloseWithError(err)
		done <- err
	}()
	err := func() error {
		gz, err := gzip.NewReader(pr)
		if errors.Is(err, io.EOF) {
			return nil
		}
		if err != nil {
			return err
		}
		tr := tar.NewReader(gz)
		for {
			hdr, err := tr.Next()
			if errors.Is(err, io.EOF) {
				return nil
			}
			if err != nil {
				return fmt.Errorf("the copy from the server broke off: %w", err)
			}
			name := strings.Trim(strings.TrimPrefix(hdr.Name, "./"), "/")
			if name == "" || name == "." || strings.Contains("/"+name+"/", "/../") {
				continue
			}
			if err := fn(name, hdr, tr); err != nil {
				return err
			}
		}
	}()
	pr.CloseWithError(errors.New("stopped"))
	rerr := <-done
	if err != nil {
		return err
	}
	return rerr
}

// files copies the subscription's home: every site's folder and the
// account's other files.
func (p *pleskPull) files() error {
	var size int64
	count := 0
	cmd := "tar -C " + shellQuote(p.home) + " --numeric-owner --ignore-failed-read --warning=no-file-changed" +
		" --exclude=./logs --exclude=./tmp --exclude=./statistics -cf - . | gzip -1"
	err := p.stream(cmd, func(name string, hdr *tar.Header, r io.Reader) error {
		count++
		if hdr.Typeflag == tar.TypeReg {
			size += hdr.Size
			if configNames[path.Base(name)] && hdr.Size <= configLimit {
				b, err := io.ReadAll(io.LimitReader(r, hdr.Size))
				if err != nil {
					return fmt.Errorf("the copy from the server broke off: %w", err)
				}
				if bytes.Contains(b, []byte("/var/www/vhosts/")) {
					p.remark("old-path", name)
				}
				r = bytes.NewReader(b)
			}
		}
		return p.add("homedir/"+name, hdr, r)
	})
	if err != nil {
		return err
	}
	if count == 0 {
		return fmt.Errorf("%s could not be read on the server", p.home)
	}
	fmt.Fprintf(p.log, "  %s\n", i18n.T(p.ctx, "Dosyalar alındı (%s)", humanSize(size)))
	return nil
}

// mail writes the mailboxes' passwords, quotas and forwarders, and copies
// their Maildirs.
func (p *pleskPull) mail() error {
	if len(p.boxes) == 0 {
		return nil
	}
	fmt.Fprintf(p.log, "▸ %s\n", i18n.T(p.ctx, "Posta kutuları alınıyor"))
	p.passwords = p.mailPasswords()
	boxes := map[string]bool{}
	var domains []string
	for _, d := range p.domains {
		var passwd, shadow, quota, va strings.Builder
		for _, b := range p.boxes {
			if b.dom != d.id {
				continue
			}
			addr := b.name + "@" + d.name
			if b.postbox {
				boxes[d.name+"/"+b.name] = true
				fmt.Fprintf(&passwd, "%s:x:0:0::/:/bin/false\n", b.name)
				if pw, ok := p.passwords[addr]; ok {
					fmt.Fprintf(&shadow, "%s:%s:::::::\n", b.name, sha512Crypt(pw))
				}
				if b.quota > 0 {
					fmt.Fprintf(&quota, "%s:%d\n", b.name, b.quota)
				}
			}
			if len(b.forwards) > 0 {
				fmt.Fprintf(&va, "%s: %s\n", addr, strings.Join(b.forwards, ","))
			}
			for _, a := range b.aliases {
				fmt.Fprintf(&va, "%s@%s: %s\n", a, d.name, addr)
			}
		}
		for _, f := range []struct {
			name string
			b    *strings.Builder
		}{{"homedir/etc/" + d.name + "/passwd", &passwd}, {"homedir/etc/" + d.name + "/shadow", &shadow}, {"homedir/etc/" + d.name + "/quota", &quota}, {"va/" + d.name, &va}} {
			if f.b.Len() > 0 {
				if err := p.file(f.name, []byte(f.b.String())); err != nil {
					return err
				}
			}
		}
		if passwd.Len() > 0 {
			domains = append(domains, d.name)
		}
	}
	if len(domains) == 0 {
		return nil
	}
	q := make([]string, len(domains))
	for i, d := range domains {
		q[i] = shellQuote(d)
	}
	var size int64
	cmd := "tar -C /var/qmail/mailnames --numeric-owner --ignore-failed-read --warning=no-file-changed -cf - -- " + strings.Join(q, " ") + " | gzip -1"
	err := p.stream(cmd, func(name string, hdr *tar.Header, r io.Reader) error {
		// <domain>/<box>/Maildir/<rest>; the rest of a box's folder is
		// Plesk's own.
		parts := strings.SplitN(name, "/", 4)
		if len(parts) < 3 || parts[2] != "Maildir" || !boxes[parts[0]+"/"+parts[1]] {
			return nil
		}
		to := "homedir/mail/" + parts[0] + "/" + parts[1]
		if len(parts) == 4 {
			to += "/" + parts[3]
		}
		if hdr.Typeflag == tar.TypeReg {
			size += hdr.Size
		}
		return p.add(to, hdr, r)
	})
	if err != nil {
		return err
	}
	fmt.Fprintf(p.log, "  %s\n", i18n.T(p.ctx, "Posta alındı (%s)", humanSize(size)))
	return nil
}

// mailPasswords reads the mailboxes' passwords with Plesk's mail_auth_view,
// which prints a table: | address | flags | password |.
func (p *pleskPull) mailPasswords() map[string]string {
	out := map[string]string{}
	var b bytes.Buffer
	cmd := `for f in /usr/local/psa/admin/bin/mail_auth_view /usr/local/psa/admin/sbin/mail_auth_view; do if [ -x "$f" ]; then exec "$f"; fi; done; echo "mail_auth_view is missing" >&2; exit 3`
	if err := p.r.run(p.ctx, cmd, limitWriter(&b, 64<<20)); err != nil {
		fmt.Fprintf(p.log, "  ! %s\n", i18n.T(p.ctx, "Posta parolaları okunamadı; kutulara yeni parola verilecek: %s", err))
		return out
	}
	for _, line := range strings.Split(b.String(), "\n") {
		line = strings.TrimSpace(line)
		if !strings.HasPrefix(line, "|") || !strings.HasSuffix(line, "|") {
			continue
		}
		cells := strings.Split(line[1:len(line)-1], "|")
		if len(cells) < 3 {
			continue
		}
		addr := strings.ToLower(strings.TrimSpace(cells[0]))
		pw := strings.TrimSpace(strings.Join(cells[2:], "|"))
		if strings.Count(addr, "@") == 1 && pw != "" {
			out[addr] = pw
		}
	}
	return out
}

// databases dumps the subscription's MySQL databases and reads their
// users.
func (p *pleskPull) databases() error {
	var dumped []string
	for _, db := range p.dbs {
		switch {
		case db.kind == "postgresql":
			if err := p.file("psql/"+db.name, nil); err != nil {
				return err
			}
			continue
		case db.kind != "mysql":
			continue
		case db.host != "localhost" && db.host != "127.0.0.1" && db.host != "::1" && db.host != "":
			p.remark("remote-database", db.name)
			continue
		case database.ValidateDatabaseName(db.name) != nil:
			p.remark("bad-database", db.name)
			continue
		}
		if len(dumped) == 0 {
			fmt.Fprintf(p.log, "▸ %s\n", i18n.T(p.ctx, "Veritabanları alınıyor"))
		}
		if err := p.dump(db.name); err != nil {
			return err
		}
		dumped = append(dumped, db.name)
	}
	if len(dumped) == 0 {
		return nil
	}
	return p.file("mysql.sql", []byte(p.mysqlUsers(dumped)))
}

// dump copies a database's dump: plesk db dump, or mysqldump with Plesk's
// administrator where that is missing. A dump without mysqldump's last
// line is broken.
func (p *pleskPull) dump(name string) error {
	file := filepath.Join(p.work, "dump.sql")
	f, err := os.OpenFile(file, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o600)
	if err != nil {
		return err
	}
	defer os.Remove(file)
	defer f.Close()
	q := shellQuote(name)
	cmd := "(plesk db dump " + q + " 2>/dev/null || MYSQL_PWD=\"$(cat /etc/psa/.psa.shadow)\" mysqldump -uadmin --single-transaction --quick --routines --triggers --events " + q + ") | gzip -1"
	pr, pw := io.Pipe()
	done := make(chan error, 1)
	go func() {
		err := p.r.run(p.ctx, cmd, pw)
		pw.CloseWithError(err)
		done <- err
	}()
	var copyErr error
	gz, err := gzip.NewReader(pr)
	if err == nil {
		_, copyErr = io.Copy(f, gz)
	} else if !errors.Is(err, io.EOF) {
		copyErr = err
	}
	pr.CloseWithError(errors.New("stopped"))
	if rerr := <-done; copyErr == nil {
		copyErr = rerr
	}
	if copyErr != nil {
		return fmt.Errorf("the database %s could not be copied: %w", name, copyErr)
	}
	if err := f.Close(); err != nil {
		return err
	}
	fi, err := os.Stat(file)
	if err != nil {
		return err
	}
	tail := make([]byte, min(fi.Size(), 4096))
	if rf, err := os.Open(file); err == nil {
		_, _ = rf.ReadAt(tail, fi.Size()-int64(len(tail)))
		rf.Close()
	}
	if !bytes.Contains(tail, []byte("-- Dump completed")) {
		return fmt.Errorf("the dump of the database %s is incomplete", name)
	}
	rf, err := os.Open(file)
	if err != nil {
		return err
	}
	defer rf.Close()
	fmt.Fprintf(p.log, "  %s\n", i18n.T(p.ctx, "Veritabanı: %s", name))
	return p.add("mysql/"+name+".sql", &tar.Header{Typeflag: tar.TypeReg, Mode: 0o600, Size: fi.Size(), ModTime: time.Now()}, rf)
}

// mysqlUsers returns the users that have access to the databases, with
// their password hashes and grants, as statements like cPanel's
// mysql.sql.
func (p *pleskPull) mysqlUsers(dbs []string) string {
	type account struct{ user, host string }
	var accounts []account
	for _, row := range p.optional("grants", i18n.T(p.ctx, "Veritabanı kullanıcıları"), `SELECT DISTINCT User, Host, Db FROM mysql.db`) {
		if len(row) < 3 || row[0] == "" {
			continue
		}
		a := account{row[0], row[1]}
		if slices.Contains(accounts, a) {
			continue
		}
		for _, db := range dbs {
			if mysqlLike(row[2], db) {
				accounts = append(accounts, a)
				break
			}
		}
	}
	sort.Slice(accounts, func(i, j int) bool {
		return accounts[i].user+"@"+accounts[i].host < accounts[j].user+"@"+accounts[j].host
	})
	var out strings.Builder
	for _, a := range accounts {
		id := sqlQuote(a.user) + "@" + sqlQuote(a.host)
		show := "SHOW CREATE USER " + id + "; SHOW GRANTS FOR " + id
		// MySQL 8 writes caching_sha2 hashes in hex only when asked.
		rows, err := pleskQuery(p.ctx, p.r, "user", "SET SESSION print_identified_with_as_hex=1; "+show)
		if err != nil {
			if rows, err = pleskQuery(p.ctx, p.r, "user", show); err != nil {
				fmt.Fprintf(p.log, "  ! %s\n", i18n.T(p.ctx, "%s okunamadı: %s", a.user, err))
				continue
			}
		}
		for _, row := range rows {
			if len(row) > 0 && row[0] != "" {
				out.WriteString(row[0] + ";\n")
			}
		}
	}
	return out.String()
}

// zones writes the zones of the domains and their aliases.
func (p *pleskPull) zones() error {
	var names []string
	for _, d := range p.domains {
		if d.parent == 0 {
			names = append(names, d.name)
			names = append(names, d.aliases...)
		}
	}
	zones := map[string]*strings.Builder{}
	for _, row := range p.optional("dns", i18n.T(p.ctx, "DNS kayıtları"), `SELECT z.name, r.type, r.host, r.val, IFNULL(r.opt,'') FROM dns_recs r JOIN dns_zone z ON z.id=r.dns_zone_id WHERE z.name IN (`+sqlList(names)+`) ORDER BY z.name, r.id`) {
		if len(row) < 5 {
			continue
		}
		zone := strings.ToLower(row[0])
		if !slices.Contains(names, zone) {
			continue
		}
		line, ok := pleskRecord(row[1], row[2], row[3], row[4])
		if !ok {
			continue
		}
		if zones[zone] == nil {
			zones[zone] = &strings.Builder{}
			zones[zone].WriteString("$TTL 3600\n")
		}
		zones[zone].WriteString(line + "\n")
	}
	for _, z := range names {
		if b := zones[z]; b != nil {
			if err := p.file("dnszones/"+z+".db", []byte(b.String())); err != nil {
				return err
			}
		}
	}
	return nil
}

// pleskRecord turns a row of Plesk's dns_recs into a zone file line. Host
// and targets are absolute names with a trailing dot; MX and SRV keep
// their numbers in opt.
func pleskRecord(typ, host, val, opt string) (string, bool) {
	typ = strings.ToUpper(strings.TrimSpace(typ))
	host = strings.ToLower(strings.TrimSpace(host))
	val, opt = strings.TrimSpace(val), strings.TrimSpace(opt)
	if host == "" || strings.ContainsAny(host, " \t;\"()") {
		return "", false
	}
	if !strings.HasSuffix(host, ".") {
		host += "."
	}
	target := func(s string) string {
		s = strings.ToLower(s)
		if !strings.HasSuffix(s, ".") {
			s += "."
		}
		return s
	}
	var data string
	switch typ {
	case "A", "AAAA":
		data = val
	case "CNAME", "NS":
		data = target(val)
	case "MX":
		data = strconv.Itoa(atoiOr(opt, 10)) + " " + target(val)
	case "SRV":
		f := strings.Fields(opt)
		if len(f) != 3 {
			return "", false
		}
		data = strings.Join(f, " ") + " " + target(val)
	case "TXT":
		data = zoneString(val)
	case "CAA":
		f := strings.Fields(opt + " " + val)
		if len(f) < 3 {
			return "", false
		}
		data = f[0] + " " + f[1] + " " + zoneString(strings.Trim(strings.Join(f[2:], " "), `"`))
	default:
		return "", false
	}
	if data == "" || strings.ContainsAny(data, "\n;()") && typ != "TXT" && typ != "CAA" {
		return "", false
	}
	return host + "\tIN\t" + typ + "\t" + data, true
}

// zoneString quotes text for a zone file, in strings of at most 255.
func zoneString(s string) string {
	esc := strings.NewReplacer(`\`, `\\`, `"`, `\"`)
	var parts []string
	for len(s) > 255 {
		parts = append(parts, `"`+esc.Replace(s[:255])+`"`)
		s = s[255:]
	}
	return strings.Join(append(parts, `"`+esc.Replace(s)+`"`), " ")
}

// certificates writes the certificate each domain uses, with its key and
// chain.
func (p *pleskPull) certificates() error {
	var ids []string
	for _, d := range p.domains {
		if d.cert > 0 && d.parent == 0 {
			ids = append(ids, strconv.Itoa(d.id))
		}
	}
	if len(ids) == 0 {
		return nil
	}
	for _, row := range p.optional("certificates", i18n.T(p.ctx, "Sertifikalar"), `SELECT h.dom_id, IFNULL(c.pvt_key,''), IFNULL(c.cert,''), IFNULL(c.ca_cert,'') FROM hosting h JOIN certificates c ON c.id=h.certificate_id WHERE h.dom_id IN (`+strings.Join(ids, ",")+`)`) {
		if len(row) < 4 {
			continue
		}
		d := p.domainByID(atoi(row[0]))
		if d == nil || row[1] == "" || row[2] == "" {
			continue
		}
		if err := p.file("apache_tls/"+d.name, []byte(row[1]+"\n"+row[2]+"\n"+row[3]+"\n")); err != nil {
			return err
		}
	}
	return nil
}

// cronAndFTP writes the system user's cron jobs and the additional FTP
// accounts, which are listed, not moved.
func (p *pleskPull) cronAndFTP() error {
	var b bytes.Buffer
	if err := p.r.run(p.ctx, "crontab -l -u "+shellQuote(p.login)+" 2>/dev/null || true", limitWriter(&b, 1<<20)); err == nil && b.Len() > 0 {
		if err := p.file("cron/"+p.user, b.Bytes()); err != nil {
			return err
		}
	}
	var ftp strings.Builder
	for _, row := range p.optional("ftp", i18n.T(p.ctx, "FTP hesapları"), fmt.Sprintf(`SELECT login FROM sys_users WHERE mapped_to=%d`, p.sysID)) {
		if len(row) > 0 && row[0] != "" && row[0] != p.login {
			fmt.Fprintf(&ftp, "%s:x:0:0::/:/bin/false\n", oneLine(strings.ReplaceAll(row[0], ":", "")))
		}
	}
	if ftp.Len() > 0 {
		return p.file("proftpdpasswd", []byte(ftp.String()))
	}
	return nil
}

// limitWriter writes at most n bytes to w and fails after.
func limitWriter(w io.Writer, n int64) io.Writer { return &limited{w: w, n: n} }

type limited struct {
	w io.Writer
	n int64
}

func (l *limited) Write(b []byte) (int, error) {
	if int64(len(b)) > l.n {
		return 0, errors.New("the server's answer is too long")
	}
	l.n -= int64(len(b))
	return l.w.Write(b)
}
