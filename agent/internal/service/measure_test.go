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
	res, err := m.Measure(ctx, "mysql")
	if err != nil {
		t.Fatal(err)
	}
	got := res.(Measurement)
	if got.MemoryMB == 0 || got.Variables["innodb_buffer_pool_size"] == "" || len(got.Digests) == 0 || got.Status["Uptime"] == "" {
		t.Errorf("demo measurement: %+v", got)
	}
	var v *ValidationError
	if _, err := m.Measure(ctx, "postfix"); !errors.As(err, &v) {
		t.Errorf("postfix: %v", err)
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

func TestMeasurePHP(t *testing.T) {
	m, sim := newTest(t)
	ctx := context.Background()
	res, err := m.Measure(ctx, "php8.4-fpm")
	if err != nil {
		t.Fatal(err)
	}
	got := res.(PHPMeasurement)
	if got.Kind != "php" || got.Version != "8.4" || got.OPcache == nil || len(got.Pools) == 0 {
		t.Errorf("demo measurement: %+v", got)
	}
	if PHPVersionOf("php8.4-fpm") != "8.4" || PHPVersionOf("mysql") != "" || PHPVersionOf("php8.4") != "" {
		t.Error("PHPVersionOf")
	}

	// The probe pool: written once, checked, PHP-FPM reloaded once.
	if err := m.ensureProbe(ctx, "8.4"); err != nil {
		t.Fatal(err)
	}
	conf, _ := os.ReadFile(sim.Path(probePool("8.4")))
	for _, want := range []string{"[serfex_probe]\n", "user = nobody\n", "listen = /run/php/serfex_probe-8.4.sock\n", "listen.mode = 0600\n", "php_admin_value[open_basedir] = /opt/serfex/php-probe/\n"} {
		if !strings.Contains(string(conf), want) {
			t.Errorf("probe pool lacks %q:\n%s", want, conf)
		}
	}
	if b, _ := os.ReadFile(sim.Path(probeScript())); !strings.HasPrefix(string(b), "<?php // Managed by Serfex") {
		t.Errorf("probe script:\n%s", b)
	}
	reloads := func() int {
		n := 0
		for _, c := range sim.Commands() {
			if c.Name == "systemctl" && len(c.Args) == 2 && c.Args[0] == "reload" && c.Args[1] == "php8.4-fpm" {
				n++
			}
		}
		return n
	}
	if n := reloads(); n != 1 {
		t.Errorf("reloads after writing: %d", n)
	}
	if err := m.ensureProbe(ctx, "8.4"); err != nil || reloads() != 1 {
		t.Errorf("second ensure: %v, reloads %d", err, reloads())
	}
	// Sites' pools are read; webmail's and the probe's are not.
	write := func(p, s string) {
		_ = os.MkdirAll(filepath.Dir(sim.Path(p)), 0o755)
		if err := os.WriteFile(sim.Path(p), []byte(s), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	write("/etc/php/8.4/fpm/pool.d/a.com.conf", "; Managed by Serfex.\n[a.com]\npm = dynamic\npm.max_children = 12\npm.status_listen = /run/php/serfex-a.com.status.sock\n")
	write("/etc/php/8.4/fpm/pool.d/serfex-webmail.conf", "; Managed by Serfex.\n[serfex-webmail]\npm = ondemand\npm.max_children = 20\n")
	write("/etc/php/8.4/fpm/pool.d/b.com.conf", "[b.com]\npm.max_children = 5\npm.status_listen = /run/x.sock\n")
	pools := readPools(sim, "8.4")
	if len(pools) != 1 || pools[0].Domain != "a.com" || pools[0].PM != "dynamic" || pools[0].MaxChildren != 12 || pools[0].socket != "/run/php/serfex-a.com.status.sock" {
		t.Errorf("pools: %+v", pools)
	}
	page := os.Getpagesize()
	write("/proc/20/cmdline", "php-fpm: pool a.com\x00\x00")
	write("/proc/20/statm", "100 "+itoa(70<<20/page)+" "+itoa(20<<20/page)+" 0 0 0 0\n")
	write("/proc/21/cmdline", "php-fpm: pool a.com        ")
	write("/proc/21/statm", "100 "+itoa(40<<20/page)+" "+itoa(10<<20/page)+" 0 0 0 0\n")
	if mem := poolMemory(sim)["a.com"]; mem.workers != 2 || int(mem.mb+0.5) != 80 {
		t.Errorf("pool memory: %+v", mem)
	}
}

func TestParseOPcache(t *testing.T) {
	body := `{"loaded":true,"status":{"opcache_enabled":true,"memory_usage":{"used_memory":125829120,"free_memory":2097152,"wasted_memory":6291456},
		"interned_strings_usage":{"buffer_size":16777216,"used_memory":16357785,"free_memory":419431},
		"opcache_statistics":{"num_cached_scripts":9840,"num_cached_keys":12210,"max_cached_keys":32531,"hits":48000000,"misses":90000,"start_time":1700000000,"oom_restarts":3,"hash_restarts":0,"manual_restarts":1}},
		"directives":{"opcache.enable":true,"opcache.memory_consumption":134217728,"opcache.interned_strings_buffer":16,"opcache.max_accelerated_files":20000,"opcache.validate_timestamps":true,"opcache.revalidate_freq":2}}`
	o, err := parseOPcache([]byte(body))
	if err != nil {
		t.Fatal(err)
	}
	want := OPcache{Loaded: true, Enabled: true, MemoryMB: 128, UsedMB: 120, FreeMB: 2, WastedMB: 6, StringsMB: 16, StringsUsedMB: 15.6, StringsFreeMB: 0.4,
		MaxFiles: 20000, CachedScripts: 9840, CachedKeys: 12210, MaxCachedKeys: 32531, Hits: 48000000, Misses: 90000, OOMRestarts: 3, ManualRestarts: 1,
		StartTime: 1700000000, ValidateTimestamps: true, RevalidateFreq: 2}
	if *o != want {
		t.Errorf("got  %+v\nwant %+v", *o, want)
	}
	// OPcache switched off: no status, the settings still read.
	o, err = parseOPcache([]byte(`{"loaded":true,"status":null,"directives":{"opcache.enable":false,"opcache.memory_consumption":134217728}}`))
	if err != nil || o.Enabled || o.MemoryMB != 128 {
		t.Errorf("off: %+v %v", o, err)
	}
	if _, err := parseOPcache([]byte("<html>")); err == nil {
		t.Error("garbage accepted")
	}
}
