package site

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"strings"
	"syscall"

	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/txn"
)

// Renaming a site (Alan adını değiştir): the site keeps its account, files,
// databases and settings, and everything named after its domain follows
// the new name:
//
//   - its folder /home/<user>/web/<old> becomes /home/<user>/web/<new>, and
//     the old path stays as a link to it, so paths written into settings
//     files, crontabs or scripts keep working;
//   - links in the account's home that pointed into the old folder (Laravel's
//     storage link, cPanel's public_html) point into the new one;
//   - its Nginx logs, WAF and protected-folder snippets and error pages move
//     with it, snippets rewritten for their new place;
//   - files of other parts of the agent (Laravel's run logs and backups,
//     Redis data, task history) move as the caller says;
//   - the old name's Nginx, Apache and PHP-FPM files go, the new name's are
//     written, and the web server switches with one reload.
//
// The folder is renamed on the same file system, so nothing is copied and
// no file changes owner or mode. A failure at any step puts everything
// back and the site keeps answering under its old name.

// RenameSpec moves a site of an account from one domain to another.
type RenameSpec struct {
	// From is the site's domain now.
	From string
	// Site is the site under its new name; Site.Domain is the new domain.
	Site Spec
	// Before runs once the request has been checked and before anything
	// moves. It stops what runs under the old name (queue workers, Redis)
	// and returns more [old, new] paths to move with the site.
	Before func(ctx context.Context) ([][2]string, error)
	// Remove are managed files of the old name that go (its crontab);
	// they come back when the rename fails.
	Remove []string
	// Fit runs once the files are under the new name and before the web
	// server switches (addresses in settings files). It returns how to
	// undo it, used when a later step fails.
	Fit func(ctx context.Context) (func(context.Context), error)
}

// RenameResult describes a renamed site.
type RenameResult struct {
	Result
	// Links is how many links in the account's home now point into the
	// new folder.
	Links int `json:"links"`
}

// Rename moves a site to a new domain (see the package comment above).
func (m *Manager) Rename(ctx context.Context, r RenameSpec) (res RenameResult, err error) {
	if err := ValidateDomain(r.From); err != nil {
		return res, err
	}
	if err := r.Site.validate(); err != nil {
		return res, err
	}
	if r.From == r.Site.Domain {
		return res, invalid("the site is already called %s", r.From)
	}
	m.mu.Lock()
	defer m.mu.Unlock()

	u, err := m.h.LookupUser(r.Site.User)
	if err != nil {
		return res, invalid("site user %s: %v", r.Site.User, err)
	}
	if u.UID == 0 || filepath.Clean(u.Home) != "/home/"+r.Site.User {
		return res, invalid("%s is not a site user", r.Site.User)
	}
	old := layout(r.From, r.Site.User, r.Site.PHPVersion, "")
	next := layout(r.Site.Domain, r.Site.User, r.Site.PHPVersion, "")
	if fi, err := os.Lstat(m.h.Path(old.siteDir)); err != nil || !fi.IsDir() {
		return res, invalid("the site folder %s is missing", old.siteDir)
	}
	if _, err := os.Lstat(m.h.Path(next.siteDir)); err == nil && !m.isRenameLink(next.siteDir) {
		return res, invalid("%s already exists; move it away first", next.siteDir)
	}
	for _, p := range []string{next.nginxAvail, apacheAvail(r.Site.Domain), next.logDir, ExtraDir(r.Site.Domain)} {
		if m.exists(p) {
			return res, invalid("%s is already set up on this server (%s exists)", r.Site.Domain, p)
		}
	}
	versions, err := m.PHPVersions()
	if err != nil {
		return res, &OperationError{err}
	}
	if !contains(versions, r.Site.PHPVersion) {
		return res, invalid("PHP %s is not installed", r.Site.PHPVersion)
	}
	apache := r.Site.WebServer == WebApache || m.exists(apacheAvail(r.From))

	t := txn.New(m.h)
	switched := false
	defer func() {
		if err == nil {
			return
		}
		if rbErr := t.Rollback(context.WithoutCancel(ctx)); rbErr != nil {
			err = fmt.Errorf("%w (rollback also failed: %v)", err, rbErr)
		}
		// The web server may have been reloaded without the old name;
		// with its files back it serves the site again.
		if switched {
			m.reload(context.WithoutCancel(ctx), r.Site.PHPVersion, apache)
		}
		var v *ValidationError
		if !errors.As(err, &v) {
			err = &OperationError{err}
		}
	}()

	var extra [][2]string
	if r.Before != nil {
		if extra, err = r.Before(ctx); err != nil {
			return res, err
		}
	}
	if err := m.dropRenameLink(t, next.siteDir); err != nil {
		return res, err
	}
	moves := append([][2]string{
		{old.siteDir, next.siteDir},
		{old.logDir, next.logDir},
		{ExtraDir(r.From), ExtraDir(r.Site.Domain)},
		{ErrorPagesRoot + "/" + r.From, ErrorPagesRoot + "/" + r.Site.Domain},
	}, extra...)
	for _, mv := range moves {
		if err := m.move(t, mv[0], mv[1]); err != nil {
			return res, err
		}
	}
	// The old path leads to the new folder. The link is relative, so it
	// works inside SFTP and SSH jails too.
	link := m.h.Path(old.siteDir)
	if err := os.Symlink(r.Site.Domain, link); err != nil {
		return res, err
	}
	t.OnUndo(func(context.Context) error { return os.Remove(link) })
	if err := m.h.Chown(link, u.UID, u.GID); err != nil {
		return res, err
	}
	// Snippets name their own folder (WAF rules, password files).
	if err := m.rewriteTree(t, ExtraDir(r.Site.Domain), ExtraDir(r.From)+"/", ExtraDir(r.Site.Domain)+"/"); err != nil {
		return res, err
	}
	if res.Links, err = m.retargetLinks(t, old.home, old.siteDir, next.siteDir); err != nil {
		return res, err
	}
	// The page cache is rebuilt under the new name.
	if err := os.RemoveAll(m.h.Path(CacheDir(r.From))); err != nil {
		return res, err
	}
	if r.Fit != nil {
		undo, err := r.Fit(ctx)
		if err != nil {
			return res, err
		}
		if undo != nil {
			t.OnUndo(func(ctx context.Context) error { undo(ctx); return nil })
		}
	}

	// The old name's web server files go; the new name's come in the
	// same reload.
	if err := t.Unlink(old.nginxEnabled); err != nil {
		return res, err
	}
	if err := t.RemoveFile(old.nginxAvail); err != nil {
		return res, err
	}
	if err := m.removeApacheSite(t, r.From); err != nil {
		return res, err
	}
	for _, v := range versions {
		if err := t.RemoveFile(fpmPool(v, r.From)); err != nil {
			return res, err
		}
	}
	for _, p := range append([]string{maintenancePage(r.From), suspendedPage(r.From)}, r.Remove...) {
		if err := t.RemoveFile(p); err != nil {
			return res, err
		}
	}
	switched = true
	applied, err := m.apply(ctx, r.Site)
	if err != nil {
		return res, err
	}
	res.Result = applied
	return res, nil
}

// reload reloads PHP-FPM, Apache and Nginx, for a rename put back.
func (m *Manager) reload(ctx context.Context, php string, apache bool) {
	units := []string{"php" + php + "-fpm"}
	if apache && m.exists("/usr/sbin/apache2") {
		units = append(units, "apache2")
	}
	for _, u := range append(units, "nginx") {
		_, _ = m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"reload", u}})
	}
}

// move renames from to to as part of t; a missing from is skipped.
func (m *Manager) move(t *txn.Tx, from, to string) error {
	src, dst := m.h.Path(from), m.h.Path(to)
	if _, err := os.Lstat(src); errors.Is(err, os.ErrNotExist) {
		return nil
	}
	if _, err := os.Lstat(dst); err == nil {
		return fmt.Errorf("%s already exists", to)
	}
	if err := os.MkdirAll(filepath.Dir(dst), 0o755); err != nil {
		return err
	}
	if err := os.Rename(src, dst); err != nil {
		return fmt.Errorf("moving %s to %s failed: %w", from, to, err)
	}
	t.Changed = true
	t.OnUndo(func(context.Context) error { return os.Rename(dst, src) })
	return nil
}

// rewriteTree replaces from with to in the regular files below dir (small
// root-owned snippets), keeping their modes and owners.
func (m *Manager) rewriteTree(t *txn.Tx, dir, from, to string) error {
	root := m.h.Path(dir)
	return filepath.WalkDir(root, func(p string, e fs.DirEntry, err error) error {
		if err != nil {
			if errors.Is(err, os.ErrNotExist) {
				return nil
			}
			return err
		}
		if !e.Type().IsRegular() {
			return nil
		}
		fi, err := e.Info()
		if err != nil || fi.Size() > 4<<20 {
			return err
		}
		b, err := os.ReadFile(p)
		if err != nil {
			return err
		}
		out := bytes.ReplaceAll(b, []byte(from), []byte(to))
		if bytes.Equal(out, b) {
			return nil
		}
		if err := os.WriteFile(p, out, fi.Mode().Perm()); err != nil {
			return err
		}
		t.OnUndo(func(context.Context) error { return os.WriteFile(p, b, fi.Mode().Perm()) })
		return nil
	})
}

// retargetLinks points absolute links below home that lead into from (or
// are from) at the same place under to. It returns how many it changed.
func (m *Manager) retargetLinks(t *txn.Tx, home, from, to string) (int, error) {
	root := m.h.Path(home)
	n, seen := 0, 0
	err := filepath.WalkDir(root, func(p string, e fs.DirEntry, err error) error {
		if err != nil {
			return nil
		}
		if seen++; seen > linkWalkLimit {
			return filepath.SkipAll
		}
		if e.Type()&fs.ModeSymlink == 0 {
			return nil
		}
		target, err := os.Readlink(p)
		if err != nil || (target != from && !strings.HasPrefix(target, from+"/")) {
			return nil
		}
		fi, err := os.Lstat(p)
		if err != nil {
			return nil
		}
		uid, gid := owner(fi)
		next := to + strings.TrimPrefix(target, from)
		if err := os.Remove(p); err != nil {
			return err
		}
		if err := os.Symlink(next, p); err != nil {
			_ = os.Symlink(target, p)
			return err
		}
		_ = m.h.Chown(p, uid, gid)
		t.OnUndo(func(context.Context) error {
			_ = os.Remove(p)
			if err := os.Symlink(target, p); err != nil {
				return err
			}
			return m.h.Chown(p, uid, gid)
		})
		n++
		return nil
	})
	return n, err
}

// owner returns the owner of a file.
func owner(fi fs.FileInfo) (int, int) {
	if st, ok := fi.Sys().(*syscall.Stat_t); ok {
		return int(st.Uid), int(st.Gid)
	}
	return 0, 0
}

// linkWalkLimit bounds the folders and files looked at for links.
const linkWalkLimit = 500000

// isRenameLink reports whether abs is the link a rename left in place of
// a site folder: a link to a sibling folder.
func (m *Manager) isRenameLink(abs string) bool {
	target, err := os.Readlink(m.h.Path(abs))
	return err == nil && target != "" && !strings.Contains(target, "/")
}

// dropRenameLink removes such a link from where a site folder must be.
func (m *Manager) dropRenameLink(t *txn.Tx, abs string) error {
	if !m.isRenameLink(abs) {
		return nil
	}
	p := m.h.Path(abs)
	target, _ := os.Readlink(p)
	fi, err := os.Lstat(p)
	if err != nil {
		return err
	}
	uid, gid := owner(fi)
	if err := os.Remove(p); err != nil {
		return err
	}
	t.Changed = true
	t.OnUndo(func(context.Context) error {
		if err := os.Symlink(target, p); err != nil {
			return err
		}
		return m.h.Chown(p, uid, gid)
	})
	return nil
}

// removeRenameLinks removes the links in web that a rename left pointing
// at the folder domain.
func (m *Manager) removeRenameLinks(web, domain string) {
	entries, err := os.ReadDir(m.h.Path(web))
	if err != nil {
		return
	}
	for _, e := range entries {
		if e.Type()&fs.ModeSymlink == 0 {
			continue
		}
		p := m.h.Path(web + "/" + e.Name())
		if target, err := os.Readlink(p); err == nil && target == domain {
			_ = os.Remove(p)
		}
	}
}
