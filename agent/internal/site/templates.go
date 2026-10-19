package site

import (
	"bytes"
	"text/template"

	"github.com/winchester37/servik/agent/internal/txn"
)

var nginxTmpl = template.Must(template.New("nginx").Parse(`# {{.Marker}}. Manual changes are overwritten.
# Site: {{.Domain}} (user {{.User}}, PHP {{.PHPVersion}})
{{- define "acme"}}
    # Let's Encrypt validation, answered by Servik whatever the site does.
    location ^~ /.well-known/acme-challenge/ {
        root {{.ACMERoot}};
        default_type "text/plain";
        try_files $uri =404;
    }
{{- end}}
{{- if .Maint}}
# Maintenance: everyone gets the maintenance page except these addresses,
# browsers holding the bypass cookie, and Let's Encrypt validation.
geo $servik_maint_ip_{{.MaintVar}} {
    default 1;
{{- range .MaintAllow}}
    {{.}} 0;
{{- end}}
}
map $cookie_servik_maintenance $servik_maint_c_{{.MaintVar}} {
{{- if .MaintSecret}}
    "{{.MaintSecret}}" 0;
{{- end}}
    default $servik_maint_ip_{{.MaintVar}};
}
map $uri $servik_maint_{{.MaintVar}} {
{{- if .MaintSecret}}
    "/{{.MaintSecret}}" 0;
{{- end}}
    "~^/\.well-known/acme-challenge/" 0;
    default $servik_maint_c_{{.MaintVar}};
}
{{- end}}
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
    server_name {{.ServerNames}};

    root {{.DocumentRoot}};
    index index.php index.html index.htm;

    access_log {{.LogDir}}/access.log;
    error_log {{.LogDir}}/error.log;

    client_max_body_size 64m;
{{template "acme" .}}
{{- if .Suspended}}

    # The hosting account is suspended: every request gets the suspension
    # page. The files stay where they are.
    error_page 503 @servik_suspended;
    location @servik_suspended {
        root {{.SuspendedRoot}};
        try_files /{{.Domain}}.html =503;
        add_header Cache-Control "no-store" always;
    }

    location / {
        return 503;
    }
}
{{- else}}
{{- if .Maint}}

    error_page 503 @servik_maintenance;
    location @servik_maintenance {
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
        add_header Set-Cookie "servik_maintenance={{.MaintSecret}}; Path=/; Max-Age=43200; HttpOnly; SameSite=Lax{{if .TLS}}; Secure{{end}}";
        return 302 /;
    }
{{- end}}

    if ($servik_maint_{{.MaintVar}}) {
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
        proxy_set_header Connection $servik_connection_upgrade;
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
        try_files /servik-no-such-file @app;
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
        proxy_set_header Connection $servik_connection_upgrade;
        proxy_read_timeout 120s;
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
    }
{{- end}}

    location ~ /\.(?!well-known) {
        deny all;
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
ssl_session_cache shared:ServikTLS:10m;
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

pm = ondemand
pm.max_children = {{.MaxChildren}}
pm.process_idle_timeout = 30s
pm.max_requests = 500
; Live monitoring reads the pool's status here; Nginx never passes this
; path, and the status socket only answers root and the web server.
pm.status_path = /servik-fpm-status
pm.status_listen = {{.StatusSocket}}

chdir = /
php_admin_value[open_basedir] = {{.Home}}/
php_admin_value[upload_tmp_dir] = {{.Home}}/tmp
php_admin_value[sys_temp_dir] = {{.Home}}/tmp
php_admin_value[session.save_path] = {{.Home}}/tmp
php_admin_value[error_log] = {{.SiteDir}}/logs/php-error.log
php_admin_flag[log_errors] = on
`))

const placeholderHTML = `<!doctype html>
<html lang="tr">
<meta charset="utf-8">
<title>{{.Domain}}</title>
<style>body{font-family:system-ui,sans-serif;margin:15vh auto;max-width:36rem;padding:0 1rem;color:#11191c}</style>
<h1>{{.Domain}}</h1>
<p>Bu site Servik ile oluşturuldu. Dosyalarınızı <code>public_html</code> klasörüne yükleyin.</p>
`

var placeholderTmpl = template.Must(template.New("index").Parse(placeholderHTML))

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

	// Suspended serves only the suspension page.
	Suspended     bool
	SuspendedRoot string
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
// includes (cert.TLSConf), for other vhosts Servik writes.
func TLSConfig() []byte { return tlsConf }
