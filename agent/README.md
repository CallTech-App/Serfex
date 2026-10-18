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

Errors: `-32602` means the request can never work as sent (bad domain,
reserved account name, PHP version not installed); `-32000` means a valid
change failed on the server, and the message says why (for example the
output of `nginx -t`).

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

## Dry run

`-dry-run` swaps the real system for a simulator: files go under `-root`
(default: a new temporary directory), `useradd`/`userdel` edit
`<root>/etc/passwd`, and service commands are only logged. The panel's
integration tests and local development use this mode.

```sh
./bin/servik-agent -dry-run -root /tmp/servik-root -socket /tmp/servik-agent.sock
```

Example:

```sh
echo '{"jsonrpc":"2.0","id":1,"method":"agent.info"}' | sudo socat - UNIX-CONNECT:/run/servik-agent/agent.sock
```
