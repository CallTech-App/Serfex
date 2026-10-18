// Package txn applies root-owned file changes transactionally: every change
// remembers how to undo itself, so a failed operation leaves the server as
// it found it.
package txn

import (
	"context"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"

	"github.com/winchester37/servik/agent/internal/host"
)

// Marker is the first line of every file Servik manages. Files without it
// are never overwritten or removed.
const Marker = "Managed by Servik"

// HasMarker reports whether content starts with a line carrying Marker.
func HasMarker(content []byte) bool {
	first, _, _ := strings.Cut(string(content), "\n")
	return strings.Contains(first, Marker)
}

// Tx records changes made on a Host and how to undo them.
type Tx struct {
	H host.Host
	// Changed is set by every method that changed something.
	Changed bool

	undo []func(context.Context) error
}

// New returns a transaction on h.
func New(h host.Host) *Tx { return &Tx{H: h} }

// OnUndo registers f to run on Rollback.
func (t *Tx) OnUndo(f func(context.Context) error) { t.undo = append(t.undo, f) }

// Rollback undoes every recorded change in reverse order.
func (t *Tx) Rollback(ctx context.Context) error {
	var errs []error
	for i := len(t.undo) - 1; i >= 0; i-- {
		if err := t.undo[i](ctx); err != nil {
			errs = append(errs, err)
		}
	}
	t.undo = nil
	return errors.Join(errs...)
}

// ReadManaged returns the file's content, or nil if it does not exist. It
// refuses to touch files that Servik did not write.
func (t *Tx) ReadManaged(abs string) ([]byte, error) {
	old, err := os.ReadFile(t.H.Path(abs))
	if errors.Is(err, os.ErrNotExist) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	if !HasMarker(old) {
		return nil, fmt.Errorf("%s exists and is not managed by Servik; move it away first", abs)
	}
	return old, nil
}

// WriteFile atomically replaces a managed file when its content differs.
// The file is world-readable and owned by root.
func (t *Tx) WriteFile(abs string, data []byte) error {
	return t.WriteFileMode(abs, data, 0o644, 0, 0)
}

// WriteFileMode is WriteFile with an explicit mode and owner, for files
// that hold secrets. Mode and owner are fixed even when the content is
// already right.
func (t *Tx) WriteFileMode(abs string, data []byte, mode os.FileMode, uid, gid int) error {
	old, err := t.ReadManaged(abs)
	if err != nil {
		return err
	}
	p := t.H.Path(abs)
	if old != nil && string(old) == string(data) {
		if err := t.H.Chown(p, uid, gid); err != nil {
			return err
		}
		return os.Chmod(p, mode)
	}
	if err := t.atomicWrite(p, data, mode, uid, gid); err != nil {
		return err
	}
	t.Changed = true
	t.OnUndo(func(context.Context) error {
		if old == nil {
			return removeIfExists(p)
		}
		return t.atomicWrite(p, old, mode, uid, gid)
	})
	return nil
}

// WriteOwned atomically replaces a file in a directory that only Servik
// writes (certificates, keys), where a marker line cannot be used. Mode and
// owner are fixed even when the content is already right.
func (t *Tx) WriteOwned(abs string, data []byte, mode os.FileMode, uid, gid int) error {
	p := t.H.Path(abs)
	old, err := os.ReadFile(p)
	if err != nil && !errors.Is(err, os.ErrNotExist) {
		return err
	}
	existed := err == nil
	if existed && string(old) == string(data) {
		if err := t.H.Chown(p, uid, gid); err != nil {
			return err
		}
		return os.Chmod(p, mode)
	}
	if err := t.atomicWrite(p, data, mode, uid, gid); err != nil {
		return err
	}
	t.Changed = true
	t.OnUndo(func(context.Context) error {
		if !existed {
			return removeIfExists(p)
		}
		return t.atomicWrite(p, old, mode, uid, gid)
	})
	return nil
}

// TakeOver makes room for a managed file where the distribution put its
// own (Postfix's main.cf, Dovecot's dovecot.conf): a file without the
// marker is renamed to abs.before-servik, never deleted. Managed and
// missing files are left alone.
func (t *Tx) TakeOver(abs string) error {
	p := t.H.Path(abs)
	old, err := os.ReadFile(p)
	if errors.Is(err, os.ErrNotExist) || (err == nil && HasMarker(old)) {
		return nil
	}
	if err != nil {
		return err
	}
	saved := p + ".before-servik"
	if _, err := os.Lstat(saved); err == nil {
		return fmt.Errorf("%s exists and is not managed by Servik, and %s.before-servik is taken; move one of them away first", abs, abs)
	}
	if err := os.Rename(p, saved); err != nil {
		return err
	}
	t.Changed = true
	t.OnUndo(func(context.Context) error { return os.Rename(saved, p) })
	return nil
}

// RemoveFile deletes a managed file; missing files are fine.
func (t *Tx) RemoveFile(abs string) error {
	old, err := t.ReadManaged(abs)
	if err != nil || old == nil {
		return err
	}
	if err := os.Remove(t.H.Path(abs)); err != nil {
		return err
	}
	t.Changed = true
	t.OnUndo(func(context.Context) error { return t.atomicWrite(t.H.Path(abs), old, 0o644, 0, 0) })
	return nil
}

// Symlink makes link point at target.
func (t *Tx) Symlink(target, link string) error {
	p := t.H.Path(link)
	if cur, err := os.Readlink(p); err == nil {
		if cur == target {
			return nil
		}
		return fmt.Errorf("%s already points to %s", link, cur)
	} else if !errors.Is(err, os.ErrNotExist) {
		return fmt.Errorf("%s exists and is not a symlink", link)
	}
	if err := os.Symlink(target, p); err != nil {
		return err
	}
	t.Changed = true
	t.OnUndo(func(context.Context) error { return removeIfExists(p) })
	return nil
}

// Unlink removes a symlink; missing links are fine.
func (t *Tx) Unlink(link string) error {
	p := t.H.Path(link)
	target, err := os.Readlink(p)
	if errors.Is(err, os.ErrNotExist) {
		return nil
	}
	if err != nil {
		return fmt.Errorf("%s is not a symlink", link)
	}
	if err := os.Remove(p); err != nil {
		return err
	}
	t.Changed = true
	t.OnUndo(func(context.Context) error { return os.Symlink(target, p) })
	return nil
}

// ReplaceSymlink makes link point at target, whatever it pointed at
// before. link must be a symlink already, or missing.
func (t *Tx) ReplaceSymlink(target, link string) error {
	p := t.H.Path(link)
	old, err := os.Readlink(p)
	switch {
	case err == nil && old == target:
		return nil
	case err != nil && !errors.Is(err, os.ErrNotExist):
		return fmt.Errorf("%s exists and is not a symlink", link)
	}
	tmp := p + ".servik-new"
	_ = os.Remove(tmp)
	if err := os.Symlink(target, tmp); err != nil {
		return err
	}
	if err := os.Rename(tmp, p); err != nil {
		os.Remove(tmp)
		return err
	}
	t.Changed = true
	t.OnUndo(func(context.Context) error {
		if old == "" {
			return removeIfExists(p)
		}
		if err := os.Symlink(old, tmp); err != nil {
			return err
		}
		return os.Rename(tmp, p)
	})
	return nil
}

// RootDir creates a root-controlled directory (one whose parents only root
// can change) and sets its owner and mode.
func (t *Tx) RootDir(abs string, mode os.FileMode, uid, gid int) error {
	p := t.H.Path(abs)
	fi, err := os.Lstat(p)
	switch {
	case errors.Is(err, os.ErrNotExist):
		if err := os.MkdirAll(filepath.Dir(p), 0o755); err != nil {
			return err
		}
		if err := os.Mkdir(p, mode); err != nil {
			return err
		}
		t.Changed = true
		t.OnUndo(func(context.Context) error { return os.RemoveAll(p) })
	case err != nil:
		return err
	case !fi.IsDir():
		return fmt.Errorf("%s exists and is not a directory", abs)
	}
	if err := t.H.Chown(p, uid, gid); err != nil {
		return err
	}
	return os.Chmod(p, mode)
}

// UserDirs creates directories inside a customer's home as that customer,
// skipping the ones that already exist.
func (t *Tx) UserDirs(ctx context.Context, user string, mode string, dirs ...string) error {
	var missing []string
	for _, d := range dirs {
		if _, err := os.Lstat(t.H.Path(d)); errors.Is(err, os.ErrNotExist) {
			missing = append(missing, d)
		}
	}
	if len(missing) == 0 {
		return nil
	}
	args := append([]string{"-d", "-m", mode}, missing...)
	if _, err := t.H.Run(ctx, host.AsUser(user, host.Command{Name: "install", Args: args})); err != nil {
		return err
	}
	t.Changed = true
	t.OnUndo(func(ctx context.Context) error {
		_, err := t.H.Run(ctx, host.AsUser(user, host.Command{Name: "rm", Args: append([]string{"-rf", "--one-file-system"}, missing...)}))
		return err
	})
	return nil
}

func (t *Tx) atomicWrite(path string, data []byte, mode os.FileMode, uid, gid int) error {
	tmp, err := os.CreateTemp(filepath.Dir(path), ".servik-*")
	if err != nil {
		return err
	}
	defer os.Remove(tmp.Name())
	if _, err := tmp.Write(data); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Chmod(mode); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Close(); err != nil {
		return err
	}
	if err := t.H.Chown(tmp.Name(), uid, gid); err != nil {
		return err
	}
	return os.Rename(tmp.Name(), path)
}

func removeIfExists(path string) error {
	if err := os.Remove(path); err != nil && !errors.Is(err, os.ErrNotExist) {
		return err
	}
	return nil
}
