// Package tuning holds the server-wide service settings the panel's
// Hizmetler page changes (Nginx limits, the database server's connections
// and buffers, Postfix's message size). They are kept in one file so that
// the packages that write those services' configuration (site, mail,
// service) agree on them.
//
//	/etc/servik/services.json              the settings
//	/etc/nginx/conf.d/servik-tuning.conf   Nginx http-level limits
package tuning

import (
	"encoding/json"
	"fmt"
	"os"

	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/txn"
)

const (
	// File holds the settings.
	File = "/etc/servik/services.json"
	// NginxConf holds the http-level Nginx settings every site inherits.
	NginxConf = "/etc/nginx/conf.d/servik-tuning.conf"
)

// Settings are the service settings the panel can change.
type Settings struct {
	Nginx    Nginx    `json:"nginx"`
	Database Database `json:"database"`
	Postfix  Postfix  `json:"postfix"`
}

// Nginx settings.
type Nginx struct {
	// WorkerProcesses is "auto" (one per CPU core) or a number.
	WorkerProcesses   string `json:"worker_processes"`
	WorkerConnections int    `json:"worker_connections"`
	// ClientMaxBodyMB is the largest request body (upload); a site whose
	// PHP post_max_size is larger gets its own limit.
	ClientMaxBodyMB  int `json:"client_max_body_mb"`
	KeepaliveTimeout int `json:"keepalive_timeout"`
}

// Database settings of the MySQL or MariaDB server customers use.
type Database struct {
	MaxConnections     int `json:"max_connections"`
	BufferPoolMB       int `json:"innodb_buffer_pool_mb"`
	MaxAllowedPacketMB int `json:"max_allowed_packet_mb"`
}

// Postfix settings.
type Postfix struct {
	MessageSizeMB int `json:"message_size_mb"`
}

// Defaults are the settings before anything was changed: Nginx and the
// database server keep their packages' defaults, uploads up to 64 MB and
// mail up to 50 MB.
func Defaults() Settings {
	return Settings{
		Nginx:    Nginx{WorkerProcesses: "auto", WorkerConnections: 768, ClientMaxBodyMB: 64, KeepaliveTimeout: 65},
		Database: Database{MaxConnections: 151, BufferPoolMB: 128, MaxAllowedPacketMB: 64},
		Postfix:  Postfix{MessageSizeMB: 50},
	}
}

// Load reads the settings; missing ones are the defaults.
func Load(h host.Host) Settings {
	s := Defaults()
	if data, err := os.ReadFile(h.Path(File)); err == nil {
		_ = json.Unmarshal(data, &s)
	}
	d := Defaults()
	if s.Nginx.WorkerProcesses == "" {
		s.Nginx.WorkerProcesses = d.Nginx.WorkerProcesses
	}
	if s.Nginx.WorkerConnections <= 0 {
		s.Nginx.WorkerConnections = d.Nginx.WorkerConnections
	}
	if s.Nginx.ClientMaxBodyMB <= 0 {
		s.Nginx.ClientMaxBodyMB = d.Nginx.ClientMaxBodyMB
	}
	if s.Nginx.KeepaliveTimeout <= 0 {
		s.Nginx.KeepaliveTimeout = d.Nginx.KeepaliveTimeout
	}
	if s.Database.MaxConnections <= 0 {
		s.Database = d.Database
	}
	if s.Postfix.MessageSizeMB <= 0 {
		s.Postfix = d.Postfix
	}
	return s
}

// Save writes the settings in a transaction.
func Save(t *txn.Tx, s Settings) error {
	if err := t.RootDir("/etc/servik", 0o755, 0, 0); err != nil {
		return err
	}
	data, _ := json.MarshalIndent(s, "", "  ")
	return t.WriteOwned(File, append(data, '\n'), 0o644, 0, 0)
}

// WriteNginx writes the http-level Nginx settings.
func WriteNginx(t *txn.Tx, n Nginx) error {
	if err := t.RootDir("/etc/nginx/conf.d", 0o755, 0, 0); err != nil {
		return err
	}
	return t.WriteFile(NginxConf, NginxContent(n))
}

// NginxContent is the http-level configuration for n.
func NginxContent(n Nginx) []byte {
	return fmt.Appendf(nil, `# %s. Change these on the panel's Hizmetler page.
# Every site inherits them; a site whose PHP accepts larger uploads sets
# its own client_max_body_size.
client_max_body_size %dm;
`, txn.Marker, n.ClientMaxBodyMB)
}
