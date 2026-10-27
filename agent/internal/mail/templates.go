package mail

import (
	"bytes"
	"text/template"

	"github.com/calltech-app/serfex/agent/internal/txn"
)

// tmplData fills the configuration templates.
type tmplData struct {
	Marker      string
	Hostname    string
	CertFile    string
	KeyFile     string
	IPv6        bool
	VmailUID    int
	HourlyLimit int
	MessageSize int64
	MilterAddr  string
	RedisSocket string
	Maps        string
	DovecotDir  string
	VmailDir    string

	// Webmail
	Webmail       bool
	WebUser       string
	PHPSocket     string
	TLSConf       string
	ACMERoot      string
	AutoconfigDir string
	LogDir        string
	ConfigDir     string
	DataDir       string
	PluginsDir    string
	MasterUser    string
	MasterPass    string
	DESKey        string
}

func render(t *template.Template, d tmplData) []byte {
	d.Marker = txn.Marker
	var b bytes.Buffer
	if err := t.Execute(&b, d); err != nil {
		// Templates are static and data is validated; this is a bug.
		panic(err)
	}
	return b.Bytes()
}

// TLS ciphers follow Mozilla's "intermediate" profile, without the DHE
// suites so no Diffie-Hellman parameters are needed.
const ciphers = "ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305"

var postfixMainTmpl = template.Must(template.New("main.cf").Parse(`# {{.Marker}}. Manual changes are overwritten.
# Postfix for Servik: virtual mail domains delivered to Dovecot over LMTP,
# Rspamd as milter, authentication through Dovecot. The distribution's
# file, if there was one, is kept as main.cf.before-servik.
compatibility_level = 3.6
myhostname = {{.Hostname}}
myorigin = $myhostname
mydestination = localhost
mynetworks = 127.0.0.0/8 [::1]/128
inet_interfaces = all
inet_protocols = {{if .IPv6}}all{{else}}ipv4{{end}}
smtpd_banner = $myhostname ESMTP
biff = no
append_dot_mydomain = no
alias_maps =
alias_database =
recipient_delimiter = +
message_size_limit = {{.MessageSize}}
mailbox_size_limit = 0
disable_vrfy_command = yes
smtpd_helo_required = yes

# Mail domains, mailboxes and forwarders (rebuilt by servik-agent).
virtual_mailbox_domains = texthash:{{.Maps}}/domains
virtual_mailbox_maps = texthash:{{.Maps}}/mailboxes
virtual_alias_maps = texthash:{{.Maps}}/aliases
virtual_transport = lmtp:unix:private/dovecot-lmtp

# TLS: opportunistic between servers, required for clients (master.cf).
smtpd_tls_chain_files = {{.KeyFile}}, {{.CertFile}}
smtpd_tls_security_level = may
smtpd_tls_protocols = >=TLSv1
smtpd_tls_mandatory_protocols = >=TLSv1.2
smtpd_tls_mandatory_ciphers = high
tls_high_cipherlist = ` + ciphers + `
smtpd_tls_loglevel = 1
smtp_tls_security_level = may
smtp_tls_loglevel = 1

# Mailboxes sign in through Dovecot and may only send as themselves or
# as forwarders that deliver to them.
smtpd_sasl_type = dovecot
smtpd_sasl_path = private/auth
smtpd_sasl_auth_enable = no
smtpd_sender_login_maps = texthash:{{.Maps}}/senders

smtpd_relay_restrictions = permit_mynetworks, permit_sasl_authenticated, defer_unauth_destination
smtpd_recipient_restrictions = reject_unauth_pipelining, reject_non_fqdn_recipient, reject_unknown_recipient_domain, check_recipient_access texthash:{{.Maps}}/suspended, permit_mynetworks, permit_sasl_authenticated, reject_unauth_destination, check_policy_service unix:private/quota-status

# Rspamd scores incoming mail, signs outgoing mail with DKIM and enforces
# sending limits. If it is down, mail still flows.
milter_protocol = 6
milter_default_action = accept
milter_mail_macros = i {mail_addr} {client_addr} {client_name} {auth_authen}
smtpd_milters = inet:{{.MilterAddr}}
non_smtpd_milters = inet:{{.MilterAddr}}
`))

// Submission services share these options: TLS and a mailbox login are
// required, and the sender must belong to the mailbox.
const submissionOpts = `  -o smtpd_sasl_auth_enable=yes
  -o smtpd_client_restrictions=permit_sasl_authenticated,reject
  -o smtpd_sender_restrictions=reject_sender_login_mismatch
  -o smtpd_relay_restrictions=permit_sasl_authenticated,reject
  -o smtpd_recipient_restrictions=
  -o milter_macro_daemon_name=ORIGINATING
`

var postfixMasterTmpl = template.Must(template.New("master.cf").Parse(`# {{.Marker}}. Manual changes are overwritten.
# The distribution's file is kept as master.cf.before-servik.
# service type  private unpriv  chroot  wakeup  maxproc command + args
smtp      inet  n       -       n       -       -       smtpd
submission inet n       -       n       -       -       smtpd
  -o syslog_name=postfix/submission
  -o smtpd_tls_security_level=encrypt
  -o smtpd_tls_auth_only=yes
` + submissionOpts + `submissions inet n      -       n       -       -       smtpd
  -o syslog_name=postfix/submissions
  -o smtpd_tls_wrappermode=yes
` + submissionOpts + `pickup    unix  n       -       n       60      1       pickup
cleanup   unix  n       -       n       -       0       cleanup
qmgr      unix  n       -       n       300     1       qmgr
tlsmgr    unix  -       -       n       1000?   1       tlsmgr
rewrite   unix  -       -       n       -       -       trivial-rewrite
bounce    unix  -       -       n       -       0       bounce
defer     unix  -       -       n       -       0       bounce
trace     unix  -       -       n       -       0       bounce
verify    unix  -       -       n       -       1       verify
flush     unix  n       -       n       1000?   0       flush
proxymap  unix  -       -       n       -       -       proxymap
proxywrite unix -       -       n       -       1       proxymap
smtp      unix  -       -       n       -       -       smtp
relay     unix  -       -       n       -       -       smtp
  -o syslog_name=postfix/$service_name
showq     unix  n       -       n       -       -       showq
error     unix  -       -       n       -       -       error
retry     unix  -       -       n       -       -       error
discard   unix  -       -       n       -       -       discard
local     unix  -       n       n       -       -       local
virtual   unix  -       n       n       -       -       virtual
lmtp      unix  -       -       n       -       -       lmtp
anvil     unix  -       -       n       -       1       anvil
scache    unix  -       -       n       -       1       scache
postlog   unix-dgram n  -       n       -       1       postlogd
`))

var dovecotTmpl = template.Must(template.New("dovecot.conf").Parse(`# {{.Marker}}. Manual changes are overwritten.
# Dovecot for Servik: IMAP, POP3, LMTP delivery from Postfix and Sieve for
# every virtual mailbox. conf.d/ is not read; the distribution's file is
# kept as dovecot.conf.before-servik.
protocols = imap pop3 lmtp sieve
listen = *{{if .IPv6}}, ::{{end}}
hostname = {{.Hostname}}
postmaster_address = postmaster@{{.Hostname}}

mail_location = maildir:~/Maildir
mail_uid = vmail
mail_gid = vmail
first_valid_uid = {{.VmailUID}}
last_valid_uid = {{.VmailUID}}
mail_plugins = quota
recipient_delimiter = +

auth_mechanisms = plain login
disable_plaintext_auth = yes
auth_username_format = %Lu
auth_master_user_separator = *
auth_cache_size = 1M
auth_cache_ttl = 1 hour
auth_cache_negative_ttl = 1 min

ssl = required
ssl_cert = <{{.CertFile}}
ssl_key = <{{.KeyFile}}
ssl_min_protocol = TLSv1.2
ssl_cipher_list = ` + ciphers + `
ssl_prefer_server_ciphers = no

namespace inbox {
  inbox = yes
  mailbox Drafts {
    special_use = \Drafts
    auto = subscribe
  }
  mailbox Sent {
    special_use = \Sent
    auto = subscribe
  }
  mailbox Junk {
    special_use = \Junk
    auto = subscribe
  }
  mailbox Trash {
    special_use = \Trash
    auto = subscribe
  }
}

# Webmail's one-click login: "<mailbox>*{{.MasterUser}}" with the master
# password, accepted only from this host.
passdb {
  driver = passwd-file
  args = username_format=%u {{.DovecotDir}}/master-users
  master = yes
}
passdb {
  driver = passwd-file
  args = username_format=%u {{.DovecotDir}}/users
}
userdb {
  driver = passwd-file
  args = username_format=%u {{.DovecotDir}}/users
  default_fields = uid=vmail gid=vmail home={{.VmailDir}}/%d/%n
}

service auth {
  unix_listener /var/spool/postfix/private/auth {
    mode = 0660
    user = postfix
    group = postfix
  }
}
service lmtp {
  unix_listener /var/spool/postfix/private/dovecot-lmtp {
    mode = 0600
    user = postfix
    group = postfix
  }
}
# Postfix asks before accepting a message whether the mailbox has room.
service quota-status {
  executable = quota-status -p postfix
  unix_listener /var/spool/postfix/private/quota-status {
    mode = 0600
    user = postfix
  }
  client_limit = 1
}
service imap-login {
  inet_listener imap {
    port = 143
  }
  inet_listener imaps {
    port = 993
    ssl = yes
  }
}
service pop3-login {
  inet_listener pop3 {
    port = 110
  }
  inet_listener pop3s {
    port = 995
    ssl = yes
  }
}
# ManageSieve is for webmail's filter settings only.
service managesieve-login {
  inet_listener sieve {
    address = 127.0.0.1
    port = 4190
  }
}

protocol imap {
  mail_plugins = $mail_plugins imap_quota
  mail_max_userip_connections = 20
}
protocol lmtp {
  mail_plugins = $mail_plugins sieve
}

plugin {
  quota = count:User quota
  quota_vsizes = yes
  quota_grace = 10%%
  quota_status_success = DUNNO
  quota_status_nouser = DUNNO
  quota_status_overquota = "552 5.2.2 Mailbox is full"
  # Each mailbox's own filters (from webmail). Spam filing and autoreplies
  # run before them through the mailbox's sieve_before field.
  sieve = file:~/sieve;active=~/.dovecot.sieve
}
`))

var spamSieveTmpl = []byte(`# ` + txn.Marker + `. Manual changes are overwritten.
# Files messages Rspamd marked as spam into Junk.
require ["fileinto"];
if header :is "X-Spam" "Yes" {
  fileinto "Junk";
  stop;
}
`)

var rspamdFiles = map[string]*template.Template{
	"worker-proxy.inc": template.Must(template.New("proxy").Parse(`# {{.Marker}}. Manual changes are overwritten.
# Postfix talks to Rspamd as a milter on loopback.
bind_socket = "{{.MilterAddr}}";
milter = yes;
timeout = 120s;
upstream "local" {
  default = yes;
  self_scan = yes;
}
`)),
	"redis.conf": template.Must(template.New("redis").Parse(`# {{.Marker}}. Manual changes are overwritten.
# Rspamd's own Redis instance (redis-server@servik-mail), not the one
# customers may use.
servers = "{{.RedisSocket}}";
`)),
	"actions.conf": template.Must(template.New("actions").Parse(`# {{.Marker}}. Manual changes are overwritten.
# Score 6 marks a message as spam (filed into Junk where the domain's spam
# filter is on); 15 rejects it. No greylisting: it delays legitimate mail.
reject = 15;
add_header = 6;
greylist = null;
`)),
	"greylist.conf": template.Must(template.New("greylist").Parse(`# {{.Marker}}. Manual changes are overwritten.
enabled = false;
`)),
	"ratelimit.conf": template.Must(template.New("ratelimit").Parse(`# {{.Marker}}. Manual changes are overwritten.
# Sending limit per mailbox (authenticated senders).
{{- if .HourlyLimit}}
rates {
  servik_mailbox = {
    selector = "user.lower";
    bucket = {
      burst = {{.HourlyLimit}};
      rate = "{{.HourlyLimit}} / 1h";
    }
    message = "Hourly sending limit of this mailbox reached; try again later";
  }
}
{{- else}}
# No limit is set.
{{- end}}
`)),
}

var rspamdUnitDropIn = []byte(`# ` + txn.Marker + `. Manual changes are overwritten.
# Lets Rspamd use the socket of its Redis instance.
[Service]
SupplementaryGroups=redis
`)

var redisTmpl = template.Must(template.New("redis").Parse(`# {{.Marker}}. Manual changes are overwritten.
# Redis for Rspamd (rate limits, statistics), reachable only through its
# Unix socket. Customer Redis is a separate component.
port 0
unixsocket {{.RedisSocket}}
unixsocketperm 660
pidfile /run/redis-servik-mail/redis-server.pid
logfile /var/log/redis/redis-server-servik-mail.log
dir /var/lib/redis
dbfilename servik-mail.rdb
save 900 1
maxmemory 128mb
maxmemory-policy volatile-lru
`))
