package component

import (
	"context"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"text/template"
	"time"

	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/site"
	"github.com/winchester37/servik/agent/internal/txn"
)

// Every application that uses Redis gets its own instance: one Redis
// server per site, running as the site's user on 127.0.0.1 with a
// password. Applications never share keys, FLUSHDB only empties their
// own data, and live monitoring counts the instance's CPU and memory for
// the site (unit servik-redis-<domain>).

// RedisSpec is a site's Redis instance.
type RedisSpec struct {
	Domain   string `json:"domain"`
	User     string `json:"user"`
	Port     int    `json:"port"`
	Password string `json:"password"`
	// MaxMemoryMB caps the data kept in memory; at the cap writes fail
	// (nothing is evicted, so queued jobs are never lost).
	MaxMemoryMB int `json:"max_memory_mb"`
}

// RedisResult describes an applied instance.
type RedisResult struct {
	Unit    string `json:"unit"`
	Port    int    `json:"port"`
	Changed bool   `json:"changed"`
}

var redisPasswordRe = regexp.MustCompile(`^[A-Za-z0-9]{16,128}$`)

// DefaultRedisMemory is an instance's memory cap when none is given.
const DefaultRedisMemory = 128

func redisUnit(domain string) string     { return "servik-redis-" + domain + ".service" }
func redisConf(domain string) string     { return redisDir + "/" + domain + ".conf" }
func redisData(domain string) string     { return "/var/lib/servik/redis/" + domain }
func redisUnitFile(domain string) string { return "/etc/systemd/system/" + redisUnit(domain) }

var redisConfTmpl = template.Must(template.New("redis").Parse(`# {{.Marker}}. Manual changes are overwritten.
# Redis for {{.Domain}} (user {{.User}}).
bind 127.0.0.1
port {{.Port}}
protected-mode yes
requirepass "{{.Password}}"
daemonize no
supervised no
logfile ""
dir {{.Data}}
dbfilename dump.rdb
save 300 10
save 60 10000
appendonly no
maxmemory {{.MaxMemoryMB}}mb
maxmemory-policy noeviction
databases 16
`))

var redisUnitTmpl = template.Must(template.New("unit").Parse(`# {{.Marker}}. Manual changes are overwritten.
[Unit]
Description=Redis for {{.Domain}} (Servik)
After=network.target

[Service]
User={{.User}}
Group={{.User}}
ExecStart=/usr/bin/redis-server {{.Conf}}
Restart=on-failure
RestartSec=2
UMask=0077
NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=full
ProtectHome=yes
ReadWritePaths={{.Data}}

[Install]
WantedBy=multi-user.target
`))

// ApplyRedis creates or updates a site's Redis instance and starts it.
// A change Redis does not accept is rolled back.
func (m *Manager) ApplyRedis(ctx context.Context, spec RedisSpec) (res RedisResult, err error) {
	if err := site.ValidateDomain(spec.Domain); err != nil {
		return res, err
	}
	if err := site.ValidateUser(spec.User); err != nil {
		return res, err
	}
	if spec.Port < 1024 || spec.Port > 65535 {
		return res, invalid("port must be between 1024 and 65535")
	}
	if !redisPasswordRe.MatchString(spec.Password) {
		return res, invalid("password must be 16-128 letters and digits")
	}
	if spec.MaxMemoryMB == 0 {
		spec.MaxMemoryMB = DefaultRedisMemory
	}
	if spec.MaxMemoryMB < 16 || spec.MaxMemoryMB > 8192 {
		return res, invalid("max_memory_mb must be between 16 and 8192")
	}
	if !m.comps["redis"].isInstalled(m) {
		return res, invalid("Redis is not installed; install it from Yazılım Yönetimi first")
	}
	u, err := m.h.LookupUser(spec.User)
	if err != nil {
		return res, invalid("user %q does not exist", spec.User)
	}
	if other := m.redisOnPort(spec.Port); other != "" && other != spec.Domain {
		return res, invalid("port %d is used by the Redis of %s", spec.Port, other)
	}

	m.rmu.Lock()
	defer m.rmu.Unlock()
	t := txn.New(m.h)
	defer func() {
		if err != nil {
			bg := context.WithoutCancel(ctx)
			_ = t.Rollback(bg)
			_, _ = m.h.Run(bg, host.Command{Name: "systemctl", Args: []string{"daemon-reload"}})
			if m.exists(redisUnitFile(spec.Domain)) {
				_, _ = m.h.Run(bg, host.Command{Name: "systemctl", Args: []string{"restart", redisUnit(spec.Domain)}})
			} else {
				_, _ = m.h.Run(bg, host.Command{Name: "systemctl", Args: []string{"stop", redisUnit(spec.Domain)}})
			}
			err = opError(err)
		}
	}()
	data := map[string]any{
		"Marker": txn.Marker, "Domain": spec.Domain, "User": spec.User, "Port": spec.Port,
		"Password": spec.Password, "MaxMemoryMB": spec.MaxMemoryMB, "Data": redisData(spec.Domain), "Conf": redisConf(spec.Domain),
	}
	for _, d := range []string{"/var/lib/servik", "/var/lib/servik/redis"} {
		if err := t.RootDir(d, 0o755, 0, 0); err != nil {
			return res, err
		}
	}
	if err := t.RootDir(redisData(spec.Domain), 0o700, u.UID, u.GID); err != nil {
		return res, err
	}
	if err := t.WriteFileMode(redisConf(spec.Domain), render(redisConfTmpl, data), 0o640, 0, u.GID); err != nil {
		return res, err
	}
	if err := t.WriteFile(redisUnitFile(spec.Domain), render(redisUnitTmpl, data)); err != nil {
		return res, err
	}
	res = RedisResult{Unit: redisUnit(spec.Domain), Port: spec.Port, Changed: t.Changed}
	if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"daemon-reload"}}); err != nil {
		return res, err
	}
	if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"enable", redisUnit(spec.Domain)}}); err != nil {
		return res, err
	}
	verb := "start"
	if t.Changed {
		verb = "restart"
	}
	if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{verb, redisUnit(spec.Domain)}}); err != nil {
		return res, fmt.Errorf("%s did not start (see journalctl -u %s): %w", redisUnit(spec.Domain), redisUnit(spec.Domain), err)
	}
	if err := m.pingRedis(ctx, spec.Port, spec.Password); err != nil {
		return res, err
	}
	return res, nil
}

// pingRedis waits until the instance answers.
func (m *Manager) pingRedis(ctx context.Context, port int, password string) error {
	if m.sim {
		return nil
	}
	var err error
	for i := 0; i < 20; i++ {
		var out string
		out, err = m.h.Run(ctx, host.Command{Name: "redis-cli", Args: []string{"-h", "127.0.0.1", "-p", fmt.Sprint(port), "--no-auth-warning", "ping"}, Env: []string{"REDISCLI_AUTH=" + password}})
		if err == nil && strings.TrimSpace(out) == "PONG" {
			return nil
		}
		if err == nil {
			err = fmt.Errorf("redis answered %q", strings.TrimSpace(out))
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(500 * time.Millisecond):
		}
	}
	return fmt.Errorf("redis on port %d does not answer: %w", port, err)
}

// RemoveRedis stops a site's instance and deletes it with its data.
func (m *Manager) RemoveRedis(ctx context.Context, domain string) (bool, error) {
	if err := site.ValidateDomain(domain); err != nil {
		return false, err
	}
	m.rmu.Lock()
	defer m.rmu.Unlock()
	if !m.exists(redisUnitFile(domain)) && !m.exists(redisConf(domain)) {
		return false, nil
	}
	_, _ = m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"disable", "--now", redisUnit(domain)}})
	t := txn.New(m.h)
	if err := t.RemoveFile(redisUnitFile(domain)); err != nil {
		return false, opError(err)
	}
	if err := t.RemoveFile(redisConf(domain)); err != nil {
		return false, opError(err)
	}
	if err := os.RemoveAll(m.h.Path(redisData(domain))); err != nil {
		return false, opError(err)
	}
	_, _ = m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"daemon-reload"}})
	return true, nil
}

// redisInstances lists the sites that have a Redis instance.
func (m *Manager) redisInstances() []string {
	files, _ := filepath.Glob(m.h.Path("/etc/systemd/system/servik-redis-*.service"))
	var out []string
	for _, f := range files {
		out = append(out, strings.TrimSuffix(strings.TrimPrefix(filepath.Base(f), "servik-redis-"), ".service"))
	}
	sort.Strings(out)
	return out
}

// redisOnPort returns the site whose instance listens on port.
func (m *Manager) redisOnPort(port int) string {
	want := fmt.Sprintf("\nport %d\n", port)
	for _, d := range m.redisInstances() {
		data, err := os.ReadFile(m.h.Path(redisConf(d)))
		if errors.Is(err, os.ErrNotExist) {
			continue
		}
		if strings.Contains(string(data), want) {
			return d
		}
	}
	return ""
}

func render(t *template.Template, data any) []byte {
	var b strings.Builder
	if err := t.Execute(&b, data); err != nil {
		panic(err)
	}
	return []byte(b.String())
}
