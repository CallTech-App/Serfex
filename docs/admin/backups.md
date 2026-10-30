# Backups

**Backups** takes whole-server and per-site backups, restores them in part or
in full, and copies them to remote destinations. Backups run at low priority
(nice, ionice and a small systemd CPU and I/O weight), one at a time, so they
do not slow the sites down.

## What a backup holds

- **Whole server**: every site's files, databases, email, DNS zones and
  certificates, and the panel's own database and settings.
- **Single site**: the site's files, databases, email, DNS records and
  certificates.

Customer files are archived by the customer's own account, never as root.
A file that account cannot read (for example a copy made with `sudo cp`,
owned by root) is left out instead of failing the site's backup: the
backup's details list it as a warning with the account to give it to
(`sudo chown <user>: <file>`), or delete it. Once fixed, the next backup,
incremental or full, takes it.
`*/storage/framework/cache/*` and `*/node_modules/*` are left out by
default; add your own exclusions and sites to skip under Schedule →
Advanced.

## Schedule

The defaults:

| | |
|-|-|
| Automatic backup | On: the whole server, the panel included |
| Time | Every day at 03:00 (in the panel's time zone) |
| Full backup | Sundays; the other days are incremental (only what changed) for files and email. Databases are always dumped in full. |
| Retention | 7 daily, 4 weekly, 3 monthly. An incremental chain is never cut. |
| Local disk | Kept here until a remote destination is added |

Manual backups (**Take a backup**) stay until you delete them.

## Destinations

**Destinations → Add destination**:

- **S3-compatible storage**: Amazon S3, Backblaze B2, Wasabi, Hetzner Object
  Storage, Cloudflare R2, MinIO or any other S3-compatible service (endpoint,
  region, bucket and keys).
- **SFTP**: any server with SSH. Serfex shows its own public key to add
  there, and remembers the server's host key at the first test.

A connection and write test runs before a destination is saved. Copies sent
to a remote destination are encrypted with AES-256. The passphrase is made
for you and shown under Schedule → Advanced → Encryption: **note it outside
the server**, because you need it to open the backups on a new server if this
one is lost. With a remote destination, the local copy is not kept unless
you tick *Also keep the newest backup on this server*.

## Restoring

Open a backup → **Restore**, and choose what to restore: files, databases,
email, DNS, certificates. By default the current state is backed up first,
so the restore itself can be undone (safety backups are kept 7 days). A
deleted site is created again from the backup.

Every site is backed up on this server just before it is deleted (from the
site page, the API or with its customer). These *Before deletion* backups
are kept for 30 days by default, set under Schedule → *Backups before
deletion*, and restore like any other: everything is ticked when the site no
longer exists.

A backup that is only on a remote destination is fetched to the server first
(and kept 24 hours). Archives can be downloaded too.

## Import

**Import** brings another Serfex server's backup here: upload a file, drop
archives into `/var/lib/serfex/backups/import/`, or scan a destination.
cPanel account backups and Plesk subscriptions are moved with Sites → **Move
from another server** (see [Sites](sites.md#moving-an-account-from-cpanel)).

## Rebuilding a server

To move to a new server, or after losing one:

1. Install Serfex on the new server (see [Installation](../installation.md)),
   then the same database servers and the mail server.
2. Add the old server's destination (with the encryption passphrase) and
   scan it, or import the archive.
3. On the server:

   ```sh
   sudo -u serfex php8.5 /opt/serfex/current/panel/artisan serfex:restore-server            # lists whole-server backups
   sudo -u serfex php8.5 /opt/serfex/current/panel/artisan serfex:restore-server KEY --panel --sites
   ```

## Customers

A customer sees their sites' backups, can take manual backups up to their
plan's limit (Starter 3, Professional 5, Laravel Pro 10), restore them, and
delete only their own manual ones. The plan's *Backups* feature turns this
on.

Laravel applications also have their own backups (code, `.env`, storage and
database) under Laravel → the application → Backups; see [Laravel](../laravel.md).
