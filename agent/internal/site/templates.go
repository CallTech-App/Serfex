package site

import (
	"bytes"
	"text/template"
)

var nginxTmpl = template.Must(template.New("nginx").Parse(`# {{.Marker}}. Manual changes are overwritten.
# Site: {{.Domain}} (user {{.User}}, PHP {{.PHPVersion}})
server {
    listen 80;
{{- if .IPv6}}
    listen [::]:80;
{{- end}}
    server_name {{.ServerNames}};

    root {{.DocumentRoot}};
    index index.php index.html index.htm;

    access_log {{.LogDir}}/access.log;
    error_log {{.LogDir}}/error.log;

    client_max_body_size 64m;

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

    location ~ /\.(?!well-known) {
        deny all;
    }
}
`))

var fpmTmpl = template.Must(template.New("fpm").Parse(`; {{.Marker}}. Manual changes are overwritten.
[{{.Domain}}]
user = {{.User}}
group = {{.User}}

listen = {{.PHPSocket}}
listen.owner = {{.WebUser}}
listen.group = {{.WebUser}}
listen.mode = 0660

pm = ondemand
pm.max_children = 10
pm.process_idle_timeout = 30s
pm.max_requests = 500

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
	IPv6         bool
}

func render(t *template.Template, d tmplData) []byte {
	var b bytes.Buffer
	if err := t.Execute(&b, d); err != nil {
		// Templates are static and data is validated; this is a bug.
		panic(err)
	}
	return b.Bytes()
}
