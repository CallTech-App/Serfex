package laravel

import (
	"bytes"
	"context"
	"crypto/rand"
	"crypto/sha256"
	"crypto/sha512"
	_ "embed"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/url"
	"os"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/i18n"
	"github.com/calltech-app/serfex/agent/internal/site"
	"github.com/calltech-app/serfex/agent/internal/txn"
)

// WordPress sites (WordPress araç seti): installing WordPress into a
// site's web root, finding installations already there, updates of the
// core, plugins and themes with a restore point, a one-time sign-in link
// to the dashboard, maintenance mode and hardening. They share this
// package's account helpers, run logs and per-site lock with Laravel
// deploys. Everything runs through WP-CLI as the site's account with the
// site's PHP version.
//
// On the server, root-owned:
//
//	/opt/serfex/wp-cli/wp-cli.phar                          WP-CLI, checked against its SHA-512 sum
//	/var/lib/serfex/wordpress/<domain>/runs/<run>.log       install, update and restore output
//	/var/lib/serfex/wordpress/<domain>/restore/<run>/       restore points (meta.json, files.tar.gz, database.sql.gz)
//	/etc/serfex/nginx/sites/<domain>/wordpress.conf         hardening rules (site.ExtraDir)
//
// In the WordPress folder, the account's own:
//
//	wp-content/mu-plugins/serfex-login.php   answers the one-time sign-in links

const (
	wpStateDir = "/var/lib/serfex/wordpress"
	wpCLIURL   = "https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar"
	// wpCLIMaxAge is how old WP-CLI may get before the next operation
	// fetches the current release.
	wpCLIMaxAge = 30 * 24 * time.Hour
	wpTimeout   = 30 * time.Minute
	// wpLoginTTL is how long a sign-in link works.
	wpLoginTTL = 2 * time.Minute
	// wpSnippet is the site's Nginx snippet with the hardening rules.
	wpSnippet = "wordpress.conf"
	// wpLoginPlugin answers the sign-in links, relative to the folder.
	wpLoginPlugin = "wp-content/mu-plugins/serfex-login.php"
)

//go:embed wordpress-login.php
var wpLoginPHP []byte

func wpCLIPhar() string               { return toolsDir + "/wp-cli/wp-cli.phar" }
func wpRunDir(domain string) string   { return wpStateDir + "/" + domain + "/runs" }
func wpPointDir(domain string) string { return wpStateDir + "/" + domain + "/restore" }

// WPItem is a plugin or theme of an installation.
type WPItem struct {
	Name    string `json:"name"`
	Title   string `json:"title"`
	Version string `json:"version"`
	// Update is the version offered, "" when it is current.
	Update string `json:"update"`
	// Status is active, inactive, must-use, dropin or parent (a theme
	// the active one builds on).
	Status     string `json:"status"`
	AutoUpdate bool   `json:"auto_update"`
}

// WPStatus describes an installation.
type WPStatus struct {
	Version string `json:"version"`
	// CoreUpdate is the newest WordPress offered, "" when current.
	CoreUpdate string   `json:"core_update"`
	SiteURL    string   `json:"site_url"`
	HomeURL    string   `json:"home_url"`
	Plugins    []WPItem `json:"plugins"`
	Themes     []WPItem `json:"themes"`
	// Maintenance is WordPress's own maintenance mode (.maintenance).
	Maintenance bool     `json:"maintenance"`
	Admins      []string `json:"admins"`
	// Hardening is the state of each hardening rule (WPRules).
	Hardening map[string]bool `json:"hardening"`
	// AutoUpdateCore is WP_AUTO_UPDATE_CORE: minor (WordPress's default),
	// all or off.
	AutoUpdateCore string `json:"auto_update_core"`
	Database       string `json:"database"`
	// Problem is set when WordPress could not load with its plugins and
	// themes (a fatal error): the lists come from a load without them.
	Problem string `json:"problem,omitempty"`
}

// WPFound is an installation found on a site.
type WPFound struct {
	Path    string `json:"path"`
	Version string `json:"version"`
}

var (
	wpVersionRe = regexp.MustCompile(`\$wp_version\s*=\s*'([0-9][0-9A-Za-z.\-]*)'`)
	wpSlugRe    = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$`)
	wpLoginRe   = regexp.MustCompile(`^[A-Za-z0-9 _.@-]{1,60}$`)
	wpLocaleRe  = regexp.MustCompile(`^[a-z]{2,3}(_[A-Z]{2})?$`)
)

// wpCmd is WP-CLI as the site's account in the WordPress folder. skip
// leaves plugins and themes out, so a broken plugin cannot stop it.
// PHP's notices go to the error output, so the standard output stays
// clean for JSON.
func (m *Manager) wpCmd(a App, stream io.Writer, skip bool, args ...string) host.Command {
	full := []string{"-d", "memory_limit=512M", "-d", "display_errors=stderr", wpCLIPhar(), "--path=" + a.dir(), "--no-color"}
	if skip {
		full = append(full, "--skip-plugins", "--skip-themes")
	}
	extra := []string{"WP_CLI_DISABLE_AUTO_CHECK_UPDATE=1", "WP_CLI_CACHE_DIR=/home/" + a.User + "/.wp-cli/cache"}
	return m.cmdEnv(a.Target, a.dir(), stream, extra, phpBin(a.PHPVersion), append(full, args...)...)
}

// wp runs a quiet WP-CLI command and returns its standard output; the
// error carries the first line of what it complained about.
func (m *Manager) wp(ctx context.Context, a App, skip bool, args ...string) (string, error) {
	var out bytes.Buffer
	c := m.wpCmd(a, nil, skip, args...)
	c.Stdout = &out
	stderr, err := m.h.Run(ctx, c)
	if err != nil {
		return out.String(), errors.New(wpError(stderr, out.String(), args))
	}
	return out.String(), nil
}

// wpError is the reason WP-CLI gave, without its "Error: " prefix.
func wpError(stderr, stdout string, args []string) string {
	for _, s := range []string{stderr, stdout} {
		for _, line := range strings.Split(s, "\n") {
			line = strings.TrimSpace(line)
			if line == "" || strings.HasPrefix(line, "Warning:") {
				continue
			}
			line = strings.TrimPrefix(line, "Error: ")
			if len(line) > 300 {
				line = line[:300] + "…"
			}
			return line
		}
	}
	return "wp " + strings.Join(args, " ") + " failed"
}

// wpJSON runs a WP-CLI command that prints JSON into v. Lines before the
// JSON (a plugin echoing something) are skipped.
func (m *Manager) wpJSON(ctx context.Context, a App, skip bool, v any, args ...string) error {
	out, err := m.wp(ctx, a, skip, args...)
	if err != nil {
		return err
	}
	return decodeJSON(out, v)
}

func decodeJSON(out string, v any) error {
	i := strings.IndexAny(out, "[{")
	if i < 0 {
		return errors.New("WP-CLI printed no JSON")
	}
	return json.Unmarshal([]byte(strings.TrimSpace(out[i:])), v)
}

// validateWP checks an installation's address and that WordPress is there.
func (m *Manager) validateWP(a App) error {
	if err := a.validate(); err != nil {
		return err
	}
	if err := m.requirePHP(a.PHPVersion); err != nil {
		return err
	}
	if !m.exists(a.dir() + "/wp-includes/version.php") {
		return invalid("no WordPress at %s", a.dir())
	}
	return nil
}

// ensureWPCLI downloads WP-CLI when it is missing or a month old. An old
// copy that cannot be refreshed is used as it is.
func (m *Manager) ensureWPCLI(ctx context.Context, w io.Writer) error {
	fi, err := os.Stat(m.h.Path(wpCLIPhar()))
	if err == nil && time.Since(fi.ModTime()) < wpCLIMaxAge {
		return nil
	}
	if w != nil {
		fmt.Fprintln(w, "▸ "+i18n.T(ctx, "WP-CLI indiriliyor (wp-cli.org, SHA-512 denetimiyle)"))
	}
	if derr := m.InstallWPCLI(ctx); derr != nil {
		if err == nil {
			return nil
		}
		return derr
	}
	return nil
}

// InstallWPCLI downloads the current WP-CLI into /opt/serfex/wp-cli,
// checked against its published SHA-512 sum.
func (m *Manager) InstallWPCLI(ctx context.Context) error {
	data := []byte("<?php // simulated wp-cli.phar\n")
	if !m.sim {
		var err error
		if data, err = m.Download(ctx, wpCLIURL); err != nil {
			return err
		}
		sum, err := m.Download(ctx, wpCLIURL+".sha512")
		if err != nil {
			return err
		}
		got := sha512.Sum512(data)
		if want := firstField(string(sum)); want == "" || !strings.EqualFold(hex.EncodeToString(got[:]), want) {
			return errors.New("wp-cli.phar: SHA-512 checksum does not match the published one")
		}
	}
	t := txn.New(m.h)
	for _, d := range []string{toolsDir, toolsDir + "/wp-cli"} {
		if err := t.RootDir(d, 0o755, 0, 0); err != nil {
			return err
		}
	}
	return t.WriteOwned(wpCLIPhar(), data, 0o755, 0, 0)
}

// ScanWordPress looks for WordPress in a site's web root and two levels
// of folders below it.
func (m *Manager) ScanWordPress(ctx context.Context, t Target, root string) ([]WPFound, error) {
	if err := t.validate(); err != nil {
		return nil, err
	}
	if err := site.ValidateRelPath(root); err != nil {
		return nil, invalid("root: %s", err.Error())
	}
	found := []WPFound{}
	var walk func(rel string, depth int)
	walk = func(rel string, depth int) {
		abs := siteDir(t) + "/" + rel
		if m.exists(abs+"/wp-includes/version.php") && (m.exists(abs+"/wp-config.php") || m.exists(abs+"/../wp-config.php")) {
			v := ""
			if b, err := m.readFile(ctx, t, abs+"/wp-includes/version.php", 64<<10); err == nil {
				if mm := wpVersionRe.FindSubmatch(b); mm != nil {
					v = string(mm[1])
				}
			}
			found = append(found, WPFound{Path: rel, Version: v})
			return
		}
		if depth == 2 {
			return
		}
		entries, err := os.ReadDir(m.h.Path(abs))
		if err != nil {
			return
		}
		for _, e := range entries {
			// Symbolic links are the customer's; they are not followed.
			if !e.IsDir() || strings.HasPrefix(e.Name(), ".") || site.ValidateRelPath(e.Name()) != nil {
				continue
			}
			switch e.Name() {
			case "wp-admin", "wp-includes", "wp-content", "node_modules", "vendor", "cgi-bin":
				continue
			}
			walk(rel+"/"+e.Name(), depth+1)
			if len(found) >= 20 {
				return
			}
		}
	}
	walk(root, 0)
	return found, nil
}

// WordPressStatus reads an installation: versions and updates offered,
// plugins, themes, maintenance mode, administrators and hardening.
func (m *Manager) WordPressStatus(ctx context.Context, a App) (WPStatus, error) {
	if err := m.validateWP(a); err != nil {
		return WPStatus{}, err
	}
	if err := m.ensureWPCLI(ctx, nil); err != nil {
		return WPStatus{}, &OperationError{fmt.Errorf("WP-CLI could not be downloaded: %w", err)}
	}
	ctx, cancel := context.WithTimeout(ctx, shortTimeout)
	defer cancel()
	st := WPStatus{Plugins: []WPItem{}, Themes: []WPItem{}, Admins: []string{}, AutoUpdateCore: "minor"}
	if b, err := m.readFile(ctx, a.Target, a.dir()+"/wp-includes/version.php", 64<<10); err == nil {
		if mm := wpVersionRe.FindSubmatch(b); mm != nil {
			st.Version = string(mm[1])
		}
	}

	// A plugin or theme that breaks WordPress breaks WP-CLI too: the rest
	// is read without them, and the reason is reported.
	skip := false
	fields := "--fields=name,title,status,version,update_version,auto_update"
	plugins, err := m.wpItems(ctx, a, false, "plugin", fields)
	if err != nil {
		st.Problem = err.Error()
		skip = true
		if plugins, err = m.wpItems(ctx, a, true, "plugin", fields); err != nil {
			return st, &OperationError{fmt.Errorf("WordPress could not be loaded: %s", st.Problem)}
		}
	}
	st.Plugins = plugins
	if st.Themes, err = m.wpItems(ctx, a, skip, "theme", fields); err != nil {
		st.Themes = []WPItem{}
	}

	var updates []struct {
		Version string `json:"version"`
	}
	if err := m.wpJSON(ctx, a, true, &updates, "core", "check-update", "--format=json", "--fields=version"); err == nil {
		for _, u := range updates {
			if st.CoreUpdate == "" || wpVersionLess(st.CoreUpdate, u.Version) {
				st.CoreUpdate = u.Version
			}
		}
	}
	if st.CoreUpdate != "" && !wpVersionLess(st.Version, st.CoreUpdate) {
		st.CoreUpdate = ""
	}
	if out, err := m.wp(ctx, a, true, "option", "get", "siteurl"); err == nil {
		st.SiteURL = strings.TrimSpace(out)
	}
	if out, err := m.wp(ctx, a, true, "option", "get", "home"); err == nil {
		st.HomeURL = strings.TrimSpace(out)
	}
	st.Maintenance = m.exists(a.dir() + "/.maintenance")
	var admins []struct {
		Login string `json:"user_login"`
	}
	if err := m.wpJSON(ctx, a, true, &admins, "user", "list", "--role=administrator", "--fields=user_login", "--orderby=ID", "--format=json"); err == nil {
		for _, u := range admins {
			st.Admins = append(st.Admins, u.Login)
		}
	}
	config, _ := m.wpConfig(ctx, a)
	switch strings.ToLower(strings.Trim(config["WP_AUTO_UPDATE_CORE"], `'"`)) {
	case "true", "1":
		st.AutoUpdateCore = "all"
	case "false", "0":
		st.AutoUpdateCore = "off"
	}
	if v := strings.ToLower(config["AUTOMATIC_UPDATER_DISABLED"]); v == "true" || v == "1" {
		st.AutoUpdateCore = "off"
	}
	st.Database = strings.Trim(config["DB_NAME"], `'"`)
	st.Hardening = m.wpHardening(a, config)
	return st, nil
}

// wpItems lists plugins or themes.
func (m *Manager) wpItems(ctx context.Context, a App, skip bool, kind, fields string) ([]WPItem, error) {
	var rows []struct {
		Name       string `json:"name"`
		Title      string `json:"title"`
		Status     string `json:"status"`
		Version    string `json:"version"`
		Update     string `json:"update_version"`
		AutoUpdate string `json:"auto_update"`
	}
	if err := m.wpJSON(ctx, a, skip, &rows, kind, "list", fields, "--format=json"); err != nil {
		return nil, err
	}
	items := make([]WPItem, 0, len(rows))
	for _, r := range rows {
		items = append(items, WPItem{Name: r.Name, Title: r.Title, Version: r.Version, Update: r.Update, Status: r.Status, AutoUpdate: r.AutoUpdate == "on"})
	}
	sort.SliceStable(items, func(i, j int) bool { return strings.ToLower(items[i].Title) < strings.ToLower(items[j].Title) })
	return items, nil
}

// wpConfig reads the constants of wp-config.php (not the passwords).
func (m *Manager) wpConfig(ctx context.Context, a App) (map[string]string, error) {
	var rows []struct {
		Name  string `json:"name"`
		Value any    `json:"value"`
		Type  string `json:"type"`
	}
	out := map[string]string{}
	if err := m.wpJSON(ctx, a, true, &rows, "config", "list", "--format=json", "--fields=name,value,type"); err != nil {
		return out, err
	}
	for _, r := range rows {
		if r.Type != "constant" || strings.Contains(r.Name, "PASSWORD") || strings.HasSuffix(r.Name, "_KEY") || strings.HasSuffix(r.Name, "_SALT") {
			continue
		}
		out[r.Name] = fmt.Sprint(r.Value)
	}
	return out, nil
}

// wpVersionLess compares WordPress versions such as 6.8 and 6.8.3.
func wpVersionLess(a, b string) bool {
	pa, pb := strings.Split(a, "."), strings.Split(b, ".")
	for i := 0; i < len(pa) || i < len(pb); i++ {
		var x, y int
		if i < len(pa) {
			x, _ = strconv.Atoi(strings.SplitN(pa[i], "-", 2)[0])
		}
		if i < len(pb) {
			y, _ = strconv.Atoi(strings.SplitN(pb[i], "-", 2)[0])
		}
		if x != y {
			return x < y
		}
	}
	return false
}

// WPLoginSpec asks for a sign-in link; User is an administrator's login,
// empty for the first administrator.
type WPLoginSpec struct {
	App
	User string `json:"wp_user"`
}

// WPLogin is a one-time sign-in link to the dashboard.
type WPLogin struct {
	URL     string `json:"url"`
	User    string `json:"wp_user"`
	Expires int64  `json:"expires"`
}

// WordPressLogin makes a link that signs its first visitor in to the
// dashboard as an administrator, within wpLoginTTL. Only the token's
// SHA-256 is stored in WordPress; the link is used up on first use.
func (m *Manager) WordPressLogin(ctx context.Context, spec WPLoginSpec) (WPLogin, error) {
	if err := m.validateWP(spec.App); err != nil {
		return WPLogin{}, err
	}
	if spec.User != "" && !wpLoginRe.MatchString(spec.User) {
		return WPLogin{}, invalid("wp_user %q is not a WordPress login", spec.User)
	}
	if err := m.ensureWPCLI(ctx, nil); err != nil {
		return WPLogin{}, &OperationError{fmt.Errorf("WP-CLI could not be downloaded: %w", err)}
	}
	ctx, cancel := context.WithTimeout(ctx, shortTimeout)
	defer cancel()
	var admins []struct {
		ID    int    `json:"ID"`
		Login string `json:"user_login"`
	}
	if err := m.wpJSON(ctx, spec.App, true, &admins, "user", "list", "--role=administrator", "--fields=ID,user_login", "--orderby=ID", "--format=json"); err != nil {
		return WPLogin{}, &OperationError{fmt.Errorf("the administrators could not be read: %w", err)}
	}
	var id int
	var login string
	for _, u := range admins {
		if spec.User == "" || u.Login == spec.User {
			id, login = u.ID, u.Login
			break
		}
	}
	if id == 0 {
		return WPLogin{}, invalid("WordPress has no administrator %s", spec.User)
	}
	if err := m.writeLoginPlugin(ctx, spec.App); err != nil {
		return WPLogin{}, &OperationError{err}
	}
	token := make([]byte, 32)
	if _, err := rand.Read(token); err != nil {
		return WPLogin{}, err
	}
	raw := hex.EncodeToString(token)
	sum := sha256.Sum256([]byte(raw))
	expires := time.Now().Add(wpLoginTTL).Unix()
	value, _ := json.Marshal(map[string]any{"hash": hex.EncodeToString(sum[:]), "user": id, "expires": expires})
	if _, err := m.wp(ctx, spec.App, true, "option", "update", "serfex_login", string(value), "--format=json", "--autoload=no"); err != nil {
		return WPLogin{}, &OperationError{fmt.Errorf("the sign-in link could not be saved: %w", err)}
	}
	base, err := m.wp(ctx, spec.App, true, "option", "get", "siteurl")
	if err != nil {
		return WPLogin{}, &OperationError{err}
	}
	u, perr := url.Parse(strings.TrimSpace(base))
	if perr != nil || (u.Scheme != "http" && u.Scheme != "https") || u.Host == "" {
		return WPLogin{}, &OperationError{fmt.Errorf("the site address %q is not a web address", strings.TrimSpace(base))}
	}
	u.Path = strings.TrimSuffix(u.Path, "/") + "/wp-login.php"
	u.RawQuery = "serfex_login=" + raw
	return WPLogin{URL: u.String(), User: login, Expires: expires}, nil
}

// writeLoginPlugin puts the sign-in plugin in place when it is missing
// or outdated.
func (m *Manager) writeLoginPlugin(ctx context.Context, a App) error {
	abs := a.dir() + "/" + wpLoginPlugin
	if have, err := m.readFile(ctx, a.Target, abs, 64<<10); err == nil && bytes.Equal(have, wpLoginPHP) {
		return nil
	}
	if !m.isDir(a.dir() + "/wp-content") {
		return errors.New("this WordPress has no wp-content folder")
	}
	if _, err := m.userRun(ctx, a.Target, "/", "mkdir", "-p", a.dir()+"/wp-content/mu-plugins"); err != nil {
		return errors.New("creating wp-content/mu-plugins failed")
	}
	return m.writeFile(ctx, a.Target, abs, wpLoginPHP, "0644")
}

// WPMaintenanceSpec turns WordPress's maintenance mode on or off.
type WPMaintenanceSpec struct {
	App
	On bool `json:"on"`
}

// WordPressMaintenance switches maintenance mode: visitors see
// WordPress's "briefly unavailable" page until it is turned off.
func (m *Manager) WordPressMaintenance(ctx context.Context, spec WPMaintenanceSpec) (WPStatus, error) {
	if err := m.validateWP(spec.App); err != nil {
		return WPStatus{}, err
	}
	if err := m.ensureWPCLI(ctx, nil); err != nil {
		return WPStatus{}, &OperationError{err}
	}
	on := m.exists(spec.dir() + "/.maintenance")
	if on != spec.On {
		action := map[bool]string{true: "activate", false: "deactivate"}[spec.On]
		if _, err := m.wp(ctx, spec.App, true, "maintenance-mode", action); err != nil {
			return WPStatus{}, &OperationError{err}
		}
	}
	return WPStatus{Maintenance: spec.On}, nil
}

// WPItemSpec changes one plugin or theme.
type WPItemSpec struct {
	App
	// Kind is plugin or theme.
	Kind string `json:"kind"`
	Name string `json:"name"`
	// Action is activate or deactivate (plugins), activate (themes),
	// auto-update-on or auto-update-off.
	Action string `json:"action"`
}

// WordPressItem activates or deactivates a plugin, activates a theme or
// switches an item's automatic updates.
func (m *Manager) WordPressItem(ctx context.Context, spec WPItemSpec) (WPItem, error) {
	if err := m.validateWP(spec.App); err != nil {
		return WPItem{}, err
	}
	if spec.Kind != "plugin" && spec.Kind != "theme" {
		return WPItem{}, invalid("kind must be plugin or theme")
	}
	if !wpSlugRe.MatchString(spec.Name) {
		return WPItem{}, invalid("name %q is not a plugin or theme name", spec.Name)
	}
	var args []string
	// Turning something off works even while it breaks WordPress.
	skip := false
	switch {
	case spec.Action == "activate":
		args = []string{spec.Kind, "activate", spec.Name}
	case spec.Action == "deactivate" && spec.Kind == "plugin":
		args, skip = []string{"plugin", "deactivate", spec.Name}, true
	case spec.Action == "auto-update-on" || spec.Action == "auto-update-off":
		args, skip = []string{spec.Kind, "auto-updates", map[bool]string{true: "enable", false: "disable"}[spec.Action == "auto-update-on"], spec.Name}, true
	default:
		return WPItem{}, invalid("action %q is not possible for a %s", spec.Action, spec.Kind)
	}
	if err := m.ensureWPCLI(ctx, nil); err != nil {
		return WPItem{}, &OperationError{err}
	}
	ctx, cancel := context.WithTimeout(ctx, shortTimeout)
	defer cancel()
	if _, err := m.wp(ctx, spec.App, skip, args...); err != nil {
		return WPItem{}, &OperationError{err}
	}
	items, err := m.wpItems(ctx, spec.App, true, spec.Kind, "--fields=name,title,status,version,update_version,auto_update")
	if err != nil {
		return WPItem{}, &OperationError{err}
	}
	for _, it := range items {
		if it.Name == spec.Name {
			return it, nil
		}
	}
	return WPItem{}, invalid("there is no %s %s", spec.Kind, spec.Name)
}

// WPAutoUpdateSpec sets which WordPress releases install themselves.
type WPAutoUpdateSpec struct {
	App
	// Core is minor (security and maintenance releases, WordPress's
	// default), all or off.
	Core string `json:"core"`
}

// WordPressAutoUpdate writes WP_AUTO_UPDATE_CORE to wp-config.php.
func (m *Manager) WordPressAutoUpdate(ctx context.Context, spec WPAutoUpdateSpec) (WPStatus, error) {
	if err := m.validateWP(spec.App); err != nil {
		return WPStatus{}, err
	}
	value, ok := map[string]string{"minor": "'minor'", "all": "true", "off": "false"}[spec.Core]
	if !ok {
		return WPStatus{}, invalid("core must be minor, all or off")
	}
	if err := m.ensureWPCLI(ctx, nil); err != nil {
		return WPStatus{}, &OperationError{err}
	}
	ctx, cancel := context.WithTimeout(ctx, shortTimeout)
	defer cancel()
	if _, err := m.wp(ctx, spec.App, true, "config", "set", "WP_AUTO_UPDATE_CORE", value, "--raw", "--type=constant"); err != nil {
		return WPStatus{}, &OperationError{err}
	}
	// An earlier "off" may have come from the updater being disabled.
	if spec.Core != "off" {
		if _, err := m.wp(ctx, spec.App, true, "config", "has", "AUTOMATIC_UPDATER_DISABLED", "--type=constant"); err == nil {
			if _, err := m.wp(ctx, spec.App, true, "config", "delete", "AUTOMATIC_UPDATER_DISABLED", "--type=constant"); err != nil {
				return WPStatus{}, &OperationError{err}
			}
		}
	}
	return WPStatus{AutoUpdateCore: spec.Core}, nil
}

// WordPressDetach stops managing an installation: the sign-in plugin,
// the hardening rules and the restore points go; WordPress stays.
func (m *Manager) WordPressDetach(ctx context.Context, a App) error {
	if err := a.validate(); err != nil {
		return err
	}
	unlock := m.lock(a.Domain)
	defer unlock()
	if m.exists(a.dir() + "/" + wpLoginPlugin) {
		if _, err := m.userRun(ctx, a.Target, "/", "rm", "-f", a.dir()+"/"+wpLoginPlugin); err != nil {
			return &OperationError{errors.New("removing the sign-in plugin failed")}
		}
	}
	if m.Snippet != nil {
		if err := m.Snippet(ctx, a.Domain, wpSnippet, nil); err != nil {
			return &OperationError{err}
		}
	}
	if err := os.RemoveAll(m.h.Path(wpStateDir + "/" + a.Domain + "/restore")); err != nil {
		return &OperationError{err}
	}
	return nil
}

// ReadWordPressRun returns the log of an install, update or restore.
func (m *Manager) ReadWordPressRun(domain, run string, offset int64) (RunChunk, error) {
	if err := (Target{Domain: domain, User: "x"}).validate(); err != nil {
		return RunChunk{}, err
	}
	if err := validateID("run", run); err != nil {
		return RunChunk{}, err
	}
	return m.readChunk(wpRunDir(domain)+"/"+run+".log", offset, false)
}
