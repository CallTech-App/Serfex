package service

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"testing"

	"github.com/calltech-app/serfex/agent/internal/tuning"
)

func TestDatabaseOptimizationSettings(t *testing.T) {
	m, sim := newTest(t, "/etc/mysql/mysql.conf.d/zz-serfex.cnf")
	ctx := context.Background()
	db := tuning.Database{MaxConnections: 151, BufferPoolMB: 4096, MaxAllowedPacketMB: 64, TmpTableMB: 64, TableOpenCache: 8000, IOCapacity: 2000, SlowLog: true, LongQueryTime: 1}
	got, err := m.ApplySettings(ctx, SettingsSpec{Unit: "mysql", Database: &db})
	if err != nil {
		t.Fatal(err)
	}
	if *got.Database != db {
		t.Errorf("settings read back: %+v", got.Database)
	}
	b, _ := os.ReadFile(sim.Path(mysqlTuning))
	for _, line := range []string{"innodb_buffer_pool_size = 4096M\n", "tmp_table_size = 64M\nmax_heap_table_size = 64M\n", "table_open_cache = 8000\n",
		"innodb_io_capacity = 2000\ninnodb_io_capacity_max = 4000\n", "slow_query_log = 1\nslow_query_log_file = /var/log/mysql/mysql-slow.log\nlong_query_time = 1\n"} {
		if !strings.Contains(string(b), line) {
			t.Errorf("missing %q in\n%s", line, b)
		}
	}
	// Back to the server's defaults: the lines go away.
	db = tuning.Database{MaxConnections: 151, BufferPoolMB: 128, MaxAllowedPacketMB: 64, LongQueryTime: 5}
	if got, err = m.ApplySettings(ctx, SettingsSpec{Unit: "mysql", Database: &db}); err != nil || got.Database.LongQueryTime != 0 {
		t.Fatalf("%+v %v", got.Database, err)
	}
	b, _ = os.ReadFile(sim.Path(mysqlTuning))
	if strings.Contains(string(b), "tmp_table_size") || strings.Contains(string(b), "slow_query_log") || strings.Contains(string(b), "io_capacity") {
		t.Errorf("defaults left lines:\n%s", b)
	}
	var v *ValidationError
	for _, bad := range []tuning.Database{
		{MaxConnections: 151, BufferPoolMB: 128, MaxAllowedPacketMB: 64, TmpTableMB: 8},
		{MaxConnections: 151, BufferPoolMB: 128, MaxAllowedPacketMB: 64, IOCapacity: 50000},
		{MaxConnections: 151, BufferPoolMB: 128, MaxAllowedPacketMB: 64, TableOpenCache: 10},
		{MaxConnections: 151, BufferPoolMB: 128, MaxAllowedPacketMB: 64, SlowLog: true},
	} {
		if _, err := m.ApplySettings(ctx, SettingsSpec{Unit: "mysql", Database: &bad}); !errors.As(err, &v) {
			t.Errorf("%+v: %v", bad, err)
		}
	}
}

func TestDynamicStatements(t *testing.T) {
	old := tuning.Database{MaxConnections: 151, BufferPoolMB: 128, MaxAllowedPacketMB: 64}
	db := old
	db.BufferPoolMB, db.IOCapacity, db.SlowLog, db.LongQueryTime, db.TmpTableMB = 4096, 2000, true, 1, 64
	got := strings.Join(DynamicStatements(old, db), "; ")
	want := "SET GLOBAL tmp_table_size = 67108864; SET GLOBAL max_heap_table_size = 67108864; " +
		"SET GLOBAL innodb_io_capacity_max = 4000; SET GLOBAL innodb_io_capacity = 2000; " +
		"SET GLOBAL slow_query_log_file = '/var/log/mysql/mysql-slow.log'; SET GLOBAL long_query_time = 1; SET GLOBAL slow_query_log = ON; " +
		"SET GLOBAL innodb_buffer_pool_size = 4294967296"
	if got != want {
		t.Errorf("up:\n%s\nwant\n%s", got, want)
	}
	got = strings.Join(DynamicStatements(db, old), "; ")
	want = "SET GLOBAL tmp_table_size = DEFAULT; SET GLOBAL max_heap_table_size = DEFAULT; " +
		"SET GLOBAL innodb_io_capacity = DEFAULT; SET GLOBAL innodb_io_capacity_max = DEFAULT; " +
		"SET GLOBAL slow_query_log = OFF; SET GLOBAL long_query_time = DEFAULT; SET GLOBAL innodb_buffer_pool_size = 134217728"
	if got != want {
		t.Errorf("down:\n%s\nwant\n%s", got, want)
	}
	if s := DynamicStatements(old, old); len(s) != 0 {
		t.Errorf("no change: %v", s)
	}
}

func TestMeasure(t *testing.T) {
	m, _ := newTest(t, "/etc/mysql/mysql.conf.d/zz-serfex.cnf")
	ctx := context.Background()
	got, err := m.Measure(ctx, "mysql")
	if err != nil {
		t.Fatal(err)
	}
	if got.MemoryMB == 0 || got.Variables["innodb_buffer_pool_size"] == "" || len(got.Digests) == 0 || got.Status["Uptime"] == "" {
		t.Errorf("demo measurement: %+v", got)
	}
	var v *ValidationError
	if _, err := m.Measure(ctx, "nginx"); !errors.As(err, &v) {
		t.Errorf("nginx: %v", err)
	}
	if _, err := m.Do(ctx, ActionSpec{Unit: "mysql", Action: "stop"}); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Measure(ctx, "mysql"); !errors.As(err, &v) {
		t.Errorf("stopped: %v", err)
	}
}

func TestReadMemoryAndProcesses(t *testing.T) {
	_, sim := newTest(t)
	write := func(p, s string) {
		_ = os.MkdirAll(filepath.Dir(sim.Path(p)), 0o755)
		if err := os.WriteFile(sim.Path(p), []byte(s), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	write("/proc/meminfo", "MemTotal:       32867328 kB\nMemFree:  1000 kB\nMemAvailable:   28057600 kB\nSwapTotal:       4194300 kB\nSwapFree:        3145724 kB\n")
	total, avail, swap, used := readMemory(sim)
	if total != 32097 || avail != 27400 || swap != 4095 || used != 1024 {
		t.Errorf("meminfo: %d %d %d %d", total, avail, swap, used)
	}
	page := os.Getpagesize()
	pages := func(mb int) string { return "100 " + itoa(mb<<20/page) + " 0 0 0 0 0\n" }
	write("/proc/10/comm", "mysqld\n")
	write("/proc/10/statm", pages(600))
	write("/proc/11/comm", "php-fpm8.4\n")
	write("/proc/11/statm", pages(50))
	write("/proc/12/comm", "php-fpm: pool x\n")
	write("/proc/12/statm", pages(70))
	write("/proc/13/comm", "bash\n")
	write("/proc/13/statm", pages(999))
	got := processMemory(sim)
	if got["mysql"] != 600 || got["php-fpm"] != 120 || got["redis"] != 0 {
		t.Errorf("processes: %v", got)
	}
}

func itoa(n int) string { return strconv.Itoa(n) }
