package backup

import (
	"archive/tar"
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"io"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	"github.com/winchester37/servik/agent/internal/database"
	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/laravel"
	"github.com/winchester37/servik/agent/internal/site"
)

const (
	domain = "magazaonline.com.tr"
	user   = "magaza"
	web    = "/home/magaza/web/magazaonline.com.tr"
)

func newTest(t *testing.T) (*Manager, *host.Simulator) {
	t.Helper()
	sim, err := host.NewSimulator(t.TempDir(), "8.4")
	if err != nil {
		t.Fatal(err)
	}
	ctx := context.Background()
	if _, err := site.NewManager(sim, "www-data").Apply(ctx, site.Spec{Domain: domain, User: user, PHPVersion: "8.4"}); err != nil {
		t.Fatal(err)
	}
	db := database.NewSimulated(sim)
	if _, err := db.Install(ctx, database.InstallSpec{Product: database.MySQL}); err != nil {
		t.Fatal(err)
	}
	if _, err := db.CreateDatabase(ctx, database.DatabaseSpec{Server: "mysql", Name: "magaza_shop", Site: domain}); err != nil {
		t.Fatal(err)
	}
	if _, err := db.ApplyUser(ctx, database.UserSpec{Server: "mysql", Name: "magaza_app", Password: "s3cret-password", Grants: []database.Grant{{Database: "magaza_shop", Access: "all"}}}); err != nil {
		t.Fatal(err)
	}
	write(t, sim, web+"/public_html/index.php", "<?php echo 'v1';")
	write(t, sim, web+"/public_html/uploads/logo.png", "PNG1")
	// A mailbox, owned by vmail.
	if err := os.WriteFile(sim.Path("/etc/passwd"), append(must(os.ReadFile(sim.Path("/etc/passwd"))), "vmail:x:5000:5000::/var/vmail:/usr/sbin/nologin\n"...), 0o644); err != nil {
		t.Fatal(err)
	}
	write(t, sim, "/var/vmail/"+domain+"/info/Maildir/cur/1.eml", "Subject: hello")
	return NewManager(sim, db, laravel.NewManager(sim)), sim
}

func must(b []byte, err error) []byte {
	if err != nil {
		panic(err)
	}
	return b
}

func write(t *testing.T, sim *host.Simulator, abs, content string) {
	t.Helper()
	if err := os.MkdirAll(filepath.Dir(sim.Path(abs)), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(sim.Path(abs), []byte(content), 0o644); err != nil {
		t.Fatal(err)
	}
}

func read(t *testing.T, sim *host.Simulator, abs string) string {
	t.Helper()
	b, err := os.ReadFile(sim.Path(abs))
	if err != nil {
		t.Fatal(err)
	}
	return string(b)
}

func siteItem() ItemSpec {
	return ItemSpec{
		Name:          domain,
		Site:          &SiteRef{Domain: domain, User: user},
		Databases:     []DatabaseRef{{Server: "mysql", Name: "magaza_shop"}},
		DatabaseUsers: []database.UserRef{{Server: "mysql", Name: "magaza_app"}},
		Mail:          true,
		Meta:          json.RawMessage(`{"domain":"magazaonline.com.tr","php_version":"8.4"}`),
	}
}

func members(t *testing.T, file string) []string {
	t.Helper()
	f, err := os.Open(file)
	if err != nil {
		t.Fatal(err)
	}
	defer f.Close()
	var names []string
	tr := tar.NewReader(f)
	for {
		hdr, err := tr.Next()
		if errors.Is(err, io.EOF) {
			return names
		}
		if err != nil {
			t.Fatal(err)
		}
		names = append(names, hdr.Name)
	}
}

func TestBackupHoldsFilesDatabasesMailAndTheSiteDescription(t *testing.T) {
	m, sim := newTest(t)
	res, err := m.Create(context.Background(), CreateSpec{Key: "k1", Run: "run-1", Scope: "site", Server: "srv", Items: []ItemSpec{siteItem()}})
	if err != nil {
		t.Fatal(err)
	}
	if len(res.Items) != 1 || res.Items[0].Error != "" || !res.Local || res.Remote {
		t.Fatalf("unexpected result %+v", res)
	}
	for _, p := range []string{"files", "databases", "mail"} {
		if res.Items[0].Parts[p] == 0 {
			t.Fatalf("part %s missing: %+v", p, res.Items[0])
		}
	}
	got := members(t, sim.Path(archivePath("k1", domain)))
	want := []string{"manifest.json", "database-users.json", "databases/mysql-magaza_shop.sql.gz", "files.tar.gz", "mail.tar.gz", "site.json"}
	if !slices.Equal(got, want) {
		t.Fatalf("members %v, want %v", got, want)
	}
	// The site's files were archived by its own account, at low priority.
	if !sim.Ran("nice -n 19 ionice -c 3 runuser -u magaza -- tar -czf - --one-file-system --listed-incremental=") {
		t.Fatal("tar did not run as the site's account at low priority")
	}
	if !sim.Ran("nice -n 19 ionice -c 3 runuser -u vmail -- tar") {
		t.Fatal("mail was not archived by vmail")
	}
	in, err := m.Inspect("k1", domain)
	if err != nil {
		t.Fatal(err)
	}
	if in.Manifest.Kind != "site" || in.Manifest.User != user || string(in.Meta) != `{"domain":"magazaonline.com.tr","php_version":"8.4"}` {
		t.Fatalf("unexpected inspection %+v", in)
	}
	log, _ := m.ReadRun("run-1", 0)
	if !strings.Contains(log.Data, "✓ Yedek tamamlandı") {
		t.Fatalf("log: %s", log.Data)
	}
}

func TestRestoreBringsBackFilesAfterTheSiteChanged(t *testing.T) {
	m, sim := newTest(t)
	ctx := context.Background()
	if _, err := m.Create(ctx, CreateSpec{Key: "k1", Scope: "site", Items: []ItemSpec{siteItem()}}); err != nil {
		t.Fatal(err)
	}
	write(t, sim, web+"/public_html/index.php", "<?php echo 'broken';")
	write(t, sim, web+"/public_html/hacked.php", "<?php evil();")
	if err := os.RemoveAll(sim.Path("/var/vmail/" + domain)); err != nil {
		t.Fatal(err)
	}
	res, err := m.Restore(ctx, RestoreSpec{
		Run:           "restore-1",
		Chain:         []ChainRef{{Key: "k1", Name: domain}},
		Parts:         []string{"files", "databases", "mail"},
		Site:          SiteRef{Domain: domain, User: user},
		Databases:     []DatabaseRef{{Server: "mysql", Name: "magaza_shop"}},
		DatabaseUsers: []database.UserSpec{{Server: "mysql", Name: "magaza_app", Grants: []database.Grant{{Database: "magaza_shop", Access: "all"}}}},
	})
	if err != nil {
		t.Fatal(err)
	}
	if !slices.Equal(res.Restored, []string{"files", "databases", "mail"}) {
		t.Fatalf("restored %v (skipped %v)", res.Restored, res.Skipped)
	}
	if got := read(t, sim, web+"/public_html/index.php"); got != "<?php echo 'v1';" {
		t.Fatalf("index.php = %q", got)
	}
	if _, err := os.Stat(sim.Path(web + "/public_html/hacked.php")); !errors.Is(err, os.ErrNotExist) {
		t.Fatal("a file added after the backup survived the restore")
	}
	if got := read(t, sim, "/var/vmail/"+domain+"/info/Maildir/cur/1.eml"); got != "Subject: hello" {
		t.Fatalf("mail = %q", got)
	}
	for _, p := range []string{"/home/magaza/web/." + domain + ".servik-restore", "/home/magaza/web/." + domain + ".servik-old"} {
		if _, err := os.Stat(sim.Path(p)); err == nil {
			t.Fatalf("%s left behind", p)
		}
	}
}

func TestIncrementalBackupsRestoreAsAChain(t *testing.T) {
	m, sim := newTest(t)
	ctx := context.Background()
	full := CreateSpec{Key: "full", Scope: "server", Chain: true, Items: []ItemSpec{{Name: domain, Site: &SiteRef{Domain: domain, User: user}}}}
	if _, err := m.Create(ctx, full); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(sim.Path(stateDir + "/" + domain + ".files.snar")); err != nil {
		t.Fatal("the full backup saved no snapshot")
	}
	write(t, sim, web+"/public_html/new.html", "added on monday")
	if err := os.Remove(sim.Path(web + "/public_html/uploads/logo.png")); err != nil {
		t.Fatal(err)
	}
	incr := full
	incr.Key, incr.Incremental, incr.Base = "monday", true, "full"
	res, err := m.Create(ctx, incr)
	if err != nil {
		t.Fatal(err)
	}
	if !slices.Contains(res.Items[0].Incremental, "files") {
		t.Fatalf("the second backup is not incremental: %+v", res.Items[0])
	}
	// A manual full backup does not move the chain on.
	snar := read(t, sim, stateDir+"/"+domain+".files.snar")
	if _, err := m.Create(ctx, CreateSpec{Key: "manual", Scope: "site", Items: full.Items}); err != nil {
		t.Fatal(err)
	}
	if read(t, sim, stateDir+"/"+domain+".files.snar") != snar {
		t.Fatal("a manual backup changed the incremental state")
	}

	if err := os.RemoveAll(sim.Path(web + "/public_html")); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Restore(ctx, RestoreSpec{Chain: []ChainRef{{Key: "full", Name: domain}, {Key: "monday", Name: domain}}, Parts: []string{"files"}, Site: SiteRef{Domain: domain, User: user}}); err != nil {
		t.Fatal(err)
	}
	if got := read(t, sim, web+"/public_html/new.html"); got != "added on monday" {
		t.Fatalf("new.html = %q", got)
	}
	if _, err := os.Stat(sim.Path(web + "/public_html/uploads/logo.png")); !errors.Is(err, os.ErrNotExist) {
		t.Fatal("a file deleted before the incremental came back")
	}
	if got := read(t, sim, web+"/public_html/index.php"); got != "<?php echo 'v1';" {
		t.Fatalf("index.php = %q", got)
	}
	// Restoring only the incremental is refused: its full is needed.
	if _, err := m.Restore(ctx, RestoreSpec{Chain: []ChainRef{{Key: "monday", Name: domain}}, Parts: []string{"files"}, Site: SiteRef{Domain: domain, User: user}}); err == nil || !strings.Contains(err.Error(), "no full backup") {
		t.Fatalf("err = %v", err)
	}
}

func TestRemoteCopiesAreEncryptedAndFetchedBack(t *testing.T) {
	m, sim := newTest(t)
	ctx := context.Background()
	target := &TargetSpec{Type: "s3", Bucket: "servik-yedek", AccessKey: "AK", SecretKey: "SK", Prefix: "servik/srv-ist-01"}
	res, err := m.Create(ctx, CreateSpec{Key: "k1", Scope: "server", Server: "srv-ist-01", Items: []ItemSpec{siteItem(), {Name: "panel"}}, Target: target, Passphrase: "correct horse battery"})
	if err != nil {
		t.Fatal(err)
	}
	if !res.Remote || res.Local || !res.Encrypted || res.RemoteError != "" {
		t.Fatalf("unexpected result %+v", res)
	}
	remote := sim.Path("/var/lib/servik-sim/remote/s3/servik-yedek/servik/srv-ist-01/k1")
	enc, err := os.ReadFile(remote + "/" + domain + ".tar.enc")
	if err != nil {
		t.Fatal(err)
	}
	if !isEncrypted(enc) || bytes.Contains(enc, []byte("manifest.json")) {
		t.Fatal("the remote copy is not encrypted")
	}
	if _, err := os.Stat(sim.Path(setDir("k1"))); !errors.Is(err, os.ErrNotExist) {
		t.Fatal("the local copy was kept")
	}
	// Another server finds it.
	found, err := m.Scan(ctx, &TargetSpec{Type: "s3", Bucket: "servik-yedek", AccessKey: "AK", SecretKey: "SK", Prefix: "servik"})
	if err != nil {
		t.Fatal(err)
	}
	if len(found) != 1 || found[0].Path != "srv-ist-01" || found[0].Descriptor.Key != "k1" || !found[0].Encrypted || len(found[0].Descriptor.Items) != 2 {
		t.Fatalf("found %+v", found)
	}
	if _, err := m.Fetch(ctx, FetchSpec{Key: "k1", Names: []string{domain}, Target: *target, Passphrase: "wrong passphrase!"}); !errors.Is(err, ErrWrongPassphrase) {
		t.Fatalf("err = %v", err)
	}
	if _, err := m.Fetch(ctx, FetchSpec{Key: "k1", Names: []string{domain}, Target: *target, Passphrase: "correct horse battery"}); err != nil {
		t.Fatal(err)
	}
	if in, err := m.Inspect("k1", domain); err != nil || in.Manifest.Name != domain {
		t.Fatalf("inspect after fetch: %+v %v", in, err)
	}
	if _, err := m.Delete(ctx, DeleteSpec{Key: "k1", Local: true, Target: target}); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(remote); !errors.Is(err, os.ErrNotExist) {
		t.Fatal("the remote copy was not deleted")
	}
}

func TestUploadedArchivesAreImported(t *testing.T) {
	m, sim := newTest(t)
	ctx := context.Background()
	if _, err := m.Create(ctx, CreateSpec{Key: "k1", Scope: "site", Items: []ItemSpec{siteItem()}}); err != nil {
		t.Fatal(err)
	}
	data := must(os.ReadFile(sim.Path(archivePath("k1", domain))))
	for off := 0; off < len(data); off += 1000 {
		end := min(off+1000, len(data))
		if _, err := m.UploadWrite("up1", int64(off), data[off:end]); err != nil {
			t.Fatal(err)
		}
	}
	if _, err := m.UploadWrite("up1", 5, []byte("x")); err == nil {
		t.Fatal("a write at the wrong offset was accepted")
	}
	in, err := m.Import(ctx, ImportSpec{Upload: "up1", Key: "imported"})
	if err != nil {
		t.Fatal(err)
	}
	if in.Manifest.Name != domain || in.Manifest.Key != "k1" {
		t.Fatalf("unexpected import %+v", in)
	}
	if _, err := os.Stat(sim.Path(archivePath("imported", domain))); err != nil {
		t.Fatal(err)
	}
	// A file that is not a backup is refused and left where it was.
	write(t, sim, ImportDir+"/notes.tar", "not a tar")
	if _, err := m.Import(ctx, ImportSpec{File: "notes.tar", Key: "bad"}); err == nil {
		t.Fatal("a broken archive was imported")
	}
	if _, err := os.Stat(sim.Path(ImportDir + "/notes.tar")); err != nil {
		t.Fatal("the refused file was moved")
	}
}

func TestTargetTestWritesReadsAndRemovesAFile(t *testing.T) {
	m, sim := newTest(t)
	res, err := m.TestTarget(context.Background(), TargetSpec{Type: "sftp", Host: "yedek.atlasyayin.com", User: "yedek", Prefix: "srv-ist-01"})
	if err != nil {
		t.Fatal(err)
	}
	if !res.OK {
		t.Fatalf("%+v", res)
	}
	entries, _ := os.ReadDir(sim.Path("/var/lib/servik-sim/remote/sftp/yedek.atlasyayin.com/srv-ist-01"))
	if len(entries) != 0 {
		t.Fatalf("the test left %d files", len(entries))
	}
	st, err := m.Status()
	if err != nil {
		t.Fatal(err)
	}
	if !strings.HasPrefix(st.PublicKey, "ssh-ed25519 ") || st.Path != Dir {
		t.Fatalf("status %+v", st)
	}
	if _, err := m.TestTarget(context.Background(), TargetSpec{Type: "s3", Bucket: "Bad_Bucket", AccessKey: "a", SecretKey: "b"}); err == nil {
		t.Fatal("an invalid bucket was accepted")
	}
}

func TestOneBackupAtATime(t *testing.T) {
	m, _ := newTest(t)
	m.slot <- struct{}{}
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	if _, err := m.Create(ctx, CreateSpec{Key: "k1", Scope: "site", Items: []ItemSpec{siteItem()}}); !errors.Is(err, ErrBusy) {
		t.Fatalf("err = %v", err)
	}
}

func TestEncryptionRoundTripAndTampering(t *testing.T) {
	for _, size := range []int{0, 10, encChunk, encChunk*2 + 17} {
		plain := bytes.Repeat([]byte("servik"), size/6+1)[:size]
		var buf bytes.Buffer
		w, err := newEncryptWriter(&buf, "a long passphrase")
		if err != nil {
			t.Fatal(err)
		}
		if _, err := w.Write(plain); err != nil {
			t.Fatal(err)
		}
		if err := w.Close(); err != nil {
			t.Fatal(err)
		}
		sealed := buf.Bytes()
		r, err := newDecryptReader(bytes.NewReader(sealed), "a long passphrase")
		if err != nil {
			t.Fatal(err)
		}
		got, err := io.ReadAll(r)
		if err != nil || !bytes.Equal(got, plain) {
			t.Fatalf("size %d: round trip failed: %v", size, err)
		}
		// Cut off or changed copies do not open.
		for _, bad := range [][]byte{sealed[:len(sealed)-1], append(append([]byte(nil), sealed...), 0), flip(sealed, len(sealed)/2+encHeaderLen/2)} {
			r, err := newDecryptReader(bytes.NewReader(bad), "a long passphrase")
			if err == nil {
				_, err = io.ReadAll(r)
			}
			if err == nil {
				t.Fatalf("size %d: a damaged copy opened", size)
			}
		}
		r, _ = newDecryptReader(bytes.NewReader(sealed), "another passphrase")
		if _, err := io.ReadAll(r); !errors.Is(err, ErrWrongPassphrase) {
			t.Fatalf("wrong passphrase: %v", err)
		}
	}
}

func flip(b []byte, i int) []byte {
	out := append([]byte(nil), b...)
	if i >= len(out) {
		i = len(out) - 1
	}
	out[i] ^= 1
	return out
}
