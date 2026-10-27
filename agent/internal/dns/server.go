package dns

import (
	"bufio"
	"bytes"
	"context"
	"crypto/rand"
	"encoding/hex"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"text/template"
	"time"

	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/tuning"
	"github.com/winchester37/servik/agent/internal/txn"
)

const (
	// instance is the PowerDNS virtual instance name; systemd runs it as
	// pdns@servik and it reads /etc/powerdns/pdns-servik.conf only, so the
	// distribution's pdns.conf and pdns.d/ are never touched.
	instance   = "servik"
	unit       = "pdns@" + instance
	configPath = "/etc/powerdns/pdns-servik.conf"
	pdnsBinary = "/usr/sbin/pdns_server"
	lmdbModule = "/usr/lib/*/pdns/liblmdbbackend.so"
	apiAddress = "127.0.0.1"
	apiPort    = "8081"

	resolvConf     = "/etc/resolv.conf"
	resolvedDropIn = "/etc/systemd/resolved.conf.d/servik-dns.conf"
	resolvedUplink = "/run/systemd/resolve/resolv.conf"
)

// Packages are the Ubuntu packages Setup installs.
var Packages = []string{"pdns-server", "pdns-backend-lmdb"}

var configTmpl = template.Must(template.New("pdns").Parse(`# {{.Marker}}. Manual changes are overwritten.
# PowerDNS Authoritative instance for Servik (pdns@servik.service).
launch=lmdb
lmdb-filename=/var/lib/powerdns/servik.lmdb
lmdb-sync-mode=sync

local-address={{.Listen}}
local-port=53

# Zones are changed only through the API, which listens on loopback.
api=yes
api-key={{.APIKey}}
webserver=yes
webserver-address={{.APIAddress}}
webserver-port={{.APIPort}}
webserver-allow-from=127.0.0.1,::1

# Zone transfers (to secondary name servers), caching and query logging
# are changed on the panel's Hizmetler page.
{{- range .Tuning}}
{{index . 0}}={{index . 1}}
{{- end}}
version-string=anonymous
security-poll-suffix=
`))

var resolvedTmpl = []byte(`# ` + txn.Marker + `. Manual changes are overwritten.
# PowerDNS answers on port 53 for the whole server, so the local stub
# resolver on 127.0.0.53:53 is turned off. systemd-resolved still resolves
# for this host through /run/systemd/resolve/resolv.conf.
[Resolve]
DNSStubListener=no
`)

// Status describes the DNS server.
type Status struct {
	Installed  bool   `json:"installed"`
	Configured bool   `json:"configured"`
	Running    bool   `json:"running"`
	Version    string `json:"version,omitempty"`
	Error      string `json:"error,omitempty"`
}

// Status reports whether PowerDNS is installed, set up and answering.
func (m *Manager) Status(ctx context.Context) Status {
	st := Status{Installed: m.packagesInstalled()}
	b, err := m.backend()
	if errors.Is(err, ErrNotSetUp) {
		return st
	}
	if err != nil {
		st.Error = err.Error()
		return st
	}
	st.Configured = true
	ctx, cancel := context.WithTimeout(ctx, 3*time.Second)
	defer cancel()
	v, err := b.Version(ctx)
	if err != nil {
		st.Error = err.Error()
		return st
	}
	st.Running, st.Version = true, v
	return st
}

// SetupResult describes what Setup did.
type SetupResult struct {
	Installed bool   `json:"installed"`
	Changed   bool   `json:"changed"`
	Version   string `json:"version"`
}

// Setup installs PowerDNS when missing, writes the Servik instance's
// configuration and starts it. Running it again repairs a stopped or
// changed server. Configuration changes are rolled back if PowerDNS does
// not come up with them; installed packages stay.
func (m *Manager) Setup(ctx context.Context) (res SetupResult, err error) {
	m.mu.Lock()
	defer m.mu.Unlock()

	t := txn.New(m.h)
	resolvedChanged := false
	defer func() {
		if err == nil {
			return
		}
		bg := context.WithoutCancel(ctx)
		if rbErr := t.Rollback(bg); rbErr != nil {
			err = fmt.Errorf("%w (rollback also failed: %v)", err, rbErr)
		}
		if resolvedChanged {
			_, _ = m.h.Run(bg, host.Command{Name: "systemctl", Args: []string{"restart", "systemd-resolved"}})
		}
		if _, statErr := os.Stat(m.h.Path(configPath)); statErr == nil {
			_, _ = m.h.Run(bg, host.Command{Name: "systemctl", Args: []string{"restart", unit}})
		}
		var v *ValidationError
		if !errors.As(err, &v) {
			err = &OperationError{err}
		}
	}()

	if !m.packagesInstalled() {
		if err := m.install(ctx); err != nil {
			return SetupResult{}, err
		}
		res.Installed = true
	}
	pdns, err := m.h.LookupUser("pdns")
	if err != nil {
		return SetupResult{}, fmt.Errorf("pdns account: %w", err)
	}

	// The stock instance (bind backend, empty) would hold port 53.
	_, _ = m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"disable", "--now", "pdns.service"}})

	// Free port 53 from systemd-resolved's stub listener.
	if target, err := os.Readlink(m.h.Path(resolvConf)); err == nil && strings.HasSuffix(target, "stub-resolv.conf") {
		// Without the stub, this server looks names up through the servers
		// resolved itself uses. With none listed, every lookup would end
		// at PowerDNS, which answers only for its own zones.
		if !hasNameserver(m.h.Path(resolvedUplink)) {
			return SetupResult{}, fmt.Errorf("systemd-resolved lists no upstream DNS server in %s; turning off its local resolver would leave this server unable to look up other names. Add a DNS server to the network configuration (for example nameservers in netplan) and try again", resolvedUplink)
		}
		if err := t.RootDir(filepath.Dir(resolvedDropIn), 0o755, 0, 0); err != nil {
			return SetupResult{}, err
		}
		if err := t.WriteFile(resolvedDropIn, resolvedTmpl); err != nil {
			return SetupResult{}, err
		}
		if err := t.ReplaceSymlink(resolvedUplink, resolvConf); err != nil {
			return SetupResult{}, err
		}
		resolvedChanged = true
	}

	key, err := m.apiKey()
	if err != nil {
		return SetupResult{}, err
	}
	listen := "0.0.0.0"
	if m.hasIPv6() {
		listen += ", ::"
	}
	var conf bytes.Buffer
	if err := configTmpl.Execute(&conf, map[string]any{
		"Marker": txn.Marker, "Listen": listen, "APIKey": key, "APIAddress": apiAddress, "APIPort": apiPort,
		"Tuning": tuning.PowerDNSLines(tuning.Load(m.h).PowerDNS),
	}); err != nil {
		return SetupResult{}, err
	}
	if err := t.WriteFileMode(configPath, conf.Bytes(), 0o640, 0, pdns.GID); err != nil {
		return SetupResult{}, err
	}
	res.Changed = t.Changed || res.Installed

	b := m.newBackend(APIConfig{URL: "http://" + apiAddress + ":" + apiPort, Key: key})
	if !t.Changed {
		// Nothing to change; only make sure the server is up.
		if v, err := b.Version(ctx); err == nil {
			res.Version = v
			return res, nil
		}
		res.Changed = true
	}

	if _, err := m.h.Run(ctx, host.Command{Name: pdnsBinary, Args: []string{"--config-name=" + instance, "--config=check"}}); err != nil {
		return SetupResult{}, fmt.Errorf("PowerDNS rejected the configuration: %w", err)
	}
	if resolvedChanged {
		if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"restart", "systemd-resolved"}}); err != nil {
			return SetupResult{}, err
		}
		// Go programs, this agent among them, notice a changed resolv.conf
		// only by its modification time. resolved writes the stub and
		// uplink files in the same instant, so a Go program would keep
		// asking 127.0.0.53, where PowerDNS now refuses other names.
		now := time.Now()
		_ = os.Chtimes(m.h.Path(resolvedUplink), now, now)
	}
	for _, args := range [][]string{{"enable", unit}, {"restart", unit}} {
		if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: args}); err != nil {
			return SetupResult{}, err
		}
	}
	v, err := m.waitForAPI(ctx, b)
	if err != nil {
		return SetupResult{}, err
	}
	res.Version = v
	return res, nil
}

// install installs the PowerDNS packages, refreshing the package lists
// once if the first attempt fails.
func (m *Manager) install(ctx context.Context) error {
	env := []string{"DEBIAN_FRONTEND=noninteractive"}
	inst := host.Command{Name: "apt-get", Args: append([]string{"install", "-y", "-q", "--no-install-recommends"}, Packages...), Env: env}
	if _, err := m.h.Run(ctx, inst); err == nil {
		return nil
	}
	if _, err := m.h.Run(ctx, host.Command{Name: "apt-get", Args: []string{"update", "-q"}, Env: env}); err != nil {
		return fmt.Errorf("installing PowerDNS: %w", err)
	}
	if _, err := m.h.Run(ctx, inst); err != nil {
		return fmt.Errorf("installing PowerDNS: %w", err)
	}
	return nil
}

func (m *Manager) packagesInstalled() bool {
	if _, err := os.Stat(m.h.Path(pdnsBinary)); err != nil {
		return false
	}
	found, _ := filepath.Glob(m.h.Path(lmdbModule))
	return len(found) > 0
}

// waitForAPI polls PowerDNS until it answers or apiWait passes.
func (m *Manager) waitForAPI(ctx context.Context, b Backend) (string, error) {
	deadline := time.Now().Add(m.apiWait)
	for {
		c, cancel := context.WithTimeout(ctx, 2*time.Second)
		v, err := b.Version(c)
		cancel()
		if err == nil {
			return v, nil
		}
		if time.Now().After(deadline) {
			return "", fmt.Errorf("PowerDNS did not start (see journalctl -u %s): %w", unit, err)
		}
		select {
		case <-ctx.Done():
			return "", ctx.Err()
		case <-time.After(300 * time.Millisecond):
		}
	}
}

// apiKey returns the key from the current configuration, or a new one.
func (m *Manager) apiKey() (string, error) {
	if cfg, err := m.readConfig(); err == nil && cfg.Key != "" {
		return cfg.Key, nil
	}
	b := make([]byte, 32)
	if _, err := rand.Read(b); err != nil {
		return "", err
	}
	return hex.EncodeToString(b), nil
}

// readConfig reads the API address and key from the instance config.
func (m *Manager) readConfig() (APIConfig, error) {
	data, err := os.ReadFile(m.h.Path(configPath))
	if errors.Is(err, os.ErrNotExist) {
		return APIConfig{}, ErrNotSetUp
	}
	if err != nil {
		return APIConfig{}, err
	}
	addr, port, key := apiAddress, apiPort, ""
	s := bufio.NewScanner(bytes.NewReader(data))
	for s.Scan() {
		k, v, ok := strings.Cut(s.Text(), "=")
		if !ok {
			continue
		}
		switch strings.TrimSpace(k) {
		case "api-key":
			key = strings.TrimSpace(v)
		case "webserver-address":
			addr = strings.TrimSpace(v)
		case "webserver-port":
			port = strings.TrimSpace(v)
		}
	}
	return APIConfig{URL: "http://" + addr + ":" + port, Key: key}, nil
}

// hasIPv6 reports whether the kernel has IPv6; PowerDNS refuses to start
// when asked to listen on :: without it.
func (m *Manager) hasIPv6() bool {
	_, err := os.Stat(m.h.Path("/proc/net/if_inet6"))
	return err == nil
}

// hasNameserver reports whether the resolv.conf file at path lists a
// name server.
func hasNameserver(path string) bool {
	f, err := os.Open(path)
	if err != nil {
		return false
	}
	defer f.Close()
	sc := bufio.NewScanner(f)
	for sc.Scan() {
		if fields := strings.Fields(sc.Text()); len(fields) >= 2 && fields[0] == "nameserver" {
			return true
		}
	}
	return false
}
