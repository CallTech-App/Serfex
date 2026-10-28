# Serfex panel

The Laravel application behind the Serfex web UI. See
[docs/development.md](../docs/development.md) for setup,
[docs/architecture.md](../docs/architecture.md) for how it fits with the agent,
and [CONTRIBUTING.md](../CONTRIBUTING.md) for the checks to run before opening
a pull request.

Key pieces:

- `app/Services/Agent/AgentClient.php`: JSON-RPC 2.0 client for `serfex-agent`.
- `config/serfex.php`: agent socket path and timeouts (`SERFEX_AGENT_SOCKET`, `SERFEX_AGENT_TIMEOUT`, `SERFEX_AGENT_JOB_TIMEOUT`).
- `app/Models/Site.php`, `app/Models/SystemUser.php`: a site belongs to a Linux account; an account can own several sites, and a customer owns accounts.
- `app/Jobs/ProvisionSite.php`, `app/Jobs/DeleteSite.php`: queued jobs that call the agent (`site.apply`, `site.delete`) and record the result on the site. Run `php artisan queue:work`.
- `app/Models/Certificate.php`, `app/Services/Ssl/`: TLS certificates. `Certificates` requests Let's Encrypt certificates, installs uploaded ones and swaps them on a site (the old one is removed after 10 minutes, so the swap can be undone); `DnsCheck` keeps names that do not resolve to this server away from Let's Encrypt.
- `app/Jobs/IssueCertificate.php`, `app/Jobs/IssuePanelCertificate.php`, `app/Jobs/DeleteCertificate.php`: agent calls for `ssl.issue`, `ssl.panel.issue` and `ssl.delete`. `php artisan serfex:ssl-renew` (scheduled hourly, run `php artisan schedule:work` in development) renews certificates 30 days before expiry and retries requests waiting for DNS.
- `app/Livewire/Ssl/`: the SSL Sertifikaları list, the per-site SSL page and the SSL settings (Let's Encrypt e-mail, automatic SSL for new sites, panel address).
- `app/Models/MailDomain.php`, `Mailbox.php`, `MailForwarder.php`, `app/Services/Mail/`: e-mail. `MailDomains` turns mail on and off and changes mailboxes and forwarders (removed mail is kept 10 minutes for Undo); `MailPassword` hashes mailbox passwords for Dovecot, so no password is stored; `MailDns` writes MX, SPF, DKIM, DMARC and client-settings records into the domain's zone and checks them against public DNS (`SERFEX_MAIL_CHECK_DNS`).
- `app/Jobs/SetupMailServer.php`, `SyncMailDomain.php`, `DeleteMailDomain.php`, `PurgeMailbox.php`, `IssueMailCertificate.php`: agent calls for `mail.setup`, `mail.domain.apply`, `mail.domain.delete`, `mail.mailbox.purge` and the mail server's certificate. `php artisan serfex:mail-usage` (scheduled every 30 minutes) records mailbox sizes.
- `app/Livewire/Mail/`: the E-posta list, the mail domain page (mailboxes, forwarders, settings and DNS records) and the e-mail settings; `app/Http/Controllers/Mail/WebmailController.php` opens a mailbox in webmail (Roundcube or SnappyMail) with a one-time link.
- `app/Livewire/Sites/`: the Siteler list (with the "Site ekle" form), the site detail page and the site's PHP ve Web Sunucusu page (PHP version, Nginx or Nginx + Apache).
- `app/Services/Components/Components.php`, `app/Livewire/Components/ComponentIndex.php`, `app/Jobs/RunComponentOperation.php`: Yazılım Yönetimi. The page lists what the agent reports (`component.list`) with what the panel knows uses each component (sites on Apache or a PHP version, databases, Laravel applications), refuses to remove what is in use, and runs installs, updates and removals as queued jobs whose log the page reads live (`component.run.read`). `php artisan serfex:components-check` (scheduled daily) refreshes the available updates. `app/Services/Laravel/Redis.php` gives a Laravel application its own Redis instance.
- `app/Models/Backup*.php`, `app/Services/Backups/`, `app/Livewire/Backups/BackupIndex.php`: Yedekler. `Backups` takes server-wide and per-site backups through the agent (`backup.create`) on the `backups` queue (`RunBackup`), keeps the incremental chains and the retention (`prune`), and lists problems for the dashboard; `Restores` puts sites back (a safety backup first, a deleted site is set up again from `site.json`); `BackupImports` adds archives from the import folder, an upload or a target; `BackupSettings` holds the schedule and the encryption passphrase; `PanelDump` sends the panel's database and `.env` for the panel archive. `php artisan serfex:backups` (scheduled every five minutes) starts scheduled backups; `php artisan serfex:restore-server` rebuilds a server from a whole-server backup.
- `app/Console/Commands/CreateAdmin.php`: `php artisan serfex:admin` creates the first administrator account, or resets it.
- `resources/views/components/`: Blade layout and shared components. Colours come from the design tokens in `resources/css/app.css`, copied from the approved mockup in `design/`.
- `resources/icons/`: the Lucide icons the UI uses (ISC licence), inlined by `<x-icon name="..."/>`.
