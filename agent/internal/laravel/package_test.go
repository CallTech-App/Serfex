package laravel

import (
	"archive/zip"
	"bytes"
	"context"
	"errors"
	"os"
	"strings"
	"testing"

	"github.com/calltech-app/serfex/agent/internal/host"
)

const pkgDir = "/home/hizlikargo/web/api.hizlikargo.app/app"

func zipOf(t *testing.T, files map[string]string) []byte {
	t.Helper()
	var buf bytes.Buffer
	zw := zip.NewWriter(&buf)
	for name, body := range files {
		w, err := zw.Create(name)
		if err != nil {
			t.Fatal(err)
		}
		if _, err := w.Write([]byte(body)); err != nil {
			t.Fatal(err)
		}
	}
	if err := zw.Close(); err != nil {
		t.Fatal(err)
	}
	return buf.Bytes()
}

func pkgSpec() PackageSpec {
	return PackageSpec{
		App:   App{Target: target, Path: "app"},
		Run:   "install-1",
		URL:   "https://api.github.com/repos/acme/shop/releases/assets/1",
		Env:   map[string]string{"APP_URL": "https://api.hizlikargo.app"},
		Files: []PackageFile{{Path: "storage/app/license.json", Content: []byte(`{"key":"x"}`)}},
	}
}

func TestInstallPackageUnpacksIntoThePlainDirectory(t *testing.T) {
	m, sim := newTest(t)
	ctx := context.Background()
	var gotHeaders map[string]string
	m.Fetch = func(_ context.Context, url string, h map[string]string, max int64) ([]byte, error) {
		gotHeaders = h
		return zipOf(t, map[string]string{
			"shop-2.4.0/artisan":          "#!/usr/bin/env php\n<?php\n",
			"shop-2.4.0/VERSION":          "2.4.0\n",
			"shop-2.4.0/.env.example":     "APP_NAME=Shop\nAPP_URL=http://localhost\n",
			"shop-2.4.0/public/index.php": "<?php\n",
		}), nil
	}
	spec := pkgSpec()
	spec.Headers = map[string]string{"Authorization": "Bearer t", "Accept": "application/octet-stream"}
	res, err := m.InstallPackage(ctx, spec)
	if err != nil {
		t.Fatal(err)
	}
	if res.Version != "2.4.0" || res.Files != 4 || len(res.SHA256) != 64 {
		t.Fatalf("result %+v", res)
	}
	if gotHeaders["Authorization"] != "Bearer t" {
		t.Fatalf("headers %v", gotHeaders)
	}
	if !exists(sim, pkgDir+"/artisan") || !exists(sim, pkgDir+"/public/index.php") || exists(sim, pkgDir+"/shop-2.4.0") {
		t.Fatal("the package was not unpacked at the top of app/")
	}
	if env := read(t, sim, pkgDir+"/.env"); !strings.Contains(env, "APP_NAME=Shop") || !strings.Contains(env, "APP_URL=https://api.hizlikargo.app") {
		t.Fatalf(".env = %q", env)
	}
	if got := read(t, sim, pkgDir+"/storage/app/license.json"); got != `{"key":"x"}` {
		t.Fatalf("license = %q", got)
	}
	if !strings.Contains(commands(sim), "tar -x -f - -C "+pkgDir+" --no-same-owner") {
		t.Fatal("not unpacked as the site's account")
	}
	if v, _ := m.Version(ctx, VersionSpec{App: spec.App}); v["version"] != "2.4.0" {
		t.Fatalf("version %v", v)
	}

	// The directory is no longer empty.
	if _, err := m.InstallPackage(ctx, spec); err == nil || !strings.Contains(err.Error(), "not empty") {
		t.Fatalf("second install: %v", err)
	}
	// A retry replaces it.
	spec.Run, spec.Replace = "install-2", true
	if res, err := m.InstallPackage(ctx, spec); err != nil || res.Version != "2.4.0" {
		t.Fatalf("replace = %+v %v", res, err)
	}
}

func TestInstallPackageChecksAndCleansUp(t *testing.T) {
	m, sim := newTest(t)
	ctx := context.Background()
	m.Fetch = func(context.Context, string, map[string]string, int64) ([]byte, error) {
		return zipOf(t, map[string]string{"README.md": "hi", "public/index.php": "<?php"}), nil
	}
	if _, err := m.InstallPackage(ctx, pkgSpec()); err == nil || !strings.Contains(err.Error(), "artisan") {
		t.Fatalf("err = %v", err)
	}
	if exists(sim, pkgDir) {
		t.Fatal("a failed install left files behind")
	}
	log, _ := m.ReadRun(target.Domain, "install-1", 0)
	if !strings.Contains(log.Data, "✗") {
		t.Fatalf("log = %q", log.Data)
	}

	spec := pkgSpec()
	spec.SHA256 = strings.Repeat("0", 64)
	if _, err := m.InstallPackage(ctx, spec); err == nil || !strings.Contains(err.Error(), "SHA-256") {
		t.Fatalf("checksum: %v", err)
	}

	m.Fetch = func(context.Context, string, map[string]string, int64) ([]byte, error) {
		return nil, errors.New("downloading the package: HTTP 404")
	}
	if _, err := m.InstallPackage(ctx, pkgSpec()); err == nil || !strings.Contains(err.Error(), "404") {
		t.Fatalf("download: %v", err)
	}
}

func TestPackageValidation(t *testing.T) {
	m, _ := newTest(t)
	for name, change := range map[string]func(*PackageSpec){
		"http":       func(s *PackageSpec) { s.URL = "http://example.com/a.zip" },
		"userinfo":   func(s *PackageSpec) { s.URL = "https://u:p@example.com/a.zip" },
		"header":     func(s *PackageSpec) { s.Headers = map[string]string{"Cookie": "a"} },
		"crlf":       func(s *PackageSpec) { s.Headers = map[string]string{"Accept": "a\r\nX: b"} },
		"sha":        func(s *PackageSpec) { s.SHA256 = "abc" },
		"file":       func(s *PackageSpec) { s.Files = []PackageFile{{Path: "../x"}} },
		"abs":        func(s *PackageSpec) { s.Files = []PackageFile{{Path: "/etc/passwd"}} },
		"env":        func(s *PackageSpec) { s.Env = map[string]string{"A B": "x"} },
		"versionDir": func(s *PackageSpec) { s.VersionFile = "../VERSION" },
		"path":       func(s *PackageSpec) { s.Path = "../other" },
	} {
		s := pkgSpec()
		change(&s)
		if _, err := m.InstallPackage(context.Background(), s); err == nil {
			t.Errorf("%s: accepted", name)
		}
	}
}

func TestZipToTarRefusesUnsafeEntries(t *testing.T) {
	for name, files := range map[string]map[string]string{
		"dotdot":   {"artisan": "", "../evil": "x"},
		"absolute": {"artisan": "", "/etc/evil": "x"},
		"nested":   {"a/artisan": "", "a/../../evil": "x"},
	} {
		if _, _, err := zipToTar(zipOf(t, files)); err == nil {
			t.Errorf("%s: accepted", name)
		}
	}
	if _, _, err := zipToTar([]byte("not a zip")); err == nil {
		t.Error("garbage accepted")
	}

	// A symlink entry.
	var buf bytes.Buffer
	zw := zip.NewWriter(&buf)
	h := &zip.FileHeader{Name: "link"}
	h.SetMode(os.ModeSymlink | 0o777)
	w, _ := zw.CreateHeader(h)
	_, _ = w.Write([]byte("/etc/passwd"))
	_ = zw.Close()
	if _, _, err := zipToTar(buf.Bytes()); err == nil || !strings.Contains(err.Error(), "link") {
		t.Errorf("symlink: %v", err)
	}

	// Files at the top keep their paths; one shared top directory is dropped.
	if top := commonTop(mustZip(t, map[string]string{"artisan": "", "app/x.php": ""})); top != "" {
		t.Errorf("top = %q", top)
	}
	if top := commonTop(mustZip(t, map[string]string{"shop/artisan": "", "shop/app/x.php": ""})); top != "shop/" {
		t.Errorf("top = %q", top)
	}
}

func mustZip(t *testing.T, files map[string]string) []*zip.File {
	data := zipOf(t, files)
	zr, err := zip.NewReader(bytes.NewReader(data), int64(len(data)))
	if err != nil {
		t.Fatal(err)
	}
	return zr.File
}

func TestSetupRunsCommandsAndHidesSecrets(t *testing.T) {
	m, sim := newTest(t)
	ctx := context.Background()
	if _, err := m.InstallPackage(ctx, pkgSpec()); err != nil {
		t.Fatal(err)
	}
	app := App{Target: target, Path: "app"}
	password := "Gizli-Parola-123456"
	res, err := m.Setup(ctx, SetupSpec{App: app, Run: "install-1", Redact: []string{password}, Commands: []SetupCommand{
		{Tool: "artisan", Args: []string{"shop:install", "--admin-password=" + password}},
		{Tool: "artisan", Args: []string{"migrate", "--force"}},
	}})
	if err != nil || res.Failed != -1 {
		t.Fatalf("setup = %+v %v", res, err)
	}
	log, _ := m.ReadRun(target.Domain, "install-1", 0)
	if strings.Contains(log.Data, password) || !strings.Contains(log.Data, "--admin-password=••••••") || !strings.Contains(log.Data, "Nothing to migrate") {
		t.Fatalf("log = %q", log.Data)
	}

	// The first failing command stops the rest.
	sim.Fail = func(c host.Command) error {
		if strings.Contains(c.String(), "shop:install") {
			return errors.New("SQLSTATE: access denied for " + password)
		}
		return nil
	}
	res, err = m.Setup(ctx, SetupSpec{App: app, Run: "install-2", Redact: []string{password}, Commands: []SetupCommand{
		{Tool: "artisan", Args: []string{"shop:install"}},
		{Tool: "artisan", Args: []string{"migrate", "--force"}},
	}})
	if err != nil || res.Failed != 0 || res.ExitCode == 0 {
		t.Fatalf("setup = %+v %v", res, err)
	}
	log, _ = m.ReadRun(target.Domain, "install-2", 0)
	if strings.Contains(log.Data, password) || strings.Contains(log.Data, "migrate") {
		t.Fatalf("log = %q", log.Data)
	}

	if _, err := m.Setup(ctx, SetupSpec{App: app, Run: "x", Commands: []SetupCommand{{Tool: "bash", Args: []string{"-c", "id"}}}}); err == nil {
		t.Error("an arbitrary tool was accepted")
	}
}
