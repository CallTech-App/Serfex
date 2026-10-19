# servik-agent

The privileged half of Servik. It runs as root under systemd and serves
JSON-RPC 2.0 on a Unix socket (default `/run/servik-agent/agent.sock`, mode
`0660`). Each request and response is one line of JSON.

```sh
go test ./...
CGO_ENABLED=0 go build -ldflags "-X github.com/winchester37/servik/agent/internal/version.Version=dev" \
  -o bin/servik-agent ./cmd/servik-agent
```

Methods so far:

| Method | Params | Result |
|--------|--------|--------|
| `system.ping` | | `"pong"` |
| `agent.info` | | version, Go version, OS, architecture, `dry_run` |
| `php.versions` | | PHP versions with PHP-FPM installed, e.g. `["8.3","8.4"]` |
| `site.apply` | `domain`, `user`, `php_version`, `aliases` | creates or updates a site; returns paths, `uid`, `user_created`, `changed` |
| `site.delete` | `domain`, `user`, `remove_files`, `remove_user` | removes the site; returns `files_removed`, `user_removed` |
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
| `mail.domain.apply` | `domain`, `spam_filter`, `mailboxes`, `aliases`, `catch_all` | serves exactly these mailboxes and forwarders; returns `changed` and the domain's `dkim` record |
| `mail.domain.delete` | `domain`, `remove_files` | stops serving the domain's mail; returns `deleted`, `files_removed` |
| `mail.mailbox.purge` | `domain`, `name` | deletes the stored mail of a removed mailbox |
| `mail.usage` | `domain` | bytes stored per mailbox |
| `mail.webmail.login` | `address` | a one-time `url` (valid one minute) that opens the mailbox in webmail |
| `laravel.tools` | `php_version` | installs Composer and Node.js if missing; returns their versions |
| `laravel.deploy` | target, `run`, `release`, `source`, `build`, `hooks`, `env`, `health`, `rollback`, `keep`, `seed_from` | builds a release, switches `current` to it, checks health and rolls back on failure; returns `release`, `commit`, `laravel`, `health`, `rolled_back`, `problem` |
| `laravel.rollback` | target, `run`, `release` | switches `current` back to an earlier release |
| `laravel.run` | app, `run`, `tool`, `args` | runs Artisan, Composer, npm, pnpm or bun as the site's user; with `run` it writes a log instead of returning the output |
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
| `monitor.live` | `window`, `history` | averages over the last `window` seconds (2–600): per site CPU, memory, disk I/O, PHP-FPM pool, requests, errors, database connections, mail, top addresses and limit; accounts, system services, the server, and suspect addresses; with `history` the samples of that site |
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

`site.apply` also takes `tls: {"certificate": "<name>", "redirect": true}`
to serve the site over HTTPS with a stored certificate, and `web_root`
(relative to the site directory, default `public_html`) for the directory
Nginx serves. "target" in the Laravel methods is `domain`, `user` and
`php_version`; "app" adds `path`, the application's directory inside the
site.

Errors: `-32602` means the request can never work as sent (bad domain,
reserved account name, PHP version not installed); `-32000` means a valid
change failed on the server, and the message says why (for example the
output of `nginx -t`); `-32001` means the DNS server has not been set up
yet (call `dns.setup`); `-32002` means the same for the mail server (call
`mail.setup`).

## What a site looks like on the server

```
/home/<user>/                           account home, <user>:www-data 0710
/home/<user>/tmp/                       PHP temp files and sessions
/home/<user>/web/<domain>/public_html/  document root
/home/<user>/web/<domain>/logs/         PHP error log
/var/log/servik/sites/<domain>/         Nginx access and error logs
/etc/nginx/sites-available/<domain>.conf  (+ symlink in sites-enabled/)
/etc/php/<version>/fpm/pool.d/<domain>.conf
/run/php/servik-<domain>.sock           PHP-FPM socket
```

- Each site gets its own PHP-FPM pool running as the site's account, with
  `open_basedir` limited to the account's home. One account can own several
  sites, like a cPanel account or a Plesk subscription.
- Accounts are created with `useradd` (shell `/usr/sbin/nologin`) and marked
  with the comment `Servik site user`. Existing accounts without that mark are
  never adopted or deleted.
- Files Servik writes start with `Managed by Servik`. A config file without
  that line is never overwritten or removed.
- Everything inside a customer's home is created or deleted as that customer
  (`runuser -u <user> --`), so a symlink planted there cannot redirect a root
  write.
- `site.apply` is idempotent and transactional: running it again changes
  nothing, a changed PHP version moves the pool, and if `php-fpm -t`,
  `nginx -t` or a reload fails, every change is rolled back.

## DNS

Servik answers DNS itself with PowerDNS Authoritative, run as its own
instance so the distribution's `pdns.conf` and `pdns.d/` stay untouched:

```
/etc/powerdns/pdns-servik.conf   instance config, root:pdns 0640 (holds the API key)
/var/lib/powerdns/servik.lmdb    zone data (LMDB backend)
pdns@servik.service              the instance; the stock pdns.service is disabled
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
  (`/etc/systemd/resolved.conf.d/servik-dns.conf`, `/etc/resolv.conf` then
  points at `/run/systemd/resolve/resolv.conf`), checks the config with
  `pdns_server --config=check` and waits for the API. If PowerDNS does not
  come up, the configuration changes are rolled back; installed packages stay.
- Zone transfers are refused (`disable-axfr=yes`); secondary servers come later.

## Certificates

Certificates come from Let's Encrypt through the ACME client in
`golang.org/x/crypto/acme`, built into the agent: no certbot, lego or Python
on the server, and the agent stays one static binary.

```
/etc/servik/ssl/certs/<name>/fullchain.pem    certificate + chain, 0644
/etc/servik/ssl/certs/<name>/privkey.pem      private key (ECDSA P-256), root 0600
/etc/servik/ssl/accounts/<acme server>/       ACME account key, root 0700
/etc/servik/panel/ssl/                        the panel's own certificate
/var/lib/servik/acme/.well-known/acme-challenge/   HTTP-01 answers
/etc/servik/nginx/tls.conf                    TLS defaults every HTTPS site includes
```

- **HTTP-01** (default): every Servik vhost serves
  `/.well-known/acme-challenge/` from `/var/lib/servik/acme`, also when it
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
  by the panel's web server (`servik-web`, reloaded when present); Nginx
  only answers its validation on port 80.
- `-acme-directory` and `-acme-ca` point the agent at another ACME server,
  such as Let's Encrypt staging or Pebble in tests:

```sh
sudo SERVIK_ACME_DIRECTORY=https://localhost:14000/dir SERVIK_ACME_CA=pebble.minica.pem \
    go test -tags realhost -run TestRealHost ./internal/cert
```

## Mail

`mail.setup` installs Postfix (SMTP), Dovecot (IMAP, POP3, LMTP, Sieve),
Rspamd (spam filter, DKIM signing, sending limits) with its own Redis, and
Roundcube for webmail, from Ubuntu's packages.

```
/etc/servik/mail/server.json             hostname, limits, webmail secrets (root 0600)
/etc/servik/mail/domains/<domain>.json   the domain as last applied
/etc/servik/mail/dkim/<domain>.key       DKIM private key (RSA 2048), root:_rspamd 0640
/etc/servik/mail/tls/                    self-signed certificate until a real one is set
/etc/postfix/servik/                     domain, mailbox, forwarder and sender maps
/etc/dovecot/servik/users                mailboxes: password hash, quota, Sieve script
/etc/dovecot/servik/sieve/<domain>/      per-mailbox autoreplies
/etc/rspamd/local.d/                     DKIM signing, limits, actions, Redis
/var/vmail/<domain>/<name>/Maildir/      the mail, owned by the vmail user
/etc/servik/webmail/                     Roundcube's configuration (only this one is read)
/var/lib/servik/webmail/data/            Roundcube's SQLite database
/etc/nginx/sites-available/servik-mail.conf   webmail, autoconfig and autodiscover
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
- Every domain signs with selector `servik`; `mail.domain.apply` returns
  the TXT record to publish at `servik._domainkey`. The key survives
  `mail.domain.delete` unless `remove_files` is set, so turning mail off and
  on again keeps the record valid.
- IMAP 993/143, POP3 995/110 and SMTP use the `certificate` from the
  certificate store (`ssl.issue`) for the mail hostname, or a self-signed
  one while there is none. Renewing that certificate reloads Postfix and
  Dovecot, and it cannot be deleted while the mail server uses it.
- Clients find their settings through `autoconfig.<domain>` (Thunderbird)
  and `autodiscover.<domain>` (Outlook), which Nginx answers on port 80.
- Webmail is served at `https://<hostname>/` by the customers' Nginx, with
  its own PHP-FPM pool user `servik-webmail`. `mail.webmail.login` signs the
  panel user in without a password, through a one-time token and a Dovecot
  master user that only accepts connections from the server itself.
- Removing a mailbox stops it at once; its mail stays until
  `mail.mailbox.purge`, so the panel can undo the removal.
- Every change is checked (`postfix check`, `doveconf`, `rspamadm
  configtest`, `nginx -t`) before services reload; a failure restores the
  previous files. The stock `main.cf`, `master.cf` and `dovecot.conf` are
  kept as `*.before-servik`.

```sh
sudo go test -tags realhost -run TestRealHost ./internal/mail
```

sets up the whole stack, creates mailboxes, sends through SMTP, reads
through IMAP, checks the DKIM signature, the limits, the autoreply and the
webmail sign-in. It needs Nginx, PHP-FPM, `python3-dkim` and `doveadm`
(part of Dovecot).

## Laravel

A Laravel application managed by Servik lives inside its site:

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
  downloaded once into `/opt/servik` and their checksums verified.
- Long runs (deploys, commands started from the panel) write their output
  to `/var/lib/servik/laravel/<domain>/runs/<run>.log`; the panel follows
  it with `laravel.run.read`. Logs older than 90 days are removed.
- Queue workers are systemd template units
  `servik-queue-<domain>_<worker>@<n>.service`; the scheduler is
  `servik-schedule-<domain>.timer`, running `schedule:run` every minute.
  Their output goes to `/var/log/servik/laravel/<domain>/`, rotated by
  `/etc/logrotate.d/servik-laravel`.
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
  unit: Servik's own services (`servik-*`, the panel's PostgreSQL), the
  services every site shares (Nginx, PHP-FPM masters, PowerDNS, Postfix,
  Dovecot, Rspamd, MySQL) and the rest of the system.
- **PHP-FPM.** Each pool answers status requests on its own socket
  (`pm.status_listen = /run/php/servik-<domain>.status.sock`), which Nginx
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

`firewall.block` adds the address to Servik's own nftables table, so the
distribution's firewall stays untouched:

```
table inet servik
  set blocked4, blocked6     addresses and prefixes, each with its own timeout
  chain input                priority filter - 10: drops them before other rules
/etc/servik/firewall/blocks.json   blocks as last applied (restored at start)
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
  `servik-limit-<domain>.scope` with `CPUQuota` and `MemoryMax`, created
  with the site's PHP-FPM workers (`Delegate=yes`). PHP-FPM forks new
  workers from its master, so every sample moves the site's new workers
  into the scope. The site's Laravel units get the same caps
  (`systemctl set-property --runtime`).

Limits live in `/etc/servik/limits/<domain>.json`; the agent ends them on
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

## Dry run

`-dry-run` swaps the real system for a simulator: files go under `-root`
(default: a new temporary directory), `useradd`/`userdel` edit
`<root>/etc/passwd`, service commands are only logged, DNS zones are kept
in memory, certificates come from a local "Servik Dry Run CA" and mail
packages are only pretended to be installed, and `git clone` or
`composer create-project` write a small stand-in Laravel application.
Live monitoring makes up a plausible load for the sites under `-root`
(one of them under attack, one busy), and a mail queue once the mail
server is "installed".
The panel's integration tests and local development use this mode.

```sh
./bin/servik-agent -dry-run -root /tmp/servik-root -socket /tmp/servik-agent.sock
```

Example:

```sh
echo '{"jsonrpc":"2.0","id":1,"method":"agent.info"}' | sudo socat - UNIX-CONNECT:/run/servik-agent/agent.sock
```
