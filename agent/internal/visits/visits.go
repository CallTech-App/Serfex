// Package visits answers "what did this address do here?" from the sites'
// Nginx access logs.
//
//	/var/log/serfex/sites/<domain>/access.log        today
//	/var/log/serfex/sites/<domain>/access.log.1      yesterday (delaycompress)
//	/var/log/serfex/sites/<domain>/access.log.N.gz   older, as long as logs are kept
//
// Nothing is indexed: a lookup reads the logs, newest first, until it is
// done or its time budget is spent. A report that ran out of time says so
// and covers the newest logs of every site.
package visits

import (
	"bufio"
	"bytes"
	"compress/gzip"
	"context"
	"io"
	"net"
	"net/netip"
	"os"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"syscall"
	"time"

	"github.com/calltech-app/serfex/agent/internal/firewall"
	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/monitor"
	"github.com/calltech-app/serfex/agent/internal/stats"
)

const (
	sitesLogs = "/var/log/serfex/sites"

	// DefaultLimit and MaxLimit bound the recent requests of a report.
	DefaultLimit = 200
	MaxLimit     = 500

	// DefaultBudget is how long a lookup may read logs.
	DefaultBudget = 20 * time.Second

	// maxLine is the longest log line that is read; longer ones are
	// skipped.
	maxLine = 64 << 10
	// maxPaths is how many different paths are counted per site; an
	// address that scans random paths must not fill the memory.
	maxPaths = 2000
	maxText  = 200

	timeLayout = "02/Jan/2006:15:04:05 -0700"
)

// ValidationError reports a request that can never succeed as given.
type ValidationError struct{ msg string }

func (e *ValidationError) Error() string { return e.msg }

// Spec asks what an address did.
type Spec struct {
	// Address is an IPv4 or IPv6 address, or a prefix (at least /16 for
	// IPv4, /48 for IPv6).
	Address string `json:"address"`
	// Limit is how many recent requests to return: 200 when 0, at most 500.
	Limit int `json:"limit"`
}

// Report is what an address did on this server's sites.
type Report struct {
	Address string `json:"address"`
	// Hostname is the address's reverse DNS name, "" when it has none or
	// Address is a prefix.
	Hostname string `json:"hostname"`
	Requests int    `json:"requests"`
	// Denied counts requests answered 403.
	Denied int `json:"denied"`
	// Logins counts POSTs to login pages.
	Logins int          `json:"logins"`
	First  *time.Time   `json:"first"`
	Last   *time.Time   `json:"last"`
	Sites  []SiteVisits `json:"sites"`
	// Recent are the newest requests, newest first.
	Recent []Visit `json:"recent"`
	// Since is the oldest log line that was read: the report covers the
	// time from there on. nil when there are no logs.
	Since *time.Time `json:"since"`
	// Truncated is true when the time budget ran out before every log was
	// read.
	Truncated bool `json:"truncated"`
}

// SiteVisits is what the address did on one site.
type SiteVisits struct {
	Domain   string `json:"domain"`
	Requests int    `json:"requests"`
	Denied   int    `json:"denied"`
	// TopPath is the path asked for most often, TopCount how often.
	TopPath  string `json:"top_path"`
	TopCount int    `json:"top_count"`
	// Statuses counts the answers by status code ("200", "403").
	Statuses map[string]int `json:"statuses"`
	Last     time.Time      `json:"last"`
}

// Visit is one request.
type Visit struct {
	At     time.Time `json:"at"`
	Domain string    `json:"domain"`
	Method string    `json:"method"`
	Path   string    `json:"path"`
	Status int       `json:"status"`
	Agent  string    `json:"agent"`

	file, line int // where it was read, to order requests of one second
}

// Manager reads the logs.
type Manager struct {
	h host.Host
	// Now is the clock the budget is measured with; tests replace it.
	Now func() time.Time
	// Budget is how long a lookup may read logs.
	Budget time.Duration
	// Resolve returns the reverse DNS name of an address, "" for none.
	Resolve func(ctx context.Context, address string) string
}

// NewManager returns a Manager reading h's logs.
func NewManager(h host.Host) *Manager {
	return &Manager{h: h, Now: time.Now, Budget: DefaultBudget, Resolve: lookup}
}

var hostnameRe = regexp.MustCompile(`^[A-Za-z0-9._-]{1,253}$`)

// lookup asks the system's resolver, for two seconds at most. The name
// comes from whoever runs the address's reverse zone, so anything that
// does not look like a host name is dropped.
func lookup(ctx context.Context, address string) string {
	ctx, cancel := context.WithTimeout(ctx, 2*time.Second)
	defer cancel()
	names, err := net.DefaultResolver.LookupAddr(ctx, address)
	if err != nil || len(names) == 0 {
		return ""
	}
	name := strings.TrimSuffix(names[0], ".")
	if !hostnameRe.MatchString(name) {
		return ""
	}
	return name
}

// logFile is one access log, current or rotated.
type logFile struct {
	domain, path string
	// age is 0 for access.log and N for access.log.N[.gz].
	age int
	gz  bool
}

// Address reports what an address or prefix did, from every site's access
// logs.
func (m *Manager) Address(ctx context.Context, spec Spec) (Report, error) {
	p, err := firewall.Normalize(spec.Address)
	if err != nil {
		return Report{}, &ValidationError{err.Error()}
	}
	if p.Addr().Is4() && p.Bits() < 16 || p.Addr().Is6() && p.Bits() < 48 {
		return Report{}, &ValidationError{p.String() + " is too wide; look up at most a /16 (IPv4) or /48 (IPv6)"}
	}
	limit := spec.Limit
	if limit <= 0 {
		limit = DefaultLimit
	}
	limit = min(limit, MaxLimit)

	rep := Report{Address: p.String(), Sites: []SiteVisits{}, Recent: []Visit{}}
	var needle []byte
	hostname := make(chan string, 1)
	if p.IsSingleIP() {
		rep.Address = p.Addr().String()
		needle = []byte(rep.Address + " ")
		go func() { hostname <- m.Resolve(ctx, rep.Address) }()
	} else {
		hostname <- ""
	}

	sc := scan{prefix: p, needle: needle, limit: limit, sites: map[string]*siteAcc{}}
	start := m.Now()
	spent := func() bool { return m.Now().Sub(start) > m.Budget }
	for i, f := range m.files() {
		if err := ctx.Err(); err != nil {
			return Report{}, err
		}
		if spent() {
			rep.Truncated = true
			break
		}
		if !sc.read(ctx, m.h.Path(f.path), f, i, spent) {
			if err := ctx.Err(); err != nil {
				return Report{}, err
			}
			rep.Truncated = true
			break
		}
	}

	sc.trim()
	rep.Recent = append(rep.Recent, sc.recent...)
	for domain, a := range sc.sites {
		s := SiteVisits{Domain: domain, Requests: a.requests, Denied: a.denied, Statuses: a.statuses, Last: a.last}
		for path, n := range a.paths {
			if n > s.TopCount || n == s.TopCount && path < s.TopPath {
				s.TopPath, s.TopCount = path, n
			}
		}
		rep.Sites = append(rep.Sites, s)
		rep.Requests += a.requests
		rep.Denied += a.denied
		rep.Logins += a.logins
	}
	sort.Slice(rep.Sites, func(i, j int) bool {
		if rep.Sites[i].Requests != rep.Sites[j].Requests {
			return rep.Sites[i].Requests > rep.Sites[j].Requests
		}
		return rep.Sites[i].Domain < rep.Sites[j].Domain
	})
	rep.First, rep.Last, rep.Since = sc.first, sc.last, sc.since
	rep.Hostname = <-hostname
	return rep, nil
}

// files lists every site's access logs, newest first: today's log of
// every site, then yesterday's, and so on, so a lookup that runs out of
// time has read the same days of every site.
func (m *Manager) files() []logFile {
	var out []logFile
	sites, _ := os.ReadDir(m.h.Path(sitesLogs))
	for _, s := range sites {
		// A real directory only: never a link to somewhere else.
		if !s.Type().IsDir() || strings.HasPrefix(s.Name(), ".") {
			continue
		}
		dir := sitesLogs + "/" + s.Name()
		entries, _ := os.ReadDir(m.h.Path(dir))
		for _, e := range entries {
			rest, ok := strings.CutPrefix(e.Name(), "access.log")
			if !ok || !e.Type().IsRegular() {
				continue
			}
			f := logFile{domain: s.Name(), path: dir + "/" + e.Name()}
			if rest != "" {
				rest, f.gz = strings.CutSuffix(rest, ".gz")
				n, err := strconv.Atoi(strings.TrimPrefix(rest, "."))
				if err != nil || n < 1 || !strings.HasPrefix(rest, ".") {
					continue
				}
				f.age = n
			}
			out = append(out, f)
		}
	}
	sort.Slice(out, func(i, j int) bool {
		if out[i].age != out[j].age {
			return out[i].age < out[j].age
		}
		return out[i].domain < out[j].domain
	})
	return out
}

type siteAcc struct {
	requests, denied, logins int
	paths                    map[string]int
	statuses                 map[string]int
	last                     time.Time
}

// scan collects the matching lines.
type scan struct {
	prefix netip.Prefix
	// needle is "address " for a single address: a line of that address
	// starts with it, so the others are passed over without parsing.
	needle []byte
	limit  int

	sites              map[string]*siteAcc
	recent             []Visit
	first, last, since *time.Time
}

// read adds one log file. It returns false when it stopped early: the
// time budget ran out or the call was cancelled.
func (s *scan) read(ctx context.Context, path string, f logFile, index int, spent func() bool) bool {
	fh, err := os.OpenFile(path, os.O_RDONLY|syscall.O_NOFOLLOW, 0)
	if err != nil {
		return true
	}
	defer fh.Close()
	if fi, err := fh.Stat(); err != nil || !fi.Mode().IsRegular() {
		return true
	}
	var in io.Reader = fh
	if f.gz {
		zr, err := gzip.NewReader(fh)
		if err != nil {
			return true // not a gzip file: nothing to read
		}
		defer zr.Close()
		in = zr
	}
	r := bufio.NewReaderSize(in, maxLine)
	dated := false
	for n := 0; ; n++ {
		if n%4096 == 4095 && (spent() || ctx.Err() != nil) {
			return false
		}
		line, long, err := r.ReadLine()
		if err != nil {
			// The end, or a damaged archive: what was read still counts.
			return true
		}
		if long {
			for long && err == nil {
				_, long, err = r.ReadLine()
			}
			continue
		}
		if !dated {
			// Lines are in time order, so the first one with a time is
			// the oldest of this file.
			if at, ok := lineTime(line); ok {
				dated = true
				if s.since == nil || at.Before(*s.since) {
					s.since = &at
				}
			}
		}
		if !s.matches(line) {
			continue
		}
		at, ok := lineTime(line)
		if !ok {
			continue
		}
		req, ok := stats.ParseRequest(string(line))
		if !ok {
			continue
		}
		s.add(Visit{
			At: at, Domain: f.domain, Method: clip(req.Method, 16), Path: clip(req.Path, maxText),
			Status: req.Status, Agent: clip(req.Agent, maxText), file: index, line: n,
		})
	}
}

func (s *scan) matches(line []byte) bool {
	if s.needle != nil {
		return bytes.HasPrefix(line, s.needle)
	}
	ip, _, ok := bytes.Cut(line, []byte{' '})
	if !ok {
		return false
	}
	a, err := netip.ParseAddr(string(ip))
	return err == nil && s.prefix.Contains(a.Unmap())
}

func (s *scan) add(v Visit) {
	a := s.sites[v.Domain]
	if a == nil {
		a = &siteAcc{paths: map[string]int{}, statuses: map[string]int{}}
		s.sites[v.Domain] = a
	}
	a.requests++
	a.statuses[strconv.Itoa(v.Status)]++
	if v.Status == 403 {
		a.denied++
	}
	if v.Method == "POST" && monitor.LoginPath(v.Path) {
		a.logins++
	}
	if v.Path != "" {
		if _, known := a.paths[v.Path]; known || len(a.paths) < maxPaths {
			a.paths[v.Path]++
		}
	}
	if v.At.After(a.last) {
		a.last = v.At
	}
	if s.first == nil || v.At.Before(*s.first) {
		at := v.At
		s.first = &at
	}
	if s.last == nil || v.At.After(*s.last) {
		at := v.At
		s.last = &at
	}
	s.recent = append(s.recent, v)
	if len(s.recent) >= 4*s.limit {
		s.trim()
	}
}

// trim keeps the newest requests, newest first. Requests of the same
// second keep the order they were logged in, the last one first.
func (s *scan) trim() {
	sort.Slice(s.recent, func(i, j int) bool {
		a, b := s.recent[i], s.recent[j]
		if !a.At.Equal(b.At) {
			return a.At.After(b.At)
		}
		if a.file != b.file {
			return a.file < b.file
		}
		return a.line > b.line
	})
	if len(s.recent) > s.limit {
		s.recent = s.recent[:s.limit]
	}
}

// lineTime reads the "[18/Nov/2026:10:00:00 +0000]" of a line: the last
// bracket before the request. The user name of HTTP Basic authentication
// comes before it and is whatever the visitor sent, brackets included;
// Nginx writes a double quote in it as \x22, so the first quote on the
// line opens the request.
func lineTime(line []byte) (time.Time, bool) {
	head := line
	if q := bytes.IndexByte(line, '"'); q >= 0 {
		head = line[:q]
	}
	i := bytes.LastIndexByte(head, '[')
	if i < 0 {
		return time.Time{}, false
	}
	j := bytes.IndexByte(line[i:], ']')
	if j < 0 {
		return time.Time{}, false
	}
	at, err := time.Parse(timeLayout, string(line[i+1:i+j]))
	if err != nil {
		return time.Time{}, false
	}
	return at.UTC(), true
}

// clip shortens s to n bytes and drops what is not printable: paths and
// user agents are whatever the visitor sent.
func clip(s string, n int) string {
	if len(s) > n {
		s = s[:n]
	}
	return strings.Map(func(r rune) rune {
		if r < 0x20 || r == 0x7f || r == '�' {
			return -1
		}
		return r
	}, s)
}
