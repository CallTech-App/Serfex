# Installation and first steps

## Before you start

- A **fresh** server with Ubuntu Server 24.04 LTS or newer, amd64 or arm64.
  Servik configures Nginx, PHP-FPM, PowerDNS, Postfix and the rest itself and
  does not adopt configuration it did not write, so do not install it next to
  another control panel or on a server that already hosts sites.
- Root access over SSH.
- A public IPv4 address, and a host name for the server (for example
  `server.example.com`) with an A record pointing at it. Certificates for the
  panel and the mail server need that name to resolve.
- If you want Servik to answer DNS for your domains, two name server names
  (for example `ns1.example.com` and `ns2.example.com`) registered at your
  domain registrar as glue records pointing at the server.

Memory: 2 GB is enough for a small server with mail and a database server;
plan 4 GB or more for Laravel applications with queues and front-end builds.

## Install

```sh
curl -fsSL https://github.com/Winchester37/Servik/releases/latest/download/install.sh -o install.sh
sudo bash install.sh --admin-email you@example.com
```

The installer shows what it will do and asks before it starts. It:

1. checks the operating system and architecture;
2. downloads the newest release and checks it against `SHA256SUMS`;
3. installs the panel's own PHP 8.5 (from the Ondřej Surý archive), its own
   PostgreSQL cluster `servik` on port 5433 and its own web server
   `servik-web`, apart from anything customer sites will use;
4. installs `servik-agent` as a systemd service and the `servik` command line;
5. starts the panel on `https://<host name>:8443` with a self-signed certificate;
6. installs the defaults for customer sites: Nginx, PHP 8.4, Composer,
   Node.js 24 and Fail2ban (skip with `--no-components`);
7. creates the administrator and prints the password.

Everything it prints is also written to `/var/log/servik/install.log`.

### Options

| Option | |
|--------|---|
| `--admin-email E` | Create the administrator with this email; the password is printed at the end. Without it, create one later with `servik:admin` (below). |
| `--admin-name N` | The administrator's name. |
| `--hostname H` | The panel's address. Default: the server's fully qualified host name. |
| `--lang tr\|en` | The panel's default language and the installer's. Default: Turkish when the system `LANG` is Turkish, otherwise English. |
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

Servik has its own firewall as well (Security → Firewall). It is off until you
turn it on, and it opens what the installed services need.

### Without `--admin-email`

```sh
sudo -u servik php8.5 /opt/servik/current/panel/artisan servik:admin
```

It asks for the email, the name and a password of at least 12 characters
(or takes `--email`, `--name` and `--password`). Running it again resets the
first administrator's email, name and password, which is the way back in when
that password is lost. Other administrators are managed under Settings →
Administrators.

## First steps in the panel

Sign in at `https://<host name>:8443`. Your browser warns about the
self-signed certificate the first time.

1. **Change the password and turn on two-factor sign-in**: your name at the
   top right → Account security.
2. **Give the panel its own certificate**: Settings → General → Panel
   address. Once the host name resolves to the server, Servik gets a Let's
   Encrypt certificate for it and renews it.
3. **Check the server settings**: Settings → General (language, time zone,
   session length) and Settings → Server (the server's host name).
4. **Install the DNS server** if Servik should answer for your domains:
   Domains & DNS → Install the DNS server. Then enter your name servers under
   Domains & DNS → Server DNS settings.
5. **Install the mail server** if you will host email: Email → Email settings,
   enter the mail server's name (for example `mail.example.com`), then
   Install the mail server.
6. **Install a database server**: Databases → Database servers. MySQL is the
   default; MariaDB replaces it, PostgreSQL runs alongside.
7. **Add your first site**: Sites → Add site. Servik creates the system user,
   the PHP-FPM pool, the web server configuration, the DNS zone, and a
   certificate as soon as the domain points at the server.
8. **Set a backup destination**: Backups → Destinations. Backups run daily at
   03:00 to local disk until you add S3-compatible storage or SFTP.
9. **Tell Servik who to notify**: Settings → Notifications (email and webhook).
10. Optional: **Servik AI** → Settings, with your Claude API key.

The [administrator guide](README.md#administrator-guide) covers each area.

## The command line on the server

The installer puts `servik` in `/usr/local/bin`. Sign root in without a
browser:

```sh
sudo servik login --local
servik sites list
```

See the [command line reference](cli.md).

## Where things are

| Path | |
|------|---|
| `/opt/servik/releases/<version>/` | Installed releases: `panel/`, `bin/servik-agent`, `bin/servik` |
| `/opt/servik/current` | Link to the running release |
| `/etc/servik/panel.env` | The panel's settings (`APP_KEY`, database password). Readable by root and the `servik` group only. |
| `/etc/servik/` | The agent's state: DNS, mail, firewall, cron, limits |
| `/var/lib/servik/panel/storage` | The panel's storage and logs |
| `/var/lib/servik/backups/` | Local backups and the import folder |
| `/var/log/servik/` | Install and update logs, sites' web server logs |
| `/home/<user>/web/<domain>/` | A site's files |

Services: `servik-agent`, `servik-web`, `servik-php`, `postgresql@16-servik`
(the panel's database), `servik-panel-queue`, `servik-panel-backups` and
`servik-panel-scheduler`. The Services page lists them apart from the
customers' services and does not let you stop them by mistake.

## Updating

Settings → Updates checks for a new release every day at 04:30 and updates
when you press **Update**: the panel backs itself up, installs the new release
next to the old one, migrates, and checks its health. If the check fails,
the old release and its database come back on their own. See
[Updates](admin/updates.md).

## Troubleshooting

- The installer stops with a message; the whole output is in
  `/var/log/servik/install.log`. It refuses to run on a server where Servik is
  already installed: update from the panel instead.
- The panel does not open: `systemctl status servik-web servik-php`,
  `/var/log/servik/web/` and `journalctl -u servik-php`.
- The panel says the agent cannot be reached: `systemctl status servik-agent`
  and `journalctl -u servik-agent`.
