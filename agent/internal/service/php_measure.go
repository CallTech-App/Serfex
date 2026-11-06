package service

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/monitor"
	"github.com/calltech-app/serfex/agent/internal/txn"
)

// PHPMeasurement is what the panel's optimization suggestions for one
// PHP version are worked out from: the machine, OPcache's use of its
// memory (shared by every site of the version) and each site's PHP-FPM
// pool. Counters run from PHP-FPM's (or the pool's) start; the panel
// compares measurements to see what happened in between.
type PHPMeasurement struct {
	Unit        string           `json:"unit"`
	Kind        string           `json:"kind"`
	TakenAt     time.Time        `json:"taken_at"`
	Version     string           `json:"version"`
	CPUs        int              `json:"cpus"`
	MemoryMB    int64            `json:"memory_mb"`
	AvailableMB int64            `json:"available_mb"`
	SwapMB      int64            `json:"swap_mb"`
	SwapUsedMB  int64            `json:"swap_used_mb"`
	ProcessesMB map[string]int64 `json:"processes_mb"`
	// OPcache is nil when it could not be read; OPcacheError says why.
	OPcache      *OPcache `json:"opcache"`
	OPcacheError string   `json:"opcache_error,omitempty"`
	Pools        []Pool   `json:"pools"`
}

// OPcache is the version's OPcache: its settings and how full it is.
type OPcache struct {
	// Enabled is opcache.enable; Loaded is false without the extension.
	Loaded  bool `json:"loaded"`
	Enabled bool `json:"enabled"`
	// MemoryMB is opcache.memory_consumption; UsedMB, FreeMB and WastedMB
	// split it.
	MemoryMB int     `json:"memory_mb"`
	UsedMB   float64 `json:"used_mb"`
	FreeMB   float64 `json:"free_mb"`
	WastedMB float64 `json:"wasted_mb"`
	// StringsMB is opcache.interned_strings_buffer.
	StringsMB     int     `json:"strings_mb"`
	StringsUsedMB float64 `json:"strings_used_mb"`
	StringsFreeMB float64 `json:"strings_free_mb"`
	// MaxFiles is opcache.max_accelerated_files; PHP rounds it up to the
	// next size of its table, MaxCachedKeys.
	MaxFiles           int   `json:"max_files"`
	CachedScripts      int   `json:"cached_scripts"`
	CachedKeys         int   `json:"cached_keys"`
	MaxCachedKeys      int   `json:"max_cached_keys"`
	Hits               int64 `json:"hits"`
	Misses             int64 `json:"misses"`
	OOMRestarts        int   `json:"oom_restarts"`
	HashRestarts       int   `json:"hash_restarts"`
	ManualRestarts     int   `json:"manual_restarts"`
	StartTime          int64 `json:"start_time"`
	ValidateTimestamps bool  `json:"validate_timestamps"`
	RevalidateFreq     int   `json:"revalidate_freq"`
}

// Pool is one site's PHP-FPM pool: its settings, its status page and the
// memory of its workers.
type Pool struct {
	Domain      string `json:"domain"`
	PM          string `json:"pm"`
	MaxChildren int    `json:"max_children"`
	// From the status page; the counters run from StartTime.
	StartTime  int64 `json:"start_time"`
	Accepted   int   `json:"accepted"`
	MaxReached int   `json:"max_reached"`
	MaxActive  int   `json:"max_active"`
	MaxQueue   int   `json:"max_queue"`
	Active     int   `json:"active"`
	Idle       int   `json:"idle"`
	// Workers running now and their memory (private, without what they
	// share, such as OPcache).
	Workers  int     `json:"workers"`
	MemoryMB float64 `json:"memory_mb"`
	// Read is false when the status page did not answer.
	Read bool `json:"read"`
}

var phpUnitRe = regexp.MustCompile(`^php(\d\.\d)-fpm$`)

// PHPVersionOf returns the version of a PHP-FPM unit, or "".
func PHPVersionOf(unit string) string {
	if m := phpUnitRe.FindStringSubmatch(unit); m != nil {
		return m[1]
	}
	return ""
}

// The probe pool answers the agent's OPcache questions: OPcache's memory
// belongs to the PHP-FPM master, so only a worker of that master can read
// it. It runs as nobody, starts a worker only when asked, and its socket
// is root's alone.
const probeDir = "/opt/serfex/php-probe"

// ProbePool is the name of the probe pool (never a valid domain).
const ProbePool = "serfex_probe"

func probeScript() string { return probeDir + "/opcache.php" }

func probePool(v string) string { return "/etc/php/" + v + "/fpm/pool.d/" + ProbePool + ".conf" }

func probeSocket(v string) string { return "/run/php/" + ProbePool + "-" + v + ".sock" }

func probeScriptContent() []byte {
	return []byte(`<?php // ` + txn.Marker + `. Read by the agent for optimization suggestions; only root can reach it.
header('Content-Type: application/json');
$status = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
$config = function_exists('opcache_get_configuration') ? @opcache_get_configuration() : false;
echo json_encode([
    'loaded' => extension_loaded('Zend OPcache'),
    'status' => is_array($status) ? $status : null,
    'directives' => is_array($config) ? ($config['directives'] ?? null) : null,
]);
`)
}

func probePoolContent(v string) []byte {
	return fmt.Appendf(nil, `; %s. Manual changes are overwritten.
; Lets the Serfex agent read OPcache's state for optimization suggestions.
; It starts a worker only when asked and runs nothing else.
[%s]
user = nobody
group = nogroup

listen = %s
listen.owner = root
listen.group = root
listen.mode = 0600

pm = ondemand
pm.max_children = 1
pm.process_idle_timeout = 10s
pm.max_requests = 100

chdir = /
security.limit_extensions = .php
php_admin_value[open_basedir] = %s/
php_admin_flag[log_errors] = on
php_admin_value[memory_limit] = 32M
php_admin_value[max_execution_time] = 5
`, txn.Marker, ProbePool, probeSocket(v), probeDir)
}

// ensureProbe writes the probe pool of a version when it is missing and
// reloads PHP-FPM once for it.
func (m *Manager) ensureProbe(ctx context.Context, v string) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	t := txn.New(m.h)
	err := t.RootDir(probeDir, 0o755, 0, 0)
	if err == nil {
		err = t.WriteFile(probeScript(), probeScriptContent())
	}
	if err == nil {
		err = t.WriteFile(probePool(v), probePoolContent(v))
	}
	if err != nil {
		return errors.Join(err, t.Rollback(context.WithoutCancel(ctx)))
	}
	if !t.Changed {
		return nil
	}
	if out, err := m.h.Run(ctx, host.Command{Name: "php-fpm" + v, Args: []string{"-t"}}); err != nil {
		return errors.Join(fmt.Errorf("PHP-FPM %s rejected the probe pool: %s", v, strings.TrimSpace(out)), t.Rollback(context.WithoutCancel(ctx)))
	}
	if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"reload", "php" + v + "-fpm"}}); err != nil {
		rb := t.Rollback(context.WithoutCancel(ctx))
		_, _ = m.h.Run(context.WithoutCancel(ctx), host.Command{Name: "systemctl", Args: []string{"reload", "php" + v + "-fpm"}})
		return errors.Join(fmt.Errorf("reloading php%s-fpm failed: %w", v, err), rb)
	}
	// The master opens the new socket a moment after the reload.
	for range 20 {
		if _, err := os.Stat(m.h.Path(probeSocket(v))); err == nil {
			break
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(100 * time.Millisecond):
		}
	}
	return nil
}

// measurePHP reads a running PHP-FPM version.
func (m *Manager) measurePHP(ctx context.Context, unit, v string) (PHPMeasurement, error) {
	if m.sim {
		return demoPHPMeasurement(unit, v, m.now()), nil
	}
	out := PHPMeasurement{Unit: unit, Kind: "php", TakenAt: m.now().UTC(), Version: v, Pools: []Pool{}}
	out.MemoryMB, out.AvailableMB, out.SwapMB, out.SwapUsedMB = readMemory(m.h)
	out.CPUs = countCPUs(m.h)
	out.ProcessesMB = processMemory(m.h)

	ctx, cancel := context.WithTimeout(ctx, 30*time.Second)
	defer cancel()
	if err := m.ensureProbe(ctx, v); err != nil {
		out.OPcacheError = err.Error()
	} else {
		cctx, ccancel := context.WithTimeout(ctx, 5*time.Second)
		body, err := monitor.FastCGI(cctx, m.h.Path(probeSocket(v)), probeScript(), "")
		ccancel()
		if err == nil {
			out.OPcache, err = parseOPcache(body)
		}
		if err != nil {
			out.OPcacheError = err.Error()
		}
	}

	memory := poolMemory(m.h)
	for _, p := range readPools(m.h, v) {
		cctx, ccancel := context.WithTimeout(ctx, time.Second)
		st, err := monitor.FetchPoolStatus(cctx, m.h.Path(p.socket))
		ccancel()
		pool := p.Pool
		if err == nil {
			pool.Read = true
			pool.StartTime, pool.Accepted, pool.MaxReached = st.StartTime, st.Accepted, st.MaxReached
			pool.MaxActive, pool.MaxQueue, pool.Active, pool.Idle = st.MaxActive, st.MaxQueue, st.Active, st.Idle
		}
		if mem, ok := memory[pool.Domain]; ok {
			pool.Workers, pool.MemoryMB = mem.workers, round1(mem.mb)
		}
		out.Pools = append(out.Pools, pool)
	}
	return out, nil
}

// parseOPcache reads the probe's answer.
func parseOPcache(body []byte) (*OPcache, error) {
	var r struct {
		Loaded bool `json:"loaded"`
		Status *struct {
			Enabled bool `json:"opcache_enabled"`
			Memory  struct {
				Used   float64 `json:"used_memory"`
				Free   float64 `json:"free_memory"`
				Wasted float64 `json:"wasted_memory"`
			} `json:"memory_usage"`
			Strings struct {
				Size float64 `json:"buffer_size"`
				Used float64 `json:"used_memory"`
				Free float64 `json:"free_memory"`
			} `json:"interned_strings_usage"`
			Stats struct {
				Scripts        int   `json:"num_cached_scripts"`
				Keys           int   `json:"num_cached_keys"`
				MaxKeys        int   `json:"max_cached_keys"`
				Hits           int64 `json:"hits"`
				Misses         int64 `json:"misses"`
				StartTime      int64 `json:"start_time"`
				OOMRestarts    int   `json:"oom_restarts"`
				HashRestarts   int   `json:"hash_restarts"`
				ManualRestarts int   `json:"manual_restarts"`
			} `json:"opcache_statistics"`
		} `json:"status"`
		Directives map[string]any `json:"directives"`
	}
	if err := json.Unmarshal(bytes.TrimSpace(body), &r); err != nil {
		return nil, errors.New("the probe did not answer with OPcache's state")
	}
	o := &OPcache{Loaded: r.Loaded}
	d := r.Directives
	o.Enabled = truthy(d["opcache.enable"])
	// PHP reports memory_consumption in bytes, the strings buffer in MB.
	if n := number(d["opcache.memory_consumption"]); n > 0 {
		o.MemoryMB = int(n / (1 << 20))
	}
	o.StringsMB = int(number(d["opcache.interned_strings_buffer"]))
	o.MaxFiles = int(number(d["opcache.max_accelerated_files"]))
	o.ValidateTimestamps = truthy(d["opcache.validate_timestamps"])
	o.RevalidateFreq = int(number(d["opcache.revalidate_freq"]))
	if s := r.Status; s != nil {
		o.Enabled = s.Enabled
		mb := func(b float64) float64 { return round1(b / (1 << 20)) }
		o.UsedMB, o.FreeMB, o.WastedMB = mb(s.Memory.Used), mb(s.Memory.Free), mb(s.Memory.Wasted)
		o.StringsUsedMB, o.StringsFreeMB = mb(s.Strings.Used), mb(s.Strings.Free)
		o.CachedScripts, o.CachedKeys, o.MaxCachedKeys = s.Stats.Scripts, s.Stats.Keys, s.Stats.MaxKeys
		o.Hits, o.Misses, o.StartTime = s.Stats.Hits, s.Stats.Misses, s.Stats.StartTime
		o.OOMRestarts, o.HashRestarts, o.ManualRestarts = s.Stats.OOMRestarts, s.Stats.HashRestarts, s.Stats.ManualRestarts
	}
	return o, nil
}

func truthy(v any) bool {
	switch x := v.(type) {
	case bool:
		return x
	case float64:
		return x != 0
	case string:
		return x == "1" || strings.EqualFold(x, "on") || strings.EqualFold(x, "true")
	}
	return false
}

func number(v any) float64 {
	switch x := v.(type) {
	case float64:
		return x
	case string:
		f, _ := strconv.ParseFloat(x, 64)
		return f
	case bool:
		if x {
			return 1
		}
	}
	return 0
}

func round1(f float64) float64 { return float64(int64(f*10+0.5)) / 10 }

type poolFile struct {
	Pool
	socket string
}

// readPools reads the sites' pools Serfex wrote for a version.
func readPools(h host.Host, v string) []poolFile {
	files, _ := filepath.Glob(h.Path("/etc/php/" + v + "/fpm/pool.d/*.conf"))
	var out []poolFile
	for _, f := range files {
		b, err := os.ReadFile(f)
		if err != nil || !txn.HasMarker(b) {
			continue
		}
		p := poolFile{Pool: Pool{PM: "ondemand"}}
		for _, line := range strings.Split(string(b), "\n") {
			line = strings.TrimSpace(line)
			if strings.HasPrefix(line, "[") && strings.HasSuffix(line, "]") {
				p.Domain = line[1 : len(line)-1]
				continue
			}
			k, val, ok := strings.Cut(line, "=")
			if !ok {
				continue
			}
			switch k, val = strings.TrimSpace(k), strings.TrimSpace(val); k {
			case "pm":
				p.PM = val
			case "pm.max_children":
				p.MaxChildren, _ = strconv.Atoi(val)
			case "pm.status_listen":
				p.socket = val
			}
		}
		// Sites' pools have a status socket; webmail's and the probe's
		// do not.
		if p.Domain == "" || p.socket == "" || strings.ContainsAny(p.Domain, "_/ ") {
			continue
		}
		out = append(out, p)
	}
	sort.Slice(out, func(i, j int) bool { return out[i].Domain < out[j].Domain })
	return out
}

type workerMemory struct {
	workers int
	mb      float64
}

// poolMemory adds up the private memory of each pool's workers.
func poolMemory(h host.Host) map[string]workerMemory {
	out := map[string]workerMemory{}
	dirs, _ := filepath.Glob(h.Path("/proc/[0-9]*"))
	page := float64(os.Getpagesize())
	for _, dir := range dirs {
		cmd, err := os.ReadFile(filepath.Join(dir, "cmdline"))
		if err != nil {
			continue
		}
		name, ok := strings.CutPrefix(strings.TrimSpace(strings.ReplaceAll(string(cmd), "\x00", " ")), "php-fpm: pool ")
		if !ok {
			continue
		}
		private, ok := privatePages(filepath.Join(dir, "statm"))
		if !ok {
			continue
		}
		w := out[name]
		w.workers++
		w.mb += float64(private) * page / (1 << 20)
		out[name] = w
	}
	return out
}

// privatePages is a process's resident memory without what it shares
// with others (libraries, OPcache), from /proc/<pid>/statm.
func privatePages(statm string) (int64, bool) {
	b, err := os.ReadFile(statm)
	if err != nil {
		return 0, false
	}
	f := strings.Fields(string(b))
	if len(f) < 3 {
		return 0, false
	}
	resident, _ := strconv.ParseInt(f[1], 10, 64)
	shared, _ := strconv.ParseInt(f[2], 10, 64)
	return max(0, resident-shared), true
}

// demoPHPMeasurement stands in for PHP-FPM on the simulator: OPcache
// nearly full and a shop whose pool runs out of workers every evening.
func demoPHPMeasurement(unit, v string, now time.Time) PHPMeasurement {
	hours := float64(now.Unix()%(7*86400)) / 3600
	start := now.Add(-time.Duration(hours*float64(time.Hour)) - 12*24*time.Hour).Unix()
	return PHPMeasurement{
		Unit: unit, Kind: "php", TakenAt: now.UTC(), Version: v, CPUs: 8, MemoryMB: 32096, AvailableMB: 27400, SwapMB: 4096,
		ProcessesMB: map[string]int64{"mysql": 612, "php-fpm": 1180, "redis": 310, "memcached": 0, "postgresql": 240},
		OPcache: &OPcache{
			Loaded: true, Enabled: true, MemoryMB: 128, UsedMB: 121.4, FreeMB: 2.1, WastedMB: 4.5, StringsMB: 16, StringsUsedMB: 15.6, StringsFreeMB: 0.4,
			MaxFiles: 20000, CachedScripts: 9840, CachedKeys: 12210, MaxCachedKeys: 32531, Hits: int64(48000000 + 410000*hours), Misses: int64(90000 + 1200*hours),
			OOMRestarts: 3 + int(hours/48), StartTime: start, ValidateTimestamps: true, RevalidateFreq: 2,
		},
		Pools: []Pool{
			{Domain: "roifex.com", PM: "ondemand", MaxChildren: 10, StartTime: start, Accepted: int(900000 + 5200*hours), MaxReached: 14 + int(hours/12),
				MaxActive: 10, MaxQueue: 23, Active: 4, Idle: 2, Workers: 6, MemoryMB: 384, Read: true},
			{Domain: "serfex.ai", PM: "dynamic", MaxChildren: 20, StartTime: start, Accepted: int(40000 + 210*hours), MaxActive: 3, Active: 0, Idle: 10, Workers: 10, MemoryMB: 420, Read: true},
		},
	}
}
