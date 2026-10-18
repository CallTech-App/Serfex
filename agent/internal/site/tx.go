package site

import (
	"context"
	"errors"
	"fmt"
	"os"
	"path/filepath"

	"github.com/winchester37/servik/agent/internal/host"
)

// tx applies root-owned file changes and remembers how to undo them, so a
// failed operation leaves the server as it found it.
type tx struct {
	h       host.Host
	undo    []func(context.Context) error
	changed bool
}

func (t *tx) onUndo(f func(context.Context) error) { t.undo = append(t.undo, f) }

// rollback undoes every recorded change in reverse order.
func (t *tx) rollback(ctx context.Context) error {
	var errs []error
	for i := len(t.undo) - 1; i >= 0; i-- {
		if err := t.undo[i](ctx); err != nil {
			errs = append(errs, err)
		}
	}
	t.undo = nil
	return errors.Join(errs...)
}

// readManaged returns the file's content, or nil if it does not exist. It
// refuses to touch files that Servik did not write.
func (t *tx) readManaged(abs string) ([]byte, error) {
	old, err := os.ReadFile(t.h.Path(abs))
	if errors.Is(err, os.ErrNotExist) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	if !hasMarker(old) {
		return nil, fmt.Errorf("%s exists and is not managed by Servik; move it away first", abs)
	}
	return old, nil
}

// writeFile atomically replaces a managed file when its content differs.
func (t *tx) writeFile(abs string, data []byte) error {
	old, err := t.readManaged(abs)
	if err != nil {
		return err
	}
	if old != nil && string(old) == string(data) {
		return nil
	}
	if err := atomicWrite(t.h.Path(abs), data); err != nil {
		return err
	}
	t.changed = true
	t.onUndo(func(context.Context) error {
		if old == nil {
			return removeIfExists(t.h.Path(abs))
		}
		return atomicWrite(t.h.Path(abs), old)
	})
	return nil
}

// removeFile deletes a managed file; missing files are fine.
func (t *tx) removeFile(abs string) error {
	old, err := t.readManaged(abs)
	if err != nil || old == nil {
		return err
	}
	if err := os.Remove(t.h.Path(abs)); err != nil {
		return err
	}
	t.changed = true
	t.onUndo(func(context.Context) error { return atomicWrite(t.h.Path(abs), old) })
	return nil
}

// symlink makes link point at target.
func (t *tx) symlink(target, link string) error {
	p := t.h.Path(link)
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
	t.changed = true
	t.onUndo(func(context.Context) error { return removeIfExists(p) })
	return nil
}

// unlink removes a symlink; missing links are fine.
func (t *tx) unlink(link string) error {
	p := t.h.Path(link)
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
	t.changed = true
	t.onUndo(func(context.Context) error { return os.Symlink(target, p) })
	return nil
}

// rootDir creates a root-controlled directory (one whose parents only root
// can change) and sets its owner and mode.
func (t *tx) rootDir(abs string, mode os.FileMode, uid, gid int) error {
	p := t.h.Path(abs)
	fi, err := os.Lstat(p)
	switch {
	case errors.Is(err, os.ErrNotExist):
		if err := os.MkdirAll(filepath.Dir(p), 0o755); err != nil {
			return err
		}
		if err := os.Mkdir(p, mode); err != nil {
			return err
		}
		t.changed = true
		t.onUndo(func(context.Context) error { return os.RemoveAll(p) })
	case err != nil:
		return err
	case !fi.IsDir():
		return fmt.Errorf("%s exists and is not a directory", abs)
	}
	if err := t.h.Chown(p, uid, gid); err != nil {
		return err
	}
	return os.Chmod(p, mode)
}

// userDirs creates directories inside a customer's home as that customer,
// skipping the ones that already exist.
func (t *tx) userDirs(ctx context.Context, user string, mode string, dirs ...string) error {
	var missing []string
	for _, d := range dirs {
		if _, err := os.Lstat(t.h.Path(d)); errors.Is(err, os.ErrNotExist) {
			missing = append(missing, d)
		}
	}
	if len(missing) == 0 {
		return nil
	}
	args := append([]string{"-d", "-m", mode}, missing...)
	if _, err := t.h.Run(ctx, host.AsUser(user, host.Command{Name: "install", Args: args})); err != nil {
		return err
	}
	t.changed = true
	t.onUndo(func(ctx context.Context) error {
		_, err := t.h.Run(ctx, host.AsUser(user, host.Command{Name: "rm", Args: append([]string{"-rf", "--one-file-system"}, missing...)}))
		return err
	})
	return nil
}

func atomicWrite(path string, data []byte) error {
	tmp, err := os.CreateTemp(filepath.Dir(path), ".servik-*")
	if err != nil {
		return err
	}
	defer os.Remove(tmp.Name())
	if _, err := tmp.Write(data); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Chmod(0o644); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Close(); err != nil {
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
