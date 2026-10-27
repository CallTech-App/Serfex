# Security policy

Servik runs as root on servers that host other people's sites and mail, so
we take vulnerabilities seriously and would rather hear about them early.

## Reporting a vulnerability

**Please do not open a public issue, pull request or discussion for a
security problem.**

Report it privately through GitHub: on the repository, open the
**Security** tab and choose **Report a vulnerability**
([direct link](https://github.com/CallTech-App/Serfex/security/advisories/new)).
Only the maintainers can see the report.

Please include:

- the Servik version (`servik version`, or Settings → Updates);
- what an attacker can do, and from where (a visitor, a customer, a Laravel
  team member, a support administrator, another user on the server);
- the steps to reproduce it, or a proof of concept;
- any logs or requests that help.

You can write in English or Turkish.

## What happens next

- We acknowledge the report within **3 working days**.
- We confirm the problem, tell you how serious we think it is, and agree a
  timeline with you. We aim to release a fix within **30 days** for serious
  problems, sooner when they are being exploited.
- The fix ships in a new release, which servers install from Settings →
  Updates. We then publish a GitHub security advisory, crediting you unless
  you prefer otherwise.

Please give us that time before you talk about the problem in public, and do
not access or change data that is not yours while you test.

## Supported versions

Servik is young: only the **newest release** gets security fixes. Update
from the panel to stay supported.

## In scope

Anything in this repository: the panel, `servik-agent`, the `servik` command
line, `install.sh`, `install-cli.sh` and the release build. Problems that
matter most to us:

- a customer, team member or visitor reaching another customer's data or the
  server;
- anything that makes the agent do more than its methods promise, or lets
  the panel's user become root;
- bypassing sign-in, two-step verification, roles, read-only mode or API key
  scopes;
- code execution, SQL injection, cross-site scripting or request forgery in
  the panel.

Vulnerabilities in the software Servik installs (Nginx, PHP, Postfix and so
on) belong to those projects, unless Servik configures them insecurely.
