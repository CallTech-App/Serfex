package mail

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"sort"
	"strings"
	"time"

	"github.com/calltech-app/serfex/agent/internal/txn"
)

// The webmail applications mail.setup can serve at https://<hostname>/.
const (
	Roundcube  = "roundcube"
	SnappyMail = "snappymail"
)

// SnappyMailRelease is the SnappyMail release Serfex installs. Ubuntu has
// no package, so the agent downloads the release from GitHub and checks
// it against this checksum; a newer release comes with a Serfex update.
var SnappyMailRelease = struct{ Version, SHA256 string }{
	Version: "2.38.2",
	SHA256:  "71f1d8a9065cc9cf7ddd064f5c47cc7b255cb70e6a56713647fc73d4b79e33ec",
}

const (
	snappyMailURL = "https://github.com/the-djmaze/snappymail/releases/download/v%[1]s/snappymail-%[1]s.tar.gz"
	// snappyMailDir holds each installed release in a directory of its
	// own, root-owned.
	snappyMailDir = "/opt/serfex/snappymail"
	// snappyMailData is SnappyMail's data folder: settings, contacts and
	// caches of every mailbox.
	snappyMailData    = webmailDir + "/snappymail"
	snappyMailPrivate = snappyMailData + "/_data_/_default_"
	snappyMailSSO     = webmailDir + "/snappymail-sso.php"
)

var snappyVersionRe = regexp.MustCompile(`^[0-9]+\.[0-9]+\.[0-9]+$`)

func snappyMailRoot(version string) string { return snappyMailDir + "/" + version }

// webmailApp is the application the stored setup serves.
func (s *server) webmailApp() string {
	if s.WebmailApp == "" {
		return Roundcube
	}
	return s.WebmailApp
}

// ensureSnappyMail installs SnappyMailRelease when it is missing. Like the
// packages, a downloaded release stays on a failure.
func (m *Manager) ensureSnappyMail(ctx context.Context) (string, error) {
	version := SnappyMailRelease.Version
	root := snappyMailRoot(version)
	if m.exists(root + "/snappymail/v/" + version + "/include.php") {
		return version, nil
	}
	if err := os.MkdirAll(m.h.Path(snappyMailDir), 0o755); err != nil {
		return "", err
	}
	dir := m.h.Path(root)
	tmp := dir + ".serfex-new"
	_ = os.RemoveAll(tmp)
	if err := m.fetchSnappyMail(ctx, version, tmp); err != nil {
		_ = os.RemoveAll(tmp)
		return "", err
	}
	if !m.existsIn(tmp, "snappymail/v/"+version+"/include.php") {
		_ = os.RemoveAll(tmp)
		return "", fmt.Errorf("SnappyMail %s: snappymail/v/%s/include.php is not in the release", version, version)
	}
	_ = os.RemoveAll(dir)
	if err := os.Rename(tmp, dir); err != nil {
		return "", err
	}
	return version, nil
}

// fetchSnappyMail downloads a release, checks it and unpacks it into dir.
// A simulated host gets the files Serfex looks for instead.
func (m *Manager) fetchSnappyMail(ctx context.Context, version, dir string) error {
	if m.sim {
		for name, body := range map[string]string{
			"index.php": "<?php define('APP_VERSION', '" + version + "');\n",
			"snappymail/v/" + version + "/include.php": "<?php\n",
		} {
			if err := os.MkdirAll(filepath.Dir(filepath.Join(dir, name)), 0o755); err != nil {
				return err
			}
			if err := os.WriteFile(filepath.Join(dir, name), []byte(body), 0o644); err != nil {
				return err
			}
		}
		return nil
	}
	url := fmt.Sprintf(snappyMailURL, version)
	data, err := m.Download(ctx, url)
	if err != nil {
		return fmt.Errorf("SnappyMail could not be downloaded: %w", err)
	}
	if sum := sha256.Sum256(data); !strings.EqualFold(hex.EncodeToString(sum[:]), SnappyMailRelease.SHA256) {
		return fmt.Errorf("SnappyMail %s: the download does not match its SHA-256 checksum", version)
	}
	if err := extractRelease(data, dir); err != nil {
		return fmt.Errorf("extracting SnappyMail %s: %w", version, err)
	}
	return nil
}

func (m *Manager) existsIn(dir, rel string) bool {
	_, err := os.Stat(filepath.Join(dir, rel))
	return err == nil
}

// pruneSnappyMail removes installed releases except keep (the one in use
// and the one before it, for a rollback).
func (m *Manager) pruneSnappyMail(keep ...string) {
	entries, _ := os.ReadDir(m.h.Path(snappyMailDir))
	for _, e := range entries {
		if e.IsDir() && snappyVersionRe.MatchString(e.Name()) && !slices.Contains(keep, e.Name()) {
			_ = os.RemoveAll(m.h.Path(snappyMailDir + "/" + e.Name()))
		}
	}
}

// writeSnappyMail writes SnappyMail's settings: its data folder outside
// the release, the mail server every address signs in to, the one-click
// login and the PHP-FPM pool.
func (m *Manager) writeSnappyMail(ctx context.Context, t *txn.Tx, s *server, data tmplData) error {
	acct, err := m.ensureAccount(ctx, WebmailUser, webmailDir, "Serfex webmail")
	if err != nil {
		return err
	}
	for _, d := range []struct {
		path     string
		mode     os.FileMode
		uid, gid int
	}{
		{webmailDir, 0o755, 0, 0},
		{webmailData, 0o700, acct.UID, acct.GID},
		{webmailData + "/temp", 0o700, acct.UID, acct.GID},
		{webmailSSO, 0o700, acct.UID, acct.GID},
		{snappyMailData, 0o700, acct.UID, acct.GID},
		{snappyMailData + "/_data_", 0o700, acct.UID, acct.GID},
		{snappyMailPrivate, 0o700, acct.UID, acct.GID},
		{snappyMailPrivate + "/configs", 0o700, acct.UID, acct.GID},
		{snappyMailPrivate + "/domains", 0o700, acct.UID, acct.GID},
		{"/var/log/serfex", 0o755, 0, 0},
		{webmailLogs, 0o750, acct.UID, acct.GID},
	} {
		if err := t.RootDir(d.path, d.mode, d.uid, d.gid); err != nil {
			return err
		}
	}
	// Root writes the settings; SnappyMail only reads them, and its
	// admin panel is off.
	files := map[string][]byte{
		snappyMailPrivate + "/configs/application.ini": render(snappyMailConfigTmpl, data),
		// Every address signs in to this server ("default" is SnappyMail's
		// wildcard domain).
		snappyMailPrivate + "/domains/default.json": snappyMailDomain(),
		// An existing "disabled" list keeps SnappyMail from adding its
		// sample domains (gmail.com and others).
		snappyMailPrivate + "/domains/disabled": nil,
		snappyMailSSO:                           render(snappyMailSSOTmpl, data),
	}
	paths := make([]string, 0, len(files))
	for p := range files {
		paths = append(paths, p)
	}
	sort.Strings(paths)
	for _, p := range paths {
		write := t.WriteFileMode
		if !strings.HasSuffix(p, ".ini") && !strings.HasSuffix(p, ".php") {
			// JSON (and an empty file) cannot carry the marker line.
			write = t.WriteOwned
		}
		if err := write(p, files[p], 0o640, 0, acct.GID); err != nil {
			return err
		}
	}
	// SnappyMail reads include.php next to its index.php.
	if err := t.WriteFile(data.WebmailRoot+"/include.php", render(snappyMailIncludeTmpl, data)); err != nil {
		return err
	}
	return t.WriteFile(webmailPool(s.PHPVersion), render(webmailPoolTmpl, data))
}

// snappyMailDomain is the mail server as SnappyMail connects to it: IMAP
// and ManageSieve on localhost, submission with STARTTLS on loopback
// (whose certificate names the server, not 127.0.0.1).
func snappyMailDomain() []byte {
	tls := map[string]any{
		"verify_peer": false, "verify_peer_name": false, "allow_self_signed": true,
		"SNI_enabled": true, "disable_compression": true, "security_level": 1,
	}
	sasl := []string{"PLAIN", "LOGIN"}
	domain := map[string]any{
		"IMAP": map[string]any{
			"host": "localhost", "port": 143, "type": 0, "timeout": 300, "shortLogin": false, "lowerLogin": true,
			"sasl": sasl, "ssl": tls, "disabled_capabilities": []string{"METADATA", "OBJECTID", "PREVIEW", "STATUS=SIZE"},
			"use_expunge_all_on_delete": false, "fast_simple_search": true, "force_select": false,
			"message_all_headers": false, "message_list_limit": 10000, "search_filter": "",
		},
		"SMTP": map[string]any{
			"host": "127.0.0.1", "port": 587, "type": 2, "timeout": 60, "shortLogin": false, "lowerLogin": true,
			"sasl": sasl, "ssl": tls, "useAuth": true, "setSender": false, "usePhpMail": false,
		},
		"Sieve": map[string]any{
			"host": "localhost", "port": 4190, "type": 0, "timeout": 10, "shortLogin": false, "lowerLogin": true,
			"sasl": sasl, "ssl": tls, "enabled": true,
		},
		"whiteList": "",
	}
	out, _ := json.MarshalIndent(domain, "", "    ")
	return append(out, '\n')
}

// extractRelease unpacks a SnappyMail release into dir: root-owned,
// regular files and directories only, nothing outside dir.
func extractRelease(data []byte, dir string) error {
	gz, err := gzip.NewReader(bytes.NewReader(data))
	if err != nil {
		return err
	}
	tr := tar.NewReader(gz)
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return err
	}
	for {
		hdr, err := tr.Next()
		if errors.Is(err, io.EOF) {
			return nil
		}
		if err != nil {
			return err
		}
		name := strings.TrimPrefix(hdr.Name, "./")
		if name == "" {
			continue
		}
		target := filepath.Join(dir, name)
		if !strings.HasPrefix(target, dir+string(os.PathSeparator)) {
			return fmt.Errorf("unsafe path %q", hdr.Name)
		}
		switch hdr.Typeflag {
		case tar.TypeDir:
			if err := os.MkdirAll(target, 0o755); err != nil {
				return err
			}
		case tar.TypeReg:
			if err := os.MkdirAll(filepath.Dir(target), 0o755); err != nil {
				return err
			}
			f, err := os.OpenFile(target, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o644)
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
		default:
			return fmt.Errorf("unexpected entry %q in the release", hdr.Name)
		}
	}
}

// download fetches url over HTTPS, up to 64 MB.
func download(ctx context.Context, url string) ([]byte, error) {
	ctx, cancel := context.WithTimeout(ctx, 5*time.Minute)
	defer cancel()
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return nil, err
	}
	req.Header.Set("User-Agent", "serfex-agent")
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		return nil, fmt.Errorf("downloading %s: %w", url, err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("downloading %s: HTTP %d", url, resp.StatusCode)
	}
	data, err := io.ReadAll(io.LimitReader(resp.Body, 64<<20))
	if err != nil {
		return nil, fmt.Errorf("downloading %s: %w", url, err)
	}
	return data, nil
}
