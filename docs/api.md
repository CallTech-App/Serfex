# API reference

The panel's REST API lives under `https://<panel>:8443/api/v1`. The `serfex`
command line uses it, and so can your billing system, scripts or monitoring.

## Authentication

Send a key as a bearer token:

```sh
curl -H "Authorization: Bearer sfx_panel_..." https://panel.example.com:8443/api/v1/sites
```

There are three kinds of keys:

| Key | Made under | Acts as |
|-----|------------|---------|
| **Panel API key** (`sfx_panel_…`) | Settings → API keys (full administrators) | The full administrator who made it. *Read* keys may only `GET`; *write* keys may change things. Optional address allowlist and expiry (one year by default). |
| **Command line key** (`sfx_panel_…`) | `serfex login`, approved in the browser; listed under Account security | Its person, within their role: a customer reaches only their own hosting, a read-only administrator changes nothing. Valid 365 days. |
| **Application token** (`sfx_…`) | Laravel → the application → Access & API | One Laravel application only. *Deploy* tokens deploy and manage previews; *Developer* tokens also use `.env`, commands, queues, maintenance and logs. |

Only a hash of each key is stored; a key is shown once when it is made.

## Conventions

- Requests and responses are JSON. Successful responses carry `data`; most
  changes also carry a human-readable `message`.
- Messages follow `Accept-Language` (`tr` or `en`).
- Long operations answer `202 Accepted` and run in the background; follow
  them with the `GET` endpoint named in the response, or a run's log.
- Errors are `{"message": "..."}` with:

  | Status | |
  |--------|---|
  | 401 | No key, an unknown, revoked or expired key |
  | 403 | Not allowed: read-only key, address not in the allowlist, administrators only, a feature the plan does not include |
  | 404 | Not found, or not yours |
  | 409 | Not possible now (a deployment already running, the server is busy) |
  | 422 | Validation failed; `errors` lists the fields |
  | 429 | Over 60 requests a minute; `Retry-After` says when to try again |

Example response:

```json
GET /api/v1/sites/example.com

{"data": {"id": 1, "domain": "example.com", "aliases": ["www.example.com"], "preferred_host": "bare",
  "status": "active", "php_version": "8.4", "web_server": "nginx",
  "account": "example", "customer": {"id": 1, "name": "Example Ltd"},
  "https": true, "https_redirect": true,
  "certificate_expires_at": "2027-01-18T11:53:10+00:00",
  "disk_bytes": 16384, "last_error": null,
  "created_at": "2026-10-20T11:53:09+00:00"}}
```

## Who am I

| | |
|-|-|
| `GET /me` | The key's person, role, customer, application and the server's version |
| `DELETE /me` | Revokes the command line key making the request (`serfex logout`) |

## Laravel applications

For application tokens and for people's keys within their role on the
application. `{domain}` is the application's site.

| | Body / query | Needs |
|-|--------------|-------|
| `GET /apps` | | any key |
| `GET /apps/{domain}` | | view |
| `POST /apps/{domain}/deploy` | `ref` (branch, tag or commit), `migrate`, `frontend` | deploy |
| `GET /apps/{domain}/deployments` | `?limit=` | view |
| `GET /apps/{domain}/deployments/{number}` | | view |
| `POST /apps/{domain}/rollback` | `to` (deployment number; default the previous) | deploy |
| `POST /apps/{domain}/previews` | `branch` | deploy |
| `DELETE /apps/{domain}/previews/{name}` | | deploy |
| `GET /apps/{domain}/runs/{run}` | `?offset=`: the next part of a deployment's or command's log, with `done` | view |
| `GET /apps/{domain}/env` | | operate |
| `PUT /apps/{domain}/env` | `content`: the whole `.env` | operate |
| `PATCH /apps/{domain}/env` | `set` {key: value}, `unset` [keys] | operate |
| `POST /apps/{domain}/commands` | `tool`: `artisan`, `composer`, `npm`, `pnpm`, `yarn` or `bun`; `command` | operate, and the plan's shell feature for customers |
| `GET /apps/{domain}/commands/{id}` | | view |
| `GET /apps/{domain}/queues` | | view |
| `GET /apps/{domain}/queues/failed` | | view |
| `POST /apps/{domain}/queues/restart` | | operate |
| `POST /apps/{domain}/queues/retry` | `id` (empty: all) | operate |
| `GET /apps/{domain}/maintenance` | | view |
| `POST /apps/{domain}/maintenance` | `down`, `mode` (`serfex`, `custom`, `app`), `allow` [addresses], `secret`, `retry`, `html` | operate |
| `GET /apps/{domain}/logs` | `?level=&search=&file=&limit=` | view |

*view* is any token or member of the application; *deploy* a deploy token or
a developer; *operate* a developer token or a developer.

Deploying from CI:

```sh
curl -X POST -H "Authorization: Bearer $SERFEX_TOKEN" -H "Content-Type: application/json" \
  -d "{\"ref\": \"$GITHUB_SHA\"}" https://panel.example.com:8443/api/v1/apps/api.example.com/deploy
```

The push webhook (`POST /hooks/deploy/<token>`) is described in [Laravel](laravel.md#deploying-on-push).

## Server, customers and services

Administrators only. Read-only administrators may only `GET`.

| | Body / query |
|-|--------------|
| `GET /server` | Host name, panel address and port, version, time zone, counts of sites and customers, and the open problems |
| `GET /customers` | |
| `POST /customers` | `name`, `email`, `username` (the system user), `company`, `phone`, `package_id`. Returns the customer with an `invitation_url` to send them. |
| `GET /customers/{id}` | With usage against the plan's limits |
| `POST /customers/{id}/suspend` | `reason` (not shown to the customer) |
| `POST /customers/{id}/unsuspend` | |
| `GET /packages` | The plans and their limits |
| `GET /services` | |
| `GET /services/{unit}` | |
| `GET /services/{unit}/logs` | `?lines=` |
| `POST /services/{unit}/{start\|stop\|restart}` | |

## Hosting

Administrators reach everything; customers their own hosting, within their
plan. DNS, mail and backups need the plan's feature.

### Sites and domains

| | Body / query |
|-|--------------|
| `GET /sites` | `?customer=<id>` |
| `POST /sites` | `domain`, `php_version`, `www` (default true), `preferred_host` (`bare` = without www, the default; `www`; `none` = both open the site), `customer_id`, `account` (system user). `202`: the site is being prepared. |
| `GET /sites/{domain}` | |
| `DELETE /sites/{domain}` | Removes the site with its email, databases (after a dump) and system user, after a backup of the whole site kept in Backups. `?keep_dns=1` keeps the DNS zone; `?backup=0` (administrators) skips the backup. |
| `GET /sites/{domain}/domains` | Subdomains, aliases and redirects |
| `POST /sites/{domain}/subdomains` | `name`, `root` (folder in the site) |
| `POST /sites/{domain}/aliases` | `name`, `dns` (create a zone) |
| `POST /sites/{domain}/redirects` | `source` (a domain, or a path starting with `/`), `target`, `code` (301 or 302), `keep_path`, `www`, `dns` |
| `DELETE /sites/{domain}/domains` | `?name=` the subdomain, alias or redirect source |

### Certificates

| | Body |
|-|------|
| `GET /certificates` | |
| `GET /sites/{domain}/ssl` | |
| `POST /sites/{domain}/ssl` | `wildcard`, `only_domain` |
| `POST /sites/{domain}/ssl/renew` | |
| `PUT /sites/{domain}/ssl/https` | `redirect` |

### DNS

| | Body |
|-|------|
| `GET /dns/zones` | |
| `GET /dns/zones/{zone}` | With its records |
| `POST /dns/zones/{zone}/records` | `type` (`A`, `AAAA`, `CNAME`, `MX`, `TXT`, `NS`, `SRV`, `CAA`), `name` (empty: the zone itself), `content`, `priority`, `ttl` (60–604800) |
| `DELETE /dns/zones/{zone}/records/{id}` | |

### Mail

| | Body |
|-|------|
| `GET /mail/domains` | |
| `GET /mail/domains/{domain}` | Mailboxes and forwarders |
| `POST /mail/domains` | `name`: turns email on for a domain |
| `POST /mail/domains/{domain}/mailboxes` | `name`, `password` (empty: generated and returned once), `quota_mb` |
| `PATCH /mail/domains/{domain}/mailboxes/{name}` | `password`, `generate`, `quota_mb` |
| `DELETE /mail/domains/{domain}/mailboxes/{name}` | |
| `PUT /mail/domains/{domain}/forwarders/{name}` | `to` (addresses). `{name}` `*` is the catch-all. |
| `DELETE /mail/domains/{domain}/forwarders/{name}` | |

### Databases

| | Body / query |
|-|--------------|
| `GET /databases` | `?site=&server=` |
| `POST /databases` | `site`, `name`, `user`, `password` (empty: generated and returned once), `access` (`all`, `readwrite`, `readonly`), `server` (`mysql` or `postgresql`) |
| `GET /databases/{name}` | |
| `DELETE /databases/{name}` | A dump is kept for 30 days |

### Backups

| | Body / query |
|-|--------------|
| `GET /backups` | `?site=&limit=` |
| `POST /backups` | `site` (empty: the whole server, administrators only) |
| `GET /backups/{id}` | |
| `POST /backups/{id}/restore` | `site`, `parts` (`files`, `databases`, `mail`, `dns`, `certificates`), `safety` (back up the current state first; default true) |
| `GET /restores/{id}` | |

## Command line sign-in

`POST /cli/login` and `POST /cli/login/token` are the browser-approved
sign-in that `serfex login` uses; you do not call them yourself.
