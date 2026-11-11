# DNS

Serfex can answer DNS for your domains itself, with PowerDNS Authoritative.
The panel is the source of truth: you change records in the panel, and the
agent publishes exactly those records with a new serial number. PowerDNS
runs as its own instance, so the distribution's `pdns.conf` is left alone.

If your domains' DNS is elsewhere (Cloudflare, your registrar), you do not
have to install the DNS server: Serfex then shows the records you need to add
there (for example the mail records).

## Setting it up

1. **Domains & DNS → Install the DNS server.** Zones that were waiting are
   published as soon as it runs.
2. **Domains & DNS → Server DNS settings**:
   - *Name servers*: `ns1.example.com, ns2.example.com`. Register them at
     your registrar as glue records with the server's address. Changing them
     later can also update existing zones that use the old names.
   - *Name server IP addresses*: one field per name server above. Empty
     means the server's IPv4 address; enter another address when a name
     server runs on a second server (or on this server's second address).
     Its A record in its zone here moves to that address, and it gets no
     AAAA record from this server.
   - *Server IPv4 / IPv6 address*: what new zones point at. With no IPv6
     address, new zones get no AAAA record.
   - *Default TTL* and the SOA defaults (administrator email, refresh,
     retry, expire, negative TTL).
   - *Record template for new domains*: by default the domain and `www`
     pointing at the server, plus the name servers. Edit it or restore the
     default; existing zones keep their own records.
3. At your registrar, set each domain's name servers to yours.

## Zones

A zone opens on its own when you add a site, and is linked to the site.
**Add domain** adds a DNS-only domain, starting from the server template or
empty (NS and SOA only). Customers cannot add DNS-only domains, or sites
under a zone another account owns.

A zone's page has:

- **Records**: A, AAAA, CNAME, MX, TXT, NS, SRV and CAA, with TTLs from 60
  seconds to a week. Mail, certificates (wildcard DNS challenges) and
  domain aliases add their own records; records you changed by hand are not
  overwritten.
- **Settings**: default TTL, SOA values, the serial number, **Export the zone
  file**, and **Restore default**, which writes the template again (with
  Undo right after).

If the DNS server refuses a change, the page says so and PowerDNS keeps
publishing the previous records.

## DNSSEC

A zone's **Settings** tab has **Sign the zone**. Serfex gives the zone one
ECDSA P-256 key (algorithm 13), proves missing names with NSEC3 (no extra
iterations, no salt, RFC 9276) and keeps the signatures fresh; records you
change later are signed too. Once it is on, the tab shows the **DS record**
(key tag, algorithm 13, digest type 2 = SHA-256, digest) and, for
registrars that ask for it, the public key (DNSKEY, flags 257). Enter them
in your registrar's DNSSEC section; the domain validates once the registrar
publishes them.

To turn it off, **remove the DS record at the registrar first** and wait a
day: while the registrar still lists it, validating resolvers cannot find
an unsigned domain. The panel asks before turning it off. The key is kept,
encrypted in the panel's database and in site backups, so turning signing
on again, undoing a zone delete or restoring the site (on this server or
another) brings back the same key and the DS record stays valid.

The zone list has a DNSSEC column.

## Secondary name servers

**Domains & DNS → Server DNS settings → Secondary name servers** lets other
DNS servers copy every zone, so your domains keep resolving if this server
is down. Enter their IP addresses (or networks), one per line:

- only these may transfer zones (AXFR); single addresses are told about
  every change at once (NOTIFY), networks see it at the SOA refresh time;
- every zone becomes a primary zone and is listed in a **catalog zone**
  (RFC 9432) named `catalog.<server host name>.invalid`. A secondary that
  reads it adds new domains and drops deleted ones by itself, within a
  minute;
- PowerDNS restarts for a second or two. Signed zones are transferred with
  their signatures.

The Services page shows the same list (PowerDNS → Settings) and links here.
Removing every address stops transfers and removes the catalog zone.

The section shows ready-to-paste configuration for the secondary:

- BIND 9.18 or newer: a `catalog-zones` entry in `options` and the catalog
  zone as a secondary zone in `named.conf.local`;
- PowerDNS 4.7 or newer (with `secondary=yes`): `pdnsutil
  create-secondary-zone <catalog> <this server>` and `pdnsutil set-kind
  <catalog> consumer`;
- a secondary DNS service without catalog zones: add each domain as a
  secondary zone with this server as the primary.

Then add the secondary's name to the domains' NS records (the name server
setting above, or each zone) and at the registrar. NOTIFY leaves from the
server's main address; a secondary that only accepts the primary address it
was given needs that same address. Another Serfex server cannot act as a
secondary yet.

## PowerDNS version

Serfex installs PowerDNS Authoritative 5.1 from PowerDNS's own archive
(`repo.powerdns.com`, suite `<codename>-auth-51`, pinned so that `pdns-*`
packages never come from Ubuntu). Ubuntu 24.04's own package is 4.8.3,
which PowerDNS stopped supporting in June 2026. If the archive cannot be
reached during setup, Ubuntu's version is installed and the upgrade below
is offered.

Servers set up before Serfex 0.1.72 run Ubuntu's 4.8.3. **Software → Other
components → PowerDNS → Upgrade version** moves them to 5.1:

1. PowerDNS's archive is added; if it cannot be read, nothing else changes.
2. The installed 4.8 packages are downloaded and kept, and every zone,
   record set and DNSSEC key is saved as the API shows it.
3. PowerDNS stops and its LMDB database is copied. PowerDNS 5 moves the
   database to a new format when it starts, and 4.x cannot read it again,
   so this copy is what going back uses.
4. 5.1 is installed and started; every zone, record set (the catalog
   zone's SOA aside) and DNSSEC key is compared with step 2, and the server
   must answer the SOA of the first zones on port 53.
5. Any failure puts the 4.8 packages and the copied database back.

The DNS server does not answer for about a minute. DNSSEC keys stay the
same, so the DS records at registrars stay valid. For 7 days **Go back to
old version** puts 4.8 and the copied database back and the panel writes
every zone again, so changes made after the upgrade stay; **Remove old
version** deletes the copies earlier. Point releases (5.1.x) then show as
updates on the same page.

## Cloudflare

Domains whose DNS is at Cloudflare are managed in Serfex like any other
zone: Serfex keeps the records in the panel and writes them to Cloudflare
with an API token. This server's DNS does not serve those zones.

### Connecting an account

**Domains & DNS → Cloudflare → Connect account.** Create the token at
Cloudflare: My Profile → API Tokens → Create Token → the **Edit zone DNS**
template, Zone Resources: All zones (or the zones you want). Add these
permissions for the rest:

| Permission | Used for |
|------------|----------|
| Zone → DNS → Edit | the records (required) |
| Zone → Zone Settings → Edit | setting the SSL/TLS mode to Full (strict) |
| Zone → Cache Purge → Purge | "Purge Cloudflare cache" and the purge after deployments |
| Zone → Zone → Edit and Account → Account Settings → Read | adding domains Cloudflare does not have yet |

**Check token** shows what the token can do before it is saved. The token
is stored encrypted and never shown again; **Renew token** replaces it. An
account still used by a zone cannot be removed. Several accounts can be
connected (your own and a customer's, for example).

### Defaults

- **Cloudflare proxy:** site addresses through the proxy (default) — the
  domain, `www` and the other names a site answers to get the orange cloud
  when Serfex writes them; mail, webmail, FTP and the panel's addresses are
  always DNS only. Or none through the proxy, turned on per record.
- **SSL mode:** Serfex sets the zone's SSL/TLS mode to **Full (strict)**
  once the site's Let's Encrypt certificate is in place (default on). It
  does so once; a mode you set at Cloudflare later is left alone. Flexible
  mode loops with Serfex's HTTPS redirect, so the zone page warns about it.
- **Laravel deployment:** purge the site's pages from Cloudflare's cache
  after every successful deployment (default off).
- **Visitor's real IP:** always on. Nginx takes the visitor's address from
  `CF-Connecting-IP` for requests that come from Cloudflare's addresses
  (`/etc/nginx/conf.d/serfex-realip.conf`), so logs, statistics, the WAF,
  the IP blocks and Laravel's `request()->ip()` see the real visitor.
  Cloudflare's address list is fetched every week; the agent ships with a
  copy.

### New sites

When you type a domain in **Add site**, Serfex looks for it in the connected
accounts. When it is found, **Cloudflare · found in the … account** is
selected and the records that will change at Cloudflare are listed (the old
server's `A` record → this server). The site's address records replace the
ones there; every other record (mail at another provider, verification
records, subdomains) is kept and taken into the panel. If mail is set up on
this server, only missing mail records are added.

A domain Cloudflare does not have can be added to an account (**Add to
Cloudflare**); then set the domain's name servers at the registrar to the
two Cloudflare shows on the zone page. Until Cloudflare sees them the zone
shows **Waiting for name servers** and the certificate waits; it is asked
for as soon as the zone is active.

If Cloudflare refuses (a token without permission, for example), the site
is still created and its DNS stays on this server.

### Existing zones

**Connect DNS to Cloudflare** on a zone's page compares the panel's
records with Cloudflare's and lists only the differences, each with what to
do: write Serfex's or keep Cloudflare's, take a Cloudflare-only record into
Serfex or delete it there, add a Serfex-only record to Cloudflare or delete
it in Serfex. **Settings → Move DNS back to this server** reverses it:
the zone is served by PowerDNS again (name server records are added back)
and nothing is changed at Cloudflare.

### Keeping both sides the same

Records can be changed in Serfex or in Cloudflare's dashboard. Serfex syncs
every 5 minutes (and right after a change in the panel); **Sync now** does
it at once and asks Cloudflare to check the name servers again.

- changed in Serfex → written to Cloudflare; changed in both → Serfex wins;
- changed only at Cloudflare → taken into Serfex;
- deleted in Serfex → deleted at Cloudflare; deleted at Cloudflare → deleted
  in Serfex (unless changed in Serfex since);
- new on either side → added to the other.

Record types Serfex does not keep (HTTPS, SVCB, …) and Cloudflare's own NS
and SOA records are left alone. A record Cloudflare refuses does not stop
the others; the zone shows the error. The zone page shows Cloudflare's
status, name servers, SSL mode, DNSSEC and what the last sync changed.

### Certificates

Certificates for names in a Cloudflare zone use DNS validation through
Cloudflare: the agent writes the `_acme-challenge` TXT records with the
zone's token, waits until Cloudflare's name servers answer with them, and
removes them afterwards. This works behind the proxy, and wildcard
certificates become possible.

### Blocking addresses

Cloudflare's addresses can never be blocked (the panel, Serfex AI and the
agent all refuse; Fail2ban ignores them): every visitor of a proxied site
comes from them. Block the visitor's real address instead; Nginx refuses
blocked addresses that arrive through Cloudflare too
(`/etc/nginx/conf.d/serfex-blocked.conf`). Fail2ban's own bans act on
connections, so they do not reach visitors behind the proxy.
