package laravel

import (
	"archive/tar"
	"archive/zip"
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"path"
	"regexp"
	"strings"
	"time"

	"github.com/calltech-app/serfex/agent/internal/i18n"
)

// Applications that update themselves (from their own admin panel, out of
// release packages built with their dependencies) are installed once from
// such a package. Serfex never deploys them again: their own updater
// renames the application directory, so it lives in a plain directory of
// the site, without releases, symlinks or a .git directory.

// PackageSpec installs an application from a release package into an
// empty directory of a site.
type PackageSpec struct {
	App
	// Run names the log the install writes to; the setup commands that
	// follow (laravel.setup) append to the same log.
	Run string `json:"run"`
	// URL is the zip file, over HTTPS.
	URL string `json:"url"`
	// Headers are sent with the download: Authorization (a private GitHub
	// release) and Accept. Redirects to another host drop Authorization.
	Headers map[string]string `json:"headers"`
	// SHA256 of the zip, checked when given.
	SHA256 string `json:"sha256"`
	// Env, when not empty, is written into .env on top of .env.example;
	// otherwise the application's own installer writes .env.
	Env map[string]string `json:"env"`
	// Files are written into the application directory, for example a
	// license file the installer reads.
	Files []PackageFile `json:"files"`
	// VersionFile is read after unpacking (default VERSION).
	VersionFile string `json:"version_file"`
	// Replace removes what an earlier, failed install left in the
	// directory first; otherwise it must be empty.
	Replace bool `json:"replace"`
}

// PackageFile is a file written into the application, mode 0600.
type PackageFile struct {
	Path    string `json:"path"`
	Content []byte `json:"content"`
}

// PackageResult is what was installed.
type PackageResult struct {
	Version string `json:"version"`
	SHA256  string `json:"sha256"`
	Bytes   int64  `json:"bytes"`
	Files   int    `json:"files"`
	Millis  int64  `json:"ms"`
}

// SetupSpec runs an application's one-time setup commands, one after the
// other, until one fails.
type SetupSpec struct {
	App
	Run      string         `json:"run"`
	Commands []SetupCommand `json:"commands"`
	// Redact are values (a password the commands carry) the log shows as
	// ••••••.
	Redact []string `json:"redact"`
}

// SetupCommand is one command: tool as in RunSpec, with its arguments.
type SetupCommand struct {
	Tool string   `json:"tool"`
	Args []string `json:"args"`
}

// SetupResult tells which command failed: -1 when all succeeded.
type SetupResult struct {
	Failed   int   `json:"failed"`
	ExitCode int   `json:"exit_code"`
	Millis   int64 `json:"ms"`
}

// VersionSpec names the file an application keeps its version in.
type VersionSpec struct {
	App
	File string `json:"file"`
}

const (
	maxPackage     = 512 << 20
	maxPackageFile = 256 << 10
	maxCommands    = 20
	redacted       = "••••••"
)

var sha256Re = regexp.MustCompile(`^[0-9a-f]{64}$`)

func (s PackageSpec) validate() error {
	if err := s.App.validate(); err != nil {
		return err
	}
	if err := validateID("run", s.Run); err != nil {
		return err
	}
	u, err := url.Parse(s.URL)
	if err != nil || u.Scheme != "https" || u.Host == "" || u.User != nil || len(s.URL) > 2048 {
		return invalid("url must be an https:// address of the package")
	}
	for k, v := range s.Headers {
		if k != "Authorization" && k != "Accept" {
			return invalid("only the Authorization and Accept headers may be sent")
		}
		if strings.ContainsAny(v, "\r\n") || len(v) > 4096 {
			return invalid("header %s is not valid", k)
		}
	}
	if s.SHA256 != "" && !sha256Re.MatchString(s.SHA256) {
		return invalid("sha256 must be 64 lowercase hexadecimal characters")
	}
	for k := range s.Env {
		if !envKeyRe.MatchString(k) {
			return invalid("env key %q is not a valid name", k)
		}
	}
	if len(s.Files) > 10 {
		return invalid("at most 10 files may be written")
	}
	for _, f := range s.Files {
		if err := validInside(f.Path); err != nil {
			return err
		}
		if len(f.Content) > maxPackageFile {
			return invalid("file %s must be smaller than 256 KB", f.Path)
		}
	}
	if s.VersionFile != "" {
		if err := validInside(s.VersionFile); err != nil {
			return err
		}
	}
	return nil
}

// validInside accepts a relative path inside the application directory.
func validInside(p string) error {
	if p == "" || len(p) > 200 || strings.HasPrefix(p, "/") || strings.ContainsAny(p, "\\\x00\r\n") || path.Clean(p) != p || p == "." || strings.HasPrefix(p, "../") || p == ".." {
		return invalid("path %q must be a relative path inside the application", p)
	}
	return nil
}

func (s SetupSpec) validate() error {
	if err := s.App.validate(); err != nil {
		return err
	}
	if err := validateID("run", s.Run); err != nil {
		return err
	}
	if len(s.Commands) == 0 || len(s.Commands) > maxCommands {
		return invalid("between 1 and %d commands are allowed", maxCommands)
	}
	for _, c := range s.Commands {
		if _, _, err := argv(s.Target, c.Tool, c.Args); err != nil {
			return err
		}
		if len(c.Args) > 64 {
			return invalid("at most 64 arguments are allowed")
		}
		for _, a := range c.Args {
			if len(a) > 4096 || strings.ContainsRune(a, 0) {
				return invalid("arguments must be shorter than 4096 bytes")
			}
		}
	}
	return nil
}

// InstallPackage downloads a release package, checks it and unpacks it as
// the site's account into the application's directory, which must be
// empty. A failure removes what was unpacked.
func (m *Manager) InstallPackage(ctx context.Context, spec PackageSpec) (res PackageResult, err error) {
	if err := spec.validate(); err != nil {
		return PackageResult{}, err
	}
	if spec.VersionFile == "" {
		spec.VersionFile = "VERSION"
	}
	if !m.isDir(siteDir(spec.Target)) {
		return PackageResult{}, invalid("site %s does not exist", spec.Domain)
	}
	dir := spec.dir()
	if entries, err := os.ReadDir(m.h.Path(dir)); err == nil && len(entries) > 0 && !spec.Replace {
		return PackageResult{}, invalid("%s is not empty", spec.Path)
	}
	unlock := m.lock(spec.Domain)
	defer unlock()

	log, err := m.openRun(spec.Domain, spec.Run)
	if err != nil {
		return PackageResult{}, &OperationError{err}
	}
	defer log.Close()
	ctx, cancel := context.WithTimeout(ctx, deployTimeout)
	defer cancel()
	start := time.Now()
	step := func(format string, a ...any) {
		fmt.Fprintf(log, "▸ "+i18n.Translate(ctx, format)+"\n", a...)
	}
	unpacked := false
	defer func() {
		if err != nil {
			fmt.Fprintf(log, "✗ %s\n", err)
			if unpacked {
				fmt.Fprintln(log, "↺ "+i18n.T(ctx, "Açılan dosyalar kaldırılıyor"))
				_, _ = m.userRun(context.WithoutCancel(ctx), spec.Target, "/", "rm", "-rf", "--one-file-system", dir)
			}
			err = &OperationError{err}
		}
	}()

	// PHP's command line, its extensions and Composer, as a deploy has.
	if err := m.ensureTools(ctx, log, spec.PHPVersion, false); err != nil {
		return PackageResult{}, err
	}
	if spec.Replace && m.exists(dir) {
		step("Önceki denemeden kalanlar kaldırılıyor: %s/", spec.Path)
		if _, err := m.userRun(ctx, spec.Target, "/", "rm", "-rf", "--one-file-system", dir); err != nil {
			return PackageResult{}, fmt.Errorf("removing %s failed", dir)
		}
	}
	u, _ := url.Parse(spec.URL)
	step("Paket indiriliyor: %s", u.Host+u.Path)
	data, err := m.Fetch(ctx, spec.URL, spec.Headers, maxPackage)
	if err != nil {
		return PackageResult{}, err
	}
	sum := sha256.Sum256(data)
	res.SHA256, res.Bytes = hex.EncodeToString(sum[:]), int64(len(data))
	if spec.SHA256 != "" && res.SHA256 != spec.SHA256 {
		return PackageResult{}, fmt.Errorf("the package's SHA-256 is %s, expected %s", res.SHA256, spec.SHA256)
	}
	if spec.SHA256 != "" {
		step("%s indirildi, SHA-256 doğrulandı", humanBytes(res.Bytes))
	} else {
		step("%s indirildi (SHA-256 %s)", humanBytes(res.Bytes), res.SHA256[:12])
	}

	archive, files, err := zipToTar(data)
	if err != nil {
		return PackageResult{}, err
	}
	data = nil
	res.Files = files
	step("Açılıyor: %d dosya → %s/", files, spec.Path)
	if _, err := m.userRun(ctx, spec.Target, "/", "mkdir", "-p", dir); err != nil {
		return PackageResult{}, fmt.Errorf("mkdir %s failed", dir)
	}
	unpacked = true
	c := m.cmd(spec.Target, dir, nil, "tar", "-x", "-f", "-", "-C", dir, "--no-same-owner")
	c.Stdin = archive
	if out, err := m.h.Run(ctx, c); err != nil {
		return PackageResult{}, fmt.Errorf("unpacking failed: %s", strings.TrimSpace(out))
	}
	if !m.exists(dir + "/artisan") {
		return PackageResult{}, errors.New("the package has no artisan file at its top: it is not a Laravel application")
	}
	if m.exists(dir + "/.git") {
		return PackageResult{}, errors.New("the package contains a .git directory")
	}

	if len(spec.Env) > 0 {
		content := ""
		if m.exists(dir + "/.env.example") {
			content = m.envContent(ctx, spec.Target, dir+"/.env.example")
			step(".env, .env.example temel alınarak oluşturuldu")
		}
		if err := m.writeFile(ctx, spec.Target, dir+"/.env", []byte(setEnv(content, spec.Env)), "600"); err != nil {
			return PackageResult{}, err
		}
	}
	for _, f := range spec.Files {
		abs := dir + "/" + f.Path
		if _, err := m.userRun(ctx, spec.Target, "/", "mkdir", "-p", dirOf(abs)); err != nil {
			return PackageResult{}, fmt.Errorf("mkdir %s failed", dirOf(abs))
		}
		if err := m.writeFile(ctx, spec.Target, abs, f.Content, "600"); err != nil {
			return PackageResult{}, err
		}
		step("Dosya yazıldı: %s", f.Path)
	}
	res.Version = m.readVersion(ctx, spec.App, spec.VersionFile)
	res.Millis = time.Since(start).Milliseconds()
	if res.Version != "" {
		step("Sürüm %s açıldı", res.Version)
	}
	return res, nil
}

// Setup runs the commands one after the other as the site's account in
// the application's directory, appending to the run log, and stops at
// the first that fails.
func (m *Manager) Setup(ctx context.Context, spec SetupSpec) (SetupResult, error) {
	if err := spec.validate(); err != nil {
		return SetupResult{}, err
	}
	if err := m.requireApp(spec.App); err != nil {
		return SetupResult{}, err
	}
	for _, c := range spec.Commands {
		if c.Tool == "composer" && !m.exists(composerPhar()) {
			return SetupResult{}, invalid("Composer is not installed yet; deploy the application once")
		}
	}
	if err := m.writeShims(spec.PHPVersion); err != nil {
		return SetupResult{}, &OperationError{err}
	}
	unlock := m.lock(spec.Domain)
	defer unlock()
	f, err := m.openRun(spec.Domain, spec.Run)
	if err != nil {
		return SetupResult{}, &OperationError{err}
	}
	defer f.Close()
	log := &redactor{w: f, secrets: spec.Redact}
	start := time.Now()
	res := SetupResult{Failed: -1}
	fmt.Fprintf(log, "▸ %s\n", i18n.T(ctx, "Kurulum adımları çalışıyor"))
	for i, c := range spec.Commands {
		name, args, _ := argv(spec.Target, c.Tool, c.Args)
		fmt.Fprintf(log, "$ %s %s\n", display(c.Tool), strings.Join(quoteArgs(c.Args), " "))
		cctx, cancel := context.WithTimeout(ctx, runTimeout)
		_, err := m.h.Run(cctx, m.cmd(spec.Target, spec.dir(), log, name, args...))
		timedOut := errors.Is(cctx.Err(), context.DeadlineExceeded)
		cancel()
		if code := host0(err); code != 0 || timedOut {
			if timedOut {
				fmt.Fprintf(log, "✗ %s\n", i18n.T(ctx, "%d dakika sınırı aşıldı, komut durduruldu.", int(runTimeout.Minutes())))
			} else {
				fmt.Fprintf(log, "✗ %s\n", i18n.T(ctx, "Çıkış kodu %d", code))
			}
			res.Failed, res.ExitCode = i, code
			break
		}
	}
	res.Millis = time.Since(start).Milliseconds()
	if res.Failed < 0 {
		fmt.Fprintf(log, "✓ %s\n", i18n.T(ctx, "Kurulum adımları tamamlandı. Toplam %s.", humanDuration(ctx, time.Since(start))))
	}
	return res, nil
}

// Version reads the version an application keeps in a file.
func (m *Manager) Version(ctx context.Context, spec VersionSpec) (map[string]string, error) {
	if err := spec.App.validate(); err != nil {
		return nil, err
	}
	if spec.File == "" {
		spec.File = "VERSION"
	}
	if err := validInside(spec.File); err != nil {
		return nil, err
	}
	return map[string]string{"version": m.readVersion(ctx, spec.App, spec.File)}, nil
}

var versionRe = regexp.MustCompile(`^v?[0-9A-Za-z][0-9A-Za-z.+_-]{0,39}$`)

func (m *Manager) readVersion(ctx context.Context, a App, file string) string {
	abs := a.dir() + "/" + file
	if !m.exists(abs) {
		return ""
	}
	b, err := m.readFile(ctx, a.Target, abs, 256)
	if err != nil {
		return ""
	}
	v := strings.TrimSpace(string(b))
	if !versionRe.MatchString(v) {
		return ""
	}
	return v
}

// redactor writes to w with every secret replaced.
type redactor struct {
	w       io.Writer
	secrets []string
}

func (r *redactor) Write(p []byte) (int, error) {
	out := p
	for _, s := range r.secrets {
		if len(s) >= 4 && bytes.Contains(out, []byte(s)) {
			out = bytes.ReplaceAll(out, []byte(s), []byte(redacted))
		}
	}
	if _, err := r.w.Write(out); err != nil {
		return 0, err
	}
	return len(p), nil
}

// zipToTar turns a release zip into a tar stream the site's account
// unpacks. Absolute paths, "..", links and special files are refused. A
// zip whose every entry sits in one top directory (GitHub's source
// archives) loses that directory.
func zipToTar(data []byte) ([]byte, int, error) {
	zr, err := zip.NewReader(bytes.NewReader(data), int64(len(data)))
	if err != nil {
		return nil, 0, errors.New("the package is not a zip file")
	}
	prefix := commonTop(zr.File)
	var buf bytes.Buffer
	tw := tar.NewWriter(&buf)
	var total int64
	files := 0
	for _, f := range zr.File {
		name := strings.TrimPrefix(f.Name, prefix)
		if name == "" || name == "/" {
			continue
		}
		clean := path.Clean(strings.TrimSuffix(name, "/"))
		if strings.HasPrefix(name, "/") || strings.Contains(name, "\\") || clean == ".." || strings.HasPrefix(clean, "../") || clean == "." {
			return nil, 0, fmt.Errorf("the package has an unsafe path: %s", f.Name)
		}
		mode := f.Mode()
		switch {
		case mode.IsDir():
			if err := tw.WriteHeader(&tar.Header{Typeflag: tar.TypeDir, Name: clean + "/", Mode: 0o755, ModTime: f.Modified}); err != nil {
				return nil, 0, err
			}
		case mode.IsRegular():
			total += int64(f.UncompressedSize64)
			if total > 2*maxPackage {
				return nil, 0, errors.New("the package unpacks to more than 1 GB")
			}
			perm := int64(0o644)
			if mode.Perm()&0o111 != 0 {
				perm = 0o755
			}
			if err := tw.WriteHeader(&tar.Header{Typeflag: tar.TypeReg, Name: clean, Mode: perm, Size: int64(f.UncompressedSize64), ModTime: f.Modified}); err != nil {
				return nil, 0, err
			}
			rc, err := f.Open()
			if err != nil {
				return nil, 0, fmt.Errorf("reading %s from the package: %w", f.Name, err)
			}
			n, err := io.Copy(tw, io.LimitReader(rc, int64(f.UncompressedSize64)+1))
			rc.Close()
			if err != nil || n != int64(f.UncompressedSize64) {
				return nil, 0, fmt.Errorf("reading %s from the package failed", f.Name)
			}
			files++
		default:
			return nil, 0, fmt.Errorf("the package contains a link or special file: %s", f.Name)
		}
	}
	if err := tw.Close(); err != nil {
		return nil, 0, err
	}
	if files == 0 {
		return nil, 0, errors.New("the package is empty")
	}
	return buf.Bytes(), files, nil
}

// commonTop is the one top directory every entry shares ("app-1.2.3/"),
// or "" when the files sit at the top or under several directories.
func commonTop(files []*zip.File) string {
	top := ""
	for _, f := range files {
		i := strings.IndexByte(f.Name, '/')
		if i <= 0 {
			return ""
		}
		if top == "" {
			top = f.Name[:i+1]
		} else if f.Name[:i+1] != top {
			return ""
		}
	}
	return top
}

// fetch downloads url with the given headers, up to max bytes. Go drops
// Authorization when a redirect leaves the host (GitHub sends release
// assets from another host).
func fetch(ctx context.Context, rawURL string, headers map[string]string, max int64) ([]byte, error) {
	ctx, cancel := context.WithTimeout(ctx, 15*time.Minute)
	defer cancel()
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, rawURL, nil)
	if err != nil {
		return nil, err
	}
	req.Header.Set("User-Agent", "serfex-agent")
	for k, v := range headers {
		req.Header.Set(k, v)
	}
	client := &http.Client{CheckRedirect: func(r *http.Request, via []*http.Request) error {
		if len(via) >= 5 {
			return errors.New("too many redirects")
		}
		if r.URL.Scheme != "https" {
			return errors.New("redirected to a non-https address")
		}
		return nil
	}}
	resp, err := client.Do(req)
	if err != nil {
		return nil, fmt.Errorf("downloading the package: %w", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("downloading the package: HTTP %d", resp.StatusCode)
	}
	data, err := io.ReadAll(io.LimitReader(resp.Body, max+1))
	if err != nil {
		return nil, fmt.Errorf("downloading the package: %w", err)
	}
	if int64(len(data)) > max {
		return nil, fmt.Errorf("the package is larger than %s", humanBytes(max))
	}
	return data, nil
}

func humanBytes(n int64) string {
	switch {
	case n >= 1<<30:
		return fmt.Sprintf("%.1f GB", float64(n)/(1<<30))
	case n >= 1<<20:
		return fmt.Sprintf("%.1f MB", float64(n)/(1<<20))
	case n >= 1<<10:
		return fmt.Sprintf("%.1f KB", float64(n)/(1<<10))
	}
	return fmt.Sprintf("%d B", n)
}

// demoPackage is the release package a simulator downloads.
func demoPackage() []byte {
	var buf bytes.Buffer
	zw := zip.NewWriter(&buf)
	for name, body := range map[string]string{
		"demo-1.0.0/artisan":          "#!/usr/bin/env php\n<?php\n",
		"demo-1.0.0/VERSION":          "1.0.0\n",
		"demo-1.0.0/.env.example":     "APP_NAME=Demo\nAPP_ENV=production\nAPP_KEY=\n",
		"demo-1.0.0/public/index.php": "<?php\n",
	} {
		w, _ := zw.Create(name)
		_, _ = io.WriteString(w, body)
	}
	_ = zw.Close()
	return buf.Bytes()
}
