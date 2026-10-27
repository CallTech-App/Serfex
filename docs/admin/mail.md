# Email

Serfex runs a complete mail server: Postfix for SMTP, Dovecot for IMAP and
POP3, Rspamd for spam filtering and DKIM signing, and Roundcube for webmail.
Mailboxes are virtual (they are not system users) and their passwords are
stored only as hashes.

## Setting it up

1. **Email → Email settings**: enter the mail server's name, for example
   `mail.example.com`. Mail clients and webmail use this one name for every
   domain on the server (like cPanel), and it gets its own Let's Encrypt
   certificate once it resolves to the server.
2. **Install the mail server** (on the Email page). Serfex installs and
   configures Postfix, Dovecot, Rspamd and, if turned on, Roundcube. The stock
   configuration files are kept with a `.before-serfex` suffix.
3. Make sure the server's reverse DNS (PTR) record, set at your hosting
   provider, matches the mail server's name, and that port 25 is open
   outbound. Many providers block it until asked.

Email settings also hold the defaults:

| Setting | Default |
|---------|---------|
| New mailbox quota | 1 GB |
| Hourly sending limit | 500 messages per mailbox. Keeps a mailbox with a stolen password from turning the server into a spam source. 0 is unlimited. |
| Webmail | Roundcube on, opened from the panel with one click |
| New sites | Email turned on for each new site's domain |

## Domains

Email turns on for a site's domain when the site is created (unless that is
off), or with **Turn on email** on the Email page. A domain's page has:

- **Mailboxes**: create with a password you type or one Serfex generates
  (shown once). Each mailbox has a quota, forwarding (with or without
  keeping a copy) and an auto-reply with an optional last day. **Webmail**
  opens the mailbox in Roundcube with a one-time link.
- **Forwarders**: an address that passes mail on to other addresses, on any
  domain, without a mailbox. One forwarder per domain can catch all unknown
  addresses (catch-all).
- **Mail traffic**: sent, received, rejected and bounced messages per day,
  counted from the mail log.
- **Mail settings**:
  - *Spam filter*: mail with an Rspamd score of 6 or more goes to Junk;
    15 or more is rejected. Greylisting is off.
  - *DNS records*: Serfex writes and checks MX, SPF, DKIM, DMARC and the
    autoconfig/autodiscover records that let Thunderbird and Outlook find
    the settings themselves. If the domain's DNS is elsewhere, the page shows
    the records to add there and checks them.
  - *Email client settings*: IMAP 993, POP3 995, SMTP 465 (or 587 with
    STARTTLS), all on the mail server's name.

The records Serfex writes:

| Record | Value |
|--------|-------|
| MX | `10 mail.<domain>` |
| `mail` A/AAAA | the server |
| SPF (TXT) | `v=spf1 a mx ip4:<server> ~all` |
| DKIM (TXT) | selector `serfex`, RSA 2048 |
| DMARC (TXT) | `v=DMARC1; p=none` |

Removed mailboxes and domains keep their mail for 10 minutes so the change
can be undone.

## The mail queue

Live Monitor → **Mail queue** shows Postfix's queue grouped by sender domain.
**Hold** stops a domain's waiting messages from going out (for a site that
sends spam) until you **Release** them; **Retry the queue now** retries
deferred messages.

## Suspended customers

A suspended customer's domains defer incoming mail with a temporary error
(so senders retry later) and their mailboxes cannot sign in. Nothing is
deleted.

## Not in 0.1

Backing up mailboxes on their own (they are in site and server backups),
CalDAV and CardDAV, outgoing relays (smarthosts) for customer mail. The
panel's own notification mail can go through another SMTP server: Settings →
Notifications.
