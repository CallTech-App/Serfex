<!-- This file is generated: cd cli && go test ./internal/app -run TestReferenceIsCurrent -update -->

# Command line reference

The Servik command line: sites, domains, DNS, SSL, mail, databases, backups, services and Laravel applications.

First sign in to a panel: servik login panel.example.com:8443

Every command also shows its own help with `--help`: `servik sites create --help`.

## Global options

These options work with every command.

| Option | |
| --- | --- |
| `--profile <name>` | The profile to use (default: the last signed in; SERVIK_PROFILE) |
| `--json` | Prints the output as JSON |
| `--lang <tr\|en>` | Language (default: the system's; SERVIK_LANG) |
| `--url <address>` | Panel address (SERVIK_URL) |
| `--token <key>` | API key or application token (SERVIK_TOKEN) |
| `-y, --yes` | Does it without asking |
| `--no-color` | Output without colors (NO_COLOR) |
| `-h, --help` | Shows the help |

## Account

### servik login

```
servik login [panel-address] [options]
```

Signs in to a Servik panel. An approval page opens in the browser; once you approve, a key is made for this computer and listed under Account security.

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
servik login panel.ornek.com.tr:8443
servik login https://panel.ornek.com.tr:8443 --token svk_…
sudo servik login --local
```

### servik setup-url

```
servik setup-url
```

Makes a one-time setup link for a panel that has no administrator yet. Whoever opens it creates the first administrator account and goes on to the setup wizard. The link works for 24 hours; a new one stops the old one.

install.sh prints the first link at the end of the installation. Runs on the server itself as root.

Examples:

```sh
sudo servik setup-url
```

### servik logout

```
servik logout
```

Signs out of the profile and closes its key on the panel

### servik profile

```
servik profile <command>
```

Lists the panels you are signed in to and switches between them

Also called: `profiles`

| Command | |
| --- | --- |
| [`list`](#servik-profile-list) | Lists the profiles |
| [`use`](#servik-profile-use) | Changes the default profile |
| [`remove`](#servik-profile-remove) | Deletes the profile and closes its key on the panel |

### servik profile list

```
servik profile list
```

Lists the profiles

Also called: `ls`

### servik profile use

```
servik profile use <name>
```

Changes the default profile

### servik profile remove

```
servik profile remove <name>
```

Deletes the profile and closes its key on the panel

Also called: `rm`

### servik whoami

```
servik whoami
```

Shows who you are signed in as, and on which panel

### servik status

```
servik status
```

Shows the server's state and what needs attention

### servik fingerprint

```
servik fingerprint [panel-address]
```

Prints the SHA-256 fingerprint of the certificate the panel shows. Run it on the server without an address and compare it with what your computer shows at the first sign-in.

## Hosting

### servik sites

```
servik sites <command>
```

Lists, creates and deletes sites

Also called: `site`

| Command | |
| --- | --- |
| [`list`](#servik-sites-list) | Lists the sites |
| [`show`](#servik-sites-show) | Shows a site's details |
| [`create`](#servik-sites-create) | Creates a new site |
| [`delete`](#servik-sites-delete) | Deletes a site with its files, databases and mail |

### servik sites list

```
servik sites list
```

Lists the sites

Also called: `ls`

### servik sites show

```
servik sites show <site>
```

Shows a site's details

### servik sites create

```
servik sites create <domain> [options]
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
servik sites create kahveatolyesi.com.tr --php 8.4 --wait
```

### servik sites delete

```
servik sites delete <site> [options]
```

Deletes a site with its files, databases and mail

Also called: `rm`

| Option | |
| --- | --- |
| `--keep-dns` | Keeps the DNS zone |

### servik domains

```
servik domains <command>
```

Subdomains, additional domains and redirects

Also called: `domain`

| Command | |
| --- | --- |
| [`list`](#servik-domains-list) | Lists a site's other names |
| [`add`](#servik-domains-add) | Adds a subdomain (blog) or an additional domain (example.net) |
| [`redirect`](#servik-domains-redirect) | Redirects a path (/old) or a domain to another address |
| [`remove`](#servik-domains-remove) | Removes a subdomain, an additional domain or a redirect |

### servik domains list

```
servik domains list <site>
```

Lists a site's other names

Also called: `ls`

### servik domains add

```
servik domains add <site> <name> [options]
```

Adds a name to a site. A name without a dot or under the site (blog, blog.example.com) is a subdomain with its own folder; another domain (example.net) is an additional domain that opens the same site. DNS and Let's Encrypt are set up for you.

| Option | |
| --- | --- |
| `--root <folder>` | The subdomain's folder, relative to the site's (default: subdomains/<name>) |
| `--dns` | Creates a DNS zone for an additional domain (default: yes) |

Examples:

```sh
servik domains add ornek.com.tr blog
servik domains add ornek.com.tr ornek.net --no-dns
```

### servik domains redirect

```
servik domains redirect <site> <source> <target> [options]
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
servik domains redirect ornek.com.tr /eski https://ornek.com.tr/yeni
servik domains redirect ornek.com.tr eskiad.com https://ornek.com.tr --keep-path
```

### servik domains remove

```
servik domains remove <site> <name>
```

Removes a subdomain, an additional domain or a redirect

Also called: `rm`

### servik dns

```
servik dns <command>
```

DNS zones and records

| Command | |
| --- | --- |
| [`zones`](#servik-dns-zones) | Lists the DNS zones |
| [`records`](#servik-dns-records) | Lists a zone's records |
| [`add`](#servik-dns-add) | Adds a record (name: @ is the zone itself) |
| [`delete`](#servik-dns-delete) | Deletes a record (its number: servik dns records) |

### servik dns zones

```
servik dns zones
```

Lists the DNS zones

Also called: `list`, `ls`

### servik dns records

```
servik dns records <zone>
```

Lists a zone's records

Also called: `show`

### servik dns add

```
servik dns add <zone> <type> <name> <value> [options]
```

Adds a record (name: @ is the zone itself)

| Option | |
| --- | --- |
| `--ttl <seconds>` | The record's TTL (default: the zone's) |
| `--priority <number>` | MX and SRV priority (default: 10) |

Examples:

```sh
servik dns add ornek.com.tr A www 203.0.113.10
servik dns add ornek.com.tr MX @ mail.ornek.com.tr --priority 10
servik dns add ornek.com.tr TXT @ "v=spf1 mx -all"
```

### servik dns delete

```
servik dns delete <zone> <record-no>
```

Deletes a record (its number: servik dns records)

Also called: `rm`

### servik ssl

```
servik ssl <command>
```

The sites' certificates and HTTPS

| Command | |
| --- | --- |
| [`list`](#servik-ssl-list) | Lists the sites' certificates |
| [`show`](#servik-ssl-show) | Shows a site's certificate |
| [`issue`](#servik-ssl-issue) | Gets a Let's Encrypt certificate |
| [`renew`](#servik-ssl-renew) | Renews the certificate now |
| [`https`](#servik-ssl-https) | Turns redirecting HTTP requests to HTTPS on or off |

### servik ssl list

```
servik ssl list
```

Lists the sites' certificates

Also called: `ls`

### servik ssl show

```
servik ssl show <site>
```

Shows a site's certificate

### servik ssl issue

```
servik ssl issue <site> [options]
```

Gets a Let's Encrypt certificate

| Option | |
| --- | --- |
| `--wildcard` | For *.domain too (when DNS is on this server) |
| `--only-domain` | Without www. and the additional domains |
| `--wait` | Waits until the certificate is issued |

### servik ssl renew

```
servik ssl renew <site> [options]
```

Renews the certificate now

| Option | |
| --- | --- |
| `--wait` | Waits until the certificate is renewed |

### servik ssl https

```
servik ssl https <site> on|off
```

Turns redirecting HTTP requests to HTTPS on or off

### servik mail

```
servik mail <command>
```

Mail domains, mailboxes and forwarders

| Command | |
| --- | --- |
| [`domains`](#servik-mail-domains) | Lists the domains with mail on |
| [`enable`](#servik-mail-enable) | Turns on mail for a domain (the DNS records are written for you) |
| [`list`](#servik-mail-list) | Lists the mailboxes and forwarders |
| [`create`](#servik-mail-create) | Creates a mailbox |
| [`password`](#servik-mail-password) | Changes a mailbox's password |
| [`quota`](#servik-mail-quota) | Changes a mailbox's quota (0 is unlimited) |
| [`delete`](#servik-mail-delete) | Deletes a mailbox |
| [`forward`](#servik-mail-forward) | Forwards an address to other addresses (*@domain: catch-all) |
| [`unforward`](#servik-mail-unforward) | Deletes a forwarder |

### servik mail domains

```
servik mail domains
```

Lists the domains with mail on

### servik mail enable

```
servik mail enable <domain>
```

Turns on mail for a domain (the DNS records are written for you)

### servik mail list

```
servik mail list <domain>
```

Lists the mailboxes and forwarders

Also called: `ls`

### servik mail create

```
servik mail create <address> [options]
```

Creates a mailbox

Also called: `add`

| Option | |
| --- | --- |
| `--password-stdin` | Reads the password from standard input (without it the panel makes a strong one and shows it once) |
| `--quota <MB>` | Quota in MB (default: the server's; 0 is unlimited) |

Examples:

```sh
servik mail create siparis@ornek.com.tr
printf '%s' "$PAROLA" | servik mail create info@ornek.com.tr --password-stdin
```

### servik mail password

```
servik mail password <address> [options]
```

Changes a mailbox's password

| Option | |
| --- | --- |
| `--password-stdin` | Reads the password from standard input (without it the panel makes a strong one and shows it once) |

### servik mail quota

```
servik mail quota <address> <MB>
```

Changes a mailbox's quota (0 is unlimited)

### servik mail delete

```
servik mail delete <address>
```

Deletes a mailbox

Also called: `rm`

### servik mail forward

```
servik mail forward <address> <target>…
```

Forwards an address to other addresses (*@domain: catch-all)

Examples:

```sh
servik mail forward satis@ornek.com.tr ali@ornek.com.tr ayse@gmail.com
servik mail forward '*@ornek.com.tr' info@ornek.com.tr
```

### servik mail unforward

```
servik mail unforward <address>
```

Deletes a forwarder

### servik db

```
servik db <command>
```

The sites' databases

Also called: `databases`, `database`

| Command | |
| --- | --- |
| [`list`](#servik-db-list) | Lists the databases |
| [`create`](#servik-db-create) | Creates a database and its user |
| [`delete`](#servik-db-delete) | Deletes a database (backs it up first) |

### servik db list

```
servik db list [options]
```

Lists the databases

Also called: `ls`

| Option | |
| --- | --- |
| `--site <site>` | Only this site's databases |

### servik db create

```
servik db create <site> <name> [options]
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
servik db create kahveatolyesi.com.tr dukkan
```

### servik db delete

```
servik db delete <name> [options]
```

Deletes a database (backs it up first)

Also called: `rm`

| Option | |
| --- | --- |
| `--no-backup` | Takes no backup before deleting |

### servik backups

```
servik backups <command>
```

Backups and restores

Also called: `backup`

| Command | |
| --- | --- |
| [`list`](#servik-backups-list) | Lists the backups, newest first |
| [`show`](#servik-backups-show) | Shows what is in a backup |
| [`create`](#servik-backups-create) | Takes a backup now: of a site or (administrators) of the whole server |
| [`restore`](#servik-backups-restore) | Restores a site from a backup |

### servik backups list

```
servik backups list [options]
```

Lists the backups, newest first

Also called: `ls`

| Option | |
| --- | --- |
| `--site <site>` | Only the backups with this site |
| `-n, --limit <number>` | At most how many backups (default: 20) |

### servik backups show

```
servik backups show <no>
```

Shows what is in a backup

### servik backups create

```
servik backups create [site] [options]
```

Takes a backup now: of a site or (administrators) of the whole server

| Option | |
| --- | --- |
| `--wait` | Waits until the backup ends |

### servik backups restore

```
servik backups restore <no> <site> [options]
```

Restores a site from a backup. The site as it is now is backed up first, so the restore can be undone too.

| Option | |
| --- | --- |
| `--only <part>` | Only these: files, databases, mail, dns, certificates (comma separated) |
| `--no-safety` | Takes no safety backup first |
| `--wait` | Waits until the restore ends |

Examples:

```sh
servik backups restore 42 kahveatolyesi.com.tr --only files,databases --wait
```

## Server

### servik services

```
servik services <command>
```

The server's services (administrators)

Also called: `service`

| Command | |
| --- | --- |
| [`list`](#servik-services-list) | Lists the services |
| [`status`](#servik-services-status) | Shows a service's state |
| [`start`](#servik-services-start) | Starts the service |
| [`stop`](#servik-services-stop) | Stops the service |
| [`restart`](#servik-services-restart) | Tests the configuration and restarts the service |
| [`logs`](#servik-services-logs) | The last lines of the service's log |

### servik services list

```
servik services list
```

Lists the services

Also called: `ls`

### servik services status

```
servik services status <service>
```

Shows a service's state

Also called: `show`

### servik services start

```
servik services start <service>
```

Starts the service

### servik services stop

```
servik services stop <service>
```

Stops the service

### servik services restart

```
servik services restart <service>
```

Tests the configuration and restarts the service

### servik services logs

```
servik services logs <service> [options]
```

The last lines of the service's log

| Option | |
| --- | --- |
| `-n, --lines <number>` | How many lines (default: 50) |

### servik customers

```
servik customers <command>
```

Customers and their packages (administrators)

Also called: `customer`

| Command | |
| --- | --- |
| [`list`](#servik-customers-list) | Lists the customers |
| [`show`](#servik-customers-show) | The customer's sites and package usage |
| [`suspend`](#servik-customers-suspend) | Suspends the customer (sites answer 503, mail is held) |
| [`unsuspend`](#servik-customers-unsuspend) | Lifts the suspension |
| [`packages`](#servik-customers-packages) | Lists the packages |

### servik customers list

```
servik customers list
```

Lists the customers

Also called: `ls`

### servik customers show

```
servik customers show <no>
```

The customer's sites and package usage

### servik customers suspend

```
servik customers suspend <no> [options]
```

Suspends the customer (sites answer 503, mail is held)

| Option | |
| --- | --- |
| `--reason <text>` | The reason the customer sees |

### servik customers unsuspend

```
servik customers unsuspend <no>
```

Lifts the suspension

### servik customers packages

```
servik customers packages
```

Lists the packages

## Laravel

### servik apps

```
servik apps
```

Lists the Laravel applications you can reach

### servik deploy

```
servik deploy [options]
```

Deploys the application from its repository: the new release is installed under releases/ and goes live once it passes the health check; if it fails, the previous release stays live.

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERVIK_APP; not needed with a token) |
| `--ref <branch\|tag\|commit>` | The branch, tag or commit to deploy (default: the application's branch) |
| `--no-migrate` | Does not run migrations in this deploy |
| `--no-frontend` | Does not build the front end (npm run build) in this deploy |
| `--wait` | Shows the output until it ends; exits non-zero when it fails |
| `--timeout <duration>` | The longest wait for --wait (default: 60m) |

Examples:

```sh
servik deploy -a api.ornek.com.tr --wait
SERVIK_URL=https://panel.ornek.com.tr:8443 SERVIK_TOKEN=svk_… servik deploy --ref "$GITHUB_SHA" --wait
```

### servik deployments

```
servik deployments [options]
```

Lists the latest deployments

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERVIK_APP; not needed with a token) |
| `-n, --limit <number>` | How many deployments (default: 10) |

### servik rollback

```
servik rollback [options]
```

Goes back to the previous release

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERVIK_APP; not needed with a token) |
| `--to <no>` | The number of the deployment to go back to (default: the one before) |
| `--wait` | Shows the output until it ends; exits non-zero when it fails |
| `--timeout <duration>` | The longest wait for --wait (default: 60m) |

### servik preview

```
servik preview <command>
```

Preview environments (<branch>.domain)

Also called: `previews`

| Command | |
| --- | --- |
| [`create`](#servik-preview-create) | Opens a preview environment for a branch |
| [`delete`](#servik-preview-delete) | Removes a preview environment |

### servik preview create

```
servik preview create <branch> [options]
```

Opens a preview environment for a branch

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERVIK_APP; not needed with a token) |

### servik preview delete

```
servik preview delete <name> [options]
```

Removes a preview environment

Also called: `rm`

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERVIK_APP; not needed with a token) |

### servik env

```
servik env [options]
```

Shows and changes the application's .env file. On save, config:cache is refreshed and the workers restart; the panel keeps every version.

| Command | |
| --- | --- |
| [`list`](#servik-env-list) | Lists the keys and their values (secrets masked) |
| [`get`](#servik-env-get) | Prints one value as it is |
| [`set`](#servik-env-set) | Changes or adds values |
| [`unset`](#servik-env-unset) | Deletes keys |
| [`pull`](#servik-env-pull) | Downloads the .env file |
| [`push`](#servik-env-push) | Replaces the whole .env file |

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERVIK_APP; not needed with a token) |
| `--reveal` | Shows passwords and keys unmasked |

### servik env list

```
servik env list [options]
```

Lists the keys and their values (secrets masked)

Also called: `ls`

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERVIK_APP; not needed with a token) |
| `--reveal` | Shows passwords and keys unmasked |

### servik env get

```
servik env get <KEY> [options]
```

Prints one value as it is

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERVIK_APP; not needed with a token) |

### servik env set

```
servik env set <KEY=value>… [options]
```

Changes or adds values

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERVIK_APP; not needed with a token) |

Examples:

```sh
servik env set APP_DEBUG=false MAIL_FROM_NAME="Kargo"
```

### servik env unset

```
servik env unset <KEY>… [options]
```

Deletes keys

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERVIK_APP; not needed with a token) |

### servik env pull

```
servik env pull [options]
```

Downloads the .env file

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERVIK_APP; not needed with a token) |
| `-o, --output <file>` | The file to write (default: standard output) |

### servik env push

```
servik env push <file> [options]
```

Replaces the whole .env file

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERVIK_APP; not needed with a token) |

### servik artisan

```
servik artisan <command>… [options]
```

Runs artisan in the application's folder as the site's user and shows its output; the exit code is the command's. servik's options (-a) come before the command.

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERVIK_APP; not needed with a token) |

### servik composer

```
servik composer <command>… [options]
```

Runs composer in the application's folder as the site's user and shows its output; the exit code is the command's. servik's options (-a) come before the command.

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERVIK_APP; not needed with a token) |

### servik npm

```
servik npm <command>… [options]
```

Runs npm in the application's folder as the site's user and shows its output; the exit code is the command's. servik's options (-a) come before the command.

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERVIK_APP; not needed with a token) |

### servik queues

```
servik queues [options]
```

Queue workers and waiting jobs

Also called: `queue`

| Command | |
| --- | --- |
| [`status`](#servik-queues-status) | Shows the workers and the waiting jobs |
| [`failed`](#servik-queues-failed) | Lists the failed jobs |
| [`retry`](#servik-queues-retry) | Queues one failed job, or all of them, again |
| [`restart`](#servik-queues-restart) | Restarts the workers |

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERVIK_APP; not needed with a token) |

### servik queues status

```
servik queues status [options]
```

Shows the workers and the waiting jobs

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERVIK_APP; not needed with a token) |

### servik queues failed

```
servik queues failed [options]
```

Lists the failed jobs

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERVIK_APP; not needed with a token) |

### servik queues retry

```
servik queues retry [job-id] [options]
```

Queues one failed job, or all of them, again

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERVIK_APP; not needed with a token) |

### servik queues restart

```
servik queues restart [options]
```

Restarts the workers

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERVIK_APP; not needed with a token) |

### servik maintenance

```
servik maintenance [options]
```

Maintenance mode

Also called: `down`

| Command | |
| --- | --- |
| [`status`](#servik-maintenance-status) | Shows whether the application is in maintenance mode |
| [`on`](#servik-maintenance-on) | Turns maintenance mode on |
| [`off`](#servik-maintenance-off) | Turns maintenance mode off |

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERVIK_APP; not needed with a token) |

### servik maintenance status

```
servik maintenance status [options]
```

Shows whether the application is in maintenance mode

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERVIK_APP; not needed with a token) |

### servik maintenance on

```
servik maintenance on [options]
```

Turns maintenance mode on

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERVIK_APP; not needed with a token) |
| `--mode <servik\|custom\|app>` | Servik's page (default), your own HTML or php artisan down |
| `--allow <ip>` | An IP or network that still sees the site (more than once) |
| `--secret <path>` | The secret path that bypasses maintenance (default: random) |
| `--retry <seconds>` | The Retry-After header |
| `--html-file <file>` | HTML file for --mode custom |

### servik maintenance off

```
servik maintenance off [options]
```

Turns maintenance mode off

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERVIK_APP; not needed with a token) |

### servik logs

```
servik logs [options]
```

The application's logs (storage/logs)

| Option | |
| --- | --- |
| `-a, --app <domain>` | Laravel application (SERVIK_APP; not needed with a token) |
| `--level <level>` | This level and above: debug, info, warning, error, critical |
| `--file <file>` | Log file (default: the newest) |
| `--search <text>` | Those containing this text |
| `-n, --lines <number>` | How many entries (default: 50) |
| `-f, --follow` | Prints new entries as they come |
| `--detail` | Also prints error details (stack traces) |

Examples:

```sh
servik logs -a api.ornek.com.tr --level error -n 20
servik logs -f
```

## Other

### servik completion

```
servik completion bash|zsh|fish
```

Prints the shell completion script. Commands, options and names such as sites and applications complete with Tab.

Examples:

```sh
source <(servik completion bash)
servik completion zsh > "${fpath[1]}/_servik"
servik completion fish > ~/.config/fish/completions/servik.fish
```

### servik version

```
servik version
```

Prints the version
