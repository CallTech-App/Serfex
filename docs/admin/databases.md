# Databases

Customer databases run on their own servers, apart from the panel's
PostgreSQL. You install them from the panel when you need them.

## Database servers

**Databases → Database servers** installs and repairs:

- **MySQL** (default) or **MariaDB**: the two exclude each other.
- **PostgreSQL**, alongside either of them.
- The **web database manager**: phpMyAdmin for MySQL and MariaDB, Adminer for
  PostgreSQL, served by the panel's own web server on the panel port (8443).

### Supported versions

Servik installs only the major versions it supports, each from its vendor's
own apt archive, which Servik adds to apt when the server is installed:

| Server | Versions (default first) | Archive |
|--------|--------------------------|---------|
| MySQL | 9.7 LTS, 8.4 LTS | repo.mysql.com |
| MariaDB | 12.3 LTS, 11.8 LTS | dlm.mariadb.com |
| PostgreSQL | 18 | apt.postgresql.org |

The install confirmation (Software) and the setup card (Databases) let you
choose among them and show the package version each would bring. A new major
version (PostgreSQL 19, say) is never installed because the vendor published
it: it is added to this list by a Servik release once it has been tested.

A server then stays on its major version; Software → Database servers →
**Update** installs its minor releases. If the archive cannot be reached, the
installation fails and is rolled back; Servik does not fall back to Ubuntu's
older versions. A server installed earlier in a version no longer listed
(Ubuntu's MySQL 8.0, MariaDB 10.11, PostgreSQL 16) keeps running in it and
gets its minor updates. Removing MySQL or MariaDB also takes its archive out
of apt.

A failed installation is rolled back. A database server listens only on the
server itself until some database user is allowed to connect from outside;
then it listens publicly and admits only the addresses you allowed.

Tuning (MySQL connections, buffer pool, packet size) is under Services →
the database service → Settings.

## Databases and users

Names start with the account's prefix: for account `kahve`, databases and
users are `kahve_<name>`.

- **Create database**: pick the site, the server and a name. Servik can
  create a user with the same name in the same step. The password (24
  characters, or your own) is shown once with the connection details and is
  never stored.
- **Add user**: grant it all privileges, read and write, or read only, on
  any of the account's databases. **Remote connection** lists allowed
  addresses: an IP address, a network (`88.247.120.0/28`), a prefix
  (`10.8.0.%`) or `%` for anywhere.
- **Open in phpMyAdmin / Adminer** from the database's page: a one-time link
  valid for a minute signs you in with a temporary user that is removed after
  four hours. No password is needed.
- **Delete database**: type its name. A compressed dump is taken first by
  default and kept for 30 days in `/var/lib/servik/backups/databases`; users
  lose access at once and the data is deleted after 10 minutes, so it can be
  undone until then.

Database sizes are measured every 30 minutes and count toward the customer's
disk quota.

## Laravel

The Laravel install wizard can create a database and user for the
application (`<prefix>laravel` by default) and writes `DB_*` into its `.env`.
On a database's page, **Link** connects an existing database to a Laravel
application.

The panel's own PostgreSQL is a separate cluster on port 5433, on the version
the installer gave it (the newest stable one for new installs; servers installed
before PostgreSQL came from apt.postgresql.org keep 16); a customer PostgreSQL server uses the standard port 5432.
