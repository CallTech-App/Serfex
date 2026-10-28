<!-- This file is generated: cd cli && go test ./internal/app -run TestReferenceIsCurrent -update -->

# Command line reference

The Serfex command line: sites, domains, DNS, SSL, mail, databases, backups, services and Laravel applications.

First sign in to a panel: serfex login panel.example.com:8443

Every command also shows its own help with `--help`: `serfex sites create --help`.

## Global options

These options work with every command.

| Option | |
| --- | --- |
| `--profile <name>` | The profile to use (default: the last signed in; SERFEX_PROFILE) |
| `--json` | Prints the output as JSON |
| `--lang <tr\|en>` | Language (default: the system's; SERFEX_LANG) |
| `--url <address>` | Panel address (SERFEX_URL) |
| `--token <key>` | API key or application token (SERFEX_TOKEN) |
| `-y, --yes` | Does it without asking |
| `--no-color` | Output without colors (NO_COLOR) |
| `-h, --help` | Shows the help |

## Account

### serfex login

```
serfex login [panel-address] [options]
```

Signs in to a Serfex panel. An approval page opens in the browser; once you approve, a key is made for this computer and listed under Account security.

For CI or scripts, give an API key or a token from an application's Access and API section with --token. On the server itself, as root, --local signs in without a browser.

| Option | |
| --- | --- |
| `--local` | On the server as root, without a browser |
| `--email <email>` | --local: the administrator the key acts as (default: the first full administrator) |
| `--read-only` | --local: a key that can only read |
| `--fingerprint <sha256>` | The expected SHA-256 fingerprint of the panel's certificate |
| `--no-browser` | Does not open the browser; only prints the link |

Examples:

```sh
serfex login panel.ornek.com.tr:8443
serfex login https://panel.ornek.com.tr:8443 --token sfx_…
sudo serfex login --local
```

### serfex setup-url

```
serfex setup-url
```

Makes a one-time setup link for a panel that has no administrator yet. Whoever opens it creates the first administrator account and goes on to the setup wizard. The link works for 24 hours; a new one stops the old one.

install.sh prints the first link at the end of the installation. Runs on the server itself as root.

Examples:

```sh
sudo serfex setup-url
```

### serfex reset-password

```
serfex reset-password <email>
```

Makes a one-time password reset link for an administrator, customer or team member account. Whoever opens it sets a new password; two-step verification, when it is on, is still asked at sign-in. The link works for 60 minutes; a new one stops the old one.

Use it when the panel cannot send mail or the mail does not arrive. Runs on the server itself as root.

Examples:

```sh
sudo serfex reset-password ayse@ornek.com.tr
```

### serfex logout

```
serfex logout
```

Signs out of the profile and closes its key on the panel

### serfex profile

```
serfex profile <command>
```

Lists the panels you are signed in to and switches between them

Also called: `profiles`

| Command | |
| --- | --- |
| [`list`](#serfex-profile-list) | Lists the profiles |
| [`use`](#serfex-profile-use) | Changes the default profile |
| [`remove`](#serfex-profile-remove) | Deletes the profile and closes its key on the panel |

### serfex profile list

```
serfex profile list
```

Lists the profiles

Also called: `ls`

### serfex profile use

```
serfex profile use <name>
```

Changes the default profile

### serfex profile remove

```
serfex profile remove <name>
```

Deletes the profile and closes its key on the panel

Also called: `rm`

### serfex whoami

```
serfex whoami
```

Shows who you are signed in as, and on which panel

### serfex status

```
serfex status
```

Shows the server's state and what needs attention

### serfex fingerprint

```
serfex fingerprint [panel-address]
```

Prints the SHA-256 fingerprint of the certificate the panel shows. Run it on the server without an address and compare it with what your computer shows at the first sign-in.

## Hosting

### serfex sites

```
serfex sites <command>
```

Lists, creates and deletes sites

Also called: `site`

| Command | |
| --- | --- |
| [`list`](#serfex-sites-list) | Lists the sites |
| [`show`](#serfex-sites-show) | Shows a site's details |
| [`create`](#serfex-sites-create) | Creates a new site |
| [`delete`](#serfex-sites-delete) | Deletes a site with its files, databases and mail |

### serfex sites list

```
serfex sites list
```

Lists the sites

Also called: `ls`

### serfex sites show

```
serfex sites show <site>
```

Shows a site's details

### serfex sites create

```
serfex sites create <domain> [options]
```

Creates a new site: system user, web folder, web server configuration and DNS zone, as in the panel.

Also called: `add`

| Option | |
| --- | --- |
| `--php <version>` | PHP version (default: the server's) |
| `--www` | The www. name opens too (default: yes; --no-www to turn it off) |
| `--customer <id>` | The customer the site belongs to (administrators) |
| `--account <name>` | The system user's name (default: from the domain) |
| `--wait` | Waits until the site is ready |

Examples:

```sh
serfex sites create kahveatolyesi.com.tr --php 8.4 --wait
```

### serfex sites delete

```
serfex sites delete <site> [options]
```

Deletes a site with its files, databases and mail

Also called: `rm`

| Option | |
| --- | --- |
| `--keep-dns` | Keeps the DNS zone |
| `--no-backup` | Skips the backup taken before deleting (administrators only) |

### serfex domains

```
serfex domains <command>
```

Subdomains, additional domains and redirects

Also called: `domain`

| Command | |
| --- | --- |
| [`list`](#serfex-domains-list) | Lists a site's other names |
| [`add`](#serfex-domains-add) | Adds a subdomain (blog) or an additional domain (example.net) |
| [`redirect`](#serfex-domains-redirect) | Redirects a path (/old) or a domain to another address |
| [`remove`](#serfex-domains-remove) | Removes a subdomain, an additional domain or a redirect |

### serfex domains list

```
serfex domains list <site>
```

Lists a site's other names

Also called: `ls`

### serfex domains add

```
serfex domains add <site> <name> [options]
```

Adds a name to a site. A name without a dot or under the site (blog, blog.example.com) is a subdomain with its own folder; another domain (example.net) is an additional domain that opens the same site. DNS and Let's Encrypt are set up for you.

| Option | |
| --- | --- |
| `--root <folder>` | The subdomain's folder, relative to the site's (default: subdomains/<name>) |
| `--dns` | Creates a DNS zone for an additional domain (default: yes) |

Examples:

```sh
serfex domains add ornek.com.tr blog
serfex domains add ornek.com.tr ornek.net --no-dns
```

### serfex domains redirect

```
serfex domains redirect <site> <source> <target> [options]
```

Redirects a path (/old) or a domain to another address

| Option | |
| --- | --- |
| `--code <301\|302>` | Redirect code (default: 301) |
| `--keep-path` | Keeps the path in a domain redirect |
| `--www` | Adds the www. name to a domain redirect |
| `--dns` | Creates a DNS zone for the redirected domain (default: yes) |

Examples:

```sh
serfex domains redirect ornek.com.tr /eski https://ornek.com.tr/yeni
serfex domains redirect ornek.com.tr eskiad.com https://ornek.com.tr --keep-path
```

### serfex domains remove

```
serfex domains remove <site> <name>
```

Removes a subdomain, an additional domain or a redirect

Also called: `rm`

### serfex dns

```
serfex dns <command>
```

DNS zones and records

| Command | |
| --- | --- |
| [`zones`](#serfex-dns-zones) | Lists the DNS zones |
| [`records`](#serfex-dns-records) | Lists a zone's records |
| [`add`](#serfex-dns-add) | Adds a record (name: @ is the zone itself) |
| [`delete`](#serfex-dns-delete) | Deletes a record (its number: serfex dns records) |

### serfex dns zones

```
serfex dns zones
```

Lists the DNS zones

Also called: `list`, `ls`

### serfex dns records

```
serfex dns records <zone>
```

Lists a zone's records

Also called: `show`

### serfex dns add

```
serfex dns add <zone> <type> <name> <value> [options]
```

Adds a record (name: @ is the zone itself)

| Option | |
| --- | --- |
| `--ttl <seconds>` | The record's TTL (default: the zone's) |
| `--priority <number>` | MX and SRV priority (default: 10) |

Examples:

```sh
serfex dns add ornek.com.tr A www 203.0.113.10
serfex dns add ornek.com.tr MX @ mail.ornek.com.tr --priority 10
serfex dns add ornek.com.tr TXT @ "v=spf1 mx -all"
```

### serfex dns delete

```
serfex dns delete <zone> <record-no>
```

Deletes a record (its number: serfex dns records)

Also called: `rm`

### serfex ssl

```
serfex ssl <command>
```

The sites' certificates and HTTPS

| Command | |
| --- | --- |
| [`list`](#serfex-ssl-list) | Lists the sites' certificates |
| [`show`](#serfex-ssl-show) | Shows a site's certificate |
| [`issue`](#serfex-ssl-issue) | Gets a Let's Encrypt certificate |
| [`renew`](#serfex-ssl-renew) | Renews the certificate now |
| [`https`](#serfex-ssl-https) | Turns redirecting HTTP requests to HTTPS on or off |

### serfex ssl list

```
serfex ssl list
```

Lists the sites' certificates

Also called: `ls`

### serfex ssl show

```
serfex ssl show <site>
```

Shows a site's certificate

### serfex ssl issue

```
serfex ssl issue <site> [options]
```

Gets a Let's Encrypt certificate

| Option | |
| --- | --- |
| `--wildcard` | For *.domain too (when DNS is on this server) |
| `--only-domain` | Without www. and the additional domains |
| `--wait` | Waits until the certificate is issued |

### serfex ssl renew

```
serfex ssl renew <site> [options]
```

Renews the certificate now

| Option | |
| --- | --- |
| `--wait` | Waits until the certificate is renewed |

### serfex ssl https

```
serfex ssl https <site> on|off
```

Turns redirecting HTTP requests to HTTPS on or off

### serfex mail

```
serfex mail <command>
```

Mail domains, mailboxes and forwarders

| Command | |
| --- | --- |
| [`domains`](#serfex-mail-domains) | Lists the domains with mail on |
| [`enable`](#serfex-mail-enable) | Turns on mail for a domain (the DNS records are written for you) |
| [`list`](#serfex-mail-list) | Lists the mailboxes and forwarders |
| [`create`](#serfex-mail-create) | Creates a mailbox |
| [`password`](#serfex-mail-password) | Changes a mailbox's password |
| [`quota`](#serfex-mail-quota) | Changes a mailbox's quota (0 is unlimited) |
| [`delete`](#serfex-mail-delete) | Deletes a mailbox |
| [`forward`](#serfex-mail-forward) | Forwards an address to other addresses (*@domain: catch-all) |
| [`unforward`](#serfex-mail-unforward) | Deletes a forwarder |

### serfex mail domains

```
serfex mail domains
```

Lists the domains with mail on

### serfex mail enable

```
serfex mail enable <domain>
```

Turns on mail for a domain (the DNS records are written for you)

### serfex mail list

```
serfex mail list <domain>
```

Lists the mailboxes and forwarders

Also called: `ls`

### serfex mail create

```
serfex mail create <address> [options]
```

Creates a mailbox

Also called: `add`

| Option | |
| --- | --- |
| `--password-stdin` | Reads the password from standard input (without it the panel makes a strong one and shows it once) |
| `--quota <MB>` | Quota in MB (default: the server's; 0 is unlimited) |

Examples:

```sh
serfex mail create siparis@ornek.com.tr
printf '%s' "$PAROLA" | serfex mail create info@ornek.com.tr --password-stdin
```

### serfex mail password

```
serfex mail password <address> [options]
```

Changes a mailbox's password

| Option | |
| --- | --- |
| `--password-stdin` | Reads the password from standard input (without it the panel makes a strong one and shows it once) |

### serfex mail quota

```
serfex mail quota <address> <MB>
```

Changes a mailbox's quota (0 is unlimited)

### serfex mail delete

```
serfex mail delete <address>
```

Deletes a mailbox

Also called: `rm`

### serfex mail forward

```
serfex mail forward <address> <target>…
```

Forwards an address to other addresses (*@domain: catch-all)

Examples:

```sh
serfex mail forward satis@ornek.com.tr ali@ornek.com.tr ayse@gmail.com
serfex mail forward '*@ornek.com.tr' info@ornek.com.tr
```

### serfex mail unforward

```
serfex mail unforward <address>
```

Deletes a forwarder

### serfex db

```
serfex db <command>
```

The sites' databases

Also called: `databases`, `database`

| Command | |
| --- | --- |
| [`list`](#serfex-db-list) | Lists the databases |
| [`create`](#serfex-db-create) | Creates a database and its user |
| [`delete`](#serfex-db-delete) | Deletes a database (backs it up first) |

### serfex db list

```
serfex db list [options]
```

Lists the databases

Also called: `ls`

| Option | |
| --- | --- |
| `--site <site>` | Only this site's databases |

### serfex db create

```
serfex db create <site> <name> [options]
```

Creates a database and a user with the same name. The site's prefix (shop_) is added to the names; without a password the panel makes a strong one and shows it once with the connection details.

Also called: `add`

| Option | |
| --- | --- |
| `--user <name>` | The user's name (default: the database's) |
| `--no-user` | Makes no user |
| `--password-stdin` | Reads the user's password from standard input |
| `--access <all\|read>` | The user's access (default: all) |
| `--server <mysql\|postgresql>` | Database server (default: the first one installed) |

Examples:

```sh
serfex db create kahveatolyesi.com.tr dukkan
```

### serfex db delete

```
serfex db delete <name> [options]
```

Deletes a database (backs it up first)

Also called: `rm`

| Option | |
| --- | --- |
| `--no-backup` | Takes no backup before deleting |

### serfex backups

```
serfex backups <command>
```

Backups and restores

Also called: `backup`

| Command | |
| --- | --- |
| [`list`](#serfex-backups-list) | Lists the backups, newest first |
| [`show`](#serfex-backups-show) | Shows what is in a backup |
| [`create`](#serfex-backups-create) | Takes a backup now: of a site or (administrators) of the whole server |
| [`restore`](#serfex-backups-restore) | Restores a site from a backup |

### serfex backups list

```
serfex backups list [options]
```

Lists the backups, newest first

Also called: `ls`

| Option | |
| --- | --- |
| `--site <site>` | Only the backups with this site |
| `-n, --limit <number>` | At most how many backups (default: 20) |

### serfex backups show

```
serfex backups show <no>
```

Shows what is in a backup

### serfex backups create

```
serfex backups create [site] [options]
```

Takes a backup now: of a site or (administrators) of the whole server

| Option | |
| --- | --- |
| `--wait` | Waits until the backup ends |

### serfex backups restore

```
serfex backups restore <no> <site> [options]
```

Restores a site from a backup. The site as it is now is backed up first, so the restore can be undone too.

| Option | |
| --- | --- |
| `--only <part>` | Only these: files, databases, mail, dns, certificates (comma separated) |
| `--no-safety` | Takes no safety backup first |
| `--wait` | Waits until the restore ends |

Examples:

```sh
serfex backups restore 42 kahveatolyesi.com.tr --only files,databases --wait
```

## Server

### serfex services

```
serfex services <command>
```

The server's services (administrators)

Also called: `service`

| Command | |
| --- | --- |
| [`list`](#serfex-services-list) | Lists the services |
| [`status`](#serfex-services-status) | Shows a service's state |
| [`start`](#serfex-services-start) | Starts the service |
| [`stop`](#serfex-services-stop) | Stops the service |
| [`restart`](#serfex-services-restart) | Tests the configuration and restarts the service |
| [`logs`](#serfex-services-logs) | The last lines of the service's log |

### serfex services list

```
serfex services list
```

Lists the services

Also called: `ls`

### serfex services status

```
serfex services status <service>
```

Shows a service's state

Also called: `show`

### serfex services start

```
serfex services start <service>
```

Starts the service

### serfex services stop

```
serfex services stop <service>
```

Stops the service

### serfex services restart

```
serfex services restart <service>
```

Tests the configuration and restarts the service

### serfex services logs

```
serfex services logs <service> [options]
```

The last lines of the service's log

| Option | |
| --- | --- |
| `-n, --lines <number>` | How many lines (default: 50) |

### serfex customers

```
serfex customers <command>
```

Customers and their packages (administrators)

Also called: `customer`

| Command | |
| --- | --- |
| [`list`](#serfex-customers-list) | Lists the customers |
| [`show`](#serfex-customers-show) | The customer's sites and package usage |
| [`suspend`](#serfex-customers-suspend) | Suspends the customer (sites answer 503, mail is held) |
| [`unsuspend`](#serfex-customers-unsuspend) | Lifts the suspension |
| [`packages`](#serfex-customers-packages) | Lists the packages |

### serfex customers list

```
serfex customers list
```

Lists the customers

Also called: `ls`

### serfex customers show

```
serfex customers show <no>
```

The customer's sites and package usage

### serfex customers suspend

```
serfex customers suspend <no> [options]
```

Suspends the customer (sites answer 503, mail is held)

| Option | |
| --- | --- |
| `--reason <text>` | The reason the customer sees |

### serfex customers unsuspend

```
serfex customers unsuspend <no>
```

Lifts the suspension

### serfex customers packages

```
serfex customers packages
```

Lists the packages

## Laravel

### serfex apps

```
serfex apps
```

Lists the Laravel applications you can reach

### serfex deploy

```
serfex deploy [options]
```

Deploys the application from its repository: the new release is installed under releases/ and goes live once it passes the health check; if it fails, the previous release stays live.

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERFEX_APP; not needed with a token) |
| `--ref <branch\|tag\|commit>` | The branch, tag or commit to deploy (default: the application's branch) |
| `--no-migrate` | Does not run migrations in this deploy |
| `--no-frontend` | Does not build the front end (npm run build) in this deploy |
| `--wait` | Shows the output until it ends; exits non-zero when it fails |
| `--timeout <duration>` | The longest wait for --wait (default: 60m) |

Examples:

```sh
serfex deploy -a api.ornek.com.tr --wait
SERFEX_URL=https://panel.ornek.com.tr:8443 SERFEX_TOKEN=sfx_… serfex deploy --ref "$GITHUB_SHA" --wait
```

### serfex deployments

```
serfex deployments [options]
```

Lists the latest deployments

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERFEX_APP; not needed with a token) |
| `-n, --limit <number>` | How many deployments (default: 10) |

### serfex rollback

```
serfex rollback [options]
```

Goes back to the previous release

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERFEX_APP; not needed with a token) |
| `--to <no>` | The number of the deployment to go back to (default: the one before) |
| `--wait` | Shows the output until it ends; exits non-zero when it fails |
| `--timeout <duration>` | The longest wait for --wait (default: 60m) |

### serfex preview

```
serfex preview <command>
```

Preview environments (<branch>.domain)

Also called: `previews`

| Command | |
| --- | --- |
| [`create`](#serfex-preview-create) | Opens a preview environment for a branch |
| [`delete`](#serfex-preview-delete) | Removes a preview environment |

### serfex preview create

```
serfex preview create <branch> [options]
```

Opens a preview environment for a branch

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERFEX_APP; not needed with a token) |

### serfex preview delete

```
serfex preview delete <name> [options]
```

Removes a preview environment

Also called: `rm`

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERFEX_APP; not needed with a token) |

### serfex env

```
serfex env [options]
```

Shows and changes the application's .env file. On save, config:cache is refreshed and the workers restart; the panel keeps every version.

| Command | |
| --- | --- |
| [`list`](#serfex-env-list) | Lists the keys and their values (secrets masked) |
| [`get`](#serfex-env-get) | Prints one value as it is |
| [`set`](#serfex-env-set) | Changes or adds values |
| [`unset`](#serfex-env-unset) | Deletes keys |
| [`pull`](#serfex-env-pull) | Downloads the .env file |
| [`push`](#serfex-env-push) | Replaces the whole .env file |

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERFEX_APP; not needed with a token) |
| `--reveal` | Shows passwords and keys unmasked |

### serfex env list

```
serfex env list [options]
```

Lists the keys and their values (secrets masked)

Also called: `ls`

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERFEX_APP; not needed with a token) |
| `--reveal` | Shows passwords and keys unmasked |

### serfex env get

```
serfex env get <KEY> [options]
```

Prints one value as it is

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERFEX_APP; not needed with a token) |

### serfex env set

```
serfex env set <KEY=value>… [options]
```

Changes or adds values

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERFEX_APP; not needed with a token) |

Examples:

```sh
serfex env set APP_DEBUG=false MAIL_FROM_NAME="Kargo"
```

### serfex env unset

```
serfex env unset <KEY>… [options]
```

Deletes keys

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERFEX_APP; not needed with a token) |

### serfex env pull

```
serfex env pull [options]
```

Downloads the .env file

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERFEX_APP; not needed with a token) |
| `-o, --output <file>` | The file to write (default: standard output) |

### serfex env push

```
serfex env push <file> [options]
```

Replaces the whole .env file

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERFEX_APP; not needed with a token) |

### serfex artisan

```
serfex artisan <command>… [options]
```

Runs artisan in the application's folder as the site's user and shows its output; the exit code is the command's. serfex's options (-a) come before the command.

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERFEX_APP; not needed with a token) |

### serfex composer

```
serfex composer <command>… [options]
```

Runs composer in the application's folder as the site's user and shows its output; the exit code is the command's. serfex's options (-a) come before the command.

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERFEX_APP; not needed with a token) |

### serfex npm

```
serfex npm <command>… [options]
```

Runs npm in the application's folder as the site's user and shows its output; the exit code is the command's. serfex's options (-a) come before the command.

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERFEX_APP; not needed with a token) |

### serfex queues

```
serfex queues [options]
```

Queue workers and waiting jobs

Also called: `queue`

| Command | |
| --- | --- |
| [`status`](#serfex-queues-status) | Shows the workers and the waiting jobs |
| [`failed`](#serfex-queues-failed) | Lists the failed jobs |
| [`retry`](#serfex-queues-retry) | Queues one failed job, or all of them, again |
| [`restart`](#serfex-queues-restart) | Restarts the workers |

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERFEX_APP; not needed with a token) |

### serfex queues status

```
serfex queues status [options]
```

Shows the workers and the waiting jobs

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERFEX_APP; not needed with a token) |

### serfex queues failed

```
serfex queues failed [options]
```

Lists the failed jobs

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERFEX_APP; not needed with a token) |

### serfex queues retry

```
serfex queues retry [job-id] [options]
```

Queues one failed job, or all of them, again

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERFEX_APP; not needed with a token) |

### serfex queues restart

```
serfex queues restart [options]
```

Restarts the workers

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERFEX_APP; not needed with a token) |

### serfex maintenance

```
serfex maintenance [options]
```

Maintenance mode

Also called: `down`

| Command | |
| --- | --- |
| [`status`](#serfex-maintenance-status) | Shows whether the application is in maintenance mode |
| [`on`](#serfex-maintenance-on) | Turns maintenance mode on |
| [`off`](#serfex-maintenance-off) | Turns maintenance mode off |

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERFEX_APP; not needed with a token) |

### serfex maintenance status

```
serfex maintenance status [options]
```

Shows whether the application is in maintenance mode

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERFEX_APP; not needed with a token) |

### serfex maintenance on

```
serfex maintenance on [options]
```

Turns maintenance mode on

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERFEX_APP; not needed with a token) |
| `--mode <serfex\|custom\|app>` | Serfex's page (default), your own HTML or php artisan down |
| `--allow <ip>` | An IP or network that still sees the site (more than once) |
| `--secret <path>` | The secret path that bypasses maintenance (default: random) |
| `--retry <seconds>` | The Retry-After header |
| `--html-file <file>` | HTML file for --mode custom |

### serfex maintenance off

```
serfex maintenance off [options]
```

Turns maintenance mode off

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERFEX_APP; not needed with a token) |

### serfex logs

```
serfex logs [options]
```

The application's logs (storage/logs)

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERFEX_APP; not needed with a token) |
| `--level <level>` | This level and above: debug, info, warning, error, critical |
| `--file <file>` | Log file (default: the newest) |
| `--search <text>` | Those containing this text |
| `-n, --lines <number>` | How many entries (default: 50) |
| `-f, --follow` | Prints new entries as they come |
| `--detail` | Also prints error details (stack traces) |

Examples:

```sh
serfex logs -a api.ornek.com.tr --level error -n 20
serfex logs -f
```

## Other

### serfex completion

```
serfex completion bash|zsh|fish
```

Prints the shell completion script. Commands, options and names such as sites and applications complete with Tab.

Examples:

```sh
source <(serfex completion bash)
serfex completion zsh > "${fpath[1]}/_serfex"
serfex completion fish > ~/.config/fish/completions/serfex.fish
```

### serfex version

```
serfex version
```

Prints the version
