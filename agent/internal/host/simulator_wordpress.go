package host

import (
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
)

// WP-CLI, simulated well enough for the WordPress toolkit's dry runs and
// tests: "core download" writes a small WordPress, and the rest reads and
// changes its state, kept in the folder's .serfex-sim-wp.json.

// SimWPState is the file a simulated WordPress keeps its state in.
const SimWPState = ".serfex-sim-wp.json"

// SimWP is the state of a simulated WordPress. Tests may write one to
// make a site look like it has WordPress.
type SimWP struct {
	Installed  bool              `json:"installed"`
	Version    string            `json:"version"`
	CoreUpdate string            `json:"core_update"`
	Plugins    []SimWPItem       `json:"plugins"`
	Themes     []SimWPItem       `json:"themes"`
	Admins     []string          `json:"admins"`
	Constants  map[string]string `json:"constants"`
	Options    map[string]string `json:"options"`
	// Broken makes WordPress fail unless plugins are skipped, as a
	// plugin with a fatal error would.
	Broken string `json:"broken,omitempty"`
}

// SimWPItem is a plugin or theme of a simulated WordPress.
type SimWPItem struct {
	Name       string `json:"name"`
	Title      string `json:"title"`
	Status     string `json:"status"`
	Version    string `json:"version"`
	Update     string `json:"update_version"`
	AutoUpdate string `json:"auto_update"`
}

var (
	simWPVersion = regexp.MustCompile(`\$wp_version\s*=\s*'([^']+)'`)
	simWPDefine  = regexp.MustCompile(`define\( '([A-Z0-9_]+)', (.+) \);`)
)

// NewSimWP is what "core download" gives: WordPress 6.8.3, with 6.9 and
// an Akismet update on offer.
func NewSimWP() SimWP {
	return SimWP{
		Version: "6.8.3", CoreUpdate: "6.9",
		Plugins: []SimWPItem{
			{Name: "akismet", Title: "Akismet Anti-spam: Spam Protection", Status: "inactive", Version: "5.5", Update: "5.6", AutoUpdate: "off"},
			{Name: "hello", Title: "Hello Dolly", Status: "inactive", Version: "1.7.2", AutoUpdate: "off"},
		},
		Themes: []SimWPItem{
			{Name: "twentytwentyfive", Title: "Twenty Twenty-Five", Status: "active", Version: "1.3", AutoUpdate: "off"},
			{Name: "twentytwentyfour", Title: "Twenty Twenty-Four", Status: "inactive", Version: "1.3", Update: "1.4", AutoUpdate: "off"},
		},
		Constants: map[string]string{},
		Options:   map[string]string{},
	}
}

// WriteSimWP writes a simulated WordPress into dir (a path under Root).
func (s *Simulator) WriteSimWP(dir string, wp SimWP) error {
	files := map[string]string{
		"index.php":                    "<?php\ndefine('WP_USE_THEMES', true);\nrequire __DIR__ . '/wp-blog-header.php';\n",
		"wp-login.php":                 "<?php\n",
		"wp-blog-header.php":           "<?php\n",
		"wp-config-sample.php":         "<?php\n",
		"xmlrpc.php":                   "<?php\n",
		"license.txt":                  "WordPress - Web publishing software\n",
		"readme.html":                  "<!DOCTYPE html><title>WordPress</title>\n",
		"wp-admin/index.php":           "<?php\n",
		"wp-content/index.php":         "<?php\n// Silence is golden.\n",
		"wp-content/plugins/hello.php": "<?php\n/* Plugin Name: Hello Dolly */\n",
	}
	for _, p := range wp.Plugins {
		if p.Name != "hello" {
			files["wp-content/plugins/"+p.Name+"/"+p.Name+".php"] = "<?php\n/* Plugin Name: " + p.Title + " */\n"
		}
	}
	for _, t := range wp.Themes {
		files["wp-content/themes/"+t.Name+"/style.css"] = "/* Theme Name: " + t.Title + " */\n"
	}
	for name, content := range files {
		p := s.Path(dir + "/" + name)
		if err := os.MkdirAll(filepath.Dir(p), 0o755); err != nil {
			return err
		}
		if err := os.WriteFile(p, []byte(content), 0o644); err != nil {
			return err
		}
	}
	return s.saveSimWP(dir, wp)
}

func (s *Simulator) saveSimWP(dir string, wp SimWP) error {
	if err := os.WriteFile(s.Path(dir+"/wp-includes/version.php"), []byte("<?php\n$wp_version = '"+wp.Version+"';\n"), 0o644); err != nil {
		if err := os.MkdirAll(s.Path(dir+"/wp-includes"), 0o755); err != nil {
			return err
		}
		if err := os.WriteFile(s.Path(dir+"/wp-includes/version.php"), []byte("<?php\n$wp_version = '"+wp.Version+"';\n"), 0o644); err != nil {
			return err
		}
	}
	if len(wp.Constants) > 0 {
		var b strings.Builder
		b.WriteString("<?php\n")
		keys := make([]string, 0, len(wp.Constants))
		for k := range wp.Constants {
			keys = append(keys, k)
		}
		sort.Strings(keys)
		for _, k := range keys {
			fmt.Fprintf(&b, "define( '%s', %s );\n", k, wp.Constants[k])
		}
		b.WriteString("require_once ABSPATH . 'wp-settings.php';\n")
		mode := os.FileMode(0o644)
		if fi, err := os.Stat(s.Path(dir + "/wp-config.php")); err == nil {
			mode = fi.Mode().Perm()
		}
		if err := os.WriteFile(s.Path(dir+"/wp-config.php"), []byte(b.String()), mode); err != nil {
			return err
		}
	}
	data, _ := json.MarshalIndent(wp, "", "  ")
	return os.WriteFile(s.Path(dir+"/"+SimWPState), data, 0o644)
}

func (s *Simulator) loadSimWP(dir string) (SimWP, error) {
	b, err := os.ReadFile(s.Path(dir + "/" + SimWPState))
	if err != nil {
		return SimWP{}, errors.New("Error: This does not seem to be a WordPress installation.")
	}
	var wp SimWP
	if err := json.Unmarshal(b, &wp); err != nil {
		return SimWP{}, err
	}
	if wp.Constants == nil {
		wp.Constants = map[string]string{}
	}
	if wp.Options == nil {
		wp.Options = map[string]string{}
	}
	// wp-config.php is the truth for constants: a file changed by hand
	// (or by a copy) wins over the saved state.
	if b, err := os.ReadFile(s.Path(dir + "/wp-config.php")); err == nil {
		for _, m := range simWPDefine.FindAllSubmatch(b, -1) {
			wp.Constants[string(m[1])] = string(m[2])
		}
	}
	if v, err := os.ReadFile(s.Path(dir + "/wp-includes/version.php")); err == nil {
		if m := simWPVersion.FindSubmatch(v); m != nil {
			wp.Version = string(m[1])
		}
	}
	return wp, nil
}

// wpCLI simulates "php ... wp-cli.phar ARGS".
func (s *Simulator) wpCLI(c Command, cwd string, args []string) (string, error) {
	dir, skip := cwd, false
	var rest []string
	assoc := map[string]string{}
	for _, a := range args {
		switch {
		case strings.HasPrefix(a, "--path="):
			dir = strings.TrimPrefix(a, "--path=")
		case a == "--skip-plugins" || a == "--skip-themes":
			skip = true
		case a == "--no-color":
		case strings.HasPrefix(a, "--"):
			k, v, _ := strings.Cut(strings.TrimPrefix(a, "--"), "=")
			assoc[k] = v
		default:
			rest = append(rest, a)
		}
	}
	out := func(text string) (string, error) {
		if c.Stdout != nil {
			_, err := io.WriteString(c.Stdout, text)
			return "", err
		}
		return text, nil
	}
	cmd := strings.Join(rest, " ")
	if _, ok := assoc["version"]; ok && len(rest) == 0 {
		return out("WP-CLI 2.12.0\n")
	}
	if strings.HasPrefix(cmd, "core download") {
		if _, err := os.Stat(s.Path(dir + "/wp-includes/version.php")); err == nil {
			return "", errors.New("Error: WordPress files seem to already be present here.")
		}
		return "Downloading WordPress 6.8.3 (" + assoc["locale"] + ")...\nSuccess: WordPress downloaded.\n", s.WriteSimWP(dir, NewSimWP())
	}
	wp, err := s.loadSimWP(dir)
	if err != nil {
		return "", err
	}
	if wp.Broken != "" && !skip && len(rest) > 0 && rest[0] != "config" && rest[0] != "db" && rest[0] != "maintenance-mode" {
		return "", errors.New(wp.Broken)
	}
	items := func(kind string) *[]SimWPItem {
		if kind == "theme" {
			return &wp.Themes
		}
		return &wp.Plugins
	}
	switch {
	case cmd == "config create":
		for k, v := range map[string]string{"DB_NAME": assoc["dbname"], "DB_USER": assoc["dbuser"], "DB_HOST": assoc["dbhost"]} {
			wp.Constants[k] = "'" + v + "'"
		}
		wp.Constants["DB_PASSWORD"] = "'" + strings.TrimSpace(string(c.Stdin)) + "'"
		wp.Options["table_prefix"] = assoc["dbprefix"]
		return "Success: Generated 'wp-config.php' file.\n", s.saveSimWP(dir, wp)
	case cmd == "core install":
		wp.Installed = true
		wp.Admins = []string{assoc["admin_user"]}
		wp.Options["siteurl"], wp.Options["home"], wp.Options["blogname"] = assoc["url"], assoc["url"], assoc["title"]
		return "Success: WordPress installed successfully.\n", s.saveSimWP(dir, wp)
	case cmd == "core is-installed":
		if !wp.Installed {
			return "", errors.New("")
		}
	case cmd == "core check-update":
		if wp.CoreUpdate == "" {
			return out("Success: WordPress is at the latest version.\n")
		}
		return out(`[{"version":"` + wp.CoreUpdate + `","update_type":"major"}]` + "\n")
	case cmd == "core update":
		if wp.CoreUpdate == "" {
			return "Success: WordPress is up to date.\n", nil
		}
		from := wp.Version
		wp.Version, wp.CoreUpdate = wp.CoreUpdate, ""
		return "Updating to version " + wp.Version + " from " + from + "...\nUnpacking the update...\nSuccess: WordPress updated successfully.\n", s.saveSimWP(dir, wp)
	case cmd == "core update-db":
		return "Success: WordPress database upgraded successfully from db version 58975 to 60421.\n", nil
	case strings.HasPrefix(cmd, "language "):
		return "Success: Translations are up to date.\n", nil
	case cmd == "plugin list" || cmd == "theme list":
		list := *items(rest[0])
		if list == nil {
			list = []SimWPItem{}
		}
		b, _ := json.Marshal(list)
		return out(string(b) + "\n")
	case len(rest) >= 2 && (rest[0] == "plugin" || rest[0] == "theme") && rest[1] == "update":
		var b strings.Builder
		list := items(rest[0])
		for i := range *list {
			it := &(*list)[i]
			if it.Update != "" && (assoc["all"] != "" || contains(rest[2:], it.Name)) {
				fmt.Fprintf(&b, "Updating %s %s → %s...\n", it.Name, it.Version, it.Update)
				it.Version, it.Update = it.Update, ""
			}
		}
		b.WriteString("Success: Updated.\n")
		return b.String(), s.saveSimWP(dir, wp)
	case len(rest) == 3 && (rest[1] == "activate" || rest[1] == "deactivate"):
		list := items(rest[0])
		found := false
		for i := range *list {
			it := &(*list)[i]
			switch {
			case it.Name == rest[2]:
				found = true
				it.Status = map[string]string{"activate": "active", "deactivate": "inactive"}[rest[1]]
			case rest[0] == "theme" && rest[1] == "activate" && it.Status == "active":
				it.Status = "inactive"
			}
		}
		if !found {
			return "", fmt.Errorf("Error: The '%s' %s could not be found.", rest[2], rest[0])
		}
		return "Success: done.\n", s.saveSimWP(dir, wp)
	case len(rest) == 4 && rest[1] == "auto-updates":
		list := items(rest[0])
		for i := range *list {
			if (*list)[i].Name == rest[3] {
				(*list)[i].AutoUpdate = map[string]string{"enable": "on", "disable": "off"}[rest[2]]
			}
		}
		return "Success: done.\n", s.saveSimWP(dir, wp)
	case len(rest) >= 3 && rest[0] == "search-replace":
		n := 0
		for _, m := range []map[string]string{wp.Options, wp.Constants} {
			for k, v := range m {
				if strings.Contains(v, rest[1]) {
					m[k] = strings.ReplaceAll(v, rest[1], rest[2])
					n++
				}
			}
		}
		return fmt.Sprintf("Success: Made %d replacements.\n", n), s.saveSimWP(dir, wp)
	case cmd == "cache flush":
		return "Success: The cache was flushed.\n", nil
	case cmd == "maintenance-mode activate":
		return "Success: Activated Maintenance mode.\n", os.WriteFile(s.Path(dir+"/.maintenance"), []byte("<?php $upgrading = time(); ?>"), 0o644)
	case cmd == "maintenance-mode deactivate":
		return "Success: Deactivated Maintenance mode.\n", os.Remove(s.Path(dir + "/.maintenance"))
	case cmd == "maintenance-mode is-active":
		if _, err := os.Stat(s.Path(dir + "/.maintenance")); err != nil {
			return "", errors.New("")
		}
	case strings.HasPrefix(cmd, "rewrite "):
		return "Success: Rewrite structure set.\n", nil
	case len(rest) == 3 && rest[0] == "option" && rest[1] == "get":
		v, ok := wp.Options[rest[2]]
		if !ok {
			return "", fmt.Errorf("Error: Could not get '%s' option. Does it exist?", rest[2])
		}
		return out(v + "\n")
	case len(rest) == 4 && rest[0] == "option" && rest[1] == "update":
		wp.Options[rest[2]] = rest[3]
		return "Success: Updated '" + rest[2] + "' option.\n", s.saveSimWP(dir, wp)
	case len(rest) == 3 && rest[0] == "config" && rest[1] == "has":
		if _, ok := wp.Constants[rest[2]]; !ok {
			return "", errors.New("")
		}
	case len(rest) == 4 && rest[0] == "config" && rest[1] == "set":
		v := rest[3]
		if _, raw := assoc["raw"]; !raw {
			v = "'" + v + "'"
		}
		wp.Constants[rest[2]] = v
		return "Success: Updated the constant '" + rest[2] + "' in the 'wp-config.php' file.\n", s.saveSimWP(dir, wp)
	case len(rest) == 3 && rest[0] == "config" && rest[1] == "delete":
		delete(wp.Constants, rest[2])
		return "Success: Deleted the constant '" + rest[2] + "' from the 'wp-config.php' file.\n", s.saveSimWP(dir, wp)
	case cmd == "config list":
		var rows []map[string]string
		for k, v := range wp.Constants {
			rows = append(rows, map[string]string{"name": k, "value": strings.Trim(v, "'"), "type": "constant"})
		}
		sort.Slice(rows, func(i, j int) bool { return rows[i]["name"] < rows[j]["name"] })
		b, _ := json.Marshal(rows)
		return out(string(b) + "\n")
	case cmd == "user list":
		var rows []map[string]any
		for i, a := range wp.Admins {
			rows = append(rows, map[string]any{"ID": i + 1, "user_login": a})
		}
		b, _ := json.Marshal(rows)
		if rows == nil {
			b = []byte("[]")
		}
		return out(string(b) + "\n")
	case cmd == "db export -":
		return out("-- Simulated WordPress database\nCREATE TABLE wp_options (option_id bigint, option_name varchar(191));\n")
	case cmd == "db import -":
		if c.Input != nil {
			_, _ = io.Copy(io.Discard, c.Input)
		}
		return "Success: Imported from 'STDIN'.\n", nil
	}
	return "", nil
}

func contains(list []string, s string) bool {
	for _, v := range list {
		if v == s {
			return true
		}
	}
	return false
}
