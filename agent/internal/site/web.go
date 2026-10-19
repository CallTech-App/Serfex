package site

import (
	"net/netip"
	"regexp"
	"strings"
	"text/template"

	"github.com/winchester37/servik/agent/internal/txn"
)

// What else a vhost can do besides serving files and PHP: hand requests to
// a long-running application server (Laravel Octane), forward WebSocket
// paths (Laravel Reverb), and show a maintenance page to everyone but the
// team.

// ProxySpec sends every request that is not a static file to a local HTTP
// server instead of PHP-FPM.
type ProxySpec struct {
	Port int `json:"port"`
}

// WebSocketSpec forwards requests below Path to a local WebSocket server.
type WebSocketSpec struct {
	// Path starts and ends with a slash, such as "/app/".
	Path string `json:"path"`
	Port int    `json:"port"`
}

// MaintenanceSpec answers every request with 503 and a maintenance page,
// except from the listed addresses, from browsers that opened the secret
// link, and for Let's Encrypt validation.
type MaintenanceSpec struct {
	// Allow lists IP addresses and networks (CIDR) that see the site.
	Allow []string `json:"allow"`
	// Secret, when set, makes https://<domain>/<secret> set a cookie that
	// lets that browser in.
	Secret string `json:"secret"`
	// Retry is the Retry-After header in seconds; 0 leaves it out.
	Retry int `json:"retry"`
	// HTML is the page shown; empty shows Servik's own.
	HTML string `json:"html"`
}

const (
	// MaintenanceDir holds the maintenance pages, readable by Nginx.
	MaintenanceDir = "/var/lib/servik/maintenance"
	// SuspendedDir holds the pages of suspended sites.
	SuspendedDir = "/var/lib/servik/suspended"
	// websocketConf defines the Connection header map proxied vhosts use.
	websocketConf = "/etc/nginx/conf.d/servik-websocket.conf"
	maxAllow      = 100
	maxPageSize   = 256 << 10
)

var (
	wsPathRe = regexp.MustCompile(`^/([A-Za-z0-9._-]+/)*$`)
	secretRe = regexp.MustCompile(`^[A-Za-z0-9_-]{8,64}$`)
)

func validPort(p int) bool { return p >= 1024 && p <= 65535 }

func (s Spec) validateWeb() error {
	if s.Proxy != nil && !validPort(s.Proxy.Port) {
		return invalid("proxy.port must be between 1024 and 65535")
	}
	if len(s.WebSockets) > 10 {
		return invalid("at most 10 websocket paths are allowed")
	}
	seen := map[string]bool{}
	for _, w := range s.WebSockets {
		if !wsPathRe.MatchString(w.Path) || len(w.Path) > 64 || w.Path == "/" || strings.HasPrefix(w.Path, "/.well-known/") {
			return invalid("websocket path %q must look like /app/", w.Path)
		}
		if seen[w.Path] {
			return invalid("websocket path %q is listed twice", w.Path)
		}
		seen[w.Path] = true
		if !validPort(w.Port) {
			return invalid("websocket port must be between 1024 and 65535")
		}
	}
	if mt := s.Maintenance; mt != nil {
		if len(mt.Allow) > maxAllow {
			return invalid("at most %d allowed addresses are allowed", maxAllow)
		}
		for _, a := range mt.Allow {
			if _, err := parseAllow(a); err != nil {
				return invalid("%q is not an IP address or network", a)
			}
		}
		if mt.Secret != "" && !secretRe.MatchString(mt.Secret) {
			return invalid("maintenance.secret must be 8-64 letters, digits, - or _")
		}
		if mt.Retry < 0 || mt.Retry > 86400 {
			return invalid("maintenance.retry must be between 0 and 86400 seconds")
		}
		if len(mt.HTML) > maxPageSize {
			return invalid("the maintenance page must be smaller than 256 KB")
		}
	}
	return nil
}

// parseAllow reads an address or a network in CIDR form.
func parseAllow(s string) (netip.Prefix, error) {
	if strings.Contains(s, "/") {
		p, err := netip.ParsePrefix(s)
		return p.Masked(), err
	}
	a, err := netip.ParseAddr(s)
	if err != nil {
		return netip.Prefix{}, err
	}
	return netip.PrefixFrom(a, a.BitLen()), nil
}

// maintenanceVar turns a domain into a unique Nginx variable suffix:
// "." and "-" map to distinct sequences, and domains have no "_".
func maintenanceVar(domain string) string {
	return strings.NewReplacer(".", "_d", "-", "_h").Replace(domain)
}

// maintenancePage is the file Nginx serves during maintenance.
func maintenancePage(domain string) string { return MaintenanceDir + "/" + domain + ".html" }

// defaultPage is plain text/template: html/template would drop the marker
// comment. The domain is a validated host name.
var defaultPage = template.Must(template.New("page").Parse(`<!-- {{.Marker}}. Manual changes are overwritten. -->
<!doctype html>
<html lang="tr">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Bakımdayız · {{.Domain}}</title>
<style>
body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f6f8f8;color:#11191c}
main{max-width:30rem;padding:2rem 1.5rem;text-align:center}
h1{font-size:1.5rem;margin:0 0 .75rem}
p{margin:.25rem 0;line-height:1.55;color:#4b5b60}
.en{margin-top:1.25rem;font-size:.9rem}
@media (prefers-color-scheme:dark){body{background:#0f1719;color:#e3ecec}p{color:#9fb1b4}}
</style>
<main>
<h1>Kısa bir bakım çalışması yapıyoruz</h1>
<p><strong>{{.Domain}}</strong> birazdan yeniden açılacak. Lütfen birkaç dakika sonra tekrar deneyin.</p>
<p class="en">We are doing some quick maintenance and will be back shortly.</p>
</main>
</html>
`))

// pageContent returns the maintenance page with Servik's marker on top,
// so the file stays recognisable as Servik's.
func pageContent(domain, html string) []byte {
	if strings.TrimSpace(html) == "" {
		var b strings.Builder
		_ = defaultPage.Execute(&b, map[string]string{"Marker": txn.Marker, "Domain": domain})
		return []byte(b.String())
	}
	return []byte("<!-- " + txn.Marker + ". Manual changes are overwritten. -->\n" + html)
}

// suspendedPage is the file Nginx serves while a site is suspended.
func suspendedPage(domain string) string { return SuspendedDir + "/" + domain + ".html" }

var suspendedTmpl = template.Must(template.New("suspended").Parse(`<!-- {{.Marker}}. Manual changes are overwritten. -->
<!doctype html>
<html lang="tr">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Hizmet dışı · {{.Domain}}</title>
<style>
body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f6f8f8;color:#11191c}
main{max-width:30rem;padding:2rem 1.5rem;text-align:center}
h1{font-size:1.5rem;margin:0 0 .75rem}
p{margin:.25rem 0;line-height:1.55;color:#4b5b60}
.en{margin-top:1.25rem;font-size:.9rem}
@media (prefers-color-scheme:dark){body{background:#0f1719;color:#e3ecec}p{color:#9fb1b4}}
</style>
<main>
<h1>Bu site şu anda hizmet dışı</h1>
<p><strong>{{.Domain}}</strong> barındırma hesabı askıya alındı.</p>
<p>Sitenin sahibiyseniz barındırma sağlayıcınızla iletişime geçin.</p>
<p class="en">This site is currently unavailable because its hosting account is suspended.</p>
</main>
</html>
`))

func suspendedContent(domain string) []byte {
	var b strings.Builder
	_ = suspendedTmpl.Execute(&b, map[string]string{"Marker": txn.Marker, "Domain": domain})
	return []byte(b.String())
}

var wsConf = []byte(`# ` + txn.Marker + `. Manual changes are overwritten.
# Connection header for vhosts that proxy WebSockets (Octane, Reverb).
map $http_upgrade $servik_connection_upgrade {
    default upgrade;
    ''      close;
}
`)

// webData fills in the proxy, WebSocket and maintenance parts of the vhost.
func webData(d *tmplData, s Spec) {
	if s.Suspended {
		// A suspended site runs nothing: no proxy, no WebSockets, no
		// maintenance bypass.
		d.Suspended, d.SuspendedRoot = true, SuspendedDir
		return
	}
	if s.Proxy != nil {
		d.ProxyPort = s.Proxy.Port
	}
	d.WebSockets = s.WebSockets
	if mt := s.Maintenance; mt != nil {
		d.Maint = true
		d.MaintVar = maintenanceVar(s.Domain)
		d.MaintSecret = mt.Secret
		d.MaintRetry = mt.Retry
		d.MaintRoot = MaintenanceDir
		for _, a := range mt.Allow {
			p, _ := parseAllow(a)
			d.MaintAllow = append(d.MaintAllow, p.String())
		}
	}
}
