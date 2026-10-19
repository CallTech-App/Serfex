# Servik panel

The Laravel application behind the Servik web UI. See the
[repository README](../README.md) for setup, and [CONTRIBUTING.md](../CONTRIBUTING.md)
for the checks to run before opening a pull request.

Key pieces:

- `app/Services/Agent/AgentClient.php`: JSON-RPC 2.0 client for `servik-agent`.
- `config/servik.php`: agent socket path and timeouts (`SERVIK_AGENT_SOCKET`, `SERVIK_AGENT_TIMEOUT`, `SERVIK_AGENT_JOB_TIMEOUT`).
- `app/Models/Site.php`, `app/Models/SystemUser.php`: a site belongs to a Linux account; an account can own several sites. Customers and plans will own accounts in a later milestone.
- `app/Jobs/ProvisionSite.php`, `app/Jobs/DeleteSite.php`: queued jobs that call the agent (`site.apply`, `site.delete`) and record the result on the site. Run `php artisan queue:work`.
- `app/Models/Certificate.php`, `app/Services/Ssl/`: TLS certificates. `Certificates` requests Let's Encrypt certificates, installs uploaded ones and swaps them on a site (the old one is removed after 10 minutes, so the swap can be undone); `DnsCheck` keeps names that do not resolve to this server away from Let's Encrypt.
- `app/Jobs/IssueCertificate.php`, `app/Jobs/IssuePanelCertificate.php`, `app/Jobs/DeleteCertificate.php`: agent calls for `ssl.issue`, `ssl.panel.issue` and `ssl.delete`. `php artisan servik:ssl-renew` (scheduled hourly, run `php artisan schedule:work` in development) renews certificates 30 days before expiry and retries requests waiting for DNS.
- `app/Livewire/Ssl/`: the SSL Sertifikaları list, the per-site SSL page and the SSL settings (Let's Encrypt e-mail, automatic SSL for new sites, panel address).
- `app/Models/MailDomain.php`, `Mailbox.php`, `MailForwarder.php`, `app/Services/Mail/`: e-mail. `MailDomains` turns mail on and off and changes mailboxes and forwarders (removed mail is kept 10 minutes for Undo); `MailPassword` hashes mailbox passwords for Dovecot, so no password is stored; `MailDns` writes MX, SPF, DKIM, DMARC and client-settings records into the domain's zone and checks them against public DNS (`SERVIK_MAIL_CHECK_DNS`).
- `app/Jobs/SetupMailServer.php`, `SyncMailDomain.php`, `DeleteMailDomain.php`, `PurgeMailbox.php`, `IssueMailCertificate.php`: agent calls for `mail.setup`, `mail.domain.apply`, `mail.domain.delete`, `mail.mailbox.purge` and the mail server's certificate. `php artisan servik:mail-usage` (scheduled every 30 minutes) records mailbox sizes.
- `app/Livewire/Mail/`: the E-posta list, the mail domain page (mailboxes, forwarders, settings and DNS records) and the e-mail settings; `app/Http/Controllers/Mail/WebmailController.php` opens a mailbox in Roundcube with a one-time link.
- `app/Livewire/Sites/`: the Siteler list (with the "Site ekle" form), the site detail page and the site's PHP ve Web Sunucusu page (PHP version, Nginx or Nginx + Apache).
- `app/Services/Components/Components.php`, `app/Livewire/Components/ComponentIndex.php`, `app/Jobs/RunComponentOperation.php`: Yazılım Yönetimi. The page lists what the agent reports (`component.list`) with what the panel knows uses each component (sites on Apache or a PHP version, databases, Laravel applications), refuses to remove what is in use, and runs installs, updates and removals as queued jobs whose log the page reads live (`component.run.read`). `php artisan servik:components-check` (scheduled daily) refreshes the available updates. `app/Services/Laravel/Redis.php` gives a Laravel application its own Redis instance.
- `app/Console/Commands/CreateAdmin.php`: `php artisan servik:admin` creates or resets the single administrator account.
- `resources/views/components/`: Blade layout and shared components. Colours come from the design tokens in `resources/css/app.css`, copied from the approved mockup in `design/`.
- `resources/icons/`: the Lucide icons the UI uses (ISC licence), inlined by `<x-icon name="..."/>`.
