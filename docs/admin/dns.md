# DNS

Servik can answer DNS for your domains itself, with PowerDNS Authoritative.
The panel is the source of truth: you change records in the panel, and the
agent publishes exactly those records with a new serial number. PowerDNS
runs as its own instance, so the distribution's `pdns.conf` is left alone.

If your domains' DNS is elsewhere (Cloudflare, your registrar), you do not
have to install the DNS server: Servik then shows the records you need to add
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

## Not in 0.1

DNSSEC signing (shown as coming) and secondary DNS servers. Servik's
PowerDNS is the only authoritative server for its zones, so both name
server names point at the same server for now.
