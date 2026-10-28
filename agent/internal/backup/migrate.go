package backup

import (
	"archive/tar"
	"bufio"
	"compress/gzip"
	"context"
	"crypto/rand"
	"errors"
	"fmt"
	"io"
	"math/big"
	"net/netip"
	"os"
	"path"
	"path/filepath"
	"regexp"
	"slices"
	"sort"
	"strings"
	"syscall"
	"time"

	"github.com/calltech-app/serfex/agent/internal/cert"
	"github.com/calltech-app/serfex/agent/internal/database"
	"github.com/calltech-app/serfex/agent/internal/i18n"
	"github.com/calltech-app/serfex/agent/internal/site"
)

// Moving accounts from other panels: an account archive is read
// (migrate.inspect), then turned into Serfex site backups, one per site
// (migrate.convert), which the panel restores like any other backup: the
// sites are created from their description, files, databases (with their
// users' passwords), mail and certificates are put back.
//
//	/var/lib/serfex/backups/migrate/<id>.tar   uploaded archives being moved

const migrateDir = Dir + "/migrate"

var migrateFileRe = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._-]{0,200}\.(tar\.gz|tgz|tar)$`)

// MigrateSource names an account archive: one copied to the import
// folder (File), one just uploaded from the panel (Upload), or one an
// earlier inspection kept (Source).
type MigrateSource struct {
	File   string `json:"file,omitempty"`
	Upload string `json:"upload,omitempty"`
	Source string `json:"source,omitempty"`
}

// path returns the archive's file.
func (m *Manager) migratePath(s MigrateSource) (string, error) {
	switch {
	case s.File != "" && s.Upload == "" && s.Source == "":
		if !migrateFileRe.MatchString(s.File) {
			return "", invalid("file %q is not valid", s.File)
		}
		p := m.h.Path(ImportDir + "/" + s.File)
		if fi, err := os.Lstat(p); err != nil || !fi.Mode().IsRegular() {
			return "", invalid("%s is not in the import folder", s.File)
		}
		return p, nil
	case s.Upload != "" && s.File == "" && s.Source == "":
		if err := validateKey("upload", s.Upload); err != nil {
			return "", err
		}
		return m.h.Path(uploadsDir + "/" + s.Upload + ".part"), nil
	case s.Source != "" && s.File == "" && s.Upload == "":
		if err := validateKey("source", s.Source); err != nil {
			return "", err
		}
		p := m.h.Path(migrateDir + "/" + s.Source + ".tar")
		if _, err := os.Stat(p); err != nil {
			return "", invalid("the archive is no longer on the server; upload it again")
		}
		return p, nil
	}
	return "", invalid("give a file, an upload or a source")
}

// MigrateFile is an archive in the import folder that may be an account
// from another panel.
type MigrateFile struct {
	File     string    `json:"file"`
	Size     int64     `json:"size"`
	Modified time.Time `json:"modified"`
}

// MigrateScan lists the import folder's .tar.gz, .tgz and .tar files
// that are not Serfex backups.
func (m *Manager) MigrateScan() ([]MigrateFile, error) {
	if err := m.dirs(); err != nil {
		return nil, opError(err)
	}
	entries, err := os.ReadDir(m.h.Path(ImportDir))
	if err != nil {
		return nil, opError(err)
	}
	out := []MigrateFile{}
	for _, e := range entries {
		if !e.Type().IsRegular() || !migrateFileRe.MatchString(e.Name()) {
			continue
		}
		p := m.h.Path(ImportDir + "/" + e.Name())
		if strings.HasSuffix(e.Name(), ".tar") {
			if _, err := m.inspectFile(p); err == nil {
				continue
			}
		}
		fi, err := e.Info()
		if err != nil {
			continue
		}
		out = append(out, MigrateFile{File: e.Name(), Size: fi.Size(), Modified: fi.ModTime().UTC()})
	}
	return out, nil
}

// MigrateInspection is what migrate.inspect returns.
type MigrateInspection struct {
	// Source names an uploaded archive from now on.
	Source string        `json:"source,omitempty"`
	Plan   MigrationPlan `json:"plan"`
	// Free space where the archive is converted and where homes are.
	FreeBackups int64 `json:"free_backups"`
	FreeHome    int64 `json:"free_home"`
}

// MigrateInspect reads an account archive and says what moving it brings.
// An uploaded archive is kept for the conversion.
func (m *Manager) MigrateInspect(ctx context.Context, src MigrateSource) (MigrateInspection, error) {
	file, err := m.migratePath(src)
	if err != nil {
		return MigrateInspection{}, err
	}
	if err := m.dirs(); err != nil {
		return MigrateInspection{}, opError(err)
	}
	a, err := readCPanel(file)
	if err != nil {
		if errors.Is(err, os.ErrNotExist) {
			return MigrateInspection{}, invalid("the upload is not on the server; upload it again")
		}
		if errors.Is(err, errNotCPanel) {
			return MigrateInspection{}, invalid("%s", err.Error())
		}
		return MigrateInspection{}, opError(err)
	}
	out := MigrateInspection{Plan: a.plan(m.now())}
	creds := a.credentials()
	for i, u := range out.Plan.DatabaseUsers {
		out.Plan.DatabaseUsers[i].KeepsPassword = m.db.Accepts(ctx, database.MySQL, creds[u.Name])
	}
	if src.Upload != "" {
		if err := os.MkdirAll(m.h.Path(migrateDir), 0o700); err != nil {
			return MigrateInspection{}, opError(err)
		}
		if err := os.Rename(file, m.h.Path(migrateDir+"/"+src.Upload+".tar")); err != nil {
			return MigrateInspection{}, opError(err)
		}
		out.Source = src.Upload
	} else if src.Source != "" {
		out.Source = src.Source
	}
	out.FreeBackups = freeSpace(m.h.Path(Dir))
	out.FreeHome = freeSpace(m.h.Path("/home"))
	return out, nil
}

// MigrateDiscard removes an archive an inspection kept.
func (m *Manager) MigrateDiscard(src MigrateSource) error {
	if err := validateKey("source", src.Source); err != nil {
		return err
	}
	err := os.Remove(m.h.Path(migrateDir + "/" + src.Source + ".tar"))
	if errors.Is(err, os.ErrNotExist) {
		return nil
	}
	return opError(err)
}

func freeSpace(p string) int64 {
	var fs syscall.Statfs_t
	if syscall.Statfs(p, &fs) != nil {
		return 0
	}
	return int64(fs.Bavail) * int64(fs.Bsize)
}

// credentials returns the stored passwords of the database users.
func (a *cpAccount) credentials() map[string]database.Credential {
	out := map[string]database.Credential{}
	for _, u := range a.dbUsers() {
		out[u.name] = u.credential
	}
	return out
}

// MigrateSpec turns an account archive into Serfex site backups.
type MigrateSpec struct {
	MigrateSource
	Key string `json:"key"`
	Run string `json:"run"`
	// Account is the account the sites go under here.
	Account string `json:"account"`
	// Sites are the domains to bring (main and addon domains).
	Sites []string `json:"sites"`
	// Parts are what to bring: files, databases, mail, dns, certificates.
	Parts []string `json:"parts"`
	// IPv4 and IPv6 are this server's addresses: DNS records pointing at
	// the old server are moved to them.
	IPv4 string `json:"ipv4,omitempty"`
	IPv6 string `json:"ipv6,omitempty"`
}

// MigratedPassword is a database user's new password: the old server's
// could not be used here. Files are the settings it was written into.
type MigratedPassword struct {
	Name     string   `json:"name"`
	Password string   `json:"password"`
	Files    []string `json:"files"`
}

// MigrateResult describes the backups made.
type MigrateResult struct {
	Key       string             `json:"key"`
	Items     []ItemResult       `json:"items"`
	Size      int64              `json:"size"`
	Passwords []MigratedPassword `json:"passwords"`
	Notes     []MigrationNote    `json:"notes"`
}

var migrateParts = []string{"files", "databases", "mail", "dns", "certificates"}

func (s *MigrateSpec) validate() error {
	if err := validateKey("backup", s.Key); err != nil {
		return err
	}
	if s.Run != "" {
		if err := validateKey("run", s.Run); err != nil {
			return err
		}
	}
	if err := site.ValidateUser(s.Account); err != nil {
		return invalid("%s", err.Error())
	}
	if len(s.Sites) == 0 {
		return invalid("choose at least one site to move")
	}
	for _, p := range s.Parts {
		if !slices.Contains(migrateParts, p) {
			return invalid("part %q cannot be moved", p)
		}
	}
	if s.IPv4 != "" {
		if a, err := netip.ParseAddr(s.IPv4); err != nil || !a.Is4() {
			return invalid("ipv4 %q is not valid", s.IPv4)
		}
	}
	if s.IPv6 != "" {
		if a, err := netip.ParseAddr(s.IPv6); err != nil || !a.Is6() {
			return invalid("ipv6 %q is not valid", s.IPv6)
		}
	}
	return nil
}

// converter holds one conversion's state.
type converter struct {
	m       *Manager
	spec    MigrateSpec
	a       *cpAccount
	plan    MigrationPlan
	sites   []*convSite
	homeOf  *convSite
	routes  []convRoute
	resets  map[string]string
	changed map[string][]string
	dbs     map[string]*convSite
	notes   []MigrationNote
	homeTop map[string]bool
	work    string
	log     io.Writer
	ctx     context.Context
}

// convSite is one site being built.
type convSite struct {
	MigrationSite
	dir   string
	files *tgzWriter
	home  *tgzWriter
	mail  *tgzWriter
	dbs   []string
}

// convRoute sends a folder of the old home directory into a site: its
// document root, or a subdomain's. site nil drops it (a site not moved).
type convRoute struct {
	old  string
	site *convSite
	dest string
}

func (c *converter) has(part string) bool { return slices.Contains(c.spec.Parts, part) }

// MigrateConvert turns an account archive into Serfex site backups in a
// new backup set (spec.Key), one archive per site.
func (m *Manager) MigrateConvert(ctx context.Context, spec MigrateSpec) (res MigrateResult, err error) {
	if err := spec.validate(); err != nil {
		return MigrateResult{}, err
	}
	file, err := m.migratePath(spec.MigrateSource)
	if err != nil {
		return MigrateResult{}, err
	}
	release, err := m.acquire(ctx)
	if err != nil {
		return MigrateResult{}, err
	}
	defer release()
	ctx, cancel := context.WithTimeout(ctx, opTimeout)
	defer cancel()
	log, closeLog := m.logger(spec.Run)
	defer closeLog()
	defer func() {
		if err != nil {
			fmt.Fprintf(log, "✗ %s\n", i18n.T(ctx, "Taşıma durdu: %s", err))
			err = opError(err)
		}
	}()
	if err := m.dirs(); err != nil {
		return MigrateResult{}, err
	}
	fmt.Fprintf(log, "▸ %s\n", i18n.T(ctx, "Arşiv okunuyor: %s", filepath.Base(file)))
	a, err := readCPanel(file)
	if err != nil {
		if errors.Is(err, errNotCPanel) {
			return MigrateResult{}, invalid("%s", err.Error())
		}
		return MigrateResult{}, err
	}
	if fi, err := os.Stat(file); err == nil {
		if need := 2*fi.Size() + 1<<30; freeSpace(m.h.Path(Dir)) < need {
			return MigrateResult{}, invalid("there is not enough free disk space to convert the archive: %s needed", humanSize(need))
		}
	}
	setDirAbs := m.h.Path(setDir(spec.Key))
	if err := os.Mkdir(setDirAbs, 0o700); err != nil {
		return MigrateResult{}, invalid("backup %s exists already", spec.Key)
	}
	defer func() {
		if err != nil {
			os.RemoveAll(setDirAbs)
		}
	}()
	work := m.h.Path(workRoot + "/migrate-" + randomHex(8))
	if err := os.Mkdir(work, 0o700); err != nil {
		return MigrateResult{}, err
	}
	defer os.RemoveAll(work)

	c := &converter{m: m, spec: spec, a: a, plan: a.plan(m.now()), resets: map[string]string{}, changed: map[string][]string{},
		dbs: map[string]*convSite{}, homeTop: map[string]bool{}, work: work, log: log, ctx: ctx}
	if err := c.prepare(); err != nil {
		return MigrateResult{}, err
	}
	fmt.Fprintf(log, "▸ %s\n", i18n.T(ctx, "Dosyalar, veritabanları ve posta dönüştürülüyor"))
	if err := walkArchive(file, c.entry); err != nil {
		c.closeAll()
		return MigrateResult{}, err
	}
	if err := c.finish(); err != nil {
		return MigrateResult{}, err
	}
	res = MigrateResult{Key: spec.Key, Items: []ItemResult{}, Passwords: []MigratedPassword{}, Notes: c.notes}
	for _, s := range c.sites {
		ir, err := c.pack(s)
		if err != nil {
			return MigrateResult{}, fmt.Errorf("%s: %w", s.Domain, err)
		}
		res.Items = append(res.Items, ir)
		res.Size += ir.Size
		fmt.Fprintf(log, "▸ %s\n", i18n.T(ctx, "%s hazır (%s)", s.Domain, humanSize(ir.Size)))
	}
	users := slices.Sorted(func(yield func(string) bool) {
		for u := range c.resets {
			if !yield(u) {
				return
			}
		}
	})
	for _, u := range users {
		res.Passwords = append(res.Passwords, MigratedPassword{Name: u, Password: c.resets[u], Files: append([]string{}, c.changed[u]...)})
	}
	desc := Descriptor{Version: 1, Key: spec.Key, Server: a.source() + ":" + a.user, Scope: "site", Created: m.now().UTC(), Items: res.Items}
	if err := writeJSON(filepath.Join(setDirAbs, "backup.json"), desc); err != nil {
		return MigrateResult{}, err
	}
	fmt.Fprintln(log, "✓ "+i18n.T(ctx, "Arşiv dönüştürüldü."))
	return res, nil
}

// prepare picks the sites, the folders that go into each, and the
// database users that need new passwords.
func (c *converter) prepare() error {
	for _, want := range c.spec.Sites {
		i := slices.IndexFunc(c.plan.Sites, func(s MigrationSite) bool { return s.Domain == want })
		if i < 0 {
			return invalid("%s is not a site of the archive", want)
		}
	}
	for _, s := range c.plan.Sites {
		if !slices.Contains(c.spec.Sites, s.Domain) {
			c.routes = append(c.routes, convRoute{old: s.DocRoot})
			continue
		}
		cs := &convSite{MigrationSite: s, dir: filepath.Join(c.work, fmt.Sprint(len(c.sites)))}
		if err := os.Mkdir(cs.dir, 0o700); err != nil {
			return err
		}
		c.sites = append(c.sites, cs)
	}
	// The account's other files go with the main site, or the first one.
	c.homeOf = c.sites[0]
	if c.has("files") {
		for _, s := range c.sites {
			c.routes = append(c.routes, convRoute{old: s.DocRoot, site: s, dest: site.DefaultWebRoot})
			for _, sub := range s.Subdomains {
				if !strings.HasPrefix(sub.WebRoot, site.DefaultWebRoot) {
					c.routes = append(c.routes, convRoute{old: sub.DocRoot, site: s, dest: sub.WebRoot})
				}
			}
		}
		// The longest folder first: it wins.
		sort.SliceStable(c.routes, func(i, j int) bool { return len(c.routes[i].old) > len(c.routes[j].old) })
	}
	if c.has("databases") {
		for _, d := range c.plan.Databases {
			s := c.siteNamed(d.Site)
			if d.Site == "" {
				s = c.homeOf
			}
			if s != nil {
				c.dbs[d.Name] = s
				s.dbs = append(s.dbs, d.Name)
			}
		}
		creds := c.a.credentials()
		for _, u := range c.plan.DatabaseUsers {
			if !c.userMoves(u) {
				continue
			}
			if !c.m.db.Accepts(c.ctx, database.MySQL, creds[u.Name]) {
				c.resets[u.Name] = newPassword()
			}
		}
	}
	return nil
}

func (c *converter) siteNamed(d string) *convSite {
	for _, s := range c.sites {
		if s.Domain == d {
			return s
		}
	}
	return nil
}

// userMoves tells whether a database user has access to a database moved.
func (c *converter) userMoves(u MigrationDBUser) bool {
	for _, d := range u.Databases {
		if c.dbs[d] != nil {
			return true
		}
	}
	return false
}

// newPassword returns 24 letters and digits.
func newPassword() string {
	const chars = "abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789"
	b := make([]byte, 24)
	for i := range b {
		n, _ := rand.Int(rand.Reader, big.NewInt(int64(len(chars))))
		b[i] = chars[n.Int64()]
	}
	return string(b)
}

// homeSkip are folders of a cPanel home directory that do not move: mail
// and its settings move on their own, the rest is cPanel's.
var homeSkip = map[string]bool{
	"mail": true, "etc": true, "tmp": true, "logs": true, "access-logs": true, "ssl": true, ".cpanel": true,
	".trash": true, ".cphorde": true, ".cpaddons": true, ".ssh": true, ".lastlogin": true, ".contactemail": true,
	".cpanel-logs": true, ".cagefs": true, ".cl.selector": true, ".spamassassin": true, ".razor": true,
}

var oldBackupRe = regexp.MustCompile(`^(cpmove-.*|backup-.*)\.tar(\.gz)?$`)

// entry routes one entry of the archive.
func (c *converter) entry(name string, hdr *tar.Header, r io.Reader) error {
	if err := c.ctx.Err(); err != nil {
		return err
	}
	dir, base := path.Split(name)
	switch {
	case dir == "mysql/" && strings.HasSuffix(base, ".sql"):
		db := strings.TrimSuffix(base, ".sql")
		if s := c.dbs[db]; s != nil {
			return c.dump(s, db, r)
		}
	case strings.HasPrefix(name, "homedir/"):
		rel := strings.Trim(strings.TrimPrefix(name, "homedir/"), "/")
		if rel == "" {
			return nil
		}
		if strings.HasPrefix(rel, "mail/") {
			return c.mailEntry(strings.TrimPrefix(rel, "mail/"), hdr, r)
		}
		if c.has("files") {
			return c.homeEntry(rel, hdr, r)
		}
	}
	return nil
}

// target is where a path of the old home goes: an archive and the name
// in it; nil to leave it out.
func (c *converter) target(rel string) (*tgzWriter, string, error) {
	for _, rt := range c.routes {
		rest, ok := under(rel, rt.old)
		if !ok {
			continue
		}
		if rt.site == nil {
			return nil, "", nil
		}
		w, err := rt.site.filesWriter()
		return w, path.Join(rt.dest, rest), err
	}
	top, _, _ := strings.Cut(rel, "/")
	if homeSkip[top] || (top == rel && oldBackupRe.MatchString(top)) {
		return nil, "", nil
	}
	if top == "web" {
		// Serfex's sites live in web/.
		rel = "web.cpanel" + strings.TrimPrefix(rel, "web")
		top = "web.cpanel"
	}
	c.homeTop[top] = true
	w, err := c.homeOf.homeWriter()
	return w, rel, err
}

func (c *converter) homeEntry(rel string, hdr *tar.Header, r io.Reader) error {
	w, name, err := c.target(rel)
	if err != nil || w == nil {
		return err
	}
	if hdr.Typeflag == tar.TypeReg && configNames[path.Base(rel)] && hdr.Size <= configLimit && len(c.resets) > 0 {
		b, err := io.ReadAll(r)
		if err != nil {
			return err
		}
		if out, user, ok := rewriteDBPassword(b, c.resets); ok {
			b = out
			c.changed[user] = append(c.changed[user], c.newPath(w, name))
		}
		h := *hdr
		h.Size = int64(len(b))
		return w.add(name, &h, strings.NewReader(string(b)))
	}
	return w.add(name, hdr, r)
}

// newPath is where a file of an archive lands on this server.
func (c *converter) newPath(w *tgzWriter, name string) string {
	home := "/home/" + c.spec.Account
	for _, s := range c.sites {
		if s.files == w {
			return home + "/web/" + s.Domain + "/" + name
		}
	}
	return home + "/" + name
}

// mailFiles are Dovecot's own files, made again on the new server.
func mailFile(base string) bool {
	return strings.HasPrefix(base, "dovecot.index") || strings.HasPrefix(base, "dovecot.list.index") ||
		base == "dovecot.mailbox.log" || base == "maildirsize"
}

func (c *converter) mailEntry(rel string, hdr *tar.Header, r io.Reader) error {
	if !c.has("mail") {
		return nil
	}
	parts := strings.SplitN(rel, "/", 3)
	if len(parts) < 2 {
		return nil
	}
	d, box := parts[0], parts[1]
	s := c.siteNamed(d)
	if s == nil || !validLocal(box) || !slices.Contains(c.a.passwd[d], box) || c.a.mdbox[d][box] || mailFile(path.Base(rel)) {
		return nil
	}
	name := box + "/Maildir"
	if len(parts) == 3 {
		name += "/" + parts[2]
	}
	w, err := s.mailWriter()
	if err != nil {
		return err
	}
	return w.add(name, hdr, r)
}

// dump stores a database dump in the site's archive, compressed.
func (c *converter) dump(s *convSite, db string, r io.Reader) error {
	p := filepath.Join(s.dir, filepath.FromSlash(dumpMember(database.MySQL, db)))
	if err := os.MkdirAll(filepath.Dir(p), 0o700); err != nil {
		return err
	}
	f, err := os.OpenFile(p, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o600)
	if err != nil {
		return err
	}
	defer f.Close()
	gz := gzip.NewWriter(f)
	if _, err := io.Copy(gz, r); err != nil {
		return err
	}
	if err := gz.Close(); err != nil {
		return err
	}
	fmt.Fprintf(c.log, "  %s\n", i18n.T(c.ctx, "Veritabanı: %s", db))
	return f.Close()
}

// finish adds links that keep the old server's paths working, and closes
// the archives.
func (c *converter) finish() error {
	home := "/home/" + c.spec.Account
	if c.has("files") {
		for _, rt := range c.routes {
			if rt.site == nil || rt.old == "" {
				continue
			}
			to := home + "/web/" + rt.site.Domain + "/" + rt.dest
			parent := path.Dir(rt.old)
			if parent == "." {
				parent = ""
			}
			var w *tgzWriter
			var name string
			var err error
			if parent == "" {
				// /home/<user>/public_html keeps working.
				if w, err = c.homeOf.homeWriter(); err != nil {
					return err
				}
				name = rt.old
			} else {
				if w, name, err = c.target(parent); err != nil {
					return err
				}
				name = path.Join(name, path.Base(rt.old))
			}
			if w == nil || w.has(name) {
				continue
			}
			if err := w.symlink(name, to); err != nil {
				return err
			}
		}
		// What sat next to a document root in the home directory (a
		// Laravel application beside public_html) is linked next to it
		// again, so paths like ../laravel still lead there.
		tops := slices.Sorted(func(yield func(string) bool) {
			for t := range c.homeTop {
				if !yield(t) {
					return
				}
			}
		})
		for _, s := range c.sites {
			if strings.Contains(s.DocRoot, "/") {
				continue
			}
			w, err := s.filesWriter()
			if err != nil {
				return err
			}
			for _, t := range tops {
				if strings.HasPrefix(t, ".") || t == "www" || t == "public_ftp" || t == "perl5" || t == site.DefaultWebRoot || t == "logs" || t == "subdomains" || w.has(t) {
					continue
				}
				if err := w.symlink(t, home+"/"+t); err != nil {
					return err
				}
			}
		}
	}
	return c.closeAll()
}

func (c *converter) closeAll() error {
	var first error
	for _, s := range c.sites {
		for _, w := range []*tgzWriter{s.files, s.home, s.mail} {
			if w != nil {
				if err := w.close(); err != nil && first == nil {
					first = err
				}
			}
		}
	}
	return first
}

// pack writes a site's archive: its parts, its description (site.json)
// and manifest.
func (c *converter) pack(s *convSite) (ItemResult, error) {
	ir := ItemResult{Name: s.Domain, Parts: map[string]int64{}, Skipped: map[string]string{}, Incremental: []string{}, Warnings: []string{}}
	if c.has("files") && s.files == nil {
		// An empty document root still replaces the placeholder page.
		if _, err := s.filesWriter(); err != nil {
			return ir, err
		}
		if err := s.files.close(); err != nil {
			return ir, err
		}
	}
	for _, part := range []string{"files", "home", "mail"} {
		if fi, err := os.Stat(filepath.Join(s.dir, part+".tar.gz")); err == nil {
			ir.Parts[part] = fi.Size()
		}
	}
	meta := c.meta(s)
	if len(s.dbs) > 0 {
		var size int64
		for _, db := range s.dbs {
			size += fileSize(filepath.Join(s.dir, filepath.FromSlash(dumpMember(database.MySQL, db))))
		}
		ir.Parts["databases"] = size
		var creds []database.UserExport
		all := c.a.credentials()
		for _, u := range meta.DatabaseUsers {
			cr := all[u.Name]
			if pw, ok := c.resets[u.Name]; ok {
				cr = database.Credential{Password: pw}
			}
			creds = append(creds, database.UserExport{Server: database.MySQL, Name: u.Name, Credential: cr})
		}
		if err := writeJSON(filepath.Join(s.dir, "database-users.json"), creds); err != nil {
			return ir, err
		}
	}
	if c.has("certificates") && s.Certificate != nil {
		if crt := c.a.certFor(s.Domain, c.m.now()); crt != nil {
			if err := writeJSON(filepath.Join(s.dir, "certificates.json"), []certFile{{Name: s.Domain, Certificate: crt.chain, PrivateKey: crt.key}}); err != nil {
				return ir, err
			}
			ir.Parts["certificates"] = fileSize(filepath.Join(s.dir, "certificates.json"))
			in := cert.Describe(s.Domain, crt.leaf)
			meta.Certificates = []map[string]any{{
				"name": s.Domain, "kind": "site", "source": "custom", "domains": in.Names, "challenge": nil, "issuer": in.Issuer,
				"serial": in.Serial, "fingerprint": in.Fingerprint, "auto_renew": false,
				"not_before": in.NotBefore.Format(time.RFC3339), "not_after": in.NotAfter.Format(time.RFC3339), "in_use": true,
			}}
		}
	}
	if err := writeJSON(filepath.Join(s.dir, "site.json"), meta); err != nil {
		return ir, err
	}
	man := Manifest{Version: 1, Kind: "site", Name: s.Domain, Key: c.spec.Key, Server: c.a.source() + ":" + c.a.user, Created: c.m.now().UTC(), User: c.spec.Account, Parts: ir.Parts}
	for _, db := range s.dbs {
		man.Databases = append(man.Databases, DatabaseRef{Server: database.MySQL, Name: db})
	}
	if err := writeJSON(filepath.Join(s.dir, "manifest.json"), man); err != nil {
		return ir, err
	}
	out := c.m.h.Path(archivePath(c.spec.Key, s.Domain))
	if err := packDir(out, s.dir); err != nil {
		return ir, err
	}
	os.RemoveAll(s.dir)
	ir.Size = fileSize(out)
	return ir, nil
}

// siteMeta is site.json for a moved site, in the panel's form.
type siteMeta struct {
	Version       int              `json:"version"`
	Site          map[string]any   `json:"site"`
	Account       map[string]any   `json:"account"`
	Databases     []DatabaseRef    `json:"databases"`
	DatabaseUsers []metaDBUser     `json:"database_users"`
	Mail          map[string]any   `json:"mail"`
	DNS           map[string]any   `json:"dns"`
	Certificates  []map[string]any `json:"certificates"`
	Laravel       any              `json:"laravel"`
	// Migration is what only a move has: the aliases' zones, and where
	// the site came from.
	Migration map[string]any `json:"migration"`
}

type metaDBUser struct {
	Server string           `json:"server"`
	Name   string           `json:"name"`
	Hosts  []string         `json:"hosts"`
	Grants []database.Grant `json:"grants"`
}

func (c *converter) meta(s *convSite) siteMeta {
	aliases := []string{}
	for _, d := range append([]string{s.Domain}, s.Aliases...) {
		if d != s.Domain {
			aliases = append(aliases, d)
		}
		if !strings.HasPrefix(d, "www.") {
			aliases = append(aliases, "www."+d)
		}
	}
	subs := []map[string]string{}
	for _, sub := range s.Subdomains {
		subs = append(subs, map[string]string{"name": sub.Name, "web_root": sub.WebRoot})
	}
	m := siteMeta{
		Version: 1,
		Site: map[string]any{
			"domain": s.Domain, "aliases": aliases, "php_version": s.PHP, "web_server": "nginx", "web_root": site.DefaultWebRoot,
			"https_redirect": true, "subdomains": subs, "redirects": []any{}, "error_pages": nil, "nginx_directives": nil, "apache_directives": nil,
		},
		Account:       map[string]any{"name": c.spec.Account, "customer_id": nil},
		Databases:     []DatabaseRef{},
		DatabaseUsers: []metaDBUser{},
		Certificates:  []map[string]any{},
		Migration:     map[string]any{"source": c.a.source(), "user": c.a.user, "zones": []any{}},
	}
	for _, db := range s.dbs {
		m.Databases = append(m.Databases, DatabaseRef{Server: database.MySQL, Name: db})
	}
	users := c.a.dbUsers()
	for _, u := range c.plan.DatabaseUsers {
		cu := users[slices.IndexFunc(users, func(x *cpDBUser) bool { return x.name == u.Name })]
		var grants []database.Grant
		for _, g := range cu.grants(c.plan.Databases) {
			if slices.Contains(s.dbs, g.Database) {
				grants = append(grants, g)
			}
		}
		if len(grants) > 0 {
			m.DatabaseUsers = append(m.DatabaseUsers, metaDBUser{Server: database.MySQL, Name: u.Name, Hosts: []string{}, Grants: grants})
		}
	}
	if c.has("mail") {
		for _, md := range c.plan.Mail {
			if md.Domain != s.Domain {
				continue
			}
			fwd := c.a.forwarders(s.Domain)
			boxes := []map[string]any{}
			for _, b := range md.Mailboxes {
				hash, _ := mailHash(c.a.shadow[s.Domain][b.Name])
				var forward []string
				for _, f := range fwd {
					if f[0] == b.Name {
						forward = strings.Split(f[1], ",")
					}
				}
				boxes = append(boxes, map[string]any{
					"name": b.Name, "password_hash": hash, "quota_mb": b.QuotaMB, "forward_to": nonNil(forward),
					"keep_copy": true, "autoreply": nil,
				})
			}
			forwarders := []map[string]any{}
			for _, f := range fwd {
				if !slices.ContainsFunc(md.Mailboxes, func(b MigrationMailbox) bool { return b.Name == f[0] }) {
					forwarders = append(forwarders, map[string]any{"name": f[0], "destinations": strings.Split(f[1], ",")})
				}
			}
			m.Mail = map[string]any{"spam_filter": true, "manage_dns": true, "mailboxes": boxes, "forwarders": forwarders}
		}
	}
	if c.has("dns") {
		if rrs, ok := c.a.zones[s.Domain]; ok {
			m.DNS = map[string]any{"zone": map[string]any{"name": s.Domain}, "records": c.records(s.Domain, rrs)}
		}
		var zones []any
		for _, alias := range s.Aliases {
			if rrs, ok := c.a.zones[alias]; ok {
				zones = append(zones, map[string]any{"name": alias, "records": c.records(alias, rrs)})
			}
		}
		if zones != nil {
			m.Migration["zones"] = zones
		}
	}
	return m
}

func nonNil(s []string) []string {
	if s == nil {
		return []string{}
	}
	return s
}

// cpanelHosts are names cPanel adds to every zone for its own services.
var cpanelHosts = map[string]bool{
	"cpanel": true, "whm": true, "webmail": true, "webdisk": true, "cpcalendars": true, "cpcontacts": true,
	"autoconfig": true, "autodiscover": true, "_cpanel-dcv-test-record": true, "_caldav._tcp": true, "_caldavs._tcp": true,
	"_carddav._tcp": true, "_carddavs._tcp": true, "_autodiscover._tcp": true, "_acme-challenge": true,
}

// records turns a zone's records into the panel's form: names relative to
// the zone, records pointing at the old server pointed here, cPanel's own
// records, its name servers and its DKIM key left out.
func (c *converter) records(zone string, rrs []zoneRR) []map[string]any {
	old := map[string]bool{}
	for _, ip := range c.plan.OldIPs {
		if a, err := netip.ParseAddr(ip); err == nil {
			old[a.String()] = true
		}
	}
	local := func(host string) bool {
		host = strings.TrimSuffix(strings.ToLower(host), ".")
		return host == zone || host == "mail."+zone
	}
	out := []map[string]any{}
	for _, rr := range rrs {
		var name string
		switch {
		case rr.Name == zone:
			name = "@"
		case strings.HasSuffix(rr.Name, "."+zone):
			name = strings.TrimSuffix(rr.Name, "."+zone)
		default:
			continue
		}
		if cpanelHosts[name] || (rr.Type == "TXT" && strings.HasSuffix(name, "._domainkey")) {
			continue
		}
		ttl := min(max(rr.TTL, 60), 604800)
		rec := map[string]any{"name": name, "type": rr.Type, "priority": nil, "ttl": ttl}
		d := rr.Data
		host := func(s string) string { return absName(s, zone) + "." }
		switch rr.Type {
		case "A", "AAAA":
			a, err := netip.ParseAddr(d[0])
			if err != nil {
				continue
			}
			ip := a.String()
			if old[ip] {
				ip = c.spec.IPv4
				if rr.Type == "AAAA" {
					ip = c.spec.IPv6
				}
				if ip == "" {
					continue
				}
			}
			rec["content"] = ip
		case "CNAME":
			if name == "mail" && local(d[0]) {
				// Serfex gives mail.<domain> an address of its own.
				continue
			}
			rec["content"] = host(d[0])
		case "NS":
			if name == "@" {
				continue
			}
			rec["content"] = host(d[0])
		case "MX":
			if len(d) < 2 || local(d[1]) {
				// Mail delivered here gets Serfex's own MX record.
				continue
			}
			rec["priority"] = atoiOr(d[0], 10)
			rec["content"] = host(d[1])
		case "SRV":
			if len(d) < 4 {
				continue
			}
			rec["priority"] = atoiOr(d[0], 0)
			rec["content"] = d[1] + " " + d[2] + " " + host(d[3])
		case "TXT":
			txt := strings.Join(d, "")
			if c.spec.IPv4 != "" {
				for ip := range old {
					if a, _ := netip.ParseAddr(ip); a.Is4() {
						txt = strings.ReplaceAll(txt, "ip4:"+ip, "ip4:"+c.spec.IPv4)
					}
				}
			}
			rec["content"] = txt
		case "CAA":
			if len(d) < 3 {
				continue
			}
			rec["content"] = d[0] + " " + strings.ToLower(d[1]) + ` "` + strings.Join(d[2:], "") + `"`
		default:
			continue
		}
		out = append(out, rec)
	}
	return out
}

func atoiOr(s string, def int) int {
	var n int
	if _, err := fmt.Sscan(s, &n); err != nil || n < 0 || n > 65535 {
		return def
	}
	return n
}

var (
	pwDefine   = [2]*regexp.Regexp{regexp.MustCompile(`define\(\s*['"](?:DB_USER|DB_USERNAME)['"]\s*,\s*['"]([^'"]+)['"]\s*\)`), regexp.MustCompile(`(define\(\s*['"](?:DB_PASSWORD|DB_PASS)['"]\s*,\s*)('(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*")`)}
	pwEnv      = [2]*regexp.Regexp{regexp.MustCompile(`(?m)^\s*DB_USERNAME\s*=\s*["']?([^"'\s#]+)`), regexp.MustCompile(`(?m)^(\s*DB_PASSWORD\s*=)[^\r\n]*`)}
	pwProperty = [2]*regexp.Regexp{regexp.MustCompile(`public\s+\$user\s*=\s*['"]([^'"]+)['"]`), regexp.MustCompile(`(public\s+\$password\s*=\s*)('(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*")`)}
	pwArray    = [2]*regexp.Regexp{regexp.MustCompile(`['"]username['"]\s*=>\s*['"]([^'"]+)['"]`), regexp.MustCompile(`(['"]password['"]\s*=>\s*)('(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*")`)}
)

// rewriteDBPassword writes a database user's new password into a site's
// settings file (wp-config.php, .env, Joomla's configuration.php, PHP
// arrays) when the file names exactly that user. It returns the new
// content and the user.
func rewriteDBPassword(b []byte, resets map[string]string) ([]byte, string, bool) {
	for _, re := range [][2]*regexp.Regexp{pwDefine, pwEnv, pwProperty, pwArray} {
		users := map[string]bool{}
		for _, m := range re[0].FindAllSubmatch(b, -1) {
			users[string(m[1])] = true
		}
		if len(users) != 1 {
			continue
		}
		var user string
		for u := range users {
			user = u
		}
		pw, ok := resets[user]
		if !ok || !re[1].Match(b) {
			continue
		}
		out := re[1].ReplaceAllFunc(b, func(m []byte) []byte {
			sub := re[1].FindSubmatch(m)
			if re == pwEnv {
				return append(append([]byte{}, sub[1]...), pw...)
			}
			q := sub[2][0]
			return append(append(append(append([]byte{}, sub[1]...), q), pw...), q)
		})
		return out, user, true
	}
	return b, "", false
}

// tgzWriter writes a .tar.gz part.
type tgzWriter struct {
	f    *os.File
	bw   *bufio.Writer
	gz   *gzip.Writer
	tw   *tar.Writer
	seen map[string]bool
}

func newTgz(file string) (*tgzWriter, error) {
	f, err := os.OpenFile(file, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o600)
	if err != nil {
		return nil, err
	}
	bw := bufio.NewWriterSize(f, 1<<20)
	gz, _ := gzip.NewWriterLevel(bw, gzip.BestSpeed)
	return &tgzWriter{f: f, bw: bw, gz: gz, tw: tar.NewWriter(gz), seen: map[string]bool{}}, nil
}

func (s *convSite) filesWriter() (*tgzWriter, error) {
	if s.files == nil {
		w, err := newTgz(filepath.Join(s.dir, "files.tar.gz"))
		if err != nil {
			return nil, err
		}
		s.files = w
		if err := w.add(site.DefaultWebRoot, &tar.Header{Typeflag: tar.TypeDir, Mode: 0o755, ModTime: time.Now()}, nil); err != nil {
			return nil, err
		}
	}
	return s.files, nil
}

func (s *convSite) homeWriter() (*tgzWriter, error) {
	if s.home == nil {
		w, err := newTgz(filepath.Join(s.dir, "home.tar.gz"))
		if err != nil {
			return nil, err
		}
		s.home = w
	}
	return s.home, nil
}

func (s *convSite) mailWriter() (*tgzWriter, error) {
	if s.mail == nil {
		w, err := newTgz(filepath.Join(s.dir, "mail.tar.gz"))
		if err != nil {
			return nil, err
		}
		s.mail = w
	}
	return s.mail, nil
}

func (w *tgzWriter) has(name string) bool { return w.seen[strings.TrimSuffix(name, "/")] }

// add writes an entry under a new name. Owners are dropped (the archive is
// unpacked by the account), as are set-id bits and special files; a hard
// link stays only when its target is in the same archive.
func (w *tgzWriter) add(name string, hdr *tar.Header, r io.Reader) error {
	name = strings.TrimSuffix(name, "/")
	if name == "" || name == "." || w.seen[name] {
		return nil
	}
	h := &tar.Header{Name: name, Mode: hdr.Mode & 0o1777, ModTime: hdr.ModTime, Typeflag: hdr.Typeflag, Format: tar.FormatPAX}
	switch hdr.Typeflag {
	case tar.TypeReg:
		h.Size = hdr.Size
	case tar.TypeDir:
		h.Name += "/"
	case tar.TypeSymlink:
		h.Linkname = hdr.Linkname
	case tar.TypeLink:
		return nil
	default:
		return nil
	}
	if h.ModTime.IsZero() {
		h.ModTime = time.Now()
	}
	if err := w.tw.WriteHeader(h); err != nil {
		return err
	}
	w.seen[name] = true
	if hdr.Typeflag == tar.TypeReg && r != nil {
		if _, err := io.CopyN(w.tw, r, hdr.Size); err != nil {
			return fmt.Errorf("the archive is damaged: %w", err)
		}
	}
	return nil
}

func (w *tgzWriter) symlink(name, target string) error {
	return w.add(name, &tar.Header{Typeflag: tar.TypeSymlink, Linkname: target, Mode: 0o777, ModTime: time.Now()}, nil)
}

func (w *tgzWriter) close() error {
	if w.f == nil {
		return nil
	}
	err := w.tw.Close()
	if e := w.gz.Close(); err == nil {
		err = e
	}
	if e := w.bw.Flush(); err == nil {
		err = e
	}
	if e := w.f.Close(); err == nil {
		err = e
	}
	w.f = nil
	return err
}
