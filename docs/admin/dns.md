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
