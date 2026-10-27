// Package mail runs Serfex's mail server: Postfix for SMTP, Dovecot for
// IMAP, POP3, LMTP delivery and Sieve, Rspamd for spam filtering, DKIM
// signing and sending limits, and Roundcube for webmail.
//
// Mail domains and mailboxes are virtual: every mailbox belongs to the
// single vmail account, so a mailbox never needs a system user.
//
// Layout on the server (Ubuntu 24.04):
//
//	/etc/serfex/mail/server.json            server settings the agent applied, root 0600
//	/etc/serfex/mail/domains/<domain>.json  each mail domain as the panel sent it, root 0600
//	/etc/serfex/mail/dkim/<domain>.key      DKIM private keys, root:_rspamd 0640
//	/etc/serfex/mail/tls/                   self-signed certificate until a real one is set
//	/etc/postfix/main.cf, master.cf         Postfix configuration (the stock files are kept as *.before-serfex)
//	/etc/postfix/serfex/                    domain, mailbox, alias and sender maps
//	/etc/dovecot/dovecot.conf               Dovecot configuration (the stock file is kept as dovecot.conf.before-serfex)
//	/etc/dovecot/serfex/users               mailboxes with their password hashes, root:dovecot 0640
//	/etc/dovecot/serfex/sieve/              spam filing and autoreply scripts
//	/etc/rspamd/local.d/                    Rspamd settings (Rspamd's own files stay untouched)
//	/etc/redis/redis-serfex-mail.conf       Redis instance for Rspamd only, on a Unix socket
//	/var/vmail/<domain>/<mailbox>/Maildir/  mail, owned by vmail
//
// The panel keeps mail domains in its own database and sends a whole
// domain with mail.domain.apply. The agent stores it, rebuilds every map
// from the stored domains and reloads the services, so running the same
// request twice changes nothing, and a change the services reject is
// rolled back.
package mail

import (
	"errors"
	"fmt"
	"os"
	"sync"

	"github.com/calltech-app/serfex/agent/internal/host"
)

const (
	// VmailUser owns every mailbox.
	VmailUser = "vmail"
	// VmailDir holds the mailboxes, one directory per domain.
	VmailDir = "/var/vmail"

	stateDir    = "/etc/serfex/mail"
	serverState = stateDir + "/server.json"
	domainsDir  = stateDir + "/domains"
	dkimDir     = stateDir + "/dkim"
	tlsDir      = stateDir + "/tls"
	selfCert    = tlsDir + "/fullchain.pem"
	selfKey     = tlsDir + "/privkey.pem"

	postfixMain   = "/etc/postfix/main.cf"
	postfixMaster = "/etc/postfix/master.cf"
	postfixMaps   = "/etc/postfix/serfex"

	dovecotConf    = "/etc/dovecot/dovecot.conf"
	dovecotDir     = "/etc/dovecot/serfex"
	dovecotUsers   = dovecotDir + "/users"
	dovecotMasters = dovecotDir + "/master-users"
	sieveDir       = dovecotDir + "/sieve"
	spamSieve      = sieveDir + "/spam.sieve"

	rspamdLocal  = "/etc/rspamd/local.d"
	rspamdDropIn = "/etc/systemd/system/rspamd.service.d/serfex.conf"
	redisConf    = "/etc/redis/redis-serfex-mail.conf"
	redisUnit    = "redis-server@serfex-mail"
	redisSocket  = "/run/redis-serfex-mail/redis-server.sock"
	milterAddr   = "127.0.0.1:11332"

	// DKIMSelector names the DKIM key in DNS: serfex._domainkey.<domain>.
	DKIMSelector = "serfex"
	// MasterUser is the Dovecot master account webmail signs in with
	// after a one-click login from the panel; it only works from this
	// host.
	MasterUser = "serfex-webmail"
)

// Units are the services of the mail server, in start order.
var Units = []string{redisUnit, "rspamd", "dovecot", "postfix"}

// ValidationError reports a request that can never succeed as given.
type ValidationError struct{ msg string }

func (e *ValidationError) Error() string { return e.msg }

func invalid(format string, a ...any) error { return &ValidationError{fmt.Sprintf(format, a...)} }

// OperationError reports a valid request that failed on the server.
type OperationError struct{ Err error }

func (e *OperationError) Error() string { return e.Err.Error() }
func (e *OperationError) Unwrap() error { return e.Err }

// ErrNotSetUp is returned by domain operations before mail.setup has run.
var ErrNotSetUp = errors.New("the mail server is not set up yet")

// Manager runs the mail server. Operations are serialised.
type Manager struct {
	h       host.Host
	webUser string
	mu      sync.Mutex
}

// NewManager returns a Manager. webUser is the account Nginx runs as; it
// connects to the webmail's PHP-FPM socket.
func NewManager(h host.Host, webUser string) *Manager {
	return &Manager{h: h, webUser: webUser}
}

// opError wraps everything but validation errors as an OperationError.
func opError(err error) error {
	var v *ValidationError
	var o *OperationError
	if err == nil || errors.As(err, &v) || errors.As(err, &o) || errors.Is(err, ErrNotSetUp) {
		return err
	}
	return &OperationError{err}
}

// exists reports whether abs exists on the host.
func (m *Manager) exists(abs string) bool {
	_, err := os.Stat(m.h.Path(abs))
	return err == nil
}

// hasIPv6 reports whether the kernel has IPv6; daemons refuse to start
// when told to listen on :: without it.
func (m *Manager) hasIPv6() bool { return m.exists("/proc/net/if_inet6") }
