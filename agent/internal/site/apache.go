package site

import (
	"os"
	"text/template"

	"github.com/winchester37/servik/agent/internal/txn"
)

// A site can run behind Apache: Nginx still answers every request (TLS,
// Let's Encrypt, maintenance and suspension pages, live monitoring's
// access log) and serves existing static files itself, and hands
// everything else to Apache, which honours .htaccess and passes PHP to the
// site's own PHP-FPM pool. Apache only listens on the loopback address.

// Web servers a site can use.
const (
	WebNginx  = "nginx"
	WebApache = "apache"
)

const (
	// ApachePort is where Apache listens, on 127.0.0.1 only.
	ApachePort = 7080
	// ApachePorts is Apache's listen file, which Servik owns once Apache
	// is installed through it.
	ApachePorts = "/etc/apache2/ports.conf"
)

func apacheAvail(domain string) string   { return "/etc/apache2/sites-available/" + domain + ".conf" }
func apacheEnabled(domain string) string { return "/etc/apache2/sites-enabled/" + domain + ".conf" }

// ApacheConfig is the Apache vhost of a site, for checks elsewhere.
func ApacheConfig(domain string) string { return apacheAvail(domain) }

// ApacheReady reports whether Apache is installed and set up by Servik
// (its ports.conf is Servik's).
func (m *Manager) ApacheReady() bool {
	if _, err := os.Stat(m.h.Path("/usr/sbin/apache2")); err != nil {
		return false
	}
	data, err := os.ReadFile(m.h.Path(ApachePorts))
	return err == nil && txn.HasMarker(data)
}

var apacheTmpl = template.Must(template.New("apache").Parse(`# {{.Marker}}. Manual changes are overwritten.
# Site: {{.Domain}} (user {{.User}}, PHP {{.PHPVersion}}), behind Nginx.
{{- define "vhostBody"}}
    DocumentRoot {{.DocumentRoot}}
    ErrorLog {{.LogDir}}/apache-error.log

    # Visitors' addresses and HTTPS come from Nginx.
    RemoteIPHeader X-Real-IP
    RemoteIPInternalProxy 127.0.0.1
    SetEnvIf X-Forwarded-Proto "^https$" HTTPS=on

    <Directory {{.SiteDir}}>
        Options -Indexes +SymLinksIfOwnerMatch
        AllowOverride All
        Require all granted
        DirectoryIndex index.php index.html index.htm
    </Directory>

    # PHP runs in the site's own PHP-FPM pool, as the site's user.
    <FilesMatch "\.php$">
        <If "-f %{REQUEST_FILENAME}">
            SetHandler "proxy:unix:{{.PHPSocket}}|fcgi://servik-{{.Domain}}/"
        </If>
    </FilesMatch>
{{- end}}
<VirtualHost 127.0.0.1:{{.ApachePort}}>
    ServerName {{.Domain}}
{{- range .Aliases}}
    ServerAlias {{.}}
{{- end}}
{{- template "vhostBody" .}}
{{- if .ApacheDirectives}}

    # The administrator's own directives (Web sunucusu yönergeleri).
{{.ApacheDirectives}}
{{- end}}
</VirtualHost>
{{- range .Subdomains}}

# Subdomain {{.ServerNames}}.
<VirtualHost 127.0.0.1:{{.ApachePort}}>
    ServerName {{.ServerNames}}
{{- template "vhostBody" .}}
</VirtualHost>
{{- end}}
`))
