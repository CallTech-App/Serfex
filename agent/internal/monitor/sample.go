package monitor

import (
	"bufio"
	"bytes"
	"context"
	"os"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/site"
)

// clockTicks is USER_HZ, the unit of the CPU times in /proc. It is 100 on
// every architecture Ubuntu ships.
const clockTicks = 100

// raw is one sample of the machine, before it is attributed to sites.
type raw struct {
	uptime    float64 // seconds since boot
	cpuBusy   uint64  // jiffies all CPUs spent working, since boot
	cpuTotal  uint64  // all jiffies since boot
	cpus      int
	memTotal  uint64
	memAvail  uint64
	load      [3]float64
	netRx     uint64 // bytes received on every interface but loopback, since boot
	netTx     uint64
	procs     []proc
	pools     map[string]poolStatus // by site
	db        []dbConn
	dbServers []string            // database servers that answered
	access    map[string][]string // new access log lines, by site
	mailLog   []string
	authLog   []string
}

// proc is a process as read from /proc.
type proc struct {
	PID   int
	UID   int
	Cmd   string // command line, arguments separated by spaces
	Unit  string // systemd unit or scope it runs in, e.g. nginx.service
	CPU   uint64 // user + system time, clock ticks
	Start uint64 // start time, clock ticks after boot
	Mem   uint64 // resident anonymous memory (heap, stacks), bytes
	IO    uint64 // bytes read from and written to storage
}

// poolStatus is a PHP-FPM pool's status page.
type poolStatus struct {
	Active     int `json:"active processes"`
	Idle       int `json:"idle processes"`
	Total      int `json:"total processes"`
	Queue      int `json:"listen queue"`
	MaxReached int `json:"max children reached"`
	Accepted   int `json:"accepted conn"`
	SlowReqs   int `json:"slow requests"`
}

// dbConn is one connection to a database server.
type dbConn struct {
	Server  string // mysql or postgresql
	User    string
	DB      string
	Active  bool // running a query
	Seconds int  // how long the current query has run
}

// sampler takes samples; the real machine's or the dry run's.
type sampler interface {
	sample(ctx context.Context, sites []siteInfo) raw
}

// systemSampler reads /proc, the PHP-FPM status sockets, the logs and the
// database servers' process lists.
type systemSampler struct {
	h     host.Host
	fpm   func(ctx context.Context, socket string) (poolStatus, error)
	tails map[string]*tail
}

func newSystemSampler(h host.Host) *systemSampler {
	return &systemSampler{h: h, fpm: func(ctx context.Context, socket string) (poolStatus, error) {
		return fetchPoolStatus(ctx, h.Path(socket))
	}, tails: map[string]*tail{}}
}

const (
	mailLog = "/var/log/mail.log"
	authLog = "/var/log/auth.log"
)

func (s *systemSampler) sample(ctx context.Context, sites []siteInfo) raw {
	r := raw{pools: map[string]poolStatus{}, access: map[string][]string{}}
	s.readStat(&r)
	r.procs = readProcs(s.h)

	var wg sync.WaitGroup
	var mu sync.Mutex
	sem := make(chan struct{}, 16)
	for _, si := range sites {
		if si.StatusSocket == "" {
			continue
		}
		wg.Add(1)
		go func(si siteInfo) {
			defer wg.Done()
			sem <- struct{}{}
			defer func() { <-sem }()
			cctx, cancel := context.WithTimeout(ctx, 700*time.Millisecond)
			defer cancel()
			st, err := s.fpm(cctx, si.StatusSocket)
			if err != nil {
				return
			}
			mu.Lock()
			r.pools[si.Domain] = st
			mu.Unlock()
		}(si)
	}

	seen := map[string]bool{}
	for _, si := range sites {
		file := site.AccessLog(si.Domain)
		seen[file] = true
		r.access[si.Domain] = s.tail(file)
	}
	for _, f := range []string{mailLog, authLog} {
		seen[f] = true
	}
	r.mailLog = s.tail(mailLog)
	r.authLog = s.tail(authLog)
	for f := range s.tails {
		if !seen[f] {
			delete(s.tails, f)
		}
	}
	r.db, r.dbServers = readDatabases(ctx, s.h)
	wg.Wait()
	return r
}

func (s *systemSampler) tail(file string) []string {
	t := s.tails[file]
	if t == nil {
		t = &tail{file: s.h.Path(file)}
		s.tails[file] = t
	}
	return t.read()
}

func (s *systemSampler) readStat(r *raw) {
	if b, err := os.ReadFile(s.h.Path("/proc/uptime")); err == nil {
		f := strings.Fields(string(b))
		if len(f) > 0 {
			r.uptime, _ = strconv.ParseFloat(f[0], 64)
		}
	}
	if b, err := os.ReadFile(s.h.Path("/proc/stat")); err == nil {
		for _, line := range strings.Split(string(b), "\n") {
			f := strings.Fields(line)
			if len(f) < 5 {
				continue
			}
			if f[0] == "cpu" {
				for i, v := range f[1:] {
					n, _ := strconv.ParseUint(v, 10, 64)
					r.cpuTotal += n
					// idle and iowait
					if i != 3 && i != 4 {
						r.cpuBusy += n
					}
				}
			} else if strings.HasPrefix(f[0], "cpu") {
				r.cpus++
			}
		}
	}
	if b, err := os.ReadFile(s.h.Path("/proc/meminfo")); err == nil {
		for _, line := range strings.Split(string(b), "\n") {
			f := strings.Fields(line)
			if len(f) < 2 {
				continue
			}
			n, _ := strconv.ParseUint(f[1], 10, 64)
			switch f[0] {
			case "MemTotal:":
				r.memTotal = n * 1024
			case "MemAvailable:":
				r.memAvail = n * 1024
			}
		}
	}
	r.netRx, r.netTx = readNetDev(s.h.Path("/proc/net/dev"))
	if b, err := os.ReadFile(s.h.Path("/proc/loadavg")); err == nil {
		f := strings.Fields(string(b))
		for i := 0; i < 3 && i < len(f); i++ {
			r.load[i], _ = strconv.ParseFloat(f[i], 64)
		}
	}
}

// readNetDev adds up the bytes every network interface but loopback
// received and sent.
func readNetDev(file string) (rx, tx uint64) {
	b, err := os.ReadFile(file)
	if err != nil {
		return 0, 0
	}
	for _, line := range strings.Split(string(b), "\n") {
		name, rest, ok := strings.Cut(line, ":")
		if !ok || strings.TrimSpace(name) == "lo" {
			continue
		}
		f := strings.Fields(rest)
		if len(f) < 9 {
			continue
		}
		r, err1 := strconv.ParseUint(f[0], 10, 64)
		t, err2 := strconv.ParseUint(f[8], 10, 64)
		if err1 == nil && err2 == nil {
			rx += r
			tx += t
		}
	}
	return rx, tx
}

// readProcs reads every user-space process. Kernel threads (no command
// line) are left out.
func readProcs(h host.Host) []proc {
	dir := h.Path("/proc")
	entries, err := os.ReadDir(dir)
	if err != nil {
		return nil
	}
	procs := make([]proc, 0, len(entries))
	for _, e := range entries {
		pid, err := strconv.Atoi(e.Name())
		if err != nil || pid <= 0 {
			continue
		}
		p, ok := readProc(dir+"/"+e.Name(), pid)
		if ok {
			procs = append(procs, p)
		}
	}
	return procs
}

func readProc(dir string, pid int) (proc, bool) {
	cmdline, err := os.ReadFile(dir + "/cmdline")
	if err != nil || len(cmdline) == 0 {
		return proc{}, false
	}
	stat, err := os.ReadFile(dir + "/stat")
	if err != nil {
		return proc{}, false
	}
	p := proc{PID: pid, Cmd: strings.TrimSpace(string(bytes.ReplaceAll(cmdline, []byte{0}, []byte{' '})))}
	if len(p.Cmd) > 200 {
		p.Cmd = p.Cmd[:200]
	}
	// The command name may contain spaces and parentheses; the fields
	// after the last ")" are fixed.
	i := bytes.LastIndexByte(stat, ')')
	if i < 0 {
		return proc{}, false
	}
	f := strings.Fields(string(stat[i+1:]))
	// f[0] is field 3 (state); utime is field 14, stime 15, starttime 22.
	if len(f) < 20 {
		return proc{}, false
	}
	ut, _ := strconv.ParseUint(f[11], 10, 64)
	st, _ := strconv.ParseUint(f[12], 10, 64)
	p.CPU = ut + st
	p.Start, _ = strconv.ParseUint(f[19], 10, 64)

	if b, err := os.ReadFile(dir + "/status"); err == nil {
		sc := bufio.NewScanner(bytes.NewReader(b))
		for sc.Scan() {
			line := sc.Text()
			switch {
			case strings.HasPrefix(line, "Uid:"):
				if fs := strings.Fields(line); len(fs) > 1 {
					p.UID, _ = strconv.Atoi(fs[1])
				}
			case strings.HasPrefix(line, "RssAnon:"):
				if fs := strings.Fields(line); len(fs) > 1 {
					kb, _ := strconv.ParseUint(fs[1], 10, 64)
					p.Mem = kb * 1024
				}
			}
		}
	}
	if b, err := os.ReadFile(dir + "/io"); err == nil {
		for _, line := range strings.Split(string(b), "\n") {
			k, v, ok := strings.Cut(line, ": ")
			if !ok || (k != "read_bytes" && k != "write_bytes") {
				continue
			}
			n, _ := strconv.ParseUint(strings.TrimSpace(v), 10, 64)
			p.IO += n
		}
	}
	if b, err := os.ReadFile(dir + "/cgroup"); err == nil {
		p.Unit = unitOf(string(b))
	}
	return p, true
}

// unitOf returns the systemd unit in a /proc/<pid>/cgroup file: the last
// path element of the unified hierarchy that names a service or scope.
func unitOf(cgroup string) string {
	var unified, v1 string
	for _, line := range strings.Split(cgroup, "\n") {
		if rest, ok := strings.CutPrefix(line, "0::"); ok {
			unified = rest
		} else if _, rest, ok := strings.Cut(line, ":name=systemd:"); ok {
			// cgroup v1: the systemd named hierarchy.
			v1 = rest
		}
	}
	if u := lastUnit(unified); u != "" {
		return u
	}
	return lastUnit(v1)
}

func lastUnit(path string) string {
	parts := strings.Split(path, "/")
	for i := len(parts) - 1; i >= 0; i-- {
		if strings.HasSuffix(parts[i], ".service") || strings.HasSuffix(parts[i], ".scope") {
			return parts[i]
		}
	}
	return ""
}

// tail returns the lines appended to a log file since the last read. The
// first read starts at the end: only new events count. A rotated or
// truncated file is read from its start.
type tail struct {
	file   string
	ino    uint64
	offset int64
	opened bool
	// missing is set when the file did not exist: once it appears, it is
	// new and read from its start.
	missing bool
	part    []byte
}

// maxRead bounds the work per sample when a log grows very fast.
const maxRead = 8 << 20

func (t *tail) read() []string {
	f, err := os.Open(t.file)
	if err != nil {
		t.opened, t.missing = false, true
		return nil
	}
	defer f.Close()
	fi, err := f.Stat()
	if err != nil {
		return nil
	}
	ino := inode(fi)
	size := fi.Size()
	if !t.opened {
		t.opened, t.ino, t.offset, t.part = true, ino, size, nil
		if !t.missing {
			return nil
		}
		t.offset = 0
	}
	if ino != t.ino || size < t.offset {
		t.ino, t.offset, t.part = ino, 0, nil
	}
	if size == t.offset {
		return nil
	}
	if size-t.offset > maxRead {
		// Too far behind: skip to the last maxRead bytes.
		t.offset, t.part = size-maxRead, nil
	}
	buf := make([]byte, size-t.offset)
	n, _ := f.ReadAt(buf, t.offset)
	buf = buf[:n]
	t.offset += int64(n)
	data := append(t.part, buf...)
	last := bytes.LastIndexByte(data, '\n')
	if last < 0 {
		t.part = data
		return nil
	}
	t.part = append([]byte(nil), data[last+1:]...)
	return strings.Split(string(data[:last]), "\n")
}
