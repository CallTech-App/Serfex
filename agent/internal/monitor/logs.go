package monitor

import (
	"net/netip"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"
)

// Rules that make an address a suspect. Web rules count the last minute,
// login rules for mail and SSH the last ten minutes.
const (
	FloodPerMinute     = 300 // requests from one address to one site
	LoginPerMinute     = 20  // POSTs to a login page
	ScanPerMinute      = 60  // "404 Not Found" answers
	AuthFailsPer10Min  = 10  // failed mail or SSH logins
	webWindow          = time.Minute
	authWindow         = 10 * time.Minute
	suspectMemory      = 10 * time.Minute
	topIPs             = 5
	maxTrackedAuthIPs  = 5000
	maxTrackedMessages = 20000
)

// loginPaths are login pages that bots guess passwords on.
var loginPaths = map[string]bool{
	"/wp-login.php": true, "/xmlrpc.php": true, "/login": true, "/admin/login": true,
	"/user/login": true, "/administrator/index.php": true, "/admin": true,
}

// access is one parsed Nginx access log line ("combined" format).
type access struct {
	IP     string
	Method string
	Path   string
	Status int
}

func parseAccess(line string) (access, bool) {
	ip, rest, ok := strings.Cut(line, " ")
	if !ok {
		return access{}, false
	}
	q1 := strings.IndexByte(rest, '"')
	if q1 < 0 {
		return access{}, false
	}
	q2 := strings.IndexByte(rest[q1+1:], '"')
	if q2 < 0 {
		return access{}, false
	}
	req := strings.Fields(rest[q1+1 : q1+1+q2])
	after := strings.Fields(rest[q1+q2+2:])
	if len(after) == 0 {
		return access{}, false
	}
	status, err := strconv.Atoi(after[0])
	if err != nil {
		return access{}, false
	}
	a := access{IP: ip, Status: status}
	if len(req) >= 2 {
		a.Method = req[0]
		a.Path, _, _ = strings.Cut(req[1], "?")
	}
	return a, true
}

// ipCount is what one address did to one site during one sample.
type ipCount struct {
	Requests  int
	Logins    int
	NotFound  int
	LoginPath string
}

// webTick is one sample's traffic of a site.
type webTick struct {
	at       time.Time
	requests int
	err4     int
	err5     int
	ips      map[string]*ipCount
}

func newWebTick(at time.Time, lines []string) webTick {
	t := webTick{at: at, ips: map[string]*ipCount{}}
	for _, line := range lines {
		a, ok := parseAccess(line)
		if !ok {
			continue
		}
		t.requests++
		switch {
		case a.Status >= 500:
			t.err5++
		case a.Status >= 400:
			t.err4++
		}
		c := t.ips[a.IP]
		if c == nil {
			c = &ipCount{}
			t.ips[a.IP] = c
		}
		c.Requests++
		if a.Status == 404 {
			c.NotFound++
		}
		if a.Method == "POST" && loginPaths[strings.ToLower(a.Path)] {
			c.Logins++
			c.LoginPath = a.Path
		}
	}
	return t
}

// Suspect is an address that broke one of the rules recently.
type Suspect struct {
	IP string `json:"ip"`
	// Target is the site attacked, or "" when the whole server is (mail
	// and SSH logins).
	Target string `json:"target"`
	// Kind is flood, login, scan, mail or ssh.
	Kind string `json:"kind"`
	// Detail is the login page, or the mail service.
	Detail string `json:"detail"`
	// Count is how many requests or failed logins the rule's window holds
	// now (per minute for web rules, per ten minutes for logins).
	Count int       `json:"count"`
	Peak  int       `json:"peak"`
	Since time.Time `json:"since"`
	Last  time.Time `json:"last"`
}

type suspectKey struct{ ip, target, kind string }

// detector keeps the windows the rules look at.
type detector struct {
	web      map[string][]webTick // by site, newest last
	auth     map[authKey][]authEvent
	suspects map[suspectKey]*Suspect
}

type authKey struct{ ip, kind string }

type authEvent struct {
	at     time.Time
	n      int
	detail string
}

func newDetector() *detector {
	return &detector{web: map[string][]webTick{}, auth: map[authKey][]authEvent{}, suspects: map[suspectKey]*Suspect{}}
}

// addWeb records a sample of a site's traffic and applies the web rules.
func (d *detector) addWeb(site string, t webTick) {
	ticks := append(d.web[site], t)
	for len(ticks) > 0 && t.at.Sub(ticks[0].at) >= webWindow {
		ticks = ticks[1:]
	}
	d.web[site] = ticks

	totals := d.ipTotals(site)
	for ip, c := range totals {
		if skipIP(ip) {
			continue
		}
		d.check(ip, site, "flood", "", c.Requests, FloodPerMinute, t.at)
		d.check(ip, site, "login", c.LoginPath, c.Logins, LoginPerMinute, t.at)
		d.check(ip, site, "scan", "", c.NotFound, ScanPerMinute, t.at)
	}
	// Suspects that went quiet show their current count.
	for k, s := range d.suspects {
		if k.target != site || s.Kind == "mail" || s.Kind == "ssh" {
			continue
		}
		c := totals[k.ip]
		if c == nil {
			s.Count = 0
			continue
		}
		switch s.Kind {
		case "flood":
			s.Count = c.Requests
		case "login":
			s.Count = c.Logins
		case "scan":
			s.Count = c.NotFound
		}
	}
}

func (d *detector) ipTotals(site string) map[string]*ipCount {
	totals := map[string]*ipCount{}
	for _, t := range d.web[site] {
		for ip, c := range t.ips {
			s := totals[ip]
			if s == nil {
				s = &ipCount{}
				totals[ip] = s
			}
			s.Requests += c.Requests
			s.Logins += c.Logins
			s.NotFound += c.NotFound
			if c.LoginPath != "" {
				s.LoginPath = c.LoginPath
			}
		}
	}
	return totals
}

// IPCount is an address and how many requests it sent in the last minute.
type IPCount struct {
	IP       string `json:"ip"`
	Requests int    `json:"requests"`
}

// top returns the addresses that sent a site the most requests in the
// last minute.
func (d *detector) top(site string) []IPCount {
	totals := d.ipTotals(site)
	list := make([]IPCount, 0, len(totals))
	for ip, c := range totals {
		list = append(list, IPCount{IP: ip, Requests: c.Requests})
	}
	sort.Slice(list, func(i, j int) bool {
		if list[i].Requests != list[j].Requests {
			return list[i].Requests > list[j].Requests
		}
		return list[i].IP < list[j].IP
	})
	if len(list) > topIPs {
		list = list[:topIPs]
	}
	return list
}

func (d *detector) check(ip, target, kind, detail string, count, limit int, at time.Time) {
	if count < limit {
		return
	}
	k := suspectKey{ip, target, kind}
	s := d.suspects[k]
	if s == nil {
		s = &Suspect{IP: ip, Target: target, Kind: kind, Since: at}
		d.suspects[k] = s
	}
	s.Count, s.Last = count, at
	if count > s.Peak {
		s.Peak = count
	}
	if detail != "" {
		s.Detail = detail
	}
}

var (
	saslFailRe    = regexp.MustCompile(`postfix/[a-z/]*smtpd\[\d+\]: warning: [^\[\s]*\[([0-9A-Fa-f:.]+)\]: SASL \S+ authentication failed`)
	dovecotFailRe = regexp.MustCompile(`dovecot: (imap|pop3|submission|managesieve)-login: .*auth failed(?:, (\d+) attempts)?.*\brip=([0-9A-Fa-f:.]+)`)
	sshFailRe     = regexp.MustCompile(`sshd\[\d+\]: (?:Failed \S+ for (?:invalid user )?\S*|Invalid user \S*) from ([0-9A-Fa-f:.]+) port`)
	pickupRe      = regexp.MustCompile(`postfix/pickup\[\d+\]: ([0-9A-Za-z]+): uid=(\d+) from=`)
	qmgrFromRe    = regexp.MustCompile(`postfix/qmgr\[\d+\]: ([0-9A-Za-z]+): from=<[^@>]*@([^>]+)>, size=\d+, nrcpt=(\d+)`)
)

// addAuthLines applies the login rules to new mail.log and auth.log lines.
func (d *detector) addAuthLines(mail, auth []string, at time.Time) {
	add := func(ip, kind, detail string, n int) {
		if _, err := netip.ParseAddr(ip); err != nil || skipIP(ip) {
			return
		}
		k := authKey{ip, kind}
		if _, ok := d.auth[k]; !ok && len(d.auth) >= maxTrackedAuthIPs {
			return
		}
		d.auth[k] = append(d.auth[k], authEvent{at: at, n: n, detail: detail})
	}
	for _, line := range mail {
		if m := saslFailRe.FindStringSubmatch(line); m != nil {
			add(m[1], "mail", "smtp", 1)
		} else if m := dovecotFailRe.FindStringSubmatch(line); m != nil {
			n, _ := strconv.Atoi(m[2])
			add(m[3], "mail", m[1], max(n, 1))
		}
	}
	for _, line := range auth {
		if m := sshFailRe.FindStringSubmatch(line); m != nil {
			add(m[1], "ssh", "ssh", 1)
		}
	}
	for k, events := range d.auth {
		for len(events) > 0 && at.Sub(events[0].at) >= authWindow {
			events = events[1:]
		}
		if len(events) == 0 {
			delete(d.auth, k)
			if s := d.suspects[suspectKey{k.ip, "", k.kind}]; s != nil {
				s.Count = 0
			}
			continue
		}
		d.auth[k] = events
		n := 0
		for _, e := range events {
			n += e.n
		}
		if s := d.suspects[suspectKey{k.ip, "", k.kind}]; s != nil {
			s.Count = n
		}
		d.check(k.ip, "", k.kind, events[len(events)-1].detail, n, AuthFailsPer10Min, at)
	}
}

// expire forgets suspects that have been quiet for a while, and the
// traffic windows of sites that no longer exist.
func (d *detector) expire(at time.Time, sites map[string]bool) {
	for k, s := range d.suspects {
		if at.Sub(s.Last) >= suspectMemory || (k.target != "" && !sites[k.target]) {
			delete(d.suspects, k)
		}
	}
	for s := range d.web {
		if !sites[s] {
			delete(d.web, s)
		}
	}
}

func (d *detector) list() []Suspect {
	out := make([]Suspect, 0, len(d.suspects))
	for _, s := range d.suspects {
		out = append(out, *s)
	}
	sort.Slice(out, func(i, j int) bool {
		if out[i].Count != out[j].Count {
			return out[i].Count > out[j].Count
		}
		return out[i].IP < out[j].IP
	})
	return out
}

// skipIP leaves the server talking to itself out of the rules.
func skipIP(ip string) bool {
	a, err := netip.ParseAddr(ip)
	return err != nil || a.IsLoopback() || a.IsUnspecified()
}

// mailCounter counts the messages each sender sends: PHP's mail() by
// account (the uid Postfix's pickup logs), everything else by the
// sender's domain. Each message counts once, however often Postfix
// retries it.
type mailCounter struct {
	seen  map[string]time.Time
	byUID map[int]int
	byDom map[string]int
}

func newMailCounter() *mailCounter {
	return &mailCounter{seen: map[string]time.Time{}}
}

func (c *mailCounter) add(lines []string, at time.Time) {
	c.byUID, c.byDom = map[int]int{}, map[string]int{}
	for _, line := range lines {
		if m := pickupRe.FindStringSubmatch(line); m != nil {
			if _, dup := c.seen[m[1]]; dup {
				continue
			}
			c.seen[m[1]] = at
			uid, _ := strconv.Atoi(m[2])
			c.byUID[uid]++
		} else if m := qmgrFromRe.FindStringSubmatch(line); m != nil {
			if _, dup := c.seen[m[1]]; dup {
				continue
			}
			c.seen[m[1]] = at
			c.byDom[strings.ToLower(m[2])]++
		}
	}
	for id, t := range c.seen {
		if at.Sub(t) > 6*time.Hour || len(c.seen) > maxTrackedMessages {
			delete(c.seen, id)
		}
	}
}
