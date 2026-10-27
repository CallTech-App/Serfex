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
- **Databases**: the site's databases, see [Databases](databases.md).
- **Statistics**: visitors, page views, requests and bandwidth by hour, day
  or month, with top pages, referrers, countries and bots. Counted from the
  site's own access log every minute; nothing leaves the server. Countries
  come from the regional Internet registries' lists, refreshed monthly.
- **Disk usage**: files, email, databases and application backups.
- **Backups**: the site's last backup, see [Backups](backups.md).

### Development

- **PHP & web server**: the PHP version; Nginx alone, or Nginx in front of
  Apache for sites that need `.htaccess` (once Apache is installed under
  Software); PHP settings such as `memory_limit` and `upload_max_filesize`
  within the plan's cap; custom `php.ini` lines; the PHP-FPM pool (most
  workers, renewal, request timeout). On customer sites `exec`,
  `shell_exec`, `proc_open` and similar functions are off; an administrator
  can allow them per site.
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

## Deleting a site

More actions → **Delete site**, then type the domain. The web server
configuration, PHP pool, files, SFTP accounts and scheduled tasks are
removed. Four boxes, all ticked by default, decide the rest:

- the DNS zone (unticked, it stays as a DNS-only domain);
- the email (unticked, the domain keeps receiving mail without the site);
- the databases, each dumped first and the dump kept for 30 days (unticked,
  they stay with the account);
- the system user, when it owns no other site.

## Limits for customers

A customer's plan limits the number of sites, subdomains, aliases, SFTP
accounts, scheduled tasks and databases, the disk space and monthly traffic,
the PHP versions and the PHP memory cap. See [Customers and plans](customers.md).
