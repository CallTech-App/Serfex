package site

import (
	"bytes"
	"text/template"

	"github.com/calltech-app/serfex/agent/internal/txn"
)

var nginxTmpl = template.Must(template.New("nginx").Parse(`# {{.Marker}}. Manual changes are overwritten.
# Site: {{.Domain}} (user {{.User}}, PHP {{.PHPVersion}}{{if .Apache}}, Apache{{end}})
{{- define "apacheHeaders"}}
        proxy_http_version 1.1;
        proxy_set_header Connection "";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host $host;
        proxy_read_timeout 300s;
{{- end}}
{{- define "proxyCache"}}
{{- with .Cache}}{{if .Page}}
        proxy_cache serfex_{{.Var}};
        proxy_cache_key $scheme$request_method$host$request_uri;
        proxy_cache_valid 200 301 302 {{.Minutes}}m;
        proxy_cache_valid 404 1m;
        proxy_cache_use_stale error timeout updating http_500 http_503;
        proxy_cache_background_update on;
        proxy_cache_lock on;
        proxy_cache_bypass $serfex_cache_skip_{{.Var}};
        proxy_no_cache $serfex_cache_skip_{{.Var}};
{{- end}}{{end}}
{{- end}}
{{- define "acme"}}
    # Let's Encrypt validation, answered by Serfex whatever the site does.
    location ^~ /.well-known/acme-challenge/ {
        root {{.ACMERoot}};
        default_type "text/plain";
        try_files $uri =404;
    }
{{- end}}
{{- if .Maint}}
# Maintenance: everyone gets the maintenance page except these addresses,
# browsers holding the bypass cookie, and Let's Encrypt validation.
geo $serfex_maint_ip_{{.MaintVar}} {
    default 1;
{{- range .MaintAllow}}
    {{.}} 0;
{{- end}}
}
map $cookie_serfex_maintenance $serfex_maint_c_{{.MaintVar}} {
{{- if .MaintSecret}}
    "{{.MaintSecret}}" 0;
{{- end}}
    default $serfex_maint_ip_{{.MaintVar}};
}
map $uri $serfex_maint_{{.MaintVar}} {
{{- if .MaintSecret}}
    "/{{.MaintSecret}}" 0;
{{- end}}
    "~^/\.well-known/acme-challenge/" 0;
    default $serfex_maint_c_{{.MaintVar}};
}
{{- end}}
{{- with .Cache}}
{{- if .Page}}
# Page cache (Önbellek ve hız): pages are kept for {{.Minutes}} minutes.
{{if $.Apache}}proxy_cache_path{{else}}fastcgi_cache_path{{end}} {{.Dir}} levels=1:2 keys_zone=serfex_{{.Var}}:10m max_size={{.MaxSize}} inactive=1d use_temp_path=off;
# Requests with a cookie other than an analytics one, with an
# Authorization header, or for sign-in, administration and cart pages are
# sent to the application.
map $http_cookie $serfex_cache_cookie_{{.Var}} {
    "" 0;
    "~^(\s*({{.CookieOK}})[^=]*=[^;]*;?)*$" 0;
    default 1;
}
map $http_authorization $serfex_cache_auth_{{.Var}} {
    "" $serfex_cache_cookie_{{.Var}};
    default 1;
}
map $request_uri $serfex_cache_skip_{{.Var}} {
{{- range .Bypass}}
    "~{{.}}" 1;
{{- end}}
    default $serfex_cache_auth_{{.Var}};
}
{{- end}}
{{- if .Browser}}
# Browser cache (Önbellek ve hız): static files for {{.Days}} days.
map $sent_http_content_type $serfex_expires_{{.Var}} {
    default off;
    "~^image/" {{.Days}}d;
    "~^font/" {{.Days}}d;
    "~^(video|audio)/" {{.Days}}d;
    "~^text/css" {{.Days}}d;
    "~javascript" {{.Days}}d;
    "~^application/(x-font|font|vnd\.ms-fontobject)" {{.Days}}d;
}
{{- end}}
{{- end}}
{{- define "listen"}}
{{- if not .Redirect}}
    listen 80;
{{- if .IPv6}}
    listen [::]:80;
{{- end}}
{{- end}}
{{- if .TLS}}
    # "listen ... http2" works on the Nginx 1.24 of Ubuntu 24.04; newer
    # versions accept it with a deprecation notice.
    listen 443 ssl http2;
{{- if .IPv6}}
    listen [::]:443 ssl http2;
{{- end}}
    ssl_certificate {{.CertFile}};
    ssl_certificate_key {{.KeyFile}};
    include {{.TLSConf}};
{{- if .Stapling}}
    ssl_stapling on;
    ssl_stapling_verify on;
    resolver {{.Resolvers}} valid=300s;
{{- end}}
{{- end}}
{{- end}}
{{- define "body"}}

    root {{.DocumentRoot}};
    index index.php index.html index.htm;

    access_log {{.LogDir}}/access.log;
    error_log {{.LogDir}}/error.log;

{{- if .Pool.BodyMB}}
    client_max_body_size {{.Pool.BodyMB}}m; # PHP post_max_size
{{- end}}

    # Serfex's additions for this site (Güvenlik): WAF mode, password
    # protected folders, blocked paths. Missing files are fine.
    include {{.ExtraConf}};
{{- with .Cache}}
{{- if .Gzip}}

    # Compression (Önbellek ve hız).
    gzip on;
    gzip_vary on;
    gzip_proxied any;
    gzip_comp_level 5;
    gzip_min_length 256;
    gzip_types text/plain text/css text/xml text/javascript application/javascript application/json application/xml application/rss+xml application/atom+xml application/manifest+json image/svg+xml font/ttf font/otf application/vnd.ms-fontobject;
{{- end}}
{{- if .Browser}}
    expires $serfex_expires_{{.Var}};
{{- end}}
{{- if .Page}}
    add_header X-Serfex-Cache $upstream_cache_status;
{{- end}}
{{- end}}
{{- if .NoIndex}}

    # A staging copy (Siteyi kopyala): search engines are asked to stay out.
    add_header X-Robots-Tag "noindex, nofollow" always;
{{- end}}
{{- if .Directives}}

    # The administrator's own directives (Web sunucusu yönergeleri).
{{.Directives}}
{{- end}}
{{template "acme" .}}
{{- if .Suspended}}

    # The hosting account is suspended: every request gets the suspension
    # page. The files stay where they are.
    error_page 503 @serfex_suspended;
    location @serfex_suspended {
        root {{.SuspendedRoot}};
        try_files /{{.Domain}}.html =503;
        add_header Cache-Control "no-store" always;
    }

    location / {
        return 503;
    }
{{- else}}
{{- if .ErrorPages}}

    # The site's own error pages (Hata sayfaları).
{{- range .ErrorPages}}
    error_page {{.Codes}} {{.URI}};
    location = {{.URI}} {
        root {{$.ErrorRoot}};
        internal;
    }
{{- end}}
{{- end}}
{{- range .PathRedirects}}

    # Redirect (Yönlendirmeler): {{.Path}} and everything below it.
    location = {{.Path}} {
        return {{.Code}} "{{.Exact}}";
    }
    location ~ "{{.Pattern}}" {
        return {{.Code}} "{{.Below}}";
    }
{{- end}}
{{- if .Maint}}

    error_page 503 @serfex_maintenance;
    location @serfex_maintenance {
        root {{.MaintRoot}};
        try_files /{{.Domain}}.html =503;
        add_header Cache-Control "no-store" always;
{{- if .MaintRetry}}
        add_header Retry-After {{.MaintRetry}} always;
{{- end}}
    }
{{- if .MaintSecret}}

    # The bypass link: sets the cookie, then back to the home page.
    location = /{{.MaintSecret}} {
        add_header Set-Cookie "serfex_maintenance={{.MaintSecret}}; Path=/; Max-Age=43200; HttpOnly; SameSite=Lax{{if .TLS}}; Secure{{end}}";
        return 302 /;
    }
{{- end}}

    if ($serfex_maint_{{.MaintVar}}) {
        return 503;
    }
{{- end}}
{{- range .WebSockets}}

    # WebSocket server (Laravel Reverb).
    location ^~ {{.Path}} {
        proxy_pass http://127.0.0.1:{{.Port}};
        proxy_http_version 1.1;
        proxy_set_header Host $http_host;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $serfex_connection_upgrade;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }
{{- end}}
{{- if .ProxyPort}}

    # Application server (Laravel Octane); static files are served directly.
    location / {
        try_files $uri @app;
    }

    location ~ \.php$ {
        try_files /serfex-no-such-file @app;
    }

    location @app {
        proxy_pass http://127.0.0.1:{{.ProxyPort}};
        proxy_http_version 1.1;
        proxy_set_header Host $http_host;
        proxy_set_header Scheme $scheme;
        proxy_set_header SERVER_PORT $server_port;
        proxy_set_header REMOTE_ADDR $remote_addr;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $serfex_connection_upgrade;
        proxy_read_timeout 120s;
    }
{{- else if .Apache}}

    # Apache serves the site (.htaccess works); Nginx sends existing static
    # files itself and everything else to Apache.
    location / {
        proxy_pass http://127.0.0.1:{{.ApachePort}};
{{- template "apacheHeaders"}}
{{- template "proxyCache" .}}
    }

    location ~* \.(?:css|js|mjs|map|jpe?g|png|gif|webp|avif|svg|ico|woff2?|ttf|otf|eot|mp4|webm|ogg|mp3|pdf|txt|xml|zip|gz)$ {
        try_files $uri @apache;
    }

    location @apache {
        proxy_pass http://127.0.0.1:{{.ApachePort}};
{{- template "apacheHeaders"}}
{{- template "proxyCache" .}}
    }
{{- else}}

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        fastcgi_pass unix:{{.PHPSocket}};
{{- with .Cache}}{{if .Page}}
        fastcgi_cache serfex_{{.Var}};
        fastcgi_cache_key $scheme$request_method$host$request_uri;
        fastcgi_cache_valid 200 301 302 {{.Minutes}}m;
        fastcgi_cache_valid 404 1m;
        fastcgi_cache_use_stale error timeout updating http_500 http_503;
        fastcgi_cache_background_update on;
        fastcgi_cache_lock on;
        fastcgi_cache_bypass $serfex_cache_skip_{{.Var}};
        fastcgi_no_cache $serfex_cache_skip_{{.Var}};
{{- end}}{{end}}
    }
{{- end}}

    location ~ /\.(?!well-known) {
        deny all;
    }
{{- end}}
{{- end}}
{{- define "group"}}
{{- if .Redirect}}
server {
    listen 80;
{{- if .IPv6}}
    listen [::]:80;
{{- end}}
    server_name {{.ServerNames}};

    access_log {{.LogDir}}/access.log;
    error_log {{.LogDir}}/error.log;
{{template "acme" .}}

    location / {
        return 301 https://$host$request_uri;
    }
}
{{- end}}
server {
{{- template "listen" .}}
    server_name {{.ServerNames}};
{{- template "body" .}}
}
{{- if .PlainNames}}

# Not in the certificate yet (its DNS may not point here): plain HTTP
# until the certificate covers it.
server {
    listen 80;
{{- if .IPv6}}
    listen [::]:80;
{{- end}}
    server_name {{.PlainNames}};
{{- template "body" .}}
}
{{- end}}
{{- end}}
{{- template "group" .}}
{{- range .Subdomains}}

# Subdomain {{.ServerNames}}: its own document root, the site's PHP.
{{- template "group" .}}
{{- end}}
{{- range .HostRedirects}}

{{if .Preferred}}# Preferred address (Tercih edilen adres): {{.Names}} goes to {{.Preferred}}
{{- else}}# Redirect (Yönlendirmeler): {{.Names}}
{{- end}}
server {
    listen 80;
{{- if $.IPv6}}
    listen [::]:80;
{{- end}}
{{- if .TLS}}
    listen 443 ssl http2;
{{- if $.IPv6}}
    listen [::]:443 ssl http2;
{{- end}}
    ssl_certificate {{$.CertFile}};
    ssl_certificate_key {{$.KeyFile}};
    include {{$.TLSConf}};
{{- end}}
    server_name {{.Names}};

    access_log {{$.LogDir}}/access.log;
    error_log {{$.LogDir}}/error.log;
{{template "acme" $}}

    location / {
        return {{.Code}} "{{.Target}}";
    }
}
{{- end}}
`))

// tlsConf is included by every HTTPS vhost: TLS 1.2 and 1.3 with Mozilla's
// "intermediate" ciphers, which every browser of the last decade supports.
var tlsConf = []byte(`# ` + txn.Marker + `. Manual changes are overwritten.
# TLS settings shared by every HTTPS site (Mozilla "intermediate").
ssl_protocols TLSv1.2 TLSv1.3;
ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305;
ssl_prefer_server_ciphers off;
ssl_session_cache shared:SerfexTLS:10m;
ssl_session_timeout 1d;
ssl_session_tickets off;
`)

var fpmTmpl = template.Must(template.New("fpm").Parse(`; {{.Marker}}. Manual changes are overwritten.
[{{.Domain}}]
user = {{.User}}
group = {{.User}}

listen = {{.PHPSocket}}
listen.owner = {{.WebUser}}
listen.group = {{.WebUser}}
listen.mode = 0660

pm = {{.Pool.PM}}
pm.max_children = {{.MaxChildren}}
{{- if eq .Pool.PM "dynamic"}}
pm.start_servers = {{.Pool.StartServers}}
pm.min_spare_servers = {{.Pool.MinSpare}}
pm.max_spare_servers = {{.Pool.MaxSpare}}
{{- end}}
pm.process_idle_timeout = 30s
pm.max_requests = {{.Pool.MaxRequests}}
; Workers without a temporary limit (Canlı İzleme).
; serfex-max-children = {{.Pool.Base}}
{{- if .Pool.RequestTimeout}}
request_terminate_timeout = {{.Pool.RequestTimeout}}s
{{- end}}
; Live monitoring reads the pool's status here; Nginx never passes this
; path, and the status socket only answers root and the web server.
pm.status_path = /serfex-fpm-status
pm.status_listen = {{.StatusSocket}}

chdir = /
php_admin_value[open_basedir] = {{.Home}}/
php_admin_value[upload_tmp_dir] = {{.Home}}/tmp
php_admin_value[sys_temp_dir] = {{.Home}}/tmp
php_admin_value[session.save_path] = {{.Home}}/tmp
php_admin_value[error_log] = {{.SiteDir}}/logs/php-error.log
php_admin_flag[log_errors] = on
{{- if .Pool.Disable}}
php_admin_value[disable_functions] = {{.Pool.Disable}}
{{- end}}
{{- if or .Pool.Values .Pool.AdminValues}}

; The site's own PHP settings (PHP ve Web Sunucusu).
{{- range .Pool.AdminValues}}
php_admin_value[{{.Key}}] = {{.Value}}
{{- end}}
{{- range .Pool.Values}}
php_value[{{.Key}}] = {{.Value}}
{{- end}}
{{- end}}
`))

type tmplData struct {
	Marker       string
	Domain       string
	User         string
	PHPVersion   string
	WebUser      string
	ServerNames  string
	Home         string
	SiteDir      string
	DocumentRoot string
	LogDir       string
	PHPSocket    string
	StatusSocket string
	MaxChildren  int
	IPv6         bool
	ACMERoot     string

	// HTTPS, when the site has a certificate.
	TLS       bool
	Redirect  bool
	CertFile  string
	KeyFile   string
	TLSConf   string
	Stapling  bool
	Resolvers string

	// Octane, Reverb and maintenance (see web.go).
	ProxyPort   int
	WebSockets  []WebSocketSpec
	Maint       bool
	MaintVar    string
	MaintAllow  []string
	MaintSecret string
	MaintRetry  int
	MaintRoot   string

	// Apache, when the site runs behind it (see apache.go).
	Apache     bool
	ApachePort int
	Aliases    []string

	// Suspended serves only the suspension page.
	Suspended     bool
	SuspendedRoot string

	// ExtraConf is the glob of the site's extra snippets (ExtraDir).
	ExtraConf string

	// Names the certificate does not cover yet, served on plain HTTP
	// while the others redirect to HTTPS.
	PlainNames string
	// Subdomains are full copies of this data with their own names and
	// document root, rendered as server blocks of their own.
	Subdomains       []tmplData
	HostRedirects    []hostRedirect
	PathRedirects    []pathRedirect
	ErrorPages       []errorPage
	ErrorRoot        string
	Directives       string
	ApacheDirectives string

	// Pool holds the site's PHP-FPM pool settings (see php.go).
	Pool pool

	// Cache is the site's page cache, browser cache and compression (see
	// cache.go); nil leaves Nginx's defaults.
	Cache *cacheData
	// NoIndex asks search engines to leave a staging copy out.
	NoIndex bool
}

func render(t *template.Template, d tmplData) []byte {
	var b bytes.Buffer
	if err := t.Execute(&b, d); err != nil {
		// Templates are static and data is validated; this is a bug.
		panic(err)
	}
	return b.Bytes()
}

// TLSConfig returns the shared TLS settings file every HTTPS vhost
// includes (cert.TLSConf), for other vhosts Serfex writes.
func TLSConfig() []byte { return tlsConf }
