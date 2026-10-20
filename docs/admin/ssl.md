# SSL certificates

Servik gets free certificates from Let's Encrypt with its own ACME client
inside the agent (no certbot), and renews them on its own.

## How a site gets its certificate

When a site is added, Servik asks Let's Encrypt for a certificate covering the
domain, `www` and its aliases and subdomains, as soon as they resolve to the
server. Before that the site shows **DNS does not point to this server yet**,
opens over plain http, and Servik tries again every hour.

- **Challenge**: through the web server (HTTP-01) by default. When the
  domain's DNS zone is on this server, Servik can use a DNS record instead,
  which also allows a **wildcard** certificate (`*.example.com`).
- **Keys**: ECDSA P-256.
- **Renewal**: 30 days before expiry, checked hourly
  (`servik:ssl-renew`). A failed renewal keeps the old certificate in place
  until it expires, and shows on the SSL list and the overview; you are
  warned 14 days before expiry.
- **HTTPS redirect**: on by default (301 from http to https). HSTS is not
  sent.

## The SSL pages

- **SSL Certificates** lists every site's certificate with issuer, expiry,
  automatic renewal and redirect, and puts problems first. The panel's and
  the mail server's certificates are listed here too.
- A site's **SSL certificate** page (site → Security → SSL certificate):
  **Renew now**, **Change coverage** (extra names, wildcard), **Upload
  certificate** (certificate, private key and an optional CA bundle; uploaded
  certificates are renewed by uploading the new one), **Remove certificate**
  and the HTTPS redirect.
- **SSL settings**: the email address for the Let's Encrypt account and
  whether new sites get a certificate automatically.

## The panel's and the mail server's certificates

- The panel's own certificate is under Settings → General → Panel
  certificate: Let's Encrypt for the panel address, or an uploaded one.
  Until then the panel uses a self-signed certificate.
- The mail server's certificate (for IMAP, SMTP and webmail) is under
  Email → Email settings, for the mail server's name.

## Developing without public DNS

On a development machine with the dry-run agent, set
`SERVIK_SSL_CHECK_DNS=false` in `panel/.env` so the panel does not wait for
public DNS. Certificates then come from a simulated CA.
