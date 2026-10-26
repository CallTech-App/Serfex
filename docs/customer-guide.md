# Customer guide

This guide is for people whose hosting runs on a Servik server: your hosting
company gives you a sign-in link, and everything below is in your own panel.
What you can use depends on your plan; anything your plan does not include is
marked "Not in your plan".

## Signing in

Open the sign-in link you were sent (it is valid for 7 days) and choose your
password. Then sign in at the panel's address with your email and password.

Under your name at the top right → **Account security** you can:

- choose the panel's language (Turkish or English);
- turn on **two-step verification** with an authenticator app (Google
  Authenticator, Microsoft Authenticator, 1Password, Bitwarden…) and keep the
  recovery codes somewhere safe;
- see and sign out your open sessions and command line sign-ins.

## Overview and usage

The first page shows your plan's use: disk space, this month's traffic, and
how many sites, mailboxes, databases and other items you have against the
plan's limits. **Usage** has the details by site and the last 12 months.
You get an email when you reach 85% and 100% of a limit. Your sites keep
running over the traffic limit; a full disk only stops new sites, mailboxes,
uploads and backups.

## Sites

**Sites → Add site**: type the domain and choose the PHP version. The site
opens in a few seconds at `/home/<your user>/web/<domain>/public_html`, and
gets a free SSL certificate once the domain points at the server (until then
it says "DNS does not point to this server yet"). Until you upload your own
`index.html` or `index.php`, visitors see Servik's default page.

On a site's page:

- **File Manager**: upload (up to 2 GB per file), edit, zip and extract,
  permissions. Deleted files stay in the trash for 7 days.
- **FTP accounts**: SFTP accounts for one folder of the site. Connect with
  an SFTP program (FileZilla, WinSCP, Cyberduck) on port 22; plain FTP is not
  supported. The password is shown once.
- **PHP & web server**: the PHP version and settings such as
  `memory_limit` and `upload_max_filesize`.
- **Scheduled tasks**: run a command every night, every hour or every few
  minutes.
- **Git deployment**: the site's folder (public_html by default) comes from
  a Git repository. Connect a public `https://` address, one opened with an
  access token, or an SSH address with the deploy key Servik makes; then
  **Deploy**, or add the webhook address to the repository to deploy on
  every push. Files the repository does not track stay; any earlier deploy
  can be put back, and the first one undone.
- **Statistics**: visitors, pages, bandwidth, referrers, countries and bots.
- **Logs**: the site's access and error logs.
- **Domains**: subdomains, other domains that open the same site, and
  redirects.
- **Error pages**: your own 403, 404 and 50x pages.
- **SSL certificate**, **Security scan**, **Password-protected folders** and
  the **web application firewall**, if your plan includes security tools.

## Domains & DNS

Your domains' DNS records, if the server hosts your DNS: A, AAAA, CNAME, MX,
TXT, NS, SRV and CAA. To use it, set your domain's name servers at your
registrar to the ones your hosting company gives you.

## Email

Email turns on for your sites' domains. On a domain's page:

- **Create mailbox** with a quota. **Webmail** opens it in the browser
  without a password.
- Forwarding, auto-replies, and forwarders to other addresses, including a
  catch-all for unknown addresses.
- **Email client settings**: IMAP 993, POP3 995 and SMTP 465 or 587 on the
  mail server's name, with the full email address as the username.
  Thunderbird and Outlook find these by themselves.
- **DNS records**: the MX, SPF, DKIM and DMARC records your mail needs. If
  your DNS is elsewhere, add the ones shown there.

## Databases

**Create database** for a site with a user (names start with your account's
prefix). The password is shown once; copy it then. **phpMyAdmin** or
**Adminer** opens the database in the browser without a password. A user can
be allowed to connect from your own computer's address.

## Laravel

If your plan includes Laravel: install an application from a Git repository
or start a new project, deploy without downtime, roll back, run Artisan,
manage queues, the scheduler and `.env`, and give your developers access.
See the [Laravel guide](laravel.md).

## Backups

Your sites are included in the server's scheduled backups. **Take a backup**
makes one yourself (up to your plan's limit); **Restore** puts back files,
databases, email or DNS from any of them, after backing up the current state
so you can undo.

## Command line

The `servik` command line works with your account too:

```sh
curl -fsSL https://<panel address>:8443/cli/install.sh | sh
servik login <panel address>:8443
servik sites list
```

See the [command line reference](cli.md).
