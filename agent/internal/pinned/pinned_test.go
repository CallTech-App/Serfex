package pinned

import (
	"archive/tar"
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

type entry struct {
	name, body string
	typ        byte
}

func archive(t *testing.T, entries ...entry) []byte {
	t.Helper()
	var buf bytes.Buffer
	tw := tar.NewWriter(&buf)
	for _, e := range entries {
		typ := e.typ
		if typ == 0 {
			typ = tar.TypeReg
		}
		hdr := &tar.Header{Name: e.name, Typeflag: typ, Mode: 0o777, Size: int64(len(e.body))}
		if typ == tar.TypeSymlink {
			hdr.Linkname, hdr.Size = e.body, 0
		}
		if err := tw.WriteHeader(hdr); err != nil {
			t.Fatal(err)
		}
		if typ == tar.TypeReg {
			if _, err := tw.Write([]byte(e.body)); err != nil {
				t.Fatal(err)
			}
		}
	}
	if err := tw.Close(); err != nil {
		t.Fatal(err)
	}
	return buf.Bytes()
}

func TestUnpackTar(t *testing.T) {
	dir := t.TempDir()
	data := archive(t,
		entry{name: "tool-1.0/", typ: tar.TypeDir},
		entry{name: "tool-1.0/index.php", body: "<?php\n"},
		entry{name: "./tool-1.0/lib/a.php", body: "a"},
		entry{name: "tool-1.0/link", body: "/etc/shadow", typ: tar.TypeSymlink},
	)
	if err := UnpackTar(bytes.NewReader(data), dir, 1<<20); err != nil {
		t.Fatal(err)
	}
	for f, want := range map[string]string{"index.php": "<?php\n", "lib/a.php": "a"} {
		b, err := os.ReadFile(filepath.Join(dir, f))
		if err != nil || string(b) != want {
			t.Errorf("%s: %q, %v", f, b, err)
		}
		if fi, _ := os.Stat(filepath.Join(dir, f)); fi.Mode().Perm() != 0o644 {
			t.Errorf("%s: mode %v", f, fi.Mode())
		}
	}
	if _, err := os.Lstat(filepath.Join(dir, "link")); err == nil {
		t.Error("a link was unpacked")
	}

	for what, entries := range map[string][]entry{
		"outside":      {{name: "tool-1.0/../../x", body: "x"}},
		"absolute":     {{name: "/etc/cron.d/x", body: "x"}},
		"second top":   {{name: "tool-1.0/a", body: "a"}, {name: "other/b", body: "b"}},
		"too large":    {{name: "tool-1.0/big", body: strings.Repeat("x", 2048)}},
		"empty":        nil,
		"dot dot only": {{name: "..", typ: tar.TypeDir}},
	} {
		if err := UnpackTar(bytes.NewReader(archive(t, entries...)), t.TempDir(), 1024); err == nil {
			t.Errorf("%s: unpacked", what)
		}
	}
}

func TestFetchChecksTheFile(t *testing.T) {
	sum := sha256.Sum256([]byte("release"))
	r := Release{Version: "1.0", URL: "https://example.org/tool-1.0.tar.gz", SHA256: hex.EncodeToString(sum[:])}
	get := func(body string, err error) func(context.Context, string) ([]byte, error) {
		return func(_ context.Context, url string) ([]byte, error) {
			if url != r.URL {
				t.Errorf("url %s", url)
			}
			return []byte(body), err
		}
	}
	if b, err := r.Fetch(context.Background(), get("release", nil)); err != nil || string(b) != "release" {
		t.Errorf("%q, %v", b, err)
	}
	if _, err := r.Fetch(context.Background(), get("changed", nil)); err == nil || !strings.Contains(err.Error(), "SHA-256") {
		t.Errorf("a changed file was taken: %v", err)
	}
	if _, err := r.Fetch(context.Background(), get("", errors.New("offline"))); err == nil {
		t.Error("no error")
	}
}
