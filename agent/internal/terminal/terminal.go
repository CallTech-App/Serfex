// Package terminal runs SSH terminali: a site user's shell in the
// panel, in the browser.
//
// The panel asks for a session over RPC (terminal.open) and gets a ticket:
// a random token that works once, within TicketTTL, from the address the
// panel was opened from. The browser then opens a WebSocket to
// serfex-web's /terminal/socket, which Nginx passes to this package's own
// Unix socket (Socket). The token picks the session, and the agent starts
// the user's login shell on a new pseudo-terminal, as that user, in the
// site's folder. Nothing runs as root and nothing goes through sshd.
//
// Only an account with a shell (SSH erişimi "SFTP ve kabuk", group
// serfex-shell) gets one. A session ends when the browser leaves, after
// the idle limit without input, after the time limit, when the panel
// closes it or when the shell exits. What is typed is not recorded; the
// opening and the end are logged (journal) and kept for the panel.
package terminal

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net"
	"net/http"
	"net/netip"
	"os"
	"os/exec"
	"os/user"
	"path/filepath"
	"regexp"
	"slices"
	"sort"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"

	"github.com/calltech-app/serfex/agent/internal/host"
)

const (
	// Socket is where serfex-web's /terminal/socket is passed to.
	Socket = "/run/serfex-agent/terminal.sock"
	// Path is the address the browser opens on the panel's port.
	Path = "/terminal/socket"

	// TicketTTL is how long a ticket waits for the browser.
	TicketTTL = 60 * time.Second
	// DefaultIdle and DefaultMax are the limits when the panel sends none.
	DefaultIdle = 15 * time.Minute
	DefaultMax  = 2 * time.Hour
	// DefaultSessions bounds the sessions open at once on the server.
	DefaultSessions = 5
	// keepEnded is how many ended sessions List still shows.
	keepEnded = 50

	// groupShell is the group of site users with a shell (package ftp).
	groupShell = "serfex-shell"
)

// ErrLimit refuses a session while MaxSessions are open.
var ErrLimit = errors.New("too many terminal sessions are open")

// ValidationError reports a request that can never succeed as given.
type ValidationError struct{ msg string }

func (e *ValidationError) Error() string { return e.msg }

func invalid(format string, a ...any) error { return &ValidationError{fmt.Sprintf(format, a...)} }

// OperationError reports a valid request that failed on the server.
type OperationError struct{ Err error }

func (e *OperationError) Error() string { return e.Err.Error() }
func (e *OperationError) Unwrap() error { return e.Err }

// OpenSpec asks for a session.
type OpenSpec struct {
	// User is the site's system account; Dir the folder the shell starts
	// in (the site's), inside the user's home.
	User string `json:"user"`
	Dir  string `json:"dir"`
	// Site, Actor and IP describe the session for the logs and List:
	// the site's domain, who opened it and from where. A ticket only
	// works from IP when one is given.
	Site  string `json:"site"`
	Actor string `json:"actor"`
	IP    string `json:"ip"`
	// IdleMinutes and MaxMinutes override DefaultIdle and DefaultMax.
	IdleMinutes int `json:"idle_minutes"`
	MaxMinutes  int `json:"max_minutes"`
}

// Ticket lets the browser connect once.
type Ticket struct {
	ID      string    `json:"id"`
	Token   string    `json:"token"`
	Path    string    `json:"path"`
	Expires time.Time `json:"expires"`
	Idle    int       `json:"idle_seconds"`
	Max     int       `json:"max_seconds"`
}

// Session is a session as List shows it.
type Session struct {
	ID      string     `json:"id"`
	User    string     `json:"user"`
	Site    string     `json:"site"`
	Actor   string     `json:"actor"`
	IP      string     `json:"ip"`
	State   string     `json:"state"` // waiting, open, ended
	Created time.Time  `json:"created"`
	Started *time.Time `json:"started,omitempty"`
	Ended   *time.Time `json:"ended,omitempty"`
	// Reason says why it ended: left, idle, limit, closed, exited,
	// expired, failed, stopped.
	Reason string `json:"reason,omitempty"`
}

type session struct {
	Session
	token     string
	dir       string
	idle, max time.Duration
	stop      chan string // a reason, from Close
	once      sync.Once
}

// Manager keeps the sessions. The zero value is not usable; use
// NewManager.
type Manager struct {
	h host.Host
	// Demo runs the agent's own user's shell instead (dry-run agent), in
	// the simulated home, so screenshots and tests have a real terminal.
	Demo bool
	Log  *slog.Logger
	// MaxSessions bounds the sessions waiting or open at once.
	MaxSessions int
	now         func() time.Time

	mu       sync.Mutex
	sessions map[string]*session
	wg       sync.WaitGroup
	// socket and group are Listen's, for ownSocket.
	socket, group string
	owned         bool
}

func NewManager(h host.Host) *Manager {
	return &Manager{h: h, MaxSessions: DefaultSessions, now: time.Now, sessions: map[string]*session{}, Log: slog.New(slog.NewTextHandler(io.Discard, nil))}
}

var userName = regexp.MustCompile(`^[a-z_][a-z0-9_-]{0,31}$`)

func randomHex(n int) string {
	b := make([]byte, n)
	if _, err := rand.Read(b); err != nil {
		panic(err)
	}
	return hex.EncodeToString(b)
}

func minutes(n int, def time.Duration, lo, hi int) (time.Duration, error) {
	if n == 0 {
		return def, nil
	}
	if n < lo || n > hi {
		return 0, invalid("limit must be between %d and %d minutes", lo, hi)
	}
	return time.Duration(n) * time.Minute, nil
}

// shellAccount checks that name is a site user with a shell and returns
// it.
func (m *Manager) shellAccount(name string) (host.User, error) {
	if !userName.MatchString(name) {
		return host.User{}, invalid("invalid user name %q", name)
	}
	u, err := m.h.LookupUser(name)
	if err != nil {
		return host.User{}, invalid("user %s does not exist", name)
	}
	if u.UID < 1000 || u.UID == 65534 {
		return host.User{}, invalid("user %s is not a site user", name)
	}
	if !m.inGroup(name, groupShell) {
		return host.User{}, invalid("user %s has no shell access (SSH access is not \"SFTP and shell\")", name)
	}
	if base := filepath.Base(u.Shell); u.Shell == "" || base == "nologin" || base == "false" {
		return host.User{}, invalid("user %s has no shell access", name)
	}
	return u, nil
}

// inGroup reads /etc/group: the account database the host sees.
func (m *Manager) inGroup(name, group string) bool {
	data, err := os.ReadFile(m.h.Path("/etc/group"))
	if err != nil {
		return false
	}
	for _, line := range strings.Split(string(data), "\n") {
		f := strings.Split(line, ":")
		if len(f) == 4 && f[0] == group {
			return slices.Contains(strings.Split(f[3], ","), name)
		}
	}
	return false
}

// ownSocketLocked gives the web server's group the socket. That account
// may only be made after the agent starts (panel.setup on a new server),
// so every Open tries again until it worked.
func (m *Manager) ownSocketLocked() {
	if m.owned || m.socket == "" || m.group == "" {
		return
	}
	g, err := user.LookupGroup(m.group)
	if err != nil {
		return
	}
	gid, _ := strconv.Atoi(g.Gid)
	if err := os.Chown(m.socket, 0, gid); err != nil {
		m.Log.Error("terminal socket", "err", err)
		return
	}
	m.owned = true
}

// Open checks the account and returns a ticket for a new session.
func (m *Manager) Open(_ context.Context, spec OpenSpec) (Ticket, error) {
	u, err := m.shellAccount(spec.User)
	if err != nil {
		return Ticket{}, err
	}
	dir := filepath.Clean("/" + spec.Dir)
	if spec.Dir == "" {
		dir = u.Home
	}
	if dir != u.Home && !strings.HasPrefix(dir, strings.TrimSuffix(u.Home, "/")+"/") {
		return Ticket{}, invalid("folder %s is outside the home of %s", dir, u.Name)
	}
	if spec.IP != "" {
		if _, err := netip.ParseAddr(spec.IP); err != nil {
			return Ticket{}, invalid("invalid IP address %q", spec.IP)
		}
	}
	idle, err := minutes(spec.IdleMinutes, DefaultIdle, 1, 240)
	if err != nil {
		return Ticket{}, err
	}
	max, err := minutes(spec.MaxMinutes, DefaultMax, 5, 720)
	if err != nil {
		return Ticket{}, err
	}

	m.mu.Lock()
	defer m.mu.Unlock()
	m.ownSocketLocked()
	m.sweepLocked()
	active := 0
	for _, s := range m.sessions {
		if s.State != "ended" {
			active++
		}
	}
	if m.MaxSessions > 0 && active >= m.MaxSessions {
		return Ticket{}, ErrLimit
	}
	now := m.now()
	s := &session{
		Session: Session{ID: randomHex(8), User: u.Name, Site: limit(spec.Site, 253), Actor: limit(spec.Actor, 120), IP: spec.IP, State: "waiting", Created: now},
		token:   randomHex(32),
		dir:     dir,
		idle:    idle,
		max:     max,
		stop:    make(chan string, 1),
	}
	m.sessions[s.ID] = s
	return Ticket{ID: s.ID, Token: s.token, Path: Path, Expires: now.Add(TicketTTL), Idle: int(idle / time.Second), Max: int(max / time.Second)}, nil
}

func limit(s string, n int) string {
	if len(s) > n {
		return s[:n]
	}
	return s
}

// sweepLocked ends tickets the browser never used and forgets the oldest
// ended sessions.
func (m *Manager) sweepLocked() {
	now := m.now()
	var ended []*session
	for _, s := range m.sessions {
		if s.State == "waiting" && now.Sub(s.Created) > TicketTTL {
			m.endLocked(s, "expired")
		}
		if s.State == "ended" {
			ended = append(ended, s)
		}
	}
	if len(ended) > keepEnded {
		sort.Slice(ended, func(i, j int) bool { return ended[i].Ended.Before(*ended[j].Ended) })
		for _, s := range ended[:len(ended)-keepEnded] {
			delete(m.sessions, s.ID)
		}
	}
}

func (m *Manager) endLocked(s *session, reason string) {
	if s.State == "ended" {
		return
	}
	now := m.now()
	s.State, s.Ended, s.Reason, s.token = "ended", &now, reason, ""
}

// List returns the sessions, newest first.
func (m *Manager) List(context.Context) []Session {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.sweepLocked()
	out := make([]Session, 0, len(m.sessions))
	for _, s := range m.sessions {
		out = append(out, s.Session)
	}
	sort.Slice(out, func(i, j int) bool { return out[i].Created.After(out[j].Created) })
	return out
}

// Get returns one session.
func (m *Manager) Get(_ context.Context, id string) (Session, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.sweepLocked()
	s, ok := m.sessions[id]
	if !ok {
		return Session{}, invalid("no terminal session %q", id)
	}
	return s.Session, nil
}

// Close ends a session; an open shell gets SIGHUP. Closing an ended or
// unknown session is not an error.
func (m *Manager) Close(_ context.Context, id string) (Session, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	s, ok := m.sessions[id]
	if !ok {
		return Session{}, nil
	}
	switch s.State {
	case "waiting":
		m.endLocked(s, "closed")
	case "open":
		select {
		case s.stop <- "closed":
		default:
		}
	}
	return s.Session, nil
}

// CloseUser ends every session of an account, for example when its shell
// access is taken away or the site is deleted.
func (m *Manager) CloseUser(ctx context.Context, name string) {
	m.mu.Lock()
	var ids []string
	for _, s := range m.sessions {
		if s.User == name && s.State != "ended" {
			ids = append(ids, s.ID)
		}
	}
	m.mu.Unlock()
	for _, id := range ids {
		_, _ = m.Close(ctx, id)
	}
}

// Listen serves the browser's connections on socket until ctx ends;
// group (the web server's) may connect.
func (m *Manager) Listen(ctx context.Context, socket, group string) error {
	if err := os.MkdirAll(filepath.Dir(socket), 0o755); err != nil {
		return err
	}
	if err := os.Remove(socket); err != nil && !errors.Is(err, os.ErrNotExist) {
		return err
	}
	ln, err := net.Listen("unix", socket)
	if err != nil {
		return err
	}
	if err := os.Chmod(socket, 0o660); err != nil {
		ln.Close()
		return err
	}
	m.mu.Lock()
	m.socket, m.group = socket, group
	m.ownSocketLocked()
	m.mu.Unlock()
	srv := &http.Server{Handler: m, ReadHeaderTimeout: 10 * time.Second}
	go func() {
		<-ctx.Done()
		srv.Close()
		m.stopAll("stopped")
	}()
	err = srv.Serve(ln)
	if errors.Is(err, http.ErrServerClosed) {
		err = nil
	}
	m.wg.Wait()
	return err
}

func (m *Manager) stopAll(reason string) {
	m.mu.Lock()
	defer m.mu.Unlock()
	for _, s := range m.sessions {
		switch s.State {
		case "waiting":
			m.endLocked(s, reason)
		case "open":
			select {
			case s.stop <- reason:
			default:
			}
		}
	}
}

// claim takes the session a token belongs to, once.
func (m *Manager) claim(token, ip string) (*session, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.sweepLocked()
	if len(token) != 64 {
		return nil, errors.New("invalid token")
	}
	for _, s := range m.sessions {
		if s.State != "waiting" || s.token == "" || !constantEqual(s.token, token) {
			continue
		}
		if s.IP != "" && ip != "" && !sameIP(s.IP, ip) {
			m.endLocked(s, "failed")
			return nil, errors.New("ticket used from another address")
		}
		now := m.now()
		s.State, s.Started, s.token = "open", &now, ""
		return s, nil
	}
	return nil, errors.New("unknown or used ticket")
}

func constantEqual(a, b string) bool {
	if len(a) != len(b) {
		return false
	}
	var v byte
	for i := 0; i < len(a); i++ {
		v |= a[i] ^ b[i]
	}
	return v == 0
}

func sameIP(a, b string) bool {
	x, err1 := netip.ParseAddr(a)
	y, err2 := netip.ParseAddr(b)
	return err1 == nil && err2 == nil && x.Unmap() == y.Unmap()
}

// control is a text message from the browser.
type control struct {
	Type string `json:"t"`
	Cols int    `json:"cols"`
	Rows int    `json:"rows"`
}

// ServeHTTP takes the browser's WebSocket.
func (m *Manager) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	if r.URL.Path != Path {
		http.NotFound(w, r)
		return
	}
	if !sameOrigin(r) {
		http.Error(w, "forbidden", http.StatusForbidden)
		return
	}
	if !isUpgrade(r) {
		http.Error(w, "websocket expected", http.StatusBadRequest)
		return
	}
	s, err := m.claim(r.URL.Query().Get("token"), r.Header.Get("X-Real-IP"))
	if err != nil {
		m.Log.Warn("terminal refused", "err", err, "ip", r.Header.Get("X-Real-IP"))
		http.Error(w, "forbidden", http.StatusForbidden)
		return
	}
	cols, _ := strconv.Atoi(r.URL.Query().Get("cols"))
	rows, _ := strconv.Atoi(r.URL.Query().Get("rows"))
	conn, err := upgrade(w, r)
	if err != nil {
		m.finish(s, "failed")
		return
	}
	m.wg.Add(1)
	defer m.wg.Done()
	m.run(s, conn, clampSize(cols, 80, 20, 500), clampSize(rows, 24, 5, 200))
}

func clampSize(n, def, lo, hi int) int {
	if n == 0 {
		return def
	}
	return min(max(n, lo), hi)
}

func (m *Manager) finish(s *session, reason string) {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.endLocked(s, reason)
}

func sendJSON(c *wsConn, v any) {
	b, _ := json.Marshal(v)
	_ = c.write(opText, b)
}

// run bridges a WebSocket and a new shell until one of them ends.
func (m *Manager) run(s *session, conn *wsConn, cols, rows int) {
	reason := "failed"
	defer func() {
		m.finish(s, reason)
		var dur time.Duration
		if s.Started != nil {
			dur = m.now().Sub(*s.Started).Round(time.Second)
		}
		m.Log.Info("terminal closed", "id", s.ID, "user", s.User, "site", s.Site, "actor", s.Actor, "ip", s.IP, "reason", reason, "duration", dur.String())
	}()

	master, cmd, err := m.start(s, cols, rows)
	if err != nil {
		m.Log.Error("terminal shell did not start", "id", s.ID, "user", s.User, "err", err)
		sendJSON(conn, map[string]string{"t": "exit", "reason": "failed"})
		conn.close(1011, "shell did not start")
		return
	}
	m.Log.Info("terminal opened", "id", s.ID, "user", s.User, "site", s.Site, "actor", s.Actor, "ip", s.IP)
	sendJSON(conn, map[string]any{"t": "ready", "id": s.ID, "user": s.User, "idle": int(s.idle / time.Second), "max": int(s.max / time.Second)})

	done := make(chan string, 3)
	var lastInput syncTime
	lastInput.set(m.now())

	// Shell → browser.
	go func() {
		buf := make([]byte, 32<<10)
		for {
			n, err := master.Read(buf)
			if n > 0 {
				if werr := conn.write(opBinary, buf[:n]); werr != nil {
					done <- "left"
					return
				}
			}
			if err != nil {
				// EIO: every process holding the terminal is gone.
				done <- "exited"
				return
			}
		}
	}()
	// Browser → shell.
	go func() {
		for {
			op, data, err := conn.readMessage()
			if err != nil {
				done <- "left"
				return
			}
			if op == opBinary {
				lastInput.set(m.now())
				if _, err := master.Write(data); err != nil {
					done <- "exited"
					return
				}
				continue
			}
			var c control
			if json.Unmarshal(data, &c) != nil {
				continue
			}
			switch c.Type {
			case "resize":
				if c.Cols > 0 && c.Rows > 0 {
					_ = resize(master, clampSize(c.Cols, 80, 20, 500), clampSize(c.Rows, 24, 5, 200))
				}
			case "ping":
				lastInput.set(m.now())
			}
		}
	}()

	tick := time.NewTicker(time.Second)
	defer tick.Stop()
	pings := 0
	warned := false
loop:
	for {
		select {
		case reason = <-done:
			break loop
		case reason = <-s.stop:
			break loop
		case <-tick.C:
			now := m.now()
			if now.Sub(*s.Started) >= s.max {
				reason = "limit"
				break loop
			}
			idleFor := now.Sub(lastInput.get())
			if idleFor >= s.idle {
				reason = "idle"
				break loop
			}
			// One minute before the idle limit the page says so.
			if left := s.idle - idleFor; left <= time.Minute && !warned {
				warned = true
				sendJSON(conn, map[string]any{"t": "idle", "seconds": int(left / time.Second)})
			} else if left > time.Minute {
				warned = false
			}
			// Keeps Nginx and proxies from dropping a quiet connection.
			if pings++; pings%25 == 0 {
				_ = conn.write(opPing, nil)
			}
		}
	}
	m.Log.Debug("terminal ending", "id", s.ID, "reason", reason)
	// Ended before the browser hears of it, so the panel's look at the
	// session (terminal.get) already finds the reason.
	m.finish(s, reason)
	if reason != "left" {
		sendJSON(conn, map[string]string{"t": "exit", "reason": reason})
	}
	conn.close(1000, reason)
	stopShell(cmd)
	master.Close()
}

// stopShell hangs up the shell's session like a closed SSH connection,
// then kills what is left of it.
func stopShell(cmd *exec.Cmd) {
	if cmd.Process == nil {
		return
	}
	pgid := cmd.Process.Pid
	_ = syscall.Kill(-pgid, syscall.SIGHUP)
	_ = syscall.Kill(-pgid, syscall.SIGCONT)
	exited := make(chan struct{})
	go func() {
		_ = cmd.Wait()
		close(exited)
	}()
	select {
	case <-exited:
	case <-time.After(3 * time.Second):
		_ = syscall.Kill(-pgid, syscall.SIGKILL)
		<-exited
	}
}

type syncTime struct {
	mu sync.Mutex
	t  time.Time
}

func (s *syncTime) set(t time.Time) { s.mu.Lock(); s.t = t; s.mu.Unlock() }
func (s *syncTime) get() time.Time  { s.mu.Lock(); defer s.mu.Unlock(); return s.t }

// start runs the login shell on a new pseudo-terminal.
func (m *Manager) start(s *session, cols, rows int) (*os.File, *exec.Cmd, error) {
	u, err := m.shellAccount(s.User)
	if err != nil {
		return nil, nil, err
	}
	master, slave, err := openPTY()
	if err != nil {
		return nil, nil, &OperationError{err}
	}
	defer slave.Close()
	_ = resize(master, cols, rows)

	env := []string{"TERM=xterm-256color", "COLORTERM=truecolor", "LANG=C.UTF-8", "SERFEX_TERMINAL=1"}
	var cmd *exec.Cmd
	if m.Demo {
		// Dry run: the agent's own account plays the site user.
		home := m.h.Path(u.Home)
		dir := m.h.Path(s.dir)
		if st, err := os.Stat(dir); err != nil || !st.IsDir() {
			dir = home
		}
		if st, err := os.Stat(dir); err != nil || !st.IsDir() {
			dir = os.TempDir()
		}
		cmd = exec.Command("/bin/bash", "--noprofile", "--norc", "-i")
		cmd.Dir = dir
		cmd.Env = append(env, "HOME="+home, "USER="+u.Name, "LOGNAME="+u.Name, "SHELL=/bin/bash",
			"PATH=/usr/local/bin:/usr/bin:/bin", `PS1=\[\e[32m\]`+u.Name+`@serfex\[\e[0m\]:\[\e[34m\]\w\[\e[0m\]$ `)
		cmd.SysProcAttr = &syscall.SysProcAttr{Setsid: true, Setctty: true, Ctty: 0}
	} else {
		groups, err := supplementaryGroups(u.Name)
		if err != nil {
			master.Close()
			return nil, nil, &OperationError{err}
		}
		ttyGID := u.GID
		if g, err := user.LookupGroup("tty"); err == nil {
			ttyGID, _ = strconv.Atoi(g.Gid)
		}
		// The terminal belongs to the user, as sshd does it, so tty,
		// mesg and screen work.
		if err := slave.Chown(u.UID, ttyGID); err != nil {
			master.Close()
			return nil, nil, &OperationError{err}
		}
		_ = slave.Chmod(0o620)
		dir := s.dir
		if st, err := os.Stat(dir); err != nil || !st.IsDir() {
			dir = u.Home
		}
		// argv[0] with a dash makes a login shell: /etc/profile and the
		// user's ~/.profile run, as over SSH.
		cmd = exec.Command(u.Shell)
		cmd.Args = []string{"-" + filepath.Base(u.Shell)}
		cmd.Dir = dir
		cmd.Env = append(env, "HOME="+u.Home, "USER="+u.Name, "LOGNAME="+u.Name, "SHELL="+u.Shell,
			"PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/usr/games:/usr/local/games")
		cmd.SysProcAttr = &syscall.SysProcAttr{
			Setsid: true, Setctty: true, Ctty: 0,
			Credential: &syscall.Credential{Uid: uint32(u.UID), Gid: uint32(u.GID), Groups: groups},
		}
	}
	cmd.Stdin, cmd.Stdout, cmd.Stderr = slave, slave, slave
	if err := cmd.Start(); err != nil {
		master.Close()
		return nil, nil, &OperationError{err}
	}
	return master, cmd, nil
}

func supplementaryGroups(name string) ([]uint32, error) {
	u, err := user.Lookup(name)
	if err != nil {
		return nil, err
	}
	ids, err := u.GroupIds()
	if err != nil {
		return nil, err
	}
	out := make([]uint32, 0, len(ids))
	for _, id := range ids {
		n, err := strconv.ParseUint(id, 10, 32)
		if err == nil {
			out = append(out, uint32(n))
		}
	}
	return out, nil
}
