package service

import (
	"bufio"
	"context"
	"fmt"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
	"time"

	"github.com/calltech-app/serfex/agent/internal/host"
	"golang.org/x/sys/unix"
)

// Measurement is what the panel's optimization suggestions for the
// database server are worked out from: the machine, the server's
// settings and counters, how much data it holds, its most expensive
// statements and how much memory the services sharing the machine use.
// Counters run from the server's start; the panel compares measurements
// to see what happened in between.
type Measurement struct {
	Unit     string    `json:"unit"`
	TakenAt  time.Time `json:"taken_at"`
	Version  string    `json:"version"`
	CPUs     int       `json:"cpus"`
	MemoryMB int64     `json:"memory_mb"`
	// AvailableMB is memory available without swapping.
	AvailableMB int64 `json:"available_mb"`
	SwapMB      int64 `json:"swap_mb"`
	SwapUsedMB  int64 `json:"swap_used_mb"`
	// Disk is the kind of disk the data lives on: nvme, ssd, hdd or ""
	// when it cannot be told.
	Disk      string            `json:"disk"`
	Variables map[string]string `json:"variables"`
	Status    map[string]string `json:"status"`
	// DataBytes is the size of InnoDB tables and their indexes.
	DataBytes int64 `json:"data_bytes"`
	Tables    int   `json:"tables"`
	// Digests are the statements that took the most time since the start
	// (MySQL's performance_schema; empty when it is off, as on MariaDB).
	Digests []Digest `json:"digests"`
	// ProcessesMB is the memory (RSS) of the services sharing the
	// machine: mysql, php-fpm, redis, memcached, postgresql.
	ProcessesMB map[string]int64 `json:"processes_mb"`
}

// Digest is one normalized statement: its literals are replaced with "?",
// so it carries no customer data.
type Digest struct {
	Schema       string  `json:"schema"`
	Digest       string  `json:"digest"`
	Text         string  `json:"text"`
	Count        int64   `json:"count"`
	TimeMS       float64 `json:"time_ms"`
	RowsExamined int64   `json:"rows_examined"`
	RowsSent     int64   `json:"rows_sent"`
}

var measuredVariables = []string{
	"version", "innodb_buffer_pool_size", "max_connections", "max_allowed_packet", "tmp_table_size", "max_heap_table_size",
	"table_open_cache", "innodb_io_capacity", "innodb_io_capacity_max", "slow_query_log", "long_query_time",
	"innodb_redo_log_capacity", "innodb_log_file_size", "innodb_flush_log_at_trx_commit", "performance_schema",
	"sort_buffer_size", "join_buffer_size", "read_buffer_size", "read_rnd_buffer_size", "thread_stack", "binlog_cache_size",
}

var measuredStatus = []string{
	"Uptime", "Max_used_connections", "Threads_connected", "Connection_errors_max_connections", "Created_tmp_disk_tables",
	"Created_tmp_tables", "Innodb_buffer_pool_reads", "Innodb_buffer_pool_read_requests", "Opened_tables", "Questions",
	"Slow_queries", "Innodb_os_log_written",
}

// systemSchemas hold no customer data.
const systemSchemas = "'mysql','sys','information_schema','performance_schema'"

// Measure reads a running MySQL or MariaDB server.
func (m *Manager) Measure(ctx context.Context, unit string) (Measurement, error) {
	d, st, err := m.find(ctx, unit)
	if err != nil {
		return Measurement{}, err
	}
	if d.settings != "database" {
		return Measurement{}, invalid("%s is not a database server", d.name)
	}
	if st.State != "active" && st.State != "reloading" {
		return Measurement{}, invalid("%s is not running; start it first", d.name)
	}
	if m.sim {
		return demoMeasurement(unit, m.now()), nil
	}
	out := Measurement{Unit: unit, TakenAt: m.now().UTC(), Variables: map[string]string{}, Status: map[string]string{}}
	out.MemoryMB, out.AvailableMB, out.SwapMB, out.SwapUsedMB = readMemory(m.h)
	out.CPUs = countCPUs(m.h)
	out.Disk = diskKind(m.h, "/var/lib/mysql")
	out.ProcessesMB = processMemory(m.h)

	ctx, cancel := context.WithTimeout(ctx, 20*time.Second)
	defer cancel()
	rows, err := m.sql(ctx, "SHOW GLOBAL VARIABLES WHERE Variable_name IN ("+quoted(measuredVariables)+")")
	if err != nil {
		return Measurement{}, &OperationError{err}
	}
	for _, r := range rows {
		if len(r) == 2 {
			out.Variables[r[0]] = r[1]
		}
	}
	out.Version = out.Variables["version"]
	if rows, err = m.sql(ctx, "SHOW GLOBAL STATUS WHERE Variable_name IN ("+quoted(measuredStatus)+")"); err != nil {
		return Measurement{}, &OperationError{err}
	}
	for _, r := range rows {
		if len(r) == 2 {
			out.Status[r[0]] = r[1]
		}
	}
	if rows, err = m.sql(ctx, "SELECT COALESCE(SUM(data_length + index_length), 0), COUNT(*) FROM information_schema.TABLES "+
		"WHERE engine = 'InnoDB' AND table_schema NOT IN ("+systemSchemas+")"); err == nil && len(rows) == 1 && len(rows[0]) == 2 {
		out.DataBytes, _ = strconv.ParseInt(rows[0][0], 10, 64)
		out.Tables, _ = strconv.Atoi(rows[0][1])
	}
	out.Digests = []Digest{}
	if strings.EqualFold(out.Variables["performance_schema"], "ON") {
		rows, err = m.sql(ctx, "SELECT SCHEMA_NAME, DIGEST, DIGEST_TEXT, COUNT_STAR, ROUND(SUM_TIMER_WAIT / 1000000000, 1), SUM_ROWS_EXAMINED, SUM_ROWS_SENT "+
			"FROM performance_schema.events_statements_summary_by_digest WHERE SCHEMA_NAME IS NOT NULL AND DIGEST IS NOT NULL "+
			"AND SCHEMA_NAME NOT IN ("+systemSchemas+") ORDER BY SUM_TIMER_WAIT DESC LIMIT 50")
		if err == nil {
			for _, r := range rows {
				if len(r) != 7 {
					continue
				}
				dg := Digest{Schema: r[0], Digest: r[1], Text: unescape(r[2])}
				dg.Count, _ = strconv.ParseInt(r[3], 10, 64)
				dg.TimeMS, _ = strconv.ParseFloat(r[4], 64)
				dg.RowsExamined, _ = strconv.ParseInt(r[5], 10, 64)
				dg.RowsSent, _ = strconv.ParseInt(r[6], 10, 64)
				out.Digests = append(out.Digests, dg)
			}
		}
	}
	return out, nil
}

// sql runs a query as root over the local socket and returns its rows.
func (m *Manager) sql(ctx context.Context, query string) ([][]string, error) {
	out, err := m.h.Run(ctx, host.Command{Name: "mysql", Args: []string{"--batch", "--skip-column-names", "-e", query}})
	if err != nil {
		return nil, err
	}
	var rows [][]string
	for _, line := range strings.Split(strings.TrimRight(out, "\n"), "\n") {
		if line != "" {
			rows = append(rows, strings.Split(line, "\t"))
		}
	}
	return rows, nil
}

func quoted(names []string) string {
	q := make([]string, len(names))
	for i, n := range names {
		q[i] = "'" + n + "'"
	}
	return strings.Join(q, ",")
}

// unescape undoes the mysql client's batch escaping of a value.
func unescape(s string) string {
	return strings.NewReplacer(`\n`, " ", `\t`, " ", `\\`, `\`, `\0`, "").Replace(s)
}

func readMemory(h host.Host) (total, available, swap, swapUsed int64) {
	f, err := os.Open(h.Path("/proc/meminfo"))
	if err != nil {
		return
	}
	defer f.Close()
	kb := map[string]int64{}
	sc := bufio.NewScanner(f)
	for sc.Scan() {
		name, rest, ok := strings.Cut(sc.Text(), ":")
		if !ok {
			continue
		}
		v, _ := strconv.ParseInt(strings.TrimSuffix(strings.TrimSpace(rest), " kB"), 10, 64)
		kb[name] = v
	}
	return kb["MemTotal"] >> 10, kb["MemAvailable"] >> 10, kb["SwapTotal"] >> 10, (kb["SwapTotal"] - kb["SwapFree"]) >> 10
}

func countCPUs(h host.Host) int {
	b, err := os.ReadFile(h.Path("/proc/cpuinfo"))
	if err != nil {
		return 0
	}
	n := 0
	for _, line := range strings.Split(string(b), "\n") {
		if strings.HasPrefix(line, "processor") {
			n++
		}
	}
	return n
}

// diskKind tells what kind of disk holds dir: the block device of its
// file system, or, for LVM and RAID, the first disk under it.
func diskKind(h host.Host, dir string) string {
	var st syscall.Stat_t
	if err := syscall.Stat(h.Path(dir), &st); err != nil {
		return ""
	}
	dev := uint64(st.Dev)
	sys, err := filepath.EvalSymlinks(h.Path(fmt.Sprintf("/sys/dev/block/%d:%d", unix.Major(dev), unix.Minor(dev))))
	if err != nil {
		return ""
	}
	for range 4 {
		if slaves, _ := filepath.Glob(filepath.Join(sys, "slaves", "*")); len(slaves) > 0 {
			if s, err := filepath.EvalSymlinks(slaves[0]); err == nil {
				sys = s
				continue
			}
		}
		break
	}
	// A partition has no queue of its own; its disk is the parent.
	if _, err := os.Stat(filepath.Join(sys, "queue")); err != nil {
		sys = filepath.Dir(sys)
	}
	if strings.HasPrefix(filepath.Base(sys), "nvme") {
		return "nvme"
	}
	b, err := os.ReadFile(filepath.Join(sys, "queue", "rotational"))
	if err != nil {
		return ""
	}
	if strings.TrimSpace(string(b)) == "1" {
		return "hdd"
	}
	return "ssd"
}

// processMemory adds up the resident memory of the services that share
// the machine with the database server.
func processMemory(h host.Host) map[string]int64 {
	out := map[string]int64{"mysql": 0, "php-fpm": 0, "redis": 0, "memcached": 0, "postgresql": 0}
	dirs, _ := filepath.Glob(h.Path("/proc/[0-9]*"))
	page := int64(os.Getpagesize())
	for _, dir := range dirs {
		comm, err := os.ReadFile(filepath.Join(dir, "comm"))
		if err != nil {
			continue
		}
		var group string
		switch name := strings.TrimSpace(string(comm)); {
		case name == "mysqld" || name == "mariadbd":
			group = "mysql"
		case strings.HasPrefix(name, "php-fpm"):
			group = "php-fpm"
		case name == "redis-server":
			group = "redis"
		case name == "memcached":
			group = "memcached"
		case name == "postgres":
			group = "postgresql"
		default:
			continue
		}
		statm, err := os.ReadFile(filepath.Join(dir, "statm"))
		if err != nil {
			continue
		}
		if f := strings.Fields(string(statm)); len(f) > 1 {
			pages, _ := strconv.ParseInt(f[1], 10, 64)
			out[group] += pages * page
		}
	}
	for k, v := range out {
		out[k] = v >> 20
	}
	return out
}

// demoMeasurement stands in for a real server on the simulator: a 32 GB
// machine whose MySQL still has the package's settings.
func demoMeasurement(unit string, now time.Time) Measurement {
	hours := float64(now.Unix()%(7*86400)) / 3600
	grow := func(base, perHour float64) string { return strconv.FormatInt(int64(base+perHour*hours), 10) }
	return Measurement{
		Unit: unit, TakenAt: now.UTC(), Version: "8.4.7", CPUs: 8, MemoryMB: 32096, AvailableMB: 27400, SwapMB: 4096, SwapUsedMB: 0, Disk: "nvme",
		Variables: map[string]string{
			"version": "8.4.7", "innodb_buffer_pool_size": "134217728", "max_connections": "151", "max_allowed_packet": "67108864",
			"tmp_table_size": "16777216", "max_heap_table_size": "16777216", "table_open_cache": "4000", "innodb_io_capacity": "200",
			"innodb_io_capacity_max": "2000", "slow_query_log": "OFF", "long_query_time": "10.000000", "innodb_redo_log_capacity": "104857600",
			"innodb_flush_log_at_trx_commit": "1", "performance_schema": "ON", "sort_buffer_size": "262144", "join_buffer_size": "262144",
			"read_buffer_size": "131072", "read_rnd_buffer_size": "262144", "thread_stack": "1048576", "binlog_cache_size": "32768",
		},
		Status: map[string]string{
			"Uptime": grow(1000000, 3600), "Max_used_connections": "38", "Threads_connected": "9", "Connection_errors_max_connections": "0",
			"Created_tmp_disk_tables": grow(400000, 74), "Created_tmp_tables": grow(1000000, 194), "Innodb_buffer_pool_reads": grow(9000000, 72000),
			"Innodb_buffer_pool_read_requests": grow(100000000, 837000), "Opened_tables": grow(9000, 12), "Questions": grow(90000000, 160000),
			"Slow_queries": "0", "Innodb_os_log_written": grow(20000000000, 188743680),
		},
		DataBytes: 3328599654, Tables: 412,
		Digests: []Digest{
			{Schema: "roifex_laravel", Digest: "5c2f0e7d1b6a4c8e9f0a1b2c3d4e5f60", Text: "SELECT * FROM `orders` WHERE `status` = ? ORDER BY `created_at` DESC LIMIT ?",
				Count: int64(1200000 + 1717*hours), TimeMS: 228000000 + 326000*hours, RowsExamined: int64(216000000000 + 309000000*hours), RowsSent: int64(24000000 + 34340*hours)},
			{Schema: "serfexsite_wp", Digest: "8a1b2c3d4e5f60718293a4b5c6d7e8f9", Text: "SELECT `option_name` , `option_value` FROM `wp_options` WHERE `autoload` IN (...)",
				Count: int64(290000 + 408*hours), TimeMS: 121800000 + 171500*hours, RowsExamined: int64(290000000 + 408000*hours), RowsSent: int64(145000000 + 204000*hours)},
			{Schema: "roifex_laravel", Digest: "1f2e3d4c5b6a79881726354453627180", Text: "SELECT * FROM `products` WHERE `id` = ? LIMIT ?",
				Count: int64(9000000 + 12000*hours), TimeMS: 5400000 + 7200*hours, RowsExamined: int64(9000000 + 12000*hours), RowsSent: int64(9000000 + 12000*hours)},
		},
		ProcessesMB: map[string]int64{"mysql": 612, "php-fpm": 1180, "redis": 310, "memcached": 0, "postgresql": 240},
	}
}
