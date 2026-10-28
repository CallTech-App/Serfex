package backup

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"strings"
	"time"

	"github.com/calltech-app/serfex/agent/internal/database"
	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/i18n"
	"github.com/calltech-app/serfex/agent/internal/laravel"
	"github.com/calltech-app/serfex/agent/internal/site"
)

// Siteyi kopyala: a site's files and databases copied onto another site
// of the same account, for a staging copy, to bring a staging copy up to
// date, or to publish it back to the live site. The panel creates the
// target site and its databases first; this copies into them.
//
// The files are packed by the account and unpacked by the account into a
// new folder next to the target's (tar through a pipe, as backups do),
// fitted to the new site there, and swapped in with two renames, so the
// target serves either its old files or the new ones. Its logs folder
// stays its own. Fitting means:
//
//   - settings files (wp-config.php, .env, Joomla's configuration.php, PHP
//     arrays) get the target's database names and host names;
//   - symbolic links that pointed into the source folder point into the
//     target's;
//   - Laravel's cached configuration (bootstrap/cache/config.php), which
//     holds the source's database and address, is removed.
//
// Each database is dumped and loaded into its counterpart through a pipe;
// the target's database users keep their grants. A WordPress copy then
// gets its new address in its database (WP-CLI search-replace).

// CopySite is one side of a copy.
type CopySite struct {
	Domain     string `json:"domain"`
	User       string `json:"user"`
	PHPVersion string `json:"php_version"`
}

func (s CopySite) ref() SiteRef { return SiteRef{Domain: s.Domain, User: s.User} }

// CopyDatabase loads database From into database To.
type CopyDatabase struct {
	Server string `json:"server"`
	From   string `json:"from"`
	To     string `json:"to"`
}

// CopySpec copies a site onto another.
type CopySpec struct {
	Run   string   `json:"run"`
	From  CopySite `json:"from"`
	To    CopySite `json:"to"`
	Files bool     `json:"files"`
	// Databases are loaded in order; each target database is replaced.
	Databases []CopyDatabase `json:"databases"`
	// Hosts pairs the source's host names with the target's
	// ("www.example.com", "staging.example.com"): addresses with the old
	// name get the new one in settings files and in WordPress.
	Hosts [][2]string `json:"hosts"`
	// WordPress is the folder of the site's WordPress, relative to the
	// site directory; empty when it has none.
	WordPress string `json:"wordpress"`
	// Public is what the copied WordPress tells search engines: "0" stay
	// away, "1" welcome, "keep" what the target said before the copy,
	// empty what the source said.
	Public string `json:"public"`
}

// CopyNote is something the copy could not do by itself. Code is one of
// db_not_in_config (Subject: the database), config_cache (a Laravel
// application whose cached configuration was removed), links (how many
// links were pointed at the target) and changed_while_reading.
type CopyNote struct {
	Code    string `json:"code"`
	Subject string `json:"subject"`
}

// CopyResult says what the copy did.
type CopyResult struct {
	Bytes  int64      `json:"bytes"`
	Notes  []CopyNote `json:"notes"`
	Millis int64      `json:"ms"`
}

const (
	maxCopyDatabases = 50
	maxCopyHosts     = 20
	// copyMargin is left free on the disk on top of the copy.
	copyMargin = 512 << 20
	// copyWalkLimit bounds the folders and files looked at for links and
	// settings files.
	copyWalkLimit = 300000
	configDepth   = 6
)

func (s *CopySpec) validate() error {
	if err := validateKey("run", s.Run); err != nil {
		return err
	}
	for _, c := range []CopySite{s.From, s.To} {
		if err := c.ref().validate(); err != nil {
			return err
		}
		if err := site.ValidatePHPVersion(c.PHPVersion); err != nil {
			return invalid("%s", err.Error())
		}
	}
	if s.From.User != s.To.User {
		return invalid("both sites must belong to the same account")
	}
	if s.From.Domain == s.To.Domain {
		return invalid("a site cannot be copied onto itself")
	}
	if !s.Files && len(s.Databases) == 0 {
		return invalid("nothing to copy")
	}
	if len(s.Databases) > maxCopyDatabases {
		return invalid("at most %d databases can be copied at once", maxCopyDatabases)
	}
	seen := map[string]bool{}
	for _, d := range s.Databases {
		for _, n := range []string{d.From, d.To} {
			if err := (database.DatabaseSpec{Server: d.Server, Name: n}).Validate(); err != nil {
				return err
			}
		}
		if d.From == d.To || seen[d.Server+"/"+d.To] {
			return invalid("database %s cannot be loaded twice or from itself", d.To)
		}
		seen[d.Server+"/"+d.To] = true
	}
	if len(s.Hosts) > maxCopyHosts {
		return invalid("at most %d host names are allowed", maxCopyHosts)
	}
	for _, h := range s.Hosts {
		for _, n := range h {
			if err := site.ValidateDomain(n); err != nil {
				return invalid("%s", err.Error())
			}
		}
	}
	if s.WordPress != "" {
		if err := site.ValidateRelPath(s.WordPress); err != nil {
			return invalid("wordpress: %s", err.Error())
		}
	}
	if !slices.Contains([]string{"", "0", "1", "keep"}, s.Public) {
		return invalid("public must be 0, 1, keep or empty")
	}
	return nil
}

// Copy copies a site's files and databases onto another site of the same
// account. It waits for a backup or restore in progress.
func (m *Manager) Copy(ctx context.Context, spec CopySpec) (res CopyResult, err error) {
	if err := spec.validate(); err != nil {
		return CopyResult{}, err
	}
	src, dst := spec.From.ref(), spec.To.ref()
	for _, s := range []SiteRef{src, dst} {
		if !m.isDir(s.webDir()) {
			return CopyResult{}, invalid("site %s does not exist", s.Domain)
		}
	}
	release, err := m.acquire(ctx)
	if err != nil {
		return CopyResult{}, err
	}
	defer release()
	ctx, cancel := context.WithTimeout(ctx, opTimeout)
	defer cancel()
	log, closeLog := m.logger(spec.Run)
	defer closeLog()
	defer func() {
		if err != nil {
			fmt.Fprintf(log, "✗ %s\n", i18n.T(ctx, "Kopyalama durdu: %s", err))
			err = opError(err)
		}
	}()
	start := time.Now()
	res.Notes = []CopyNote{}
	fmt.Fprintf(log, "▸ %s\n", i18n.T(ctx, "Kopyalama başladı: %s → %s", src.Domain, dst.Domain))

	var wp *laravel.App
	if spec.WordPress != "" && m.laravel != nil {
		wp = &laravel.App{Target: laravel.Target{Domain: dst.Domain, User: dst.User, PHPVersion: spec.To.PHPVersion}, Path: spec.WordPress}
	}
	public := spec.Public
	if public == "keep" {
		// What the target tells search engines now, before its database
		// is replaced; a target without WordPress yet keeps the source's.
		public = ""
		if wp != nil && m.exists(dst.webDir()+"/"+spec.WordPress+"/wp-includes/version.php") {
			if v, err := m.laravel.WPOption(ctx, *wp, "blog_public"); err == nil && (v == "0" || v == "1") {
				public = v
			}
		}
	}

	if spec.Files {
		notes, size, err := m.copyFiles(ctx, spec, log)
		if err != nil {
			return CopyResult{}, fmt.Errorf("files: %w", err)
		}
		res.Notes, res.Bytes = append(res.Notes, notes...), size
	}
	for _, d := range spec.Databases {
		fmt.Fprintf(log, "▸ %s\n", i18n.T(ctx, "Veritabanı kopyalanıyor: %s → %s", d.From, d.To))
		if err := m.copyDatabase(ctx, d, dst.Domain); err != nil {
			return CopyResult{}, fmt.Errorf("database %s: %w", d.From, err)
		}
	}
	if wp != nil && m.exists(dst.webDir()+"/"+spec.WordPress+"/wp-includes/version.php") {
		fmt.Fprintf(log, "▸ %s\n", i18n.T(ctx, "WordPress yeni adresine göre düzenleniyor"))
		if err := m.laravel.WPMove(ctx, laravel.WPMoveSpec{App: *wp, Replace: spec.Hosts, Public: public}, log); err != nil {
			return CopyResult{}, fmt.Errorf("wordpress: %w", err)
		}
	}
	res.Millis = time.Since(start).Milliseconds()
	fmt.Fprintln(log, "✓ "+i18n.T(ctx, "Kopyalama bitti."))
	return res, nil
}

// copyDatabase dumps one database into another through a pipe.
func (m *Manager) copyDatabase(ctx context.Context, d CopyDatabase, site string) error {
	pr, pw := io.Pipe()
	done := make(chan error, 1)
	go func() {
		err := m.db.Dump(ctx, d.Server, d.From, pw)
		pw.CloseWithError(err)
		done <- err
	}()
	err := m.db.Load(ctx, d.Server, d.To, site, pr)
	pr.CloseWithError(errors.New("the load stopped"))
	if dumpErr := <-done; dumpErr != nil && err == nil {
		err = dumpErr
	}
	return err
}

// copyFiles puts a copy of the source's folder (without its logs) in
// place of the target's. It returns the notes and the bytes copied.
func (m *Manager) copyFiles(ctx context.Context, spec CopySpec, log io.Writer) ([]CopyNote, int64, error) {
	user := spec.To.User
	srcDir, dstDir := spec.From.ref().webDir(), spec.To.ref().webDir()
	parent, base := filepath.Dir(dstDir), filepath.Base(dstDir)
	next, old := parent+"/."+base+".serfex-copy", parent+"/."+base+".serfex-old"
	rm := func(p string) error {
		_, err := m.h.Run(context.WithoutCancel(ctx), host.AsUser(user, host.Command{Name: "rm", Args: []string{"-rf", "--one-file-system", p}}))
		return err
	}
	for _, p := range []string{next, old} {
		if m.exists(p) {
			if err := rm(p); err != nil {
				return nil, 0, fmt.Errorf("removing %s failed", p)
			}
		}
	}
	size := treeSize(m.h.Path(srcDir), "logs")
	if free := freeSpace(m.h.Path(parent)); free > 0 && free < size+copyMargin {
		return nil, 0, errors.New(i18n.T(ctx, "diskte yeterli yer yok: kopya için %s gerekiyor, %s boş", humanSize(size+copyMargin), humanSize(free)))
	}
	fmt.Fprintf(log, "▸ %s\n", i18n.T(ctx, "Dosyalar kopyalanıyor (%s)", humanSize(size)))
	if _, err := m.h.Run(ctx, host.AsUser(user, host.Command{Name: "mkdir", Args: []string{"-p", next}})); err != nil {
		return nil, 0, fmt.Errorf("creating %s failed", next)
	}
	var notes []CopyNote
	warn, err := m.pipeTree(ctx, user, srcDir, next)
	if err != nil {
		_ = rm(next)
		return nil, 0, err
	}
	if warn != "" {
		notes = append(notes, CopyNote{Code: "changed_while_reading", Subject: warn})
	}
	fitted, err := m.fitCopy(ctx, spec, next, log)
	if err != nil {
		_ = rm(next)
		return nil, 0, err
	}
	notes = append(notes, fitted...)

	// The target keeps its own logs: PHP keeps writing where it was.
	if m.isDir(dstDir + "/logs") {
		if _, err := m.h.Run(ctx, host.AsUser(user, host.Command{Name: "mv", Args: []string{"-T", dstDir + "/logs", next + "/logs"}})); err != nil {
			_ = rm(next)
			return nil, 0, errors.New("moving the logs folder failed")
		}
	} else if _, err := m.h.Run(ctx, host.AsUser(user, host.Command{Name: "mkdir", Args: []string{"-p", next + "/logs"}})); err != nil {
		_ = rm(next)
		return nil, 0, errors.New("creating the logs folder failed")
	}
	if _, err := m.h.Run(ctx, host.AsUser(user, host.Command{Name: "mv", Args: []string{"-T", dstDir, old}})); err != nil {
		_, _ = m.h.Run(context.WithoutCancel(ctx), host.AsUser(user, host.Command{Name: "mv", Args: []string{"-T", next + "/logs", dstDir + "/logs"}}))
		_ = rm(next)
		return nil, 0, errors.New("moving the target's files aside failed")
	}
	if _, err := m.h.Run(ctx, host.AsUser(user, host.Command{Name: "mv", Args: []string{"-T", next, dstDir}})); err != nil {
		_, _ = m.h.Run(context.WithoutCancel(ctx), host.AsUser(user, host.Command{Name: "mv", Args: []string{"-T", old, dstDir}}))
		_, _ = m.h.Run(context.WithoutCancel(ctx), host.AsUser(user, host.Command{Name: "mv", Args: []string{"-T", next + "/logs", dstDir + "/logs"}}))
		_ = rm(next)
		return nil, 0, errors.New("putting the copied files in place failed")
	}
	_ = rm(old)
	fmt.Fprintf(log, "▸ %s\n", i18n.T(ctx, "Dosyalar yerine kondu"))
	return notes, size, nil
}

// pipeTree packs src (without its logs folder) as user and unpacks it
// into dir as user. warn is set when files changed while being read.
func (m *Manager) pipeTree(ctx context.Context, user, src, dir string) (warn string, err error) {
	pr, pw := io.Pipe()
	type packed struct {
		out string
		err error
	}
	done := make(chan packed, 1)
	go func() {
		c := m.slow(host.AsUser(user, host.Command{Name: "tar", Args: []string{"-cf", "-", "--one-file-system", "--exclude=./logs", "-C", src, "."}, Stdout: pw}))
		out, err := m.h.Run(ctx, c)
		pw.CloseWithError(err)
		done <- packed{out, err}
	}()
	c := m.slow(host.AsUser(user, host.Command{Name: "tar", Args: []string{"-xpf", "-", "--no-same-owner", "--delay-directory-restore", "-C", dir}, Input: pr}))
	out, err := m.h.Run(ctx, c)
	pr.CloseWithError(errors.New("unpacking stopped"))
	p := <-done
	if p.err != nil {
		if host.ExitCode(p.err) != 1 {
			return "", fmt.Errorf("packing the files failed: %s", firstLine(p.out))
		}
		warn = firstLine(p.out)
	}
	if err != nil {
		return "", fmt.Errorf("unpacking the files failed: %s", firstLine(out))
	}
	return warn, nil
}

// treeSize adds up the regular files below dir, leaving out the top-level
// folder skip. Root reads metadata only.
func treeSize(dir, skip string) int64 {
	var n int64
	_ = filepath.WalkDir(dir, func(p string, e fs.DirEntry, err error) error {
		if err != nil {
			return nil
		}
		if e.IsDir() && p == filepath.Join(dir, skip) {
			return filepath.SkipDir
		}
		if e.Type().IsRegular() {
			if fi, err := e.Info(); err == nil {
				n += fi.Size()
			}
		}
		return nil
	})
	return n
}

// skipFit are folders whose files are never settings files of the site.
var skipFit = map[string]bool{"node_modules": true, "vendor": true, ".git": true, "uploads": true, "cache": true, "storage": true}

// fitCopy fits the unpacked copy in dir to the target site: links, settings
// files and Laravel's cached configuration.
func (m *Manager) fitCopy(ctx context.Context, spec CopySpec, dir string, log io.Writer) ([]CopyNote, error) {
	user := spec.To.User
	srcDir, dstDir := spec.From.ref().webDir(), spec.To.ref().webDir()
	var links, configs, laravelApps []string
	seen := 0
	root := m.h.Path(dir)
	err := filepath.WalkDir(root, func(p string, e fs.DirEntry, err error) error {
		if err != nil {
			return nil
		}
		if seen++; seen > copyWalkLimit {
			return filepath.SkipAll
		}
		rel, _ := filepath.Rel(root, p)
		depth := strings.Count(rel, string(filepath.Separator))
		switch {
		case e.Type()&fs.ModeSymlink != 0:
			if target, err := os.Readlink(p); err == nil && (target == srcDir || strings.HasPrefix(target, srcDir+"/")) {
				links = append(links, rel)
			}
		case e.IsDir():
			if rel != "." && skipFit[e.Name()] {
				return filepath.SkipDir
			}
		case e.Type().IsRegular() && depth < configDepth:
			if configNames[e.Name()] {
				configs = append(configs, rel)
			}
			if e.Name() == "artisan" {
				laravelApps = append(laravelApps, filepath.Dir(rel))
			}
		}
		return nil
	})
	if err != nil {
		return nil, err
	}

	var notes []CopyNote
	for _, rel := range links {
		target, _ := os.Readlink(filepath.Join(root, rel))
		abs := dir + "/" + rel
		next := dstDir + strings.TrimPrefix(target, srcDir)
		if _, err := m.h.Run(ctx, host.AsUser(user, host.Command{Name: "rm", Args: []string{"-f", abs}})); err != nil {
			return nil, fmt.Errorf("replacing the link %s failed", rel)
		}
		if _, err := m.h.Run(ctx, host.AsUser(user, host.Command{Name: "ln", Args: []string{"-s", next, abs}})); err != nil {
			return nil, fmt.Errorf("replacing the link %s failed", rel)
		}
	}
	if len(links) > 0 {
		notes = append(notes, CopyNote{Code: "links", Subject: fmt.Sprint(len(links))})
	}

	names := map[string]string{}
	for _, d := range spec.Databases {
		names[d.From] = d.To
	}
	used := map[string]bool{}
	for _, rel := range configs {
		abs := dir + "/" + rel
		if fi, err := os.Lstat(m.h.Path(abs)); err != nil || fi.Size() > configLimit {
			continue
		}
		var buf bytes.Buffer
		if _, err := m.h.Run(ctx, host.AsUser(user, host.Command{Name: "cat", Args: []string{abs}, Stdout: &buf})); err != nil {
			continue
		}
		out, hits := fitConfig(buf.Bytes(), names, spec.Hosts)
		for _, n := range hits {
			used[n] = true
		}
		if bytes.Equal(out, buf.Bytes()) {
			continue
		}
		if _, err := m.h.Run(ctx, host.AsUser(user, host.Command{Name: "dd", Args: []string{"of=" + abs, "status=none"}, Stdin: out})); err != nil {
			return nil, fmt.Errorf("writing %s failed", rel)
		}
		fmt.Fprintf(log, "  %s\n", i18n.T(ctx, "%s yeni siteye göre düzenlendi", rel))
	}
	for _, d := range spec.Databases {
		if !used[d.From] {
			notes = append(notes, CopyNote{Code: "db_not_in_config", Subject: d.From})
		}
	}
	for _, app := range laravelApps {
		cached := dir + "/" + app + "/bootstrap/cache/config.php"
		if app == "." {
			cached = dir + "/bootstrap/cache/config.php"
		}
		if !m.exists(cached) {
			continue
		}
		if _, err := m.h.Run(ctx, host.AsUser(user, host.Command{Name: "rm", Args: []string{"-f", cached}})); err != nil {
			return nil, fmt.Errorf("removing %s failed", cached)
		}
		notes = append(notes, CopyNote{Code: "config_cache", Subject: app})
	}
	return notes, nil
}

// dbNameRes find a database name in settings files: WordPress and
// OpenCart constants, .env, Joomla, PHP arrays (Drupal, Magento,
// PrestaShop), PHP variables and PDO DSNs. The value is the second group.
var dbNameRes = []*regexp.Regexp{
	regexp.MustCompile(`(define\(\s*['"](?:DB_NAME|DB_DATABASE)['"]\s*,\s*['"])([^'"]+)(['"])`),
	regexp.MustCompile(`(?m)(^\s*DB_DATABASE\s*=\s*["']?)([A-Za-z0-9_$-]+)(["']?)`),
	regexp.MustCompile(`(public\s+\$db\s*=\s*['"])([^'"]+)(['"])`),
	regexp.MustCompile(`(['"](?:database|dbname|db_name|DB_NAME|DB_DATABASE)['"]\s*=>\s*['"])([^'"]+)(['"])`),
	regexp.MustCompile(`(\$(?:db_?name|database|DB_NAME)\s*=\s*['"])([^'"]+)(['"])`),
	regexp.MustCompile(`(dbname=)([A-Za-z0-9_$-]+)()`),
}

// fitConfig puts the target's database names and host names into a
// settings file. It returns the new content and the source databases it
// found.
func fitConfig(b []byte, names map[string]string, hosts [][2]string) ([]byte, []string) {
	var hits []string
	for _, re := range dbNameRes {
		b = re.ReplaceAllFunc(b, func(m []byte) []byte {
			sub := re.FindSubmatch(m)
			to, ok := names[string(sub[2])]
			if !ok {
				return m
			}
			hits = append(hits, string(sub[2]))
			return append(append(append([]byte{}, sub[1]...), to...), sub[3]...)
		})
	}
	for _, h := range hosts {
		b = replaceHost(b, h[0], h[1])
	}
	return b, hits
}

// replaceHost turns ://from (and :\/\/from) into ://to where from is the
// whole host name: ://example.com changes, ://example.com.tr does not.
func replaceHost(b []byte, from, to string) []byte {
	if from == to {
		return b
	}
	for _, sep := range []string{"://", `:\/\/`} {
		old := []byte(sep + from)
		var out []byte
		rest := b
		for {
			i := bytes.Index(rest, old)
			if i < 0 {
				out = append(out, rest...)
				break
			}
			end := i + len(old)
			if end < len(rest) && isHostByte(rest[end]) {
				out = append(out, rest[:end]...)
			} else {
				out = append(append(out, rest[:i]...), sep+to...)
			}
			rest = rest[end:]
		}
		b = out
	}
	return b
}

func isHostByte(c byte) bool {
	return c == '.' || c == '-' || c >= 'a' && c <= 'z' || c >= 'A' && c <= 'Z' || c >= '0' && c <= '9'
}
