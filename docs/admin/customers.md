# Customers and plans

**Customers & Plans** is where you sell hosting: a customer is a company or a
person with a panel login, one or more system accounts, and a plan.

## How ownership works

A customer owns system accounts; an account owns sites; a site owns its DNS
zone, email, certificates, databases and Laravel applications. Sites without
a customer are yours (the administrators'). Linking an account to a customer
moves all of its sites to them, and they count toward the customer's plan.

## Plans

Four plans come with Serfex; edit them or add your own. An empty limit is
unlimited. A change to a plan applies at once to every customer on it, and
never deletes anything that is already over a new limit.

| Limit | Starter | Professional (default) | Laravel Pro | Business |
|-------|---------|------------------------|-------------|----------|
| Sites | 1 | 5 | 10 | unlimited |
| Disk | 5 GB | 20 GB | 50 GB | 100 GB |
| Traffic per month | 100 GB | 500 GB | 1,000 GB | 2,000 GB |
| Mailboxes | 5 | 25 | 25 | unlimited |
| Databases | 2 | 10 | 20 | unlimited |
| Laravel applications | none | 2 | 10 | unlimited |
| Subdomains / domain aliases | 5 / 2 | 25 / 10 | 50 / 20 | unlimited |
| SFTP accounts | 2 | 10 | 20 | unlimited |
| Scheduled tasks | 2 | 10 | 20 | unlimited |
| Manual backups | 3 | 5 | 10 | unlimited |
| PHP memory limit at most | 256 MB | 512 MB | 1,024 MB | unlimited |

Plans also choose which PHP versions new sites may use and the
**features**: DNS, email, Laravel, backups, security tools and PHP settings
(all on, except Laravel on Starter).

## Customers

**Add customer**: company, contact person, email, phone, system user and
plan. Serfex shows a **sign-in link** (valid 7 days) to send to the customer;
they set their own password. Serfex does not email it.

A customer's page shows their sites, usage, details and plan, with:

- **Add site** / **Link an existing site**.
- **Usage**: disk (home folder, mailboxes, databases, application backups,
  measured every 30 minutes) and monthly traffic, with a 12-month history.
  Customers get an email at 85% and 100% of their quota. Nothing is cut off
  when they go over; a full disk only refuses new sites, mailboxes,
  applications and backups.
- **Suspend**: their sites show an "out of service" page (Let's Encrypt
  renewals still work), incoming mail is deferred and mailboxes cannot sign
  in, SFTP accounts and scheduled tasks are locked, Laravel queues and
  processes stop, and the customer cannot sign in to the panel. Their DNS
  keeps answering. Nothing is deleted; **Activate** brings everything back.
- **Sign in as customer**: open the panel exactly as they see it, with a
  banner to return.
- **Delete customer**: type the username. Choose whether their sites are
  deleted or stay on the server without a customer. It can be undone for 10
  minutes.

## What a customer sees

Only their own sites, DNS, email, databases, certificates, backups, Laravel
applications and usage, within their plan's features. They do not see Live
Monitor, Serfex AI, Security (except the per-site tools their plan includes),
Services, Software or Settings. See the [customer guide](../customer-guide.md).

## From other systems

A billing system can create, suspend and unsuspend customers through the
API: see [API](../api.md).
