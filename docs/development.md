# Development

How to run Serfex from a checkout, and the tests that guard it. Read
[CONTRIBUTING.md](../CONTRIBUTING.md) for the ground rules and
[Architecture](architecture.md) for how the pieces fit.

You need PHP 8.3 or newer (production runs 8.5), Composer, Node.js 22 or
newer, PostgreSQL 16 or newer and Go 1.26 or newer (the toolchain line in
go.mod fetches the version releases are built with). You do not need root:
the agent has a dry-run mode that keeps every server change under a scratch
directory.

## Panel

```sh
cd panel
composer install
cp .env.example .env
php artisan key:generate
# create a PostgreSQL database and user, then set DB_* in .env
php artisan migrate
npm install && npm run build
php artisan serfex:admin   # create the administrator account
php artisan serve
php artisan queue:work     # in a second terminal: runs site jobs
php artisan queue:work backups --queue=backups  # backups and restores, which run for hours
php artisan schedule:work  # in a third terminal: certificate renewals
```

In production the scheduler runs from cron (`* * * * * php artisan schedule:run`).

With a dry-run agent, certificates come from a simulated CA. Set
`SERFEX_SSL_CHECK_DNS=false` in `panel/.env` so the panel does not wait for
public DNS to point at your machine before requesting them, and
`SERFEX_MAIL_CHECK_DNS=false` to skip the public lookups of mail records.

Without root, run the agent in dry-run mode: it keeps every server change
under a scratch directory and only logs the commands it would run.

```sh
../agent/bin/serfex-agent -dry-run -root /tmp/serfex-root -socket /tmp/serfex-agent.sock
# and in panel/.env: SERFEX_AGENT_SOCKET=/tmp/serfex-agent.sock
```

Serfex AI, the built-in assistant, calls the Claude API with a key the
administrator enters under Serfex AI → Ayarlar; the key is stored encrypted
with the panel's `APP_KEY`. Its answers run on the queue, so keep a worker
running (`php artisan queue:work`) while you try it. It reads panel state
through the panel's own services and changes things only through them; it has
no shell and never talks to the agent directly. `SERFEX_AI_BASE_URL` points it
at another API endpoint, for example a proxy.

Customers sign in to the same panel and see only their own sites, DNS, mail,
certificates and Laravel applications, within their package. Disk usage is
measured by the agent every 30 minutes (`serfex:disk-usage`) and traffic is
added up every minute with the monitor samples, so keep the scheduler running
(`php artisan schedule:work`) to see quotas fill.

Backups (Yedekler) run on their own queue so a long backup never holds up site
jobs; keep the `backups` worker above running. The scheduler starts scheduled
backups (`serfex:backups`, every five minutes). Archives copied to the server
by hand go in `/var/lib/serfex/backups/import/` and are listed under Yedekler →
İçe aktar. cPanel and Plesk backups cannot be imported. To rebuild a server
from a whole-server backup, install Serfex, the same database servers and mail,
list the backup (İçe aktar, or scan its target), then run:

```sh
php artisan serfex:restore-server            # lists the whole-server backups
php artisan serfex:restore-server KEY --panel --sites
```

Tests need a PostgreSQL database named `serfex_test` (see `panel/phpunit.xml`).
The Serfex AI tests answer with a fake Claude API, so they need no key:

```sh
php artisan test
```

## Agent

```sh
cd agent
go test ./...
CGO_ENABLED=0 go build -o bin/serfex-agent ./cmd/serfex-agent
sudo ./bin/serfex-agent -socket /run/serfex-agent/agent.sock
```

The agent's provisioning test against real Nginx and PHP-FPM needs root and
changes the machine, so run it only on a throwaway server or VM:

```sh
sudo go test -tags realhost -run TestRealHost ./internal/site
```

The backup test backs a real site (files, a MySQL database and a mailbox) up
to local disk, S3-compatible storage and SFTP on the same machine, destroys
it and restores it. Point it at any S3-compatible server (CI uses moto's), or
it uses an in-process S3:

```sh
sudo env "PATH=$PATH" SERFEX_S3_ENDPOINT=http://127.0.0.1:9000 SERFEX_S3_ACCESS_KEY=... SERFEX_S3_SECRET_KEY=... \
  go test -tags realhost -run TestRealHost ./internal/backup
```

The panel's own real-server test (a customer's site, disk quota and
suspension) talks to an agent running as root, on the same kind of throwaway
machine with Nginx and PHP-FPM 8.3:

```sh
sudo env "PATH=$PATH" SERFEX_REALHOST=1 SERFEX_AGENT_SOCKET=/run/serfex-agent/agent.sock php artisan test --testsuite=RealHost
```

To run the panel's agent integration tests against a real agent binary:

```sh
SERFEX_AGENT_BIN=../agent/bin/serfex-agent php artisan test --testsuite=Integration
```

## Command line

```sh
cd cli
go test ./...
go build -o bin/serfex ./cmd/serfex
./bin/serfex login http://localhost:8000
```

The end-to-end run CI does against a real panel and agent (a site created,
served by Nginx and deleted through `serfex`) needs the same throwaway machine
as the agent's real-server tests: `scripts/ci/cli-e2e.sh`.

## Translations

The panel's text is written in Turkish inside `__()` and translated in
`panel/lang/en.json`; the agent and the command line keep their English in
`internal/i18n/catalog.go`. Check all three before a pull request:

```sh
cd panel
php artisan serfex:lang-check --agent=../agent
```

The Go catalog tests (`go test ./internal/i18n` in `agent/` and `cli/`) fail
on a message without English, an unused entry, or Turkish written outside
`T()`.

## Command line reference

[`docs/cli.md`](cli.md) and [`docs/tr/cli.md`](tr/cli.md) are generated from
the command tree. After changing a command, an option or a help text:

```sh
cd cli
go test ./internal/app -run TestReferenceIsCurrent -update
```

## Releases

A pushed `v*` tag, or running the Release workflow on `main` from the
Actions tab with a version, runs [`.github/workflows/release.yml`](../.github/workflows/release.yml),
which builds with [`scripts/build-release.sh`](../scripts/build-release.sh) and
publishes a GitHub Release with the notes in `release-notes/<version>.md`
(see [release-notes/README.md](../release-notes/README.md)). To try the build
locally (it needs the tools above and network access for Composer and npm):

```sh
SERFEX_ARCHES=amd64 scripts/build-release.sh 0.1.0 dist
```
