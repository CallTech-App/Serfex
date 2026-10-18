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

`site.apply` also takes `tls: {"certificate": "<name>", "redirect": true}`
to serve the site over HTTPS with a stored certificate.

Errors: `-32602` means the request can never work as sent (bad domain,
reserved account name, PHP version not installed); `-32000` means a valid
change failed on the server, and the message says why (for example the
output of `nginx -t`); `-32001` means the DNS server has not been set up
yet (call `dns.setup`).

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

## Dry run

`-dry-run` swaps the real system for a simulator: files go under `-root`
(default: a new temporary directory), `useradd`/`userdel` edit
`<root>/etc/passwd`, service commands are only logged, DNS zones are kept
in memory and certificates come from a local "Servik Dry Run CA". The panel's integration tests and local development use this
mode.

```sh
./bin/servik-agent -dry-run -root /tmp/servik-root -socket /tmp/servik-agent.sock
```

Example:

```sh
echo '{"jsonrpc":"2.0","id":1,"method":"agent.info"}' | sudo socat - UNIX-CONNECT:/run/servik-agent/agent.sock
```
