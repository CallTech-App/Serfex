package security

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"

	"github.com/winchester37/servik/agent/internal/files"
	"github.com/winchester37/servik/agent/internal/site"
)

// FixSpec asks for one finding to be fixed, or a fix to be undone.
type FixSpec struct {
	Domain string `json:"domain"`
	User   string `json:"user"`
	// Path is relative to the site folder; "" is the whole folder.
	Path   string `json:"path"`
	Action string `json:"action"`
	// Undo holds what a permissions or owner fix returned, to put back.
	Undo map[string]string `json:"undo,omitempty"`
}

// FixResult says what changed.
type FixResult struct {
	// Changed counts the entries changed.
	Changed int `json:"changed"`
	// TrashID is where a quarantined file waits (file manager trash).
	TrashID string `json:"trash_id,omitempty"`
	// Undo is what to send back to undo the fix: each changed entry's
	// mode ("0666") or owner ("1001:1001"). Missing when there were too
	// many entries to keep.
	Undo map[string]string `json:"undo,omitempty"`
}

// maxUndo bounds the entries a fix remembers.
const maxUndo = 20000

// Fix applies a finding's fix. Permissions and owners change only on
// entries reached without following a symlink, so a link planted in the
// site folder cannot point the fix at the rest of the server.
func (m *Manager) Fix(ctx context.Context, spec FixSpec) (FixResult, error) {
	if err := site.ValidateDomain(spec.Domain); err != nil {
		return FixResult{}, err
	}
	if err := site.ValidateUser(spec.User); err != nil {
		return FixResult{}, err
	}
	rel, err := cleanRel(spec.Path)
	if err != nil {
		return FixResult{}, invalid("path %q is not valid", spec.Path)
	}
	u, err := m.h.LookupUser(spec.User)
	if err != nil {
		return FixResult{}, invalid("account %s: %v", spec.User, err)
	}
	root := m.h.Path(files.SiteDir(spec.User, spec.Domain))
	switch spec.Action {
	case FixQuarantine:
		if rel == "" {
			return FixResult{}, invalid("the site folder itself cannot be quarantined")
		}
		out, err := m.Files.Do(ctx, files.Spec{User: spec.User, Domain: spec.Domain, Request: files.Request{Op: "quarantine", Paths: []string{rel}}})
		if err != nil {
			return FixResult{}, err
		}
		var r struct {
			IDs []string `json:"ids"`
		}
		b, _ := json.Marshal(out)
		_ = json.Unmarshal(b, &r)
		if len(r.IDs) == 0 {
			return FixResult{}, &OperationError{errors.New("nothing was quarantined")}
		}
		return FixResult{Changed: 1, TrashID: r.IDs[0]}, nil
	case FixPermissions:
		return m.walkFix(root, rel, spec.Undo, func(fd int, st *syscall.Stat_t) (string, bool, error) {
			perm := st.Mode & 0o7777
			if perm&0o002 == 0 || st.Mode&syscall.S_IFMT == syscall.S_IFDIR && perm&0o1000 != 0 {
				return "", false, nil
			}
			return "0" + strconv.FormatUint(uint64(perm), 8), true, chmodFD(fd, perm&^0o002)
		}, func(fd int, v string) error {
			n, err := strconv.ParseUint(v, 8, 32)
			if err != nil || n > 0o7777 {
				return fmt.Errorf("bad mode %q", v)
			}
			return chmodFD(fd, uint32(n))
		})
	case FixOwner:
		if m.sim {
			return FixResult{}, nil
		}
		return m.walkFix(root, rel, spec.Undo, func(fd int, st *syscall.Stat_t) (string, bool, error) {
			if int(st.Uid) == u.UID && int(st.Gid) == u.GID {
				return "", false, nil
			}
			return fmt.Sprintf("%d:%d", st.Uid, st.Gid), true, chownFD(fd, u.UID, u.GID)
		}, func(fd int, v string) error {
			a, b, ok := strings.Cut(v, ":")
			uid, err1 := strconv.Atoi(a)
			gid, err2 := strconv.Atoi(b)
			if !ok || err1 != nil || err2 != nil {
				return fmt.Errorf("bad owner %q", v)
			}
			return chownFD(fd, uid, gid)
		})
	}
	return FixResult{}, invalid("unknown fix %q", spec.Action)
}

// walkFix runs change on rel and, for a folder, everything below it; with
// undo set it puts the recorded values back instead.
func (m *Manager) walkFix(root, rel string, undo map[string]string,
	change func(fd int, st *syscall.Stat_t) (string, bool, error), restore func(fd int, v string) error) (FixResult, error) {
	res := FixResult{Undo: map[string]string{}}
	if undo != nil {
		for p, v := range undo {
			p, err := cleanRel(p)
			if err != nil {
				continue
			}
			fd, err := openNoFollow(root, p)
			if err != nil {
				continue // gone since
			}
			var st syscall.Stat_t
			if syscall.Fstat(fd, &st) == nil && st.Mode&syscall.S_IFMT != syscall.S_IFLNK && restore(fd, v) == nil {
				res.Changed++
			}
			syscall.Close(fd)
		}
		res.Undo = nil
		return res, nil
	}
	start := filepath.Join(root, filepath.FromSlash(rel))
	err := filepath.WalkDir(start, func(p string, d fs.DirEntry, err error) error {
		if err != nil {
			if p == start {
				return err
			}
			return nil
		}
		if d.Type()&fs.ModeSymlink != 0 {
			return nil
		}
		r, _ := filepath.Rel(root, p)
		r = filepath.ToSlash(r)
		if r == "." {
			r = ""
		}
		fd, err := openNoFollow(root, r)
		if err != nil {
			return nil // replaced by a link meanwhile
		}
		defer syscall.Close(fd)
		var st syscall.Stat_t
		if err := syscall.Fstat(fd, &st); err != nil || st.Mode&syscall.S_IFMT == syscall.S_IFLNK {
			return nil
		}
		old, changed, err := change(fd, &st)
		if err != nil {
			return err
		}
		if changed {
			res.Changed++
			if res.Undo != nil {
				res.Undo[r] = old
				if len(res.Undo) > maxUndo {
					res.Undo = nil
				}
			}
		}
		return nil
	})
	if err != nil {
		if errors.Is(err, fs.ErrNotExist) {
			return res, invalid("%s no longer exists", rel)
		}
		return res, &OperationError{err}
	}
	return res, nil
}

const oPath = 0x200000 // O_PATH on Linux (amd64, arm64)

// openNoFollow opens rel inside root with O_PATH, refusing every symlink
// on the way, the last component included.
func openNoFollow(root, rel string) (int, error) {
	fd, err := syscall.Open(root, oPath|syscall.O_DIRECTORY|syscall.O_NOFOLLOW|syscall.O_CLOEXEC, 0)
	if err != nil {
		return -1, err
	}
	if rel == "" {
		return fd, nil
	}
	parts := strings.Split(rel, "/")
	for i, p := range parts {
		flags := oPath | syscall.O_NOFOLLOW | syscall.O_CLOEXEC
		if i < len(parts)-1 {
			flags |= syscall.O_DIRECTORY
		}
		next, err := syscall.Openat(fd, p, flags, 0)
		syscall.Close(fd)
		if err != nil {
			return -1, err
		}
		fd = next
	}
	return fd, nil
}

func fdPath(fd int) string { return "/proc/self/fd/" + strconv.Itoa(fd) }

// chmodFD changes the entry the O_PATH descriptor holds; the /proc link
// leads to that very inode, whatever happens to its name meanwhile.
func chmodFD(fd int, perm uint32) error {
	return syscall.Chmod(fdPath(fd), perm)
}

func chownFD(fd, uid, gid int) error {
	// AT_EMPTY_PATH: the descriptor itself.
	return syscall.Fchownat(fd, "", uid, gid, 0x1000)
}
