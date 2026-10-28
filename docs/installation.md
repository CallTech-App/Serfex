# Installation and first steps

## Before you start

- A **fresh** server with Ubuntu Server 24.04 LTS or newer, amd64 or arm64.
  Serfex configures Nginx, PHP-FPM, PowerDNS, Postfix and the rest itself and
  does not adopt configuration it did not write, so do not install it next to
  another control panel or on a server that already hosts sites.
- Root access over SSH.
- A public IPv4 address, and a host name for the server (for example
  `server.example.com`) with an A record pointing at it. Certificates for the
  panel and the mail server need that name to resolve.
- If you want Serfex to answer DNS for your domains, two name server names
  (for example `ns1.example.com` and `ns2.example.com`) registered at your
  domain registrar as glue records pointing at the server.

Memory: 2 GB is enough for a small server with mail and a database server;
plan 4 GB or more for Laravel applications with queues and front-end builds.

## Install

```sh
curl -fsSL https://github.com/CallTech-App/Serfex/releases/latest/download/install.sh -o install.sh
sudo bash install.sh
```

The installer shows what it will do and asks before it starts. It:

1. checks the operating system and architecture;
2. downloads the newest release and checks it against `SHA256SUMS`;
3. installs the panel's own PHP 8.5 (from the Ondřej Surý archive), its own
   PostgreSQL cluster `serfex` on port 5433 (the newest stable PostgreSQL
   from apt.postgresql.org, Ubuntu's own if that archive cannot be reached)
   and its own web server
   `serfex-web`, apart from anything customer sites will use;
4. installs `serfex-agent` as a systemd service and the `serfex` command line;
5. starts the panel on `https://<host name>:8443` with a self-signed certificate;
6. installs the defaults for customer sites: Nginx, PHP 8.4, Composer,
   Node.js 24 and Fail2ban (skip with `--no-components`);
7. prints a one-time setup link (below).

Everything it prints is also written to `/var/log/serfex/install.log`.

### Options

| Option | |
|--------|---|
| `--admin-email E` | Create the administrator now with this email and print its password at the end, instead of the setup link. For automated installs; the setup wizard still opens at the first sign-in. |
| `--admin-name N` | The administrator's name. |
| `--hostname H` | The panel's address. Default: the server's fully qualified host name. |
| `--lang tr\|en` | The panel's default language and the installer's. Default: English. The setup page can still switch the panel to Turkish. |
| `--version V` | Install release `V` instead of the newest. |
| `--channel stable\|beta` | `beta` also considers pre-releases (`v0.2.0-beta.1`). |
| `--no-components` | Do not install Nginx, PHP 8.4, Composer, Node.js and Fail2ban for sites now. |
| `--source URL` | Where releases are listed: the GitHub API address of a repository (for forks and mirrors). |
| `-y`, `--yes` | Do not ask before starting. |

### Ports

Open these in your provider's firewall:

| Port | For |
|------|-----|
| 22 | SSH and SFTP accounts |
| 80, 443 | Sites, and Let's Encrypt's HTTP challenge |
| 8443 | The panel, phpMyAdmin and Adminer |
| 53 (TCP and UDP) | DNS, once you install the DNS server |
| 25, 465, 587, 110, 995, 143, 993 | Mail (SMTP, POP3, IMAP), once you install the mail server |

Serfex has its own firewall as well (Security → Firewall). It is off until you
turn it on, and it opens what the installed services need.

### The setup link

The installer ends with a link like this:

```
https://203.0.113.10:8443/install/Xk9…
```

Open it in a browser (accept the self-signed certificate warning). It asks
for the panel's language (English unless you pick Türkçe; the page switches
as soon as you pick), your name, email and a password of at least 12
characters, creates the first administrator and opens the setup wizard. The
link works once and for 24 hours. Nobody can sign in before it is used, and
only root on the server can make a new one:

```sh
sudo serfex setup-url
```

A new link stops the previous one. Once an administrator exists, the command
refuses. Other administrators are invited under Settings → Administrators.

### Forgotten passwords

**Forgot password?** on the sign-in page mails a one-time link to the
account's address (administrators, customers and team members alike). The
mail goes out the way the panel's notifications do (Settings →
Notifications: this server's mail server or your SMTP server), in the
person's language. The link works once and for 60 minutes, only the newest
one works, and one address gets at most one link a minute and five an hour.
The page answers the same whether or not the address has an account.
Setting the new password signs the account out everywhere and mails a
notice; two-step verification, when it is on, is still asked at the next
sign-in. Requests and resets are written to the audit log.

When the panel cannot send mail, the page says so and points to the server:

```sh
sudo serfex reset-password ayse@example.com
```

prints the same kind of link for any account, without sending mail.

Serfex does not sign in with the server's root password: PHP never runs as
root, the root password never crosses the browser, and the setup link works
on servers that only allow SSH keys.

## The setup wizard

The wizard opens after the administrator is created, and at the first
sign-in of a full administrator when the account was made another way. Each
step saves with the same settings as its own page and can be left for later
("Later"):

1. **Server**: the server's fully qualified host name (also set it as the
   PTR record of the IP address at your provider, for mail), the IPv4 and
   IPv6 addresses DNS records use, the time zone and the server's language.
2. **Name servers**: your own domain, `ns1`/`ns2` under it, each with its
   IP address (empty means the server's; enter another one when a name
   server runs on a second server or address), installing the DNS server and
   creating your domain's zone. Register the two names with their addresses as
   glue (host) records at your domain registrar, then make them the domain's
   name servers; the wizard shows the exact names and addresses.
3. **Panel address**: open the panel on the server's own name (the
   default), on a separate name such as `admin.example.com`, or only on its
   IP address and port. A name gets a Let's Encrypt certificate, retried
   every hour until the name points here; it cannot be a site's or the mail
   server's name. The IP address always works. The port is changed under
   Settings → General.
4. **Notifications**: where problem emails go. If the server cannot send mail
   yet, the step says so and links to the outgoing mail settings.
5. **Firewall**: turns on Serfex's firewall with the ports the installed
   services need (the panel, web, SSH on its real port, and DNS and mail once
   installed). Your own connection to the panel is never cut.
6. **Defaults**: the web server and PHP version new sites get, and the
   update channel (stable or beta). Updates are never installed on their own.
7. **Backups**: the daily server backup and its time, with a reminder to add
   a remote destination (S3 or SFTP).

The summary shows what is done and what is left, with the glue and PTR
reminders. Until you press **Finish setup**, Overview shows the same list at
the top. The wizard can be run again at any time from Settings → Setup wizard.

## After the wizard

1. **Turn on two-factor sign-in**: your name at the top right → Account
   security.
2. **Install the mail server** if you will host email: Email → Email settings,
   enter the mail server's name (for example `mail.example.com`), then
   Install the mail server.
3. **Install a database server**: Databases → Database servers. MySQL is the
   default; MariaDB replaces it, PostgreSQL runs alongside.
4. **Add your first site**: Sites → Add site. Serfex creates the system user,
   the PHP-FPM pool, the web server configuration, the DNS zone, and a
   certificate as soon as the domain points at the server.
5. Optional: **Serfex AI** → Settings, with your Claude API key.

The [administrator guide](README.md#administrator-guide) covers each area.

## The command line on the server

The installer puts `serfex` in `/usr/local/bin`. Sign root in without a
browser:

```sh
sudo serfex login --local
serfex sites list
```

See the [command line reference](cli.md).

## Where things are

| Path | |
|------|---|
| `/opt/serfex/releases/<version>/` | Installed releases: `panel/`, `bin/serfex-agent`, `bin/serfex` |
| `/opt/serfex/current` | Link to the running release |
| `/etc/serfex/panel.env` | The panel's settings (`APP_KEY`, database password). Readable by root and the `serfex` group only. |
| `/etc/serfex/` | The agent's state: DNS, mail, firewall, cron, limits |
| `/var/lib/serfex/panel/storage` | The panel's storage and logs |
| `/var/lib/serfex/backups/` | Local backups and the import folder |
| `/var/log/serfex/` | Install and update logs, sites' web server logs |
| `/home/<user>/web/<domain>/` | A site's files |

Services: `serfex-agent`, `serfex-web`, `serfex-php`, `postgresql@18-serfex`
(the panel's database; the number is its PostgreSQL version), `serfex-panel-queue`, `serfex-panel-backups` and
`serfex-panel-scheduler`. The Services page lists them apart from the
customers' services and does not let you stop them by mistake.

## Updating

Settings → Updates checks for a new release every day at 04:30 and updates
when you press **Update**: the panel backs itself up, installs the new release
next to the old one, migrates, and checks its health. If the check fails,
the old release and its database come back on their own. See
[Updates](admin/updates.md).

## Troubleshooting

- The installer stops with a message; the whole output is in
  `/var/log/serfex/install.log`. It refuses to run on a server where Serfex is
  already installed: update from the panel instead.
- The panel does not open: `systemctl status serfex-web serfex-php`,
  `/var/log/serfex/web/` and `journalctl -u serfex-php`.
- The panel says the agent cannot be reached: `systemctl status serfex-agent`
  and `journalctl -u serfex-agent`.
