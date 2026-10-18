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

Tests need a PostgreSQL database named `servik_test` (see `panel/phpunit.xml`):

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

To run the panel's agent integration tests against a real agent binary:

```sh
SERVIK_AGENT_BIN=../agent/bin/servik-agent php artisan test --testsuite=Integration
```

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

Servik is licensed under the [GNU Affero General Public License v3.0](LICENSE).
