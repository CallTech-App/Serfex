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
sudo bash install.sh
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
7. prints a one-time setup link (below).

Everything it prints is also written to `/var/log/servik/install.log`.

### Options

| Option | |
|--------|---|
| `--admin-email E` | Create the administrator now with this email and print its password at the end, instead of the setup link. For automated installs; the setup wizard still opens at the first sign-in. |
| `--admin-name N` | The administrator's name. |
| `--hostname H` | The panel's address. Default: the server's fully qualified host name. |
| `--lang tr\|en` | The panel's default language and the installer's. Default: Turkish when the system `LANG` is Turkish, otherwise English. |
| `--version V` | Install release `V` instead of the newest. |
| `--channel stable\|beta` | `beta` also considers pre-releases (`v0.2.0-beta.1`). |
| `--no-components` | Do not install Nginx, PHP 8.4, Composer, Node.js and Fail2ban for sites now. |
| `--source URL` | Where releases are listed: the GitHub API address of a repository (for forks and mirrors). |
| `--token T` | A GitHub token for a private repository's releases (or `SERVIK_GITHUB_TOKEN`). It is kept in `/etc/servik/release-token`, readable only by root, so the panel's update check can read them too. |
| `-y`, `--yes` | Do not ask before starting. |

### From a private repository

While a repository is private, GitHub serves neither its releases nor the
installer without a token. A fine-grained token with read access to the
repository's contents is enough:

```sh
export SERVIK_GITHUB_TOKEN=github_pat_...
curl -fsSL -H "Authorization: Bearer $SERVIK_GITHUB_TOKEN" -H 'Accept: application/vnd.github.raw' \
  https://api.github.com/repos/Winchester37/Servik/contents/scripts/install.sh | sudo -E bash
```

Once the repository is public, delete the token on GitHub and on the server
(`sudo rm /etc/servik/release-token`).

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

### The setup link

The installer ends with a link like this:

```
https://203.0.113.10:8443/kurulum/Xk9…
```

Open it in a browser (accept the self-signed certificate warning). It asks
for your name, email, a password of at least 12 characters and the panel's
language, creates the first administrator and opens the setup wizard. The
link works once and for 24 hours. Nobody can sign in before it is used, and
only root on the server can make a new one:

```sh
sudo servik setup-url
```

A new link stops the previous one. Once an administrator exists, the command
refuses; a lost password is reset on the server with
`sudo -u servik php8.5 /opt/servik/current/panel/artisan servik:admin`, which
also takes `--email`, `--name` and `--password`. Other administrators are
invited under Settings → Administrators.

Servik does not sign in with the server's root password: PHP never runs as
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
2. **Name servers**: your own domain, `ns1`/`ns2` under it (and a second IP
   address for `ns2` if the server has one), installing the DNS server and
   creating your domain's zone. Register the two names with their addresses as
   glue (host) records at your domain registrar, then make them the domain's
   name servers; the wizard shows the exact names and addresses.
3. **Panel address**: the name you open the panel with (default
   `panel.<your domain>`) and its Let's Encrypt certificate, retried every
   hour until the name points here. The port is changed under Settings →
   General.
4. **Notifications**: where problem emails go. If the server cannot send mail
   yet, the step says so and links to the outgoing mail settings.
5. **Firewall**: turns on Servik's firewall with the ports the installed
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
4. **Add your first site**: Sites → Add site. Servik creates the system user,
   the PHP-FPM pool, the web server configuration, the DNS zone, and a
   certificate as soon as the domain points at the server.
5. Optional: **Servik AI** → Settings, with your Claude API key.

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
