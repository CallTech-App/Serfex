# Servik

Servik is an open-source web hosting control panel for Ubuntu servers, with a
first-class toolkit for deploying and running Laravel applications.

> **Status:** early development. Nothing here is ready for production servers yet.

## Architecture

Servik is split into two programs that live in this repository:

| Directory | What it is | Runs as |
|-----------|------------|---------|
| [`panel/`](panel) | The web panel: a Laravel 13 application with a Livewire UI, backed by its own PostgreSQL database. | Unprivileged user |
| [`agent/`](agent) | `servik-agent`: a single static Go binary that performs root-level server work (web server, PHP-FPM, DNS, mail, certificates, system users). | `root`, under systemd |

The panel never runs as root. It sends requests to the agent over a local Unix
socket (`/run/servik-agent/agent.sock`) using JSON-RPC 2.0. The socket is
readable only by root and the panel's group.

The panel's own services (PHP, web server, PostgreSQL, Redis) are kept separate
from the services that customer sites use.

Other directories:

- [`design/`](design) holds the approved HTML mockups of the panel UI.

## Requirements

- Target server: Ubuntu Server 24.04 LTS or newer.
- Panel: PHP 8.5 in production (8.3+ works for development), Composer, Node.js 22+, PostgreSQL 16+.
- Agent: Go 1.24+.

## Development

### Panel

```sh
cd panel
composer install
cp .env.example .env
php artisan key:generate
# create a PostgreSQL database and user, then set DB_* in .env
php artisan migrate
npm install && npm run build
php artisan servik:admin   # create the administrator account
php artisan serve
php artisan queue:work     # in a second terminal: runs site jobs
php artisan queue:work backups --queue=backups  # backups and restores, which run for hours
php artisan schedule:work  # in a third terminal: certificate renewals
```

In production the scheduler runs from cron (`* * * * * php artisan schedule:run`).

With a dry-run agent, certificates come from a simulated CA. Set
`SERVIK_SSL_CHECK_DNS=false` in `panel/.env` so the panel does not wait for
public DNS to point at your machine before requesting them, and
`SERVIK_MAIL_CHECK_DNS=false` to skip the public lookups of mail records.

Without root, run the agent in dry-run mode: it keeps every server change
under a scratch directory and only logs the commands it would run.

```sh
../agent/bin/servik-agent -dry-run -root /tmp/servik-root -socket /tmp/servik-agent.sock
# and in panel/.env: SERVIK_AGENT_SOCKET=/tmp/servik-agent.sock
```

Servik AI, the built-in assistant, calls the Claude API with a key the
administrator enters under Servik AI → Ayarlar; the key is stored encrypted
with the panel's `APP_KEY`. Its answers run on the queue, so keep a worker
running (`php artisan queue:work`) while you try it. It reads panel state
through the panel's own services and changes things only through them; it has
no shell and never talks to the agent directly. `SERVIK_AI_BASE_URL` points it
at another API endpoint, for example a proxy.

Customers sign in to the same panel and see only their own sites, DNS, mail,
certificates and Laravel applications, within their package. Disk usage is
measured by the agent every 30 minutes (`servik:disk-usage`) and traffic is
added up every minute with the monitor samples, so keep the scheduler running
(`php artisan schedule:work`) to see quotas fill.

Backups (Yedekler) run on their own queue so a long backup never holds up site
jobs; keep the `backups` worker above running. The scheduler starts scheduled
backups (`servik:backups`, every five minutes). Archives copied to the server
by hand go in `/var/lib/servik/backups/import/` and are listed under Yedekler →
İçe aktar. cPanel and Plesk backups cannot be imported. To rebuild a server
from a whole-server backup, install Servik, the same database servers and mail,
list the backup (İçe aktar, or scan its target), then run:

```sh
php artisan servik:restore-server            # lists the whole-server backups
php artisan servik:restore-server KEY --panel --sites
```

Tests need a PostgreSQL database named `servik_test` (see `panel/phpunit.xml`).
The Servik AI tests answer with a fake Claude API, so they need no key:

```sh
php artisan test
```

### Agent

```sh
cd agent
go test ./...
CGO_ENABLED=0 go build -o bin/servik-agent ./cmd/servik-agent
sudo ./bin/servik-agent -socket /run/servik-agent/agent.sock
```

The agent's provisioning test against real Nginx and PHP-FPM needs root and
changes the machine, so run it only on a throwaway server or VM:

```sh
sudo go test -tags realhost -run TestRealHost ./internal/site
```

The backup test backs a real site (files, a MySQL database and a mailbox) up
to local disk, S3-compatible storage and SFTP on the same machine, destroys
it and restores it. Point it at MinIO, or it uses an in-process S3:

```sh
sudo env "PATH=$PATH" SERVIK_S3_ENDPOINT=http://127.0.0.1:9000 SERVIK_S3_ACCESS_KEY=... SERVIK_S3_SECRET_KEY=... \
  go test -tags realhost -run TestRealHost ./internal/backup
```

The panel's own real-server test (a customer's site, disk quota and
suspension) talks to an agent running as root, on the same kind of throwaway
machine with Nginx and PHP-FPM 8.3:

```sh
sudo env "PATH=$PATH" SERVIK_REALHOST=1 SERVIK_AGENT_SOCKET=/run/servik-agent/agent.sock php artisan test --testsuite=RealHost
```

To run the panel's agent integration tests against a real agent binary:

```sh
SERVIK_AGENT_BIN=../agent/bin/servik-agent php artisan test --testsuite=Integration
```

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

Servik is licensed under the [GNU Affero General Public License v3.0](LICENSE).
