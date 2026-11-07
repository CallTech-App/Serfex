# Release notes

`release-notes/<version>.md` (for example `0.2.0.md`) becomes the text of
that GitHub Release, which the panel shows on Settings → Updates before an
update. Serfex is used around the world, so release notes are written in
**English**, for the administrators who read them. Use the panel's English
names for pages and buttons (from `panel/lang/en.json`), for example
"Settings → Updates" rather than "Araçlar ve Ayarlar → Güncellemeler".

Open with one sentence that sums up the release, then write one Markdown
list item per change:

```markdown
Laravel deployments now show the full output when a migration fails.

- **Security:** the file manager can no longer read outside the site folder.
- **Laravel:** a failed `migrate` is shown with its full output.
```

The Release workflow refuses notes that contain Turkish-only letters
(ğ, ı, ş, İ). Without a file the release lists the merged pull requests
instead.

To publish, either run the Release workflow on `main` from the Actions tab
(Run workflow, version `0.2.0`), which tags the current `main` after a
clean build, or push the tag yourself:
`git tag v0.2.0 && git push origin v0.2.0`. A tag with a
suffix (`v0.2.0-beta.1`) is a pre-release, offered only to servers on the
beta channel.

To correct the notes of a release that is already published, change its
file on `main` and run the **Release notes** workflow from the Actions tab.
It rewrites the text of every published release that has a file here (or
only the versions you give it) and leaves the release archives alone.
Installed servers see the new text at their next update check.
