package service

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"net/netip"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"syscall"

	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/tuning"
	"github.com/calltech-app/serfex/agent/internal/txn"
)

const (
	nginxConf   = "/etc/nginx/nginx.conf"
	mysqlTuning = "/etc/mysql/mysql.conf.d/zz-serfex-tuning.cnf"
	mariaTuning = "/etc/mysql/mariadb.conf.d/91-serfex-tuning.cnf"
	postfixMain = "/etc/postfix/main.cf"
	pdnsConf    = "/etc/powerdns/pdns-serfex.conf"
	// apacheEnabled is Serfex's Apache configuration switched on.
	apacheEnabled = "/etc/apache2/conf-enabled/serfex.conf"
)

// SettingsSpec names a service whose settings are read or changed; for a
// change, the section of that service holds the new values.
type SettingsSpec struct {
	Unit     string           `json:"unit"`
	Nginx    *tuning.Nginx    `json:"nginx,omitempty"`
	Database *tuning.Database `json:"database,omitempty"`
	Postfix  *tuning.Postfix  `json:"postfix,omitempty"`
	Apache   *tuning.Apache   `json:"apache,omitempty"`
	PowerDNS *tuning.PowerDNS `json:"powerdns,omitempty"`
}

// Settings returns a service's changeable settings as they are now.
func (m *Manager) Settings(ctx context.Context, unit string) (SettingsSpec, error) {
	d, _, err := m.find(ctx, unit)
	if err != nil {
		return SettingsSpec{}, err
	}
	s := tuning.Load(m.h)
	out := SettingsSpec{Unit: unit}
	switch d.settings {
	case "nginx":
		n := s.Nginx
		// What nginx.conf says wins: it may have been edited by hand.
		if conf, err := os.ReadFile(m.h.Path(nginxConf)); err == nil {
			if v := directive(conf, "worker_processes"); v != "" {
				n.WorkerProcesses = v
			}
			if v, err := strconv.Atoi(directive(conf, "worker_connections")); err == nil {
				n.WorkerConnections = v
			}
			if v, err := strconv.Atoi(strings.TrimSuffix(directive(conf, "keepalive_timeout"), "s")); err == nil {
				n.KeepaliveTimeout = v
			}
		}
		out.Nginx = &n
	case "database":
		db := s.Database
		out.Database = &db
	case "postfix":
		p := s.Postfix
		out.Postfix = &p
	case "apache":
		a := s.Apache
		out.Apache = &a
	case "powerdns":
		p := s.PowerDNS
		out.PowerDNS = &p
	default:
		return SettingsSpec{}, invalid("%s has no settings to change here", d.name)
	}
	return out, nil
}

// directive returns the value of the first uncommented directive name in
// an Nginx configuration.
func directive(conf []byte, name string) string {
	re := regexp.MustCompile(`(?m)^\s*` + regexp.QuoteMeta(name) + `\s+([^;#]+);`)
	if m := re.FindSubmatch(conf); m != nil {
		return strings.TrimSpace(string(m[1]))
	}
	return ""
}

// setDirective replaces the first directive name (commented out or not)
// in an Nginx configuration. A configuration without the line gets it at
// the top of block ("" for the main context, "events", "http").
func setDirective(conf []byte, block, name, value string) ([]byte, error) {
	re := regexp.MustCompile(`(?m)^([ \t]*)(?:#[ \t]*)?` + regexp.QuoteMeta(name) + `[ \t]+[^;\n]*;`)
	if loc := re.FindSubmatchIndex(conf); loc != nil {
		var out bytes.Buffer
		out.Write(conf[:loc[0]])
		out.Write(conf[loc[2]:loc[3]])
		fmt.Fprintf(&out, "%s %s;", name, value)
		out.Write(conf[loc[1]:])
		return out.Bytes(), nil
	}
	at, indent := 0, ""
	if block != "" {
		open := regexp.MustCompile(`(?m)^([ \t]*)` + regexp.QuoteMeta(block) + `[ \t]*\{[^\n]*\n`)
		loc := open.FindSubmatchIndex(conf)
		if loc == nil {
			return nil, fmt.Errorf("%s has no %s block", nginxConf, block)
		}
		at, indent = loc[1], string(conf[loc[2]:loc[3]])+"    "
	}
	var out bytes.Buffer
	out.Write(conf[:at])
	fmt.Fprintf(&out, "%s%s %s;\n", indent, name, value)
	out.Write(conf[at:])
	return out.Bytes(), nil
}

var workersRe = regexp.MustCompile(`^(auto|[1-9][0-9]{0,2})$`)

// ApplySettings changes a service's settings. The configuration is tested
// before the service reloads or restarts; anything that fails puts the
// previous settings back.
func (m *Manager) ApplySettings(ctx context.Context, spec SettingsSpec) (SettingsSpec, error) {
	d, st, err := m.find(ctx, spec.Unit)
	if err != nil {
		return SettingsSpec{}, err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	s := tuning.Load(m.h)
	t := txn.New(m.h)
	var apply func() error
	switch d.settings {
	case "nginx":
		n := spec.Nginx
		switch {
		case n == nil:
			return SettingsSpec{}, invalid("nginx settings are missing")
		case !workersRe.MatchString(n.WorkerProcesses):
			return SettingsSpec{}, invalid("worker_processes must be auto or 1-999")
		case n.WorkerConnections < 128 || n.WorkerConnections > 65535:
			return SettingsSpec{}, invalid("worker_connections must be between 128 and 65535")
		case n.ClientMaxBodyMB < 1 || n.ClientMaxBodyMB > 102400:
			return SettingsSpec{}, invalid("client_max_body_size must be between 1 and 102400 MB")
		case n.KeepaliveTimeout < 0 || n.KeepaliveTimeout > 300:
			return SettingsSpec{}, invalid("keepalive_timeout must be between 0 and 300 seconds")
		}
		s.Nginx = *n
		apply = func() error { return m.applyNginx(ctx, t, *n) }
	case "database":
		db := spec.Database
		switch {
		case db == nil:
			return SettingsSpec{}, invalid("database settings are missing")
		case db.MaxConnections < 10 || db.MaxConnections > 10000:
			return SettingsSpec{}, invalid("max_connections must be between 10 and 10000")
		case db.BufferPoolMB < 32 || db.BufferPoolMB > 1048576:
			return SettingsSpec{}, invalid("innodb_buffer_pool_size must be between 32 MB and 1 TB")
		case db.MaxAllowedPacketMB < 1 || db.MaxAllowedPacketMB > 1024:
			return SettingsSpec{}, invalid("max_allowed_packet must be between 1 and 1024 MB")
		}
		s.Database = *db
		apply = func() error { return m.applyDatabase(ctx, t, d.unit, *db) }
	case "postfix":
		p := spec.Postfix
		if p == nil || p.MessageSizeMB < 1 || p.MessageSizeMB > 2048 {
			return SettingsSpec{}, invalid("message_size_limit must be between 1 and 2048 MB")
		}
		s.Postfix = *p
		apply = func() error { return m.applyPostfix(ctx, t, *p) }
	case "apache":
		a := spec.Apache
		switch {
		case a == nil:
			return SettingsSpec{}, invalid("apache settings are missing")
		case a.MaxRequestWorkers < 25 || a.MaxRequestWorkers > 2000:
			return SettingsSpec{}, invalid("MaxRequestWorkers must be between 25 and 2000")
		case a.Timeout < 10 || a.Timeout > 300:
			return SettingsSpec{}, invalid("Timeout must be between 10 and 300 seconds")
		}
		restart := tuning.ApacheServerLimit(*a) != tuning.ApacheServerLimit(s.Apache)
		s.Apache = *a
		apply = func() error { return m.applyApache(ctx, t, *a, restart) }
	case "powerdns":
		p := spec.PowerDNS
		switch {
		case p == nil:
			return SettingsSpec{}, invalid("powerdns settings are missing")
		case p.CacheTTL < 0 || p.CacheTTL > 86400:
			return SettingsSpec{}, invalid("cache-ttl must be between 0 and 86400 seconds")
		case p.NegativeTTL < 0 || p.NegativeTTL > 86400:
			return SettingsSpec{}, invalid("negquery-cache-ttl must be between 0 and 86400 seconds")
		}
		allow, err := axfrAllow(p.AXFRAllow)
		if err != nil {
			return SettingsSpec{}, err
		}
		p.AXFRAllow = allow
		s.PowerDNS = *p
		apply = func() error { return m.applyPowerDNS(ctx, t, *p) }
	default:
		return SettingsSpec{}, invalid("%s has no settings to change here", d.name)
	}
	if st.State != "active" && st.State != "reloading" {
		return SettingsSpec{}, invalid("%s is not running; start it first", d.name)
	}
	if err := tuning.Save(t, s); err != nil {
		return SettingsSpec{}, &OperationError{errors.Join(err, t.Rollback(context.WithoutCancel(ctx)))}
	}
	if err := apply(); err != nil {
		var v *ValidationError
		rb := t.Rollback(context.WithoutCancel(ctx))
		m.recover(context.WithoutCancel(ctx), d)
		if errors.As(err, &v) && rb == nil {
			return SettingsSpec{}, err
		}
		return SettingsSpec{}, &OperationError{errors.Join(err, rb)}
	}
	return m.Settings(ctx, spec.Unit)
}

// recover brings a service back after its old settings were restored.
func (m *Manager) recover(ctx context.Context, d def) {
	if m.sim {
		return
	}
	verb := "reload-or-restart"
	if d.settings == "database" || d.settings == "powerdns" {
		verb = "restart"
	}
	_, _ = m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{verb, d.unit + ".service"}})
}

// test runs a configuration test; its failure is the caller's mistake.
func (m *Manager) test(ctx context.Context, name string, args ...string) error {
	if out, err := m.h.Run(ctx, host.Command{Name: name, Args: args}); err != nil {
		return invalid("the service refused the settings, nothing was changed: %s", tail(out, err))
	}
	return nil
}

// legacyBody is the upload limit every vhost carried before the limit
// became server-wide.
var legacyBody = []byte("\n    client_max_body_size 64m;\n")

func (m *Manager) applyNginx(ctx context.Context, t *txn.Tx, n tuning.Nginx) error {
	if err := tuning.WriteNginx(t, n); err != nil {
		return err
	}
	conf, err := os.ReadFile(m.h.Path(nginxConf))
	if err != nil && !m.sim {
		return err
	}
	if err == nil {
		for _, d := range [][3]string{
			{"", "worker_processes", n.WorkerProcesses},
			{"events", "worker_connections", strconv.Itoa(n.WorkerConnections)},
			{"http", "keepalive_timeout", strconv.Itoa(n.KeepaliveTimeout)},
		} {
			if conf, err = setDirective(conf, d[0], d[1], d[2]); err != nil {
				return err
			}
		}
		if err := t.WriteOwned(nginxConf, conf, 0o644, 0, 0); err != nil {
			return err
		}
	}
	// Sites provisioned before keep their own copy of the old limit,
	// which would hide the server-wide one.
	vhosts, _ := filepath.Glob(m.h.Path("/etc/nginx/sites-available/*.conf"))
	for _, f := range vhosts {
		b, err := os.ReadFile(f)
		if err != nil || !txn.HasMarker(b) || !bytes.Contains(b, legacyBody) {
			continue
		}
		abs := "/etc/nginx/sites-available/" + filepath.Base(f)
		if err := t.WriteFile(abs, bytes.ReplaceAll(b, legacyBody, []byte("\n"))); err != nil {
			return err
		}
	}
	if err := m.test(ctx, "nginx", "-t"); err != nil {
		return err
	}
	_, err = m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"reload", "nginx.service"}})
	return err
}

func (m *Manager) applyDatabase(ctx context.Context, t *txn.Tx, unit string, db tuning.Database) error {
	file := mysqlTuning
	if unit == "mariadb" {
		file = mariaTuning
	}
	content := fmt.Sprintf(`# %s. Change these on the panel's Hizmetler page.
[mysqld]
max_connections = %d
innodb_buffer_pool_size = %dM
max_allowed_packet = %dM
`, txn.Marker, db.MaxConnections, db.BufferPoolMB, db.MaxAllowedPacketMB)
	if err := t.RootDir(filepath.Dir(file), 0o755, 0, 0); err != nil {
		return err
	}
	if err := t.WriteFile(file, []byte(content)); err != nil {
		return err
	}
	if !t.Changed || m.sim {
		return nil
	}
	if out, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"restart", unit + ".service"}}); err != nil {
		return fmt.Errorf("%s did not start with the new settings: %s", unit, tail(out, err))
	}
	return nil
}

func (m *Manager) applyPostfix(ctx context.Context, t *txn.Tx, p tuning.Postfix) error {
	main, err := os.ReadFile(m.h.Path(postfixMain))
	if err != nil {
		return err
	}
	re := regexp.MustCompile(`(?m)^message_size_limit\s*=.*$`)
	line := fmt.Appendf(nil, "message_size_limit = %d", int64(p.MessageSizeMB)<<20)
	if re.Match(main) {
		main = re.ReplaceAll(main, line)
	} else {
		main = append(append(main, line...), '\n')
	}
	if err := t.WriteOwned(postfixMain, main, 0o644, 0, 0); err != nil {
		return err
	}
	if err := m.test(ctx, "postfix", "check"); err != nil {
		return err
	}
	_, err = m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"reload", "postfix.service"}})
	return err
}

// maxAXFR is how many addresses may transfer zones.
const maxAXFR = 32

// axfrAllow checks the addresses allowed to transfer zones and writes
// them the same way every time: an address or a network in CIDR form,
// each once.
func axfrAllow(list []string) ([]string, error) {
	out := []string{}
	seen := map[string]bool{}
	for _, v := range list {
		v = strings.TrimSpace(v)
		if v == "" {
			continue
		}
		var norm string
		if strings.Contains(v, "/") {
			p, err := netip.ParsePrefix(v)
			if err != nil {
				return nil, invalid("%q is not an IP address or network", v)
			}
			norm = p.Masked().String()
		} else {
			a, err := netip.ParseAddr(v)
			if err != nil || a.Zone() != "" {
				return nil, invalid("%q is not an IP address or network", v)
			}
			norm = a.Unmap().String()
		}
		if !seen[norm] {
			seen[norm] = true
			out = append(out, norm)
		}
	}
	if len(out) > maxAXFR {
		return nil, invalid("at most %d addresses may transfer zones", maxAXFR)
	}
	return out, nil
}

func (m *Manager) applyApache(ctx context.Context, t *txn.Tx, a tuning.Apache, restart bool) error {
	if err := t.WriteFile(tuning.ApacheConf, tuning.ApacheContent(a)); err != nil {
		return err
	}
	if err := m.test(ctx, "apache2ctl", "configtest"); err != nil {
		return err
	}
	// A graceful reload keeps the old number of process slots.
	verb := "reload"
	if restart {
		verb = "restart"
	}
	_, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{verb, "apache2.service"}})
	return err
}

func (m *Manager) applyPowerDNS(ctx context.Context, t *txn.Tx, p tuning.PowerDNS) error {
	path := m.h.Path(pdnsConf)
	conf, err := os.ReadFile(path)
	if err != nil {
		return err
	}
	// The file holds the API key: it keeps its mode and group.
	gid := 0
	if fi, err := os.Stat(path); err == nil {
		if st, ok := fi.Sys().(*syscall.Stat_t); ok {
			gid = int(st.Gid)
		}
	}
	if err := t.WriteFileMode(pdnsConf, tuning.SetPowerDNS(conf, p), 0o640, 0, gid); err != nil {
		return err
	}
	if err := m.test(ctx, "pdns_server", "--config-name=serfex", "--config=check"); err != nil {
		return err
	}
	// PowerDNS cannot reload its settings; a restart takes a moment.
	if out, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"restart", "pdns@serfex.service"}}); err != nil {
		return fmt.Errorf("PowerDNS did not start with the new settings: %s", tail(out, err))
	}
	return nil
}
