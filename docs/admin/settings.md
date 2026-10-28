# Settings

**Settings** is for full administrators. Its left menu:

## General

- **Panel address** and the **panel certificate**: Let's Encrypt for the
  address (renewed on its own), or upload your own.
- **Panel port** (8443): when you change it, `serfex-web` listens on both
  the old and the new port until you confirm from the new one. Without a
  confirmation it goes back after 10 minutes, so a closed firewall cannot lock
  you out. phpMyAdmin and Adminer use the same port.
- **Time zone** (default Europe/Istanbul): set on the server too. Scheduled
  tasks, backups and the update check follow it; dates are stored in UTC.
- **Language**: the default for the panel and notifications. Everyone can
  choose their own under Account details.
- **Session length**: 120 minutes without activity.

## Server

The server's host name (separate from the panel's and the mail server's
addresses), how long logs are kept, whether customers' disk limits are
enforced ([disk quotas](customers.md#disk-quotas)), its IP addresses,
operating system, hardware and uptime.

## Notifications

Who hears about a problem when it starts and when it is over. Each problem is
reported once. Serfex checks every 5 minutes, and you choose which kinds to
hear about:

- SSL certificates that could not be obtained or renewed, or are about to expire;
- backups that failed or did not start, and unreachable destinations;
- a new Serfex release, or a failed update;
- the server's disk 90% full, or a customer over their disk quota;
- a customer over their monthly traffic (their sites keep running);
- a stopped service such as Nginx, PHP-FPM, MySQL or the mail server.

Channels:

- **Email**: recipients (default: the full administrators), through this
  server's mail server or another SMTP server (Google Workspace, Microsoft
  365, Amazon SES…), with the password stored encrypted.
- **Webhook**: a URL that receives a JSON POST signed with
  `X-Serfex-Signature: sha256=<HMAC of the body>`; the signing key is on the
  page. The titles' language can be set separately.
- **Send a test notification**, and a list of recent notifications.

## Administrators

Invite administrators with a link, and choose a role:

| Role | Can |
|------|-----|
| Full administrator | Everything |
| Support | Everything except Settings, Serfex AI settings and updates |
| Read-only | See every page; change nothing. Does not see Serfex AI. |

The last full administrator cannot be demoted or removed. Their two-step
verification can be reset here.

## API keys

Keys for a billing system, scripts or monitoring, with **read** or **write**
privileges, an optional address allowlist and an expiry (one year by
default). A key acts as the full administrator who created it and stops
working if that person is no longer one. The key is shown once; only its hash
is stored. See [API](../api.md).

## Activity log

Every change made in the panel, by whom (people, customers, API keys, the
command line) and from which page. A change can be undone from the log for 10
minutes, by its author or a full administrator. What Serfex AI read and did is
in its own log.

## Updates

See [Updates](updates.md).

## Setup wizard

The steps a new server needs (server name and addresses, name servers, the
panel's address, notifications, firewall, defaults, backups), as after the
installation. Run it again at any time; each step saves through the same
settings as its own page. Overview lists the open steps until the wizard is
finished. See [Installation](../installation.md#the-setup-wizard).

## Account details and Account security (everyone)

Click your name in the menu. **Account details** has your name, email
address and language (and a customer's phone). A new email address asks for
the current password and takes effect once the link mailed to it is opened
(24 hours); the old address stays in use until then. The notification
recipients, the Let's Encrypt contact and a customer's own record move to the
new address with it. When the panel can't send mail at all, the address
changes at once without confirmation, and the dialog says so.

**Account security** has the password change (the other sessions are signed
out and a notice is mailed), two-step verification with recovery codes, open
sessions, and the command line sign-ins of this account (each can be signed
out).
