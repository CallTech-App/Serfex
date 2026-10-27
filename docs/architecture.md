# Architecture

This is how Serfex is built, as of 0.1. For running it from a checkout see
[Development](development.md).

```
 browser ──https:8443──▶ serfex-web (Nginx) ──▶ serfex-php (PHP-FPM 8.5, user serfex)
 serfex CLI ──/api/v1──▶        │                     │  Laravel 13 + Livewire 4 panel
 Git webhooks ─────────▶        │                     ├─▶ PostgreSQL cluster "serfex" :5433
                                │                     └─▶ queue workers + scheduler (systemd)
                                │                              │
                                │      JSON-RPC 2.0 over /run/serfex-agent/agent.sock
                                │                              ▼
                                │                      serfex-agent (Go, root)
                                │                              │
 visitors ──80/443──▶ Nginx (+ Apache) ─▶ PHP-FPM pool per site, as the site's user
 mail ──25/465/587/993──▶ Postfix, Dovecot, Rspamd       PowerDNS ◀── 53
```

## Three programs

| | Language | Runs as | Role |
|-|----------|---------|------|
| `panel/` | PHP 8.5, Laravel 13, Livewire 4 | `serfex` | The UI, the API, the database of record, every decision |
| `agent/` | Go, one static binary (its only dependency is `golang.org/x/crypto` for ACME) | `root` | Every change to the server |
| `cli/` | Go, standard library only | anyone | A client of `/api/v1` |

### The rule that shapes everything

**The panel never runs as root, and the agent never decides.** The panel
holds the state (sites, zones, mailboxes, plans) in PostgreSQL and asks the
agent to make the server match it. The agent offers narrow, validated
methods (`site.apply`, `dns.zone.apply`, `db.user.apply`…), never "run this
command", so a compromised panel can only do what those methods allow.

The agent listens only on a Unix socket, `/run/serfex-agent/agent.sock`, mode
0660, group `serfex` (`-socket-group`). Each request and response is one line
of JSON-RPC 2.0. Errors use JSON-RPC codes plus Serfex's own:

| Code | |
|------|---|
| -32602 | The request can never work as sent (bad domain, reserved name, missing PHP version) |
| -32000 | A valid change failed on the server; the message says why |
| -32001 / -32002 / -32003 | The DNS server / mail server / database server is not set up |
| -32004 / -32005 | A component is in use / another component operation is running |
| -32006 / -32007 | A backup / an update is running |
| -32008 | A file manager operation was refused |
| -32009 | ModSecurity is not installed |
| -32010 | The web server refused the configuration (output included) |

Messages are translated: the agent reads the request's language and answers
in Turkish or English.

### How the agent changes the server

- **Transactions.** Changes are written to temporary files, tested
  (`nginx -t`, `php-fpm -t`, `postfix check`, `apachectl configtest`…) and
  then put in place; if a test or a reload fails, every file goes back.
  Applying the same request twice changes nothing.
- **Ownership marks.** Files it writes start with `Managed by Serfex`; system
  accounts it creates carry the comment `Serfex site user`. It never
  overwrites or deletes a file or account without the mark. Stock
  configuration it takes over is kept with a `.before-serfex` suffix.
- **Acting as the customer.** Anything inside a customer's home (creating
  sites, the file manager, archives for backups, Laravel builds, cron jobs)
  runs as that customer through `runuser`, so a symlink planted in a home
  folder cannot turn a root write against the system.
- **State** lives in `/etc/serfex/` (DNS, mail, firewall, cron, limits, FTP)
  and `/var/lib/serfex/` (backups, components, updates, statistics).
- **Dry run.** `serfex-agent -dry-run -root DIR` keeps every change under
  `DIR` and only logs the commands it would run. Development and the panel's
  screenshots use it.

### Agent methods by area

`agent/README.md` documents the parameters of the core methods.

| Area | Methods |
|------|---------|
| System | `system.ping`, `agent.info`, `system.info`, `system.addresses`, `system.hostname.set`, `system.timezone.set`, `system.timezones`, `usage.disk` |
| Sites | `site.apply`, `site.delete`, `site.logs`, `web.protect.apply`, `web.deny.apply` |
| PHP | `php.versions`, `php.info`, `php.settings.apply`, `php.extension.set`, `php.restart` |
| Files and SFTP | `files.*` (list, read, save, upload, move, copy, delete, chmod, compress, extract, search, trash…), `ftp.status`, `ftp.account.apply`, `ftp.account.delete`, `ftp.account.list` |
| Scheduled tasks | `cron.apply`, `cron.remove`, `cron.run`, `cron.runs` |
| DNS | `dns.status`, `dns.setup`, `dns.zone.apply`, `dns.zone.get`, `dns.zone.delete` |
| Certificates | `ssl.issue`, `ssl.install`, `ssl.get`, `ssl.delete`, `ssl.panel.issue`, `ssl.panel.install`, `ssl.panel.get` |
| Mail | `mail.status`, `mail.setup`, `mail.domain.apply`, `mail.domain.delete`, `mail.mailbox.purge`, `mail.usage`, `mail.webmail.login`, `mail.queue`, `mail.queue.flush`, `mail.queue.hold`, `mail.queue.release` |
| Databases | `db.status`, `db.server.install`, `db.manager.setup`, `db.manager.login`, `db.database.create`, `db.database.delete`, `db.user.apply`, `db.user.delete`, `db.list` |
| Laravel | `laravel.tools`, `laravel.deploy`, `laravel.rollback`, `laravel.run`, `laravel.run.read`, `laravel.status`, `laravel.env.get`, `laravel.env.set`, `laravel.queue.apply`, `laravel.queue.restart`, `laravel.schedule.apply`, `laravel.process.apply`, `laravel.process.restart`, `laravel.process.log`, `laravel.maintenance`, `laravel.logs`, `laravel.probe`, `laravel.scan`, `laravel.key`, `laravel.health`, `laravel.tinker.start`, `laravel.tinker.send`, `laravel.tinker.status`, `laravel.tinker.stop`, `laravel.backup.create`, `laravel.backup.read`, `laravel.backup.restore`, `laravel.backup.delete`, `laravel.remove`, `redis.apply`, `redis.remove` |
| Git deploys | `site.git.deploy`, `site.git.key`, `site.git.read`, `site.git.remove` |
| Monitoring and statistics | `monitor.live`, `monitor.limit.apply`, `monitor.limit.remove`, `monitor.limits`, `stats.collect` |
| Security | `firewall.block`, `firewall.unblock`, `firewall.list`, `firewall.rules`, `firewall.rules.apply`, `waf.site.apply`, `waf.events`, `scan.run`, `scan.files`, `scan.fix`, `fail2ban.status`, `fail2ban.refresh`, `fail2ban.ban`, `fail2ban.unban`, `ssh.status`, `ssh.apply` |
| Services | `service.list`, `service.action`, `service.logs`, `service.settings`, `service.settings.apply` |
| Software | `component.list`, `component.check`, `component.install`, `component.update`, `component.remove`, `component.configure`, `component.extension`, `component.run.read` |
| Backups | `backup.create`, `backup.restore`, `backup.status`, `backup.read`, `backup.run.read`, `backup.delete`, `backup.fetch`, `backup.import`, `backup.scan`, `backup.inspect`, `backup.member.read`, `backup.upload.write`, `backup.target.test` |
| Panel and updates | `panel.setup`, `panel.web.status`, `panel.web.ports`, `update.status`, `update.check`, `update.start`, `update.rollback`, `update.run.read` |

## The panel

- **Livewire pages** under `app/Livewire/`, one per area, following the
  approved mockups in `design/` (rules in `design/mockup/kaynak/src/GUIDE.md`):
  a list first, then a detail page with its tools.
- **Services** under `app/Services/` hold the logic (`Sites`, `Dns\Zones`,
  `Mail\MailDomains`, `Laravel\LaravelApps`, `Backups\Backups`…). Livewire,
  the API, Serfex AI and the console commands all go through them;
  `Agent\AgentClient` is the only way to the agent.
- **Jobs** on the database queue do anything slow (provisioning, certificates,
  deployments, component installs). Backups and restores have their own queue
  (`backups`) so a long backup never holds up site work.
- **Scheduled commands** (`routes/console.php`), every minute unless noted:
  `serfex:monitor-record`, `serfex:stats-collect`, `serfex:services-check`,
  `serfex:fail2ban-sync`, `serfex:settings-check`; every 5 minutes
  `serfex:backups`, `serfex:laravel-backups`, `serfex:notify`,
  `serfex:cron-history`; every 30 minutes `serfex:disk-usage`,
  `serfex:mail-usage`; hourly `serfex:ssl-renew`, `serfex:stats-rollup`;
  daily `serfex:files-trash` (03:40), `serfex:stats-prune` (04:10),
  `serfex:components-check` and `serfex:update-check` (04:30); weekly
  `serfex:security-scan` (Sunday 03:20).
- **Access control** in layers: middleware (`EnsureHostingAccess`,
  `EnsureAdmin`, `EnsureFullAdmin`, `RefuseReadOnlyChanges`), the `Access`
  checks on every Livewire mount and action, and `visibleTo()` scopes on the
  models so a customer's queries only ever see their own rows. Read-only
  administrators are also stopped by a database write guard.
- **Audit**: every change becomes an activity log entry, most with a
  10-minute undo.
- **Languages**: the Turkish text is the key (`__('Siteler')`), English is in
  `lang/en.json`; `serfex:lang-check` fails CI on missing, unused or
  untranslated text.

### Who signs in

| | Sees |
|-|------|
| Administrators (full, support, read-only) | Everything, within the role |
| Customers | Their own hosting, within their plan's features |
| Laravel team members | Only the applications they were added to, within their role |

## Isolation

The panel's stack is separate from the customers':

| | Panel | Customer sites |
|-|-------|----------------|
| Web server | `serfex-web` (own Nginx config in `/etc/serfex/web`), port 8443 | `nginx` (and `apache2` on 127.0.0.1:7080), ports 80/443 |
| PHP | `serfex-php`, PHP 8.5, user `serfex` | one PHP-FPM pool per site, per version, as the site's user |
| Database | PostgreSQL cluster `serfex` on 5433 | MySQL or MariaDB on 3306, PostgreSQL on 5432 |
| Cache, queue, sessions | the panel's PostgreSQL | per-application Redis instances |

phpMyAdmin and Adminer are served by `serfex-web` from their own pool in
`serfex-php` (`dbadmin`), apart from the panel's pool (`panel`).

## Optional components

Only what every server needs is installed by `install.sh` (the panel, the
agent, Nginx, PHP 8.4, Composer, Node.js 24, Fail2ban). Everything else is
installed from Software, Databases, Email or Domains & DNS when it is
needed, through `component.install` and the `*.setup` methods: Apache, PHP
8.1–8.5, MySQL or MariaDB, PostgreSQL, phpMyAdmin and Adminer, PowerDNS,
Postfix, Dovecot, Rspamd and Roundcube, Redis, Memcached, Supervisor, Node.js
22/24/26, Python, ModSecurity with the OWASP CRS, ClamAV. Each install is a
logged, stepwise run that rolls back on failure; a component that is in use
cannot be removed.

## Releases and updates

A `v*` tag builds a GitHub Release with `scripts/build-release.sh`:

| Asset | |
|-------|---|
| `serfex-<version>-linux-{amd64,arm64}.tar.gz` | `VERSION`, `bin/serfex-agent`, `bin/serfex`, `panel/` (with `vendor/` and built assets), `downloads/` (the CLI builds the panel serves) |
| `serfex-cli-{linux,darwin}-{amd64,arm64}` | The command line |
| `install.sh`, `install-cli.sh` | The installers |
| `SHA256SUMS` | Checksums of all of the above |

On a server:

```
/opt/serfex/releases/<version>/   one directory per release
/opt/serfex/current -> releases/<version>
/etc/serfex/panel.env             linked as panel/.env (root:serfex 0640)
/etc/serfex/panel.json            PHP version, release source, host name
/var/lib/serfex/panel/storage     linked as panel/storage
/usr/local/bin/serfex -> current/bin/serfex
```

An update (`update.start`) runs in its own transient systemd unit, so it
survives the panel and the agent restarting: download, verify, unpack, put
the panel in maintenance, snapshot the panel database, migrate, switch
`current`, restart, health check, and on failure restore the snapshot and
switch back. See [Updates](admin/updates.md).

## Tests

- Go unit tests for the agent and the CLI, with the dry-run host.
- PHP feature tests for the panel against PostgreSQL, with a fake agent and a
  fake Claude API.
- **Real-server jobs** in CI, one or more per area, run the agent as root on a
  fresh Ubuntu runner against real Nginx, PHP-FPM, PowerDNS, Postfix,
  MySQL, Let's Encrypt's test server (Pebble), S3 and SFTP, and the panel and
  CLI end to end. They change the machine, so run them only on throwaway
  servers (`-tags realhost`).
