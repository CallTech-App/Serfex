package terminal

import (
	"bufio"
	"bytes"
	"context"
	"crypto/rand"
	"encoding/binary"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/calltech-app/serfex/agent/internal/host"
)

// newDemo returns a dry-run manager serving on a socket, with site user
// u1 (shell) and u2 (SFTP only).
func newDemo(t *testing.T) (*Manager, string) {
	t.Helper()
	root := t.TempDir()
	sim, err := host.NewSimulator(root)
	if err != nil {
		t.Fatal(err)
	}
	add := func(file, line string) {
		f, err := os.OpenFile(sim.Path(file), os.O_APPEND|os.O_WRONLY, 0)
		if err != nil {
			t.Fatal(err)
		}
		fmt.Fprintln(f, line)
		f.Close()
	}
	add("/etc/passwd", "u1:x:1001:1001::/home/u1:/bin/bash")
	add("/etc/passwd", "u2:x:1002:1002::/home/u2:/usr/sbin/nologin")
	add("/etc/group", "serfex-shell:x:990:u1")
	add("/etc/group", "serfex-sftp:x:991:u2")
	if err := os.MkdirAll(sim.Path("/home/u1/example.com"), 0o755); err != nil {
		t.Fatal(err)
	}
	m := NewManager(sim)
	m.Demo = true
	sock := filepath.Join(t.TempDir(), "terminal.sock")
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan error, 1)
	go func() { done <- m.Listen(ctx, sock, "") }()
	t.Cleanup(func() {
		cancel()
		select {
		case <-done:
		case <-time.After(10 * time.Second):
			t.Error("Listen did not stop")
		}
	})
	for i := 0; i < 100; i++ {
		if _, err := os.Stat(sock); err == nil {
			break
		}
		time.Sleep(10 * time.Millisecond)
	}
	return m, sock
}

// client is a browser's side of the WebSocket.
type client struct {
	conn net.Conn
	r    *bufio.Reader
	// out is the output not matched by until yet.
	out bytes.Buffer
}

func dial(t *testing.T, sock, token string, header http.Header) (*client, int) {
	t.Helper()
	conn, err := net.Dial("unix", sock)
	if err != nil {
		t.Fatal(err)
	}
	req, _ := http.NewRequest("GET", "http://panel.test:8443"+Path+"?token="+token+"&cols=100&rows=30", nil)
	req.Header.Set("Connection", "Upgrade")
	req.Header.Set("Upgrade", "websocket")
	req.Header.Set("Sec-WebSocket-Version", "13")
	req.Header.Set("Sec-WebSocket-Key", "dGhlIHNhbXBsZSBub25jZQ==")
	for k, v := range header {
		req.Header[k] = v
	}
	if err := req.Write(conn); err != nil {
		t.Fatal(err)
	}
	r := bufio.NewReader(conn)
	resp, err := http.ReadResponse(r, req)
	if err != nil {
		t.Fatal(err)
	}
	if resp.StatusCode != http.StatusSwitchingProtocols {
		conn.Close()
		return nil, resp.StatusCode
	}
	if got := resp.Header.Get("Sec-WebSocket-Accept"); got != "s3pPLMBiTxaQ9kYGzzhZRbK+xOo=" {
		t.Fatalf("accept key %q", got)
	}
	c := &client{conn: conn, r: r}
	t.Cleanup(func() { conn.Close() })
	return c, resp.StatusCode
}

func (c *client) send(op byte, data []byte) error {
	var mask [4]byte
	_, _ = rand.Read(mask[:])
	head := []byte{0x80 | op}
	switch n := len(data); {
	case n <= 125:
		head = append(head, 0x80|byte(n))
	case n <= 0xFFFF:
		head = append(head, 0x80|126)
		head = binary.BigEndian.AppendUint16(head, uint16(n))
	default:
		head = append(head, 0x80|127)
		head = binary.BigEndian.AppendUint64(head, uint64(n))
	}
	head = append(head, mask[:]...)
	body := bytes.Clone(data)
	for i := range body {
		body[i] ^= mask[i%4]
	}
	_, err := c.conn.Write(append(head, body...))
	return err
}

func (c *client) read() (byte, []byte, error) {
	var head [2]byte
	if _, err := io.ReadFull(c.r, head[:]); err != nil {
		return 0, nil, err
	}
	n := uint64(head[1] & 0x7F)
	switch n {
	case 126:
		var ext [2]byte
		io.ReadFull(c.r, ext[:])
		n = uint64(binary.BigEndian.Uint16(ext[:]))
	case 127:
		var ext [8]byte
		io.ReadFull(c.r, ext[:])
		n = binary.BigEndian.Uint64(ext[:])
	}
	data := make([]byte, n)
	_, err := io.ReadFull(c.r, data)
	return head[0] & 0x0F, data, err
}

// until reads until the output holds want, returning the text messages.
func (c *client) until(t *testing.T, want string) []string {
	t.Helper()
	_ = c.conn.SetReadDeadline(time.Now().Add(10 * time.Second))
	out := &c.out
	var texts []string
	for {
		if i := strings.Index(out.String(), want); i >= 0 && !strings.HasPrefix(want, `"`) {
			out.Next(i + len(want))
			return texts
		}
		op, data, err := c.read()
		if err != nil {
			t.Fatalf("waiting for %q: %v; output so far %q, messages %v", want, err, out.String(), texts)
		}
		switch op {
		case opBinary:
			out.Write(data)
		case opText:
			texts = append(texts, string(data))
			if strings.HasPrefix(want, `"`) && strings.Contains(string(data), want) {
				return texts
			}
		case opClose:
			if want == "close" {
				return texts
			}
			t.Fatalf("closed waiting for %q; output %q, messages %v", want, out.String(), texts)
		}
	}
}

func TestShellRunsAndResizes(t *testing.T) {
	m, sock := newDemo(t)
	tk, err := m.Open(context.Background(), OpenSpec{User: "u1", Dir: "/home/u1/example.com", Site: "example.com", Actor: "Sercan", IP: "203.0.113.5"})
	if err != nil {
		t.Fatal(err)
	}
	if tk.Path != Path || len(tk.Token) != 64 || tk.Idle != 900 || tk.Max != 7200 {
		t.Fatalf("ticket %+v", tk)
	}
	c, code := dial(t, sock, tk.Token, http.Header{"Origin": {"https://panel.test:8443"}, "X-Real-Ip": {"203.0.113.5"}})
	if code != 101 {
		t.Fatalf("status %d", code)
	}
	c.until(t, `"t":"ready"`)
	if s, _ := m.Get(context.Background(), tk.ID); s.State != "open" || s.Started == nil {
		t.Fatalf("session %+v", s)
	}
	// The shell starts in the site's folder; pasted lines arrive whole.
	c.send(opBinary, []byte("pwd; echo hel''lo\r"))
	c.until(t, "/home/u1/example.com")
	c.until(t, "hello")
	c.send(opText, []byte(`{"t":"resize","cols":132,"rows":40}`))
	c.send(opBinary, []byte("stty size\r"))
	c.until(t, "40 132")
	// A large paste in one message.
	big := strings.Repeat("x", 70000)
	c.send(opBinary, []byte("echo "+big+" | wc -c\r"))
	c.until(t, "70001")

	c.send(opBinary, []byte("exit\r"))
	c.until(t, `"reason":"exited"`)
	waitState(t, m, tk.ID, "ended", "exited")
}

func waitState(t *testing.T, m *Manager, id, state, reason string) {
	t.Helper()
	for i := 0; i < 200; i++ {
		s, _ := m.Get(context.Background(), id)
		if s.State == state && s.Reason == reason {
			return
		}
		time.Sleep(25 * time.Millisecond)
	}
	s, _ := m.Get(context.Background(), id)
	t.Fatalf("session %+v, want %s/%s", s, state, reason)
}

func TestTicketWorksOnceFromItsAddress(t *testing.T) {
	m, sock := newDemo(t)
	ctx := context.Background()
	tk, err := m.Open(ctx, OpenSpec{User: "u1", IP: "203.0.113.5"})
	if err != nil {
		t.Fatal(err)
	}
	if _, code := dial(t, sock, tk.Token, http.Header{"Origin": {"https://evil.test"}}); code != 403 {
		t.Fatalf("other origin: %d", code)
	}
	if _, code := dial(t, sock, strings.Repeat("0", 64), nil); code != 403 {
		t.Fatalf("unknown token: %d", code)
	}
	if _, code := dial(t, sock, tk.Token, http.Header{"X-Real-Ip": {"198.51.100.9"}}); code != 403 {
		t.Fatalf("other address: %d", code)
	}
	// Tried from another address, the ticket is gone.
	if _, code := dial(t, sock, tk.Token, http.Header{"X-Real-Ip": {"203.0.113.5"}}); code != 403 {
		t.Fatalf("burnt ticket: %d", code)
	}
	waitState(t, m, tk.ID, "ended", "failed")

	tk, _ = m.Open(ctx, OpenSpec{User: "u1"})
	c, code := dial(t, sock, tk.Token, nil)
	if code != 101 {
		t.Fatalf("status %d", code)
	}
	c.until(t, `"t":"ready"`)
	if _, code := dial(t, sock, tk.Token, nil); code != 403 {
		t.Fatalf("second use: %d", code)
	}
	// The panel closes it.
	if _, err := m.Close(ctx, tk.ID); err != nil {
		t.Fatal(err)
	}
	c.until(t, `"reason":"closed"`)
	waitState(t, m, tk.ID, "ended", "closed")
}

func TestOpenRefuses(t *testing.T) {
	m, _ := newDemo(t)
	ctx := context.Background()
	for _, spec := range []OpenSpec{
		{User: "u2"},              // SFTP only
		{User: "root"},            // not a site user
		{User: "nobody-here"},     // no such user
		{User: "../x"},            // bad name
		{User: "u1", Dir: "/etc"}, // outside the home
		{User: "u1", Dir: "/home/u1/../u2"},
		{User: "u1", IP: "not-an-ip"},
		{User: "u1", IdleMinutes: 1000},
	} {
		var v *ValidationError
		if _, err := m.Open(ctx, spec); !errors.As(err, &v) {
			t.Errorf("%+v: %v", spec, err)
		}
	}
	m.MaxSessions = 2
	for i := 0; i < 2; i++ {
		if _, err := m.Open(ctx, OpenSpec{User: "u1"}); err != nil {
			t.Fatal(err)
		}
	}
	if _, err := m.Open(ctx, OpenSpec{User: "u1"}); !errors.Is(err, ErrLimit) {
		t.Fatalf("third session: %v", err)
	}
}

func TestUnusedTicketExpiresAndIdleEnds(t *testing.T) {
	m, sock := newDemo(t)
	ctx := context.Background()
	now := time.Now()
	m.mu.Lock()
	m.now = func() time.Time { return now }
	m.mu.Unlock()
	tk, _ := m.Open(ctx, OpenSpec{User: "u1"})
	now = now.Add(TicketTTL + time.Second)
	if s, _ := m.Get(ctx, tk.ID); s.State != "ended" || s.Reason != "expired" {
		t.Fatalf("session %+v", s)
	}
	if _, code := dial(t, sock, tk.Token, nil); code != 403 {
		t.Fatalf("expired ticket: %d", code)
	}

	// Idle: shortened for the test; the warning comes first.
	m.mu.Lock()
	m.now = time.Now
	m.mu.Unlock()
	tk, _ = m.Open(ctx, OpenSpec{User: "u1"})
	m.mu.Lock()
	m.sessions[tk.ID].idle = 2 * time.Second
	m.mu.Unlock()
	c, _ := dial(t, sock, tk.Token, nil)
	texts := c.until(t, `"reason":"idle"`)
	if !strings.Contains(strings.Join(texts, " "), `"t":"idle"`) {
		t.Errorf("no idle warning: %v", texts)
	}
	waitState(t, m, tk.ID, "ended", "idle")
}

func TestCloseUser(t *testing.T) {
	m, sock := newDemo(t)
	ctx := context.Background()
	tk, _ := m.Open(ctx, OpenSpec{User: "u1"})
	c, _ := dial(t, sock, tk.Token, nil)
	c.until(t, `"t":"ready"`)
	waiting, _ := m.Open(ctx, OpenSpec{User: "u1"})
	m.CloseUser(ctx, "u1")
	c.until(t, `"reason":"closed"`)
	waitState(t, m, waiting.ID, "ended", "closed")
	list := m.List(ctx)
	if len(list) != 2 {
		t.Fatalf("list %+v", list)
	}
	b, _ := json.Marshal(list[0])
	if strings.Contains(string(b), tk.Token) {
		t.Fatal("list shows a token")
	}
}
