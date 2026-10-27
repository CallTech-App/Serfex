package security

import (
	"bufio"
	"context"
	"errors"
	"io"
	"io/fs"
	"os"
	"path"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"syscall"
	"time"

	"github.com/calltech-app/serfex/agent/internal/files"
	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/site"
)

// Severities, from worst.
const (
	SeverityCritical = "critical"
	SeverityHigh     = "high"
	SeverityMedium   = "medium"
	SeverityLow      = "low"
)

// Checks a finding comes from.
const (
	CheckMalware    = "malware"    // ClamAV found a virus signature
	CheckWebShell   = "webshell"   // a PHP backdoor pattern
	CheckSuspicious = "suspicious" // odd code worth a look
	CheckWritable   = "writable"   // anyone on the server may change it
	CheckOwner      = "owner"      // not owned by the site's account
	CheckExposed    = "exposed"    // secrets or backups under the web root
	CheckOutdated   = "outdated"   // software past its security support
	CheckDebug      = "debug"      // Laravel debug mode in production
)

// Fixes the panel offers for a finding.
const (
	FixQuarantine  = "quarantine"  // move into the site's trash, restorable
	FixPermissions = "permissions" // take away write access for others
	FixOwner       = "owner"       // give it to the site's account
	FixDeny        = "deny"        // Nginx refuses the path
)

// Limits of one site's scan.
const (
	maxEntries   = 300000
	maxContent   = 2 << 20
	perCheckList = 50
)

// Minimum supported versions; older ones get an "outdated" finding.
var (
	// WordPress fixes security problems in recent branches only.
	wordPressMinimum = []int{6, 6}
	// Laravel 11's security support ended in March 2026.
	laravelMinimum = 12
	// PHP 8.1 reached its end of life at the end of 2025.
	phpMinimum = []int{8, 2}
)

// ScanSite is one site to scan.
type ScanSite struct {
	Domain     string `json:"domain"`
	User       string `json:"user"`
	PHPVersion string `json:"php_version"`
	// WebRoot is the folder Nginx serves, relative to the site folder
	// (public_html, laravel/current/public).
	WebRoot string `json:"web_root"`
}

// ScanSpec lists the sites to scan.
type ScanSpec struct {
	Sites []ScanSite `json:"sites"`
}

// Finding is one problem.
type Finding struct {
	Check    string `json:"check"`
	Code     string `json:"code"`
	Severity string `json:"severity"`
	// Path is relative to the site folder; "" is the folder itself.
	Path string `json:"path"`
	// URL is the path a visitor would fetch, for files under the web root.
	URL    string   `json:"url,omitempty"`
	Detail string   `json:"detail,omitempty"`
	Fixes  []string `json:"fixes"`
	// Count is how many entries a summary finding stands for.
	Count int `json:"count,omitempty"`
}

// SiteScan is one site's result.
type SiteScan struct {
	Domain   string    `json:"domain"`
	Files    int       `json:"files"`
	Findings []Finding `json:"findings"`
	Error    string    `json:"error,omitempty"`
	// Truncated is set when the site had more entries than are scanned.
	Truncated bool `json:"truncated,omitempty"`
}

// ScanResult is the result of a scan.
type ScanResult struct {
	ClamAV     bool       `json:"clamav"`
	Sites      []SiteScan `json:"sites"`
	StartedAt  time.Time  `json:"started_at"`
	FinishedAt time.Time  `json:"finished_at"`
}

// Scan checks sites one after the other. A site that cannot be scanned
// gets an error; the others still are.
func (m *Manager) Scan(ctx context.Context, spec ScanSpec) (ScanResult, error) {
	if len(spec.Sites) == 0 {
		return ScanResult{}, invalid("no sites to scan")
	}
	for _, s := range spec.Sites {
		if err := site.ValidateDomain(s.Domain); err != nil {
			return ScanResult{}, err
		}
		if err := site.ValidateUser(s.User); err != nil {
			return ScanResult{}, err
		}
		if s.WebRoot != "" {
			if err := site.ValidateRelPath(s.WebRoot); err != nil {
				return ScanResult{}, err
			}
		}
	}
	m.scan.Lock()
	defer m.scan.Unlock()
	res := ScanResult{ClamAV: m.clamAvailable(), StartedAt: m.Now().UTC(), Sites: []SiteScan{}}
	for _, s := range spec.Sites {
		if ctx.Err() != nil {
			return res, &OperationError{ctx.Err()}
		}
		res.Sites = append(res.Sites, m.scanSite(ctx, s, res.ClamAV))
	}
	res.FinishedAt = m.Now().UTC()
	return res, nil
}

func (m *Manager) clamAvailable() bool {
	return m.exists("/usr/bin/clamdscan") && m.exists("/run/clamav/clamd.ctl")
}

type walker struct {
	m        *Manager
	ctx      context.Context
	s        ScanSite
	root     string // host path of the site folder
	docroot  string // relative web root, "public_html"
	uid, gid int
	owners   bool
	skip     string // laravel/releases/<n> folders other than current
	res      SiteScan
	counts   map[string]int
	apps     []string // folders of Laravel applications
}

func (m *Manager) scanSite(ctx context.Context, s ScanSite, clam bool) SiteScan {
	w := &walker{m: m, ctx: ctx, s: s, root: m.h.Path(files.SiteDir(s.User, s.Domain)), docroot: s.WebRoot, counts: map[string]int{}}
	w.res = SiteScan{Domain: s.Domain, Findings: []Finding{}}
	if w.docroot == "" {
		w.docroot = site.DefaultWebRoot
	}
	if fi, err := os.Lstat(w.root); err != nil || !fi.IsDir() {
		w.res.Error = "the site folder is missing"
		return w.res
	}
	if u, err := m.h.LookupUser(s.User); err == nil && !m.sim {
		w.uid, w.gid, w.owners = u.UID, u.GID, true
	}
	if target, err := os.Readlink(filepath.Join(w.root, "laravel", "current")); err == nil {
		w.skip = path.Clean(path.Join("laravel", target))
	}
	if err := filepath.WalkDir(w.root, w.visit); err != nil && !errors.Is(err, errStop) {
		w.res.Error = err.Error()
	}
	for _, check := range []string{CheckWritable, CheckOwner} {
		if n := w.counts[check]; n > perCheckList {
			f := Finding{Check: check, Code: check + "-more", Severity: SeverityMedium, Count: n - perCheckList, Fixes: []string{}}
			if check == CheckWritable {
				f.Fixes = []string{FixPermissions}
			} else {
				f.Severity, f.Fixes = SeverityLow, []string{FixOwner}
			}
			w.res.Findings = append(w.res.Findings, f)
		}
	}
	w.laravelChecks()
	if v, ok := parseVersion(s.PHPVersion, 2); ok && less(v, phpMinimum) {
		w.add(Finding{Check: CheckOutdated, Code: "php", Severity: SeverityMedium, Detail: "PHP " + s.PHPVersion, Fixes: []string{}})
	}
	if clam {
		w.clamScan()
	}
	sortFindings(w.res.Findings)
	return w.res
}

var errStop = errors.New("stop")

func (w *walker) rel(p string) string {
	r, err := filepath.Rel(w.root, p)
	if err != nil || r == "." {
		return ""
	}
	return filepath.ToSlash(r)
}

// webPath is the URL of rel when it is under the web root, else "".
func (w *walker) webPath(rel string) string {
	prefix := w.docroot + "/"
	if w.skip != "" && strings.HasPrefix(w.docroot, "laravel/current/") {
		// The web root goes through the current symlink, which the walk
		// does not follow; the release folder is what it reaches.
		prefix = w.skip + "/" + strings.TrimPrefix(w.docroot, "laravel/current/") + "/"
	}
	if !strings.HasPrefix(rel, prefix) {
		return ""
	}
	return "/" + strings.TrimPrefix(rel, prefix)
}

func (w *walker) add(f Finding) {
	if f.Fixes == nil {
		f.Fixes = []string{}
	}
	w.res.Findings = append(w.res.Findings, f)
}

func (w *walker) visit(p string, d fs.DirEntry, err error) error {
	if w.ctx.Err() != nil {
		return w.ctx.Err()
	}
	if err != nil {
		return nil // unreadable entries are skipped
	}
	rel := w.rel(p)
	w.res.Files++
	if w.res.Files > maxEntries {
		w.res.Truncated = true
		return errStop
	}
	name := d.Name()
	if d.IsDir() && rel != "" {
		switch {
		case rel == "logs":
			return fs.SkipDir
		case strings.HasPrefix(rel, "laravel/releases/") && strings.Count(rel, "/") == 2 && w.skip != "" && rel != w.skip:
			return fs.SkipDir // an older release: not served
		}
	}
	fi, err := d.Info()
	if err != nil {
		return nil
	}
	symlink := fi.Mode()&fs.ModeSymlink != 0
	if !symlink {
		w.checkMode(rel, fi)
	}
	url := w.webPath(rel)
	if d.IsDir() {
		if name == ".git" {
			if url != "" {
				w.exposed(rel, url, url+"/HEAD", "git", true)
			}
			return fs.SkipDir
		}
		return nil
	}
	if symlink || !fi.Mode().IsRegular() {
		return nil
	}
	if url != "" {
		w.checkExposed(rel, url, name, fi.Size())
	}
	w.checkContent(p, rel, name, fi.Size())
	return nil
}

func (w *walker) checkMode(rel string, fi fs.FileInfo) {
	if fi.Mode().Perm()&0o002 != 0 && fi.Mode()&fs.ModeSticky == 0 {
		w.counts[CheckWritable]++
		if w.counts[CheckWritable] <= perCheckList {
			w.add(Finding{Check: CheckWritable, Code: kindOf(fi), Severity: SeverityMedium, Path: rel, Detail: modeText(fi), Fixes: []string{FixPermissions}})
		}
	}
	if !w.owners {
		return
	}
	if st, ok := fi.Sys().(*syscall.Stat_t); ok && (int(st.Uid) != w.uid) {
		w.counts[CheckOwner]++
		if w.counts[CheckOwner] <= perCheckList {
			w.add(Finding{Check: CheckOwner, Code: kindOf(fi), Severity: SeverityLow, Path: rel, Detail: "uid " + strconv.Itoa(int(st.Uid)), Fixes: []string{FixOwner}})
		}
	}
}

func kindOf(fi fs.FileInfo) string {
	if fi.IsDir() {
		return "dir"
	}
	return "file"
}

func modeText(fi fs.FileInfo) string {
	return "0" + strconv.FormatUint(uint64(fi.Mode().Perm()), 8)
}

var backupExt = regexp.MustCompile(`(?i)(?:\.(?:sql|sql\.gz|sql\.zip|sql\.bz2|dump|zip|tar|tar\.gz|tgz|tar\.bz2|rar|7z|bak|backup|old|orig|save|swp)|~)$`)

func (w *walker) checkExposed(rel, url, name string, size int64) {
	switch {
	case strings.HasPrefix(name, ".env") && name != ".env.example" && name != ".env.sample":
		w.exposed(rel, url, url, "env", true)
	case size > 0 && backupExt.MatchString(name):
		w.exposed(rel, url, url, "backup", false)
	}
}

// exposed records a sensitive file under the web root: worse when the
// web server actually sends it. dot says Nginx refuses it by default.
func (w *walker) exposed(rel, url, probe, code string, dot bool) {
	served := !dot
	if w.m.Fetch != nil {
		c := w.m.Fetch(w.ctx, w.s.Domain, probe)
		served = c == 200 || c == 206
		if c == 0 {
			served = !dot
		}
	}
	f := Finding{Check: CheckExposed, Code: code, Path: rel, URL: url, Fixes: []string{FixQuarantine}}
	switch {
	case served && code == "backup":
		f.Severity = SeverityHigh
		f.Fixes = []string{FixQuarantine, FixDeny}
	case served:
		f.Severity = SeverityCritical
		f.Fixes = []string{FixQuarantine, FixDeny}
	default:
		f.Severity, f.Detail = SeverityLow, "not served"
	}
	w.add(f)
}

var phpInfoOnly = regexp.MustCompile(`(?is)^\s*<\?php\s+(?:@?phpinfo\s*\(\s*(?:-?\d+|INFO_\w+)?\s*\)\s*;?\s*)+(?:\?>)?\s*$`)

func (w *walker) checkContent(abs, rel, name string, size int64) {
	ext := strings.ToLower(path.Ext(name))
	php, image := phpExtensions[ext], imageExtensions[ext]
	wp := strings.HasSuffix(rel, "wp-includes/version.php")
	laravel := strings.HasSuffix(rel, "vendor/laravel/framework/src/Illuminate/Foundation/Application.php")
	if !php && !image || size > maxContent || size == 0 {
		return
	}
	content, err := readNoFollow(abs, maxContent)
	if err != nil {
		return
	}
	third := strings.Contains("/"+rel, "/vendor/") || strings.Contains("/"+rel, "/node_modules/")
	switch {
	case image:
		if hasPHP(content) {
			w.add(Finding{Check: CheckWebShell, Code: "php-in-image", Severity: SeverityHigh, Path: rel, URL: w.webPath(rel), Fixes: []string{FixQuarantine}})
		}
		return
	case wp:
		w.wordPress(rel, content)
	case laravel:
		w.laravelVersion(rel, content)
	}
	if sig, ok := match(content, third, size); ok {
		check := CheckWebShell
		if !sig.strong {
			check = CheckSuspicious
		}
		w.add(Finding{Check: check, Code: sig.code, Severity: sig.severity, Path: rel, URL: w.webPath(rel), Fixes: []string{FixQuarantine}})
		return
	}
	url := w.webPath(rel)
	if url != "" && size < 512 && phpInfoOnly.Match(content) {
		w.add(Finding{Check: CheckExposed, Code: "phpinfo", Severity: SeverityMedium, Path: rel, URL: url, Fixes: []string{FixQuarantine, FixDeny}})
		return
	}
	if !third && strings.Contains("/"+rel, "/uploads/") {
		w.add(Finding{Check: CheckSuspicious, Code: "php-in-uploads", Severity: SeverityMedium, Path: rel, URL: url, Fixes: []string{FixQuarantine}})
	}
}

// readNoFollow reads at most n bytes of a file, refusing a symlink put
// in its place after the walk saw it.
func readNoFollow(abs string, n int64) ([]byte, error) {
	f, err := os.OpenFile(abs, os.O_RDONLY|syscall.O_NOFOLLOW|syscall.O_NONBLOCK, 0)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	if fi, err := f.Stat(); err != nil || !fi.Mode().IsRegular() {
		return nil, errors.New("not a regular file")
	}
	return io.ReadAll(io.LimitReader(f, n))
}

var (
	wpVersionRe      = regexp.MustCompile(`\$wp_version\s*=\s*'([0-9][0-9.]*)'`)
	laravelVersionRe = regexp.MustCompile(`const\s+VERSION\s*=\s*'([0-9][0-9.]*)'`)
	appDebugRe       = regexp.MustCompile(`(?m)^\s*APP_DEBUG\s*=\s*["']?(true|1|on|yes)["']?\s*$`)
	appEnvRe         = regexp.MustCompile(`(?m)^\s*APP_ENV\s*=\s*["']?([A-Za-z0-9_-]+)`)
)

func (w *walker) wordPress(rel string, content []byte) {
	m := wpVersionRe.FindSubmatch(content)
	if m == nil {
		return
	}
	v, ok := parseVersion(string(m[1]), 2)
	if ok && less(v, wordPressMinimum) {
		w.add(Finding{Check: CheckOutdated, Code: "wordpress", Severity: SeverityHigh, Path: path.Dir(path.Dir(rel)), Detail: "WordPress " + string(m[1]), Fixes: []string{}})
	}
}

func (w *walker) laravelVersion(rel string, content []byte) {
	app := strings.TrimSuffix(rel, "/vendor/laravel/framework/src/Illuminate/Foundation/Application.php")
	if app == rel {
		app = ""
	}
	w.apps = append(w.apps, app)
	m := laravelVersionRe.FindSubmatch(content)
	if m == nil {
		return
	}
	if v, ok := parseVersion(string(m[1]), 1); ok && v[0] < laravelMinimum {
		w.add(Finding{Check: CheckOutdated, Code: "laravel", Severity: SeverityMedium, Path: app, Detail: "Laravel " + string(m[1]), Fixes: []string{}})
	}
}

// laravelChecks looks at the .env of each application found: debug mode
// shows code, settings and secrets on every error page.
func (w *walker) laravelChecks() {
	seen := map[string]bool{}
	for _, app := range w.apps {
		env := path.Join(app, ".env")
		if w.skip != "" && app == w.skip {
			env = "laravel/shared/.env"
		}
		if seen[env] {
			continue
		}
		seen[env] = true
		content, err := readNoFollow(filepath.Join(w.root, filepath.FromSlash(env)), 256<<10)
		if err != nil || !appDebugRe.Match(content) {
			continue
		}
		mode := "production"
		if m := appEnvRe.FindSubmatch(content); m != nil {
			mode = strings.ToLower(string(m[1]))
		}
		if mode == "local" || mode == "development" || mode == "testing" {
			continue
		}
		w.add(Finding{Check: CheckDebug, Code: "app-debug", Severity: SeverityHigh, Path: env, Detail: "APP_ENV=" + mode, Fixes: []string{}})
	}
}

// clamScan asks the ClamAV daemon about the whole site folder. The
// daemon reads each file through a descriptor the scanner passes, so it
// needs no access to the account's files.
func (w *walker) clamScan() {
	out, err := w.m.h.Run(w.ctx, host.Command{Name: "nice", Args: []string{"-n", "19", "ionice", "-c", "3",
		"clamdscan", "--fdpass", "--multiscan", "--infected", "--no-summary", "--stdout", files.SiteDir(w.s.User, w.s.Domain)}})
	if err != nil && host.ExitCode(err) != 1 {
		w.res.Error = "ClamAV: " + lastLines(out, 2)
		return
	}
	for _, f := range parseClam(out, w.m.h.Path(files.SiteDir(w.s.User, w.s.Domain)), files.SiteDir(w.s.User, w.s.Domain)) {
		w.addMalware(f)
	}
}

// addMalware replaces a pattern finding about the same file.
func (w *walker) addMalware(f Finding) {
	out := w.res.Findings[:0]
	for _, old := range w.res.Findings {
		if old.Path == f.Path && (old.Check == CheckWebShell || old.Check == CheckSuspicious) {
			continue
		}
		out = append(out, old)
	}
	w.res.Findings = append(out, f)
}

var clamLine = regexp.MustCompile(`^(.+): (.+) FOUND$`)

func parseClam(out string, roots ...string) []Finding {
	var res []Finding
	sc := bufio.NewScanner(strings.NewReader(out))
	for sc.Scan() {
		m := clamLine.FindStringSubmatch(strings.TrimSpace(sc.Text()))
		if m == nil {
			continue
		}
		rel := m[1]
		for _, r := range roots {
			if strings.HasPrefix(rel, r+"/") {
				rel = strings.TrimPrefix(rel, r+"/")
				break
			}
		}
		if filepath.IsAbs(rel) {
			continue
		}
		res = append(res, Finding{Check: CheckMalware, Code: "clamav", Severity: SeverityCritical, Path: rel, Detail: m[2], Fixes: []string{FixQuarantine}})
	}
	return res
}

func sortFindings(f []Finding) {
	sort.SliceStable(f, func(i, j int) bool {
		if a, b := rank(f[i].Severity), rank(f[j].Severity); a != b {
			return a > b
		}
		if f[i].Check != f[j].Check {
			return f[i].Check < f[j].Check
		}
		return f[i].Path < f[j].Path
	})
}

func parseVersion(s string, parts int) ([]int, bool) {
	f := strings.Split(s, ".")
	if len(f) < parts {
		return nil, false
	}
	out := make([]int, parts)
	for i := 0; i < parts; i++ {
		n, err := strconv.Atoi(f[i])
		if err != nil {
			return nil, false
		}
		out[i] = n
	}
	return out, true
}

func less(a, b []int) bool {
	for i := range a {
		if i >= len(b) {
			return false
		}
		if a[i] != b[i] {
			return a[i] < b[i]
		}
	}
	return false
}

// --- One file, after an upload -----------------------------------------

// FileSpec names a file of a site.
type FileSpec struct {
	Domain string `json:"domain"`
	User   string `json:"user"`
	// Paths are relative to the site folder.
	Paths []string `json:"paths"`
}

// ScanFiles checks single files (just uploaded with the file manager).
func (m *Manager) ScanFiles(ctx context.Context, spec FileSpec) ([]Finding, error) {
	if err := site.ValidateDomain(spec.Domain); err != nil {
		return nil, err
	}
	if err := site.ValidateUser(spec.User); err != nil {
		return nil, err
	}
	if len(spec.Paths) > 100 {
		return nil, invalid("at most 100 files at a time")
	}
	w := &walker{m: m, ctx: ctx, s: ScanSite{Domain: spec.Domain, User: spec.User}, root: m.h.Path(files.SiteDir(spec.User, spec.Domain)), docroot: site.DefaultWebRoot, counts: map[string]int{}}
	w.res.Findings = []Finding{}
	clam := m.clamAvailable()
	for _, rel := range spec.Paths {
		rel, err := cleanRel(rel)
		if err != nil || rel == "" {
			return nil, invalid("path %q is not valid", rel)
		}
		abs := filepath.Join(w.root, filepath.FromSlash(rel))
		fi, err := os.Lstat(abs)
		if err != nil || !fi.Mode().IsRegular() {
			continue
		}
		w.checkContent(abs, rel, path.Base(rel), fi.Size())
		if clam {
			out, err := m.h.Run(ctx, host.Command{Name: "clamdscan", Args: []string{"--fdpass", "--infected", "--no-summary", "--stdout", files.SiteDir(spec.User, spec.Domain) + "/" + rel}})
			if err == nil || host.ExitCode(err) == 1 {
				for _, f := range parseClam(out, m.h.Path(files.SiteDir(spec.User, spec.Domain)), files.SiteDir(spec.User, spec.Domain)) {
					w.addMalware(f)
				}
			}
		}
	}
	// Only what makes a file dangerous counts here.
	out := []Finding{}
	for _, f := range w.res.Findings {
		if f.Check == CheckMalware || f.Check == CheckWebShell || f.Check == CheckSuspicious {
			out = append(out, f)
		}
	}
	sortFindings(out)
	return out, nil
}

func cleanRel(rel string) (string, error) {
	if strings.ContainsRune(rel, 0) || len(rel) > 4096 {
		return "", errors.New("invalid")
	}
	for _, p := range strings.Split(rel, "/") {
		if p == ".." {
			return "", errors.New("invalid")
		}
	}
	return strings.TrimPrefix(path.Clean("/"+rel), "/"), nil
}
