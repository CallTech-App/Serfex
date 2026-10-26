package monitor

import (
	"context"
	"encoding/binary"
	"fmt"
	"io"
	"net"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/site"
	"github.com/winchester37/servik/agent/internal/stats"
)

// fixture is a simulated machine with two sites of one account, one site
// of another, and a fake /proc the test fills in.
type fixture struct {
	t      *testing.T
	sim    *host.Simulator
	sites  *site.Manager
	m      *Monitor
	now    time.Time
	uptime float64
	pools  map[string]poolStatus
}

func newFixture(t *testing.T) *fixture {
	t.Helper()
	sim, err := host.NewSimulator(t.TempDir(), "8.4")
	if err != nil {
		t.Fatal(err)
	}
	f := &fixture{t: t, sim: sim, sites: site.NewManager(sim, "www-data"), now: time.Date(2026, 10, 19, 12, 0, 0, 0, time.UTC), uptime: 1000, pools: map[string]poolStatus{}}
	ctx := context.Background()
	for _, s := range []site.Spec{
		{Domain: "magaza.com.tr", User: "magaza", PHPVersion: "8.4", Aliases: []string{"www.magaza.com.tr"}},
		{Domain: "kahve.com", User: "kahve", PHPVersion: "8.4"},
		{Domain: "blog.kahve.com", User: "kahve", PHPVersion: "8.4"},
	} {
		if _, err := f.sites.Apply(ctx, s); err != nil {
			t.Fatal(err)
		}
	}
	ss := newSystemSampler(sim)
	ss.fpm = func(_ context.Context, socket string) (poolStatus, error) {
		for d, st := range f.pools {
			if socket == site.StatusSocket(d) {
				return st, nil
			}
		}
		return poolStatus{}, fmt.Errorf("no status for %s", socket)
	}
	f.m = newMonitor(sim, ss, f.sites, nil)
	f.m.now = func() time.Time { return f.now }
	f.m.restartWait = 50 * time.Millisecond
	f.sites.MaxChildren = f.m.PHPWorkers
	f.writeStat(0, 0)
	return f
}

func (f *fixture) write(abs, content string) {
	f.t.Helper()
	p := f.sim.Path(abs)
	if err := os.MkdirAll(filepath.Dir(p), 0o755); err != nil {
		f.t.Fatal(err)
	}
	if err := os.WriteFile(p, []byte(content), 0o644); err != nil {
		f.t.Fatal(err)
	}
}

func (f *fixture) append(abs string, lines ...string) {
	f.t.Helper()
	fh, err := os.OpenFile(f.sim.Path(abs), os.O_APPEND|os.O_WRONLY|os.O_CREATE, 0o644)
	if err != nil {
		f.t.Fatal(err)
	}
	defer fh.Close()
	for _, l := range lines {
		fmt.Fprintln(fh, l)
	}
}

func (f *fixture) writeStat(busy, idle uint64) {
	f.write("/proc/uptime", fmt.Sprintf("%.2f 0.00\n", f.uptime))
	f.write("/proc/stat", fmt.Sprintf("cpu  %d 0 0 %d 0 0 0 0 0 0\ncpu0 0 0 0 0 0 0 0 0 0 0\ncpu1 0 0 0 0 0 0 0 0 0 0\n", busy, idle))
	f.write("/proc/meminfo", "MemTotal:       8000000 kB\nMemAvailable:   6000000 kB\n")
	f.write("/proc/loadavg", "0.50 0.40 0.30 1/100 999\n")
}

// proc writes /proc/<pid>; cpu is in clock ticks, mem in kB.
func (f *fixture) proc(pid, uid int, cmd, unit string, cpu, start, memKB, io uint64) {
	dir := fmt.Sprintf("/proc/%d", pid)
	f.write(dir+"/cmdline", strings.ReplaceAll(cmd, " ", "\x00")+"\x00")
	// Fields 3..22: state ppid pgrp session tty tpgid flags minflt cminflt majflt cmajflt utime stime cutime cstime priority nice threads itreal starttime
	f.write(dir+"/stat", fmt.Sprintf("%d (%s) S 1 1 1 0 -1 0 0 0 0 0 %d 0 0 0 20 0 1 0 %d 0 0\n", pid, "x y", cpu, start))
	f.write(dir+"/status", fmt.Sprintf("Name:\tx\nUid:\t%d\t%d\t%d\t%d\nRssAnon:\t%d kB\n", uid, uid, uid, uid, memKB))
	f.write(dir+"/io", fmt.Sprintf("rchar: 1\nwchar: 1\nread_bytes: %d\nwrite_bytes: 0\n", io))
	cg := "0::/system.slice/" + unit
	if unit == "" {
		cg = "0::/user.slice/user-1000.slice/session-1.scope"
	}
	f.write(dir+"/cgroup", cg+"\n")
}

func (f *fixture) tick(d time.Duration) {
	f.now = f.now.Add(d)
	f.uptime += d.Seconds()
	f.write("/proc/uptime", fmt.Sprintf("%.2f 0.00\n", f.uptime))
	f.m.Sample(context.Background())
}

func (f *fixture) uid(user string) int {
	u, err := f.sim.LookupUser(user)
	if err != nil {
		f.t.Fatal(err)
	}
	return u.UID
}

func siteOf(snap Snapshot, domain string) SiteUsage {
	for _, s := range snap.Sites {
		if s.Domain == domain {
			return s
		}
	}
	return SiteUsage{}
}

func TestProcessesAreAttributedToSites(t *testing.T) {
	f := newFixture(t)
	magaza, kahve := f.uid("magaza"), f.uid("kahve")
	start := uint64(500 * clockTicks)
	f.proc(10, magaza, "php-fpm: pool magaza.com.tr", "php8.4-fpm.service", 0, start, 50000, 0)
	f.proc(11, magaza, "php artisan queue:work", "servik-queue-magaza.com.tr_default@1.service", 0, start, 30000, 0)
	f.proc(12, magaza, "/usr/sbin/cron", "", 0, start, 1000, 0)
	f.proc(20, kahve, "php-fpm: pool kahve.com", "php8.4-fpm.service", 0, start, 20000, 0)
	f.proc(21, kahve, "bash", "", 0, start, 2000, 0)
	f.proc(30, 33, "nginx: worker process", "nginx.service", 0, start, 9000, 0)
	f.proc(31, 0, "/usr/local/bin/servik-agent", "servik-agent.service", 0, start, 9000, 0)
	f.pools["magaza.com.tr"] = poolStatus{Active: 3, Idle: 1, Total: 4}
	f.tick(0)

	// Two seconds later: magaza's worker used one core, its queue worker
	// half of one; kahve's account shell a quarter.
	f.proc(10, magaza, "php-fpm: pool magaza.com.tr", "php8.4-fpm.service", 200, start, 50000, 4096)
	f.proc(11, magaza, "php artisan queue:work", "servik-queue-magaza.com.tr_default@1.service", 100, start, 30000, 0)
	f.proc(21, kahve, "bash", "", 50, start, 2000, 0)
	f.proc(30, 33, "nginx: worker process", "nginx.service", 20, start, 9000, 0)
	// A worker started since the last sample counts in full.
	f.proc(40, kahve, "php-fpm: pool blog.kahve.com", "php8.4-fpm.service", 40, uint64((f.uptime+1)*clockTicks), 10000, 0)
	f.writeStat(1200, 2800)
	f.tick(2 * time.Second)

	snap := f.m.Snapshot(SnapshotSpec{Window: 2})
	m := siteOf(snap, "magaza.com.tr")
	if m.CPU != 150 || m.Memory != (50000+30000+1000)*1024 || m.Processes != 3 || m.IO != 2048 {
		t.Errorf("magaza = %+v", m.Usage)
	}
	if !m.PHP.Known || m.PHP.Busy != 3 || m.PHP.Workers != 4 || m.PHP.Max != site.DefaultMaxChildren {
		t.Errorf("magaza php = %+v", m.PHP)
	}
	if k := siteOf(snap, "kahve.com"); k.CPU != 0 || k.Processes != 1 {
		t.Errorf("kahve = %+v (the account's shell belongs to the account, not a site)", k.Usage)
	}
	if b := siteOf(snap, "blog.kahve.com"); b.CPU != 20 {
		t.Errorf("blog = %+v", b.Usage)
	}
	if len(snap.Accounts) != 1 || snap.Accounts[0].User != "kahve" || snap.Accounts[0].CPU != 25 || len(snap.Accounts[0].Sites) != 2 {
		t.Errorf("accounts = %+v", snap.Accounts)
	}
	groups := map[string]string{}
	for _, s := range snap.Services {
		groups[s.Name] = s.Group
	}
	if groups["Nginx"] != "shared" || groups["Servik agent"] != "servik" {
		t.Errorf("services = %+v", snap.Services)
	}
	if snap.Server.CPU != 30 || snap.Server.CPUs != 2 || snap.Server.MemoryTotal != 8000000*1024 {
		t.Errorf("server = %+v", snap.Server)
	}
}

func accessLine(ip, method, path string, status int) string {
	return fmt.Sprintf(`%s - - [19/Oct/2026:12:00:00 +0000] "%s %s HTTP/1.1" %d 512 "-" "curl/8"`, ip, method, path, status)
}

func TestTrafficAndAttacks(t *testing.T) {
	f := newFixture(t)
	log := site.AccessLog("magaza.com.tr")
	f.append(log, accessLine("192.0.2.1", "GET", "/old", 200)) // before monitoring: ignored
	f.tick(0)

	var lines []string
	for i := 0; i < FloodPerMinute; i++ {
		lines = append(lines, accessLine("198.51.100.7", "GET", "/sepet?x=1", 200))
	}
	for i := 0; i < LoginPerMinute; i++ {
		lines = append(lines, accessLine("203.0.113.66", "POST", "/wp-login.php", 200))
	}
	for i := 0; i < 10; i++ {
		lines = append(lines, accessLine("192.0.2.50", "GET", "/", 200), accessLine("127.0.0.1", "GET", "/", 500))
	}
	lines = append(lines, accessLine("192.0.2.51", "GET", "/nope", 404))
	f.append(log, lines...)
	f.append(mailLog,
		"Oct 19 12:00:00 mail postfix/submission/smtpd[1]: warning: unknown[198.51.100.99]: SASL LOGIN authentication failed: authentication failure",
		"Oct 19 12:00:00 mail dovecot: imap-login: Disconnected: Connection closed (auth failed, 9 attempts in 30 secs): user=<a@b.com>, method=PLAIN, rip=198.51.100.99, lip=192.0.2.10",
		"Oct 19 12:00:00 mail postfix/pickup[2]: AB12: uid="+fmt.Sprint(f.uid("magaza"))+" from=<magaza>",
		"Oct 19 12:00:00 mail postfix/qmgr[3]: AB12: from=<bilgi@magaza.com.tr>, size=100, nrcpt=1 (queue active)",
		"Oct 19 12:00:00 mail postfix/qmgr[3]: CD34: from=<satis@www.magaza.com.tr>, size=100, nrcpt=3 (queue active)",
		"Oct 19 12:00:00 mail postfix/qmgr[3]: CD34: from=<satis@www.magaza.com.tr>, size=100, nrcpt=3 (queue active)",
	)
	f.append(authLog, "Oct 19 12:00:00 host sshd[9]: Failed password for root from 192.0.2.200 port 22 ssh2")
	f.tick(2 * time.Second)

	snap := f.m.Snapshot(SnapshotSpec{Window: 2})
	m := siteOf(snap, "magaza.com.tr")
	total := FloodPerMinute + LoginPerMinute + 21
	if m.Requests != float64(total)/2 || m.ServerErrors != 5 || m.ClientErrors != 0.5 {
		t.Errorf("traffic = %v req/s, %v 5xx/s, %v 4xx/s", m.Requests, m.ServerErrors, m.ClientErrors)
	}
	// Bytes sent add up from the start, the line before it not counted.
	if m.Traffic != uint64(total)*512 || snap.Started.IsZero() {
		t.Errorf("traffic = %d bytes since %v", m.Traffic, snap.Started)
	}
	if len(m.TopIPs) == 0 || m.TopIPs[0] != (IPCount{"198.51.100.7", FloodPerMinute}) {
		t.Errorf("top = %+v", m.TopIPs)
	}
	// One message from PHP (pickup) and one from a mailbox, each once.
	if m.MailPerHour != 2*3600/2 {
		t.Errorf("mail = %v/h", m.MailPerHour)
	}

	kinds := map[string]Suspect{}
	for _, s := range snap.Suspects {
		kinds[s.Kind+" "+s.IP] = s
	}
	if s, ok := kinds["flood 198.51.100.7"]; !ok || s.Target != "magaza.com.tr" || s.Count != FloodPerMinute {
		t.Errorf("flood suspect = %+v (all: %+v)", s, snap.Suspects)
	}
	if s, ok := kinds["login 203.0.113.66"]; !ok || s.Detail != "/wp-login.php" {
		t.Errorf("login suspect = %+v", s)
	}
	if s, ok := kinds["mail 198.51.100.99"]; !ok || s.Count != 10 || s.Target != "" {
		t.Errorf("mail suspect = %+v", s)
	}
	if _, ok := kinds["ssh 192.0.2.200"]; ok {
		t.Error("one failed SSH login made a suspect")
	}
	for k := range kinds {
		if strings.Contains(k, "127.0.0.1") {
			t.Error("loopback became a suspect")
		}
	}

	// A minute later the flood stopped: the suspect stays, with count 0.
	for i := 0; i < 31; i++ {
		f.tick(2 * time.Second)
	}
	snap = f.m.Snapshot(SnapshotSpec{Window: 10})
	for _, s := range snap.Suspects {
		if s.IP == "198.51.100.7" && (s.Count != 0 || s.Peak != FloodPerMinute) {
			t.Errorf("quiet suspect = %+v", s)
		}
	}
	// Ten quiet minutes later it is forgotten.
	f.tick(11 * time.Minute)
	if snap = f.m.Snapshot(SnapshotSpec{}); len(snap.Suspects) != 0 {
		t.Errorf("suspects after 10 quiet minutes = %+v", snap.Suspects)
	}
}

func TestDatabaseConnectionsCountForTheirAccount(t *testing.T) {
	f := newFixture(t)
	f.write(mysqlSocket, "")
	f.m.Sample(context.Background())
	// The simulator's mysql prints nothing; feed the parser directly.
	conns, err := query(context.Background(), outputHost{f.sim, "magaza\tmagaza_shop\tQuery\t12\nmagaza_ro\t\tSleep\t300\nkahve\tkahve_wp\tSleep\t1\nroot\t\tQuery\t0\nevent_scheduler\t\tDaemon\t9\n"}, host.Command{Name: "mysql"}, "mysql")
	if err != nil || len(conns) != 4 {
		t.Fatalf("conns = %+v, %v", conns, err)
	}
	f.m.mu.Lock()
	f.m.process(f.now.Add(2*time.Second), discoverSites(f.sim), raw{uptime: f.uptime + 2, db: conns, dbServers: []string{"mysql"}})
	f.m.mu.Unlock()
	snap := f.m.Snapshot(SnapshotSpec{Window: 2})
	if m := siteOf(snap, "magaza.com.tr"); m.DB.Connections != 2 || m.DB.Active != 1 || m.DB.Longest != 12 {
		t.Errorf("magaza db = %+v", m.DB)
	}
	if len(snap.Accounts) != 1 || snap.Accounts[0].DB.Connections != 1 {
		t.Errorf("kahve's connection = %+v", snap.Accounts)
	}
}

type outputHost struct {
	*host.Simulator
	out string
}

func (h outputHost) Run(context.Context, host.Command) (string, error) { return h.out, nil }

func TestLimitsCapAndEnd(t *testing.T) {
	f := newFixture(t)
	ctx := context.Background()
	start := uint64(500 * clockTicks)
	f.proc(10, f.uid("magaza"), "php-fpm: pool magaza.com.tr", "php8.4-fpm.service", 0, start, 1000, 0)
	f.proc(11, f.uid("magaza"), "php-fpm: pool magaza.com.tr.eski", "php8.4-fpm.service", 0, start, 1000, 0)
	// The scope does not exist yet.
	f.sim.Fail = func(c host.Command) error {
		if c.String() == `systemctl is-active --quiet servik-limit-magaza.com.tr.scope` {
			return fmt.Errorf("inactive")
		}
		return nil
	}

	if _, err := f.m.ApplyLimit(ctx, LimitSpec{Domain: "magaza.com.tr", Seconds: 30}); err == nil {
		t.Fatal("a limit without caps was accepted")
	}
	if _, err := f.m.ApplyLimit(ctx, LimitSpec{Domain: "yok.com", CPU: 50, Seconds: 600}); err == nil {
		t.Fatal("a limit on a missing site was accepted")
	}
	l, err := f.m.ApplyLimit(ctx, LimitSpec{Domain: "magaza.com.tr", CPU: 50, MemoryMB: 512, PHPWorkers: 4, Seconds: 600})
	if err != nil {
		t.Fatal(err)
	}
	if l.Expires.Sub(l.Created) != 10*time.Minute {
		t.Fatalf("limit = %+v", l)
	}
	pool := "/etc/php/8.4/fpm/pool.d/magaza.com.tr.conf"
	b, _ := os.ReadFile(f.sim.Path(pool))
	if !strings.Contains(string(b), "pm.max_children = 4\n") || !f.sim.Ran("systemctl reload php8.4-fpm") {
		t.Fatalf("pool not limited:\n%s", b)
	}
	var start1 string
	for _, c := range f.sim.Commands() {
		if c.Name == "busctl" {
			start1 = c.String()
		}
	}
	for _, want := range []string{"StartTransientUnit", "servik-limit-magaza.com.tr.scope", "PIDs au 1 10 ", "Delegate b true", "CPUQuotaPerSecUSec t 500000", "MemoryMax t 536870912"} {
		if !strings.Contains(start1, want) {
			t.Errorf("busctl call lacks %q: %s", want, start1)
		}
	}
	if !f.sim.Ran("systemctl set-property --runtime servik-limit-magaza.com.tr.scope CPUQuota=50% MemoryMax=512M") {
		t.Error("scope caps not set")
	}

	// Re-applying the site keeps the limit.
	if _, err := f.sites.Apply(ctx, site.Spec{Domain: "magaza.com.tr", User: "magaza", PHPVersion: "8.4", Aliases: []string{"www.magaza.com.tr"}}); err != nil {
		t.Fatal(err)
	}
	if b, _ := os.ReadFile(f.sim.Path(pool)); !strings.Contains(string(b), "pm.max_children = 4\n") {
		t.Fatal("site.apply dropped the limit")
	}
	f.m.Sample(ctx)
	if snap := f.m.Snapshot(SnapshotSpec{}); siteOf(snap, "magaza.com.tr").Limit == nil {
		t.Error("snapshot does not show the limit")
	}

	// A restarted agent picks it up from its file.
	m2 := newMonitor(f.sim, f.m.sampler, f.sites, nil)
	m2.now = f.m.now
	if err := m2.loadLimits(ctx); err != nil || len(m2.Limits()) != 1 {
		t.Fatalf("reloaded limits = %+v, %v", m2.Limits(), err)
	}

	// Ten minutes later it ends by itself.
	f.sim.Fail = nil
	f.tick(11 * time.Minute)
	if b, _ := os.ReadFile(f.sim.Path(pool)); !strings.Contains(string(b), fmt.Sprintf("pm.max_children = %d\n", site.DefaultMaxChildren)) {
		t.Fatalf("pool not restored:\n%s", b)
	}
	if !f.sim.Ran("systemctl set-property --runtime servik-limit-magaza.com.tr.scope CPUQuota= MemoryMax=infinity") {
		t.Error("scope caps not lifted")
	}
	if len(f.m.Limits()) != 0 {
		t.Errorf("limits = %+v", f.m.Limits())
	}
	if _, err := os.Stat(f.sim.Path(limitFile("magaza.com.tr"))); !os.IsNotExist(err) {
		t.Error("limit file left behind")
	}
}

func TestRemoveLimit(t *testing.T) {
	f := newFixture(t)
	ctx := context.Background()
	if _, err := f.m.ApplyLimit(ctx, LimitSpec{Domain: "kahve.com", PHPWorkers: 2, Seconds: 3600}); err != nil {
		t.Fatal(err)
	}
	if removed, err := f.m.RemoveLimit(ctx, "kahve.com"); err != nil || !removed {
		t.Fatalf("remove = %v, %v", removed, err)
	}
	if removed, _ := f.m.RemoveLimit(ctx, "kahve.com"); removed {
		t.Error("removed twice")
	}
	if f.sim.Ran("busctl") {
		t.Error("a workers-only limit created a scope")
	}
}

func TestRestartPoolStopsOnlyThatSitesWorkers(t *testing.T) {
	f := newFixture(t)
	f.proc(10, 1000, "php-fpm: pool magaza.com.tr", "php8.4-fpm.service", 0, 1, 1, 0)
	f.proc(11, 1000, "php-fpm: pool magaza.com.tr", "php8.4-fpm.service", 0, 1, 1, 0)
	f.proc(12, 1001, "php-fpm: pool kahve.com", "php8.4-fpm.service", 0, 1, 1, 0)
	f.proc(13, 0, "php-fpm: master process (/etc/php/8.4/fpm/php-fpm.conf)", "php8.4-fpm.service", 0, 1, 1, 0)
	n, err := f.m.RestartPool(context.Background(), "magaza.com.tr")
	if err != nil || n != 2 {
		t.Fatalf("restart = %d, %v", n, err)
	}
	if !f.sim.Ran("kill -TERM 10 11") || !f.sim.Ran("kill -KILL 10 11") {
		t.Errorf("commands = %v", f.sim.Commands())
	}
	if f.sim.Ran("kill -TERM 10 11 12") {
		t.Error("another site's worker was stopped")
	}
}

func TestHistory(t *testing.T) {
	f := newFixture(t)
	for i := 0; i < 200; i++ {
		f.tick(2 * time.Second)
	}
	snap := f.m.Snapshot(SnapshotSpec{Window: 600, History: "kahve.com"})
	if len(snap.History) == 0 || len(snap.History) > 150 {
		t.Fatalf("history has %d points", len(snap.History))
	}
	if snap.Window != 600 {
		t.Errorf("window = %d", snap.Window)
	}
}

func TestParsers(t *testing.T) {
	a, ok := parseAccess(`2001:db8::5 - bob [19/Oct/2026:12:00:00 +0000] "POST /wp-login.php?redirect=1 HTTP/2.0" 302 0 "-" "Mozilla/5.0 (X11; Linux)"`)
	if !ok || a != (access{IP: "2001:db8::5", Method: "POST", Path: "/wp-login.php", Status: 302}) {
		t.Errorf("parseAccess = %+v %v", a, ok)
	}
	if _, ok := parseAccess(`garbage`); ok {
		t.Error("garbage parsed")
	}
	if a, ok := parseAccess(`192.0.2.1 - - [x] "\x16\x03\x01" 400 157 "-" "-"`); !ok || a.Status != 400 || a.Bytes != 157 {
		t.Errorf("TLS on HTTP = %+v %v", a, ok)
	}
	for in, want := range map[string]string{
		"0::/system.slice/nginx.service\n":                                   "nginx.service",
		"0::/system.slice/servik-limit-a\\x2db.com.scope\n":                  "servik-limit-a\\x2db.com.scope",
		"12:pids:/x\n1:name=systemd:/system.slice/cron.service\n0::/\n":      "cron.service",
		"0::/user.slice/user-0.slice/session-3.scope\n":                      "session-3.scope",
		"0::/system.slice/servik-queue-a.com_default@2.service/sub/cgroup\n": "servik-queue-a.com_default@2.service",
	} {
		if got := unitOf(in); got != want {
			t.Errorf("unitOf(%q) = %q, want %q", in, got, want)
		}
	}
	for unit, want := range map[string]string{
		"servik-queue-a.com_default@2.service": "a.com",
		"servik-proc-a.com_horizon.service":    "a.com",
		"servik-schedule-a.com.service":        "a.com",
		"servik-limit-my\\x2dsite.com.scope":   "my-site.com",
		"nginx.service":                        "",
	} {
		if got := unitSite(unit); got != want {
			t.Errorf("unitSite(%q) = %q, want %q", unit, got, want)
		}
	}
	if scopeName("my-site.com") != `servik-limit-my\x2dsite.com.scope` {
		t.Error(scopeName("my-site.com"))
	}
}

func TestTailFollowsRotation(t *testing.T) {
	dir := t.TempDir()
	file := filepath.Join(dir, "access.log")
	os.WriteFile(file, []byte("old\n"), 0o644)
	tl := &tail{file: file}
	if got := tl.read(); got != nil {
		t.Fatalf("first read = %q", got)
	}
	fh, _ := os.OpenFile(file, os.O_APPEND|os.O_WRONLY, 0)
	fh.WriteString("a\nb\npart")
	fh.Close()
	if got := tl.read(); strings.Join(got, ",") != "a,b" {
		t.Fatalf("read = %q", got)
	}
	fh, _ = os.OpenFile(file, os.O_APPEND|os.O_WRONLY, 0)
	fh.WriteString("ial\n")
	fh.Close()
	if got := tl.read(); strings.Join(got, ",") != "partial" {
		t.Fatalf("read = %q", got)
	}
	os.Rename(file, file+".1")
	os.WriteFile(file, []byte("new\n"), 0o644)
	if got := tl.read(); strings.Join(got, ",") != "new" {
		t.Fatalf("after rotation = %q", got)
	}
}

// TestFetchPoolStatus talks FastCGI to a fake PHP-FPM.
func TestFetchPoolStatus(t *testing.T) {
	sock := filepath.Join(t.TempDir(), "s.sock")
	ln, err := net.Listen("unix", sock)
	if err != nil {
		t.Fatal(err)
	}
	defer ln.Close()
	got := make(chan map[string]string, 1)
	go func() {
		c, err := ln.Accept()
		if err != nil {
			return
		}
		defer c.Close()
		params := map[string]string{}
		var buf []byte
		for {
			var h [8]byte
			if _, err := io.ReadFull(c, h[:]); err != nil {
				return
			}
			body := make([]byte, int(binary.BigEndian.Uint16(h[4:6]))+int(h[6]))
			io.ReadFull(c, body)
			if h[1] == fcgiParams {
				buf = append(buf, body...)
			}
			if h[1] == fcgiStdin {
				break
			}
		}
		for len(buf) > 0 {
			kl, vl := int(buf[0]), int(buf[1])
			params[string(buf[2:2+kl])] = string(buf[2+kl : 2+kl+vl])
			buf = buf[2+kl+vl:]
		}
		got <- params
		var out []byte
		resp := "Content-type: application/json\r\n\r\n" + `{"pool":"a.com","listen queue":2,"idle processes":1,"active processes":3,"total processes":4,"max children reached":5,"accepted conn":99}`
		h := [8]byte{1, fcgiStdout, 0, 1}
		binary.BigEndian.PutUint16(h[4:6], uint16(len(resp)))
		out = append(append(out, h[:]...), resp...)
		end := [16]byte{1, fcgiEndRequest, 0, 1, 0, 8}
		c.Write(append(out, end[:]...))
	}()
	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()
	st, err := fetchPoolStatus(ctx, sock)
	if err != nil {
		t.Fatal(err)
	}
	if st != (poolStatus{Active: 3, Idle: 1, Total: 4, Queue: 2, MaxReached: 5, Accepted: 99}) {
		t.Errorf("status = %+v", st)
	}
	if p := <-got; p["SCRIPT_NAME"] != StatusPath || p["QUERY_STRING"] != "json" {
		t.Errorf("params = %v", p)
	}
}

func TestDemoProducesLoadAndAttacks(t *testing.T) {
	f := newFixture(t)
	f.write("/usr/sbin/postfix", "")
	d := newDemoSampler(func() time.Time { return f.now })
	d.h = f.sim
	f.m.sampler = d
	for i := 0; i < 10; i++ {
		f.tick(2 * time.Second)
	}
	snap := f.m.Snapshot(SnapshotSpec{Window: 10})
	if len(snap.Sites) != 3 || snap.Server.CPU == 0 || len(snap.Services) == 0 {
		t.Fatalf("demo snapshot = %+v", snap)
	}
	busy := 0
	for _, s := range snap.Sites {
		if s.CPU > 0 && s.Requests > 0 {
			busy++
		}
	}
	if busy != 3 {
		t.Errorf("sites with load = %d", busy)
	}
	if len(snap.Suspects) == 0 {
		t.Error("no suspects in the demo")
	}
	if _, err := os.Stat(f.sim.Path(simMailQueue)); err != nil {
		t.Error("demo mail queue not seeded")
	}
}

func TestDescribeUnit(t *testing.T) {
	for unit, want := range map[string][2]string{
		"apache2.service":                  {"Apache", "shared"},
		"memcached.service":                {"Memcached", "shared"},
		"clamav-daemon.service":            {"ClamAV", "shared"},
		"fail2ban.service":                 {"Fail2ban", "system"},
		"postgresql@16-main.service":       {"PostgreSQL", "shared"},
		"postgresql.service":               {"PostgreSQL (panel veritabanı)", "servik"},
		"redis-server@servik-mail.service": {"Redis (posta)", "shared"},
	} {
		name, group := describeUnit(unit)
		if name != want[0] || group != want[1] {
			t.Errorf("describeUnit(%q) = %q, %q; want %q, %q", unit, name, group, want[0], want[1])
		}
	}
}

func TestStatisticsAndNetworkAreFed(t *testing.T) {
	f := newFixture(t)
	f.m.Stats = stats.New()
	// The log lines are from the fixture's day, which is older than the
	// week the aggregator keeps by the real clock.
	f.m.Stats.SetClock(func() time.Time { return f.now })
	f.m.Stats.MailDomains = func() map[string]bool { return map[string]bool{"magaza.com.tr": true} }
	f.write("/proc/net/dev", "Inter-|   Receive |  Transmit\n face |bytes packets errs drop fifo frame compressed multicast|bytes packets\n    lo: 999 1 0 0 0 0 0 0 999 1 0 0 0 0 0 0\n  eth0: 1000 10 0 0 0 0 0 0 5000 20 0 0 0 0 0 0\n")
	f.tick(0)
	f.append(site.AccessLog("magaza.com.tr"),
		accessLine("192.0.2.1", "GET", "/", 200),
		`192.0.2.2 - - [19/Oct/2026:12:00:01 +0000] "GET /urun HTTP/1.1" 200 100 "https://www.google.com/" "Mozilla/5.0 (X11; Linux x86_64) Firefox/140.0"`)
	f.append(mailLog, "Oct 19 12:00:00 mail postfix/lmtp[5]: EF56: to=<bilgi@magaza.com.tr>, relay=mail[private/dovecot-lmtp], delay=0.1, dsn=2.0.0, status=sent (250 2.0.0 Saved)")
	f.write("/proc/net/dev", "Inter-|   Receive |  Transmit\n face |bytes packets errs drop fifo frame compressed multicast|bytes packets\n    lo: 99999 1 0 0 0 0 0 0 99999 1 0 0 0 0 0 0\n  eth0: 3000 10 0 0 0 0 0 0 9000 20 0 0 0 0 0 0\n")
	f.tick(2 * time.Second)

	b := f.m.Stats.Collect(stats.CollectSpec{})
	if len(b.Sites) != 1 || b.Sites[0].Domain != "magaza.com.tr" || b.Sites[0].Hours[0].Requests != 2 {
		t.Fatalf("statistics = %+v", b.Sites)
	}
	if ref := b.Sites[0].Hours[0].Items[stats.KindReferrer]; ref["google.com"].Requests != 1 {
		t.Fatalf("referrers = %+v", ref)
	}
	if len(b.Mail) != 1 || b.Mail[0].Hours[0].Received != 1 {
		t.Fatalf("mail = %+v", b.Mail)
	}
	snap := f.m.Snapshot(SnapshotSpec{Window: 2})
	if snap.Server.NetRx != 1000 || snap.Server.NetTx != 2000 {
		t.Fatalf("network = %v rx/s, %v tx/s", snap.Server.NetRx, snap.Server.NetTx)
	}
}
