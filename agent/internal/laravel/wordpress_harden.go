package laravel

import (
	"context"
	"errors"
	"fmt"
	"os"
	"regexp"
	"strings"

	"github.com/calltech-app/serfex/agent/internal/site"
	"github.com/calltech-app/serfex/agent/internal/txn"
)

// Hardening of a WordPress installation. Two rules live in WordPress
// itself (wp-config.php), three in the site's Nginx snippet, which Nginx
// reads in front of PHP whatever the site's web server is.

// WPRules are the hardening rules, in the order the panel lists them.
var WPRules = []string{"file_edit", "config_perms", "uploads_php", "sensitive_files", "xmlrpc"}

// WPRecommended are the rules a new installation gets. XML-RPC stays
// open: the WordPress apps and Jetpack use it.
var WPRecommended = []string{"file_edit", "config_perms", "uploads_php", "sensitive_files"}

var nginxRules = map[string]bool{"uploads_php": true, "sensitive_files": true, "xmlrpc": true}

// webPathRe is the address of the WordPress folder: / or /blog/.
var webPathRe = regexp.MustCompile(`^/([A-Za-z0-9_][A-Za-z0-9._-]*/)*$`)

// WPHardenSpec turns hardening rules on or off; rules not named stay as
// they are.
type WPHardenSpec struct {
	App
	// WebPath is the address of the folder on the site, / or /blog/.
	WebPath string          `json:"web_path"`
	Rules   map[string]bool `json:"rules"`
}

// WordPressHarden applies hardening rules and returns the state of all.
func (m *Manager) WordPressHarden(ctx context.Context, spec WPHardenSpec) (WPStatus, error) {
	if err := m.validateWP(spec.App); err != nil {
		return WPStatus{}, err
	}
	if !webPathRe.MatchString(spec.WebPath) || len(spec.WebPath) > 200 {
		return WPStatus{}, invalid("web_path %q must look like / or /blog/", spec.WebPath)
	}
	for r := range spec.Rules {
		if !contains(WPRules, r) {
			return WPStatus{}, invalid("unknown hardening rule %q", r)
		}
	}
	if err := m.ensureWPCLI(ctx, nil); err != nil {
		return WPStatus{}, &OperationError{err}
	}
	unlock := m.lock(spec.Domain)
	defer unlock()
	ctx, cancel := context.WithTimeout(ctx, shortTimeout)
	defer cancel()
	if err := m.harden(ctx, spec); err != nil {
		var v *ValidationError
		if errors.As(err, &v) {
			return WPStatus{}, err
		}
		return WPStatus{}, &OperationError{err}
	}
	config, _ := m.wpConfig(ctx, spec.App)
	return WPStatus{Hardening: m.wpHardening(spec.App, config)}, nil
}

func (m *Manager) harden(ctx context.Context, spec WPHardenSpec) error {
	a := spec.App
	if on, ok := spec.Rules["file_edit"]; ok {
		var err error
		if on {
			_, err = m.wp(ctx, a, true, "config", "set", "DISALLOW_FILE_EDIT", "true", "--raw", "--type=constant")
		} else if _, herr := m.wp(ctx, a, true, "config", "has", "DISALLOW_FILE_EDIT", "--type=constant"); herr == nil {
			_, err = m.wp(ctx, a, true, "config", "delete", "DISALLOW_FILE_EDIT", "--type=constant")
		}
		if err != nil {
			return fmt.Errorf("wp-config.php could not be changed: %w", err)
		}
	}
	if on, ok := spec.Rules["config_perms"]; ok {
		file := m.wpConfigFile(a)
		if file == "" {
			return invalid("there is no wp-config.php")
		}
		mode := map[bool]string{true: "600", false: "644"}[on]
		if _, err := m.userRun(ctx, a.Target, "/", "chmod", mode, file); err != nil {
			return fmt.Errorf("chmod %s wp-config.php failed", mode)
		}
	}
	state := m.nginxHardening(a.Domain)
	changed := false
	for r, on := range spec.Rules {
		if nginxRules[r] && state[r] != on {
			state[r], changed = on, true
		}
	}
	if !changed {
		return nil
	}
	if m.Snippet == nil {
		return errors.New("the site's Nginx rules cannot be written")
	}
	return m.Snippet(ctx, a.Domain, wpSnippet, wpNginxConf(a.Domain, spec.WebPath, state))
}

// wpNginxConf is the site's snippet for the rules that are on; nil when
// none is.
func wpNginxConf(domain, webPath string, on map[string]bool) []byte {
	if !on["uploads_php"] && !on["sensitive_files"] && !on["xmlrpc"] {
		return nil
	}
	p := regexp.QuoteMeta(webPath)
	var b strings.Builder
	fmt.Fprintf(&b, "# %s. Manual changes are overwritten.\n# WordPress hardening of %s at %s (WordPress → Security).\n", txn.Marker, domain, webPath)
	if on["uploads_php"] {
		fmt.Fprintf(&b, "\n# rule: uploads_php\n# Nothing uploaded runs as PHP.\nlocation ~* \"^%swp-content/uploads/.*\\.(php[0-9]?|phtml|phar)$\" {\n    deny all;\n}\n", p)
	}
	if on["sensitive_files"] {
		fmt.Fprintf(&b, "\n# rule: sensitive_files\n# Settings, logs and files that tell the WordPress version.\nlocation ~* \"^%s(wp-config\\.php|wp-config-sample\\.php|readme\\.html|license\\.txt|wp-content/debug\\.log)$\" {\n    deny all;\n}\n", p)
	}
	if on["xmlrpc"] {
		fmt.Fprintf(&b, "\n# rule: xmlrpc\nlocation = %sxmlrpc.php {\n    deny all;\n}\n", webPath)
	}
	return []byte(b.String())
}

// nginxHardening reads which rules the site's snippet holds.
func (m *Manager) nginxHardening(domain string) map[string]bool {
	state := map[string]bool{}
	b, err := os.ReadFile(m.h.Path(site.ExtraDir(domain) + "/" + wpSnippet))
	if err != nil {
		return state
	}
	for _, line := range strings.Split(string(b), "\n") {
		if r, ok := strings.CutPrefix(line, "# rule: "); ok && nginxRules[strings.TrimSpace(r)] {
			state[strings.TrimSpace(r)] = true
		}
	}
	return state
}

// wpHardening is the state of every rule.
func (m *Manager) wpHardening(a App, config map[string]string) map[string]bool {
	state := m.nginxHardening(a.Domain)
	for _, r := range WPRules {
		state[r] = state[r]
	}
	v := strings.ToLower(config["DISALLOW_FILE_EDIT"])
	state["file_edit"] = v == "true" || v == "1"
	if file := m.wpConfigFile(a); file != "" {
		if fi, err := os.Stat(m.h.Path(file)); err == nil {
			state["config_perms"] = fi.Mode().Perm()&0o007 == 0
		}
	}
	return state
}

// wpConfigFile is wp-config.php, which may sit one folder up; "" when
// there is none.
func (m *Manager) wpConfigFile(a App) string {
	for _, f := range []string{a.dir() + "/wp-config.php", a.dir() + "/../wp-config.php"} {
		if fi, err := os.Lstat(m.h.Path(f)); err == nil && fi.Mode().IsRegular() {
			return f
		}
	}
	return ""
}
