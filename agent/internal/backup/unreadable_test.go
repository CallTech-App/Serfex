package backup

import (
	"archive/tar"
	"compress/gzip"
	"context"
	"io"
	"os"
	"os/exec"
	osuser "os/user"
	"path/filepath"
	"slices"
	"strconv"
	"strings"
	"testing"

	"github.com/calltech-app/serfex/agent/internal/database"
	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/i18n"
	"github.com/calltech-app/serfex/agent/internal/laravel"
)

func TestUnreadableReadsWhatTarLeftOut(t *testing.T) {
	out := "tar: ./laravel/releases/1/.env.bak-2026-10-29: Warning: Cannot open: Permission denied\n" +
		"tar: ./private: Warning: Cannot open: Permission denied\n" +
		"tar: ./storage/logs/laravel.log: file changed as we read it\n"
	if got, want := unreadable(out), []string{"./laravel/releases/1/.env.bak-2026-10-29", "./private"}; !slices.Equal(got, want) {
		t.Fatalf("unreadable %v, want %v", got, want)
	}
	if got := firstLine(changed(out)); got != "tar: ./storage/logs/laravel.log: file changed as we read it" {
		t.Fatalf("changed %q", got)
	}
	if got := unreadable("tar: ./a: file changed as we read it\n"); len(got) != 0 {
		t.Fatalf("a changed file read as unreadable: %v", got)
	}

	ctx := context.Background()
	if got, want := unreadableWarning(ctx, "roifex", []string{"./a"}), "roifex kullanıcısının okuyamadığı bir dosya yedeğe alınmadı; sahibini roifex yapın ya da silin: ./a"; got != want {
		t.Fatalf("warning %q, want %q", got, want)
	}
	en := i18n.WithLocale(ctx, "en")
	got := unreadableWarning(en, "roifex", []string{"./a", "./b", "./c", "./d", "./e"})
	if want := "user roifex cannot read 5 files, so they were left out of the backup; make roifex their owner or delete them: ./a, ./b, ./c and 2 more"; got != want {
		t.Fatalf("warning %q, want %q", got, want)
	}
}

// A file the site's account cannot read (a root-owned copy of .env, say)
// is left out with a warning; the rest is still archived.
func TestUnreadableFileDoesNotFailTheArchive(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("needs root")
	}
	if _, err := exec.LookPath("runuser"); err != nil {
		t.Skip("no runuser")
	}
	nobody, err := osuser.Lookup("nobody")
	if err != nil {
		t.Skip("no nobody account")
	}
	uid, _ := strconv.Atoi(nobody.Uid)
	gid, _ := strconv.Atoi(nobody.Gid)

	top := t.TempDir()
	for _, d := range []string{filepath.Dir(top), top} {
		if err := os.Chmod(d, 0o755); err != nil {
			t.Fatal(err)
		}
	}
	dir := filepath.Join(top, "web")
	for _, d := range []string{dir, dir + "/app"} {
		if err := os.Mkdir(d, 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.Chown(d, uid, gid); err != nil {
			t.Fatal(err)
		}
	}
	if err := os.WriteFile(dir+"/app/index.php", []byte("<?php"), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.Chown(dir+"/app/index.php", uid, gid); err != nil {
		t.Fatal(err)
	}
	// As "sudo cp .env .env.bak" leaves it.
	if err := os.WriteFile(dir+"/app/.env.bak", []byte("APP_KEY=secret"), 0o600); err != nil {
		t.Fatal(err)
	}

	h := host.System{}
	m := NewManager(h, database.NewManager(h), laravel.NewManager(h))
	file := filepath.Join(top, "home.tar.gz")
	_, _, warns, err := m.tarAs(context.Background(), host.User{Name: nobody.Username, UID: uid, GID: gid}, "", "example.com", CreateSpec{}, dir, nil, file)
	if err != nil {
		t.Fatalf("tar failed on an unreadable file: %v", err)
	}
	if len(warns) != 1 || !strings.Contains(warns[0], "./app/.env.bak") || !strings.Contains(warns[0], "sahibini "+nobody.Username) {
		t.Fatalf("warnings %q", warns)
	}

	f, err := os.Open(file)
	if err != nil {
		t.Fatal(err)
	}
	defer f.Close()
	gz, err := gzip.NewReader(f)
	if err != nil {
		t.Fatal(err)
	}
	var names []string
	tr := tar.NewReader(gz)
	for {
		hdr, err := tr.Next()
		if err == io.EOF {
			break
		}
		if err != nil {
			t.Fatal(err)
		}
		names = append(names, hdr.Name)
	}
	if !slices.Contains(names, "./app/index.php") || slices.Contains(names, "./app/.env.bak") {
		t.Fatalf("archive holds %v", names)
	}
}
