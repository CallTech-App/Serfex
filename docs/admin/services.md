# Services, PHP and software

## Services

**Services** lists the systemd services on the server in three groups:

- **Panel**: the services Serfex itself needs (`serfex-agent`, `serfex-web`,
  `serfex-php`, the panel's PostgreSQL and its queue, backup and scheduler
  workers). Customer sites never use them. SSH, the agent and the panel
  cannot be stopped from the panel.
- **Shared services**: the web, PHP, database and mail servers that all sites
  use.
- **Other system services** of the operating system.

A service is a problem when it has failed, or when it starts at boot but is
not running; services you disabled on purpose do not count. Serfex checks
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

These values apply to PHP-FPM and to command-line PHP (Artisan, queue
workers, the scheduler, cron tasks, Composer), which reads a copy in
`/etc/php/<version>/cli/conf.d/90-serfex.ini`. The settings marked *web
only* (`memory_limit`, `max_execution_time`, `max_input_time`,
`display_errors`) stay at the command line's own values: no memory or time
limit, errors printed to the terminal. A site's own PHP settings apply to
its web requests only.

Each site's PHP-FPM pool runs `ondemand`, with at most 10 workers (customers
can raise it to 20), renewed after 500 requests.

## Software

**Software** installs, updates and removes the optional components, step by
step with a live log; a failed step rolls back what was done. A component
that is in use cannot be removed (the page says by what), and its
configuration is archived before removal. Updates are checked daily at 04:30.

Before you install a component, its Version column shows the version an
install would bring and where it comes from (the Ubuntu archive,
`ppa:ondrej/php`, repo.mysql.com, dlm.mariadb.com, apt.postgresql.org,
nodejs.org or getcomposer.org); the install confirmation and the Databases
setup card repeat it. For packages apt already lists this is apt's own
candidate. Database servers can be installed only in the versions Serfex
supports, and their confirmation lets you choose one (see
[Databases](databases.md#supported-versions)). Node.js, Composer and the
database archives apt does not list yet are looked up by **Check for
updates** and the nightly check: each archive's lists are read into
`/var/lib/serfex/components/probe` without adding it to apt.

An operation outlives the page and the panel's worker: if the worker is
restarted while it waits (a package upgrade restarting services, for one),
the page reads the outcome from the operation's log instead of waiting on
it forever. Serfex also tells Ubuntu's `needrestart` to leave its own
services (`serfex-agent`, `serfex-panel-*`) alone after package upgrades;
the workers pick up new PHP binaries on their hourly restart.

| Tab | Components |
|-----|------------|
| Web server | Nginx (always in front), Apache (behind Nginx on 127.0.0.1:7080 for sites that choose it), and the default stack for new sites |
| Database servers | MySQL or MariaDB, PostgreSQL, phpMyAdmin and Adminer |
| Other components | Redis, Memcached, Supervisor, Node.js (22, 24, 26 from nodejs.org in `/opt/serfex/node`), Composer, Python, Fail2ban, ModSecurity, ClamAV; Roundcube or SnappyMail is chosen under Email settings |
| Ubuntu updates | Ubuntu's own packages with a newer version, the restart they ask for and the automatic security updates (see below) |

Notes:

- **Redis** gives each Laravel application its own password-protected
  instance (128 MB, running as the site's user), connected from the
  application's Environment section with **Connect Redis**.
- **ModSecurity** starts in *Monitor only* mode with paranoia level 1; change
  it under its settings or Security → WAF.
- **ClamAV** scans incoming mail through Rspamd (infected mail is rejected)
  and is used by the security scan.
- **Fail2ban** keeps its jails in step with the server by itself: the mail
  jails are added as soon as the mail server is set up (also when Fail2ban
  was installed first), and the panel's jail follows a new panel port within
  a minute. A stopped Fail2ban is not started by this.

### Ubuntu updates

The **Ubuntu updates** tab lists every installed package that has a newer
version, security updates first, with the installed and the new version and
where it comes from. Full administrators update all of them at once
(**Update all**) or tick a few (**Update selected**, or **Update** on a row);
the progress window shows apt's output as it runs. Support and read-only
administrators can look but not update.

- **What "Update all" installs** is what `apt upgrade` would: every upgrade
  apt can make without removing a package, with the new packages they need
  (a new kernel's image is listed as *New package*). Packages apt leaves out
  because they would need more changes are marked and can be updated on
  their own.
- **Nothing is removed.** A selection apt could only install by removing
  another package is refused; do that one on the server with apt.
- **Configuration files changed on the server are kept**
  (`--force-confold`), and apt waits up to ten minutes for
  unattended-upgrades to finish first.
- **Held packages** (`apt-mark hold`) are shown and never updated.
  **Phased** updates, which Ubuntu rolls out to servers step by step, wait
  until Ubuntu offers them to this server.
- **Serfex components**: a package that belongs to one (Nginx, a PHP
  version, a database server, Redis…) is tagged with its name. After the
  upgrade Serfex writes the component's configuration again, restarts it and
  checks it, as the component's own **Update** does; if the check fails the
  previous configuration comes back and the log says which one.
- **Restart**: when an upgrade asks for it (`/run/reboot-required`, usually a
  new kernel or OpenSSL), the tab and Overview say so. **Restart** reboots
  the server one minute later (`shutdown -r +1`); **Undo** in the
  notification, or **Cancel the restart**, stops it.
- **Automatic security updates**: Ubuntu's unattended-upgrades installs
  security updates every day by default and Serfex leaves it that way. The
  tab shows whether it is on, whether it installs only security updates,
  whether it restarts the server by itself (off by default) and when it last
  ran. **Turn off** / **Turn on** writes `/etc/apt/apt.conf.d/20auto-upgrades`
  (installing unattended-upgrades when it is missing), with Undo.

The list comes from `apt-get -s upgrade --with-new-pkgs`, a dry run, and is
worked out again whenever dpkg's status or the package lists change, so
upgrades installed by unattended-upgrades or by hand show up without a
check. **Check for updates** and the nightly check refresh the package lists
first. Overview's **Updates** check turns amber when security updates wait or
a restart is needed.
