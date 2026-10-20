# Services, PHP and software

## Services

**Services** lists the systemd services on the server in three groups:

- **Panel**: the services Servik itself needs (`servik-agent`, `servik-web`,
  `servik-php`, the panel's PostgreSQL and its queue, backup and scheduler
  workers). Customer sites never use them. SSH, the agent and the panel
  cannot be stopped from the panel.
- **Shared services**: the web, PHP, database and mail servers that all sites
  use.
- **Other system services** of the operating system.

A service is a problem when it has failed, or when it starts at boot but is
not running; services you disabled on purpose do not count. Servik checks
every minute and notifies you.

Start, stop, restart and reload ask first when sites would be affected, and
can be undone. Before a restart or reload the configuration is tested
(`nginx -t`, `php-fpm -t`, `postfix check`…); if it fails, nothing is
restarted and the service keeps running with its old settings.

A service's page shows its uptime, CPU and memory, its log, and for some
services a **Settings** tab:

| Service | Settings (defaults) |
|---------|---------------------|
| Nginx | worker processes (auto), connections (768), keepalive (65 s), request size (64 MB) |
| MySQL / MariaDB | connections (151), buffer pool (128 MB), packet size (64 MB) |
| Postfix | message size (50 MB) |

Apache and PowerDNS have no settings tab in 0.1.

## PHP versions

**Services → PHP versions** installs and removes PHP 8.1 to 8.5 (from the
Ondřej Surý archive, except Ubuntu's own 8.3) and chooses the version for new
sites (default: the newest installed). A version in use cannot be removed.

Each version's page sets the starting `php.ini` values for sites, which each
site can then change within its plan:

| Setting | Default |
|---------|---------|
| `memory_limit` | 256M |
| `upload_max_filesize`, `post_max_size` | 64M |
| `max_execution_time` | 60 |
| `max_input_vars` | 3000 |
| `display_errors` | Off |
| `date.timezone` | the server's |
| OPcache | on, 128 MB |

It also installs and removes extensions. **Disabled functions on customer
sites** (`exec`, `passthru`, `shell_exec`, `system`, `proc_open`, `popen`,
`pcntl_exec`, `show_source` by default) applies to every customer site;
an administrator can allow them for one site on its PHP page.

These values apply to PHP-FPM, that is to the web. Command-line PHP
(Artisan, queue workers, cron) uses Ubuntu's own `php.ini` in 0.1.

Each site's PHP-FPM pool runs `ondemand`, with at most 10 workers (customers
can raise it to 20), renewed after 500 requests.

## Software

**Software** installs, updates and removes the optional components, step by
step with a live log; a failed step rolls back what was done. A component
that is in use cannot be removed (the page says by what), and its
configuration is archived before removal. Updates are checked daily at 04:30.

| Tab | Components |
|-----|------------|
| Web server | Nginx (always in front), Apache (behind Nginx on 127.0.0.1:7080 for sites that choose it), and the default stack for new sites |
| Database servers | MySQL or MariaDB, PostgreSQL, phpMyAdmin and Adminer |
| Other components | Redis, Memcached, Supervisor, Node.js (22, 24, 26 from nodejs.org in `/opt/servik/node`), Composer, Python, Fail2ban, ModSecurity, ClamAV; Roundcube is turned on under Email settings |

Notes:

- **Redis** gives each Laravel application its own password-protected
  instance (128 MB, running as the site's user), connected from the
  application's Environment section with **Connect Redis**.
- **ModSecurity** starts in *Monitor only* mode with paranoia level 1; change
  it under its settings or Security → WAF.
- **ClamAV** scans incoming mail through Rspamd (infected mail is rejected)
  and is used by the security scan.
- **Fail2ban** installed before the mail server needs a reinstall to add the
  mail jails.
