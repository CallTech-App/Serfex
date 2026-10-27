package mail

import (
	"text/template"

	"github.com/winchester37/servik/agent/internal/txn"
)

var mailVhostTmpl = template.Must(template.New("vhost").Parse(`# {{.Marker}}. Manual changes are overwritten.
# The mail server's own address: certificate validation, settings for
# mail clients{{if .Webmail}} and webmail (Roundcube){{end}}. Customers' sites have
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

    root /var/lib/roundcube/public_html;
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
; Roundcube runs as its own account, apart from every customer site.
[servik-webmail]
user = servik-webmail
group = servik-webmail

listen = {{.PHPSocket}}
listen.owner = {{.WebUser}}
listen.group = {{.WebUser}}
listen.mode = 0660

pm = ondemand
pm.max_children = 20
pm.process_idle_timeout = 30s
pm.max_requests = 500

chdir = /
env[ROUNDCUBE_CONFIG_DIR] = {{.ConfigDir}}
php_admin_value[open_basedir] = /var/lib/roundcube/:/usr/share/roundcube/:/etc/roundcube/:{{.ConfigDir}}/:/var/lib/servik/webmail/:{{.LogDir}}/:/usr/share/php/:/usr/share/javascript/:/usr/share/fonts/
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
# Runs Roundcube's job for Servik's webmail.
[Service]
User=` + WebmailUser + `
Group=` + WebmailUser + `
Environment=ROUNDCUBE_CONFIG_DIR=` + webmailConfig + `
ExecStart=
ExecStart=` + php + ` /usr/share/roundcube/bin/` + script + `
`)
}

var roundcubeConfigTmpl = template.Must(template.New("config").Parse(`<?php // {{.Marker}}. Manual changes are overwritten.
// Roundcube for Servik. Read through ROUNDCUBE_CONFIG_DIR instead of
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
$config['plugins'] = ['servik_sso', 'managesieve', 'archive', 'zipdownload', 'markasjunk'];
$config['servik_sso_dir'] = '{{.DataDir}}/sso';
$config['servik_sso_master'] = '{{.MasterUser}}';
$config['servik_sso_password'] = '{{.MasterPass}}';
`))

var ssoPlugin = []byte(`<?php // Managed by Servik. Manual changes are overwritten.

/**
 * One-click login from the Servik panel.
 *
 * The panel asks servik-agent for a token; the agent writes it to
 * servik_sso_dir as <token>.json with the address and an expiry. Opening
 * /?_task=login&_action=servik-sso&_token=<token> uses the token once and
 * signs in as that address through Dovecot's master user, which only
 * accepts connections from this host.
 */
class servik_sso extends rcube_plugin
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
        if ($args['task'] === 'login' && $args['action'] === 'servik-sso') {
            $args['action'] = 'login';
        }

        return $args;
    }

    public function authenticate($args)
    {
        $token = (string) rcube_utils::get_input_string('_token', rcube_utils::INPUT_GET);
        if (rcube_utils::get_input_string('_action', rcube_utils::INPUT_GET) !== 'servik-sso' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return $args;
        }
        $rcmail = rcmail::get_instance();
        $file = rtrim((string) $rcmail->config->get('servik_sso_dir'), '/') . '/' . $token . '.json';
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
        $_SESSION['servik_sso'] = true;
        $args['user'] = $data['user'];
        $args['pass'] = (string) $rcmail->config->get('servik_sso_password');
        $args['cookiecheck'] = false;
        $args['valid'] = true;

        return $args;
    }

    public function connect($args)
    {
        if (!empty($_SESSION['servik_sso']) && strpos((string) $args['user'], '*') === false) {
            $rcmail = rcmail::get_instance();
            $args['user'] = $args['user'] . '*' . $rcmail->config->get('servik_sso_master');
            $args['pass'] = (string) $rcmail->config->get('servik_sso_password');
        }

        return $args;
    }

    public function smtp_connect($args)
    {
        if (!empty($_SESSION['servik_sso'])) {
            $rcmail = rcmail::get_instance();
            $args['smtp_user'] = $rcmail->get_user_name() . '*' . $rcmail->config->get('servik_sso_master');
            $args['smtp_pass'] = (string) $rcmail->config->get('servik_sso_password');
        }

        return $args;
    }
}
`)
