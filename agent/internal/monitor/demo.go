package monitor

import (
	"context"
	"encoding/json"
	"fmt"
	"hash/fnv"
	"math"
	"math/rand/v2"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"time"
)

// demoSampler makes up a plausible load for dry runs and local
// development: every site gets steady traffic and some CPU, one is under
// a request flood with password guessing, one burns CPU in a queue worker,
// and someone guesses mail and SSH passwords. A site's limit file caps its
// made-up CPU, so limits visibly work.
type demoSampler struct {
	now     func() time.Time
	start   time.Time
	last    time.Time
	rnd     *rand.Rand
	procs   map[string]*proc // by key
	nextPID int
	cpuBusy float64
	cpuAll  float64
	h       interface{ Path(string) string }
	qid     int
	seeded  bool
	netRx   float64
	netTx   float64
}

// installed reports whether a file of a simulated package exists.
func (s *demoSampler) installed(abs string) bool {
	if s.h == nil {
		return false
	}
	_, err := os.Stat(s.h.Path(abs))
	return err == nil
}

func newDemoSampler(now func() time.Time) *demoSampler {
	t := now()
	return &demoSampler{now: now, start: t.Add(-36 * time.Hour), last: t, rnd: rand.New(rand.NewPCG(7, 7)), procs: map[string]*proc{}, nextPID: 1200}
}

type demoProfile struct {
	cpu, mem, rps float64
	attack, busy  bool
}

func demoProfiles(sites []siteInfo) map[string]demoProfile {
	type hs struct {
		d string
		h uint32
	}
	var list []hs
	for _, s := range sites {
		f := fnv.New32a()
		f.Write([]byte(s.Domain))
		list = append(list, hs{s.Domain, f.Sum32()})
	}
	sort.Slice(list, func(i, j int) bool { return list[i].h > list[j].h })
	out := map[string]demoProfile{}
	for i, x := range list {
		r := float64(x.h%1000) / 1000
		p := demoProfile{cpu: 2 + r*30, mem: (90 + r*700) * (1 << 20), rps: 0.3 + r*r*90}
		switch {
		case i == 0 && len(list) > 2:
			p.attack, p.cpu, p.rps, p.mem = true, 88, 160, 1900<<20
		case i == 1 && len(list) > 3:
			p.busy, p.cpu, p.mem = true, 92, 1500<<20
		}
		out[x.d] = p
	}
	return out
}

func (s *demoSampler) proc(key string, uid int, cmd, unit string) *proc {
	p := s.procs[key]
	if p == nil {
		s.nextPID += 1 + s.rnd.IntN(40)
		p = &proc{PID: s.nextPID, UID: uid, Cmd: cmd, Unit: unit, Start: uint64(s.now().Sub(s.start).Seconds() * clockTicks)}
		s.procs[key] = p
	}
	p.Unit = unit
	return p
}

func (s *demoSampler) limitOf(domain string) *Limit {
	if s.h == nil {
		return nil
	}
	b, err := os.ReadFile(s.h.Path(limitFile(domain)))
	if err != nil {
		return nil
	}
	var l Limit
	if json.Unmarshal(b, &l) != nil || !l.Expires.After(s.now()) {
		return nil
	}
	return &l
}

func (s *demoSampler) sample(_ context.Context, sites []siteInfo) raw {
	now := s.now()
	dt := now.Sub(s.last).Seconds()
	if dt <= 0 {
		dt = DefaultInterval.Seconds()
	}
	s.last = now
	sec := now.Sub(s.start).Seconds()
	wave := func(phase float64) float64 { return 1 + 0.25*math.Sin(sec/40+phase) + 0.1*(s.rnd.Float64()*2-1) }

	r := raw{uptime: sec, cpus: 8, memTotal: 32 << 30, pools: map[string]poolStatus{}, access: map[string][]string{}}
	seen := map[string]bool{}
	var total float64
	add := func(key string, uid int, cmd, unit string, cpu float64, mem uint64, io float64) {
		p := s.proc(key, uid, cmd, unit)
		p.CPU += uint64(cpu / 100 * dt * clockTicks)
		p.Mem = mem
		p.IO += uint64(io * dt)
		seen[key] = true
		total += cpu
	}

	s.seedQueue(sites, now)
	profiles := demoProfiles(sites)
	for i, si := range sites {
		pr := profiles[si.Domain]
		w := wave(float64(i))
		cpu, rps := pr.cpu*w, pr.rps*w
		lim := s.limitOf(si.Domain)
		maxWorkers := si.MaxChildren
		unit := fmt.Sprintf("php%s-fpm.service", si.PHP)
		if lim != nil {
			if lim.CPU > 0 && cpu > float64(lim.CPU) {
				cpu = float64(lim.CPU) * (0.9 + 0.08*s.rnd.Float64())
			}
			if lim.CPU > 0 || lim.MemoryMB > 0 {
				unit = scopeName(si.Domain)
			}
		}
		webCPU := cpu
		if pr.busy {
			webCPU = 8 * w
			add("queue:"+si.Domain, 1000+i, "php artisan queue:work --queue=default",
				"servik-queue-"+si.Domain+"_default@1.service", cpu-webCPU, 420<<20, 30<<10)
		}
		busy := int(math.Min(float64(maxWorkers), math.Ceil(webCPU/45+rps/60)))
		if pr.attack {
			busy = maxWorkers
		}
		workers := max(busy, 1)
		if rps < 0.5 && !pr.busy {
			workers = 1
		}
		for n := 0; n < workers; n++ {
			add(fmt.Sprintf("fpm:%s:%d", si.Domain, n), 1000+i, "php-fpm: pool "+si.Domain, unit,
				webCPU/float64(workers), uint64(pr.mem*w)/uint64(workers), 12<<10)
		}
		st := poolStatus{Active: min(busy, workers), Total: workers, Idle: workers - min(busy, workers)}
		if pr.attack {
			st.Queue = 14 + s.rnd.IntN(10)
			st.MaxReached = 3
		}
		r.pools[si.Domain] = st

		r.access[si.Domain] = s.traffic(si.Domain, rps*dt, now, pr.attack)
	}
	// System services.
	add("nginx", 33, "nginx: worker process", "nginx.service", 6*wave(1), 64<<20, 200<<10)
	add("fpm-master", 0, "php-fpm: master process (/etc/php/8.4/fpm/php-fpm.conf)", "php8.4-fpm.service", 0.4, 18<<20, 0)
	add("panel-web", 999, "php-fpm: pool servik", "servik-web.service", 2.5*wave(2), 140<<20, 4<<10)
	add("panel-db", 113, "postgres: servik servik [local] idle", "postgresql@16-main.service", 1.2*wave(3), 210<<20, 40<<10)
	add("agent", 0, "/usr/local/bin/servik-agent", "servik-agent.service", 0.6, 22<<20, 0)
	add("mysql", 114, "/usr/sbin/mysqld", "mysql.service", 14*wave(4), 1200<<20, 900<<10)
	add("pdns", 112, "/usr/sbin/pdns_server --config-name=servik", "pdns@servik.service", 0.3, 30<<20, 0)
	add("postfix", 0, "/usr/lib/postfix/sbin/master -w", "postfix@-.service", 0.2, 6<<20, 0)
	add("dovecot", 0, "/usr/sbin/dovecot -F", "dovecot.service", 0.8, 24<<20, 2<<10)
	add("rspamd", 115, "rspamd: normal process", "rspamd.service", 1.4*wave(5), 180<<20, 0)
	add("sshd", 0, "sshd: /usr/sbin/sshd -D", "ssh.service", 0.2, 8<<20, 0)
	// Software installed from Yazılım Yönetimi.
	if s.installed("/usr/sbin/apache2") {
		add("apache", 33, "/usr/sbin/apache2 -k start", "apache2.service", 3*wave(7), 90<<20, 20<<10)
	}
	if s.installed("/usr/bin/fail2ban-server") {
		add("fail2ban", 0, "/usr/bin/python3 /usr/bin/fail2ban-server -xf start", "fail2ban.service", 0.3, 40<<20, 0)
	}
	if s.h != nil {
		units, _ := filepath.Glob(s.h.Path("/etc/systemd/system/servik-redis-*.service"))
		for i, u := range units {
			unit := filepath.Base(u)
			add("redis:"+unit, 1000+i, "/usr/bin/redis-server 127.0.0.1", unit, 0.4*wave(8), 12<<20, 1<<10)
		}
	}

	for k := range s.procs {
		if !seen[k] {
			delete(s.procs, k)
		}
	}
	for _, p := range s.procs {
		r.procs = append(r.procs, *p)
	}
	s.cpuAll += 8 * dt * clockTicks
	s.cpuBusy += math.Min(total, 800) / 100 * dt * clockTicks
	r.cpuTotal, r.cpuBusy = uint64(s.cpuAll), uint64(s.cpuBusy)
	r.memAvail = r.memTotal - uint64(float64(14<<30)*wave(6))
	r.load = [3]float64{total / 100 * 1.1, total / 100, total / 100 * 0.9}
	s.netRx += (2.2e6 + 1.5e6*math.Sin(sec/300)) * wave(9) * dt
	s.netTx += (9e6 + 6e6*math.Sin(sec/300)) * wave(10) * dt
	r.netRx, r.netTx = uint64(s.netRx), uint64(s.netTx)

	// Someone guesses mail and SSH passwords; one site sends mail.
	ts := now.Format("2006-01-02T15:04:05.000000-07:00")
	if s.rnd.IntN(2) == 0 {
		r.mailLog = append(r.mailLog, ts+" mail postfix/submission/smtpd[3121]: warning: unknown[198.51.100.23]: SASL LOGIN authentication failed: authentication failure")
	}
	if s.rnd.IntN(3) == 0 {
		r.mailLog = append(r.mailLog, ts+" mail dovecot: imap-login: Disconnected: Connection closed (auth failed, 2 attempts in 4 secs): user=<info@example.com>, method=PLAIN, rip=203.0.113.77, lip=192.0.2.10")
	}
	if len(sites) > 0 && s.rnd.IntN(12) == 0 {
		s.qid++
		d := sites[len(sites)/2].Domain
		r.mailLog = append(r.mailLog, fmt.Sprintf("%s mail postfix/qmgr[2210]: D%06X: from=<bilgi@%s>, size=4210, nrcpt=1 (queue active)", ts, s.qid, d))
		status := "sent (250 2.0.0 OK)"
		if s.rnd.IntN(8) == 0 {
			status = "bounced (host mx.ornek-posta.net[198.51.100.40] said: 550 5.1.1 User unknown)"
		}
		r.mailLog = append(r.mailLog, fmt.Sprintf("%s mail postfix/smtp[2301]: D%06X: to=<musteri@ornek-posta.net>, relay=mx.ornek-posta.net[198.51.100.40]:25, delay=1, dsn=2.0.0, status=%s", ts, s.qid, status))
	}
	if len(sites) > 0 && s.rnd.IntN(4) == 0 {
		// Mail arriving for the sites, some refused as spam.
		s.qid++
		d := sites[s.rnd.IntN(len(sites))].Domain
		if s.rnd.IntN(5) == 0 {
			r.mailLog = append(r.mailLog, fmt.Sprintf("%s mail postfix/cleanup[2401]: E%06X: milter-reject: END-OF-MESSAGE from unknown[203.0.113.9]: 5.7.1 Spam message rejected; from=<kampanya@kotu.example> to=<bilgi@%s> proto=ESMTP helo=<x>", ts, s.qid, d))
		} else {
			r.mailLog = append(r.mailLog, fmt.Sprintf("%s mail postfix/qmgr[2210]: E%06X: from=<ayse@gmail.com>, size=8000, nrcpt=1 (queue active)", ts, s.qid))
			r.mailLog = append(r.mailLog, fmt.Sprintf("%s mail postfix/lmtp[2302]: E%06X: to=<bilgi@%s>, relay=mail[private/dovecot-lmtp], delay=0.2, dsn=2.0.0, status=sent (250 2.0.0 Saved)", ts, s.qid, d))
		}
	}
	if s.rnd.IntN(2) == 0 {
		r.authLog = append(r.authLog, ts+" host sshd[4410]: Failed password for invalid user admin from 192.0.2.201 port 52113 ssh2")
	}
	if len(sites) > 1 {
		r.dbServers = []string{"mysql"}
		for i, si := range sites[:min(len(sites), 4)] {
			for n := 0; n < 1+i; n++ {
				r.db = append(r.db, dbConn{Server: "mysql", User: si.User, DB: si.User + "_app", Active: n == 0 && i%2 == 0, Seconds: i})
			}
		}
	}
	return r
}

var demoPaths = []string{"/", "/", "/", "/urunler", "/urunler", "/sepet", "/iletisim", "/hakkimizda", "/blog/filtre-kahve-nasil-demlenir", "/blog/espresso-rehberi",
	"/urunler/etiyopya-yirgacheffe", "/build/assets/app.js", "/build/assets/app.css", "/favicon.ico", "/api/v1/products", "/img/kapak.webp"}

// demoVisitors are address prefixes (by the demo country table: TR, DE,
// NL, US, GB, AZ, FR) weighted by how often they visit.
var demoVisitors = []string{"88.%d.%d.%d", "88.%d.%d.%d", "88.%d.%d.%d", "78.%d.%d.%d", "78.%d.%d.%d", "5.1.%d.%d", "37.0.%d.%d", "3.%d.%d.%d", "2.24.%d.%d", "5.191.%d.%d", "2.%d.%d.%d"}

var demoReferrers = []string{"-", "-", "-", "-", "https://www.google.com/", "https://www.google.com/", "https://www.google.com.tr/", "https://www.instagram.com/",
	"https://www.bing.com/search?q=kahve", "https://t.co/x1", "https://www.facebook.com/", "https://yandex.com.tr/", "https://www.eksisozluk.com/"}

var demoBrowsers = []string{
	"Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Safari/537.36",
	"Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1",
	"Mozilla/5.0 (Linux; Android 15) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Mobile Safari/537.36",
	"Mozilla/5.0 (Macintosh; Intel Mac OS X 15_0) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15",
}

var demoBots = []string{
	"Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)",
	"Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)",
	"Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)",
	"Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)",
	"Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)",
	"Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)",
	"curl/8.5.0",
}

// traffic writes n made-up access log lines.
func (s *demoSampler) traffic(domain string, n float64, now time.Time, attack bool) []string {
	count := int(n)
	if s.rnd.Float64() < n-float64(count) {
		count++
	}
	ts := now.Format("02/Jan/2006:15:04:05 -0700")
	var lines []string
	line := func(ip, method, path string, status int, ref, agent string) {
		lines = append(lines, fmt.Sprintf(`%s - - [%s] "%s %s HTTP/1.1" %d %d "%s" "%s"`, ip, ts, method, path, status, 1000+s.rnd.IntN(30000), ref, agent))
	}
	for i := 0; i < count; i++ {
		ip := demoVisitors[s.rnd.IntN(len(demoVisitors))]
		for _, n := range []int{224 + s.rnd.IntN(16), s.rnd.IntN(255), 1 + s.rnd.IntN(250)} {
			ip = strings.Replace(ip, "%d", strconv.Itoa(n), 1)
		}
		status := 200
		if s.rnd.IntN(50) == 0 {
			status = 404
		}
		agent, ref := demoBrowsers[s.rnd.IntN(len(demoBrowsers))], demoReferrers[s.rnd.IntN(len(demoReferrers))]
		if s.rnd.IntN(5) == 0 {
			agent, ref = demoBots[s.rnd.IntN(len(demoBots))], "-"
		}
		line(ip, "GET", demoPaths[s.rnd.IntN(len(demoPaths))], status, ref, agent)
	}
	if attack {
		dt := DefaultInterval.Seconds()
		for i := 0; i < int(10*dt); i++ {
			line("45.155.205.233", "POST", "/wp-login.php", 200, "-", "Mozilla/5.0")
		}
		for i := 0; i < int(7*dt); i++ {
			line("193.42.33.117", "POST", "/xmlrpc.php", 200, "-", "Mozilla/5.0")
		}
		for i := 0; i < int(4*dt); i++ {
			line("103.153.79.6", "GET", "/sepet?urun="+fmt.Sprint(s.rnd.IntN(9999)), 502, "-", demoBrowsers[0])
		}
	}
	return lines
}

// simMailQueue is host.SimMailQueue (the simulated "postqueue -j").
const simMailQueue = "/var/spool/postfix/servik-sim-queue.jsonl"

// seedQueue fills the simulated mail queue once the simulated mail server
// is installed: a site whose contact form sends to dead addresses, and a
// few messages for a slow provider.
func (s *demoSampler) seedQueue(sites []siteInfo, now time.Time) {
	if s.seeded || s.h == nil || len(sites) == 0 {
		return
	}
	if _, err := os.Stat(s.h.Path("/usr/sbin/postfix")); err != nil {
		return
	}
	s.seeded = true
	if _, err := os.Stat(s.h.Path(simMailQueue)); err == nil {
		return
	}
	type rcpt struct {
		Address string `json:"address"`
		Reason  string `json:"delay_reason,omitempty"`
	}
	type entry struct {
		Queue      string `json:"queue_name"`
		ID         string `json:"queue_id"`
		Arrival    int64  `json:"arrival_time"`
		Size       int    `json:"message_size"`
		Sender     string `json:"sender"`
		Recipients []rcpt `json:"recipients"`
	}
	spam := sites[len(sites)/2].Domain
	var lines []string
	add := func(e entry) {
		b, _ := json.Marshal(e)
		lines = append(lines, string(b))
	}
	for i := 0; i < 38; i++ {
		add(entry{"deferred", fmt.Sprintf("4Q%07X", 0x1A2B00+i), now.Add(-time.Duration(20+i*7) * time.Minute).Unix(), 3800 + i*13, "bilgi@" + spam,
			[]rcpt{{fmt.Sprintf("musteri%d@ornek-posta.net", i), "host mx.ornek-posta.net[198.51.100.40] said: 550 5.1.1 User unknown (in reply to RCPT TO command)"}}})
	}
	for i := 0; i < 6; i++ {
		add(entry{"deferred", fmt.Sprintf("4R%07X", 0x3C4D00+i), now.Add(-time.Duration(5+i*11) * time.Minute).Unix(), 12000 + i*900, "siparis@" + sites[0].Domain,
			[]rcpt{{fmt.Sprintf("alici%d@yavas-saglayici.com", i), "connect to mx1.yavas-saglayici.com[203.0.113.25]:25: Connection timed out"}}})
	}
	for i := 0; i < 3; i++ {
		add(entry{"active", fmt.Sprintf("4S%07X", 0x5E6F00+i), now.Add(-time.Duration(i) * time.Second).Unix(), 2400, "noreply@" + sites[len(sites)-1].Domain,
			[]rcpt{{"kullanici@gmail.com", ""}}})
	}
	if err := os.MkdirAll(s.h.Path("/var/spool/postfix"), 0o755); err == nil {
		_ = os.WriteFile(s.h.Path(simMailQueue), []byte(strings.Join(lines, "\n")+"\n"), 0o644)
	}
}
