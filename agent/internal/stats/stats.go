// Package stats turns the sites' access logs and the mail log into the
// numbers behind the panel's statistics: requests, bandwidth, visitors,
// pages, referrers, countries and bots per site and hour, and messages
// sent, received, rejected and bounced per mail domain and hour.
//
// The monitor (package monitor) already reads the new log lines every
// two seconds and hands them over. Counts stay in memory until the panel
// collects them (stats.collect) and stores hourly and daily rollups in
// its own database; nothing here keeps history. Visitor addresses never
// leave the agent: a visitor is a hash of address and browser, kept for
// the day to count each one once.
//
// Collection is acknowledged: a batch stays pending, and is handed out
// again, until the panel names it in its next call. A batch the panel
// stored is never counted twice, and one it lost is sent again.
package stats

import (
	"encoding/gob"
	"errors"
	"fmt"
	"hash/fnv"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"sync"
	"time"
)

// Item kinds.
const (
	KindPage     = "page"
	KindReferrer = "referrer"
	KindCountry  = "country"
	KindBot      = "bot"
)

const (
	// maxItems bounds the distinct pages, referrers, countries or bots one
	// site keeps per hour of a batch; the rest are added up under "".
	maxItems = 300
	// maxVisitors bounds the visitors remembered per site and day; past
	// it, new visitors are no longer counted.
	maxVisitors = 1_000_000
	// maxQueue bounds the mail queue IDs remembered to find a delivery's
	// sender.
	maxQueue = 50_000
	// keepHours drops counts older than this when the panel has not
	// collected for a long time.
	keepHours = 7 * 24
	// maxPath bounds a page's path.
	maxPath = 200
)

// Counts are a site's totals for one hour.
type Counts struct {
	Requests    uint64 `json:"requests"`
	Bytes       uint64 `json:"bytes"`
	Visitors    uint64 `json:"visitors"` // first seen today, in this hour
	Pages       uint64 `json:"pages"`    // page views by people
	BotRequests uint64 `json:"bot_requests"`
	BotBytes    uint64 `json:"bot_bytes"`
	S2          uint64 `json:"s2xx"`
	S3          uint64 `json:"s3xx"`
	S4          uint64 `json:"s4xx"`
	S5          uint64 `json:"s5xx"`
}

// Item is one page, referrer, country or bot.
type Item struct {
	Requests uint64 `json:"r"`
	Bytes    uint64 `json:"b"`
	Visitors uint64 `json:"v"`
}

// Hour is one site's hour.
type Hour struct {
	Hour time.Time `json:"hour"`
	Counts
	// Items by kind, then by value.
	Items map[string]map[string]Item `json:"items"`
}

// SiteHours are the hours of one site in a batch.
type SiteHours struct {
	Domain string `json:"domain"`
	Hours  []Hour `json:"hours"`
}

// MailCounts are a mail domain's messages in one hour.
type MailCounts struct {
	Sent     uint64 `json:"sent"`     // delivered to other servers
	Received uint64 `json:"received"` // delivered to the domain's mailboxes
	Rejected uint64 `json:"rejected"` // refused on the way in (spam, unknown user)
	Bounced  uint64 `json:"bounced"`  // could not be delivered to other servers
}

// MailHour is one mail domain's hour.
type MailHour struct {
	Hour time.Time `json:"hour"`
	MailCounts
}

// MailHours are the hours of one mail domain in a batch.
type MailHours struct {
	Domain string     `json:"domain"`
	Hours  []MailHour `json:"hours"`
}

// Batch is what stats.collect returns.
type Batch struct {
	// ID names the batch in the next call's Ack; "" when there was
	// nothing to collect.
	ID    string      `json:"id"`
	Sites []SiteHours `json:"sites"`
	Mail  []MailHours `json:"mail"`
	// Countries tells whether the country table is loaded.
	Countries bool `json:"countries"`
}

// CollectSpec acknowledges the batch the panel stored last.
type CollectSpec struct {
	Ack string `json:"ack"`
}

type hourKey struct {
	domain string
	hour   int64 // unix seconds
}

type hourAcc struct {
	Counts
	Items map[string]map[string]*Item
}

type dayVisitors struct {
	Day  string
	Seen map[uint64]struct{}
}

// Aggregator adds up log lines until they are collected.
type Aggregator struct {
	mu       sync.Mutex
	now      func() time.Time
	geo      *Geo
	epoch    string
	seq      int
	web      map[hourKey]*hourAcc
	mail     map[hourKey]*MailCounts
	pending  *Batch
	visitors map[string]*dayVisitors
	queue    map[string]string // Postfix queue ID -> local sender domain
	order    []string          // queue IDs, oldest first

	// MailDomains returns the server's mail domains; only they are
	// counted.
	MailDomains func() map[string]bool
}

// New returns an empty aggregator.
func New() *Aggregator {
	return &Aggregator{
		now: time.Now, epoch: fmt.Sprintf("%x", time.Now().UnixNano()),
		web: map[hourKey]*hourAcc{}, mail: map[hourKey]*MailCounts{},
		visitors: map[string]*dayVisitors{}, queue: map[string]string{},
	}
}

// SetClock replaces the clock that ages counts out, for tests that
// replay logs from a fixed day.
func (a *Aggregator) SetClock(now func() time.Time) {
	a.mu.Lock()
	a.now = now
	a.mu.Unlock()
}

// SetGeo installs the country table.
func (a *Aggregator) SetGeo(g *Geo) {
	a.mu.Lock()
	a.geo = g
	a.mu.Unlock()
}

// HasGeo tells whether a country table is installed.
func (a *Aggregator) HasGeo() bool {
	a.mu.Lock()
	defer a.mu.Unlock()
	return a.geo.Size() > 0
}

func (a *Aggregator) acc(domain string, at time.Time) *hourAcc {
	k := hourKey{domain, at.UTC().Truncate(time.Hour).Unix()}
	h := a.web[k]
	if h == nil {
		h = &hourAcc{Items: map[string]map[string]*Item{}}
		a.web[k] = h
	}
	return h
}

func (h *hourAcc) item(kind, value string) *Item {
	m := h.Items[kind]
	if m == nil {
		m = map[string]*Item{}
		h.Items[kind] = m
	}
	it := m[value]
	if it == nil {
		if len(m) >= maxItems {
			value = ""
			if it = m[value]; it != nil {
				return it
			}
		}
		it = &Item{}
		m[value] = it
	}
	return it
}

// AddAccess counts a site's new access log lines. names are the host
// names the site answers to, so visitors moving between its own pages
// are not counted as referred.
func (a *Aggregator) AddAccess(domain string, names []string, lines []string, at time.Time) {
	if len(lines) == 0 {
		return
	}
	a.mu.Lock()
	defer a.mu.Unlock()
	h := a.acc(domain, at)
	day := at.In(time.Local).Format("2006-01-02")
	seen := a.visitors[domain]
	if seen == nil || seen.Day != day {
		seen = &dayVisitors{Day: day, Seen: map[uint64]struct{}{}}
		a.visitors[domain] = seen
	}
	for _, line := range lines {
		r, ok := ParseRequest(line)
		if !ok {
			continue
		}
		h.Requests++
		h.Bytes += r.Bytes
		switch {
		case r.Status >= 500:
			h.S5++
		case r.Status >= 400:
			h.S4++
		case r.Status >= 300:
			h.S3++
		case r.Status >= 200:
			h.S2++
		}
		if bot := r.Bot(); bot != "" {
			h.BotRequests++
			h.BotBytes += r.Bytes
			it := h.item(KindBot, bot)
			it.Requests++
			it.Bytes += r.Bytes
			continue
		}
		country := a.geo.Country(r.IP)
		if country == "" {
			country = "-"
		}
		c := h.item(KindCountry, country)
		c.Requests++
		c.Bytes += r.Bytes
		f := fnv.New64a()
		f.Write([]byte(r.IP))
		f.Write([]byte{0})
		f.Write([]byte(r.Agent))
		key := f.Sum64()
		if _, ok := seen.Seen[key]; !ok && len(seen.Seen) < maxVisitors {
			seen.Seen[key] = struct{}{}
			h.Visitors++
			c.Visitors++
		}
		if r.IsPage() {
			h.Pages++
			p := r.Path
			if len(p) > maxPath {
				p = p[:maxPath]
			}
			it := h.item(KindPage, p)
			it.Requests++
			it.Bytes += r.Bytes
			if ref := r.ReferrerHost(names); ref != "" {
				h.item(KindReferrer, ref).Requests++
			}
		}
	}
}

var (
	qmgrFrom   = regexp.MustCompile(`postfix/qmgr\[\d+\]: ([0-9A-Za-z]+): from=<[^@>]*@([^>]+)>`)
	delivery   = regexp.MustCompile(`postfix/(lmtp|smtp|virtual|local)\[\d+\]: ([0-9A-Za-z]+): to=<[^@>]*@([^>]+)>,.* status=(sent|bounced)`)
	rejectLine = regexp.MustCompile(`(?:: reject: |milter-reject: ).*; from=<[^>]*> to=<[^@>]*@([^>]+)>`)
)

// AddMail counts the new lines of the mail log.
func (a *Aggregator) AddMail(lines []string, at time.Time) {
	if len(lines) == 0 || a.MailDomains == nil {
		return
	}
	domains := a.MailDomains()
	if len(domains) == 0 {
		return
	}
	a.mu.Lock()
	defer a.mu.Unlock()
	get := func(d string) *MailCounts {
		k := hourKey{d, at.UTC().Truncate(time.Hour).Unix()}
		c := a.mail[k]
		if c == nil {
			c = &MailCounts{}
			a.mail[k] = c
		}
		return c
	}
	for _, line := range lines {
		if m := qmgrFrom.FindStringSubmatch(line); m != nil {
			d := strings.ToLower(m[2])
			if domains[d] {
				if _, ok := a.queue[m[1]]; !ok {
					a.order = append(a.order, m[1])
				}
				a.queue[m[1]] = d
				for len(a.order) > maxQueue {
					delete(a.queue, a.order[0])
					a.order = a.order[1:]
				}
			}
			continue
		}
		if m := delivery.FindStringSubmatch(line); m != nil {
			agent, id, rcpt, status := m[1], m[2], strings.ToLower(m[3]), m[4]
			if agent == "smtp" {
				if sender, ok := a.queue[id]; ok {
					if status == "sent" {
						get(sender).Sent++
					} else {
						get(sender).Bounced++
					}
				}
			} else if status == "sent" && domains[rcpt] {
				get(rcpt).Received++
			}
			continue
		}
		if m := rejectLine.FindStringSubmatch(line); m != nil {
			if d := strings.ToLower(m[1]); domains[d] {
				get(d).Rejected++
			}
		}
	}
}

// Collect drops the batch the panel acknowledged and hands out the
// pending one, freezing what was counted since into a new batch when
// nothing is pending.
func (a *Aggregator) Collect(spec CollectSpec) Batch {
	a.mu.Lock()
	defer a.mu.Unlock()
	if a.pending != nil && spec.Ack != "" && spec.Ack == a.pending.ID {
		a.pending = nil
	}
	a.trim()
	if a.pending == nil && (len(a.web) > 0 || len(a.mail) > 0) {
		a.seq++
		b := &Batch{ID: fmt.Sprintf("%s-%d", a.epoch, a.seq), Sites: []SiteHours{}, Mail: []MailHours{}}
		sites := map[string]*SiteHours{}
		for k, h := range a.web {
			s := sites[k.domain]
			if s == nil {
				s = &SiteHours{Domain: k.domain}
				sites[k.domain] = s
			}
			hr := Hour{Hour: time.Unix(k.hour, 0).UTC(), Counts: h.Counts, Items: map[string]map[string]Item{}}
			for kind, m := range h.Items {
				hr.Items[kind] = make(map[string]Item, len(m))
				for v, it := range m {
					hr.Items[kind][v] = *it
				}
			}
			s.Hours = append(s.Hours, hr)
		}
		for _, s := range sites {
			sort.Slice(s.Hours, func(i, j int) bool { return s.Hours[i].Hour.Before(s.Hours[j].Hour) })
			b.Sites = append(b.Sites, *s)
		}
		sort.Slice(b.Sites, func(i, j int) bool { return b.Sites[i].Domain < b.Sites[j].Domain })
		mail := map[string]*MailHours{}
		for k, c := range a.mail {
			m := mail[k.domain]
			if m == nil {
				m = &MailHours{Domain: k.domain}
				mail[k.domain] = m
			}
			m.Hours = append(m.Hours, MailHour{Hour: time.Unix(k.hour, 0).UTC(), MailCounts: *c})
		}
		for _, m := range mail {
			sort.Slice(m.Hours, func(i, j int) bool { return m.Hours[i].Hour.Before(m.Hours[j].Hour) })
			b.Mail = append(b.Mail, *m)
		}
		sort.Slice(b.Mail, func(i, j int) bool { return b.Mail[i].Domain < b.Mail[j].Domain })
		a.pending = b
		a.web, a.mail = map[hourKey]*hourAcc{}, map[hourKey]*MailCounts{}
	}
	out := Batch{Sites: []SiteHours{}, Mail: []MailHours{}}
	if a.pending != nil {
		out = *a.pending
	}
	out.Countries = a.geo.Size() > 0
	return out
}

// trim drops counts the panel has not collected for a week, so a panel
// that stopped collecting does not make the agent grow without end.
func (a *Aggregator) trim() {
	oldest := a.now().Add(-keepHours * time.Hour).Unix()
	for k := range a.web {
		if k.hour < oldest {
			delete(a.web, k)
		}
	}
	for k := range a.mail {
		if k.hour < oldest {
			delete(a.mail, k)
		}
	}
}

// saved is what Save writes: the counts not yet collected and today's
// visitors, so a restart of the agent neither loses nor recounts them.
type saved struct {
	Epoch    string
	Seq      int
	Web      map[string]map[int64]*hourAcc
	Mail     map[string]map[int64]*MailCounts
	Pending  *Batch
	Visitors map[string]*dayVisitors
}

// Save writes the aggregator's state to file.
func (a *Aggregator) Save(file string) error {
	a.mu.Lock()
	s := saved{Epoch: a.epoch, Seq: a.seq, Web: map[string]map[int64]*hourAcc{}, Mail: map[string]map[int64]*MailCounts{}, Pending: a.pending, Visitors: a.visitors}
	for k, h := range a.web {
		if s.Web[k.domain] == nil {
			s.Web[k.domain] = map[int64]*hourAcc{}
		}
		s.Web[k.domain][k.hour] = h
	}
	for k, c := range a.mail {
		if s.Mail[k.domain] == nil {
			s.Mail[k.domain] = map[int64]*MailCounts{}
		}
		s.Mail[k.domain][k.hour] = c
	}
	defer a.mu.Unlock()
	if err := os.MkdirAll(filepath.Dir(file), 0o700); err != nil {
		return err
	}
	tmp := file + ".tmp"
	f, err := os.OpenFile(tmp, os.O_CREATE|os.O_TRUNC|os.O_WRONLY, 0o600)
	if err != nil {
		return err
	}
	if err := gob.NewEncoder(f).Encode(s); err != nil {
		f.Close()
		return err
	}
	if err := f.Close(); err != nil {
		return err
	}
	return os.Rename(tmp, file)
}

// Load reads what Save wrote and removes the file, so the same counts
// are never loaded twice. A missing file is not an error.
func (a *Aggregator) Load(file string) error {
	f, err := os.Open(file)
	if errors.Is(err, os.ErrNotExist) {
		return nil
	}
	if err != nil {
		return err
	}
	var s saved
	err = gob.NewDecoder(f).Decode(&s)
	f.Close()
	_ = os.Remove(file)
	if err != nil {
		return err
	}
	a.mu.Lock()
	defer a.mu.Unlock()
	a.epoch, a.seq, a.pending = s.Epoch, s.Seq, s.Pending
	for d, hours := range s.Web {
		for hr, h := range hours {
			if h.Items == nil {
				h.Items = map[string]map[string]*Item{}
			}
			a.web[hourKey{d, hr}] = h
		}
	}
	for d, hours := range s.Mail {
		for hr, c := range hours {
			a.mail[hourKey{d, hr}] = c
		}
	}
	for d, v := range s.Visitors {
		if v != nil && v.Seen != nil {
			a.visitors[d] = v
		}
	}
	return nil
}

// Forget drops the visitors of sites that no longer exist.
func (a *Aggregator) Forget(sites map[string]bool) {
	a.mu.Lock()
	defer a.mu.Unlock()
	for d := range a.visitors {
		if !sites[d] {
			delete(a.visitors, d)
		}
	}
}
