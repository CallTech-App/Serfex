// Package tuning holds the server-wide service settings the panel's
// Hizmetler page changes (Nginx and Apache limits, the database server's
// connections and buffers, Postfix's message size, PowerDNS's caches,
// query log and zone transfers). They are kept in one file so that the
// packages that write those services' configuration (site, mail, dns,
// component, service) agree on them.
//
//	/etc/servik/services.json                 the settings
//	/etc/nginx/conf.d/servik-tuning.conf      Nginx http-level limits
//	/etc/apache2/conf-available/servik.conf   Apache's server-wide settings
package tuning

import (
	"bytes"
	"encoding/json"
	"fmt"
	"os"
	"regexp"
	"strconv"
	"strings"

	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/txn"
)

const (
	// File holds the settings.
	File = "/etc/servik/services.json"
	// NginxConf holds the http-level Nginx settings every site inherits.
	NginxConf = "/etc/nginx/conf.d/servik-tuning.conf"
	// ApacheConf holds Servik's server-wide Apache settings. Apache reads
	// it (through conf-enabled) after the modules' own files, so its MPM
	// limits win over mpm_event.conf.
	ApacheConf = "/etc/apache2/conf-available/servik.conf"
)

// Settings are the service settings the panel can change.
type Settings struct {
	Nginx    Nginx    `json:"nginx"`
	Database Database `json:"database"`
	Postfix  Postfix  `json:"postfix"`
	Apache   Apache   `json:"apache"`
	PowerDNS PowerDNS `json:"powerdns"`
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

// Apache settings. Apache runs behind Nginx, which opens a new connection
// for every request it hands over and waits for the answer up to 300
// seconds, so there is no keep-alive to tune and a longer timeout would
// not help.
type Apache struct {
	// MaxRequestWorkers is how many requests Apache serves at once.
	MaxRequestWorkers int `json:"max_request_workers"`
	// Timeout is how long Apache waits on a slow request, in seconds.
	Timeout int `json:"timeout"`
}

// PowerDNS settings.
type PowerDNS struct {
	// CacheTTL is how long answers stay in the packet cache, in seconds;
	// 0 turns the cache off.
	CacheTTL int `json:"cache_ttl"`
	// NegativeTTL is how long "no such name" answers are cached.
	NegativeTTL int `json:"negquery_cache_ttl"`
	// LogQueries writes every query to the service's log.
	LogQueries bool `json:"log_queries"`
	// AXFRAllow are the addresses and networks (secondary name servers)
	// that may transfer zones; none refuses every transfer.
	AXFRAllow []string `json:"axfr_allow"`
}

// Defaults are the settings before anything was changed: Nginx, Apache,
// PowerDNS and the database server keep their packages' defaults, uploads
// go up to 64 MB, mail up to 50 MB, and no zone is transferred.
func Defaults() Settings {
	return Settings{
		Nginx:    Nginx{WorkerProcesses: "auto", WorkerConnections: 768, ClientMaxBodyMB: 64, KeepaliveTimeout: 65},
		Database: Database{MaxConnections: 151, BufferPoolMB: 128, MaxAllowedPacketMB: 64},
		Postfix:  Postfix{MessageSizeMB: 50},
		Apache:   Apache{MaxRequestWorkers: 150, Timeout: 300},
		PowerDNS: PowerDNS{CacheTTL: 20, NegativeTTL: 60, AXFRAllow: []string{}},
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
	if s.Apache.MaxRequestWorkers <= 0 || s.Apache.Timeout <= 0 {
		s.Apache = d.Apache
	}
	if s.PowerDNS.CacheTTL < 0 || s.PowerDNS.NegativeTTL < 0 {
		s.PowerDNS = d.PowerDNS
	}
	if s.PowerDNS.AXFRAllow == nil {
		s.PowerDNS.AXFRAllow = []string{}
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

// apacheThreads is the threads per Apache process (mpm_event's default),
// written out so that ServerLimit below is always right.
const apacheThreads = 25

// ApacheServerLimit is the most Apache processes a: room for
// MaxRequestWorkers twice over, as processes finishing a graceful reload
// keep their slots, and never below Apache's default of 16.
func ApacheServerLimit(a Apache) int {
	return max(16, 2*((a.MaxRequestWorkers+apacheThreads-1)/apacheThreads))
}

// ApacheContent is Servik's server-wide Apache configuration for a.
func ApacheContent(a Apache) []byte {
	return fmt.Appendf(nil, `# %s. Manual changes are overwritten.
ServerName localhost
ServerTokens Prod
ServerSignature Off
TraceEnable Off

# Change these on the panel's Hizmetler page.
Timeout %d
<IfModule mpm_event_module>
    ThreadsPerChild %d
    ServerLimit %d
    MaxRequestWorkers %d
</IfModule>
`, txn.Marker, a.Timeout, apacheThreads, ApacheServerLimit(a), a.MaxRequestWorkers)
}

// PowerDNSLines are the lines of Servik's PowerDNS configuration that p
// decides, as [name, value] in file order.
func PowerDNSLines(p PowerDNS) [][2]string {
	disable, allow := "yes", ""
	if len(p.AXFRAllow) > 0 {
		disable, allow = "no", strings.Join(p.AXFRAllow, ",")
	}
	logQueries, level := "no", "4"
	if p.LogQueries {
		// PowerDNS logs queries at the notice level, one above its default.
		logQueries, level = "yes", "5"
	}
	return [][2]string{
		{"disable-axfr", disable},
		{"allow-axfr-ips", allow},
		{"cache-ttl", strconv.Itoa(p.CacheTTL)},
		{"negquery-cache-ttl", strconv.Itoa(p.NegativeTTL)},
		{"log-dns-queries", logQueries},
		{"loglevel", level},
	}
}

// legacyAXFR is the comment configurations written before zone transfers
// could be allowed have above disable-axfr.
var legacyAXFR = []byte("# No secondary servers in this version: refuse zone transfers.\n")

// SetPowerDNS puts p's lines into a PowerDNS configuration, replacing the
// ones it has and adding the others at the end.
func SetPowerDNS(conf []byte, p PowerDNS) []byte {
	conf = bytes.Replace(conf, legacyAXFR, []byte("# Zone transfers (to secondary name servers), caching and query logging\n# are changed on the panel's Hizmetler page.\n"), 1)
	for _, l := range PowerDNSLines(p) {
		line := []byte(l[0] + "=" + l[1])
		re := regexp.MustCompile(`(?m)^[ \t]*` + regexp.QuoteMeta(l[0]) + `[ \t]*=.*$`)
		if re.Match(conf) {
			conf = re.ReplaceAllLiteral(conf, line)
			continue
		}
		if len(conf) > 0 && !bytes.HasSuffix(conf, []byte("\n")) {
			conf = append(conf, '\n')
		}
		conf = append(append(conf, line...), '\n')
	}
	return conf
}
