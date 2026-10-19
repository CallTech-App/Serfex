package files

import (
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io/fs"
	"os"
	"path"
	"path/filepath"
	"sort"
	"strings"
	"time"
)

// The trash keeps what the file manager deletes or overwrites for
// TrashDays, outside the site folder (so the web server never serves it)
// but in the account's own home, on the same disk:
//
//	<Trash>/<id>/meta.json   where it came from and when
//	<Trash>/<id>/item        the file or folder itself
//
// It belongs to the site's user like everything else the helper makes,
// and counts towards the account's disk.

// TrashItem is one entry of the trash.
type TrashItem struct {
	ID   string `json:"id"`
	Path string `json:"path"`
	// Kind is "deleted", "version" (the editor saved over it) or
	// "replaced" (an upload replaced it).
	Kind      string `json:"kind"`
	Type      string `json:"type"`
	Size      int64  `json:"size"`
	DeletedAt int64  `json:"deleted_at"`
}

func newID(now int64) string {
	b := make([]byte, 4)
	_, _ = rand.Read(b)
	return time.Unix(now, 0).UTC().Format("20060102t150405") + "-" + hex.EncodeToString(b)
}

func validID(id string) bool {
	if len(id) < 10 || len(id) > 40 {
		return false
	}
	for _, r := range id {
		if !(r >= 'a' && r <= 'z' || r >= '0' && r <= '9' || r == '-') {
			return false
		}
	}
	return true
}

func (s *session) trashDir() (string, error) {
	if s.req.Trash == "" {
		return "", fail(CodeInvalid, "", "no trash folder")
	}
	if err := os.MkdirAll(s.req.Trash, 0o700); err != nil {
		return "", err
	}
	return s.req.Trash, nil
}

// toTrash moves an entry into the trash.
func (s *session) toTrash(rel, abs, kind string) (string, error) {
	dir, err := s.trashDir()
	if err != nil {
		return "", err
	}
	fi, err := os.Lstat(abs)
	if err != nil {
		return "", err
	}
	id := newID(s.req.Now)
	slot := filepath.Join(dir, id)
	if err := os.Mkdir(slot, 0o700); err != nil {
		return "", err
	}
	item := TrashItem{ID: id, Path: rel, Kind: kind, Type: typeOf(fi), Size: treeSize(abs), DeletedAt: s.req.Now}
	if err := writeMeta(slot, item); err != nil {
		_ = os.RemoveAll(slot)
		return "", err
	}
	if err := os.Rename(abs, filepath.Join(slot, "item")); err != nil {
		_ = os.RemoveAll(slot)
		return "", err
	}
	return id, nil
}

// keepVersion puts a hard link to a file into the trash: the file stays
// where it is until a new version is renamed over it.
func (s *session) keepVersion(rel, abs string) (string, error) {
	dir, err := s.trashDir()
	if err != nil {
		return "", err
	}
	fi, err := os.Lstat(abs)
	if err != nil {
		return "", err
	}
	id := newID(s.req.Now)
	slot := filepath.Join(dir, id)
	if err := os.Mkdir(slot, 0o700); err != nil {
		return "", err
	}
	if err := writeMeta(slot, TrashItem{ID: id, Path: rel, Kind: "version", Type: "file", Size: fi.Size(), DeletedAt: s.req.Now}); err != nil {
		_ = os.RemoveAll(slot)
		return "", err
	}
	if err := os.Link(abs, filepath.Join(slot, "item")); err != nil {
		// Hard links can be refused (another owner's file); copy instead.
		if err := copyTree(abs, filepath.Join(slot, "item")); err != nil {
			_ = os.RemoveAll(slot)
			return "", err
		}
	}
	return id, nil
}

func typeOf(fi fs.FileInfo) string {
	switch {
	case fi.IsDir():
		return "dir"
	case fi.Mode()&fs.ModeSymlink != 0:
		return "link"
	}
	return "file"
}

// treeSize adds up a folder's files (stopping at 100 000 entries).
func treeSize(abs string) int64 {
	var total int64
	n := 0
	_ = filepath.WalkDir(abs, func(_ string, d fs.DirEntry, err error) error {
		if err != nil {
			return nil
		}
		if n++; n > 100000 {
			return filepath.SkipAll
		}
		if d.Type().IsRegular() {
			if fi, err := d.Info(); err == nil {
				total += fi.Size()
			}
		}
		return nil
	})
	return total
}

func writeMeta(slot string, item TrashItem) error {
	data, err := json.Marshal(item)
	if err != nil {
		return err
	}
	return os.WriteFile(filepath.Join(slot, "meta.json"), data, 0o600)
}

func readMeta(slot string) (TrashItem, error) {
	var item TrashItem
	data, err := os.ReadFile(filepath.Join(slot, "meta.json"))
	if err != nil {
		return item, err
	}
	err = json.Unmarshal(data, &item)
	return item, err
}

func (s *session) items() ([]TrashItem, error) {
	if s.req.Trash == "" {
		return nil, fail(CodeInvalid, "", "no trash folder")
	}
	entries, err := os.ReadDir(s.req.Trash)
	if errors.Is(err, fs.ErrNotExist) {
		return []TrashItem{}, nil
	}
	if err != nil {
		return nil, err
	}
	out := []TrashItem{}
	for _, e := range entries {
		if !e.IsDir() || !validID(e.Name()) {
			continue
		}
		item, err := readMeta(filepath.Join(s.req.Trash, e.Name()))
		if err != nil {
			continue
		}
		item.ID = e.Name()
		out = append(out, item)
	}
	sort.Slice(out, func(i, j int) bool { return out[i].ID > out[j].ID })
	return out, nil
}

func (s *session) trashList() (any, error) {
	s.purge(TrashDays)
	items, err := s.items()
	if err != nil {
		return nil, err
	}
	return map[string]any{"items": items, "days": TrashDays}, nil
}

// trashRestore puts entries back where they were. A version replaces the
// file it was saved over (which goes to the trash in turn); a deleted
// entry whose place is taken comes back under a new name. Missing
// folders on the way are made again.
func (s *session) trashRestore() (any, error) {
	type restored struct {
		ID   string `json:"id"`
		Path string `json:"path"`
	}
	done := []restored{}
	for _, id := range s.req.IDs {
		if !validID(id) || s.req.Trash == "" {
			return map[string]any{"restored": done}, fail(CodeNotFound, id, "not in the trash")
		}
		slot := filepath.Join(s.req.Trash, id)
		item, err := readMeta(slot)
		if err != nil {
			return map[string]any{"restored": done}, fail(CodeNotFound, id, "not in the trash")
		}
		rel, err := clean(item.Path)
		if err != nil || rel == "" {
			return map[string]any{"restored": done}, fail(CodeInvalid, id, "bad trash entry")
		}
		dirRel := path.Dir(rel)
		if dirRel == "." {
			dirRel = ""
		}
		dirAbs, err := s.ensureFolder(dirRel)
		if err != nil {
			return map[string]any{"restored": done}, err
		}
		name := path.Base(rel)
		target := filepath.Join(dirAbs, name)
		if _, err := os.Lstat(target); err == nil {
			if item.Kind == "deleted" {
				name = freeName(dirAbs, name, " (geri yüklendi)")
				target = filepath.Join(dirAbs, name)
			} else if _, err := s.toTrash(rel, target, "version"); err != nil {
				return map[string]any{"restored": done}, err
			}
		}
		if err := os.Rename(filepath.Join(slot, "item"), target); err != nil {
			return map[string]any{"restored": done}, err
		}
		_ = os.RemoveAll(slot)
		done = append(done, restored{ID: id, Path: path.Join(dirRel, name)})
	}
	return map[string]any{"restored": done}, nil
}

// ensureFolder resolves a folder inside the root, making it (and its
// parents) when missing.
func (s *session) ensureFolder(rel string) (string, error) {
	if _, abs, err := s.folder(rel); err == nil {
		return abs, nil
	}
	parts := strings.Split(rel, "/")
	cur := ""
	for _, p := range parts {
		next := path.Join(cur, p)
		if _, _, err := s.folder(next); err != nil {
			_, abs, err := s.folder(cur)
			if err != nil {
				return "", err
			}
			if err := os.Mkdir(filepath.Join(abs, p), 0o755); err != nil {
				return "", err
			}
		}
		cur = next
	}
	_, abs, err := s.folder(rel)
	return abs, err
}

// trashDelete removes entries for good; no IDs empties the trash.
func (s *session) trashDelete() (any, error) {
	ids := s.req.IDs
	if len(ids) == 0 {
		items, err := s.items()
		if err != nil {
			return nil, err
		}
		for _, it := range items {
			ids = append(ids, it.ID)
		}
	}
	n := 0
	for _, id := range ids {
		if !validID(id) || s.req.Trash == "" {
			continue
		}
		if err := removeAll(filepath.Join(s.req.Trash, id)); err == nil {
			n++
		}
	}
	return map[string]any{"deleted": n}, nil
}

func (s *session) trashPurge() (any, error) {
	days := s.req.Days
	if days <= 0 {
		days = TrashDays
	}
	return map[string]any{"deleted": s.purge(days)}, nil
}

// purge removes entries older than days.
func (s *session) purge(days int) int {
	items, err := s.items()
	if err != nil {
		return 0
	}
	n := 0
	cutoff := s.req.Now - int64(days)*86400
	for _, it := range items {
		if it.DeletedAt < cutoff {
			if removeAll(filepath.Join(s.req.Trash, it.ID)) == nil {
				n++
			}
		}
	}
	return n
}

// removeAll deletes a tree, first making read-only folders writable so
// a folder the customer locked (chmod 555) can still go.
func removeAll(p string) error {
	if err := os.RemoveAll(p); err == nil {
		return nil
	}
	_ = filepath.WalkDir(p, func(q string, d fs.DirEntry, err error) error {
		if err == nil && d.IsDir() {
			_ = os.Chmod(q, 0o700)
		}
		return nil
	})
	return os.RemoveAll(p)
}
