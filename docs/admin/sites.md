# Sites

**Sites** lists every website on the server with its system user, customer,
this month's traffic and status. Open a site for its detail page: a status
strip (web server, PHP, system user, document root, certificate, last backup)
and its tools in six cards: Files & data, Development, Applications,
Domain & DNS, Email and Security.

## Adding a site

Sites → **Add site**:

| Field | |
|-------|---|
| Domain | `example.com`. `www.example.com` opens the site too unless you untick it. |
| Customer | Leave empty for a site of your own, or pick a customer: the site then counts toward their plan and shows in their panel. |
| System user | The Linux account that owns the files. A new name creates the account; an existing Serfex account adds the site to it (one account can own several sites, like a cPanel account or a Plesk subscription). |
| PHP version | Any installed version the customer's plan allows. Default: the version set for new sites under Services → PHP versions. |

Serfex then, in one transaction that is rolled back if any step fails:

- creates the account (`/usr/sbin/nologin` shell) and
  `/home/<user>/web/<domain>/public_html` with Serfex's default page
  (`index.html`: the domain, how to upload files, a "Sign in to the panel"
  button when a panel address is set under Settings → General, Turkish
  with an English link). New subdomain folders get the same page. The
  customer's own `index.html` or `index.php` replaces it;
- writes the Nginx site and a PHP-FPM pool that runs as the account, with
  `open_basedir` limited to its home;
- opens the DNS zone from the server template, if the DNS server is installed;
- turns email on for the domain, if that is set under Email settings;
- requests a Let's Encrypt certificate once the domain resolves to the
  server. Until then the site shows "DNS does not point to this server yet"
  and opens over http.

Serfex never adopts system accounts or configuration files it did not create:
its accounts carry the comment `Serfex site user` and its files start with
`Managed by Serfex`.

## Site tools

### Files & data

- **File Manager**: upload (2 GB per file), download, an editor for text
  files up to 2 MB, permissions, move, copy, zip and extract, search. Every
  operation runs as the site's user, never as root. Deleted files go to a
  trash that is kept 7 days and counts toward the disk quota.
- **FTP accounts**: SFTP accounts locked into one folder of the site, for a
  designer or an agency. They connect to port 22 with a password; plain FTP is
  not offered. The password is generated, shown once and never stored.
- **SSH access**: lets the site's own system user sign in with an SSH key or
  an optional password. Off by default. **SFTP only** locks the user in its
  home folder (`/home/<user>`): no commands, no tunnels. **SFTP and shell**,
  which only an administrator can turn on, gives bash in the real home and
  local port forwarding (for a tunnel to the database). The setting belongs
  to the system user, so it reaches every site that user owns. Keys are kept
  in the panel and only those keys sign in; the password is shown once.
- **Databases**: the site's databases, see [Databases](databases.md).
- **Statistics**: visitors, page views, requests and bandwidth by hour, day
  or month, with top pages, referrers, countries and bots. Counted from the
  site's own access log every minute; nothing leaves the server. Countries
  come from the regional Internet registries' lists, refreshed monthly.
- **Disk usage**: files, email, databases and application backups.
- **Backups**: the site's last backup, see [Backups](backups.md).
- **Copy site**: a staging copy on an address of its own, see
  [Copying a site](#copying-a-site) below.

### Development

- **PHP & web server**: the PHP version; Nginx alone, or Nginx in front of
  Apache for sites that need `.htaccess` (once Apache is installed under
  Software); PHP settings such as `memory_limit` and `upload_max_filesize`
  within the plan's cap; custom `php.ini` lines; the PHP-FPM pool (most
  workers, renewal, request timeout). On customer sites `exec`,
  `shell_exec`, `proc_open` and similar functions are off; an administrator
  can allow them per site.
- **Cache & speed**: three switches, all off until you turn them on.
  - *Page cache*: Nginx keeps the pages PHP makes and sends them again
    without running PHP, for 1 minute to 1 day (10 minutes by default).
    Only GET and HEAD requests of visitors without a session are cached;
    a response that sets a cookie or that the application marks private or
    no-cache is never kept, nor sign-in, admin, cart, checkout and API paths
    (`/wp-admin/`, `/login`, `/cart`, `/checkout`, `/api/`, …) or paths you
    add. Analytics and consent cookies do not stop a page from being cached.
    Each site has its own cache of at most 256 MB in
    `/var/cache/serfex/nginx/<domain>`; **Clear cache** empties it. The
    `X-Serfex-Cache` response header says HIT, MISS or BYPASS. Not used for
    Octane applications.
  - *Browser cache*: images, CSS, JavaScript, fonts and video are kept by
    browsers for 1 to 365 days (30 by default).
  - *Compression*: gzip for HTML, CSS, JavaScript, JSON, SVG and fonts.
- **Composer** and **Node.js**: run Composer and npm, pnpm, Yarn or Bun in
  any folder of the site as the site's user, with live output. Folders with
  a `composer.json` or `package.json` (four levels deep, so a WordPress theme is found) are listed with
  their lock file and whether `vendor/` or `node_modules/` is installed;
  common commands and the scripts of `package.json` are one click away.
  The site can pin the Node.js line its commands (and its Laravel
  application's builds) use, among the lines installed under Software.
  Composer and Node.js 24 are downloaded on first use. Customers need the
  plan's "Commands and Tinker" feature.
- **Scheduled tasks**: cron jobs run as the site's user, with a time limit
  (1 hour by default), no overlapping runs and the last 20 runs' output
  kept. Laravel's own scheduler is under Laravel → Scheduler instead.
- **Logs**: the access and error logs of the site and its subdomains, with
  filters, live follow and download.
- **Error pages**: your own HTML for 403, 404 and 500, 502 and 504 errors.
  503 is kept for maintenance and suspension.
- **Web server directives** (administrators only): your own Nginx or Apache
  lines. They are tested with `nginx -t` or `apachectl configtest` first and
  not applied if the server refuses them.

### Applications

Install or link a Laravel application on the site, see [Laravel](../laravel.md).

**WordPress** manages one WordPress per site, through WP-CLI running as the
site's own user:

- **Install WordPress** puts it in the site's web root (which must be empty
  apart from the "site is ready" page) with a new MySQL/MariaDB database
  (`<prefix>wp`), the site title, an administrator account and language
  (Turkish or English). The administrator password is shown once; Serfex does
  not keep it. `admin` and `administrator` are refused as usernames. A failed
  install removes its files and its database. Not offered on a site with a
  Laravel application.
- A WordPress that is already on the site (in the web root or a folder up to
  two levels down) is found and can be managed with **Manage**; its files are
  not touched.
- **Update** WordPress itself, one plugin or theme, or everything with an
  update. A restore point (the database and the files being updated) is taken
  first; if the site answers with an error after the update, the update is
  rolled back by itself. **Undo** in History puts the last update's restore
  point back for 7 days. The panel checks every managed WordPress for updates
  once a day.
- **Sign in to WordPress** opens the dashboard as the first administrator with
  a link that works once, for two minutes. It is written to the audit log.
- **Maintenance mode**, plugin activate/deactivate, theme switch and the core
  automatic update policy (security and maintenance releases by default, all
  releases, or off) change at once and can be undone.
- **Security** rules: turn off the file editor, protect `wp-config.php`, no PHP
  in `wp-content/uploads`, hide `readme.html`, `license.txt`, `debug.log` and
  `wp-config.php` (the first four are recommended and applied on install), and
  turn off XML-RPC. The web server rules live in the site's own Nginx snippet.
- **Stop managing** leaves WordPress, its files and database on the site and
  removes only Serfex's sign-in plugin, web server rules and restore points.
- **Email**: WordPress sends with PHP's `mail()`, which this server refuses to
  sites. Until an SMTP plugin (WP Mail SMTP, FluentSMTP…) is active, the page
  says so and gives the settings: a mailbox login on the mail server's name,
  port 587 with STARTTLS.

Customers manage the WordPress on their own sites the same way.

### Copying a site

**Copy site** makes a staging copy of a site on a new address
(`staging.example.com` by default) under the same system user, PHP version
and web server, for trying plugins, updates and changes away from visitors.

- The whole site folder is copied except its logs. Addresses and database
  names in settings files (`wp-config.php`, `.env`, PHP configuration
  arrays) are changed to the copy's, symbolic links into the site point into
  the copy, and Laravel's cached configuration is removed. A database name
  not found in any settings file is listed, to be set by hand.
- With **Databases** each of the site's databases is copied into a new one
  named `<name>_copy`, and the same database users get the same access.
- A WordPress gets its new address in its own database (WP-CLI
  search-replace, serialized data included) and is managed on the copy too.
- **Hide from search engines** (on by default) adds `X-Robots-Tag: noindex`
  to every response and turns on WordPress's "Discourage search engines".
- Mail, scheduled tasks, FTP accounts and Laravel queue workers are not
  copied. If the domain's DNS is on this server the copy's record is added;
  otherwise add an A record for it. A certificate is requested as for any new
  site.
- **Refresh from source** writes the live site's files and/or databases
  over the copy again.
- **Publish to the live site** writes the copy's files and/or databases over
  the live site. The live site is backed up first; if the publish fails it is
  restored at once, and **Undo** restores it from that backup for 7 days.
  Not offered on sites with a Laravel application, which go live through Git
  deploys and preview environments.

A copy counts against the plan's sites and databases. Customers copy their
own sites the same way.

### Domain & DNS

- **Domains** has three tabs:
  - *Subdomains* (`blog.example.com`) with their own folder, a folder inside
    the main site, or any folder you choose. They share the site's PHP pool
    and user.
  - *Domain aliases*: other domains that open the same site, with a DNS zone
    created for them when you like.
  - *Redirects*: a whole domain or one path to another address, 301 or 302,
    keeping the rest of the path or not.

  Aliases and subdomains are added to the certificate as soon as their DNS
  points at the server.
- **DNS records**: the domain's zone, see [DNS](dns.md).

### Email

The site's mailboxes and forwarders, see [Email](mail.md).

### Security

- **SSL certificate**, see [SSL](ssl.md).
- **Security scan** and blocked paths, see [Security](security.md).
- **Password-protected folders**: HTTP basic authentication for a folder
  such as `/admin/`, with its own users.
- **Web application firewall**: the site's own ModSecurity mode and rule
  exceptions.

## Moving an account from cPanel

Sites → **Move from another server** (administrators) brings a whole cPanel
account here. Use the account's full backup: in cPanel, Files → Backup →
*Download a Full Account Backup* (`backup-…_user.tar.gz`), or as root on the
old server `/scripts/pkgacct user` (`/home/cpmove-user.tar.gz`). Upload it on
the page, or copy large archives to `/var/lib/serfex/backups/import/` and pick
them there.

The page reads the archive and shows what it holds before anything changes:
the sites (the main domain and each addon domain become a site, parked
domains become aliases, subdomains come with their folders), databases,
mailboxes, DNS zones and valid certificates, and what will not come. Choose
the sites, the parts and the customer (a new one from the account's contact
address, an existing one, or none), then **Start the move**.

- The account keeps its cPanel user name, so database names and paths stay
  the same. Each site's files go to `~/web/<domain>/public_html`; the old
  paths (`~/public_html`, `~/<addon>`) are linked to the new place, and the
  rest of the home folder comes along.
- Database users keep their passwords when this MySQL can use the stored
  hash. Otherwise they get a new one, written into `wp-config.php`, `.env`
  or a similar settings file, and listed in the report.
- Mailboxes keep their passwords (SHA-512/256, MD5 and bcrypt crypt hashes)
  and messages (Maildir). Others get a new password; mdbox mailboxes are
  created without their messages.
- DNS zones start from Serfex's records (its name servers, this server's
  addresses); the old records that do not clash are added, with the old
  server's address replaced by this one's. cPanel's own service records and
  DKIM are left out (Serfex signs mail with its own key).
- Certificates that are trusted and still valid are used until they expire.
- A domain that already exists here is skipped, and databases or users that
  exist are not overwritten.
- Cron jobs, FTP accounts, PostgreSQL databases and remote MySQL access are
  listed in the report but not moved.

The old server is not touched. Visitors keep going there until you point the
domains' DNS here; the report lists the next steps and any new passwords
(kept encrypted until you press **Forget passwords**).

## Moving a subscription from Plesk

Plesk needs no backup: on Sites → **Move from another server**, open the
**Plesk server** tab and connect to the old server over SSH, as root or as a
user with passwordless sudo. Sign in with Serfex's own key (the page shows
the line that adds it to `~/.ssh/authorized_keys`; it is the key SFTP backup
targets use) or with the password, which is kept encrypted only until the
copy is done. The page shows the server's SSH fingerprint, and every later
connection must show the same key. Plesk Obsidian (18) on Linux is needed.

Pick a subscription: it is copied to this server first (on the backups
queue; you can leave the page), read with Plesk's own tools, and nothing
changes on the old server:

- settings from Plesk's database (`plesk db`): domains, aliases, mailboxes,
  forwarders, databases, DNS records and certificates;
- files with `tar` from `/var/www/vhosts/<domain>` (without `logs/` and
  `tmp/`), mail from `/var/qmail/mailnames` (Maildir);
- MySQL databases with `plesk db dump`, users with their stored hashes;
- mailbox passwords with `mail_auth_view`; they are stored here only as
  SHA512-CRYPT hashes, so mailboxes keep their passwords.

Then the same review, move and report follow as for cPanel. The account
opens under the subscription's system user (a name this server cannot use
is corrected and noted). The main domain and each additional domain become
a site, aliases become additional names with their zones, subdomains come
with their folders, and `~/httpdocs` is linked to the new place.

Listed but not moved: domains without web hosting (forwarding), the mail of
aliases, databases on another database server, PostgreSQL databases, cron
jobs and additional FTP accounts. Settings files that name the old
`/var/www/vhosts/` path are listed so you can correct them.

## Deleting a site

More actions → **Delete site**, then type the domain. The web server
configuration, PHP pool, files, SFTP accounts and scheduled tasks are
removed. Four boxes, all ticked by default, decide the rest:

- the DNS zone (unticked, it stays as a DNS-only domain);
- the email (unticked, the domain keeps receiving mail without the site);
- the databases, each dumped first and the dump kept for 30 days (unticked,
  they stay with the account);
- the system user, when it owns no other site.

Before anything is removed, the whole site (files, databases, email, DNS
records and certificates) is backed up on this server. The backup shows in
**Backups** as *Before deletion* and is kept for 30 days (Backups →
Schedule → *Backups before deletion*). The toast after the deletion has an
**Undo** that restores it straight away; later, open the backup and choose
**Restore**, which creates the site again. Administrators can untick *Take a
backup before deleting*. When the disk has no room for the backup, the dialog says
so and the site is deleted without one. If the backup fails, nothing is
deleted and the site shows the reason.

## Limits for customers

A customer's plan limits the number of sites, subdomains, aliases, SFTP
accounts, scheduled tasks and databases, the disk space and monthly traffic,
the PHP versions and the PHP memory cap. See [Customers and plans](customers.md).
