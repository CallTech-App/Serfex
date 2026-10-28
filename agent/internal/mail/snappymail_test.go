package mail

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"os"
	"strings"
	"testing"
)

func TestSnappyMailSetup(t *testing.T) {
	m, sim := newTest(t)
	ctx := context.Background()
	res, err := m.Setup(ctx, SetupSpec{Hostname: "mail.example.net", HourlyLimit: 500, Webmail: true, WebmailApp: SnappyMail})
	if err != nil {
		t.Fatal(err)
	}
	v := SnappyMailRelease.Version
	if res.WebmailApp != SnappyMail || res.WebmailVersion != v || res.Webmail != "https://mail.example.net/" {
		t.Fatalf("result = %+v", res)
	}
	if sim.Ran("roundcube-core") || sim.Ran("updatedb.sh") {
		t.Error("Roundcube was installed for SnappyMail")
	}
	root := snappyMailRoot(v)
	contains(t, "vhost", read(t, sim, mailVhost), "root "+root+";", "location = /serfex-sso.php",
		"fastcgi_param SCRIPT_FILENAME /var/lib/serfex/webmail/snappymail-sso.php", "location ^~ /snappymail/", "(static|themes)")
	if strings.Contains(read(t, sim, mailVhost), "roundcube") {
		t.Error("the vhost still points at Roundcube")
	}
	pool := read(t, sim, webmailPool("8.3"))
	contains(t, "pool", pool, "open_basedir] = "+root+"/:/var/lib/serfex/webmail/:/var/log/serfex/webmail/")
	if strings.Contains(pool, "ROUNDCUBE") {
		t.Error("the pool still sets Roundcube's configuration")
	}
	contains(t, "include.php", read(t, sim, root+"/include.php"), "define('APP_DATA_FOLDER_PATH', '/var/lib/serfex/webmail/snappymail/');")
	contains(t, "application.ini", read(t, sim, snappyMailPrivate+"/configs/application.ini"),
		"allow_admin_panel = Off", "[plugins]\nenable = On", `path = "/var/log/serfex/webmail/"`, `type = "sqlite"`)
	contains(t, "domain", read(t, sim, snappyMailPrivate+"/domains/default.json"), `"port": 587`, `"type": 2`, `"useAuth": true`, `"port": 4190`)
	if _, err := os.Stat(sim.Path(snappyMailPrivate + "/domains/disabled")); err != nil {
		t.Error("SnappyMail would add its sample domains")
	}
	contains(t, "sso", read(t, sim, snappyMailSSO), "/var/lib/serfex/webmail/data/sso/", "'*serfex-webmail'", root+"/index.php")

	st := m.Status(ctx)
	if st.WebmailApp != SnappyMail || st.WebmailVersion != v || st.WebmailLatest != "" {
		t.Fatalf("status = %+v", st)
	}

	if _, err := m.ApplyDomain(ctx, domainSpec()); err != nil {
		t.Fatal(err)
	}
	login, err := m.WebmailLogin("info@example.com")
	if err != nil {
		t.Fatal(err)
	}
	token, ok := strings.CutPrefix(login.URL, "https://mail.example.net/serfex-sso.php?token=")
	if !ok || len(token) != 64 {
		t.Fatalf("url = %s", login.URL)
	}
	contains(t, "token", read(t, sim, webmailSSO+"/"+token+".json"), `"user":"info@example.com"`)

	// Back to Roundcube: SnappyMail's login goes, its data stays.
	if _, err := m.Setup(ctx, SetupSpec{Hostname: "mail.example.net", HourlyLimit: 500, Webmail: true}); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(sim.Path(snappyMailSSO)); !os.IsNotExist(err) {
		t.Error("SnappyMail's login script is still there")
	}
	if _, err := os.Stat(sim.Path(snappyMailPrivate + "/configs/application.ini")); err != nil {
		t.Error("SnappyMail's data was removed")
	}
	contains(t, "vhost", read(t, sim, mailVhost), "root /var/lib/roundcube/public_html;")
	if st := m.Status(ctx); st.WebmailApp != Roundcube || st.WebmailVersion != "" {
		t.Fatalf("status = %+v", st)
	}
}

func TestSnappyMailUpdateKeepsOneRelease(t *testing.T) {
	m, sim := newTest(t)
	ctx := context.Background()
	spec := SetupSpec{Hostname: "mail.example.net", Webmail: true, WebmailApp: SnappyMail}
	old := SnappyMailRelease
	t.Cleanup(func() { SnappyMailRelease = old })

	for _, v := range []string{"2.30.0", "2.31.0"} {
		SnappyMailRelease.Version = v
		if _, err := m.Setup(ctx, spec); err != nil {
			t.Fatal(err)
		}
	}
	// A newer agent brings 2.32.0.
	SnappyMailRelease.Version = "2.32.0"
	if st := m.Status(ctx); st.WebmailVersion != "2.31.0" || st.WebmailLatest != "2.32.0" {
		t.Fatalf("status = %+v", st)
	}
	if _, err := m.Setup(ctx, spec); err != nil {
		t.Fatal(err)
	}
	entries, _ := os.ReadDir(sim.Path(snappyMailDir))
	var got []string
	for _, e := range entries {
		got = append(got, e.Name())
	}
	if strings.Join(got, " ") != "2.31.0 2.32.0" {
		t.Fatalf("releases = %v, want the one in use and the one before", got)
	}
	contains(t, "vhost", read(t, sim, mailVhost), "root /opt/serfex/snappymail/2.32.0;")
}

func TestSnappyMailDownloadIsChecked(t *testing.T) {
	m, sim := newTest(t)
	m.sim = false
	old := SnappyMailRelease
	t.Cleanup(func() { SnappyMailRelease = old })
	SnappyMailRelease.Version = "2.38.2"

	release := tarball(t, map[string]string{
		"index.php":                        "<?php",
		"snappymail/v/2.38.2/include.php":  "<?php",
		"snappymail/v/2.38.2/static/a.css": "a{}",
	})
	var asked string
	m.Download = func(_ context.Context, url string) ([]byte, error) { asked = url; return release, nil }

	SnappyMailRelease.SHA256 = strings.Repeat("0", 64)
	if _, err := m.ensureSnappyMail(context.Background()); err == nil || !strings.Contains(err.Error(), "checksum") {
		t.Fatalf("a download with the wrong checksum: err = %v", err)
	}
	if _, err := os.Stat(sim.Path(snappyMailRoot("2.38.2"))); !os.IsNotExist(err) {
		t.Fatal("a download with the wrong checksum was installed")
	}

	sum := sha256.Sum256(release)
	SnappyMailRelease.SHA256 = hex.EncodeToString(sum[:])
	if _, err := m.ensureSnappyMail(context.Background()); err != nil {
		t.Fatal(err)
	}
	if asked != "https://github.com/the-djmaze/snappymail/releases/download/v2.38.2/snappymail-2.38.2.tar.gz" {
		t.Errorf("downloaded %s", asked)
	}
	if read(t, sim, snappyMailRoot("2.38.2")+"/snappymail/v/2.38.2/static/a.css") != "a{}" {
		t.Error("the release was not unpacked")
	}

	for name, entries := range map[string]map[string]string{
		"escape":  {"../evil.php": "<?php"},
		"missing": {"index.php": "<?php"},
	} {
		SnappyMailRelease.Version = "9.9.9"
		bad := tarball(t, entries)
		sum := sha256.Sum256(bad)
		SnappyMailRelease.SHA256 = hex.EncodeToString(sum[:])
		m.Download = func(context.Context, string) ([]byte, error) { return bad, nil }
		if _, err := m.ensureSnappyMail(context.Background()); err == nil {
			t.Errorf("%s: a bad release was accepted", name)
		}
		if _, err := os.Stat(sim.Path(snappyMailRoot("9.9.9"))); !os.IsNotExist(err) {
			t.Errorf("%s: a bad release was installed", name)
		}
	}
}

func tarball(t *testing.T, files map[string]string) []byte {
	t.Helper()
	var buf bytes.Buffer
	gz := gzip.NewWriter(&buf)
	tw := tar.NewWriter(gz)
	for name, body := range files {
		if err := tw.WriteHeader(&tar.Header{Name: name, Mode: 0o644, Size: int64(len(body)), Typeflag: tar.TypeReg}); err != nil {
			t.Fatal(err)
		}
		if _, err := tw.Write([]byte(body)); err != nil {
			t.Fatal(err)
		}
	}
	if err := tw.Close(); err != nil {
		t.Fatal(err)
	}
	if err := gz.Close(); err != nil {
		t.Fatal(err)
	}
	return buf.Bytes()
}
