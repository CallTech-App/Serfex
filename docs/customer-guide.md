# Customer guide

This guide is for people whose hosting runs on a Serfex server: your hosting
company gives you a sign-in link, and everything below is in your own panel.
What you can use depends on your plan; anything your plan does not include is
marked "Not in your plan".

## Signing in

Open the sign-in link you were sent (it is valid for 7 days) and choose your
password. Then sign in at the panel's address with your email and password.
If you forget the password, **Forgot password?** on the sign-in page mails
you a link that sets a new one (it works for 60 minutes).

Click your name in the menu to open **Account details**, where you can:

- change your name, phone number and the panel's language (Turkish or English);
- change your email address: type your current password, then open the link
  sent to the new address (it works for 24 hours). Until you do, you keep
  signing in with the old address.

Next to it, **Account security** lets you:

- change your password (your other sessions are signed out);
- turn on **two-step verification** with an authenticator app (Google
  Authenticator, Microsoft Authenticator, 1Password, Bitwarden…) and keep the
  recovery codes somewhere safe;
- see and sign out your open sessions and command line sign-ins.

## Overview and usage

The first page shows your plan's use: disk space, this month's traffic, and
how many sites, mailboxes, databases and other items you have against the
plan's limits. **Usage** has the details by site and the last 12 months.
You get an email when you reach 85% and 100% of a limit. Your sites keep
running over the traffic limit. A full disk stops new sites, mailboxes,
uploads and backups and, on most servers, any file writes: uploads, SFTP
and your sites' own writes fail and incoming mail waits (senders retry for
days) until you make room.

## Sites

**Sites → Add site**: type the domain and choose the PHP version. The site
opens in a few seconds at `/home/<your user>/web/<domain>/public_html`, and
gets a free SSL certificate once the domain points at the server (until then
it says "DNS does not point to this server yet"). Until you upload your own
`index.html` or `index.php`, visitors see Serfex's default page.

On a site's page:

- **File Manager**: upload (up to 2 GB per file), edit, zip and extract,
  permissions. Deleted files stay in the trash for 7 days.
- **FTP accounts**: SFTP accounts for one folder of the site. Connect with
  an SFTP program (FileZilla, WinSCP, Cyberduck) on port 22; plain FTP is not
  supported. The password is shown once.
- **SSH access** (if your plan has it): turn on SFTP for the site's own user,
  add your public key (`~/.ssh/id_ed25519.pub`) and connect with
  `sftp <user>@<your domain>`. You land in your home folder and see only
  your own files. A password is optional and shown once. Shell access is
  turned on by your server administrator.
- **Connection details**: the server address, the SFTP port and accounts,
  the site's databases with their users and the SSH commands on one page,
  ready to copy. Passwords are not kept; set a new one on the page linked
  next to each part.
- **PHP & web server**: the PHP version and settings such as
  `memory_limit` and `upload_max_filesize`.
- **Scheduled tasks**: run a command every night, every hour or every few
  minutes.
- **Git deployment**: the site's folder (public_html by default) comes from
  a Git repository. Connect a public `https://` address, one opened with an
  access token, or an SSH address with the deploy key Serfex makes; then
  **Deploy**, or add the webhook address to the repository to deploy on
  every push. With your GitHub, GitLab or Bitbucket account connected (your
  name → Git accounts, when the server offers it) you pick the repository and
  branch from lists, and Serfex adds the deploy key and webhook to it. Files
  the repository does not track stay; any earlier deploy can be put back, and
  the first one undone.
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

On a PostgreSQL database, **Extensions** → **Turn on** enables pgvector
(vector columns and similarity search, Laravel's `vector` columns). Your
application does not need to run `CREATE EXTENSION`.

## Laravel

If your plan includes Laravel: install an application from a Git repository
or start a new project, deploy without downtime, roll back, run Artisan,
manage queues, the scheduler and `.env`, and give your developers access.
See the [Laravel guide](laravel.md).

## WordPress

Site → **WordPress**: install WordPress in one click (the database and the
administrator account are made for you), or manage one already on the site.
Update WordPress, plugins and themes safely: a restore point is taken before
every update, a broken site is rolled back by itself, and you can undo the
last update for 7 days. **Sign in to WordPress** opens the dashboard without a
password. Maintenance mode and the recommended security settings are one
click each.

## Copying a site, cache and Composer

Site → **Copy site** makes a staging copy on an address such as
`staging.example.com`, with copies of the site's databases, hidden from
search engines. Try changes there, then **Publish to the live site**: the
live site is backed up first and the publish can be undone for 7 days. A copy
counts against your plan's sites and databases.

Site → **Cache & speed** turns on the page cache, browser cache and
compression. Signed-in visitors, carts and admin pages always get fresh pages.

Site → **Composer** and **Node.js** run Composer and npm commands in a folder
of your site, when your plan includes commands.

## Backups

Your sites are included in the server's scheduled backups. **Take a backup**
makes one yourself (up to your plan's limit); **Restore** puts back files,
databases, email or DNS from any of them, after backing up the current state
so you can undo.

## Command line

The `serfex` command line works with your account too:

```sh
curl -fsSL https://<panel address>:8443/cli/install.sh | sh
serfex login <panel address>:8443
serfex sites list
```

See the [command line reference](cli.md).
