# Release notes

`release-notes/<version>.md` (for example `0.2.0.md`) becomes the text of
that GitHub Release, which the panel shows on Araçlar ve Ayarlar →
Güncellemeler before an update. Write one Markdown list item per change,
in Turkish, for the administrators who read it:

```markdown
- Güvenlik: dosya yöneticisinde site klasörü dışını okuma kapatıldı.
- Laravel dağıtımında migrate hatası tam çıktıyla gösteriliyor.
```

Without a file the release lists the merged pull requests instead.

To publish: `git tag v0.2.0 && git push origin v0.2.0`. A tag with a
suffix (`v0.2.0-beta.1`) is a pre-release, offered only to servers on the
beta channel.
