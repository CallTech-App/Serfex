package files

import (
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"syscall"
	"time"
	"unicode/utf8"
)

// Request is one file operation, as the manager hands it to the helper.
// Root and Trash are absolute; every other path is relative to Root
// ("" is Root itself).
type Request struct {
	Op    string `json:"op"`
	Root  string `json:"root"`
	Trash string `json:"trash"`
	// Now is the time of the request (Unix seconds), for trash entries.
	Now int64 `json:"now"`

	Path   string   `json:"path,omitempty"`
	Paths  []string `json:"paths,omitempty"`
	To     string   `json:"to,omitempty"`
	Name   string   `json:"name,omitempty"`
	Data   []byte   `json:"data,omitempty"`
	Offset int64    `json:"offset,omitempty"`
	// MTime is the file's modification time (Unix microseconds) when
	// the editor opened it.
	MTime     int64    `json:"mtime,omitempty"`
	Mode      string   `json:"mode,omitempty"`
	DirMode   string   `json:"dir_mode,omitempty"`
	FileMode  string   `json:"file_mode,omitempty"`
	Recursive bool     `json:"recursive,omitempty"`
	Overwrite bool     `json:"overwrite,omitempty"`
	Format    string   `json:"format,omitempty"`
	Query     string   `json:"query,omitempty"`
	Upload    string   `json:"upload,omitempty"`
	Final     bool     `json:"final,omitempty"`
	IDs       []string `json:"ids,omitempty"`
	Days      int      `json:"days,omitempty"`
}

// Error is a refused or failed operation. Code is stable (the panel shows
// its own text for it); Path names the entry it is about.
type Error struct {
	Code string `json:"code"`
	Path string `json:"path,omitempty"`
	Msg  string `json:"message"`
}

func (e *Error) Error() string {
	if e.Path != "" {
		return e.Code + ": " + e.Path + ": " + e.Msg
	}
	return e.Code + ": " + e.Msg
}

func fail(code, p, format string, a ...any) error {
	return &Error{Code: code, Path: p, Msg: fmt.Sprintf(format, a...)}
}

// Error codes.
const (
	CodeExists    = "exists"
	CodeNotFound  = "not_found"
	CodeNotDir    = "not_dir"
	CodeIsDir     = "is_dir"
	CodeOutside   = "outside"
	CodeName      = "invalid_name"
	CodeBinary    = "binary"
	CodeTooBig    = "too_big"
	CodeConflict  = "conflict"
	CodeDenied    = "denied"
	CodeArchive   = "archive"
	CodeInvalid   = "invalid"
	CodeTooMany   = "too_many"
	CodeIntoSelf  = "into_itself"
	CodeNoSpace   = "no_space"
	CodeOperation = "failed"
)

// Limits.
const (
	// MaxEditBytes is the largest file the editor opens.
	MaxEditBytes = 2 << 20
	// ChunkBytes is how much one read returns and one upload write takes.
	ChunkBytes = 4 << 20
	// MaxUploadBytes bounds one uploaded file.
	MaxUploadBytes = 2 << 30
	// MaxListEntries bounds one folder listing.
	MaxListEntries = 5000
	// MaxSearchResults bounds a search.
	MaxSearchResults = 500
	// MaxExtractBytes bounds what one archive may unpack to.
	MaxExtractBytes = 20 << 30
	// MaxExtractEntries bounds the entries of one archive.
	MaxExtractEntries = 200000
	// TrashDays is how long deleted entries stay in the trash.
	TrashDays = 7
	// QuarantineDays is how long files the security scan quarantined
	// stay in the trash.
	QuarantineDays = 30
	// uploadPrefix names unfinished uploads; listings hide them.
	uploadPrefix = ".serfex-upload-"
	// searchTime bounds a search.
	searchTime = 15 * time.Second
)

// Entry is one file, folder or link of a listing.
type Entry struct {
	Name   string `json:"name"`
	Path   string `json:"path"`
	Type   string `json:"type"` // dir, file, link
	Size   int64  `json:"size"`
	MTime  int64  `json:"mtime"`
	Mode   string `json:"mode"`
	Owned  bool   `json:"owned"`
	Target string `json:"target,omitempty"`
	// TargetDir is set for links to folders inside the root.
	TargetDir string `json:"target_dir,omitempty"`
}

// folderLike is a folder or a link to one; listings show those first.
func (e Entry) folderLike() bool {
	return e.Type == "dir" || e.Type == "link" && e.TargetDir != ""
}

// session is the helper at work on one request.
type session struct {
	req  Request
	root string // on disk
	real string // root with symlinks resolved
	uid  int
}

// Serve runs one request read from in and writes the answer to out: the
// helper process the manager starts as the site's own user. It refuses to
// run as root, so a mistake can never turn it into a root file manager.
func Serve(in io.Reader, out io.Writer) int {
	enc := json.NewEncoder(out)
	if os.Geteuid() == 0 {
		_ = enc.Encode(map[string]any{"error": &Error{Code: CodeDenied, Msg: "the file helper does not run as root"}})
		return 1
	}
	syscall.Umask(0o022)
	var req Request
	if err := json.NewDecoder(io.LimitReader(in, 64<<20)).Decode(&req); err != nil {
		_ = enc.Encode(map[string]any{"error": &Error{Code: CodeInvalid, Msg: err.Error()}})
		return 1
	}
	res, err := Execute(req, "")
	if err != nil {
		_ = enc.Encode(map[string]any{"error": asError(err)})
		return 1
	}
	_ = enc.Encode(map[string]any{"result": res})
	return 0
}

// asError turns any error into an Error for the manager.
func asError(err error) *Error {
	var e *Error
	if errors.As(err, &e) {
		return e
	}
	switch {
	case errors.Is(err, fs.ErrNotExist):
		return &Error{Code: CodeNotFound, Msg: err.Error()}
	case errors.Is(err, fs.ErrExist):
		return &Error{Code: CodeExists, Msg: err.Error()}
	case errors.Is(err, fs.ErrPermission):
		return &Error{Code: CodeDenied, Msg: err.Error()}
	case errors.Is(err, syscall.ENOSPC), errors.Is(err, syscall.EDQUOT):
		return &Error{Code: CodeNoSpace, Msg: err.Error()}
	case errors.Is(err, syscall.ENOTEMPTY):
		return &Error{Code: CodeExists, Msg: err.Error()}
	}
	return &Error{Code: CodeOperation, Msg: err.Error()}
}

// Execute performs req. prefix stands in for / (the simulator's root);
// it is empty on a real server.
func Execute(req Request, prefix string) (any, error) {
	root := filepath.Join(prefix, filepath.Clean("/"+req.Root))
	if req.Root == "" || !filepath.IsAbs(req.Root) {
		return nil, fail(CodeInvalid, "", "root must be absolute")
	}
	real, err := filepath.EvalSymlinks(root)
	if err != nil {
		return nil, fail(CodeNotFound, "", "the site folder is missing: %v", err)
	}
	if req.Now == 0 {
		req.Now = time.Now().Unix()
	}
	if req.Trash != "" {
		req.Trash = filepath.Join(prefix, filepath.Clean("/"+req.Trash))
	}
	s := &session{req: req, root: root, real: real, uid: os.Geteuid()}
	switch req.Op {
	case "list":
		return s.list()
	case "read":
		return s.read()
	case "text":
		return s.text()
	case "save":
		return s.save()
	case "upload":
		return s.upload()
	case "mkdir":
		return s.mkdir()
	case "create":
		return s.create()
	case "rename":
		return s.rename()
	case "move":
		return s.move()
	case "copy":
		return s.copy()
	case "delete":
		return s.delete()
	case "quarantine":
		return s.quarantine()
	case "chmod":
		return s.chmod()
	case "compress":
		return s.compress()
	case "extract":
		return s.extract()
	case "search":
		return s.search()
	case "trash.list":
		return s.trashList()
	case "trash.restore":
		return s.trashRestore()
	case "trash.delete":
		return s.trashDelete()
	case "trash.purge":
		return s.trashPurge()
	}
	return nil, fail(CodeInvalid, "", "unknown operation %q", req.Op)
}

// Ops are the operations Execute knows.
var Ops = []string{"list", "read", "text", "save", "upload", "mkdir", "create", "rename", "move", "copy", "delete", "quarantine", "chmod", "compress", "extract", "search", "trash.list", "trash.restore", "trash.delete", "trash.purge"}

// clean checks a relative path from the panel and returns it cleaned:
// "" for the root, otherwise "a/b". ".." can never climb out.
func clean(rel string) (string, error) {
	if strings.ContainsRune(rel, 0) || len(rel) > 4096 {
		return "", fail(CodeName, rel, "invalid path")
	}
	for _, part := range strings.Split(rel, "/") {
		if part == ".." {
			return "", fail(CodeOutside, rel, "paths may not contain ..")
		}
	}
	return strings.TrimPrefix(path.Clean("/"+rel), "/"), nil
}

// validName checks a single file or folder name.
func validName(name string) error {
	switch {
	case name == "" || name == "." || name == "..":
		return fail(CodeName, name, "a name is required")
	case len(name) > 255:
		return fail(CodeName, name, "names are at most 255 bytes")
	case strings.ContainsAny(name, "/\x00"):
		return fail(CodeName, name, "names may not contain / or NUL")
	case !utf8.ValidString(name):
		return fail(CodeName, name, "names must be UTF-8")
	case strings.HasPrefix(name, uploadPrefix):
		return fail(CodeName, name, "this name is reserved for uploads")
	}
	return nil
}

// disk maps a cleaned relative path to its place on disk.
func (s *session) disk(rel string) string {
	if rel == "" {
		return s.root
	}
	return filepath.Join(s.root, filepath.FromSlash(rel))
}

// within reports whether resolved (symlinks resolved) lies inside the root.
func (s *session) within(resolved string) bool {
	return resolved == s.real || strings.HasPrefix(resolved, s.real+string(filepath.Separator))
}

// entry resolves rel for an operation on the entry itself (delete,
// rename, chmod, move): its folder must resolve inside the root, the entry
// itself is not followed. The root itself is refused.
func (s *session) entry(raw string) (rel, abs string, err error) {
	rel, err = clean(raw)
	if err != nil {
		return "", "", err
	}
	if rel == "" {
		return "", "", fail(CodeDenied, "", "the site folder itself cannot be changed")
	}
	dir, err := filepath.EvalSymlinks(filepath.Dir(s.disk(rel)))
	if err != nil {
		return "", "", fail(CodeNotFound, rel, "folder not found")
	}
	if !s.within(dir) {
		return "", "", fail(CodeOutside, rel, "outside the site folder")
	}
	abs = filepath.Join(dir, path.Base(rel))
	if _, err := os.Lstat(abs); err != nil {
		return "", "", fail(CodeNotFound, rel, "not found")
	}
	return rel, abs, nil
}

// follow resolves rel through symlinks for reading or writing content;
// the result must lie inside the root.
func (s *session) follow(raw string) (rel, abs string, err error) {
	rel, err = clean(raw)
	if err != nil {
		return "", "", err
	}
	abs, err = filepath.EvalSymlinks(s.disk(rel))
	if err != nil {
		return "", "", fail(CodeNotFound, rel, "not found")
	}
	if !s.within(abs) {
		return "", "", fail(CodeOutside, rel, "outside the site folder")
	}
	return rel, abs, nil
}

// folder resolves rel to a folder inside the root.
func (s *session) folder(raw string) (rel, abs string, err error) {
	rel, abs, err = s.follow(raw)
	if err != nil {
		return "", "", err
	}
	fi, err := os.Stat(abs)
	if err != nil {
		return "", "", fail(CodeNotFound, rel, "not found")
	}
	if !fi.IsDir() {
		return "", "", fail(CodeNotDir, rel, "not a folder")
	}
	return rel, abs, nil
}

// child joins a folder and a new entry's name, refusing taken names.
func child(dirRel, dirAbs, name string) (rel, abs string, err error) {
	if err := validName(name); err != nil {
		return "", "", err
	}
	rel = path.Join(dirRel, name)
	abs = filepath.Join(dirAbs, name)
	if _, err := os.Lstat(abs); err == nil {
		return "", "", fail(CodeExists, rel, "already exists")
	}
	return rel, abs, nil
}

func modeString(m fs.FileMode) string {
	return fmt.Sprintf("%03o", m.Perm())
}

func parseMode(s string) (fs.FileMode, error) {
	if len(s) < 3 || len(s) > 4 {
		return 0, fail(CodeInvalid, "", "permissions are three octal digits, such as 644")
	}
	v, err := strconv.ParseUint(s, 8, 32)
	if err != nil || v > 0o777 {
		return 0, fail(CodeInvalid, "", "permissions are three octal digits, such as 644 (no special bits)")
	}
	return fs.FileMode(v), nil
}

func (s *session) describe(dirRel, name string, fi fs.FileInfo, abs string) Entry {
	e := Entry{Name: name, Path: path.Join(dirRel, name), Size: fi.Size(), MTime: fi.ModTime().Unix(), Mode: modeString(fi.Mode()), Type: "file"}
	if st, ok := fi.Sys().(*syscall.Stat_t); ok {
		e.Owned = int(st.Uid) == s.uid
	}
	switch {
	case fi.IsDir():
		e.Type, e.Size = "dir", 0
	case fi.Mode()&fs.ModeSymlink != 0:
		e.Type = "link"
		e.Target, _ = os.Readlink(abs)
		if r, err := filepath.EvalSymlinks(abs); err == nil && s.within(r) {
			if ti, err := os.Stat(r); err == nil && ti.IsDir() {
				rel, _ := filepath.Rel(s.real, r)
				e.TargetDir = filepath.ToSlash(rel)
				if e.TargetDir == "." {
					e.TargetDir = ""
				}
			}
		}
	}
	return e
}

func (s *session) list() (any, error) {
	rel, abs, err := s.folder(s.req.Path)
	if err != nil {
		return nil, err
	}
	d, err := os.Open(abs)
	if err != nil {
		return nil, err
	}
	defer d.Close()
	names, err := d.Readdirnames(-1)
	if err != nil {
		return nil, err
	}
	out := []Entry{}
	truncated := false
	for _, name := range names {
		if strings.HasPrefix(name, uploadPrefix) {
			// Unfinished uploads nobody continued within a day go.
			if fi, err := os.Lstat(filepath.Join(abs, name)); err == nil && time.Since(fi.ModTime()) > 24*time.Hour {
				_ = os.Remove(filepath.Join(abs, name))
			}
			continue
		}
		if len(out) >= MaxListEntries {
			truncated = true
			break
		}
		fi, err := os.Lstat(filepath.Join(abs, name))
		if err != nil {
			continue
		}
		out = append(out, s.describe(rel, name, fi, filepath.Join(abs, name)))
	}
	sort.Slice(out, func(i, j int) bool {
		if di, dj := out[i].folderLike(), out[j].folderLike(); di != dj {
			return di
		}
		return strings.ToLower(out[i].Name) < strings.ToLower(out[j].Name)
	})
	return map[string]any{"path": rel, "entries": out, "truncated": truncated}, nil
}

func (s *session) read() (any, error) {
	rel, abs, err := s.follow(s.req.Path)
	if err != nil {
		return nil, err
	}
	f, err := os.Open(abs)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	fi, err := f.Stat()
	if err != nil {
		return nil, err
	}
	if fi.IsDir() {
		return nil, fail(CodeIsDir, rel, "is a folder")
	}
	buf := make([]byte, ChunkBytes)
	n, err := f.ReadAt(buf, s.req.Offset)
	if err != nil && !errors.Is(err, io.EOF) {
		return nil, err
	}
	return map[string]any{"data": buf[:n], "offset": s.req.Offset + int64(n), "size": fi.Size()}, nil
}

// text opens a file for the editor: UTF-8 text up to MaxEditBytes.
func (s *session) text() (any, error) {
	rel, abs, err := s.follow(s.req.Path)
	if err != nil {
		return nil, err
	}
	fi, err := os.Stat(abs)
	if err != nil {
		return nil, err
	}
	if fi.IsDir() {
		return nil, fail(CodeIsDir, rel, "is a folder")
	}
	if fi.Size() > MaxEditBytes {
		return nil, fail(CodeTooBig, rel, "larger than %d bytes", MaxEditBytes)
	}
	data, err := os.ReadFile(abs)
	if err != nil {
		return nil, err
	}
	if !isText(data) {
		return nil, fail(CodeBinary, rel, "not a text file")
	}
	return map[string]any{"path": rel, "content": string(data), "mtime": fi.ModTime().UnixMicro(), "size": fi.Size(), "mode": modeString(fi.Mode())}, nil
}

func isText(data []byte) bool {
	head := data
	if len(head) > 8192 {
		head = head[:8192]
	}
	for _, b := range head {
		if b == 0 {
			return false
		}
	}
	return utf8.Valid(data)
}

// save replaces a file's content for the editor. The previous version
// goes to the trash (so Undo can bring it back); a file changed by
// someone else since it was opened (MTime) is not overwritten.
func (s *session) save() (any, error) {
	rel, abs, err := s.follow(s.req.Path)
	if err != nil {
		return nil, err
	}
	fi, err := os.Stat(abs)
	if err != nil {
		return nil, err
	}
	if fi.IsDir() {
		return nil, fail(CodeIsDir, rel, "is a folder")
	}
	if s.req.MTime != 0 && fi.ModTime().UnixMicro() != s.req.MTime {
		return nil, fail(CodeConflict, rel, "the file changed since it was opened")
	}
	if len(s.req.Data) > MaxEditBytes {
		return nil, fail(CodeTooBig, rel, "larger than %d bytes", MaxEditBytes)
	}
	id, err := s.replace(rel, abs, fi.Mode().Perm(), func(f *os.File) error {
		_, err := f.Write(s.req.Data)
		return err
	})
	if err != nil {
		return nil, err
	}
	nfi, err := os.Stat(abs)
	if err != nil {
		return nil, err
	}
	return map[string]any{"path": rel, "mtime": nfi.ModTime().UnixMicro(), "size": nfi.Size(), "trash": id}, nil
}

// replace writes a new version of abs through a temporary file and an
// atomic rename. The old version is kept in the trash (hard-linked, so
// the file is never missing). Returns the trash id ("" when none).
func (s *session) replace(rel, abs string, mode fs.FileMode, write func(*os.File) error) (string, error) {
	tmp, err := os.CreateTemp(filepath.Dir(abs), uploadPrefix+"save-")
	if err != nil {
		return "", err
	}
	defer os.Remove(tmp.Name())
	if err := write(tmp); err != nil {
		tmp.Close()
		return "", err
	}
	if err := tmp.Close(); err != nil {
		return "", err
	}
	if err := os.Chmod(tmp.Name(), mode); err != nil {
		return "", err
	}
	id := ""
	if _, err := os.Lstat(abs); err == nil {
		id, err = s.keepVersion(rel, abs)
		if err != nil {
			return "", err
		}
	}
	if err := os.Rename(tmp.Name(), abs); err != nil {
		return "", err
	}
	return id, nil
}

// upload appends a chunk to an unfinished upload in a folder; the final
// chunk moves it into place. An existing file of that name goes to the
// trash first when Overwrite is set, and is refused otherwise.
func (s *session) upload() (any, error) {
	dirRel, dirAbs, err := s.folder(s.req.Path)
	if err != nil {
		return nil, err
	}
	if err := validName(s.req.Name); err != nil {
		return nil, err
	}
	if !uploadIDRe(s.req.Upload) {
		return nil, fail(CodeInvalid, "", "bad upload id")
	}
	rel := path.Join(dirRel, s.req.Name)
	target := filepath.Join(dirAbs, s.req.Name)
	if fi, err := os.Lstat(target); err == nil {
		if fi.IsDir() {
			return nil, fail(CodeIsDir, rel, "a folder has this name")
		}
		if !s.req.Overwrite {
			return nil, fail(CodeExists, rel, "already exists")
		}
	}
	part := filepath.Join(dirAbs, uploadPrefix+s.req.Upload)
	f, err := os.OpenFile(part, os.O_WRONLY|os.O_CREATE, 0o644)
	if err != nil {
		return nil, err
	}
	fi, err := f.Stat()
	if err != nil {
		f.Close()
		return nil, err
	}
	if fi.Size() != s.req.Offset {
		f.Close()
		return nil, fail(CodeConflict, rel, "the upload is at %d bytes, not %d", fi.Size(), s.req.Offset)
	}
	if s.req.Offset+int64(len(s.req.Data)) > MaxUploadBytes {
		f.Close()
		_ = os.Remove(part)
		return nil, fail(CodeTooBig, rel, "uploads are at most %d bytes", MaxUploadBytes)
	}
	if _, err := f.WriteAt(s.req.Data, s.req.Offset); err != nil {
		f.Close()
		_ = os.Remove(part)
		return nil, err
	}
	if err := f.Close(); err != nil {
		return nil, err
	}
	size := s.req.Offset + int64(len(s.req.Data))
	if !s.req.Final {
		return map[string]any{"size": size}, nil
	}
	id := ""
	if _, err := os.Lstat(target); err == nil {
		if id, err = s.toTrash(rel, target, "replaced"); err != nil {
			return nil, err
		}
	}
	if err := os.Rename(part, target); err != nil {
		return nil, err
	}
	return map[string]any{"size": size, "path": rel, "trash": id}, nil
}

func uploadIDRe(id string) bool {
	if len(id) < 8 || len(id) > 64 {
		return false
	}
	for _, r := range id {
		if !(r >= 'a' && r <= 'z' || r >= '0' && r <= '9' || r == '-') {
			return false
		}
	}
	return true
}

func (s *session) mkdir() (any, error) {
	dirRel, dirAbs, err := s.folder(s.req.Path)
	if err != nil {
		return nil, err
	}
	rel, abs, err := child(dirRel, dirAbs, s.req.Name)
	if err != nil {
		return nil, err
	}
	if err := os.Mkdir(abs, 0o755); err != nil {
		return nil, err
	}
	return map[string]any{"path": rel}, nil
}

func (s *session) create() (any, error) {
	dirRel, dirAbs, err := s.folder(s.req.Path)
	if err != nil {
		return nil, err
	}
	rel, abs, err := child(dirRel, dirAbs, s.req.Name)
	if err != nil {
		return nil, err
	}
	f, err := os.OpenFile(abs, os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0o644)
	if err != nil {
		return nil, err
	}
	if _, err := f.Write(s.req.Data); err != nil {
		f.Close()
		return nil, err
	}
	return map[string]any{"path": rel}, f.Close()
}

func (s *session) rename() (any, error) {
	rel, abs, err := s.entry(s.req.Path)
	if err != nil {
		return nil, err
	}
	if s.req.Name == path.Base(rel) {
		return map[string]any{"path": rel}, nil
	}
	dirRel := path.Dir(rel)
	if dirRel == "." {
		dirRel = ""
	}
	nrel, nabs, err := child(dirRel, filepath.Dir(abs), s.req.Name)
	if err != nil {
		return nil, err
	}
	if err := os.Rename(abs, nabs); err != nil {
		return nil, err
	}
	return map[string]any{"path": nrel, "from": rel}, nil
}

// move moves entries into another folder. Nothing is overwritten.
func (s *session) move() (any, error) {
	toRel, toAbs, err := s.folder(s.req.To)
	if err != nil {
		return nil, err
	}
	type moved struct {
		From string `json:"from"`
		To   string `json:"to"`
	}
	var done []moved
	for _, p := range s.req.Paths {
		rel, abs, err := s.entry(p)
		if err != nil {
			return map[string]any{"moved": done}, err
		}
		if into(toAbs, abs) {
			return map[string]any{"moved": done}, fail(CodeIntoSelf, rel, "a folder cannot go into itself")
		}
		nrel, nabs, err := child(toRel, toAbs, path.Base(rel))
		if err != nil {
			if nabs == "" && filepath.Dir(abs) == toAbs {
				continue // already there
			}
			return map[string]any{"moved": done}, err
		}
		if err := os.Rename(abs, nabs); err != nil {
			return map[string]any{"moved": done}, err
		}
		done = append(done, moved{rel, nrel})
	}
	return map[string]any{"moved": done}, nil
}

// into reports whether dir is src or lies inside it.
func into(dir, src string) bool {
	rdir, err := filepath.EvalSymlinks(dir)
	if err != nil {
		rdir = dir
	}
	return rdir == src || strings.HasPrefix(rdir, src+string(filepath.Separator))
}

// copy copies entries into a folder. Copies into the folder an entry is
// already in get a free name ("a kopya.txt").
func (s *session) copy() (any, error) {
	toRel, toAbs, err := s.folder(s.req.To)
	if err != nil {
		return nil, err
	}
	var made []string
	for _, p := range s.req.Paths {
		rel, abs, err := s.entry(p)
		if err != nil {
			return map[string]any{"copied": made}, err
		}
		if into(toAbs, abs) && filepath.Dir(abs) != toAbs {
			return map[string]any{"copied": made}, fail(CodeIntoSelf, rel, "a folder cannot go into itself")
		}
		name := path.Base(rel)
		if _, err := os.Lstat(filepath.Join(toAbs, name)); err == nil {
			if filepath.Dir(abs) != toAbs {
				return map[string]any{"copied": made}, fail(CodeExists, path.Join(toRel, name), "already exists")
			}
			name = freeName(toAbs, name, " kopya")
		}
		dst := filepath.Join(toAbs, name)
		if err := copyTree(abs, dst); err != nil {
			_ = os.RemoveAll(dst)
			return map[string]any{"copied": made}, err
		}
		made = append(made, path.Join(toRel, name))
	}
	return map[string]any{"copied": made}, nil
}

// freeName returns name with suffix (and a number when needed) before its
// extension, not taken in dir.
func freeName(dir, name, suffix string) string {
	ext := path.Ext(name)
	if ext == name || strings.HasPrefix(name, ".") && strings.Count(name, ".") == 1 {
		ext = ""
	}
	base := strings.TrimSuffix(name, ext)
	for i := 1; ; i++ {
		n := base + suffix + ext
		if i > 1 {
			n = base + suffix + " " + strconv.Itoa(i) + ext
		}
		if _, err := os.Lstat(filepath.Join(dir, n)); errors.Is(err, fs.ErrNotExist) {
			return n
		}
	}
}

// copyTree copies a file, link or folder; symlinks are copied as links.
func copyTree(src, dst string) error {
	fi, err := os.Lstat(src)
	if err != nil {
		return err
	}
	switch {
	case fi.Mode()&fs.ModeSymlink != 0:
		t, err := os.Readlink(src)
		if err != nil {
			return err
		}
		return os.Symlink(t, dst)
	case fi.IsDir():
		if err := os.Mkdir(dst, 0o700); err != nil {
			return err
		}
		entries, err := os.ReadDir(src)
		if err != nil {
			return err
		}
		for _, e := range entries {
			if err := copyTree(filepath.Join(src, e.Name()), filepath.Join(dst, e.Name())); err != nil {
				return err
			}
		}
		return os.Chmod(dst, fi.Mode().Perm())
	case fi.Mode().IsRegular():
		in, err := os.Open(src)
		if err != nil {
			return err
		}
		defer in.Close()
		out, err := os.OpenFile(dst, os.O_WRONLY|os.O_CREATE|os.O_EXCL, fi.Mode().Perm())
		if err != nil {
			return err
		}
		if _, err := io.Copy(out, in); err != nil {
			out.Close()
			return err
		}
		if err := out.Close(); err != nil {
			return err
		}
		return os.Chtimes(dst, fi.ModTime(), fi.ModTime())
	}
	return nil // sockets, pipes and devices are skipped
}

// delete moves entries to the trash.
func (s *session) delete() (any, error) {
	ids := []string{}
	for _, p := range s.req.Paths {
		rel, abs, err := s.entry(p)
		if err != nil {
			return map[string]any{"ids": ids}, err
		}
		id, err := s.toTrash(rel, abs, "deleted")
		if err != nil {
			return map[string]any{"ids": ids}, err
		}
		ids = append(ids, id)
	}
	s.purge(TrashDays)
	return map[string]any{"ids": ids}, nil
}

// quarantine moves files the security scan found into the trash, where
// nobody can run or fetch them, for QuarantineDays. A file loses all its
// permissions there; restoring gives them back.
func (s *session) quarantine() (any, error) {
	ids := []string{}
	for _, p := range s.req.Paths {
		rel, abs, err := s.entry(p)
		if err != nil {
			return map[string]any{"ids": ids}, err
		}
		id, err := s.toTrash(rel, abs, "quarantined")
		if err != nil {
			return map[string]any{"ids": ids}, err
		}
		ids = append(ids, id)
	}
	s.purge(TrashDays)
	return map[string]any{"ids": ids}, nil
}

// chmod changes permissions. Recursive applies DirMode to folders and
// FileMode to files below the chosen folders. Links are never changed.
// Returns the previous permissions of the chosen entries (for Undo).
func (s *session) chmod() (any, error) {
	mode, err := parseMode(s.req.Mode)
	if err != nil {
		return nil, err
	}
	var dirMode, fileMode fs.FileMode
	if s.req.Recursive {
		if dirMode, err = parseMode(s.req.DirMode); err != nil {
			return nil, err
		}
		if fileMode, err = parseMode(s.req.FileMode); err != nil {
			return nil, err
		}
	}
	before := map[string]string{}
	changed := 0
	for _, p := range s.req.Paths {
		rel, abs, err := s.entry(p)
		if err != nil {
			return nil, err
		}
		fi, err := os.Lstat(abs)
		if err != nil {
			return nil, err
		}
		if fi.Mode()&fs.ModeSymlink != 0 {
			continue
		}
		before[rel] = modeString(fi.Mode())
		m := mode
		if s.req.Recursive && fi.IsDir() {
			m = dirMode
			err := filepath.WalkDir(abs, func(p string, d fs.DirEntry, err error) error {
				if err != nil || p == abs || d.Type()&fs.ModeSymlink != 0 {
					return nil
				}
				changed++
				if d.IsDir() {
					return os.Chmod(p, dirMode)
				}
				if d.Type().IsRegular() {
					return os.Chmod(p, fileMode)
				}
				return nil
			})
			if err != nil {
				return nil, err
			}
		}
		if err := os.Chmod(abs, m); err != nil {
			return nil, err
		}
		changed++
	}
	return map[string]any{"before": before, "changed": changed}, nil
}

// search finds entries whose name contains Query below Path.
func (s *session) search() (any, error) {
	rel, abs, err := s.folder(s.req.Path)
	if err != nil {
		return nil, err
	}
	q := strings.ToLower(strings.TrimSpace(s.req.Query))
	if q == "" {
		return nil, fail(CodeInvalid, "", "a search needs text")
	}
	deadline := time.Now().Add(searchTime)
	out := []Entry{}
	truncated := false
	err = filepath.WalkDir(abs, func(p string, d fs.DirEntry, err error) error {
		if err != nil {
			return nil
		}
		if p == abs {
			return nil
		}
		if len(out) >= MaxSearchResults || time.Now().After(deadline) {
			truncated = true
			return filepath.SkipAll
		}
		name := d.Name()
		if strings.HasPrefix(name, uploadPrefix) {
			return nil
		}
		if strings.Contains(strings.ToLower(name), q) {
			fi, err := d.Info()
			if err == nil {
				sub, _ := filepath.Rel(abs, filepath.Dir(p))
				dir := path.Join(rel, filepath.ToSlash(sub))
				if dir == "." {
					dir = ""
				}
				out = append(out, s.describe(dir, name, fi, p))
			}
		}
		return nil
	})
	if err != nil {
		return nil, err
	}
	return map[string]any{"path": rel, "entries": out, "truncated": truncated}, nil
}
