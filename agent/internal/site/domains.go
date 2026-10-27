package site

import (
	"net/url"
	"regexp"
	"sort"
	"strings"

	"github.com/calltech-app/serfex/agent/internal/txn"
)

// A site answers to more than its domain: aliases serve the same files,
// subdomains are served from their own document root with the site's PHP
// pool, and redirects send a whole host name, or a path on the site,
// somewhere else. The site can also replace the web server's own error
// pages and carry the administrator's own Nginx and Apache directives.

// SubdomainSpec is a host name served from its own document root.
type SubdomainSpec struct {
	Name string `json:"name"`
	// WebRoot is relative to the site directory, like Spec.WebRoot:
	// "subdomains/blog" for a folder of its own, or "public_html/blog"
	// for a folder inside the main site.
	WebRoot string `json:"web_root"`
}

// RedirectSpec sends requests elsewhere. Either Hosts (whole host names,
// served by a server block of their own) or Path (a path on the site's
// own names) is set.
type RedirectSpec struct {
	Hosts []string `json:"hosts"`
	// Path such as "/eski-sayfa" also matches everything below it.
	Path string `json:"path"`
	// Target is an absolute http(s) URL, or a path on the same host.
	Target string `json:"target"`
	// Code is 301 (permanent) or 302 (temporary).
	Code int `json:"code"`
	// KeepPath appends the rest of the requested path and the query
	// string to Target.
	KeepPath bool `json:"keep_path"`
}

// Error pages a site can replace. "50x" covers 500, 502 and 504; 503
// stays with the maintenance and suspension pages.
var errorCodes = map[string]string{"403": "403", "404": "404", "50x": "500 502 504"}

const (
	// ErrorPagesRoot holds every site's own error pages, readable by Nginx.
	ErrorPagesRoot = "/var/lib/servik/errors"
	maxSubdomains  = 100
	maxRedirects   = 200
	maxDirectives  = 64 << 10
)

// ConfigError reports configuration the web server refused when tested;
// nothing was changed.
type ConfigError struct {
	Server string
	Output string
}

func (e *ConfigError) Error() string {
	return e.Server + " rejected the configuration: " + e.Output
}

var (
	redirectPathRe = regexp.MustCompile(`^(/[A-Za-z0-9._~-]+)+$`)
	// Characters Nginx would read as variables, quotes, escapes or the end
	// of a directive are never part of a target.
	unsafeTargetRe = regexp.MustCompile("[\\s\"'\\\\$;{}`<>^|]")
)

func errorPageFile(domain, key string) string {
	return ErrorPagesRoot + "/" + domain + "/servik-error-" + key + ".html"
}

func (s Spec) validateDomains() error {
	if len(s.Subdomains) > maxSubdomains {
		return invalid("at most %d subdomains are allowed", maxSubdomains)
	}
	if len(s.Redirects) > maxRedirects {
		return invalid("at most %d redirects are allowed", maxRedirects)
	}
	seen := map[string]bool{s.Domain: true}
	for _, a := range s.Aliases {
		seen[a] = true
	}
	name := func(n string) error {
		if err := ValidateDomain(n); err != nil {
			return err
		}
		if seen[n] {
			return invalid("%q is listed twice or equals the domain or an alias", n)
		}
		seen[n] = true
		return nil
	}
	for _, sub := range s.Subdomains {
		if err := name(sub.Name); err != nil {
			return err
		}
		if err := ValidateRelPath(sub.WebRoot); err != nil {
			return invalid("subdomain %s: web_root: %s", sub.Name, err.Error())
		}
		if sub.WebRoot == "logs" || strings.HasPrefix(sub.WebRoot, "logs/") {
			return invalid("subdomain %s: the logs folder cannot be a document root", sub.Name)
		}
	}
	paths := map[string]bool{}
	for _, r := range s.Redirects {
		if (len(r.Hosts) == 0) == (r.Path == "") {
			return invalid("a redirect needs either hosts or a path")
		}
		for _, h := range r.Hosts {
			if err := name(h); err != nil {
				return err
			}
		}
		if r.Path != "" {
			if !redirectPathRe.MatchString(r.Path) || len(r.Path) > 200 || strings.HasPrefix(r.Path, "/.well-known") || strings.Contains(r.Path, "/../") || strings.HasSuffix(r.Path, "/..") || strings.HasSuffix(r.Path, "/.") {
				return invalid("redirect path %q must look like /old-page", r.Path)
			}
			if paths[r.Path] {
				return invalid("redirect path %q is listed twice", r.Path)
			}
			paths[r.Path] = true
		}
		if r.Code != 301 && r.Code != 302 {
			return invalid("redirect code must be 301 or 302")
		}
		if err := validTarget(r.Target, r.KeepPath); err != nil {
			return err
		}
		if len(r.Hosts) > 0 && strings.HasPrefix(r.Target, "/") {
			return invalid("a host name redirect needs a full address such as https://example.com")
		}
	}
	for k, html := range s.ErrorPages {
		if _, ok := errorCodes[k]; !ok {
			return invalid("error page %q must be 403, 404 or 50x", k)
		}
		if len(html) > maxPageSize {
			return invalid("error page %s must be smaller than 256 KB", k)
		}
	}
	if err := validDirectives(s.NginxDirectives, "nginx_directives"); err != nil {
		return err
	}
	return validDirectives(s.ApacheDirectives, "apache_directives")
}

func validTarget(t string, keepPath bool) error {
	if len(t) > 2000 || unsafeTargetRe.MatchString(t) {
		return invalid("redirect target %q contains characters that are not allowed", t)
	}
	if strings.HasPrefix(t, "/") && !strings.HasPrefix(t, "//") {
		if keepPath && strings.Contains(t, "?") {
			return invalid("a redirect that keeps the path cannot have a query string in its target")
		}
		return nil
	}
	u, err := url.Parse(t)
	if err != nil || (u.Scheme != "http" && u.Scheme != "https") || u.Host == "" || u.User != nil {
		return invalid("redirect target %q must be an http:// or https:// address or a path such as /new-page", t)
	}
	if keepPath && (u.RawQuery != "" || u.Fragment != "") {
		return invalid("a redirect that keeps the path cannot have a query string in its target")
	}
	return nil
}

// validDirectives refuses what would break out of the block the lines are
// put in; the web server's own test catches the rest.
func validDirectives(text, field string) error {
	if len(text) > maxDirectives {
		return invalid("%s must be smaller than 64 KB", field)
	}
	if strings.ContainsRune(text, 0) {
		return invalid("%s contains a NUL character", field)
	}
	depth := 0
	for _, line := range strings.Split(text, "\n") {
		line = strings.TrimSpace(line)
		if strings.HasPrefix(line, "#") {
			continue
		}
		for _, c := range stripQuoted(line) {
			switch c {
			case '{':
				depth++
			case '}':
				depth--
			}
			if depth < 0 {
				return invalid("%s closes a block it did not open", field)
			}
		}
	}
	if depth != 0 {
		return invalid("%s leaves a block open", field)
	}
	return nil
}

// stripQuoted drops quoted strings and comments from one line.
func stripQuoted(line string) string {
	var b strings.Builder
	var quote rune
	escaped := false
	for _, c := range line {
		switch {
		case escaped:
			escaped = false
		case c == '\\':
			escaped = true
		case quote != 0:
			if c == quote {
				quote = 0
			}
		case c == '"' || c == '\'':
			quote = c
		case c == '#':
			return b.String()
		default:
			b.WriteRune(c)
		}
	}
	return b.String()
}

// covers reports whether a certificate for names serves host: an exact
// name, or a wildcard one label above it.
func covers(names []string, host string) bool {
	for _, n := range names {
		if n == host {
			return true
		}
		if strings.HasPrefix(n, "*.") {
			if i := strings.IndexByte(host, '.'); i > 0 && host[i+1:] == n[2:] {
				return true
			}
		}
	}
	return false
}

// splitNames divides host names into the ones the certificate serves and
// the rest. A certificate without names (only a common name) is taken to
// serve them all, as before.
func splitNames(certNames, hosts []string) (tls, plain []string) {
	if len(certNames) == 0 {
		return hosts, nil
	}
	for _, h := range hosts {
		if covers(certNames, h) {
			tls = append(tls, h)
		} else {
			plain = append(plain, h)
		}
	}
	return tls, plain
}

// hostRedirect is a server block that only redirects.
type hostRedirect struct {
	Names  string
	TLS    bool
	Code   int
	Target string
}

// pathRedirect is a pair of locations inside the site's server block:
// the path itself, and everything below it.
type pathRedirect struct {
	Path    string
	Pattern string
	Code    int
	Exact   string
	Below   string
}

// errorPage is one error_page line and the file it serves.
type errorPage struct {
	Codes string
	URI   string
}

// group is one host-name group of the vhost: the main site, or a
// subdomain. Names the certificate does not cover yet stay on plain HTTP
// even when the site redirects to HTTPS, so a new alias whose DNS does not
// point here works before its certificate does.
func (d *tmplData) splitTLS(certNames, hosts []string) {
	d.ServerNames = strings.Join(hosts, " ")
	if !d.TLS {
		return
	}
	tls, plain := splitNames(certNames, hosts)
	if len(tls) == 0 {
		d.TLS, d.Redirect = false, false
		return
	}
	if d.Redirect {
		d.ServerNames = strings.Join(tls, " ")
		d.PlainNames = strings.Join(plain, " ")
	}
}

// domainData fills in subdomains, redirects, error pages and directives.
// base must already carry the main site's TLS settings.
func domainData(d *tmplData, s Spec, siteDir string, certNames []string) {
	main := append([]string{s.Domain}, s.Aliases...)
	tls, redirect := d.TLS, d.Redirect
	d.splitTLS(certNames, main)

	keys := make([]string, 0, len(s.ErrorPages))
	for k := range s.ErrorPages {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	for _, k := range keys {
		d.ErrorPages = append(d.ErrorPages, errorPage{Codes: errorCodes[k], URI: "/servik-error-" + k + ".html"})
	}
	d.ErrorRoot = ErrorPagesRoot + "/" + s.Domain

	for _, sub := range s.Subdomains {
		c := *d
		c.TLS, c.Redirect, c.PlainNames = tls, redirect, ""
		c.DocumentRoot = siteDir + "/" + sub.WebRoot
		c.ProxyPort, c.WebSockets, c.Maint = 0, nil, false
		c.PathRedirects, c.Directives, c.Subdomains, c.HostRedirects = nil, "", nil, nil
		c.splitTLS(certNames, []string{sub.Name})
		d.Subdomains = append(d.Subdomains, c)
	}

	for _, r := range s.Redirects {
		if r.Path != "" {
			pr := pathRedirect{Path: r.Path, Pattern: "^" + regexp.QuoteMeta(r.Path) + "(/.*)$", Code: r.Code, Exact: r.Target, Below: r.Target}
			if r.KeepPath {
				pr.Exact += "$is_args$args"
				pr.Below = strings.TrimSuffix(r.Target, "/") + "$1$is_args$args"
			}
			d.PathRedirects = append(d.PathRedirects, pr)
			continue
		}
		target := r.Target
		if r.KeepPath {
			target = strings.TrimSuffix(target, "/") + "$request_uri"
		}
		hr := hostRedirect{Names: strings.Join(r.Hosts, " "), Code: r.Code, Target: target}
		if tls {
			covered, _ := splitNames(certNames, r.Hosts)
			hr.TLS = len(covered) > 0
		}
		d.HostRedirects = append(d.HostRedirects, hr)
	}
	d.Directives = indent(s.NginxDirectives)
	d.ApacheDirectives = indent(s.ApacheDirectives)
}

// indent puts the administrator's lines one level into the block.
func indent(text string) string {
	text = strings.TrimSpace(strings.ReplaceAll(text, "\r\n", "\n"))
	if text == "" {
		return ""
	}
	lines := strings.Split(text, "\n")
	for i, l := range lines {
		if l = strings.TrimRight(l, " \t"); l != "" {
			lines[i] = "    " + l
		} else {
			lines[i] = ""
		}
	}
	return strings.Join(lines, "\n")
}

// errorPageContent puts Servik's marker on top of an error page.
func errorPageContent(html string) []byte {
	return []byte("<!-- " + txn.Marker + ". Manual changes are overwritten. -->\n" + html)
}
