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

	"github.com/calltech-app/serfex/agent/internal/aptcache"
	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/i18n"
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
// db.manager.setup gives a tool it adds (with the first database server
// that needs it) the pinned release, once serfex-web is seen serving it;
// where the release cannot be downloaded or does not work here, Ubuntu's
// package as before. A tool serfex-web serves already stays on its
// release or package: the administrator moves it on the Software page
// (UpgradeTool), which checks the release the same way and keeps the one
// before for toolsKeepFor to go back to (RevertTool). After that, or
// sooner (RemoveOldTool), Ubuntu's package is purged or the older
// release deleted.

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
	// toolsKeepFor is how long an upgrade of a tool keeps the one before.
	toolsKeepFor = 7 * 24 * time.Hour
)

// The tools, by the ids the Software page lists them with, which are
// also the names of Ubuntu's packages.
const (
	ToolPhpMyAdmin = "phpmyadmin"
	ToolAdminer    = "adminer"
)

// toolRelease is the release Serfex pins for tool.
func toolRelease(tool string) (pinned.Release, bool) {
	switch tool {
	case ToolPhpMyAdmin:
		return PhpMyAdminRelease, true
	case ToolAdminer:
		return AdminerRelease, true
	}
	return pinned.Release{}, false
}

func toolName(tool string) string {
	if tool == ToolAdminer {
		return "Adminer"
	}
	return "phpMyAdmin"
}

// toolServer is the database server a tool serves.
func toolServer(tool string) string {
	if tool == ToolAdminer {
		return PostgreSQL
	}
	return MySQL
}

// toolRoot holds the releases of tool.
func toolRoot(tool string) string {
	if tool == ToolAdminer {
		return adminerRoot
	}
	return pmaRoot
}

// toolDir is where a release of tool is unpacked.
func toolDir(tool, release string) string { return toolRoot(tool) + "/" + release }

// toolFile exists while serfex-web can serve tool on release ("" for
// Ubuntu's package).
func toolFile(tool, release string) string {
	if tool == ToolAdminer {
		return adminerFile(release)
	}
	return pmaDir(release) + "/index.php"
}

// toolVersion is a version as people read it: "4:5.2.1+dfsg-3" (dpkg's,
// for Ubuntu's package) is 5.2.1.
func toolVersion(v string) string {
	v, _, _ = strings.Cut(aptcache.Upstream(v), "+")
	v, _, _ = strings.Cut(v, "~")
	return v
}

// versionOlder reports whether version a is older than version b.
func versionOlder(a, b string) bool {
	pa, pb := strings.Split(toolVersion(a), "."), strings.Split(toolVersion(b), ".")
	for i := 0; i < len(pa) || i < len(pb); i++ {
		var x, y int
		if i < len(pa) {
			x, _ = strconv.Atoi(pa[i])
		}
		if i < len(pb) {
			y, _ = strconv.Atoi(pb[i])
		}
		if x != y {
			return x < y
		}
	}
	return false
}

// tools are the releases serfex-web serves: a version Serfex pinned, or
// "" for Ubuntu's package.
type tools struct {
	PhpMyAdmin string `json:"phpmyadmin,omitempty"`
	Adminer    string `json:"adminer,omitempty"`
}

func (t tools) get(tool string) string {
	if tool == ToolAdminer {
		return t.Adminer
	}
	return t.PhpMyAdmin
}

func (t tools) with(tool, release string) tools {
	if tool == ToolAdminer {
		t.Adminer = release
	} else {
		t.PhpMyAdmin = release
	}
	return t
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

// present reports whether serfex-web has tool to serve already, on a
// release or Ubuntu's package.
func (m *Manager) present(cur tools, tool string) bool {
	return m.exists(toolFile(tool, cur.get(tool)))
}

// fetchTools downloads the pinned release of each tool in add. It returns
// the tools to serve and, by tool, why one gets Ubuntu's package instead.
func (m *Manager) fetchTools(ctx context.Context, cur tools, add []string) (tools, map[string]string) {
	want, notes := cur, map[string]string{}
	for _, tool := range add {
		r, _ := toolRelease(tool)
		if err := m.fetchTool(ctx, tool); err != nil {
			notes[tool] = fmt.Sprintf("%s %s could not be downloaded: %v", toolName(tool), r.Version, err)
			continue
		}
		want = want.with(tool, r.Version)
	}
	return want, notes
}

func (m *Manager) fetchTool(ctx context.Context, tool string) error {
	if tool == ToolAdminer {
		return m.fetchAdminer(ctx)
	}
	return m.fetchPhpMyAdmin(ctx)
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

// pruneTools deletes the releases serfex-web neither serves nor keeps
// for going back.
func (m *Manager) pruneTools(served tools, kept map[string]toolKept) {
	for _, tool := range []string{ToolPhpMyAdmin, ToolAdminer} {
		entries, _ := os.ReadDir(m.h.Path(toolRoot(tool)))
		for _, e := range entries {
			if e.IsDir() && e.Name() != served.get(tool) && e.Name() != kept[tool].FromRelease {
				_ = os.RemoveAll(m.h.Path(toolDir(tool, e.Name())))
			}
		}
	}
}

// ---- Upgrades on the Software page ----

// ToolState is how serfex-web serves phpMyAdmin or Adminer.
type ToolState struct {
	// Served is true while the manager is set up, a database server
	// needs the tool and its files are there.
	Served bool
	// Release is the release served, "" for Ubuntu's package.
	Release string
}

// Tool reports how serfex-web serves tool.
func (m *Manager) Tool(tool string) ToolState {
	if _, ok := toolRelease(tool); !ok {
		return ToolState{}
	}
	st, err := m.loadState()
	if err != nil || st.Manager == nil {
		return ToolState{}
	}
	if _, ok := m.installedProduct(toolServer(tool)); !ok {
		return ToolState{}
	}
	release := m.served(st).get(tool)
	return ToolState{Served: m.exists(toolFile(tool, release)), Release: release}
}

// ToolOffer is the upgrade tool on version (a release, or dpkg's version
// of Ubuntu's package) can have: the release Serfex pins, when newer.
func ToolOffer(tool, version string) *UpgradeOffer {
	r, ok := toolRelease(tool)
	if !ok || toolVersion(version) == "" || !versionOlder(version, r.Version) {
		return nil
	}
	return &UpgradeOffer{From: toolVersion(version), To: r.Version}
}

// toolUpgrade is the release the Software page offers for a tool served
// on release ("" for Ubuntu's package, which Ubuntu 24.04 keeps older
// than any release Serfex pins); "" for none, or while an upgrade is
// kept.
func toolUpgrade(st *state, tool, release string) string {
	r, _ := toolRelease(tool)
	if _, kept := st.ToolsKept[tool]; kept || (release != "" && !versionOlder(release, r.Version)) {
		return ""
	}
	return r.Version
}

// toolKept is what an upgrade of a tool keeps for toolsKeepFor.
type toolKept struct {
	// From and To are the versions before and after; FromRelease is the
	// release served before, "" for Ubuntu's package.
	From        string    `json:"from"`
	FromRelease string    `json:"from_release,omitempty"`
	To          string    `json:"to"`
	Upgraded    time.Time `json:"upgraded"`
	Until       time.Time `json:"until"`
}

// ToolKept is what the last upgrade of tool keeps, nil for none.
func (m *Manager) ToolKept(tool string) *Kept {
	st, err := m.loadState()
	if err != nil {
		return nil
	}
	k, ok := st.ToolsKept[tool]
	if !ok {
		return nil
	}
	return &Kept{Product: tool, From: k.From, To: k.To, Old: toolFile(tool, k.FromRelease), Upgraded: k.Upgraded, Until: k.Until}
}

// ToolSpec asks to upgrade a tool, or to go back from its upgrade.
type ToolSpec struct {
	Tool string
	// From is the version served now as the Software page shows it
	// (dpkg's, for Ubuntu's package), what the upgrade keeps.
	From string
	Log  io.Writer
	// Fail, when set, lets tests break the operation at a named step.
	Fail func(step string) error
}

func (s ToolSpec) fail(step string) error {
	if s.Fail != nil {
		return s.Fail(step)
	}
	return nil
}

func (s ToolSpec) check() webCheck {
	return webCheck{phpMyAdmin: s.Tool == ToolPhpMyAdmin, adminer: s.Tool == ToolAdminer}
}

// toolSetup reads what an upgrade or a revert of spec.Tool needs, with
// m.mu held: the state, the tools served and the installed servers.
func (m *Manager) toolSetup(spec *ToolSpec) (st *state, cur tools, mysql, pg bool, err error) {
	if _, ok := toolRelease(spec.Tool); !ok {
		return nil, tools{}, false, false, invalid("tool must be phpmyadmin or adminer")
	}
	if spec.Log == nil {
		spec.Log = io.Discard
	}
	if st, err = m.loadState(); err != nil {
		return nil, tools{}, false, false, opError(err)
	}
	_, mysql = m.installedProduct(MySQL)
	_, pg = m.installedProduct(PostgreSQL)
	cur = m.served(st)
	if needed := map[string]bool{ToolPhpMyAdmin: mysql, ToolAdminer: pg}[spec.Tool]; st.Manager == nil || !needed || !m.present(cur, spec.Tool) {
		return nil, tools{}, false, false, invalid("%s is not set up", toolName(spec.Tool))
	}
	return st, cur, mysql, pg, nil
}

// switchTool serves spec.Tool on release ("" for Ubuntu's package) and
// checks serfex-web serves it; when it does not, the tools served before
// (cur) are served again.
func (m *Manager) switchTool(ctx context.Context, spec ToolSpec, st *state, cur tools, mysql, pg bool, release string) error {
	ports := m.webPorts(st)
	_, err := m.applyWeb(ctx, ports, mysql, pg, cur.with(spec.Tool, release), spec.check())
	if err == nil {
		err = spec.fail("check")
	}
	if err == nil {
		return nil
	}
	fmt.Fprintln(spec.Log, "↺ "+i18n.T(ctx, "Önceki sürüme dönülüyor"))
	if _, rbErr := m.applyWeb(context.WithoutCancel(ctx), ports, mysql, pg, cur, webCheck{}); rbErr != nil {
		err = fmt.Errorf("%w (going back also failed: %v)", err, rbErr)
	}
	return opError(err)
}

// UpgradeTool moves serfex-web from the release of spec.Tool or Ubuntu's
// package to the release Serfex pins: it downloads and checks the
// release, serves it and checks serfex-web serves it, else serves the
// one before again. The one before is kept for toolsKeepFor
// (RevertTool).
func (m *Manager) UpgradeTool(ctx context.Context, spec ToolSpec) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	st, cur, mysql, pg, err := m.toolSetup(&spec)
	if err != nil {
		return err
	}
	name := toolName(spec.Tool)
	r, _ := toolRelease(spec.Tool)
	if _, ok := st.ToolsKept[spec.Tool]; ok {
		return invalid("the previous upgrade of %s is still kept; remove it first", name)
	}
	prev, from := cur.get(spec.Tool), toolVersion(spec.From)
	if prev != "" {
		from = prev
	}
	if (prev != "" || from != "") && !versionOlder(from, r.Version) {
		return invalid("%s %s is the newest release Serfex offers", name, from)
	}
	if from == "" {
		from = "Ubuntu"
	}

	fmt.Fprintln(spec.Log, "▸ "+i18n.T(ctx, "%s %s indiriliyor ve doğrulanıyor", name, r.Version))
	err = spec.fail("download")
	if err == nil {
		err = m.fetchTool(ctx, spec.Tool)
	}
	if err != nil {
		return opError(fmt.Errorf("%s %s could not be downloaded: %w", name, r.Version, err))
	}
	fmt.Fprintln(spec.Log, "▸ "+i18n.T(ctx, "Serfex'in web sunucusu %s %s ile yeniden başlatılıyor", name, r.Version))
	if err := m.switchTool(ctx, spec, st, cur, mysql, pg, r.Version); err != nil {
		m.pruneTools(cur, st.ToolsKept)
		return err
	}
	now := m.now().UTC()
	if st.ToolsKept == nil {
		st.ToolsKept = map[string]toolKept{}
	}
	st.ToolsKept[spec.Tool] = toolKept{From: from, FromRelease: prev, To: r.Version, Upgraded: now, Until: now.Add(toolsKeepFor)}
	st.Tools = cur.with(spec.Tool, r.Version)
	delete(st.ToolNotes, spec.Tool)
	return opError(m.saveState(st))
}

// RevertTool goes back to what the last upgrade of spec.Tool kept:
// Ubuntu's package or the release before, checked the same way.
func (m *Manager) RevertTool(ctx context.Context, spec ToolSpec) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	st, cur, mysql, pg, err := m.toolSetup(&spec)
	if err != nil {
		return err
	}
	name := toolName(spec.Tool)
	k, ok := st.ToolsKept[spec.Tool]
	if !ok {
		return invalid("no upgrade of %s is kept to go back from", name)
	}
	if !m.exists(toolFile(spec.Tool, k.FromRelease)) {
		if k.FromRelease == "" {
			return opError(fmt.Errorf("Ubuntu's %s package is no longer installed", spec.Tool))
		}
		return opError(fmt.Errorf("%s %s is no longer in %s", name, k.FromRelease, toolRoot(spec.Tool)))
	}
	fmt.Fprintln(spec.Log, "▸ "+i18n.T(ctx, "%s %s sürümüne dönülüyor", name, k.From))
	if err := m.switchTool(ctx, spec, st, cur, mysql, pg, k.FromRelease); err != nil {
		return err
	}
	st.Tools = cur.with(spec.Tool, k.FromRelease)
	delete(st.ToolsKept, spec.Tool)
	m.pruneTools(st.Tools, st.ToolsKept)
	return opError(m.saveState(st))
}

// RemoveOldTool lets what the last upgrade of tool kept go before its
// week has passed.
func (m *Manager) RemoveOldTool(ctx context.Context, tool string, log io.Writer) error {
	if _, ok := toolRelease(tool); !ok {
		return invalid("tool must be phpmyadmin or adminer")
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	st, err := m.loadState()
	if err != nil {
		return opError(err)
	}
	if _, ok := st.ToolsKept[tool]; !ok {
		return invalid("no upgrade of %s is kept", toolName(tool))
	}
	if log == nil {
		log = io.Discard
	}
	return opError(m.removeOldTool(ctx, st, tool, log))
}

// removeOldTool purges Ubuntu's package or deletes the release an upgrade
// of tool kept. Called with m.mu held.
func (m *Manager) removeOldTool(ctx context.Context, st *state, tool string, log io.Writer) error {
	k := st.ToolsKept[tool]
	if k.FromRelease != "" {
		fmt.Fprintln(log, "▸ "+i18n.T(ctx, "Saklanan eski sürüm siliniyor: %s", toolDir(tool, k.FromRelease)))
		if err := os.RemoveAll(m.h.Path(toolDir(tool, k.FromRelease))); err != nil {
			return err
		}
	} else if m.exists(toolFile(tool, "")) {
		fmt.Fprintln(log, "▸ "+i18n.T(ctx, "Ubuntu'nun %s paketi kaldırılıyor", tool))
		if tool == ToolPhpMyAdmin {
			// Serfex's settings for Ubuntu's phpMyAdmin go first, so apt
			// removes its whole directory.
			_ = os.Remove(m.h.Path(pmaConf))
		}
		if _, err := m.h.Run(ctx, host.Command{Name: "apt-get", Args: []string{"purge", "-y", "-q", tool}, Env: aptEnv, Stream: log}); err != nil {
			return err
		}
	}
	delete(st.ToolsKept, tool)
	return m.saveState(st)
}

// pruneToolsKept lets what upgrades of the tools kept go once their week
// has passed. Called with m.mu held.
func (m *Manager) pruneToolsKept(ctx context.Context) {
	st, err := m.loadState()
	if err != nil {
		return
	}
	for tool, k := range st.ToolsKept {
		if m.now().After(k.Until) {
			_ = m.removeOldTool(ctx, st, tool, io.Discard)
		}
	}
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
