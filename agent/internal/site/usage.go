package site

import (
	"context"
	"errors"
	"io/fs"
	"os"
	"path/filepath"
	"syscall"
)

// DiskSpec asks how much disk the accounts' home directories use.
type DiskSpec struct {
	Users []string `json:"users"`
}

// DiskUsage is what one home directory holds: the space its files take on
// disk (blocks, so sparse files count what they use) and how many there
// are. A file with several hard links counts once.
type DiskUsage struct {
	Bytes uint64 `json:"bytes"`
	Files uint64 `json:"files"`
}

const maxDiskUsers = 1000

// DiskUsage measures /home/<user> of every account. An account without a
// home directory (no site created yet) uses nothing. Symbolic links are
// counted, never followed.
func (m *Manager) DiskUsage(ctx context.Context, spec DiskSpec) (map[string]DiskUsage, error) {
	if len(spec.Users) > maxDiskUsers {
		return nil, invalid("at most %d accounts can be measured at once", maxDiskUsers)
	}
	for _, u := range spec.Users {
		if err := ValidateUser(u); err != nil {
			return nil, err
		}
	}
	out := make(map[string]DiskUsage, len(spec.Users))
	for _, u := range spec.Users {
		usage, err := measure(ctx, m.h.Path("/home/"+u))
		if err != nil {
			return nil, &OperationError{err}
		}
		out[u] = usage
	}
	return out, nil
}

func measure(ctx context.Context, root string) (DiskUsage, error) {
	var u DiskUsage
	type inode struct{ dev, ino uint64 }
	seen := map[inode]bool{}
	err := filepath.WalkDir(root, func(path string, d fs.DirEntry, err error) error {
		if err != nil {
			if errors.Is(err, os.ErrNotExist) {
				return nil // removed while we walked, or no home yet
			}
			return err
		}
		if err := ctx.Err(); err != nil {
			return err
		}
		info, err := d.Info()
		if err != nil {
			if errors.Is(err, os.ErrNotExist) {
				return nil
			}
			return err
		}
		st, ok := info.Sys().(*syscall.Stat_t)
		if !ok {
			u.Bytes += uint64(info.Size())
			u.Files++
			return nil
		}
		if st.Nlink > 1 && !d.IsDir() {
			key := inode{uint64(st.Dev), st.Ino}
			if seen[key] {
				return nil
			}
			seen[key] = true
		}
		u.Bytes += uint64(st.Blocks) * 512
		if !d.IsDir() {
			u.Files++
		}
		return nil
	})
	return u, err
}
