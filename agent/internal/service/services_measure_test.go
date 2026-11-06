package service

import (
	"bytes"
	"compress/gzip"
	"context"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func write(t *testing.T, path string, data []byte) {
	t.Helper()
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(path, data, 0o644); err != nil {
		t.Fatal(err)
	}
}

func TestScanLogs(t *testing.T) {
	_, sim := newTest(t)
	now := time.Date(2026, 11, 6, 12, 0, 0, 0, time.Local)
	stamp := func(d time.Duration) string { return now.Add(-d).Format("2006/01/02 15:04:05") }
	write(t, sim.Path(nginxErrorLog), []byte(
		stamp(time.Hour)+" [alert] 12#12: 768 worker_connections are not enough\n"+
			stamp(2*time.Hour)+" [crit] 12#12: accept4() failed (24: Too many open files)\n"+
			stamp(3*time.Hour)+" [error] 12#12: something else\n"))
	write(t, sim.Path(nginxErrorLog+".1"), []byte(stamp(3*24*time.Hour)+" [alert] 768 worker_connections are not enough\n"))
	var gz bytes.Buffer
	w := gzip.NewWriter(&gz)
	_, _ = w.Write([]byte(stamp(5*24*time.Hour) + " [alert] worker_connections are not enough\n" +
		stamp(10*24*time.Hour) + " [alert] worker_connections are not enough\n"))
	_ = w.Close()
	write(t, sim.Path(nginxErrorLog+".2.gz"), gz.Bytes())

	got := scanLogs(sim, nginxErrorLog, now, []string{notEnoughConns, tooManyFiles})
	if got[0] != [2]int{3, 1} || got[1] != [2]int{1, 1} {
		t.Errorf("counts %v", got)
	}
}

func TestMemcachedConf(t *testing.T) {
	conf := []byte("# memcached default config\n-d\nlogfile /var/log/memcached.log\n-m 64\n-p 11211\n-u memcache\n-l 127.0.0.1,::1\n# -c 1024\n")
	mc := parseMemcached(conf)
	if mc.MemoryMB != 64 || mc.MaxConnections != 1024 || mc.listen != "127.0.0.1" || mc.port != "11211" {
		t.Errorf("parsed %+v", mc)
	}
	out := SetMemcached(conf, MemcachedSettings{MemoryMB: 256, MaxConnections: 2048})
	if !bytes.Contains(out, []byte("\n-m 256\n")) || !bytes.Contains(out, []byte("\n-c 2048\n")) || bytes.Contains(out, []byte("-m 64")) || !bytes.Contains(out, []byte("-u memcache")) {
		t.Errorf("set:\n%s", out)
	}
	if again := parseMemcached(out); again.MemoryMB != 256 || again.MaxConnections != 2048 {
		t.Errorf("read back %+v", again)
	}
	if out := SetMemcached([]byte("-d"), MemcachedSettings{MemoryMB: 128, MaxConnections: 1024}); string(out) != "-d\n-m 128\n-c 1024\n" {
		t.Errorf("appended %q", out)
	}
}

func TestReadRedisInfo(t *testing.T) {
	now := time.Unix(1_800_000_000, 0)
	var in RedisInstance
	readRedisInfo("# Server\r\nuptime_in_seconds:1000\r\n# Memory\r\nused_memory:115343360\r\nused_memory_peak:125829120\r\n# Stats\r\nevicted_keys:0\r\n"+
		"# Errorstats\r\nerrorstat_ERR:count=2\r\nerrorstat_OOM:count=17\r\n# Keyspace\r\ndb0:keys=40,expires=3,avg_ttl=0\r\ndb1:keys=2,expires=0,avg_ttl=0\r\n", &in, now)
	if !in.Read || in.UsedMB != 110 || in.PeakMB != 120 || in.OOMErrors != 17 || in.Keys != 42 || in.StartTime != now.Unix()-1000 {
		t.Errorf("read %+v", in)
	}
}

func TestMeasureServices(t *testing.T) {
	m, sim := newTest(t, "/usr/bin/memcached", "/etc/postgresql/17/main/postgresql.conf")
	ctx := context.Background()
	if res, err := m.Measure(ctx, "nginx"); err != nil || res.(NginxMeasurement).WorkerConnections == 0 {
		t.Errorf("nginx: %v %+v", err, res)
	}
	if res, err := m.Measure(ctx, "memcached"); err != nil || res.(MemcachedMeasurement).Stats["evictions"] == 0 {
		t.Errorf("memcached: %v %+v", err, res)
	}
	if res, err := m.Measure(ctx, "postgresql@17-main"); err != nil || res.(PostgresMeasurement).Settings["shared_buffers_mb"] != "128" {
		t.Errorf("postgresql: %v %+v", err, res)
	}
	if res, err := m.Measure(ctx, RedisSites); err != nil || len(res.(RedisMeasurement).Instances) == 0 {
		t.Errorf("redis: %v %+v", err, res)
	}
	_ = sim
}

func TestPostgresSettings(t *testing.T) {
	m, sim := newTest(t, "/etc/postgresql/17/main/postgresql.conf")
	ctx := context.Background()
	s, err := m.Settings(ctx, "postgresql@17-main")
	if err != nil || s.PostgreSQL == nil || s.PostgreSQL.SharedBuffersMB != 128 {
		t.Fatalf("settings: %v %+v", err, s.PostgreSQL)
	}
	p := *s.PostgreSQL
	p.SharedBuffersMB, p.RandomPageCost, p.WorkMemMB = 2048, "1.1", 8
	got, err := m.ApplySettings(ctx, SettingsSpec{Unit: "postgresql@17-main", PostgreSQL: &p})
	if err != nil {
		t.Fatal(err)
	}
	if got.PostgreSQL.SharedBuffersMB != 2048 || got.PostgreSQL.RandomPageCost != "1.1" || got.PostgreSQL.WorkMemMB != 8 {
		t.Errorf("applied %+v", got.PostgreSQL)
	}
	file, _ := os.ReadFile(sim.Path(pgTuning("17")))
	if !strings.Contains(string(file), "shared_buffers = '2048MB'\n") || !strings.Contains(string(file), "random_page_cost = 1.1\n") {
		t.Errorf("file:\n%s", file)
	}
	var v *ValidationError
	p.RandomPageCost = "1.1; drop"
	if _, err := m.ApplySettings(ctx, SettingsSpec{Unit: "postgresql@17-main", PostgreSQL: &p}); !errors.As(err, &v) {
		t.Errorf("bad cost: %v", err)
	}
	p.RandomPageCost, p.WorkMemMB = "1.1", 4096
	if _, err := m.ApplySettings(ctx, SettingsSpec{Unit: "postgresql@17-main", PostgreSQL: &p}); !errors.As(err, &v) {
		t.Errorf("work_mem: %v", err)
	}
}

func TestNginxFileLimit(t *testing.T) {
	m, sim := newTest(t)
	write(t, sim.Path(nginxConf), []byte("user www-data;\nworker_processes auto;\npid /run/nginx.pid;\n\nevents {\n\tworker_connections 768;\n}\n\nhttp {\n\tsendfile on;\n}\n"))
	ctx := context.Background()
	s, err := m.Settings(ctx, "nginx")
	if err != nil {
		t.Fatal(err)
	}
	n := *s.Nginx
	n.WorkerConnections, n.WorkerRlimitNofile = 2048, 4096
	if _, err := m.ApplySettings(ctx, SettingsSpec{Unit: "nginx", Nginx: &n}); err != nil {
		t.Fatal(err)
	}
	conf, _ := os.ReadFile(sim.Path(nginxConf))
	if !strings.Contains(string(conf), "worker_rlimit_nofile 4096;") || !strings.Contains(string(conf), "worker_connections 2048;") {
		t.Errorf("conf:\n%s", conf)
	}
	if s, _ := m.Settings(ctx, "nginx"); s.Nginx.WorkerRlimitNofile != 4096 {
		t.Errorf("read back %+v", s.Nginx)
	}
	n.WorkerRlimitNofile = 0
	if _, err := m.ApplySettings(ctx, SettingsSpec{Unit: "nginx", Nginx: &n}); err != nil {
		t.Fatal(err)
	}
	if conf, _ := os.ReadFile(sim.Path(nginxConf)); strings.Contains(string(conf), "worker_rlimit_nofile") {
		t.Errorf("not removed:\n%s", conf)
	}
}
