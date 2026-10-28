# serfex-agent

The privileged half of Serfex. It runs as root under systemd and serves
JSON-RPC 2.0 on a Unix socket (default `/run/serfex-agent/agent.sock`, mode
`0660`). Each request and response is one line of JSON.

```sh
go test ./...
CGO_ENABLED=0 go build -ldflags "-X github.com/calltech-app/serfex/agent/internal/version.Version=dev" \
  -o bin/serfex-agent ./cmd/serfex-agent
```

The core methods are below; [docs/architecture.md](../docs/architecture.md)
lists every method by area.

| Method | Params | Result |
|--------|--------|--------|
| `system.ping` | | `"pong"` |
| `agent.info` | | version, Go version, OS, architecture, `dry_run` |
| `php.versions` | | PHP versions with PHP-FPM installed, e.g. `["8.3","8.4"]` |
| `site.apply` | `domain`, `user`, `php_version`, `aliases`, `suspended` | creates or updates a site; a suspended site answers every request with 503 and a suspension page while its files stay. Returns paths, `uid`, `user_created`, `changed` |
| `site.delete` | `domain`, `user`, `remove_files`, `remove_user` | removes the site; returns `files_removed`, `user_removed` |
| `site.logs` | `domain`, `user`, `kind` (`access`, `error` or `php`), `bytes` | the end of the site's Nginx access or error log or its PHP error log (at most 1 MB); returns `exists`, `size`, `content`, `truncated`. Symbolic links in the customer's log path are refused |
| `usage.disk` | `users` | disk space used by each account's home directory, `{"<user>": {"bytes", "files", "sites": {"<domain>": bytes}}}` (`sites`: the part in each site's folder `web/<domain>`); hard links count once, symbolic links are not followed |
| `system.addresses` | | the server's public addresses, `{"ipv4": [...], "ipv6": [...]}` |
| `dns.status` | | `installed`, `configured`, `running`, `version` of the DNS server |
| `dns.setup` | | installs PowerDNS if missing, configures and starts it; returns `installed`, `changed`, `version` |
| `dns.zone.apply` | `zone`, `soa`, `records` | makes the zone serve exactly these records; returns `serial`, `created`, `changed` |
| `dns.zone.get` | `zone` | the records being served, with `serial` and `soa` |
| `dns.zone.delete` | `zone` | removes the zone; returns `deleted` |
| `ssl.issue` | `name`, `names`, `challenge`, `dns_zone`, `email` | obtains a certificate from Let's Encrypt and stores it as `name`; returns names, issuer, dates, `changed`, `reloaded` |
| `ssl.install` | `name`, `certificate`, `private_key`, `chain`, `domain` | stores an uploaded certificate after checking it |
| `ssl.get` | `name` | the stored certificate, or `null` |
| `ssl.delete` | `name` | removes a certificate no enabled site uses; returns `deleted` |
| `ssl.panel.issue` | `hostname`, `email` | obtains the certificate of the panel's own address |
| `ssl.panel.get` | | the panel's certificate, or `null` |
| `mail.status` | | `installed`, `configured`, `running`, per-service `services`, `hostname`, `webmail_url` |
| `mail.setup` | `hostname`, `certificate`, `hourly_limit`, `webmail` | installs the mail server if missing, configures and (re)starts it; returns `installed`, `changed`, `hostname`, `webmail_url` |
| `mail.domain.apply` | `domain`, `spam_filter`, `mailboxes`, `aliases`, `catch_all`, `suspended`, `disk_full` | serves exactly these mailboxes and forwarders (a suspended domain defers incoming mail and refuses logins; `disk_full` defers incoming mail with `452 4.2.2 Mailbox full` while logins keep working); returns `changed` and the domain's `dkim` record |
| `mail.domain.delete` | `domain`, `remove_files` | stops serving the domain's mail; returns `deleted`, `files_removed` |
| `mail.mailbox.purge` | `domain`, `name` | deletes the stored mail of a removed mailbox |
| `mail.usage` | `domain` | bytes stored per mailbox |
| `mail.webmail.login` | `address` | a one-time `url` (valid one minute) that opens the mailbox in webmail |
| `laravel.tools` | `php_version` | installs Composer and Node.js if missing; returns their versions |
| `laravel.deploy` | target, `run`, `release`, `source`, `build`, `hooks`, `env`, `health`, `rollback`, `keep`, `seed_from` | builds a release, switches `current` to it, checks health and rolls back on failure; returns `release`, `commit`, `laravel`, `health`, `rolled_back`, `problem` |
| `laravel.rollback` | target, `run`, `release` | switches `current` back to an earlier release |
| `laravel.run` | app, `run`, `tool`, `args` | runs Artisan, Composer, npm, pnpm or bun as the site's user; with `run` it writes a log instead of returning the output |
| `laravel.package.install` | app, `run`, `url`, `headers`, `sha256`, `env`, `files`, `version_file`, `replace` | downloads a release zip (HTTPS, at most 512 MB), refuses unsafe entries, unpacks it as the site's user into the app's empty directory and writes `.env` and the given files (0600); returns `version`, `sha256`, `bytes`, `files` |
| `laravel.setup` | app, `run`, `commands`, `redact` | runs the template's setup commands (Artisan, Composer, npm…) one after the other into the same log, hiding the `redact` values; returns `failed` (-1 when all succeeded) and `exit_code` |
| `laravel.version` | app, `file` | the version the application keeps in a file (default `VERSION`) |
| `laravel.run.read` | `domain`, `run`, `offset` | the next part of a run's log: `data`, `offset`, `size`, `done` |
| `laravel.status` | app | `exists`, `current`, `releases`, `maintenance`, `workers`, `schedule` |
| `laravel.env.get` / `laravel.env.set` | app (+ `content`) | reads or writes the shared `.env`, then rebuilds the config cache and restarts workers |
| `laravel.queue.apply` / `laravel.queue.restart` | app, `workers` | runs exactly these queue workers / restarts them gracefully |
| `laravel.schedule.apply` | app, `enabled` | turns the every-minute `schedule:run` timer on or off |
| `laravel.maintenance` | app, `down`, `secret`, `retry` | `artisan down` with a bypass secret, or `artisan up` |
| `laravel.logs` | app, `file`, `bytes` | the application's log files and the end of one of them |
| `laravel.probe` | app, `kind` | facts read from the app itself: `about`, `queues`, `failed`, `schedule` |
| `laravel.scan` | `sites` | Laravel applications found in these sites' directories |
| `laravel.key` | target | the site's deploy key (ed25519), created on first use |
| `laravel.health` | `domain`, `path` | requests the path through the site's own Nginx; returns `ok`, `status`, `ms` |
| `laravel.remove` | target, `remove_files` | stops workers and the timer, and with `remove_files` deletes the releases |
| `site.git.deploy` | target, `run`, `repository`, `branch`, `path`, `token`, `commit`, `undo`, `commands` | Git dağıtımı of a plain site: fetches the branch (or takes an earlier `commit`) and checks it out into the folder `path`, then runs `commands` as the site's user; with `undo`, removes the deployed files and restores what the first deploy overwrote. Returns `commit`, `message`, `author`, `previous`, `changed`, `saved`, `problem` |
| `site.git.key` | target | the deploy key (ed25519) for the site's Git deploys, created on first use |
| `site.git.read` | `domain`, `run`, `offset` | the next part of a Git deploy's log |
| `site.git.remove` | target | forgets the site's repository clone, key and logs; the deployed files stay |
| `monitor.live` | `window`, `history` | averages over the last `window` seconds (2–600): per site CPU, memory, disk I/O, PHP-FPM pool, requests, errors, database connections, mail, top addresses, limit and `traffic` (bytes sent since `started`); accounts, system services, the server (with `net_rx`/`net_tx` bytes per second), and suspect addresses; with `history` the samples of that site |
| `stats.collect` | `ack` | statistics counted since the last collection, by site and UTC hour (requests, bytes, visitors, pages, bot requests and bytes, 2xx–5xx, and the pages, referrers, countries and bots behind them) and by mail domain and hour (sent, received, rejected, bounced), as a batch with an `id`; the batch is handed out again until the next call names it in `ack`; `countries` tells whether the country table is loaded |
| `monitor.limit.apply` | `domain`, `cpu`, `memory_mb`, `php_workers`, `seconds` | caps a site for a while (replacing its limit); returns the limit with `expires_at` |
| `monitor.limit.remove` | `domain` | ends a site's limit now; returns `removed` |
| `monitor.limits` | | the limits in force |
| `php.restart` | `domain` | stops the site's PHP-FPM workers (new ones start with the next request); returns `stopped` |
| `firewall.block` | `address`, `seconds`, `reason` | drops all traffic from an address or prefix, for `seconds` (0: until removed) |
| `firewall.unblock` | `address` | removes a block; returns `removed` |
| `firewall.list` | | the blocks in force |
| `mail.queue` | | Postfix's queue: counts, messages grouped by sender domain, the oldest 200 messages |
| `mail.queue.flush` | | retries every deferred message now |
| `mail.queue.hold` / `mail.queue.release` | `domain` | holds a sender domain's queued messages (those being delivered right now finish their attempt) or releases them (`""` for bounces); returns `messages` |
| `db.status` | | per server (`mysql`, `postgresql`): `installed`, `product`, `running`, `version`, `remote`, `upgrading` (during `component.upgrade`); and the web manager's `manager` (`configured`, `port`, `phpmyadmin`, `adminer`) |
| `db.server.install` | `product` (`mysql`, `mariadb` or `postgresql`) | installs the server if missing, configures and starts it; a failed fresh install is removed again. MySQL and MariaDB exclude each other. Returns `server`, `product`, `installed`, `version` |
| `db.manager.setup` | `port` | installs phpMyAdmin and/or Adminer for the installed servers and serves them from `serfex-web` on `port` (default 8443) |
| `db.manager.login` | `server`, `database` | a one-time `path` on the manager's `port` (valid one minute) that opens the database with a temporary user (removed after four hours) |
| `db.database.create` | `server`, `name`, `site` | creates an empty UTF-8 database; `site` maps it to a site in live monitoring |
| `db.database.delete` | `server`, `name`, `backup` | with `backup` dumps it to a gzip file first (nothing is deleted if that fails); returns `deleted`, `backup`, `backup_size` |
| `db.user.apply` | `server`, `name`, `password`, `hosts`, `grants` | creates or changes a user: `password` (empty keeps it), remote `hosts` besides this server (`%`, an IPv4 address, `10.8.0.%`, a CIDR or an IPv6 address), `grants` (`database`, `access`: `all`, `readwrite`, `readonly`) replace the previous ones |
| `db.user.delete` | `server`, `name` | removes a user |
| `db.extension.list` | `server` (`postgresql`), `database` | the offered PostgreSQL extensions (`vector`) with `enabled`, the created `version` and the packaged `available` version |
| `db.extension.set` | `server` (`postgresql`), `database`, `name`, `enabled` | creates the extension in the database as the superuser, installing its package (`postgresql-<v>-pgvector`) when missing, or drops it; dropping one that columns or indexes use is refused |
| `db.list` | `server` | the customer databases with `size` and `tables`, and the users with their remote `hosts` |
| `component.list` | | every optional component (`id`, `installed`, `version`, `latest` when an update is available, `running`, `units`, `busy`, `used_by`, `required`, `settings`, `default`; database servers also `choices`, `upgrade` (`from`, `to`) and `kept` (`from`, `to`, `old`, `dump`, `until`)) and `checked_at` |
| `component.check` | | refreshes the package lists and the newest Node.js and Composer versions, then lists like `component.list` |
| `component.install` | `id`, `run`, `port` (database servers: the web manager's port) | installs or repairs a component, writing each step to the run log; a failed install is rolled back. Returns `action`, `status` |
| `component.update` | `id`, `run` | installs the newest version; the previous configuration comes back if the service does not start |
| `component.upgrade` | `id`, `run`, `version` (the major version in `upgrade.to`) | upgrades a customers' database server to a newer major version Serfex allows (PostgreSQL to the newest, MySQL and MariaDB one LTS at a time): disk check, maintenance mode, a full dump with row counts, the new version (PostgreSQL: `pg_upgradecluster` copy mode beside the old cluster; MySQL/MariaDB: in place after a cold copy of the data directory, old packages saved), every database, user and row count checked; any failure puts the old version back. The old cluster or data directory is kept for 7 days (`kept`); database calls on that server get `-32012` meanwhile |
| `component.remove-old` | `id`, `run` | deletes the old version an upgrade kept (PostgreSQL's packages only when no other cluster uses them); the dumps stay 30 days |
| `component.remove` | `id`, `run` | archives the configuration, stops and purges the component; refused with `-32004` while something uses it |
| `component.configure` | `id`, `settings` (`modsecurity_mode`: `detect` or `block`, `modsecurity_paranoia`: 1-4) | applies new settings; the previous ones come back if Nginx refuses them |
| `component.run.read` | `run`, `offset` | the next part of an operation's log: `data`, `offset`, `size`, `done` |
| `redis.apply` | `domain`, `user`, `port`, `password`, `max_memory_mb` | starts or updates a site's own Redis instance |
| `redis.remove` | `domain` | stops a site's Redis instance and deletes its data |
| `cron.apply` | `domain`, `user`, `php`, `tasks` (`id`, `schedule`, `command`, `enabled`, `timeout`) | replaces a site's scheduled tasks: the state in `/etc/serfex/cron/<domain>.json` and the enabled tasks in `/etc/cron.d/serfex-<domain>`; returns `tasks`, `enabled` |
| `cron.remove` | `domain` | removes a site's tasks, crontab and run history; returns `removed` |
| `cron.run` | `domain`, `id` | starts a task now, in the background, as the site's user; returns `started`, or `started: false, running: true` while it still runs |
| `cron.runs` | `domain`, `id`, `limit` | the newest run records (`started_at`, `finished_at`, `duration_ms`, `exit_code`, `output`, `truncated`, `timed_out`, `skipped`, `trigger`), `running`, `running_since` |
| `system.info` | | `hostname`, `fqdn`, `os`, `kernel`, `cpus`, `memory_bytes`, `timezone`, `uptime_seconds`, `addresses` |
| `system.hostname.set` | `hostname` | sets the host name (hostnamectl) and its `127.0.1.1` line in `/etc/hosts` |
| `system.timezone.set` | `timezone` | sets the time zone (timedatectl) |
| `system.timezones` | | the time zones the server knows, sorted |
| `logs.rotation.get` | | `keep_days`: how many days of rotated logs are kept (default 14) |
| `logs.rotation.set` | `keep_days` (1-365) | saves the setting and rewrites the rotation rules (see Log rotation) |
| `quota.status` | | `state` (`on`, `off` or `unsupported`), `reason` (`not_enabled`, `fstype`, `xfs_mount`, `no_fstab`, `kernel`), `detail`, `mount`, `device`, `fstype`, `enabled_at` for the file system holding `/home` (see Disk quotas) |
| `quota.enable` | | turns user quotas on (see Disk quotas); returns the status, `unsupported` with the reason when the file system or kernel cannot have them |
| `quota.report` | `users` | each existing account's `bytes` on the file system and its `limit` (0: none), from `repquota` |
| `quota.set` | `limits` (user → bytes, 0 removes) | hard block limits through `setquota -b`, rounded up to KiB; returns `set` and the `missing` accounts |
| `panel.web.status` | | serfex-web's `ports` and primary `port` |
| `panel.web.ports` | `ports` | makes serfex-web listen on 1-3 ports (the first is the primary one), for moving the panel to a new port with the old one still open until the new one is confirmed |
| `ssl.panel.install` | `certificate`, `private_key` | stores an uploaded certificate as the panel's own and reloads serfex-web |

`site.apply` also takes `tls: {"certificate": "<name>", "redirect": true}`
to serve the site over HTTPS with a stored certificate, and `web_root`
(relative to the site directory, default `public_html`) for the directory
Nginx serves, and `web_server`: `nginx` (default) or `apache` for Nginx in
front of Apache (see Software). "target" in the Laravel methods is `domain`, `user` and
`php_version`; "app" adds `path`, the application's directory inside the
site.

Errors: `-32602` means the request can never work as sent (bad domain,
reserved account name, PHP version not installed); `-32000` means a valid
change failed on the server, and the message says why (for example the
output of `nginx -t`); `-32001` means the DNS server has not been set up
yet (call `dns.setup`); `-32002` means the same for the mail server (call
`mail.setup`); `-32003` means the database server asked for is not
installed (call `db.server.install`); `-32004` means a component is in use
and cannot be removed (the message says by what); `-32005` means another
component operation is running.

## What a site looks like on the server

```
/home/<user>/                           account home, <user>:www-data 0710
/home/<user>/tmp/                       PHP temp files and sessions
/home/<user>/web/<domain>/public_html/  document root
/home/<user>/web/<domain>/logs/         PHP error log
/var/log/serfex/sites/<domain>/         Nginx access and error logs, Apache error log
/etc/nginx/sites-available/<domain>.conf  (+ symlink in sites-enabled/)
/etc/php/<version>/fpm/pool.d/<domain>.conf
/run/php/serfex-<domain>.sock           PHP-FPM socket
```

- Each site gets its own PHP-FPM pool running as the site's account, with
  `open_basedir` limited to the account's home. One account can own several
  sites, like a cPanel account or a Plesk subscription.
- Accounts are created with `useradd` (shell `/usr/sbin/nologin`) and marked
  with the comment `Serfex site user`. Existing accounts without that mark are
  never adopted or deleted.
- Files Serfex writes start with `Managed by Serfex`. A config file without
  that line is never overwritten or removed.
- Everything inside a customer's home is created or deleted as that customer
  (`runuser -u <user> --`), so a symlink planted there cannot redirect a root
  write.
- `site.apply` is idempotent and transactional: running it again changes
  nothing, a changed PHP version moves the pool, and if `php-fpm -t`,
  `nginx -t` or a reload fails, every change is rolled back.

## DNS

Serfex answers DNS itself with PowerDNS Authoritative, run as its own
instance so the distribution's `pdns.conf` and `pdns.d/` stay untouched:

```
/etc/powerdns/pdns-serfex.conf   instance config, root:pdns 0640 (holds the API key)
/var/lib/powerdns/serfex.lmdb    zone data (LMDB backend)
pdns@serfex.service              the instance; the stock pdns.service is disabled
127.0.0.1:8081                   PowerDNS HTTP API, loopback only
```

- Zone data lives in PowerDNS's own LMDB file, not in the panel's
  PostgreSQL: DNS keeps answering while the panel or its database is down or
  being upgraded, and customer-facing DNS does not depend on a system service.
  LMDB also supports DNSSEC, which a later version will switch on.
- The panel is the source of truth for records. `dns.zone.apply` receives
  the whole zone, compares it with what PowerDNS serves and sends only the
  changed record sets in one atomic API call; PowerDNS raises the SOA serial
  (`SOA-EDIT-API DEFAULT`). Running it twice changes nothing.
- Records are validated before they reach PowerDNS: supported types are A,
  AAAA, CNAME, MX, TXT, NS, SRV and CAA; a CNAME cannot share a name with
  other records or sit at the apex; all records of one name and type share a
  TTL. Long TXT values (DKIM keys) are split into 255-byte strings.
- `dns.setup` frees port 53 from systemd-resolved's stub listener
  (`/etc/systemd/resolved.conf.d/serfex-dns.conf`, `/etc/resolv.conf` then
  points at `/run/systemd/resolve/resolv.conf`), checks the config with
  `pdns_server --config=check` and waits for the API. If PowerDNS does not
  come up, the configuration changes are rolled back; installed packages stay.
- Zone transfers are refused (`disable-axfr=yes`); secondary servers come later.

## Certificates

Certificates come from Let's Encrypt through the ACME client in
`golang.org/x/crypto/acme`, built into the agent: no certbot, lego or Python
on the server, and the agent stays one static binary.

```
/etc/serfex/ssl/certs/<name>/fullchain.pem    certificate + chain, 0644
/etc/serfex/ssl/certs/<name>/privkey.pem      private key (ECDSA P-256), root 0600
/etc/serfex/ssl/accounts/<acme server>/       ACME account key, root 0700
/etc/serfex/panel/ssl/                        the panel's own certificate
/var/lib/serfex/acme/.well-known/acme-challenge/   HTTP-01 answers
/etc/serfex/nginx/tls.conf                    TLS defaults every HTTPS site includes
```

- **HTTP-01** (default): every Serfex vhost serves
  `/.well-known/acme-challenge/` from `/var/lib/serfex/acme`, also when it
  redirects HTTP to HTTPS, so validation works whatever the site's own
  files or framework do. Before asking Let's Encrypt to look, the agent
  waits until the local Nginx answers the token.
- **DNS-01** (wildcards): for zones this server's PowerDNS serves, the agent
  writes `_acme-challenge` TXT records straight through the PowerDNS API and
  removes them afterwards. `dns.zone.apply` leaves these records alone while
  a validation runs.
- Sites use a certificate by name, so renewing only replaces the two files
  and reloads Nginx. If `nginx -t` or the reload fails, the previous files
  come back.
- TLS defaults follow Mozilla's "intermediate" profile: TLS 1.2 and 1.3,
  ECDHE with AES-GCM or ChaCha20, no session tickets. OCSP stapling is on
  for certificates that name an OCSP responder (Let's Encrypt certificates
  no longer do).
- The agent never renews on its own: the panel decides when (30 days
  before expiry) and shows failures. The panel's own certificate is served
  by the panel's web server (`serfex-web`, reloaded when present); Nginx
  only answers its validation on port 80.
- `-acme-directory` and `-acme-ca` point the agent at another ACME server,
  such as Let's Encrypt staging or Pebble in tests:

```sh
sudo SERFEX_ACME_DIRECTORY=https://localhost:14000/dir SERFEX_ACME_CA=pebble.minica.pem \
    go test -tags realhost -run TestRealHost ./internal/cert
```

## Mail

`mail.setup` installs Postfix (SMTP), Dovecot (IMAP, POP3, LMTP, Sieve),
Rspamd (spam filter, DKIM signing, sending limits) with its own Redis, and
webmail, from Ubuntu's packages. The webmail is Roundcube (Ubuntu's
package) or SnappyMail (`webmail_app: "snappymail"`): the release pinned in
`mail.SnappyMailRelease` is downloaded from GitHub and checked against its
SHA-256 before use; `mail.status` reports `webmail_latest` when a newer
agent pins a newer one, and `mail.setup` installs it (the release before
stays for a rollback). Both run on the same PHP-FPM pool, as
`serfex-webmail`, and sign a mailbox in with a one-time token through
Dovecot's master user.

```
/etc/serfex/mail/server.json             hostname, limits, webmail secrets (root 0600)
/etc/serfex/mail/domains/<domain>.json   the domain as last applied
/etc/serfex/mail/dkim/<domain>.key       DKIM private key (RSA 2048), root:_rspamd 0640
/etc/serfex/mail/tls/                    self-signed certificate until a real one is set
/etc/postfix/serfex/                     domain, mailbox, forwarder and sender maps
/etc/dovecot/serfex/users                mailboxes: password hash, quota, Sieve script
/etc/dovecot/serfex/sieve/<domain>/      per-mailbox autoreplies
/etc/rspamd/local.d/                     DKIM signing, limits, actions, Redis
/var/vmail/<domain>/<name>/Maildir/      the mail, owned by the vmail user
/etc/serfex/webmail/                     Roundcube's configuration (only this one is read)
/var/lib/serfex/webmail/data/            Roundcube's SQLite database, one-click login tokens
/opt/serfex/snappymail/<version>/        SnappyMail releases (root-owned; include.php is Serfex's)
/var/lib/serfex/webmail/snappymail/      SnappyMail's data: application.ini and default.json are Serfex's
/var/lib/serfex/webmail/snappymail-sso.php   SnappyMail's one-click login
/etc/nginx/sites-available/serfex-mail.conf   webmail, autoconfig and autodiscover
```

- Mailboxes are virtual: one `vmail` system user owns all mail, so a
  mailbox is not a shell account. The panel sends Dovecot password hashes
  (`{SHA512-CRYPT}$6$...`); the agent never sees a password.
- Mail is received on 25 and sent with a login on 587 (STARTTLS) and 465
  (TLS). A mailbox may only send as itself or as a forwarder that points at
  it. Each mailbox may send `hourly_limit` messages an hour (Rspamd
  ratelimit; 0 is no limit). Messages scoring 15 or more are rejected, 6 or
  more get an `X-Spam` header, and domains with `spam_filter` file them
  into Junk.
- Every domain signs with selector `serfex`; `mail.domain.apply` returns
  the TXT record to publish at `serfex._domainkey`. The key survives
  `mail.domain.delete` unless `remove_files` is set, so turning mail off and
  on again keeps the record valid.
- IMAP 993/143, POP3 995/110 and SMTP use the `certificate` from the
  certificate store (`ssl.issue`) for the mail hostname, or a self-signed
  one while there is none. Renewing that certificate reloads Postfix and
  Dovecot, and it cannot be deleted while the mail server uses it.
- Clients find their settings through `autoconfig.<domain>` (Thunderbird)
  and `autodiscover.<domain>` (Outlook), which Nginx answers on port 80.
- Webmail is served at `https://<hostname>/` by the customers' Nginx, with
  its own PHP-FPM pool user `serfex-webmail`. `mail.webmail.login` signs the
  panel user in without a password, through a one-time token and a Dovecot
  master user that only accepts connections from the server itself.
- Removing a mailbox stops it at once; its mail stays until
  `mail.mailbox.purge`, so the panel can undo the removal.
- Every change is checked (`postfix check`, `doveconf`, `rspamadm
  configtest`, `nginx -t`) before services reload; a failure restores the
  previous files. The stock `main.cf`, `master.cf` and `dovecot.conf` are
  kept as `*.before-serfex`.

```sh
sudo go test -tags realhost -run TestRealHost ./internal/mail
```

sets up the whole stack, creates mailboxes, sends through SMTP, reads
through IMAP, checks the DKIM signature, the limits, the autoreply and the
webmail sign-in. It needs Nginx, PHP-FPM, `python3-dkim` and `doveadm`
(part of Dovecot).

## Laravel

A Laravel application managed by Serfex lives inside its site:

```
/home/<user>/web/<domain>/laravel/
  releases/<n>/          one directory per deploy, the last 5 are kept
  shared/.env            linked into every release
  shared/storage/        linked into every release
  shared/database/       SQLite database, when the app uses one
  current -> releases/<n>
```

The site's web root becomes `laravel/current/public`, so switching
`current` is the moment a release goes live. A deploy clones the
repository (or runs `composer create-project`), runs `composer install`,
the front-end build when there is a `package.json`, `migrate --force` and
the caches in the new release while the old one keeps serving, then
switches `current`, runs the health check and switches back if it fails.
Migrations are not reverted on rollback.

- Every command runs as the site's user with a clean environment, through
  `runuser`, using the site's PHP version. Composer and Node.js 24 are
  downloaded once into `/opt/serfex` and their checksums verified.
- Long runs (deploys, commands started from the panel) write their output
  to `/var/lib/serfex/laravel/<domain>/runs/<run>.log`; the panel follows
  it with `laravel.run.read`. Logs older than 90 days are removed.
- Git deploys of plain sites keep a bare clone in
  `/home/<user>/.serfex/git/<domain>/repo.git` whose work tree is the
  site's folder: changed files are written, files the repository deleted
  are removed, untracked files stay. `refs/serfex/live` is the deployed
  commit; the first deploy packs the files it overwrites into
  `before.tar` for its undo. An access token is written to a file for
  the fetch only, never put on a command line. Logs:
  `/var/lib/serfex/git/<domain>/runs/`.
- Queue workers are systemd template units
  `serfex-queue-<domain>_<worker>@<n>.service`; the scheduler is
  `serfex-schedule-<domain>.timer`, running `schedule:run` every minute.
  Their output goes to `/var/log/serfex/laravel/<domain>/`, rotated by
  `/etc/logrotate.d/serfex-laravel`.
- Private repositories are cloned over SSH with the site's own deploy key,
  owned by the site's account in `laravel/.ssh/id_ed25519`.

```sh
sudo go test -tags realhost -run TestRealHost -timeout 20m ./internal/laravel
```

creates a new Laravel project with its front end, serves it through
Nginx and PHP-FPM, deploys a broken release that is rolled back, deploys
from Git, edits `.env`, turns maintenance mode on and off, starts queue
workers and the scheduler, and removes everything again. It needs Nginx,
PHP-FPM with the usual Laravel extensions and network access.

## Live monitoring

Every two seconds the agent samples the machine and keeps the last ten
minutes in memory; `monitor.live` returns averages over the window the
panel asks for.

- **Who uses what.** CPU time, resident anonymous memory (`RssAnon`: heap
  and stacks, not the shared opcache) and disk I/O come from `/proc`. A
  process belongs to a site when it is one of the site's PHP-FPM workers
  (`php-fpm: pool <domain>`), runs in one of its Laravel units, or belongs
  to an account with a single site. Other processes are grouped by systemd
  unit: Serfex's own services (`serfex-*`, the panel's PostgreSQL), the
  services every site shares (Nginx, PHP-FPM masters, PowerDNS, Postfix,
  Dovecot, Rspamd, MySQL) and the rest of the system.
- **PHP-FPM.** Each pool answers status requests on its own socket
  (`pm.status_listen = /run/php/serfex-<domain>.status.sock`), which Nginx
  never passes; it reports busy and idle workers, the backlog and whether
  the pool hit `pm.max_children`.
- **Traffic.** New lines of each site's access log give requests per
  second, 4xx and 5xx answers and the busiest addresses. A rotated log is
  followed; a log that grows too fast is skipped ahead.
- **Suspects.** An address is listed when, in the last minute, it sent one
  site 300 requests, 20 POSTs to a login page (`/wp-login.php`,
  `/xmlrpc.php`, `/login`, ...) or got 60 "404" answers, or when it failed
  10 mail (Postfix SASL, Dovecot) or SSH logins in ten minutes. A suspect
  stays listed for ten minutes after it goes quiet. The agent never blocks
  on its own; the panel decides.
- **Databases and mail.** Connections to the customers' MySQL or
  PostgreSQL count for the account whose name the database or user starts
  with (`<account>` or `<account>_...`). Sent mail counts for the account
  of PHP's `mail()` (Postfix pickup's uid) or for the site of the sender's
  domain, once per message.

### Blocking addresses

`firewall.block` adds the address to Serfex's own nftables table, so the
distribution's firewall stays untouched:

```
table inet serfex
  set blocked4, blocked6     addresses and prefixes, each with its own timeout
  chain input                priority filter - 10: drops them before other rules
/etc/serfex/firewall/blocks.json   blocks as last applied (restored at start)
```

The kernel ends a timed block by itself, so it ends on time even while the
agent or the panel is down. Loopback, multicast, the server's own
addresses and prefixes wider than /16 (IPv4) or /48 (IPv6) are refused.
A block covers the whole server: sites share their addresses.

### Temporary limits

`monitor.limit.apply` caps one site for 1 minute to 7 days:

- **PHP workers** through `pm.max_children` in the site's pool (reloaded
  after `php-fpm -t`); `site.apply` keeps the value while the limit lasts.
- **CPU and memory** through a systemd scope,
  `serfex-limit-<domain>.scope` with `CPUQuota` and `MemoryMax`, created
  with the site's PHP-FPM workers (`Delegate=yes`). PHP-FPM forks new
  workers from its master, so every sample moves the site's new workers
  into the scope. The site's Laravel units get the same caps
  (`systemctl set-property --runtime`).

Limits live in `/etc/serfex/limits/<domain>.json`; the agent ends them on
time, also after a restart. Ending one sets the caps back to unlimited and
the pool to its usual 10 workers; workers already in the scope finish
their requests there.

`php.restart` stops a site's PHP-FPM workers (TERM, then KILL after three
seconds) without touching other sites; PHP-FPM starts new ones with the
next request.

```sh
sudo go test -tags realhost -run TestRealHost ./internal/monitor
```

serves a site through Nginx and PHP-FPM under systemd, measures a busy PHP
request through `/proc` and the pool's status socket, detects a request
flood, blocks and unblocks the address with nftables, caps the site's CPU
and memory through its scope (also for workers started later), limits its
PHP workers and restarts its pool.


## Log rotation

Every log Serfex's web servers write is rotated once a day by the system's
logrotate, compressed from the second day on (`delaycompress`) and kept for
`keep_days` days (`/etc/serfex/logs.json`, default 14):

```
/etc/logrotate.d/serfex-sites     /var/log/serfex/sites/*/*.log; Nginx reopens its logs (USR1), Apache reloads
/etc/logrotate.d/serfex-php       each site's logs/php-error.log, as the site's user (su), so a link planted in the customer's directory reaches nothing else
/etc/logrotate.d/serfex-web       /var/log/serfex/web/*.log; serfex-web and serfex-php reopen theirs
/etc/logrotate.d/serfex-webmail   /var/log/serfex/webmail/*.log, as serfex-webmail
```

The agent rebuilds these files when it starts, every hour, after
`site.apply` and `site.delete`, and on `logs.rotation.set`; the sites are
the PHP-FPM pools Serfex wrote whose account and log directory exist (one
unknown user would make logrotate refuse the whole file). A changed file is
checked with `logrotate --debug` and undone if refused. The monitor finishes
reading a renamed access log (`access.log.1`) before it follows the new one,
so statistics lose no lines to a rotation. Laravel's queue and scheduler
logs (weekly, 4) and ModSecurity's audit log (daily, 14) keep their own
rules.

## Disk quotas

Customers' disk limits are enforced with the file system's user quotas
(package `quota`) on the file system holding `/home`. `quota.enable`, on
ext4: installs the `quota` tools; loads the `quota_v2` module, installing
`linux-modules-extra-$(uname -r)` when a cloud kernel lacks it, and lists it
in `/etc/modules-load.d/serfex-quota.conf`; adds
`usrjquota=aquota.user,jqfmt=vfsv1` to the file system's `/etc/fstab` line
(the first original is kept as `/etc/fstab.before-serfex-quota`); remounts
with those options; runs `quotacheck -cum` and `quotaon -u`. A failing step
puts `/etc/fstab` back. systemd turns quotas on at boot from the options.
XFS works only when it was mounted with `uquota`/`usrquota` (it cannot be
switched on by a remount); other file systems are `unsupported`. A kernel
that cannot load the module, and a file system with no fstab line, are
remembered in `/etc/serfex/quota.json` (the kernel case until the kernel
changes) so the panel stops offering it.

Limits are hard block limits only (no soft limit, no grace), so a write
past one fails at once with `EDQUOT`; the file manager reports it as
`quota`. The panel sets each account to what its files use plus what is
left of the customer's limit, every 5 minutes. Dry runs keep the switch and
the limits in the state file and measure home directories.

## Statistics

The monitor hands the new lines of each site's access log and of the mail
log to package `stats`, which adds them up per site and UTC hour until the
panel collects them with `stats.collect` (every minute) and stores hourly
and daily rollups in its own database.

- **Visitors.** A visitor is a hash of address and user agent, counted once
  per site and day (server time); addresses never leave the agent. Bots and
  tools (Googlebot, AhrefsBot, curl, a missing user agent, anything calling
  itself a bot or crawler) are counted apart and left out of visitors,
  pages, referrers and countries.
- **Pages** are successful GETs that are not images, scripts, styles or
  downloads. **Referrers** are the referring hosts, without the site's own
  names and subdomains. At most 300 distinct values per kind, site and hour
  are kept; the rest add up under "".
- **Countries** come from the five regional registries' public delegated
  files (where each address block was registered), downloaded once a month
  into `/var/lib/serfex/stats/countries.bin` and looked up locally.
- **Mail** per domain: messages delivered to its mailboxes (`received`),
  sent to and bounced by other servers (by the queue ID's sender), and
  refused on the way in (`reject:`, `milter-reject:`).
- **Collection is acknowledged.** A batch stays pending until the next call
  names it, so a batch the panel lost is sent again and one it stored is
  never counted twice. Uncollected counts and today's visitors are written
  to `/var/lib/serfex/stats/state.gob` when the agent stops and read back
  when it starts. Counts the panel has not collected for a week are dropped.
## Databases

Customer databases run on the distribution's own servers: MySQL 8 or
MariaDB (one of them) and PostgreSQL (its `main` cluster on port 5432).
The panel's own database is separate and never touched by `db.*`.

- Names are the panel's (`<account>_<name>`, lowercase letters, digits and
  `_`, databases at most 54 characters, users 32). Names with a hyphen are
  Serfex's own: a PostgreSQL database is owned by the `NOLOGIN` role
  `<db>-owner`, and users with full access act as it (so every table they
  create belongs to the database, not to one user); `serfex-s-*` are the
  temporary users of web manager sign-ins.
- `readwrite` is data only (no schema changes), `readonly` is `SELECT`
  only. MySQL users always exist for `localhost` and `127.0.0.1`;
  PostgreSQL requires passwords (`scram-sha-256`) for everyone but
  `postgres`.
- The servers listen on `127.0.0.1` until some user has a remote host,
  then on every address (MySQL `bind-address`, PostgreSQL
  `listen_addresses` plus a Serfex-managed `pg_hba.conf` in
  `/etc/serfex/databases/`); they go back when the last remote host goes.
- Backups taken before a delete go to `/var/lib/serfex/backups/databases/`
  and are removed after 30 days.
- The web manager runs on `serfex-web` (Serfex's own Nginx, config
  `/etc/serfex/web/nginx.conf`) and `serfex-php` (its own PHP-FPM, pool user
  `serfex-dbadmin`), never on the customers' Nginx or PHP. phpMyAdmin
  (`/phpmyadmin/`, `signon` auth) serves MySQL/MariaDB, Adminer
  (`/adminer/`, PostgreSQL on this server only) PostgreSQL. It uses a
  self-signed certificate until the panel's own certificate exists, then
  that one.

```sh
sudo SERFEX_DB_PRODUCT=mariadb go test -tags realhost -run TestRealHost ./internal/database
```

installs the server, creates databases and users with each access level,
connects with them, turns remote access on and off, signs in to
phpMyAdmin or Adminer through `serfex-web` and deletes a database with a
backup.

## Software

Yazılım Yönetimi installs, updates and removes optional software with
`apt-get` (Ubuntu's packages; PHP versions Ubuntu lacks come from
`ppa:ondrej/php`), Node.js from nodejs.org and Composer from
getcomposer.org, both checked against their published SHA-256 sums.

| Component | What Serfex sets up |
|-----------|---------------------|
| `nginx` | Required, never removed. |
| `apache` | Apache 2.4 on `127.0.0.1:7080` behind Nginx, for sites with `web_server: apache`. Nginx serves existing static files and passes everything else to Apache, which reads `.htaccess` (`AllowOverride All`) and hands PHP to the site's own PHP-FPM pool (`proxy_fcgi`). `remoteip` restores the visitor's address and `HTTPS` is set behind TLS. Vhosts: `/etc/apache2/sites-available/<domain>.conf`. |
| `php8.1` … `php8.5` | PHP-FPM with the common extensions (and `redis`/`memcached` when those are installed). In use while a site, the webmail or the web database manager uses the version. |
| `mysql`, `mariadb`, `postgresql` | The database servers of Veritabanları (`db.server.install`), and the web manager. |
| `redis` | `redis-server` without the stock service: every application gets its own instance, `serfex-redis-<domain>.service`, running as the site's user on `127.0.0.1:<port>` with a password, 128 MB and `noeviction` (`/etc/serfex/redis/<domain>.conf`, data in `/var/lib/serfex/redis/<domain>`). |
| `memcached` | `memcached` on `127.0.0.1:11211`. |
| `supervisor` | `supervisord`; in use while `/etc/supervisor/conf.d` has programs. Laravel workers use systemd, not Supervisor. |
| `node22`, `node24`, `node26` | Node.js under `/opt/serfex/node/<version>`; Laravel builds use the line `/opt/serfex/node/current` points to: the first one installed, or after it is removed the newest LTS line left. |
| `composer` | `/opt/serfex/composer/composer.phar`. |
| `python` | `python3-pip`, `python3-venv`, `python3-dev`. |
| `fail2ban` | Jails in `/etc/fail2ban/jail.d/serfex.conf`: `sshd`, and `dovecot` and `postfix-sasl` when the mail server exists at install (install Fail2ban again to add them later); 5 failures in 10 minutes ban for an hour with nftables, `recidive` bans repeat offenders for a week. The server's own addresses are never banned. |
| `modsecurity` | The Nginx ModSecurity module with the OWASP Core Rule Set, server-wide, in detection-only mode at paranoia level 1 until changed (`/etc/serfex/modsecurity/main.conf`, audit log in `/var/log/serfex/modsecurity/`). |
| `clamav` | `clamd` and `freshclam`; the first signatures are downloaded during the install, and Rspamd rejects infected mail when the mail server is there. |

- One operation runs at a time. Each writes its steps to
  `/var/lib/serfex/components/runs/<run>.log` (lines `▸` step, `$`
  command, `↺` rollback, `✓` done, `✗` failed).
- An install that fails removes the packages it added and puts back the
  files it changed. An update that leaves the service broken gets the
  previous configuration back.
- Removing archives the component's configuration to
  `/var/lib/serfex/backups/components/<id>-<time>.tar.gz` first. MySQL and
  MariaDB data is moved there, not deleted; PostgreSQL's packages stay
  (the panel's own database may use them) and only the customers' `main`
  cluster is dropped. A database server is not removed while it has
  customer databases or users.

```sh
sudo go test -tags realhost -run TestRealHost ./internal/component
```

installs Apache, serves a site through Nginx and Apache with a `.htaccess`
rewrite, refuses to remove Apache while the site uses it, removes it with
a backup, rolls back a Memcached install that fails on purpose and runs a
site's own Redis instance.

## Dry run

`-dry-run` swaps the real system for a simulator: files go under `-root`
(default: a new temporary directory), `useradd`/`userdel` edit
`<root>/etc/passwd`, service commands are only logged, DNS zones are kept
in memory, certificates come from a local "Serfex Dry Run CA" and mail
packages are only pretended to be installed, and `git clone` or
`composer create-project` write a small stand-in Laravel application.
Live monitoring makes up a plausible load for the sites under `-root`
(one of them under attack, one busy), and a mail queue once the mail
server is "installed". Database servers keep their databases and users in
JSON files under `<root>/var/lib/serfex-sim/` with made-up sizes.
The panel's integration tests and local development use this mode.

```sh
./bin/serfex-agent -dry-run -root /tmp/serfex-root -socket /tmp/serfex-agent.sock
```

Example:

```sh
echo '{"jsonrpc":"2.0","id":1,"method":"agent.info"}' | sudo socat - UNIX-CONNECT:/run/serfex-agent/agent.sock
```
