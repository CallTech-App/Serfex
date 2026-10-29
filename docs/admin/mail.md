# Email

Serfex runs a complete mail server: Postfix for SMTP, Dovecot for IMAP and
POP3, Rspamd for spam filtering and DKIM signing, and Roundcube or SnappyMail
for webmail.
Mailboxes are virtual (they are not system users) and their passwords are
stored only as hashes.

## Setting it up

1. **Email → Email settings**: enter the mail server's name, for example
   `mail.example.com`. Mail clients and webmail use this one name for every
   domain on the server (like cPanel), and it gets its own Let's Encrypt
   certificate once it resolves to the server.
2. **Install the mail server** (on the Email page). Serfex installs and
   configures Postfix, Dovecot, Rspamd and, if turned on, the webmail. The stock
   configuration files are kept with a `.before-serfex` suffix.
3. Make sure the server's reverse DNS (PTR) record, set at your hosting
   provider, matches the mail server's name, and that port 25 is open
   outbound. Many providers block it until asked. Without it Gmail and
   Outlook refuse the mail (`550 5.7.25` in `/var/log/mail.log`); Email
   settings and Notifications warn when the record is missing or doesn't
   point back at the server.

Email settings also hold the defaults:

| Setting | Default |
|---------|---------|
| New mailbox quota | 1 GB |
| Hourly sending limit | 500 messages per mailbox. Keeps a mailbox with a stolen password from turning the server into a spam source. 0 is unlimited. |
| Webmail | Roundcube, opened from the panel with one click. SnappyMail (lighter, with a modern look) or Off can be chosen instead. |
| New sites | Email turned on for each new site's domain |

### Webmail

Webmail answers at `https://<mail server name>/`. **Roundcube** (Ubuntu's
package) is the default; **SnappyMail** can be chosen under Email settings
or under Software → Other. Only one of them is served at a time, at the
same address, and switching does not carry contacts or settings across:
each application keeps its own on the server, so they are there again when
you switch back.

SnappyMail has no Ubuntu package. Serfex downloads the release it was
tested with from GitHub, checks its SHA-256 checksum and keeps it in
`/opt/serfex/snappymail/<version>`, with the data (settings, contacts) in
`/var/lib/serfex/webmail/snappymail`. Its admin panel is off; Serfex writes
its settings. A Serfex update can bring a newer SnappyMail: Software shows
**Update** on its row (after the daily check or **Check for updates**).
Both applications run on the newest PHP up to 8.4 on the server, in their
own PHP-FPM pool.

## Domains

Email turns on for a site's domain when the site is created (unless that is
off), or with **Turn on email** on the Email page. A domain's page has:

- **Mailboxes**: create with a password you type or one Serfex generates
  (shown once). Each mailbox has a quota, forwarding (with or without
  keeping a copy) and an auto-reply with an optional last day. **Webmail**
  opens the mailbox in webmail with a one-time link.
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

## Mail from sites

Sites send only with a mailbox login on port 587, like any mail client, and
a mailbox sends only as its own address. `sendmail` and PHP's `mail()` are
refused to sites' system users, and SMTP from the server without a login is
relayed nowhere: mail nobody logged in for is what a hacked site sends, and it
lands the server's address on spam lists. Root, the panel's own mail and
webmail are not affected.

So an application needs a mailbox: open one (for example `noreply@<domain>`)
and enter its SMTP settings in the application. For Laravel, Environment →
**Connect a mailbox** writes them into the `.env`; for WordPress, an SMTP
plugin. Refused sends show in Laravel's logs with what they mean.

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
