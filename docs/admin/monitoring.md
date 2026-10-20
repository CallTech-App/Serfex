# Live monitoring and Servik AI

## Overview

**Overview**, the first page, is one row per area, problems first: live
monitoring (attacks, busy sites), services, sites (with this month's
traffic), server resources, customers (quotas), SSL certificates, the security
scan, your account (two-step verification), backups and the Servik version.
Each row links to the page where it is fixed; for some, **Ask AI** asks Servik
AI to explain.

## Live Monitor

**Live Monitor** samples the server every 2 seconds and keeps 24 hours of
detail. It has four tabs.

### Sites

Per site: CPU (100% is one whole core), memory, PHP workers in use,
requests per second, disk I/O, database connections and mail sent per hour,
averaged over the last 10 seconds to 10 minutes. Processes are attributed to
a site by its PHP-FPM pool, its Laravel units and its account.

**Step in on the site** opens the site's last 10 minutes and three actions:

- **Temporary resource limit**: CPU, memory and PHP workers for a time you
  choose. Other sites are not affected and the limit lifts by itself.
- **Restart** PHP: cuts stuck requests; new workers answer within seconds.
- **Block** the addresses sending it the most requests.

### Attacks

Addresses that cross one of the rules: 300 requests a minute from one
address, 20 tries a minute on a sign-in page, 60 missing pages a minute, or
10 failed mail or SSH sign-ins in 10 minutes. They stay listed for 10 minutes
after they go quiet. **Nothing is blocked automatically by these rules**; you
block with one click, for a time you choose.

**Blocked addresses** lists the blocks in force, from you, Servik AI and
Fail2ban. A Servik block drops every connection from the address (web, mail,
SSH); a Fail2ban block closes only the attacked service's port.

### Mail queue

Postfix's queue by sender domain, with **Hold**, **Release** and **Retry the
queue now**. See [Email](mail.md).

### System

CPU, load, memory, disk, network, uptime, and the services in their groups.
**Resource history** shows the server's CPU, memory, disk and network for
24 hours, 7 days, 30 days or 12 months; hourly values are kept 400 days.

## Servik AI

An assistant inside the panel, running on Claude with your own API key. It
reads the server's state through the panel (sites, logs, live monitoring,
certificates, deployments, mail queue, services, backups) and answers in your
language. It has no shell, no root and no direct access to the agent: every
change goes through the same panel services you use.

- Ask on the **Servik AI** page or from the drawer at the top of every page.
  Each answer shows where it looked.
- Changes are offered as cards you **Approve and apply** or cancel, and most
  can be undone.
- **Actions without approval** (on by default): it may block an attacking
  address for up to 24 hours and limit a site for up to 2 hours on its own.
  Both lift by themselves and can be undone with one click. Turn this off and
  they become suggestions too. Nothing else is ever done without approval.
- **Servik AI → Settings** (full administrators): the Claude API key (stored
  encrypted with the panel's `APP_KEY`), the model, the actions without
  approval, and a monthly spending limit. Usage and estimated cost per month
  are shown below.
- **Servik AI log** records everything it read and did.

Read-only administrators do not see Servik AI. Customers do not have it in
0.1.
