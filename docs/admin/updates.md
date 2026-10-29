# Updates

Serfex updates itself from its GitHub Releases. An update replaces the panel,
the agent and the command line; Ubuntu's packages and the customers'
software (PHP, MySQL, Nginx…) are updated separately, under Software (its
Ubuntu updates tab, see [Services, PHP and software](services.md#ubuntu-updates)).

## Updating from the panel

**Settings → Updates** checks every day at 04:30 (`serfex:update-check`) and
shows the new version with its release notes. Nothing is installed until you
press **Update**. Then:

1. the panel backs itself up (database and settings; these backups are kept
   30 days);
2. the new release is unpacked into `/opt/serfex/releases/<version>` next to
   the running one, and its checksum is verified against `SHA256SUMS`;
3. the panel goes into maintenance for about a minute: sites, email and DNS
   keep running;
4. migrations run and `/opt/serfex/current` switches to the new release;
5. the agent and the panel's services restart, and a health check waits up to
   90 seconds for the panel's `/up` and its workers.

If the health check fails, the previous release **and its database** come
back on their own. The progress and the full log stay on the page, and every
update is listed in the update history.

**Return to the previous version** goes back to the release before, rolling
its migrations back. The previous release stays on the server for this until
the next update.

## Update policy

- **Channel**: *Stable* takes only published releases. *Beta* also takes
  pre-releases (tags such as `v0.2.0-beta.1`); they are not recommended on
  live servers.
- **Releases kept** on disk.

## From the command line

```sh
sudo -u serfex php8.5 /opt/serfex/current/panel/artisan serfex:update --check
sudo -u serfex php8.5 /opt/serfex/current/panel/artisan serfex:update
sudo -u serfex php8.5 /opt/serfex/current/panel/artisan serfex:update --rollback
```

## Notes

- Releases are verified with SHA-256 checksums from the same release; they
  are not signed yet.
- Updates come from `https://api.github.com/repos/CallTech-App/Serfex` (the
  `source` in `/etc/serfex/panel.json`, set by `install.sh --source`).
