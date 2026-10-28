package site

import (
	"errors"
	"io/fs"
	"os"
	"path/filepath"
	"regexp"
	"strings"
)

// Önbellek ve hız: Nginx keeps the pages PHP (or Apache) made and sends
// them again without running the application (page cache), tells
// browsers to keep images, styles, scripts and fonts (browser cache), and
// compresses text responses (gzip).
//
// The page cache is safe by default: only GET and HEAD requests are
// cached, never a response that sets a cookie or that the application
// marks private or no-cache (Laravel and PHP sessions do), and requests
// carrying a cookie other than an analytics one, an Authorization header,
// or one of the paths below skip it. Each site has its own cache zone in
// /var/cache/serfex/nginx/<domain>, owned by the web server.

// CacheSpec is a site's caching and compression.
type CacheSpec struct {
	// Page keeps generated pages for PageMinutes.
	Page        bool `json:"page"`
	PageMinutes int  `json:"page_minutes"`
	// Exclude lists more paths (prefixes such as /shop/cart) that are
	// never cached.
	Exclude []string `json:"exclude"`
	// Browser lets browsers keep static files for BrowserDays.
	Browser     bool `json:"browser"`
	BrowserDays int  `json:"browser_days"`
	// Gzip compresses text, scripts, styles, SVG and fonts.
	Gzip bool `json:"gzip"`
}

// CacheRoot holds every site's page cache.
const CacheRoot = "/var/cache/serfex/nginx"

// CacheDir is a site's page cache.
func CacheDir(domain string) string { return CacheRoot + "/" + domain }

// cacheBypass are the paths of sign-in, administration, carts and
// programming interfaces, never cached whatever the site asks.
var cacheBypass = []string{
	"/wp-admin/", "/wp-login.php", "/wp-json/", "/xmlrpc.php", "/wp-cron.php", "/wp-comments-post.php",
	"/cart", "/checkout", "/my-account", "/sepet", "/odeme", "/hesabim",
	"/admin", "/login", "/logout", "/register", "/api/", "/livewire/", "/sanctum/", "/broadcasting/",
}

// cacheCookieOK are the cookies that do not change a page: analytics,
// consent banners and the language switcher. A request with any other
// cookie skips the cache.
const cacheCookieOK = `_ga|_gid|_gat|_gcl|_fbp|_fbc|_hj|_clck|_clsk|_pk_|cookieconsent|cookielawinfo|cmplz|CookieConsent|pll_language|wp-wpml_current_language|wp_lang`

var excludeRe = regexp.MustCompile(`^/[A-Za-z0-9._~!$&'()*+,;=:@%/-]{0,199}$`)

const (
	maxExclude   = 30
	cacheMaxSize = "256m"
)

func (c *CacheSpec) validate() error {
	if c == nil {
		return nil
	}
	if c.Page && (c.PageMinutes < 1 || c.PageMinutes > 10080) {
		return invalid("cache.page_minutes must be between 1 and 10080")
	}
	if c.Browser && (c.BrowserDays < 1 || c.BrowserDays > 365) {
		return invalid("cache.browser_days must be between 1 and 365")
	}
	if len(c.Exclude) > maxExclude {
		return invalid("at most %d excluded paths are allowed", maxExclude)
	}
	for _, p := range c.Exclude {
		if !excludeRe.MatchString(p) || strings.Contains(p, "..") {
			return invalid("excluded path %q must start with / and contain no spaces or quotes", p)
		}
	}
	return nil
}

// cacheData is what the vhost needs for caching.
type cacheData struct {
	// Var is the site's unique Nginx name suffix.
	Var     string
	Page    bool
	Dir     string
	Minutes int
	// Bypass are regular expressions of the paths that skip the cache.
	Bypass   []string
	CookieOK string
	MaxSize  string
	Browser  bool
	Days     int
	Gzip     bool
}

// fillCache adds the caching part of the vhost. A suspended site, and one
// whose requests go to an application server (Octane), keep no pages.
func fillCache(d *tmplData, s Spec) {
	c := s.Cache
	if c == nil || (!c.Page && !c.Browser && !c.Gzip) {
		return
	}
	cd := &cacheData{Var: maintenanceVar(s.Domain), Browser: c.Browser, Days: c.BrowserDays, Gzip: c.Gzip}
	if c.Page && !s.Suspended && s.Proxy == nil {
		cd.Page, cd.Dir, cd.Minutes, cd.CookieOK, cd.MaxSize = true, CacheDir(s.Domain), c.PageMinutes, cacheCookieOK, cacheMaxSize
		for _, p := range append(append([]string{}, cacheBypass...), c.Exclude...) {
			cd.Bypass = append(cd.Bypass, "^"+regexp.QuoteMeta(p))
		}
	}
	d.Cache = cd
}

// CacheUsage is how much a site's page cache holds.
type CacheUsage struct {
	Files int   `json:"files"`
	Bytes int64 `json:"bytes"`
}

// CacheStatus measures a site's page cache. The files are the web
// server's, outside the customer's home, so root reads them.
func (m *Manager) CacheStatus(domain string) (CacheUsage, error) {
	if err := ValidateDomain(domain); err != nil {
		return CacheUsage{}, err
	}
	var u CacheUsage
	err := filepath.WalkDir(m.h.Path(CacheDir(domain)), func(_ string, e fs.DirEntry, err error) error {
		if err != nil {
			if errors.Is(err, fs.ErrNotExist) {
				return nil
			}
			return err
		}
		if e.Type().IsRegular() {
			if fi, err := e.Info(); err == nil {
				u.Files++
				u.Bytes += fi.Size()
			}
		}
		return nil
	})
	return u, err
}

// PurgeCache empties a site's page cache. Nginx notices the missing files
// and asks the application again.
func (m *Manager) PurgeCache(domain string) (CacheUsage, error) {
	before, err := m.CacheStatus(domain)
	if err != nil {
		return CacheUsage{}, err
	}
	dir := m.h.Path(CacheDir(domain))
	entries, err := os.ReadDir(dir)
	if errors.Is(err, os.ErrNotExist) {
		return before, nil
	}
	if err != nil {
		return CacheUsage{}, &OperationError{err}
	}
	for _, e := range entries {
		if err := os.RemoveAll(filepath.Join(dir, e.Name())); err != nil {
			return CacheUsage{}, &OperationError{err}
		}
	}
	return before, nil
}
