package laravel

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"time"

	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/txn"
)

// Tools Laravel needs on the server. PHP extensions and Git come from
// Ubuntu's packages; Composer and Node.js are downloaded from their
// publishers and checked against the published SHA-256 sums, because the
// distribution's versions are too old for current Laravel releases.

const (
	composerURL = "https://getcomposer.org/download/latest-stable/composer.phar"
	// nodeLine is the Node.js release line installed for front-end builds
	// when none is installed yet.
	nodeLine = "24"
	nodeDist = "https://nodejs.org/dist/"
)

// phpPackages are the PHP packages Laravel and its common drivers need.
func phpPackages(v string) []string {
	var out []string
	for _, ext := range []string{"cli", "mbstring", "xml", "curl", "zip", "intl", "bcmath", "sqlite3", "mysql", "pgsql", "gd"} {
		out = append(out, "php"+v+"-"+ext)
	}
	return out
}

// ToolsStatus says what is installed for Laravel applications.
type ToolsStatus struct {
	PHPVersion string   `json:"php_version"`
	PHP        bool     `json:"php"`
	Missing    []string `json:"missing"`
	Composer   bool     `json:"composer"`
	Git        bool     `json:"git"`
	Node       string   `json:"node"`
}

// Tools reports which tools are installed for PHP version v.
func (m *Manager) Tools(ctx context.Context, v string) (ToolsStatus, error) {
	if err := (Target{Domain: "example.com", User: "x", PHPVersion: v}).validatePHP(); err != nil {
		return ToolsStatus{}, err
	}
	st := ToolsStatus{PHPVersion: v, PHP: m.phpInstalled(v), Missing: []string{}}
	for _, p := range append(phpPackages(v), "git", "unzip") {
		if !m.packageInstalled(ctx, p) {
			st.Missing = append(st.Missing, p)
		}
	}
	st.Composer = m.exists(composerPhar())
	st.Git = m.exists("/usr/bin/git")
	if target, err := os.Readlink(m.h.Path(toolsDir + "/node/current")); err == nil {
		st.Node = filepath.Base(target)
	}
	return st, nil
}

func (m *Manager) packageInstalled(ctx context.Context, pkg string) bool {
	out, err := m.h.Run(ctx, host.Command{Name: "dpkg-query", Args: []string{"-W", "-f=${db:Status-Status}", pkg}})
	return err == nil && strings.TrimSpace(out) == "installed"
}

// ensureTools installs what a deploy of PHP version v needs and is missing.
func (m *Manager) ensureTools(ctx context.Context, w io.Writer, v string, node bool) error {
	st, err := m.Tools(ctx, v)
	if err != nil {
		return err
	}
	if len(st.Missing) > 0 {
		fmt.Fprintf(w, "▸ Gerekli paketler kuruluyor: %s\n", strings.Join(st.Missing, " "))
		env := []string{"DEBIAN_FRONTEND=noninteractive"}
		inst := host.Command{Name: "apt-get", Args: append([]string{"install", "-y", "-q", "--no-install-recommends"}, st.Missing...), Env: env}
		if _, err := m.h.Run(ctx, inst); err != nil {
			// The package lists may be stale on a fresh server.
			if _, err := m.h.Run(ctx, host.Command{Name: "apt-get", Args: []string{"update", "-q"}, Env: env}); err != nil {
				return fmt.Errorf("apt-get update failed: %w", err)
			}
			if _, err := m.h.Run(ctx, inst); err != nil {
				return fmt.Errorf("installing %s failed: %w", strings.Join(st.Missing, " "), err)
			}
		}
		// New extensions are loaded by PHP-FPM on reload.
		_, _ = m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"reload", "php" + v + "-fpm"}})
	}
	if !m.phpInstalled(v) {
		return fmt.Errorf("the PHP %s command line is still missing", v)
	}
	if !st.Composer {
		fmt.Fprintln(w, "▸ Composer indiriliyor (getcomposer.org)")
		if err := m.InstallComposer(ctx); err != nil {
			return err
		}
	}
	if node && st.Node == "" {
		fmt.Fprintln(w, "▸ Node.js indiriliyor (nodejs.org, v"+nodeLine+".x)")
		version, err := m.InstallNode(ctx, nodeLine)
		if err != nil {
			return err
		}
		fmt.Fprintln(w, "  Node.js "+version+" kuruldu")
	}
	return m.writeShims(v)
}

// writeShims puts php and composer of version v on the deploy PATH, so
// hooks and package scripts call the site's PHP version.
func (m *Manager) writeShims(v string) error {
	dir := shimDir(v)
	t := txn.New(m.h)
	for _, d := range []string{toolsDir, toolsDir + "/php", toolsDir + "/php/" + v, dir} {
		if err := t.RootDir(d, 0o755, 0, 0); err != nil {
			return err
		}
	}
	if err := t.ReplaceSymlink(phpBin(v), dir+"/php"); err != nil {
		return err
	}
	script := "#!/bin/sh\n# " + txn.Marker + ". Manual changes are overwritten.\nexec " + phpBin(v) + " " + composerPhar() + " \"$@\"\n"
	return t.WriteOwned(dir+"/composer", []byte(script), 0o755, 0, 0)
}

// InstallComposer downloads the newest stable Composer into
// /opt/servik/composer, checked against its published SHA-256 sum.
func (m *Manager) InstallComposer(ctx context.Context) error {
	data := []byte("<?php // simulated composer.phar\n")
	if !m.sim {
		var err error
		data, err = m.Download(ctx, composerURL)
		if err != nil {
			return err
		}
		sum, err := m.Download(ctx, composerURL+".sha256")
		if err != nil {
			return err
		}
		if err := verify(data, firstField(string(sum))); err != nil {
			return fmt.Errorf("composer.phar: %w", err)
		}
	}
	t := txn.New(m.h)
	for _, d := range []string{toolsDir, toolsDir + "/composer"} {
		if err := t.RootDir(d, 0o755, 0, 0); err != nil {
			return err
		}
	}
	return t.WriteOwned(composerPhar(), data, 0o755, 0, 0)
}

// InstallNode downloads the newest Node.js of a release line ("24") for
// this machine into /opt/servik/node/<version>, replacing older versions
// of the line. Builds use /opt/servik/node/current, which points at it
// when it pointed nowhere (see pickNode).
func (m *Manager) InstallNode(ctx context.Context, major string) (string, error) {
	if !nodeMajorRe.MatchString(major) {
		return "", invalid("node line %q must be a major version such as 24", major)
	}
	arch := map[string]string{"amd64": "x64", "arm64": "arm64"}[runtime.GOARCH]
	if arch == "" {
		return "", fmt.Errorf("no Node.js build for %s", runtime.GOARCH)
	}
	t := txn.New(m.h)
	for _, d := range []string{toolsDir, toolsDir + "/node"} {
		if err := t.RootDir(d, 0o755, 0, 0); err != nil {
			return "", err
		}
	}
	if m.sim {
		version := simNode[major]
		if version == "" {
			version = "v" + major + ".0.0"
		}
		dir := toolsDir + "/node/" + version
		if err := os.MkdirAll(m.h.Path(dir+"/bin"), 0o755); err != nil {
			return "", err
		}
		return version, m.finishNode(t, major, version)
	}
	line := "latest-v" + major + ".x"
	sums, err := m.Download(ctx, nodeDist+line+"/SHASUMS256.txt")
	if err != nil {
		return "", err
	}
	var name, sum string
	for _, line := range strings.Split(string(sums), "\n") {
		f := strings.Fields(line)
		if len(f) == 2 && strings.HasPrefix(f[1], "node-v") && strings.HasSuffix(f[1], "-linux-"+arch+".tar.gz") {
			sum, name = f[0], f[1]
		}
	}
	if name == "" {
		return "", fmt.Errorf("no linux-%s build listed in %sSHASUMS256.txt", arch, nodeDist+line+"/")
	}
	version := strings.TrimSuffix(strings.TrimPrefix(name, "node-"), "-linux-"+arch+".tar.gz")
	data, err := m.Download(ctx, nodeDist+line+"/"+name)
	if err != nil {
		return "", err
	}
	if err := verify(data, sum); err != nil {
		return "", fmt.Errorf("%s: %w", name, err)
	}
	dir := m.h.Path(toolsDir + "/node/" + version)
	tmp := dir + ".servik-new"
	_ = os.RemoveAll(tmp)
	if err := extractTarGz(data, tmp); err != nil {
		os.RemoveAll(tmp)
		return "", fmt.Errorf("extracting %s: %w", name, err)
	}
	_ = os.RemoveAll(dir)
	if err := os.Rename(tmp, dir); err != nil {
		return "", err
	}
	return version, m.finishNode(t, major, version)
}

func verify(data []byte, want string) error {
	got := sha256.Sum256(data)
	if want == "" || !strings.EqualFold(hex.EncodeToString(got[:]), want) {
		return errors.New("SHA-256 checksum does not match the published one")
	}
	return nil
}

func firstField(s string) string {
	f := strings.Fields(s)
	if len(f) == 0 {
		return ""
	}
	return f[0]
}

// extractTarGz unpacks a Node.js tarball into dir, dropping its top
// directory. Entries that would land outside dir are rejected.
func extractTarGz(data []byte, dir string) error {
	gz, err := gzip.NewReader(bytes.NewReader(data))
	if err != nil {
		return err
	}
	tr := tar.NewReader(gz)
	for {
		hdr, err := tr.Next()
		if errors.Is(err, io.EOF) {
			return nil
		}
		if err != nil {
			return err
		}
		_, rel, ok := strings.Cut(hdr.Name, "/")
		if !ok || rel == "" {
			continue
		}
		target := filepath.Join(dir, rel)
		if !strings.HasPrefix(target, dir+string(os.PathSeparator)) {
			return fmt.Errorf("unsafe path %q", hdr.Name)
		}
		if err := os.MkdirAll(filepath.Dir(target), 0o755); err != nil {
			return err
		}
		switch hdr.Typeflag {
		case tar.TypeDir:
			if err := os.MkdirAll(target, 0o755); err != nil {
				return err
			}
		case tar.TypeSymlink:
			if filepath.IsAbs(hdr.Linkname) {
				return fmt.Errorf("unsafe link %q", hdr.Name)
			}
			if err := os.Symlink(hdr.Linkname, target); err != nil {
				return err
			}
		case tar.TypeReg:
			f, err := os.OpenFile(target, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, os.FileMode(hdr.Mode).Perm()&0o755)
			if err != nil {
				return err
			}
			if _, err := io.Copy(f, tr); err != nil {
				f.Close()
				return err
			}
			if err := f.Close(); err != nil {
				return err
			}
		}
	}
}

// download fetches url over HTTPS, up to 256 MB.
func download(ctx context.Context, url string) ([]byte, error) {
	ctx, cancel := context.WithTimeout(ctx, 10*time.Minute)
	defer cancel()
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return nil, err
	}
	req.Header.Set("User-Agent", "servik-agent")
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		return nil, fmt.Errorf("downloading %s: %w", url, err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("downloading %s: HTTP %d", url, resp.StatusCode)
	}
	data, err := io.ReadAll(io.LimitReader(resp.Body, 256<<20))
	if err != nil {
		return nil, fmt.Errorf("downloading %s: %w", url, err)
	}
	return data, nil
}
