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
| Private repository | Serfex makes a read-only **deploy key** (ed25519) for the site and shows it; add it to the repository, then continue. With a connected account (below) the repository and branch are picked from lists and Serfex adds the key and the webhook itself. |
| Branch | Empty: the repository's default branch. |
| PHP version | Set as the site's PHP version; Composer and Artisan use it too. |
| Database | A new MySQL, MariaDB or PostgreSQL database and user created for it (`<prefix>laravel`), an existing database, an SQLite file shared between releases, or none. `DB_*` is written to `.env`. |
| Front end | Install dependencies and build, with npm, pnpm, yarn or bun. |

When an administrator has set up GitHub, GitLab or Bitbucket under Tools &
Settings → Git providers, choosing that provider asks you to connect your
account once (your name → Git accounts) and then lists your repositories.
The application is fetched with its deploy key, which Serfex adds to the
repository together with the deploy webhook (push and pull request events);
**Type the repository address** goes back to the field. When the provider
refuses the key (you are not an administrator of the repository), it is shown
to add by hand.

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
2. Link the shared `.env` and `storage`. A live release whose `.env` was
   turned into a plain file by a hand edit (`sed -i`, an editor) gives it
   back to `shared/.env` first; the replaced one is kept as
   `shared/.env.old`.
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

A repository picked through a connected account already has it. Otherwise
add it to the repository's webhooks (GitHub: content type JSON, push and pull
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

### Mail

A site may send mail only as its own domains (the site's name, aliases,
subdomains, redirected domains and mail domain): through `sendmail` as the
site's user, or with a mailbox login on port 587. SMTP to `127.0.0.1:25`
without a login is refused for hosted domains, since Postfix cannot tell
which site connected.

Laravel's `.env.example` sends nothing from here (`MAIL_MAILER=log`, or SMTP
to `127.0.0.1:2525`, and `hello@example.com`). So when the mail server is set
up, Serfex writes `MAIL_MAILER=sendmail` and
`MAIL_FROM_ADDRESS=noreply@<domain>` after an install, from a template too.
It changes only values that cannot work: a real SMTP server with a login, or
a mail API such as SES or Postmark, is kept (only an example sender address
is replaced). The change is in the history like any save.

For an application whose settings cannot send, Environment and Overview
show what is wrong and a **Fix** button that writes the same values (with
Undo). Sites of Laravel applications keep `proc_open`, which Laravel's
sendmail transport needs, among the functions customer sites run without.
Failed sends in the logs get a plain explanation.

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
A failed send (a refused sender address, SMTP to this server without a login)
gets a line saying what it means and a link to fix the mail settings.

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

## Application templates

Tools & Settings → **Application templates** (full administrators) describes
your own Laravel application once, so that it can be installed on any site in
one step and new versions rolled out to every site that runs it. Templates are
data in the panel's database on this server; nothing about them is in the
Serfex repository. Each install is separate: its own code, database and `.env`.

A template takes its code from one of two sources:

| Source | |
|--------|---|
| **Git repository** (Serfex installs and updates) | A repository picked from a connected Git account, or a public address, at a **tag** or a **branch**. Each install is an ordinary Laravel deployment (releases, rollback, workers). For a private repository Serfex creates a read-only deploy key per site and adds it through the template's account. |
| **Release package** (the application updates itself) | A ready zip (with `vendor/` and the built front end): the newest GitHub release's asset matching a name pattern (`*.zip`), or a fixed https address. It is unpacked into the site's `app/` folder, the site root becomes `app/public`, and Serfex never deploys it again: the application updates itself from its own panel. The **Sites** tab shows the version each site reports from its `VERSION` file. |

The template's page has four tabs:

- **General**: name and description, source and version, PHP version, database
  (a new MySQL or PostgreSQL database named `<prefix>_<template>`, SQLite, or
  none), front-end build, queue worker, scheduler, update behaviour and who may
  install it. **Customers can install it on their own sites too** is off by
  default; customers only ever see the name and description.
- **Environment (.env)**: lines written on top of `.env.example` at install
  (empty: the application's own installer writes `.env`), and up to 10
  **install questions**: text, secret text or a file (at most 256 KB, written to
  `storage/app/serfex/`, a licence file for example). Updates never touch `.env`.
- **Setup steps**: one-time commands after the first install, one per line,
  starting with `php artisan`, `composer`, `npm`, `pnpm`, `yarn` or `bun` (at
  most 20). They run in order as the site's user and stop at the first failure.
  **Create the first administrator** asks for a name and e-mail on the install
  form; Serfex generates the password, shows it once to whoever started the
  install, hides it in the log and forgets it when setup finishes. Git
  templates also have their deploy hooks and migrations here.
- **Sites**: the sites running the template with their version, **Update** for
  one site, **Update all to …** for every site behind (one after the other,
  optionally stopping at the first failure) and **Install on a site**.

Placeholders work in the `.env` lines and the commands:

| Placeholder | |
|-------------|---|
| `{site.domain}`, `{site.url}`, `{site.path}` | The site's name, address (https once it has a certificate) and the application's folder. |
| `{customer.name}`, `{customer.email}` | The site's customer. |
| `{db.connection}`, `{db.host}`, `{db.port}`, `{db.name}`, `{db.user}`, `{db.password}` | The install's database. |
| `{admin.name}`, `{admin.email}`, `{admin.password}` | The first administrator. |
| `{ask.KEY}`, `{file.KEY}` | An answer, or the full path of an uploaded file. |
| `{random:N}` | N random letters and digits (8 to 64). |

Database passwords, the administrator's password and secret answers are
replaced with `••••••` in the install log. Answers are stored encrypted until
setup has run.

**Installing.** Sites → **Add site** → **Application** creates the site, gets
its certificate and then installs the template; Laravel → **Install Laravel
application** → **From a template** installs on an existing site. Progress is
shown live on the application's page; a failed install keeps its answers so
**Try again** can repeat it.

**Updating** a Git template: change its tag (or push to its branch), then
update from the Sites tab. Each site gets a database backup, a maintenance page
(the health check is let through), the new release with migrations, and goes
back to the previous release when it does not come up.

**Export / Import** moves a template between panels as JSON. The Git account
and linked sites are left out; the `.env` lines are included. An imported
template is never open to customers until you say so.

Example, a self-updating application installed from its release package
(setup command on one line):

```
php artisan app:install --no-interaction --url={site.url} --db-database={db.name} --db-username={db.user} --db-password={db.password} --admin-email={admin.email} --admin-password={admin.password} --license={file.LICENSE}
```

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
