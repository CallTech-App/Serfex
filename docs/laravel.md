# Laravel toolkit

Serfex treats a Laravel application as more than files on a site: it builds
releases, keeps them for rollback, runs the queues, the scheduler and
long-running processes as managed services, and gives developers their own
access without a server account.

**Laravel** in the sidebar lists the applications with their branch, last
deployment and queue status. An application's page has a left menu in three
groups:

- **Application**: Overview, Deployments, Previews, Environment (.env)
- **Running**: Commands, Queues, Scheduler, Processes
- **Maintenance**: Logs, Maintenance mode, Backups, Access & API

## Installing an application

Laravel → **Install Laravel application**:

| Field | |
|-------|---|
| Site | The site it runs on; its document root becomes the application's `public/`. |
| Source | **New project** (plain Laravel, or the React, Vue or Livewire starter kit), or **From a Git repository** (`user/repository` on GitHub, GitLab or Bitbucket, or any https or SSH address). |
| Private repository | Serfex makes a read-only **deploy key** (ed25519) for the site and shows it; add it to the repository, then continue. |
| Branch | Empty: the repository's default branch. |
| PHP version | Set as the site's PHP version; Composer and Artisan use it too. |
| Database | A new MySQL, MariaDB or PostgreSQL database and user created for it (`<prefix>laravel`), an existing database, an SQLite file shared between releases, or none. `DB_*` is written to `.env`. |
| Front end | Install dependencies and build, with npm, pnpm, yarn or bun. |

**Find existing applications** scans the sites for Laravel applications that
are already there and links them.

## Deployments

Each deployment builds a new release next to the live one, so visitors never
see a half-built site:

```
/home/<user>/web/<domain>/
  releases/<n>/        one directory per release
  current -> releases/<n>
  shared/.env          linked into every release
  shared/storage/      linked into every release
```

The steps:

1. Fetch the code (`git clone` of the branch or tag, or `composer
   create-project` for a new project).
2. Link the shared `.env` and `storage`.
3. *Before the build* hook.
4. `composer install --no-dev --optimize-autoloader`; `key:generate` if
   `APP_KEY` is empty.
5. The front-end install and build, when it has a `package.json`.
6. `storage:link`, `migrate --force` (when turned on), `optimize`.
7. *Before going live* hook.
8. Switch `current` to the new release in one atomic step, reload PHP-FPM
   and restart the queue workers gracefully.
9. *After going live* hook, then the **health check**: a request to the
   health address (default `/up`) through the site's own web server. If it
   fails, `current` switches back to the previous release.

The last 5 releases are kept (changeable). **Return to this release** on any
of them switches back without downtime (database migrations are not rolled
back). **Back up the database before every deployment** is available as a
safety net. Each deployment's full log stays on the page.

### Deploying on push

Under Deployments, **Automatic deployment** gives a webhook URL:

```
https://panel.example.com:8443/hooks/deploy/<token>
```

Add it to the repository's webhooks (GitHub: content type JSON, push and pull
request events; GitLab: push and merge request events; Bitbucket: push and
pull request). A push to the application's branch deploys it; pushes to
other branches are ignored. The token in the URL is the secret, so keep it
private.

From CI, either POST to the same URL (with `?ref=<branch, tag or commit>`), or
use the command line with a deploy token (below).

## Previews

With **Pull request previews** on, every pull request (or merge request) gets
its own copy of the application at `pr-<number>.<domain>`, with its own
SQLite database (migrations run on setup), its own certificate and DNS
record, and `.env` values you set only for previews. It is updated on every
push to the pull request and deleted when the pull request closes. Pull
requests from forks are ignored. **Open a branch preview** does the same by
hand for a branch. At most 5 previews per application by default.

## Environment

The shared `.env` as a table or as text, with a search, a comparison with
`.env.example` (and a button to add what is missing), and a history of every
save that can be restored. Saving rebuilds the configuration cache and
restarts the workers.

- **Connect Redis** starts a Redis instance just for this application (its
  own password, 128 MB, running as the site's user) and writes the `REDIS_*`
  values.
- **Create and connect a database** does the same for a database.

## Commands

Run Artisan, Composer or the package manager's commands as the site's user
with live output, save the ones you use often, and see recent runs.
**Tinker** opens an interactive session inside the application: variables are
kept between inputs, and what you write runs against the real database.

## Queues

Queue workers as systemd services running as the site's user: name, the
queues in order (`high,default`), the connection, how many processes, and
timeout, tries, backoff, sleep, max jobs, max time and memory. **Failed jobs**
lists them with retry and delete. Workers restart gracefully on every
deployment.

## Scheduler

Turns Laravel's `schedule:run` on every minute (a systemd timer), lists the
application's scheduled commands with their next and last run, and runs one
now. Each task has its own switch: a task turned off is skipped when it is
due, without changing the application's code (Serfex then starts
`schedule:run` through a small runner that marks those tasks as skipped).
**Run now** still runs a task that is off.

## Processes

Long-running processes, each a systemd service:

- **Horizon**: when on, it processes the queues and the workers in Queues stop.
- **Octane** with FrankenPHP, Swoole or RoadRunner: Nginx sends dynamic
  requests to it; workers, and a request limit after which a worker renews.
- **Reverb**: WebSockets, proxied by Nginx.
- **Custom**: any command, such as `php artisan mqtt:listen`.

Octane and Reverb listen on 127.0.0.1, on ports Serfex picks from 20000 to
29999.

## Logs

The application's own log files with level filters, a search and live follow.

## Maintenance mode

- **Serfex maintenance page** or **Custom HTML**: Nginx shows the page with
  503 while the application keeps running. **Allowed IP addresses** and a
  **bypass link** (a cookie for your team) see the site normally.
- **The application's own page**: runs `php artisan down` with your
  `errors/503` view; queue workers wait too.

Zero-downtime deployments do not need maintenance mode.

## Backups

The application's own backups, apart from the site's: the live release's
code (with `vendor`, without `node_modules`), `.env`, uploaded files in
`storage` (without cache, sessions and logs), and the database (mysqldump,
pg_dump or the SQLite file). Take one now or on a plan (daily or weekly, with
a number kept), download and restore in part, with a safety backup of the
current state first.

## Access & API

Give people access to one application without a server account or a hosting
login. Invite by email; they set their password from the link.

| Role | Can |
|------|-----|
| Owner | Everything, including people, API keys, restoring backups and removing the application |
| Developer | Deploy, previews, commands and Tinker, `.env`, queues, processes, taking backups, maintenance mode |
| Read-only | See status, deployments and logs; not `.env` or backups; change nothing |

Server administrators own every application. Team members see only the
applications they were added to.

**Deployment keys** (`sfx_…`) are for CI. *Deploy* keys deploy, follow
deployments and open and remove previews; *Developer* keys can also use
`.env`, commands, queues, maintenance mode and logs.

### GitHub Actions example

```yaml
- name: Deploy
  run: |
    curl -fsSL https://panel.example.com:8443/cli/install.sh | sh
    serfex deploy --ref "$GITHUB_SHA" --wait
  env:
    SERFEX_URL: https://panel.example.com:8443
    SERFEX_TOKEN: ${{ secrets.SERFEX_TOKEN }}
```

`--wait` follows the deployment and fails the job if it fails. See the
[command line reference](cli.md) and the [API](api.md).
