package mail

import (
	"text/template"

	"github.com/calltech-app/serfex/agent/internal/txn"
)

var mailVhostTmpl = template.Must(template.New("vhost").Parse(`# {{.Marker}}. Manual changes are overwritten.
# The mail server's own address: certificate validation, settings for
# mail clients{{if .Webmail}} and webmail ({{if eq .WebmailApp "snappymail"}}SnappyMail{{else}}Roundcube{{end}}){{end}}. Customers' sites have
# their own vhosts; autoconfig.<domain> and autodiscover.<domain> of every
# mail domain end up here.
server {
    listen 80;
{{- if .IPv6}}
    listen [::]:80;
{{- end}}
    server_name {{.Hostname}} ~^autoconfig\. ~^autodiscover\.;

    location ^~ /.well-known/acme-challenge/ {
        root {{.ACMERoot}};
        default_type "text/plain";
        try_files $uri =404;
    }
{{template "clients" .}}
    location / {
{{- if .Webmail}}
        return 301 https://{{.Hostname}}$request_uri;
{{- else}}
        return 404;
{{- end}}
    }
}
{{- if .Webmail}}

server {
    listen 443 ssl http2;
{{- if .IPv6}}
    listen [::]:443 ssl http2;
{{- end}}
    server_name {{.Hostname}};
    ssl_certificate {{.CertFile}};
    ssl_certificate_key {{.KeyFile}};
    include {{.TLSConf}};

{{- if eq .WebmailApp "snappymail"}}
    root {{.WebmailRoot}};
{{- else}}
    root /var/lib/roundcube/public_html;
{{- end}}
    index index.php;
    client_max_body_size 26m;
    access_log {{.LogDir}}/access.log;
    error_log {{.LogDir}}/error.log;
{{template "clients" .}}
    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_param HTTPS on;
        fastcgi_pass unix:{{.PHPSocket}};
    }
{{- if eq .WebmailApp "snappymail"}}
    # One-click login from the panel; the script lives outside the release.
    location = /serfex-sso.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME {{.SSOFile}};
        fastcgi_param HTTPS on;
        fastcgi_pass unix:{{.PHPSocket}};
    }
    # Only the release's static files are served; its code, data and
    # sample files are not.
    location ^~ /snappymail/ {
        location ~ ^/snappymail/v/[0-9.]+/(static|themes)/ {
            location ~ \.php$ {
                return 404;
            }
            expires 7d;
            try_files $uri =404;
        }
        return 404;
    }
    location ^~ /data/ {
        return 404;
    }
{{- end}}
    location ~ \.php$ {
        return 404;
    }
    location ~ /\.(?!well-known) {
        deny all;
    }
    location / {
        try_files $uri $uri/ =404;
    }
}
{{- end}}
`))

func init() {
	template.Must(mailVhostTmpl.New("clients").Parse(`
    # Thunderbird and most clients read their settings from here.
    location = /mail/config-v1.1.xml {
        alias {{.AutoconfigDir}}/config-v1.1.xml;
        default_type application/xml;
    }
    location = /.well-known/autoconfig/mail/config-v1.1.xml {
        alias {{.AutoconfigDir}}/config-v1.1.xml;
        default_type application/xml;
    }
    # Outlook POSTs here (found through _autodiscover._tcp).
    location ~* ^/autodiscover/autodiscover\.xml$ {
        default_type application/xml;
        return 200 '<?xml version="1.0" encoding="utf-8"?><Autodiscover xmlns="http://schemas.microsoft.com/exchange/autodiscover/responseschema/2006"><Response xmlns="http://schemas.microsoft.com/exchange/autodiscover/outlook/responseschema/2006a"><Account><AccountType>email</AccountType><Action>settings</Action><Protocol><Type>IMAP</Type><Server>{{.Hostname}}</Server><Port>993</Port><SSL>on</SSL><SPA>off</SPA><AuthRequired>on</AuthRequired></Protocol><Protocol><Type>SMTP</Type><Server>{{.Hostname}}</Server><Port>465</Port><SSL>on</SSL><SPA>off</SPA><AuthRequired>on</AuthRequired></Protocol></Account></Response></Autodiscover>';
    }
`))
}

var autoconfigTmpl = template.Must(template.New("autoconfig").Parse(`<!-- {{.Marker}}. Manual changes are overwritten. -->
<clientConfig version="1.1">
  <emailProvider id="{{.Hostname}}">
    <domain>{{.Hostname}}</domain>
    <displayName>{{.Hostname}}</displayName>
    <incomingServer type="imap">
      <hostname>{{.Hostname}}</hostname>
      <port>993</port>
      <socketType>SSL</socketType>
      <authentication>password-cleartext</authentication>
      <username>%EMAILADDRESS%</username>
    </incomingServer>
    <incomingServer type="pop3">
      <hostname>{{.Hostname}}</hostname>
      <port>995</port>
      <socketType>SSL</socketType>
      <authentication>password-cleartext</authentication>
      <username>%EMAILADDRESS%</username>
    </incomingServer>
    <outgoingServer type="smtp">
      <hostname>{{.Hostname}}</hostname>
      <port>465</port>
      <socketType>SSL</socketType>
      <authentication>password-cleartext</authentication>
      <username>%EMAILADDRESS%</username>
    </outgoingServer>
  </emailProvider>
</clientConfig>
`))

var webmailPoolTmpl = template.Must(template.New("pool").Parse(`; {{.Marker}}. Manual changes are overwritten.
; Webmail runs as its own account, apart from every customer site.
[serfex-webmail]
user = serfex-webmail
group = serfex-webmail

listen = {{.PHPSocket}}
listen.owner = {{.WebUser}}
listen.group = {{.WebUser}}
listen.mode = 0660

pm = ondemand
pm.max_children = 20
pm.process_idle_timeout = 30s
pm.max_requests = 500

chdir = /
{{- if eq .WebmailApp "snappymail"}}
php_admin_value[open_basedir] = {{.WebmailRoot}}/:/var/lib/serfex/webmail/:{{.LogDir}}/
{{- else}}
env[ROUNDCUBE_CONFIG_DIR] = {{.ConfigDir}}
php_admin_value[open_basedir] = /var/lib/roundcube/:/usr/share/roundcube/:/etc/roundcube/:{{.ConfigDir}}/:/var/lib/serfex/webmail/:{{.LogDir}}/:/usr/share/php/:/usr/share/javascript/:/usr/share/fonts/
{{- end}}
php_admin_value[upload_tmp_dir] = {{.DataDir}}/temp
php_admin_value[sys_temp_dir] = {{.DataDir}}/temp
php_admin_value[session.save_path] = {{.DataDir}}/temp
php_admin_value[upload_max_filesize] = 25M
php_admin_value[post_max_size] = 26M
php_admin_value[memory_limit] = 256M
php_admin_value[error_log] = {{.LogDir}}/php-error.log
php_admin_flag[log_errors] = on
php_admin_flag[display_errors] = off
`))

// roundcubeJobUnit is the drop-in that runs one of Roundcube's systemd
// housekeeping jobs on webmail's database and PHP version, instead of the
// package's own configuration and the newest PHP.
func roundcubeJobUnit(php, script string) []byte {
	return []byte(`# ` + txn.Marker + `. Manual changes are overwritten.
# Runs Roundcube's job for Serfex's webmail.
[Service]
User=` + WebmailUser + `
Group=` + WebmailUser + `
Environment=ROUNDCUBE_CONFIG_DIR=` + webmailConfig + `
ExecStart=
ExecStart=` + php + ` /usr/share/roundcube/bin/` + script + `
`)
}

var roundcubeConfigTmpl = template.Must(template.New("config").Parse(`<?php // {{.Marker}}. Manual changes are overwritten.
// Roundcube for Serfex. Read through ROUNDCUBE_CONFIG_DIR instead of
// /etc/roundcube, whose files belong to the package.
$config = [];
$config['db_dsnw'] = 'sqlite:///{{.DataDir}}/roundcube.db?mode=0600';
$config['imap_host'] = 'localhost:143';
$config['smtp_host'] = 'tls://127.0.0.1:587';
$config['smtp_user'] = '%u';
$config['smtp_pass'] = '%p';
// Loopback connection to this server's own Postfix.
$config['smtp_conn_options'] = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]];
$config['managesieve_host'] = 'localhost';
$config['managesieve_port'] = 4190;
$config['login_lc'] = 2;
$config['product_name'] = 'Webmail';
$config['support_url'] = '';
$config['des_key'] = '{{.DESKey}}';
$config['temp_dir'] = '{{.DataDir}}/temp/';
$config['log_dir'] = '{{.LogDir}}/';
$config['skin'] = 'elastic';
$config['language'] = 'tr_TR';
$config['enable_installer'] = false;
$config['plugins'] = ['serfex_sso', 'managesieve', 'archive', 'zipdownload', 'markasjunk'];
$config['serfex_sso_dir'] = '{{.DataDir}}/sso';
$config['serfex_sso_master'] = '{{.MasterUser}}';
$config['serfex_sso_password'] = '{{.MasterPass}}';
`))

var ssoPlugin = []byte(`<?php // Managed by Serfex. Manual changes are overwritten.

/**
 * One-click login from the Serfex panel.
 *
 * The panel asks serfex-agent for a token; the agent writes it to
 * serfex_sso_dir as <token>.json with the address and an expiry. Opening
 * /?_task=login&_action=serfex-sso&_token=<token> uses the token once and
 * signs in as that address through Dovecot's master user, which only
 * accepts connections from this host.
 */
class serfex_sso extends rcube_plugin
{
    public $task = 'login|mail|settings|addressbook|utils|logout';

    public function init()
    {
        $this->add_hook('startup', [$this, 'startup']);
        $this->add_hook('authenticate', [$this, 'authenticate']);
        $this->add_hook('storage_connect', [$this, 'connect']);
        $this->add_hook('managesieve_connect', [$this, 'connect']);
        $this->add_hook('smtp_connect', [$this, 'smtp_connect']);
    }

    public function startup($args)
    {
        if ($args['task'] === 'login' && $args['action'] === 'serfex-sso') {
            $args['action'] = 'login';
        }

        return $args;
    }

    public function authenticate($args)
    {
        $token = (string) rcube_utils::get_input_string('_token', rcube_utils::INPUT_GET);
        if (rcube_utils::get_input_string('_action', rcube_utils::INPUT_GET) !== 'serfex-sso' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return $args;
        }
        $rcmail = rcmail::get_instance();
        $file = rtrim((string) $rcmail->config->get('serfex_sso_dir'), '/') . '/' . $token . '.json';
        $claimed = $file . '.used';
        // Renaming first makes the token single-use, even for two requests at once.
        if (!@rename($file, $claimed)) {
            return $args;
        }
        $data = json_decode((string) file_get_contents($claimed), true);
        @unlink($claimed);
        if (!is_array($data) || !is_int($data['expires'] ?? null) || $data['expires'] < time() || !is_string($data['user'] ?? null)) {
            return $args;
        }
        $_SESSION['serfex_sso'] = true;
        $args['user'] = $data['user'];
        $args['pass'] = (string) $rcmail->config->get('serfex_sso_password');
        $args['cookiecheck'] = false;
        $args['valid'] = true;

        return $args;
    }

    public function connect($args)
    {
        if (!empty($_SESSION['serfex_sso']) && strpos((string) $args['user'], '*') === false) {
            $rcmail = rcmail::get_instance();
            $args['user'] = $args['user'] . '*' . $rcmail->config->get('serfex_sso_master');
            $args['pass'] = (string) $rcmail->config->get('serfex_sso_password');
        }

        return $args;
    }

    public function smtp_connect($args)
    {
        if (!empty($_SESSION['serfex_sso'])) {
            $rcmail = rcmail::get_instance();
            $args['smtp_user'] = $rcmail->get_user_name() . '*' . $rcmail->config->get('serfex_sso_master');
            $args['smtp_pass'] = (string) $rcmail->config->get('serfex_sso_password');
        }

        return $args;
    }
}
`)

var snappyMailIncludeTmpl = template.Must(template.New("include").Parse(`<?php // {{.Marker}}. Manual changes are overwritten.
// SnappyMail for Serfex: its data folder is outside the release, so a new
// release starts with every mailbox's settings and contacts.
define('APP_DATA_FOLDER_PATH', '{{.SnappyData}}/');
`))

// snappyMailConfigTmpl is SnappyMail's application.ini. SnappyMail fills in
// what is missing with its defaults; the file is root's and read-only to
// SnappyMail, so nothing is changed from the browser.
var snappyMailConfigTmpl = template.Must(template.New("snappymail").Parse(`; {{.Marker}}. Manual changes are overwritten.
; SnappyMail for Serfex. The admin panel is off; Serfex writes this file.

[webmail]
title = "Webmail"
loading_description = "Webmail"
language = "en"
allow_languages_on_settings = On
attachment_size_limit = 25
popup_identity = Off

[contacts]
enable = On
type = "sqlite"

[security]
allow_admin_panel = Off
admin_password = ""
force_https = On
encrypt_cipher = "aes-256-cbc"
cookie_samesite = "Strict"

[admin_panel]
allow_update = Off

[login]
default_domain = ""
determine_user_language = On
determine_user_domain = Off
allow_languages_on_login = On

[plugins]
enable = On
enabled_list = ""

[logs]
enable = On
path = "{{.LogDir}}/"
filename = "snappymail.log"
level = 4
time_zone = "UTC"

[cache]
enable = On
`))

// snappyMailSSOTmpl signs into SnappyMail from the panel. It works like
// Roundcube's serfex_sso plugin: the agent writes a token to SSODir, the
// script uses it once and signs in through Dovecot's master user, which
// only accepts connections from this host.
var snappyMailSSOTmpl = template.Must(template.New("sso").Parse(`<?php // {{.Marker}}. Manual changes are overwritten.

// One-click login from the Serfex panel into SnappyMail.
$token = (string) ($_GET['token'] ?? '');
$data = null;
if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    $file = '{{.SSODir}}/' . $token . '.json';
    $claimed = $file . '.used';
    // Renaming first makes the token single-use, even for two requests at once.
    if (@rename($file, $claimed)) {
        $data = json_decode((string) file_get_contents($claimed), true);
        @unlink($claimed);
    }
}
if (!is_array($data) || !is_int($data['expires'] ?? null) || $data['expires'] < time() || !is_string($data['user'] ?? null)) {
    header('Location: /', true, 302);
    exit;
}

$_ENV['SNAPPYMAIL_INCLUDE_AS_API'] = true;
require '{{.WebmailRoot}}/index.php';

$actions = \RainLoop\Api::Actions();
$actions->Plugins()->AddHook('login.credentials', function (&$email, &$imapUser, &$password, &$smtpUser) {
    $imapUser = $email . '*{{.MasterUser}}';
    $smtpUser = $email . '*{{.MasterUser}}';
});
try {
    $actions->LoginProcess($data['user'], new \SnappyMail\SensitiveString('{{.MasterPass}}'));
} catch (\Throwable $e) {
    error_log('Serfex one-click login for ' . $data['user'] . ' failed: ' . $e->getMessage());
    header('Location: /', true, 302);
    exit;
}

// A page of SnappyMail's own origin opens the mailbox, so the browser
// sends its SameSite=Strict cookies.
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo '<!doctype html><meta charset="utf-8"><meta http-equiv="refresh" content="0;url=/"><title>Webmail</title>';
`))
