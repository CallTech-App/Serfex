// Package panel describes how an installed Servik panel is laid out on
// the server. The installer (install.sh) writes Config; the updater, the
// panel's own web server (servik-web, servik-php) and Yazılım Yönetimi
// read it.
//
// Layout of a server installed from a release:
//
//	/opt/servik/releases/<version>/      one release: VERSION, bin/servik-agent, panel/
//	/opt/servik/current                  symlink to the release that runs
//	/etc/servik/panel.json               Config
//	/etc/servik/panel.env                the panel's .env (root:servik 0640), linked into each release
//	/var/lib/servik/panel/storage        the panel's storage directory, linked into each release
//
// The panel runs as the servik account under servik-php (PHP-FPM) and
// servik-web (Nginx) on port 8443, with its queue workers and scheduler
// as the servik-panel-* units. Releases never hold state, so switching
// the current symlink switches the code and nothing else.
package panel

import (
	"encoding/json"
	"errors"
	"os"
	"strings"

	"github.com/winchester37/servik/agent/internal/host"
)

const (
	OptDir      = "/opt/servik"
	ReleasesDir = OptDir + "/releases"
	CurrentLink = OptDir + "/current"
	ConfigFile  = "/etc/servik/panel.json"
	EnvFile     = "/etc/servik/panel.env"
	StateDir    = "/var/lib/servik/panel"
	StorageDir  = StateDir + "/storage"

	// User runs the panel's PHP, queue workers and scheduler.
	User = "servik"
	// Socket is the panel's PHP-FPM pool in servik-php.
	Socket = "/run/servik-php/panel.sock"

	// DefaultSource is where releases are published: GitHub's API for the
	// repository. A mirror serving the same /releases JSON works too.
	DefaultSource = "https://api.github.com/repos/Winchester37/Servik"
)

// Units are the panel's own services besides servik-web and servik-php.
var Units = []string{"servik-panel-queue", "servik-panel-backups", "servik-panel-scheduler"}

// Config is what the installer chose.
type Config struct {
	// PHP is the PHP version the panel runs on (servik-php uses it).
	PHP string `json:"php"`
	// Source is the release source: GitHub's API URL of the repository.
	Source string `json:"source,omitempty"`
	// Hostname is the panel's address, for the health check's Host header.
	Hostname string `json:"hostname,omitempty"`
}

// Load reads the configuration. ok is false when the panel was not
// installed from a release (a development setup).
func Load(h host.Host) (Config, bool) {
	data, err := os.ReadFile(h.Path(ConfigFile))
	if err != nil {
		return Config{}, false
	}
	var c Config
	if err := json.Unmarshal(data, &c); err != nil || c.PHP == "" {
		return Config{}, false
	}
	if c.Source == "" {
		c.Source = DefaultSource
	}
	return c, true
}

// Installed reports whether a release runs from CurrentLink.
func Installed(h host.Host) bool {
	_, err := os.Stat(h.Path(CurrentLink + "/panel/public/index.php"))
	return err == nil
}

// CurrentVersion is the version of the release CurrentLink points at.
func CurrentVersion(h host.Host) (string, error) {
	data, err := os.ReadFile(h.Path(CurrentLink + "/VERSION"))
	if errors.Is(err, os.ErrNotExist) {
		return "", nil
	}
	return strings.TrimSpace(string(data)), err
}

// Env reads the panel's .env as name => value (quotes removed).
func Env(h host.Host) (map[string]string, error) {
	data, err := os.ReadFile(h.Path(EnvFile))
	if err != nil {
		return nil, err
	}
	out := map[string]string{}
	for _, line := range strings.Split(string(data), "\n") {
		line = strings.TrimSpace(line)
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		k, v, ok := strings.Cut(line, "=")
		if !ok {
			continue
		}
		v = strings.TrimSpace(v)
		if len(v) >= 2 && (v[0] == '"' && v[len(v)-1] == '"' || v[0] == '\'' && v[len(v)-1] == '\'') {
			v = v[1 : len(v)-1]
		}
		out[strings.TrimSpace(k)] = v
	}
	return out, nil
}
