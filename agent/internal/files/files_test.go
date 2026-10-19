package files

import (
	"archive/zip"
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/winchester37/servik/agent/internal/host"
)

// newSite makes a simulated server with account kahve and site kahve.com.
func newSite(t *testing.T) (*Manager, *host.Simulator, string) {
	t.Helper()
	sim, err := host.NewSimulator(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	if _, err := sim.Run(context.Background(), host.Command{Name: "useradd", Args: []string{"--home-dir", "/home/kahve", "--shell", "/usr/sbin/nologin", "kahve"}}); err != nil {
		t.Fatal(err)
	}
	dir := sim.Path(SiteDir("kahve", "kahve.com"))
	if err := os.MkdirAll(filepath.Join(dir, "public_html"), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(dir, "public_html", "index.php"), []byte("<?php echo 'hi';\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	return NewManager(sim, "/unused"), sim, dir
}

func do(t *testing.T, m *Manager, r Request) map[string]any {
	t.Helper()
	res, err := m.Do(context.Background(), Spec{User: "kahve", Domain: "kahve.com", Request: r})
	if err != nil {
		t.Fatalf("%s: %v", r.Op, err)
	}
	b, _ := json.Marshal(res)
	var out map[string]any
	_ = json.Unmarshal(b, &out)
	return out
}

func code(t *testing.T, m *Manager, r Request) string {
	t.Helper()
	_, err := m.Do(context.Background(), Spec{User: "kahve", Domain: "kahve.com", Request: r})
	var e *Error
	if !errors.As(err, &e) {
		t.Fatalf("%s: want a file error, got %v", r.Op, err)
	}
	return e.Code
}

func names(res map[string]any) []string {
	var out []string
	for _, e := range res["entries"].([]any) {
		out = append(out, e.(map[string]any)["name"].(string))
	}
	return out
}

func TestListSortsFoldersFirstAndHidesUploads(t *testing.T) {
	m, _, dir := newSite(t)
	_ = os.WriteFile(filepath.Join(dir, "a.txt"), []byte("x"), 0o644)
	_ = os.WriteFile(filepath.Join(dir, uploadPrefix+"abcdefgh12"), []byte("x"), 0o644)
	_ = os.Symlink("public_html", filepath.Join(dir, "current"))
	res := do(t, m, Request{Op: "list"})
	if got := strings.Join(names(res), ","); got != "current,public_html,a.txt" {
		t.Fatalf("listing = %s", got)
	}
	link := res["entries"].([]any)[0].(map[string]any)
	if link["type"] != "link" || link["target_dir"] != "public_html" {
		t.Fatalf("link = %v", link)
	}
}

func TestPathsCannotLeaveTheSite(t *testing.T) {
	m, sim, dir := newSite(t)
	if c := code(t, m, Request{Op: "list", Path: "../.."}); c != CodeOutside {
		t.Fatalf("..: %s", c)
	}
	// A link out of the site is listed but never followed.
	_ = os.WriteFile(sim.Path("/etc/secret"), []byte("root only"), 0o600)
	_ = os.Symlink(sim.Path("/etc"), filepath.Join(dir, "etc"))
	if c := code(t, m, Request{Op: "list", Path: "etc"}); c != CodeOutside {
		t.Fatalf("list through link: %s", c)
	}
	if c := code(t, m, Request{Op: "read", Path: "etc/secret"}); c != CodeOutside {
		t.Fatalf("read through link: %s", c)
	}
	if c := code(t, m, Request{Op: "save", Path: "etc/secret", Data: []byte("x")}); c != CodeOutside {
		t.Fatalf("save through link: %s", c)
	}
	if c := code(t, m, Request{Op: "upload", Path: "etc", Name: "x", Upload: "up-12345678", Final: true}); c != CodeOutside {
		t.Fatalf("upload through link: %s", c)
	}
	// Deleting the link removes the link, not /etc.
	do(t, m, Request{Op: "delete", Paths: []string{"etc"}})
	if _, err := os.Stat(sim.Path("/etc/secret")); err != nil {
		t.Fatal("deleting the link touched its target")
	}
	if c := code(t, m, Request{Op: "delete", Paths: []string{""}}); c != CodeDenied {
		t.Fatalf("delete root: %s", c)
	}
	if c := code(t, m, Request{Op: "mkdir", Name: "../x"}); c != CodeName {
		t.Fatalf("mkdir ../x: %s", c)
	}
}

func TestEditorSaveKeepsPreviousVersion(t *testing.T) {
	m, _, dir := newSite(t)
	res := do(t, m, Request{Op: "text", Path: "public_html/index.php"})
	if res["content"] != "<?php echo 'hi';\n" {
		t.Fatalf("content = %v", res["content"])
	}
	mtime := int64(res["mtime"].(float64))
	saved := do(t, m, Request{Op: "save", Path: "public_html/index.php", Data: []byte("<?php echo 'bye';\n"), MTime: mtime})
	if b, _ := os.ReadFile(filepath.Join(dir, "public_html/index.php")); string(b) != "<?php echo 'bye';\n" {
		t.Fatalf("saved = %q", b)
	}
	// Saving again with the old mtime is a conflict.
	if c := code(t, m, Request{Op: "save", Path: "public_html/index.php", Data: []byte("x"), MTime: mtime}); c != CodeConflict {
		t.Fatalf("stale save: %s", c)
	}
	// Undo brings the old version back.
	do(t, m, Request{Op: "trash.restore", IDs: []string{saved["trash"].(string)}})
	if b, _ := os.ReadFile(filepath.Join(dir, "public_html/index.php")); string(b) != "<?php echo 'hi';\n" {
		t.Fatalf("restored = %q", b)
	}
	// Binary files are not opened.
	_ = os.WriteFile(filepath.Join(dir, "logo.png"), []byte{0x89, 'P', 'N', 'G', 0, 1}, 0o644)
	if c := code(t, m, Request{Op: "text", Path: "logo.png"}); c != CodeBinary {
		t.Fatalf("binary: %s", c)
	}
}

func TestDeleteGoesToTrashAndComesBack(t *testing.T) {
	m, _, dir := newSite(t)
	_ = os.MkdirAll(filepath.Join(dir, "public_html/img"), 0o755)
	_ = os.WriteFile(filepath.Join(dir, "public_html/img/a.jpg"), []byte("jpg"), 0o644)
	res := do(t, m, Request{Op: "delete", Paths: []string{"public_html/img", "public_html/index.php"}})
	ids := res["ids"].([]any)
	if len(ids) != 2 {
		t.Fatalf("ids = %v", ids)
	}
	if _, err := os.Stat(filepath.Join(dir, "public_html/img")); !errors.Is(err, os.ErrNotExist) {
		t.Fatal("folder still there")
	}
	list := do(t, m, Request{Op: "trash.list"})
	if n := len(list["items"].([]any)); n != 2 {
		t.Fatalf("trash has %d items", n)
	}
	// The place is taken meanwhile: the folder comes back renamed.
	_ = os.MkdirAll(filepath.Join(dir, "public_html/img"), 0o755)
	back := do(t, m, Request{Op: "trash.restore", IDs: []string{ids[0].(string), ids[1].(string)}})
	paths := []string{}
	for _, r := range back["restored"].([]any) {
		paths = append(paths, r.(map[string]any)["path"].(string))
	}
	if strings.Join(paths, ",") != "public_html/img (geri yüklendi),public_html/index.php" {
		t.Fatalf("restored = %v", paths)
	}
	if b, _ := os.ReadFile(filepath.Join(dir, "public_html/img (geri yüklendi)/a.jpg")); string(b) != "jpg" {
		t.Fatal("folder content lost")
	}
	// Old entries are purged.
	do(t, m, Request{Op: "delete", Paths: []string{"public_html/img"}})
	trash := m.h.Path(TrashDir("kahve", "kahve.com"))
	entries, _ := os.ReadDir(trash)
	for _, e := range entries {
		item, _ := readMeta(filepath.Join(trash, e.Name()))
		item.DeletedAt -= 8 * 86400
		_ = writeMeta(filepath.Join(trash, e.Name()), item)
	}
	if res := do(t, m, Request{Op: "trash.purge"}); res["deleted"].(float64) != 1 {
		t.Fatalf("purged %v", res["deleted"])
	}
}

func TestUploadInChunksAndReplace(t *testing.T) {
	m, _, dir := newSite(t)
	do(t, m, Request{Op: "upload", Path: "public_html", Name: "big.bin", Upload: "up-aaaaaaaa", Data: []byte("hello ")})
	if c := code(t, m, Request{Op: "upload", Path: "public_html", Name: "big.bin", Upload: "up-aaaaaaaa", Offset: 3, Data: []byte("x")}); c != CodeConflict {
		t.Fatalf("wrong offset: %s", c)
	}
	res := do(t, m, Request{Op: "upload", Path: "public_html", Name: "big.bin", Upload: "up-aaaaaaaa", Offset: 6, Data: []byte("world"), Final: true})
	if res["size"].(float64) != 11 {
		t.Fatalf("size %v", res["size"])
	}
	if b, _ := os.ReadFile(filepath.Join(dir, "public_html/big.bin")); string(b) != "hello world" {
		t.Fatalf("upload = %q", b)
	}
	// Uploading over an existing file needs Overwrite; the old one is kept.
	if c := code(t, m, Request{Op: "upload", Path: "public_html", Name: "index.php", Upload: "up-bbbbbbbb", Data: []byte("new"), Final: true}); c != CodeExists {
		t.Fatalf("no overwrite: %s", c)
	}
	res = do(t, m, Request{Op: "upload", Path: "public_html", Name: "index.php", Upload: "up-bbbbbbbb", Data: []byte("new"), Final: true, Overwrite: true})
	if res["trash"] == "" {
		t.Fatal("old version not kept")
	}
}

func TestRenameMoveCopyChmod(t *testing.T) {
	m, _, dir := newSite(t)
	do(t, m, Request{Op: "mkdir", Path: "public_html", Name: "lib"})
	do(t, m, Request{Op: "create", Path: "public_html", Name: "a.txt"})
	do(t, m, Request{Op: "rename", Path: "public_html/a.txt", Name: "b.txt"})
	do(t, m, Request{Op: "move", Paths: []string{"public_html/b.txt"}, To: "public_html/lib"})
	if _, err := os.Stat(filepath.Join(dir, "public_html/lib/b.txt")); err != nil {
		t.Fatal(err)
	}
	if c := code(t, m, Request{Op: "move", Paths: []string{"public_html"}, To: "public_html/lib"}); c != CodeIntoSelf {
		t.Fatalf("into itself: %s", c)
	}
	res := do(t, m, Request{Op: "copy", Paths: []string{"public_html/index.php"}, To: "public_html"})
	if got := res["copied"].([]any)[0]; got != "public_html/index kopya.php" {
		t.Fatalf("copy = %v", got)
	}
	do(t, m, Request{Op: "copy", Paths: []string{"public_html/lib"}, To: ""})
	if _, err := os.Stat(filepath.Join(dir, "lib/b.txt")); err != nil {
		t.Fatal(err)
	}
	res = do(t, m, Request{Op: "chmod", Paths: []string{"public_html/index.php"}, Mode: "600"})
	if res["before"].(map[string]any)["public_html/index.php"] != "644" {
		t.Fatalf("before = %v", res["before"])
	}
	if fi, _ := os.Stat(filepath.Join(dir, "public_html/index.php")); fi.Mode().Perm() != 0o600 {
		t.Fatalf("mode = %v", fi.Mode())
	}
	if c := code(t, m, Request{Op: "chmod", Paths: []string{"public_html/index.php"}, Mode: "4755"}); c != CodeInvalid {
		t.Fatalf("setuid: %s", c)
	}
	do(t, m, Request{Op: "chmod", Paths: []string{"public_html"}, Mode: "755", Recursive: true, DirMode: "750", FileMode: "640"})
	if fi, _ := os.Stat(filepath.Join(dir, "public_html/lib")); fi.Mode().Perm() != 0o750 {
		t.Fatalf("dir mode = %v", fi.Mode())
	}
	if fi, _ := os.Stat(filepath.Join(dir, "public_html/lib/b.txt")); fi.Mode().Perm() != 0o640 {
		t.Fatalf("file mode = %v", fi.Mode())
	}
}

func TestCompressExtractAndSearch(t *testing.T) {
	m, _, dir := newSite(t)
	_ = os.MkdirAll(filepath.Join(dir, "public_html/css"), 0o755)
	_ = os.WriteFile(filepath.Join(dir, "public_html/css/app.css"), []byte("body{}"), 0o644)
	for _, format := range []string{"zip", "tar.gz"} {
		res := do(t, m, Request{Op: "compress", Path: "public_html", Paths: []string{"public_html/css", "public_html/index.php"}, Name: "site", Format: format})
		archive := res["path"].(string)
		do(t, m, Request{Op: "mkdir", Name: "out-" + format})
		do(t, m, Request{Op: "extract", Path: archive, To: "out-" + format})
		if b, _ := os.ReadFile(filepath.Join(dir, "out-"+format, "css/app.css")); string(b) != "body{}" {
			t.Fatalf("%s: extracted %q", format, b)
		}
		// Extracting again collides unless Overwrite.
		if c := code(t, m, Request{Op: "extract", Path: archive, To: "out-" + format}); c != CodeExists {
			t.Fatalf("%s: second extract: %s", format, c)
		}
		do(t, m, Request{Op: "extract", Path: archive, To: "out-" + format, Overwrite: true})
	}
	// A zip that climbs out is refused before anything is written.
	var buf bytes.Buffer
	zw := zip.NewWriter(&buf)
	w, _ := zw.Create("../../evil.php")
	_, _ = w.Write([]byte("x"))
	_ = zw.Close()
	_ = os.WriteFile(filepath.Join(dir, "evil.zip"), buf.Bytes(), 0o644)
	if c := code(t, m, Request{Op: "extract", Path: "evil.zip", To: ""}); c != CodeOutside {
		t.Fatalf("zip slip: %s", c)
	}
	res := do(t, m, Request{Op: "search", Query: "APP"})
	if got := names(res); len(got) != 3 {
		t.Fatalf("search = %v", got)
	}
}
