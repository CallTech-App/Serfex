package database

import (
	"bytes"
	"context"
	"crypto/tls"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"path"
	"path/filepath"
	"strconv"
	"strings"
	"time"

	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/pinned"
)

// ---- phpMyAdmin and Adminer releases ----
//
// The web database tools come from their makers' releases, pinned to a
// version and SHA-256, rather than from Ubuntu, which keeps phpMyAdmin at
// 5.2.1 (two XSS issues fixed in 5.2.2) and Adminer at 4.8.1:
//
//	/opt/serfex/phpmyadmin/<version>/phpmyadmin/                serfex-web serves /phpmyadmin/ from here
//	/opt/serfex/phpmyadmin/<version>/phpmyadmin/config.inc.php  Serfex's settings, root:serfex-dbadmin 0640
//	/opt/serfex/adminer/<version>/adminer.php                   Adminer, which Serfex's sign-in page includes
//
// db.manager.setup moves serfex-web to the pinned releases once they are
// downloaded and serfex-web is seen serving them, then purges Ubuntu's
// packages. A tool whose release cannot be downloaded or does not work
// here stays where it was: Ubuntu's package, which a new server then
// gets as before. Servers set up before a release that pins a new
// version move when the panel's UpdateDatabaseManager job runs
// db.manager.setup after the update (dispatched by a migration).

var (
	// PhpMyAdminRelease is the phpMyAdmin serfex-web serves.
	PhpMyAdminRelease = pinned.Release{
		Version: "5.2.3",
		URL:     "https://files.phpmyadmin.net/phpMyAdmin/5.2.3/phpMyAdmin-5.2.3-all-languages.tar.xz",
		SHA256:  "57881348297c4412f86c410547cf76b4d8a236574dd2c6b7d6a2beebe7fc44e3",
	}
	// AdminerRelease is the Adminer serfex-web serves.
	AdminerRelease = pinned.Release{
		Version: "6.3.0",
		URL:     "https://github.com/vrana/adminer/releases/download/v6.3.0/adminer-6.3.0.php",
		SHA256:  "641e1a31e8cc333a309e202ef9446e1fd38c2069ed5f9ee509b80d70089d0503",
	}
)

const (
	pmaRoot     = "/opt/serfex/phpmyadmin"
	adminerRoot = "/opt/serfex/adminer"
	// pmaMaxSize limits what phpMyAdmin's archive may unpack to.
	pmaMaxSize = 256 << 20
	// toolsStarting is how long serfex-web may take to answer after a
	// restart.
	toolsStarting = 15 * time.Second
	// adminerExpired marks the page Serfex's Adminer shows a browser
	// without a session (adminerPHP's loginForm).
	adminerExpired = `id="serfex-expired"`
)

// tools are the releases serfex-web serves: a version Serfex pinned, or
// "" for Ubuntu's package.
type tools struct {
	PhpMyAdmin string `json:"phpmyadmin,omitempty"`
	Adminer    string `json:"adminer,omitempty"`
}

// pmaWebRoot is serfex-web's root for /phpmyadmin/, the directory that
// holds phpMyAdmin's.
func pmaWebRoot(version string) string {
	if version == "" {
		return "/usr/share"
	}
	return pmaRoot + "/" + version
}

// pmaDir is phpMyAdmin's directory.
func pmaDir(version string) string { return pmaWebRoot(version) + "/phpmyadmin" }

// pmaConfFile holds Serfex's phpMyAdmin settings: the release's own
// config.inc.php, or a conf.d file of Ubuntu's package.
func pmaConfFile(version string) string {
	if version == "" {
		return pmaConf
	}
	return pmaDir(version) + "/config.inc.php"
}

// adminerFile is Adminer.
func adminerFile(version string) string {
	if version == "" {
		return adminerIndex
	}
	return adminerRoot + "/" + version + "/adminer.php"
}

// served are the tools serfex-web serves: the releases recorded, as long
// as their files are there.
func (m *Manager) served(st *state) tools {
	t := st.Tools
	if t.PhpMyAdmin != "" && !m.exists(pmaDir(t.PhpMyAdmin)+"/index.php") {
		t.PhpMyAdmin = ""
	}
	if t.Adminer != "" && !m.exists(adminerFile(t.Adminer)) {
		t.Adminer = ""
	}
	return t
}

// fetchTools downloads the pinned release of each tool the installed
// servers need that serfex-web does not serve yet. It returns the tools
// to serve and why a tool stays where it was.
func (m *Manager) fetchTools(ctx context.Context, cur tools, mysql, pg bool) (tools, []string) {
	want := cur
	var notes []string
	if mysql && cur.PhpMyAdmin != PhpMyAdminRelease.Version {
		if err := m.fetchPhpMyAdmin(ctx); err != nil {
			notes = append(notes, fmt.Sprintf("phpMyAdmin %s could not be downloaded: %v", PhpMyAdminRelease.Version, err))
		} else {
			want.PhpMyAdmin = PhpMyAdminRelease.Version
		}
	}
	if pg && cur.Adminer != AdminerRelease.Version {
		if err := m.fetchAdminer(ctx); err != nil {
			notes = append(notes, fmt.Sprintf("Adminer %s could not be downloaded: %v", AdminerRelease.Version, err))
		} else {
			want.Adminer = AdminerRelease.Version
		}
	}
	return want, notes
}

func (m *Manager) simulated() bool {
	_, ok := m.h.(*host.Simulator)
	return ok
}

// fetchPhpMyAdmin downloads the pinned phpMyAdmin, checks it and unpacks
// it, unless it is there already. A dry run without a Download writes a
// stand-in.
func (m *Manager) fetchPhpMyAdmin(ctx context.Context) (err error) {
	r := PhpMyAdminRelease
	dir := pmaDir(r.Version)
	if m.exists(dir+"/index.php") && m.exists(dir+"/vendor/autoload.php") {
		return nil
	}
	if m.Download == nil {
		if !m.simulated() {
			return errors.New("downloads are not set up")
		}
		return m.standIn(map[string]string{dir + "/index.php": "<?php // phpMyAdmin " + r.Version + " (dry run)\n", dir + "/vendor/autoload.php": "<?php\n"})
	}
	data, err := r.Fetch(ctx, m.Download)
	if err != nil {
		return err
	}
	if !m.exists("/usr/bin/xz") {
		if err := installPackages(ctx, m.h, []string{"xz-utils"}, "", nil); err != nil {
			return err
		}
	}
	tmp := m.h.Path(pmaRoot + "/.unpack-" + r.Version)
	_ = os.RemoveAll(tmp)
	if err := os.MkdirAll(tmp, 0o755); err != nil {
		return err
	}
	defer os.RemoveAll(tmp)

	// xz decompresses, the agent unpacks (see pinned.UnpackTar).
	pr, pw := io.Pipe()
	done := make(chan error, 1)
	go func() {
		_, err := m.h.Run(ctx, host.Command{Name: "xz", Args: []string{"-dc"}, Stdin: data, Stdout: pw})
		pw.CloseWithError(err)
		done <- err
	}()
	err = pinned.UnpackTar(pr, tmp, pmaMaxSize)
	if err == nil {
		// The archive's padding, and xz's error if it failed.
		_, err = io.Copy(io.Discard, pr)
	}
	pr.CloseWithError(errors.New("unpacking stopped"))
	if xerr := <-done; err == nil && xerr != nil {
		err = xerr
	}
	if err != nil {
		return fmt.Errorf("unpacking %s: %w", path.Base(r.URL), err)
	}

	// The setup pages and the examples are never served.
	for _, d := range []string{"setup", "examples"} {
		if err := os.RemoveAll(filepath.Join(tmp, d)); err != nil {
			return err
		}
	}
	for _, f := range []string{"index.php", "vendor/autoload.php", "libraries/classes"} {
		if _, err := os.Stat(filepath.Join(tmp, f)); err != nil {
			return fmt.Errorf("%s holds no phpMyAdmin (no %s)", path.Base(r.URL), f)
		}
	}
	if err := os.MkdirAll(m.h.Path(pmaWebRoot(r.Version)), 0o755); err != nil {
		return err
	}
	if err := os.RemoveAll(m.h.Path(dir)); err != nil {
		return err
	}
	return os.Rename(tmp, m.h.Path(dir))
}

// fetchAdminer downloads the pinned Adminer and checks it, unless it is
// there already. A dry run without a Download writes a stand-in.
func (m *Manager) fetchAdminer(ctx context.Context) error {
	r := AdminerRelease
	file := adminerFile(r.Version)
	if m.exists(file) {
		return nil
	}
	if m.Download == nil {
		if !m.simulated() {
			return errors.New("downloads are not set up")
		}
		return m.standIn(map[string]string{file: "<?php // Adminer " + r.Version + " (dry run)\n"})
	}
	data, err := r.Fetch(ctx, m.Download)
	if err != nil {
		return err
	}
	if !bytes.HasPrefix(data, []byte("<?php")) {
		return fmt.Errorf("%s is not a PHP file", path.Base(r.URL))
	}
	return m.standIn(map[string]string{file: string(data)})
}

// standIn writes files (0644, in directories 0755), each in one step.
func (m *Manager) standIn(files map[string]string) error {
	for f, body := range files {
		p := m.h.Path(f)
		if err := os.MkdirAll(filepath.Dir(p), 0o755); err != nil {
			return err
		}
		if err := os.WriteFile(p+".tmp", []byte(body), 0o644); err != nil {
			return err
		}
		if err := os.Rename(p+".tmp", p); err != nil {
			return err
		}
	}
	return nil
}

// pruneTools deletes the releases serfex-web does not serve.
func (m *Manager) pruneTools(served tools) {
	for _, d := range []struct{ root, keep string }{{pmaRoot, served.PhpMyAdmin}, {adminerRoot, served.Adminer}} {
		entries, _ := os.ReadDir(m.h.Path(d.root))
		for _, e := range entries {
			if e.IsDir() && e.Name() != d.keep {
				_ = os.RemoveAll(m.h.Path(d.root + "/" + e.Name()))
			}
		}
	}
}

// purgeUbuntuTools purges Ubuntu's phpMyAdmin and Adminer packages once
// serfex-web serves the releases instead.
func (m *Manager) purgeUbuntuTools(ctx context.Context, served tools) error {
	var purge []string
	if served.PhpMyAdmin != "" && m.exists(pmaIndex) {
		// Serfex's settings for Ubuntu's phpMyAdmin go first, so apt
		// removes its whole directory.
		_ = os.Remove(m.h.Path(pmaConf))
		purge = append(purge, "phpmyadmin")
	}
	if served.Adminer != "" && m.exists(adminerIndex) {
		purge = append(purge, "adminer")
	}
	if len(purge) == 0 {
		return nil
	}
	_, err := m.h.Run(ctx, host.Command{Name: "apt-get", Args: append([]string{"purge", "-y", "-q"}, purge...), Env: aptEnv})
	return err
}

// webCheck says which tools applyWeb checks serfex-web serves, after it
// moved them to another release.
type webCheck struct{ phpMyAdmin, adminer bool }

func (c webCheck) any() bool { return c.phpMyAdmin || c.adminer }

// toolsServe checks that serfex-web serves the tools on port: phpMyAdmin
// sends a browser without a session to Serfex's sign-in page, Adminer
// answers with Serfex's page for an expired session. Both run PHP
// through serfex-php with Serfex's settings to get there.
func (m *Manager) toolsServe(ctx context.Context, port int, c webCheck) error {
	if m.checkTools != nil {
		return m.checkTools(ctx, port, c)
	}
	if m.simulated() {
		return nil
	}
	client := &http.Client{
		Timeout:   15 * time.Second,
		Transport: &http.Transport{TLSClientConfig: &tls.Config{InsecureSkipVerify: true}}, // serfex-web's own certificate, on the loopback address
		CheckRedirect: func(*http.Request, []*http.Request) error {
			return http.ErrUseLastResponse
		},
	}
	defer client.CloseIdleConnections()
	get := func(page string) (*http.Response, []byte, error) {
		deadline := time.Now().Add(toolsStarting)
		for {
			req, err := http.NewRequestWithContext(ctx, http.MethodGet, "https://127.0.0.1:"+strconv.Itoa(port)+page, nil)
			if err != nil {
				return nil, nil, err
			}
			res, err := client.Do(req)
			if err == nil {
				defer res.Body.Close()
				body, err := io.ReadAll(io.LimitReader(res.Body, 1<<20))
				return res, body, err
			}
			if time.Now().After(deadline) || ctx.Err() != nil {
				return nil, nil, err
			}
			time.Sleep(500 * time.Millisecond)
		}
	}
	if c.phpMyAdmin {
		res, _, err := get("/phpmyadmin/index.php")
		if err != nil {
			return fmt.Errorf("phpMyAdmin does not answer: %w", err)
		}
		if loc := res.Header.Get("Location"); res.StatusCode != http.StatusFound || !strings.Contains(loc, "/phpmyadmin/serfex-sso.php") {
			return fmt.Errorf("phpMyAdmin answers HTTP %d (Location %q) instead of sending to Serfex's sign-in (see %s/php-errors.log)", res.StatusCode, loc, webLogs)
		}
	}
	if c.adminer {
		res, body, err := get("/adminer/?pgsql=127.0.0.1&username=serfex-check")
		if err != nil {
			return fmt.Errorf("Adminer does not answer: %w", err)
		}
		if res.StatusCode >= 500 || !bytes.Contains(body, []byte(adminerExpired)) {
			return fmt.Errorf("Adminer answers HTTP %d without Serfex's sign-in page (see %s/php-errors.log)", res.StatusCode, webLogs)
		}
	}
	return nil
}
