package database

import "text/template"

type webData struct {
	Marker string
	// Ports are serfex-web's ports, the primary first.
	Ports              []int
	IPv6               bool
	Cert, Key, TLSConf string
	// PanelNames are the names on the panel's own certificate, served
	// with PanelCert and PanelKey; empty until it has one.
	PanelNames              []string
	PanelCert, PanelKey     string
	Socket, WWW, Logs, Temp string
	WebUser, AdminUser, PHP string
	PhpMyAdmin, Adminer     bool
	Tokens, Sessions, Tmp   string
	Secret                  string
	// Panel serves the installed panel at / (see package panel).
	Panel                             bool
	PanelRoot, PanelSocket, PanelUser string
}

var webConfTmpl = template.Must(template.New("web").Parse(`# {{.Marker}}. Manual changes are overwritten.
# serfex-web: Serfex's own web server. It is separate from the Nginx that
# serves the customers' sites, so their changes and restarts never reach
# it, and it serves only Serfex: the panel and its database tools.
user {{.WebUser}};
worker_processes 1;
pid /run/serfex-web.pid;
error_log {{.Logs}}/error.log warn;

events {
    worker_connections 256;
}

http {
    include /etc/nginx/mime.types;
    default_type application/octet-stream;
    sendfile on;
    server_tokens off;
    access_log {{.Logs}}/access.log;
    client_max_body_size 256m;
    client_body_temp_path {{.Temp}}/body;
    fastcgi_temp_path {{.Temp}}/fastcgi;
    proxy_temp_path {{.Temp}}/proxy;
    uwsgi_temp_path {{.Temp}}/uwsgi;
    scgi_temp_path {{.Temp}}/scgi;

    # Opened by the server's IP address or any name but the panel's: the
    # certificate the browser was shown first, so a certificate for the
    # panel's name never breaks a page that is open on the address.
    server {
{{- range .Ports}}
        listen {{.}} ssl http2 default_server;
{{- if $.IPv6}}
        listen [::]:{{.}} ssl http2 default_server;
{{- end}}
{{- end}}
        ssl_certificate {{.Cert}};
        ssl_certificate_key {{.Key}};
{{- template "body" .}}
    }
{{- if .PanelNames}}

    # Opened by the panel's name: its own certificate.
    server {
{{- range .Ports}}
        listen {{.}} ssl http2;
{{- if $.IPv6}}
        listen [::]:{{.}} ssl http2;
{{- end}}
{{- end}}
        server_name{{range .PanelNames}} {{.}}{{end}};
        ssl_certificate {{.PanelCert}};
        ssl_certificate_key {{.PanelKey}};
{{- template "body" .}}
    }
{{- end}}
}
{{define "body"}}
        include {{.TLSConf}};

        add_header X-Content-Type-Options nosniff always;
        add_header Referrer-Policy same-origin always;
        # Plain HTTP on this port goes to HTTPS.
        error_page 497 =301 https://$host:$server_port$request_uri;

{{- if .Panel}}

        # The panel. $realpath_root resolves the current release, so a
        # switched release is picked up by the next request.
        root {{.PanelRoot}};
        index index.php;
        location / {
            try_files $uri $uri/ /index.php?$query_string;
        }
        location = /index.php {
            include /etc/nginx/fastcgi_params;
            fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;
            fastcgi_param DOCUMENT_ROOT $realpath_root;
            fastcgi_param HTTPS on;
            fastcgi_pass unix:{{.PanelSocket}};
            fastcgi_read_timeout 300s;
            fastcgi_buffers 16 16k;
            fastcgi_buffer_size 32k;
        }
        location ~ \.php$ {
            return 404;
        }
        location ~ /\.(?!well-known) {
            deny all;
        }
{{- else}}
        location = / {
            return 404;
        }
{{- end}}
{{- if .PhpMyAdmin}}

        # phpMyAdmin for MySQL and MariaDB.
        location = /phpmyadmin/serfex-sso.php {
            include /etc/nginx/fastcgi_params;
            fastcgi_param SCRIPT_FILENAME {{.WWW}}/phpmyadmin-sso.php;
            fastcgi_pass unix:{{.Socket}};
        }
        location ^~ /phpmyadmin/ {
            root /usr/share;
            index index.php;
            add_header X-Frame-Options SAMEORIGIN always;
            add_header X-Content-Type-Options nosniff always;
            add_header Referrer-Policy same-origin always;
            location ~ ^/phpmyadmin/(libraries|templates|setup|sql|vendor|doc)/ {
                deny all;
            }
            location ~ \.php$ {
                try_files $uri =404;
                include /etc/nginx/fastcgi_params;
                fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
                fastcgi_pass unix:{{.Socket}};
            }
        }
{{- end}}
{{- if .Adminer}}

        # Adminer for PostgreSQL.
        location = /adminer/ {
            add_header X-Frame-Options SAMEORIGIN always;
            add_header X-Content-Type-Options nosniff always;
            add_header Referrer-Policy same-origin always;
            include /etc/nginx/fastcgi_params;
            fastcgi_param SCRIPT_FILENAME {{.WWW}}/adminer.php;
            fastcgi_pass unix:{{.Socket}};
        }
{{- end}}
{{- end}}
`))

var phpConfTmpl = template.Must(template.New("php").Parse(`; {{.Marker}}. Manual changes are overwritten.
; serfex-php: Serfex's own PHP-FPM, separate from the customers' pools.
[global]
pid = /run/serfex-php/php-fpm.pid
error_log = {{.Logs}}/php-fpm.log
daemonize = no

[dbadmin]
user = {{.AdminUser}}
group = {{.AdminUser}}
listen = {{.Socket}}
listen.owner = {{.WebUser}}
listen.group = {{.WebUser}}
listen.mode = 0660

pm = ondemand
pm.max_children = 8
pm.process_idle_timeout = 60s
request_terminate_timeout = 600s

php_admin_value[session.save_path] = {{.Sessions}}
php_admin_value[upload_tmp_dir] = {{.Tmp}}
php_admin_value[sys_temp_dir] = {{.Tmp}}
php_admin_value[open_basedir] = /usr/share/phpmyadmin/:/usr/share/adminer/:/usr/share/php/:/usr/share/javascript/:/usr/share/doc/phpmyadmin/:/etc/phpmyadmin/:/var/lib/phpmyadmin/:{{.WWW}}/:{{.Tokens}}/:{{.Sessions}}/:{{.Tmp}}/
php_admin_value[upload_max_filesize] = 256M
php_admin_value[post_max_size] = 256M
php_admin_value[memory_limit] = 512M
php_admin_value[max_execution_time] = 600
php_admin_value[disable_functions] = exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec
php_admin_flag[expose_php] = off
php_admin_flag[log_errors] = on
php_admin_value[error_log] = {{.Logs}}/php-errors.log
{{- if .Panel}}

[panel]
user = {{.PanelUser}}
group = {{.PanelUser}}
listen = {{.PanelSocket}}
listen.owner = {{.WebUser}}
listen.group = {{.WebUser}}
listen.mode = 0660

pm = dynamic
pm.max_children = 16
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 4
request_terminate_timeout = 900s

php_admin_value[upload_max_filesize] = 256M
php_admin_value[post_max_size] = 256M
php_admin_value[memory_limit] = 512M
php_admin_value[max_execution_time] = 900
php_admin_flag[expose_php] = off
php_admin_flag[log_errors] = on
php_admin_value[error_log] = {{.Logs}}/panel-php-errors.log
{{- end}}
`))

var webUnitTmpl = template.Must(template.New("webunit").Parse(`# {{.Marker}}. Manual changes are overwritten.
[Unit]
Description=Serfex web server (panel and database manager)
After=network-online.target serfex-php.service
Wants=network-online.target

[Service]
Type=forking
PIDFile=/run/serfex-web.pid
ExecStartPre=/usr/sbin/nginx -t -q -c /etc/serfex/web/nginx.conf
ExecStart=/usr/sbin/nginx -c /etc/serfex/web/nginx.conf
ExecReload=/usr/sbin/nginx -c /etc/serfex/web/nginx.conf -s reload
ExecStop=-/sbin/start-stop-daemon --quiet --stop --retry QUIT/5 --pidfile /run/serfex-web.pid
TimeoutStopSec=5
KillMode=mixed
Restart=on-failure

[Install]
WantedBy=multi-user.target
`))

var phpUnitTmpl = template.Must(template.New("phpunit").Parse(`# {{.Marker}}. Manual changes are overwritten.
[Unit]
Description=Serfex PHP-FPM (panel and database manager)
After=network.target

[Service]
Type=notify
ExecStart=/usr/sbin/php-fpm{{.PHP}} --nodaemonize --fpm-config /etc/serfex/web/php-fpm.conf
ExecReload=/bin/kill -USR2 $MAINPID
RuntimeDirectory=serfex-php
RuntimeDirectoryMode=0755
Restart=on-failure

[Install]
WantedBy=multi-user.target
`))

var pmaConfTmpl = template.Must(template.New("pma").Parse(`<?php // {{.Marker}}. Manual changes are overwritten.
// phpMyAdmin as Serfex serves it: signed in from the panel with a
// temporary user, never with a password form.
$cfg['blowfish_secret'] = sodium_hex2bin('{{.Secret}}');
$cfg['Servers'][1]['host'] = '127.0.0.1';
$cfg['Servers'][1]['connect_type'] = 'tcp';
$cfg['Servers'][1]['auth_type'] = 'signon';
$cfg['Servers'][1]['SignonSession'] = 'SerfexSignon';
$cfg['Servers'][1]['SignonCookieParams'] = ['lifetime' => 0, 'path' => '/phpmyadmin/', 'domain' => '', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax'];
$cfg['Servers'][1]['SignonURL'] = '/phpmyadmin/serfex-sso.php';
$cfg['Servers'][1]['LogoutURL'] = '/phpmyadmin/serfex-sso.php?logout=1';
$cfg['Servers'][1]['AllowRoot'] = false;
$cfg['Servers'][1]['AllowNoPassword'] = false;
$cfg['Servers'][1]['controluser'] = '';
$cfg['Servers'][1]['controlpass'] = '';
$cfg['Servers'][1]['pmadb'] = '';
$cfg['TempDir'] = '{{.Tmp}}';
$cfg['UploadDir'] = '';
$cfg['SaveDir'] = '';
$cfg['VersionCheck'] = false;
$cfg['SendErrorReports'] = 'never';
$cfg['ShowCreateDb'] = false;
$cfg['ShowChgPassword'] = false;
$cfg['PmaNoRelation_DisableWarning'] = true;
$cfg['DefaultLang'] = 'tr';
`))

// adminPages are served from WWW.
var adminPages = map[string]*template.Template{
	"token.php":          tokenPHP,
	"phpmyadmin-sso.php": pmaSSOPHP,
	"adminer.php":        adminerPHP,
}

var tokenPHP = template.Must(template.New("token").Parse(`<?php // {{.Marker}}. Manual changes are overwritten.
// One-time sign-in links from the Serfex panel. The agent writes a token
// file with a temporary user; the first visit reads and removes it.

function serfex_token($token, $server)
{
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $file = '{{.Tokens}}/' . $token . '.json';
    $raw = @file_get_contents($file);
    @unlink($file);
    $data = $raw === false ? null : json_decode($raw, true);
    if (!is_array($data) || ($data['server'] ?? '') !== $server || (int) ($data['expires'] ?? 0) < time()) {
        return null;
    }
    return $data;
}

function serfex_page($title, $text, $status = 403)
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Serfex</title>',
        '<style>body{font-family:system-ui,sans-serif;background:#f6f7f8;color:#1b1f23;display:grid;place-items:center;min-height:100vh;margin:0}',
        'main{max-width:420px;padding:24px;border:1px solid #dde1e4;border-radius:12px;background:#fff}h1{font-size:17px;margin:0 0 8px}p{font-size:14px;color:#5b6570;margin:0}',
        '@media (prefers-color-scheme:dark){body{background:#111417;color:#e6e9ec}main{background:#181c20;border-color:#2a3036}p{color:#9aa4ad}}</style></head>',
        '<body><main><h1>', htmlspecialchars($title), '</h1><p>', htmlspecialchars($text), '</p></main></body></html>';
    exit;
}
`))

var pmaSSOPHP = template.Must(template.New("pmasso").Parse(`<?php // {{.Marker}}. Manual changes are overwritten.
// Signs the browser into phpMyAdmin with the temporary user of a one-time
// link from the panel.
require __DIR__ . '/token.php';

session_name('SerfexSignon');
session_set_cookie_params(['lifetime' => 0, 'path' => '/phpmyadmin/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);

if (isset($_GET['logout'])) {
    session_start();
    $_SESSION = [];
    session_destroy();
    serfex_page('Oturum kapatıldı', 'phpMyAdmin oturumunuz kapandı. Yeniden açmak için Serfex panelinde Veritabanları sayfasını kullanın.', 200);
}

$data = serfex_token($_GET['token'] ?? null, 'mysql');
if ($data === null) {
    serfex_page('Oturum geçersiz', 'Bu bağlantı kullanılmış ya da süresi dolmuş. Serfex panelinde Veritabanları sayfasından phpMyAdmin\'i yeniden açın.');
}

session_start();
session_regenerate_id(true);
$_SESSION['PMA_single_signon_user'] = $data['user'];
$_SESSION['PMA_single_signon_password'] = $data['password'];
$_SESSION['PMA_single_signon_host'] = '127.0.0.1';
session_write_close();

header('Location: /phpmyadmin/index.php?route=/database/structure&db=' . rawurlencode($data['database']));
`))

var adminerPHP = template.Must(template.New("adminer").Parse(`<?php // {{.Marker}}. Manual changes are overwritten.
// Adminer for PostgreSQL, signed in with the temporary user of a one-time
// link from the panel. It only ever connects to this server's PostgreSQL.
require __DIR__ . '/token.php';

foreach (['server', 'sqlite', 'sqlite2', 'oracle', 'mssql', 'mongo', 'elastic', 'firebird', 'simpledb', 'clickhouse'] as $driver) {
    if (isset($_GET[$driver])) {
        serfex_page('Desteklenmiyor', 'Bu yönetici yalnızca bu sunucudaki PostgreSQL veritabanlarını açar.');
    }
}

if (isset($_GET['serfex_token'])) {
    $data = serfex_token($_GET['serfex_token'], 'postgresql');
    if ($data === null) {
        serfex_page('Oturum geçersiz', 'Bu bağlantı kullanılmış ya da süresi dolmuş. Serfex panelinde Veritabanları sayfasından Adminer\'ı yeniden açın.');
    }
    // Adminer redirects to the page it was asked for; the used token
    // must not come along.
    $_GET = [];
    $_SERVER['REQUEST_URI'] = '/adminer/';
    $_SERVER['QUERY_STRING'] = '';
    $_POST = ['auth' => [
        'driver' => 'pgsql',
        'server' => '127.0.0.1',
        'username' => $data['user'],
        'password' => $data['password'],
        'db' => $data['database'],
        'permanent' => '',
    ]];
} elseif (!isset($_GET['pgsql']) && !isset($_GET['file'])) {
    serfex_page('Oturum yok', 'Adminer\'ı Serfex panelinde Veritabanları sayfasından açın.');
}

function adminer_object()
{
    class SerfexAdminer extends Adminer
    {
        public function name()
        {
            return 'Serfex';
        }

        public function credentials()
        {
            return ['127.0.0.1', $_GET['username'] ?? '', get_password()];
        }

        public function permanentLogin($create = false)
        {
            return '';
        }

        public function loginForm()
        {
            echo '<p>Oturumun süresi doldu. Adminer\'ı Serfex panelinde Veritabanları sayfasından yeniden açın.</p>';
        }
    }

    return new SerfexAdminer();
}

include '/usr/share/adminer/adminer.php';
`))
