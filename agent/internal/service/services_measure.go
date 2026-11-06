package service

import (
	"bufio"
	"bytes"
	"compress/gzip"
	"context"
	"fmt"
	"io"
	"net"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/calltech-app/serfex/agent/internal/host"
)

// RedisSites is the "unit" optimization measures the sites' own Redis
// instances under (serfex-redis-<domain>.service, one per Laravel
// application); it is not a service of its own.
const RedisSites = "redis-sites"

const (
	redisConfDir   = "/etc/serfex/redis"
	memcachedConf  = "/etc/memcached.conf"
	nginxErrorLog  = "/var/log/nginx/error.log"
	logWindow      = 7 * 24 * time.Hour
	maxLogScan     = 256 << 20
	notEnoughConns = "worker_connections are not enough"
	tooManyFiles   = "Too many open files"
)

// Machine is what every measurement says about the machine itself.
type Machine struct {
	CPUs     int   `json:"cpus"`
	MemoryMB int64 `json:"memory_mb"`
	// AvailableMB is memory available without swapping.
	AvailableMB int64 `json:"available_mb"`
	SwapMB      int64 `json:"swap_mb"`
	SwapUsedMB  int64 `json:"swap_used_mb"`
	// ProcessesMB is the memory of the services sharing the machine.
	ProcessesMB map[string]int64 `json:"processes_mb"`
}

func readMachine(h host.Host) Machine {
	var mc Machine
	mc.MemoryMB, mc.AvailableMB, mc.SwapMB, mc.SwapUsedMB = readMemory(h)
	mc.CPUs = countCPUs(h)
	mc.ProcessesMB = processMemory(h)
	return mc
}

// NginxMeasurement is what the suggestions for Nginx are worked out
// from: its limits, how many connections a worker holds and how often
// the error log says the limits were reached in the last 7 days (and the
// last day).
type NginxMeasurement struct {
	Unit    string    `json:"unit"`
	Kind    string    `json:"kind"`
	TakenAt time.Time `json:"taken_at"`
	Machine
	// WorkerProcesses is the configured value ("auto" or a number);
	// Workers is how many run.
	WorkerProcesses    string `json:"worker_processes"`
	Workers            int    `json:"workers"`
	WorkerConnections  int    `json:"worker_connections"`
	WorkerRlimitNofile int    `json:"worker_rlimit_nofile"`
	// FileLimit is the open files limit a worker runs with.
	FileLimit int64 `json:"file_limit"`
	// Sockets is the most connections one worker held when measured.
	Sockets int `json:"sockets"`
	// NotEnough counts "worker_connections are not enough" and
	// TooManyFiles "Too many open files" in the error log; the Day ones
	// only the last 24 hours.
	NotEnough       int `json:"not_enough"`
	NotEnoughDay    int `json:"not_enough_day"`
	TooManyFiles    int `json:"too_many_files"`
	TooManyFilesDay int `json:"too_many_files_day"`
}

func (m *Manager) measureNginx(ctx context.Context, unit string) (NginxMeasurement, error) {
	if m.sim {
		return demoNginx(unit, m.now()), nil
	}
	out := NginxMeasurement{Unit: unit, Kind: "nginx", TakenAt: m.now().UTC(), Machine: readMachine(m.h)}
	s, err := m.Settings(ctx, unit)
	if err == nil && s.Nginx != nil {
		out.WorkerProcesses, out.WorkerConnections, out.WorkerRlimitNofile = s.Nginx.WorkerProcesses, s.Nginx.WorkerConnections, s.Nginx.WorkerRlimitNofile
	}
	dirs, _ := filepath.Glob(m.h.Path("/proc/[0-9]*"))
	for _, dir := range dirs {
		cmd, err := os.ReadFile(filepath.Join(dir, "cmdline"))
		if err != nil || !bytes.HasPrefix(cmd, []byte("nginx: worker process")) {
			continue
		}
		out.Workers++
		if limit := openFilesLimit(filepath.Join(dir, "limits")); limit > 0 && (out.FileLimit == 0 || limit < out.FileLimit) {
			out.FileLimit = limit
		}
		fds, _ := os.ReadDir(filepath.Join(dir, "fd"))
		n := 0
		for _, fd := range fds {
			if target, err := os.Readlink(filepath.Join(dir, "fd", fd.Name())); err == nil && strings.HasPrefix(target, "socket:") {
				n++
			}
		}
		out.Sockets = max(out.Sockets, n)
	}
	counts := scanLogs(m.h, nginxErrorLog, m.now(), []string{notEnoughConns, tooManyFiles})
	out.NotEnough, out.NotEnoughDay = counts[0][0], counts[0][1]
	out.TooManyFiles, out.TooManyFilesDay = counts[1][0], counts[1][1]
	return out, nil
}

// openFilesLimit reads the soft "Max open files" of a process.
func openFilesLimit(path string) int64 {
	b, err := os.ReadFile(path)
	if err != nil {
		return 0
	}
	for _, line := range strings.Split(string(b), "\n") {
		if strings.HasPrefix(line, "Max open files") {
			if f := strings.Fields(strings.TrimPrefix(line, "Max open files")); len(f) > 0 {
				n, _ := strconv.ParseInt(f[0], 10, 64)
				return n
			}
		}
	}
	return 0
}

// scanLogs counts the lines of an Nginx error log (with its rotated
// copies, compressed or not) holding each of words in the last 7 days
// and the last day: [word][week, day].
func scanLogs(h host.Host, log string, now time.Time, words []string) [][2]int {
	out := make([][2]int, len(words))
	files := []string{h.Path(log), h.Path(log + ".1")}
	for i := 2; i <= 8; i++ {
		files = append(files, h.Path(fmt.Sprintf("%s.%d.gz", log, i)))
	}
	week, day := now.Add(-logWindow), now.Add(-24*time.Hour)
	for _, file := range files {
		f, err := os.Open(file)
		if err != nil {
			continue
		}
		var r io.Reader = f
		if strings.HasSuffix(file, ".gz") {
			gz, err := gzip.NewReader(f)
			if err != nil {
				f.Close()
				continue
			}
			r = gz
		}
		sc := bufio.NewScanner(io.LimitReader(r, maxLogScan))
		sc.Buffer(make([]byte, 64<<10), 1<<20)
		for sc.Scan() {
			line := sc.Bytes()
			for i, w := range words {
				if !bytes.Contains(line, []byte(w)) || len(line) < 19 {
					continue
				}
				at, err := time.ParseInLocation("2006/01/02 15:04:05", string(line[:19]), time.Local)
				if err != nil || at.Before(week) {
					continue
				}
				out[i][0]++
				if at.After(day) {
					out[i][1]++
				}
			}
		}
		f.Close()
	}
	return out
}

// PostgresMeasurement is what the suggestions for the customers'
// PostgreSQL are worked out from: its memory settings and its counters
// (blocks read from the cache and from disk, temporary files) since its
// statistics started.
type PostgresMeasurement struct {
	Unit    string    `json:"unit"`
	Kind    string    `json:"kind"`
	TakenAt time.Time `json:"taken_at"`
	Version string    `json:"version"`
	Machine
	Disk string `json:"disk"`
	// Settings are in MB for the memory ones (shared_buffers_mb,
	// effective_cache_size_mb, work_mem_mb, maintenance_work_mem_mb), as
	// PostgreSQL shows them for the others.
	Settings     map[string]string `json:"settings"`
	StartTime    int64             `json:"start_time"`
	BlocksHit    int64             `json:"blocks_hit"`
	BlocksRead   int64             `json:"blocks_read"`
	TempFiles    int64             `json:"temp_files"`
	TempBytes    int64             `json:"temp_bytes"`
	Transactions int64             `json:"transactions"`
	// Backends is the number of client connections when measured.
	Backends  int   `json:"backends"`
	DataBytes int64 `json:"data_bytes"`
}

var pgMeasured = []string{"shared_buffers", "effective_cache_size", "work_mem", "maintenance_work_mem", "max_connections", "random_page_cost", "effective_io_concurrency"}

func (m *Manager) measurePostgres(ctx context.Context, unit string) (PostgresMeasurement, error) {
	v := pgVersionOf(unit)
	if m.sim {
		return demoPostgres(unit, v, m.now()), nil
	}
	out := PostgresMeasurement{Unit: unit, Kind: "postgresql", TakenAt: m.now().UTC(), Machine: readMachine(m.h), Settings: map[string]string{}}
	out.Disk = diskKind(m.h, "/var/lib/postgresql")
	ctx, cancel := context.WithTimeout(ctx, 20*time.Second)
	defer cancel()
	rows, err := m.psql(ctx, v, "SELECT 'set', name, setting, COALESCE(unit, '') FROM pg_settings WHERE name IN ("+quoted(pgMeasured)+");\n"+
		"SELECT 'stat', COALESCE(sum(blks_hit), 0), COALESCE(sum(blks_read), 0), COALESCE(sum(temp_files), 0), COALESCE(sum(temp_bytes), 0), COALESCE(sum(xact_commit + xact_rollback), 0) FROM pg_stat_database;\n"+
		"SELECT 'run', extract(epoch FROM pg_postmaster_start_time())::bigint, (SELECT count(*) FROM pg_stat_activity WHERE backend_type = 'client backend'), "+
		"(SELECT COALESCE(sum(pg_database_size(oid)), 0) FROM pg_database WHERE datallowconn AND NOT datistemplate), current_setting('server_version');\n")
	if err != nil {
		return PostgresMeasurement{}, &OperationError{err}
	}
	num := func(s string) int64 { n, _ := strconv.ParseFloat(s, 64); return int64(n) }
	for _, r := range rows {
		switch {
		case r[0] == "set" && len(r) == 4:
			name, value := r[1], r[2]
			if mb, ok := pgMB(value, r[3]); ok {
				out.Settings[name+"_mb"] = strconv.FormatInt(mb, 10)
			} else {
				out.Settings[name] = value
			}
		case r[0] == "stat" && len(r) == 6:
			out.BlocksHit, out.BlocksRead, out.TempFiles, out.TempBytes, out.Transactions = num(r[1]), num(r[2]), num(r[3]), num(r[4]), num(r[5])
		case r[0] == "run" && len(r) == 5:
			out.StartTime, out.Backends, out.DataBytes, out.Version = num(r[1]), int(num(r[2])), num(r[3]), r[4]
		}
	}
	return out, nil
}

// pgMB turns a memory setting as pg_settings shows it (a number of
// units such as 8kB) into MB.
func pgMB(value, unit string) (int64, bool) {
	n, err := strconv.ParseInt(value, 10, 64)
	if err != nil {
		return 0, false
	}
	switch unit {
	case "8kB":
		return n * 8 >> 10, true
	case "kB":
		return n >> 10, true
	case "MB":
		return n, true
	}
	return 0, false
}

// psql runs SQL as postgres on the customers' cluster of version v and
// returns its rows.
func (m *Manager) psql(ctx context.Context, v, script string) ([][]string, error) {
	out, err := m.h.Run(ctx, host.Command{
		Name:  "setpriv",
		Args:  []string{"--reuid=postgres", "--regid=postgres", "--init-groups", "--reset-env", "--", "psql", "-X", "-q", "-A", "-t", "-F", "\t", "-v", "ON_ERROR_STOP=1", "-p", m.pgPort(v), "-d", "postgres"},
		Stdin: []byte(script),
	})
	if err != nil {
		return nil, fmt.Errorf("%s", tail(out, err))
	}
	var rows [][]string
	for _, line := range strings.Split(strings.TrimRight(out, "\n"), "\n") {
		if line != "" {
			rows = append(rows, strings.Split(line, "\t"))
		}
	}
	return rows, nil
}

var pgPortRe = regexp.MustCompile(`(?m)^\s*port\s*=\s*(\d+)`)

// pgPort is the port of the customers' cluster of version v.
func (m *Manager) pgPort(v string) string {
	if b, err := os.ReadFile(m.h.Path("/etc/postgresql/" + v + "/main/postgresql.conf")); err == nil {
		if mt := pgPortRe.FindSubmatch(b); mt != nil {
			return string(mt[1])
		}
	}
	return "5432"
}

var pgUnitRe = regexp.MustCompile(`^postgresql@(\d+)-main$`)

// pgVersionOf is the major version of a customers' cluster unit, "" for
// other units.
func pgVersionOf(unit string) string {
	if mt := pgUnitRe.FindStringSubmatch(unit); mt != nil {
		return mt[1]
	}
	return ""
}

// MemcachedMeasurement is what the suggestions for Memcached are worked
// out from: its settings and its counters since it started.
type MemcachedMeasurement struct {
	Unit    string    `json:"unit"`
	Kind    string    `json:"kind"`
	TakenAt time.Time `json:"taken_at"`
	Version string    `json:"version"`
	Machine
	MemoryMB       int              `json:"memcached_mb"`
	MaxConnections int              `json:"max_connections"`
	Stats          map[string]int64 `json:"stats"`
	StartTime      int64            `json:"start_time"`
}

var memcachedStats = []string{"limit_maxbytes", "bytes", "evictions", "curr_items", "curr_connections", "max_connections", "listen_disabled_num",
	"rejected_connections", "get_hits", "get_misses", "uptime", "time", "total_connections"}

func (m *Manager) measureMemcached(ctx context.Context, unit string) (MemcachedMeasurement, error) {
	if m.sim {
		return demoMemcached(unit, m.now()), nil
	}
	out := MemcachedMeasurement{Unit: unit, Kind: "memcached", TakenAt: m.now().UTC(), Machine: readMachine(m.h), Stats: map[string]int64{}}
	conf, _ := os.ReadFile(m.h.Path(memcachedConf))
	mc := parseMemcached(conf)
	out.MemoryMB, out.MaxConnections = mc.MemoryMB, mc.MaxConnections
	network, addr := "tcp", net.JoinHostPort(mc.listen, mc.port)
	if mc.socket != "" {
		network, addr = "unix", m.h.Path(mc.socket)
	}
	d := net.Dialer{Timeout: 3 * time.Second}
	conn, err := d.DialContext(ctx, network, addr)
	if err != nil {
		return MemcachedMeasurement{}, &OperationError{fmt.Errorf("memcached does not answer on %s: %w", addr, err)}
	}
	defer conn.Close()
	_ = conn.SetDeadline(time.Now().Add(5 * time.Second))
	if _, err := conn.Write([]byte("stats\r\n")); err != nil {
		return MemcachedMeasurement{}, &OperationError{err}
	}
	want := map[string]bool{}
	for _, s := range memcachedStats {
		want[s] = true
	}
	sc := bufio.NewScanner(conn)
	for sc.Scan() {
		line := strings.TrimSpace(sc.Text())
		if line == "END" || strings.HasPrefix(line, "ERROR") {
			break
		}
		f := strings.Fields(line)
		if len(f) != 3 || f[0] != "STAT" {
			continue
		}
		if f[1] == "version" {
			out.Version = f[2]
		} else if want[f[1]] {
			out.Stats[f[1]], _ = strconv.ParseInt(f[2], 10, 64)
		}
	}
	if len(out.Stats) == 0 {
		return MemcachedMeasurement{}, &OperationError{fmt.Errorf("memcached gave no statistics: %v", sc.Err())}
	}
	out.StartTime = out.Stats["time"] - out.Stats["uptime"]
	return out, nil
}

// memcachedConfig is what Serfex reads from /etc/memcached.conf.
type memcachedConfig struct {
	MemcachedSettings
	listen, port, socket string
}

var memcachedLineRe = regexp.MustCompile(`^\s*-([mcplsU])\s+(\S+)`)

func parseMemcached(conf []byte) memcachedConfig {
	out := memcachedConfig{MemcachedSettings: MemcachedSettings{MemoryMB: 64, MaxConnections: 1024}, listen: "127.0.0.1", port: "11211"}
	for _, line := range strings.Split(string(conf), "\n") {
		mt := memcachedLineRe.FindStringSubmatch(line)
		if mt == nil {
			continue
		}
		switch mt[1] {
		case "m":
			out.MemoryMB, _ = strconv.Atoi(mt[2])
		case "c":
			out.MaxConnections, _ = strconv.Atoi(mt[2])
		case "p":
			out.port = mt[2]
		case "l":
			out.listen, _, _ = strings.Cut(mt[2], ",")
		case "s":
			out.socket = mt[2]
		}
	}
	return out
}

// RedisMeasurement is what the suggestions for the sites' Redis
// instances are worked out from.
type RedisMeasurement struct {
	Unit    string    `json:"unit"`
	Kind    string    `json:"kind"`
	TakenAt time.Time `json:"taken_at"`
	Machine
	Instances []RedisInstance `json:"instances"`
}

// RedisInstance is one site's Redis: its memory limit and use and, since
// it started, the keys it evicted and the writes it refused for lack of
// memory (with maxmemory-policy noeviction, Serfex's setting, a full
// instance refuses writes).
type RedisInstance struct {
	Domain      string  `json:"domain"`
	Port        int     `json:"port"`
	MaxMemoryMB int     `json:"max_memory_mb"`
	Read        bool    `json:"read"`
	Error       string  `json:"error,omitempty"`
	UsedMB      float64 `json:"used_mb"`
	PeakMB      float64 `json:"peak_mb"`
	Keys        int64   `json:"keys"`
	Evicted     int64   `json:"evicted"`
	OOMErrors   int64   `json:"oom_errors"`
	StartTime   int64   `json:"start_time"`
}

var (
	redisPortRe = regexp.MustCompile(`(?m)^port (\d+)$`)
	redisPassRe = regexp.MustCompile(`(?m)^requirepass "([A-Za-z0-9]+)"$`)
	redisMemRe  = regexp.MustCompile(`(?m)^maxmemory (\d+)mb$`)
)

func (m *Manager) measureRedis(ctx context.Context) (RedisMeasurement, error) {
	if m.sim {
		return demoRedis(m.now()), nil
	}
	out := RedisMeasurement{Unit: RedisSites, Kind: "redis", TakenAt: m.now().UTC(), Machine: readMachine(m.h), Instances: []RedisInstance{}}
	files, _ := filepath.Glob(m.h.Path(redisConfDir + "/*.conf"))
	sort.Strings(files)
	for _, f := range files {
		conf, err := os.ReadFile(f)
		if err != nil {
			continue
		}
		in := RedisInstance{Domain: strings.TrimSuffix(filepath.Base(f), ".conf")}
		if mt := redisPortRe.FindSubmatch(conf); mt != nil {
			in.Port, _ = strconv.Atoi(string(mt[1]))
		}
		if mt := redisMemRe.FindSubmatch(conf); mt != nil {
			in.MaxMemoryMB, _ = strconv.Atoi(string(mt[1]))
		}
		var pass string
		if mt := redisPassRe.FindSubmatch(conf); mt != nil {
			pass = string(mt[1])
		}
		cctx, cancel := context.WithTimeout(ctx, 3*time.Second)
		info, err := m.h.Run(cctx, host.Command{Name: "redis-cli", Args: []string{"-h", "127.0.0.1", "-p", strconv.Itoa(in.Port), "--no-auth-warning", "INFO"}, Env: []string{"REDISCLI_AUTH=" + pass}})
		cancel()
		if err != nil {
			in.Error = tail(info, err)
		} else {
			readRedisInfo(info, &in, m.now())
		}
		out.Instances = append(out.Instances, in)
	}
	return out, nil
}

func readRedisInfo(info string, in *RedisInstance, now time.Time) {
	for _, line := range strings.Split(info, "\n") {
		k, v, ok := strings.Cut(strings.TrimSpace(line), ":")
		if !ok {
			continue
		}
		n, _ := strconv.ParseInt(v, 10, 64)
		switch {
		case k == "used_memory":
			in.UsedMB = round1(float64(n) / (1 << 20))
		case k == "used_memory_peak":
			in.PeakMB = round1(float64(n) / (1 << 20))
		case k == "evicted_keys":
			in.Evicted = n
		case k == "uptime_in_seconds":
			in.StartTime = now.Unix() - n
		case k == "errorstat_OOM":
			// count=12
			_, c, _ := strings.Cut(v, "count=")
			c, _, _ = strings.Cut(c, ",")
			in.OOMErrors, _ = strconv.ParseInt(c, 10, 64)
		case strings.HasPrefix(k, "db"):
			_, c, _ := strings.Cut(v, "keys=")
			c, _, _ = strings.Cut(c, ",")
			keys, _ := strconv.ParseInt(c, 10, 64)
			in.Keys += keys
		}
	}
	in.Read = true
}

func demoMachine() Machine {
	return Machine{CPUs: 8, MemoryMB: 32096, AvailableMB: 27400, SwapMB: 4096, ProcessesMB: map[string]int64{"mysql": 612, "php-fpm": 1180, "redis": 310, "memcached": 60, "postgresql": 240}}
}

// demoNginx: a busy week ran out of connections twice.
func demoNginx(unit string, now time.Time) NginxMeasurement {
	return NginxMeasurement{Unit: unit, Kind: "nginx", TakenAt: now.UTC(), Machine: demoMachine(), WorkerProcesses: "auto", Workers: 8,
		WorkerConnections: 768, FileLimit: 1024, Sockets: 212, NotEnough: 37, NotEnoughDay: 4}
}

// demoPostgres: the package's settings on a 32 GB machine with 6 GB of
// data that no longer fits shared_buffers.
func demoPostgres(unit, v string, now time.Time) PostgresMeasurement {
	hours := float64(now.Unix()%(7*86400)) / 3600
	grow := func(base, perHour float64) int64 { return int64(base + perHour*hours) }
	return PostgresMeasurement{Unit: unit, Kind: "postgresql", TakenAt: now.UTC(), Version: v + ".1", Machine: demoMachine(), Disk: "nvme",
		Settings: map[string]string{"shared_buffers_mb": "128", "effective_cache_size_mb": "4096", "work_mem_mb": "4", "maintenance_work_mem_mb": "64",
			"max_connections": "100", "random_page_cost": "4", "effective_io_concurrency": "1"},
		StartTime: now.Unix() - 1000000 - int64(hours*3600), BlocksHit: grow(4e9, 2.6e6), BlocksRead: grow(2e8, 110000), TempFiles: grow(9000, 14),
		TempBytes: grow(9e10, 1.4e8), Transactions: grow(5e7, 30000), Backends: 14, DataBytes: 6_442_450_944}
}

// demoMemcached: full and evicting.
func demoMemcached(unit string, now time.Time) MemcachedMeasurement {
	hours := float64(now.Unix()%(7*86400)) / 3600
	up := int64(1000000 + hours*3600)
	return MemcachedMeasurement{Unit: unit, Kind: "memcached", TakenAt: now.UTC(), Version: "1.6.24", Machine: demoMachine(), MemoryMB: 64, MaxConnections: 1024,
		Stats: map[string]int64{"limit_maxbytes": 64 << 20, "bytes": 63 << 20, "evictions": int64(80000 + 310*hours), "curr_items": 210000,
			"curr_connections": 12, "max_connections": 1024, "listen_disabled_num": 0, "rejected_connections": 0,
			"get_hits": int64(9e7 + 61000*hours), "get_misses": int64(1.2e7 + 9500*hours), "uptime": up, "time": now.Unix(), "total_connections": 51000},
		StartTime: now.Unix() - up}
}

// demoRedis: roifex.com's Redis is close to its limit.
func demoRedis(now time.Time) RedisMeasurement {
	return RedisMeasurement{Unit: RedisSites, Kind: "redis", TakenAt: now.UTC(), Machine: demoMachine(), Instances: []RedisInstance{
		{Domain: "roifex.com", Port: 6380, MaxMemoryMB: 128, Read: true, UsedMB: 109.4, PeakMB: 121.7, Keys: 48211, StartTime: now.Unix() - 900000},
		{Domain: "serfex.ai", Port: 6381, MaxMemoryMB: 128, Read: true, UsedMB: 6.2, PeakMB: 9.8, Keys: 312, StartTime: now.Unix() - 900000},
	}}
}
