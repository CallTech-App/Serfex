# Security

**Security** has four tabs for the server; each site has its own security
tools on its page.

## Firewall

A port firewall in its own nftables table. It is **off** until you turn it
on; then only the ports of the open rules answer.

- The panel port is always open and locked, and your own address can never
  be closed out by a rule.
- Web (80, 443) and SSH are open; DNS and mail are open when those servers
  are installed; remote database ports are closed unless you open them.
- Add rules for your own ports (for example a Node.js application), limited
  to some addresses if you like, and list addresses that are always refused.

Blocking a single attacker is done from Live Monitor → Attacks or with
**Block address**: that drops every connection from the address (web, mail,
SSH) for the time you choose. Blocks lift by themselves when they expire.

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
  per 10 minutes. Fail2ban's `servik-panel` jail closes the panel port for an
  hour to an address with 10 failures in 15 minutes.
- **Fail2ban** also watches SSH and, with the mail server, Dovecot and Postfix
  sign-ins (5 failures in 10 minutes ban for an hour, repeat offenders for a
  week). Its bans show under Live Monitor → Attacks and can be lifted there.
- **SSH**: root sign-in and password sign-in. Password sign-in cannot be
  turned off until an administrator has an SSH key. SFTP accounts always
  sign in with a password.

## Per site

On a site's page, under Security: the SSL certificate, the security scan and
blocked paths, password-protected folders, and the site's WAF mode and
exceptions. Customers see these when their plan includes *Security*; the
firewall, SSH and IP blocking stay with administrators.

## Reporting a vulnerability in Servik

See [SECURITY.md](../../SECURITY.md).
