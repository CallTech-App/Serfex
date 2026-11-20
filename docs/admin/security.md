# Security

**Security** has four tabs for the server and the **IP blocks** page; each
site has its own security tools on its page.

## Firewall

A port firewall in its own nftables table. It is **off** until you turn it
on; then only the ports of the open rules answer.

- The panel port is always open and locked, and your own address can never
  be closed out by a rule.
- Web (80, 443) and SSH are open; DNS and mail are open when those servers
  are installed; remote database ports are closed unless you open them.
- Add rules for your own ports (for example a Node.js application), limited
  to some addresses if you like, and list addresses that are always refused.

Blocked addresses are refused before these rules are read, whether the
firewall is on or off; see [IP blocks](#ip-blocks).

## IP blocks

**Security → IP blocks** (the button at the top right of Security, or
Ctrl+K) is the one list of every address the server refuses, whoever
blocked it: you, Serfex AI, the API or Fail2ban. A Serfex block drops every
connection from the address (web, mail, SSH, the panel); a Fail2ban ban
closes only the port of the service that was attacked, and the list says
which. The same page refuses whole [countries](#countries).

### Blocked addresses

- Filter by who blocked, search by address, reason, site or country, and
  sort by newest or by ending soonest. Typing an address also finds the
  blocked range that holds it; for an address that is not blocked the page
  offers **Look at the address** and **Block**.
- **Block IP** takes one address or a pasted list: one address or range
  per line, or separated by commas, spaces or semicolons; anything after
  `#` on a line is a comment. Before anything is blocked the dialog says how many
  are new, how many are blocked already (their time is changed) and which
  are left out and why: not an address, a range wider than /16 (IPv6 /48),
  your own address, the server's addresses, Cloudflare's addresses, an
  allowed address, or one that overlaps an existing block. At most 1,000
  addresses at a time. Durations: 1 hour, 24 hours, 7 days, 30 days or
  permanent.
- Tick rows (or the whole page, or **Select all N addresses** of the
  filtered list) to **Unblock**, **Change duration** or **Add to allowed**
  in one step. Changing the duration of a Fail2ban ban turns it into a
  block on the whole server.
- Every action has **Undo** in its message for 10 minutes: an undone bulk
  block is lifted and leaves no trace in History, an undone unblock comes
  back for the time it had left.

### One address

Click an address (here, or a suspect under Live Monitor → Attacks) to see
what it did on this server:

- **Sites visited**: per site the number of requests, the path asked for
  most, the answers by status class, refused requests (403) and the last
  request. The numbers are read from the sites' access logs when the page
  opens, newest first and as far back as the logs are kept (14 days by
  default), and are kept for 5 minutes; **Refresh** reads again. On very
  large logs the reading stops after 20 seconds and the page says the
  numbers may be incomplete.
- **Latest requests**: the newest 200 requests, narrowed by site, answer
  and path.
- **Block history**: every block and ban the address had, who set it and
  how it ended.
- **Block**, **Unblock**, **Change duration** and **Add to allowed** work
  here as on the list. An address inside a blocked range shows the range.

Requests that reach a site through Cloudflare are counted under the
visitor's real address.

### Allowed addresses

Addresses and ranges that are **never blocked**: not by hand, not by
Serfex AI, not by Fail2ban, not by a wider block that holds them (the
allowed address still gets through), and not by a country block. Use it
for your office, a monitoring service or a payment provider's callback
addresses.

- Adding an address lifts its blocks at once; the entry can carry a note.
- The server's own addresses are always on the list and cannot be removed.
  The address you are connected from is marked.
- The list is written to the firewall (checked before the blocked
  addresses), to Nginx and to Fail2ban's `ignoreip`.

### Countries

The **Countries** tab lists every country with the requests and visitors
it sent your sites in the last 30 days and the addresses of it that are
blocked one by one, so you can see what a block would cost before you set
it. Blocked countries are on top.

- **Block a country** takes the ticked countries, or countries you type by
  name or two-letter code. Nothing is blocked until you choose; an update
  never blocks a country by itself.
- The **scope** is one choice for all blocked countries:
  - **Websites only** (the default): every site answers visitors from the
    country with 403. Mail, SSH, FTP and the panel are not affected, and
    Let's Encrypt renewals keep working. The refusal is made in Nginx by
    the visitor's own address, so it also works for sites behind
    Cloudflare's proxy.
  - **The whole server**: besides the websites, the firewall drops new
    connections from the country to everything else: SSH, FTP, the panel,
    and mail sign-ins (IMAP, POP3, SMTP submission). Incoming mail (port
    25), DNS (53) and the web ports stay open, so messages from mail
    servers in those countries are not lost and your domains keep
    resolving there. The webmail page keeps opening; its sign-in is
    guarded by Fail2ban as before. Connections the server itself opens to
    those countries (updates, APIs, outgoing mail) are not affected.
- **Always let through:** the allowed addresses, the server's own
  addresses and Cloudflare's proxy addresses.
- **The country you are connected from cannot be blocked**, so you do not
  close the panel to yourself. If you travel and find yourself kept out,
  see [Locked out](#locked-out).
- The United States get a warning: Google, Bing and most payment,
  shipping and monitoring services connect from there. Add the ones you
  need to the allowed addresses.
- Blocking, unblocking and a change of scope have **Undo** in their
  message for 10 minutes.

A country here is where an address is **registered**, from the lists the
five regional internet registries publish (the same table as the site
statistics; downloaded once a month, no account or licence needed). It is
not a location service: a VPN, a cloud provider or a mobile network can
put a visitor under another country, so treat a country block as a way to
cut noise, not as access control. A newly installed server downloads the
table in its first minutes; until then no country can be blocked.

### History

Blocks that ended, for 90 days: who blocked, why, for how long, and how it
ended (ran out, lifted by a person, lifted from the server console, moved
to allowed, replaced by another duration, or lifted by Fail2ban). **Block
again** blocks an address for as long as before; tick several rows to
block them again together.

### Locked out

Your own address and the server's addresses cannot be blocked from the
panel. If you are kept out anyway (your address changed, or Fail2ban
banned you after wrong passwords), connect to the server by SSH, or from
your provider's console when SSH is closed to you too, and run:

```sh
sudo serfex firewall unblock 203.0.113.77
```

It lifts every block that holds the address: its own block, a blocked
range around it and Fail2ban's bans. Then add the address under **Allowed
addresses** so it cannot happen again.

If the country you are in is blocked, lift the country blocks:

```sh
sudo serfex firewall countries off
```

Every country is unblocked at once; the scope is kept for the next block,
and addresses blocked one by one are not touched.

## Web application firewall (WAF)

ModSecurity with the OWASP Core Rule Set, in front of every site on Nginx or
Apache. Install it from Software first.

- **Server mode**: *Monitor only* (default: attacks are logged, requests
  pass) or *Block* (refused with 403). Start with Monitor only to see false
  alarms.
- **Paranoia level** 1 to 4; 1 is the default and has the fewest false alarms.
- The tab lists the newest 100 events. Each site can have its own mode and
  rule exceptions (a rule off for the whole site or under a path): site →
  Security → Web application firewall.

The rules are OWASP CRS 4.25.0, from the project's GitHub release, checked
against its SHA-256 and unpacked under `/opt/serfex/crs`; if the download
fails, Ubuntu's CRS 3.3.5 package is installed instead. 4.25 is CRS's
long-term release line, but 4.25.1 and newer need libmodsecurity 3.0.16 and
do not load in Ubuntu 24.04's 3.0.12, so Serfex stays on 4.25.0 for now.

Servers that installed ModSecurity before Serfex 0.1.72 run Ubuntu's 3.3.5.
**Software → Other components → ModSecurity → Upgrade version** moves them
to 4.25.0: Nginx must accept the new rules and a plain request must still
get through, or the previous rules come back. The mode, paranoia level and
site exceptions stay. CRS 4 judges some requests differently, so on sites in
*Block* mode look over the events for false alarms in the first days. For
7 days **Go back to old version** returns to the previous rules; then
Ubuntu's rules package is removed (**Remove old version** does it earlier).

## Scan

Looks for malware and web shells (with ClamAV when installed), files anyone
can write, wrong owners, `.env`, `.git` and backup files readable from the
internet, and outdated software (WordPress below 6.6, Laravel below 12, PHP
below 8.2). It runs every Sunday at 03:20, and at once on files uploaded with
the file manager.

Each finding offers a fix: quarantine a file (kept 30 days), block a path
from the web (the file stays, it just cannot be downloaded), or go to the
setting. Findings can be ignored and reopen if they are seen again.

## Sign-in security

- **Two-step verification**: require it for nobody, for administrators, or
  for everyone (customers and Laravel team members too). Anyone can turn it on
  for their own account under Account security. An administrator can reset
  it for someone who lost their phone and recovery codes.
- **Panel sign-in**: 5 tries per account per minute, 30 failures per address
  per 10 minutes. Fail2ban's `serfex-panel` jail closes the panel port for an
  hour to an address with 10 failures in 15 minutes.
- **Fail2ban** also watches SSH and, with the mail server, Dovecot and Postfix
  sign-ins (5 failures in 10 minutes ban for an hour, repeat offenders for a
  week). Its bans show under Security → IP blocks and can be lifted there.
- **Panel pages** cannot be shown in a frame on another site (clickjacking):
  the panel sends `X-Frame-Options: DENY` and `frame-ancestors 'none'`.
  Opened by its name with the panel certificate, it also sends HSTS for a
  year, so browsers open that name only over HTTPS; HSTS leaves out
  subdomains. If that certificate ever lapses, the panel stays reachable at
  its IP address and port.
- **Search engines** never list the panel, its sign-in page, the database
  tools or webmail: every response sends
  `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet`, every panel page
  has a `robots` meta tag. `/robots.txt` lets crawlers in on purpose: a
  page they may not fetch is never seen to be `noindex`, and one already
  listed (found through a link) would stay in the results. The "no site"
  page of the default server is `noindex` too.
- **SSH**: root sign-in and password sign-in. Password sign-in cannot be
  turned off until an administrator has an SSH key. SFTP accounts always
  sign in with a password, and so do site users with SSH access that were
  given one (Sites → SSH access); Fail2ban's sshd jail covers them all.
- **SSH port**: Port → Change opens SSH on the new port (22, or a free port
  from 1024 to 65535) while the old one stays open. Try
  `ssh -p NEW root@server`, then choose "Connected, close port 22" or
  "Cancel, stay on port 22" in the notice at the top of the Security page;
  until then both ports stay open, and open sessions are never cut. The
  agent checks that sshd answers on every port after each step and puts the
  previous configuration back when it does not. Serfex's firewall, the
  Fail2ban sshd jail, ufw (when on) and the SFTP details on site pages
  follow the port; a firewall at your hosting provider does not, so open the
  new port there first.

## Per site

On a site's page, under Security: the SSL certificate, the security scan and
blocked paths, password-protected folders, and the site's WAF mode and
exceptions. Customers see these when their plan includes *Security*; the
firewall, SSH and IP blocking stay with administrators.

## Reporting a vulnerability in Serfex

See [SECURITY.md](../../SECURITY.md).
