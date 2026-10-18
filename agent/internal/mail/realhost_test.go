//go:build realhost

// This test sets up a real mail server (Postfix, Dovecot, Rspamd, Redis
// and Roundcube), then sends mail through it and reads it back. It needs
// root and changes the machine, so it only builds with -tags realhost:
//
//	sudo go test -tags realhost -run TestRealHost ./internal/mail
//
// CI runs it on a throwaway runner with Nginx, PHP-FPM and python3-dkim
// installed; mail.setup installs the rest.
package mail

import (
	"bufio"
	"context"
	"crypto/tls"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/http/cookiejar"
	"net/smtp"
	"os"
	"os/exec"
	"regexp"
	"strconv"
	"strings"
	"testing"
	"time"

	"github.com/winchester37/servik/agent/internal/host"
)

const (
	testHost   = "mail.servik.test"
	testDomain = "example.test"
	password   = "Gizli-parola-42"
)

func hasSystemd() bool {
	b, err := os.ReadFile("/proc/1/comm")
	return err == nil && strings.TrimSpace(string(b)) == "systemd"
}

// serviceHost is the real system. Where systemd is not PID 1
// (containers) it starts and reloads the daemons itself.
type serviceHost struct {
	host.System
	t *testing.T
}

func (h serviceHost) sh(ctx context.Context, script string) (string, error) {
	return h.System.Run(ctx, host.Command{Name: "sh", Args: []string{"-c", script}})
}

func (h serviceHost) Run(ctx context.Context, c host.Command) (string, error) {
	if c.Name != "systemctl" || hasSystemd() {
		return h.System.Run(ctx, c)
	}
	if len(c.Args) < 2 {
		return "", nil
	}
	verb, unit := c.Args[0], c.Args[len(c.Args)-1]
	running := map[string]string{
		redisUnit: "pgrep -f '[r]edis-server.*servik-mail'",
		"rspamd":  "pgrep -f '[r]spamd: main'",
		"dovecot": "test -f /run/dovecot/master.pid && kill -0 $(cat /run/dovecot/master.pid)",
		"postfix": "postfix status",
		"nginx":   "test -f /run/nginx.pid && kill -0 $(cat /run/nginx.pid)",
	}
	start := map[string]string{
		redisUnit: "install -d -o redis -g redis -m 2755 /run/redis-servik-mail && runuser -u redis -- redis-server " + redisConf + " --daemonize yes",
		// No systemd drop-in here: put Rspamd into Redis's group instead.
		"rspamd":  "usermod -a -G redis _rspamd && install -d -o _rspamd /run/rspamd && (runuser -u _rspamd -- rspamd -c /etc/rspamd/rspamd.conf >/dev/null 2>&1 &) && sleep 5",
		"dovecot": "dovecot",
		"postfix": "postfix start",
		"nginx":   "nginx",
	}
	stop := map[string]string{
		redisUnit: "pkill -f '[r]edis-server.*servik-mail'; sleep 0.5",
		"rspamd":  "pkill -f '[r]spamd: main'; sleep 1",
		"dovecot": "doveadm stop; sleep 0.5",
		"postfix": "postfix stop",
	}
	reload := map[string]string{
		"rspamd":  "pkill -HUP -f '[r]spamd: main' && sleep 5",
		"dovecot": "doveadm reload",
		"postfix": "postfix reload",
		"nginx":   "nginx -s reload",
	}
	if v, ok := strings.CutPrefix(unit, "php"); ok {
		v = strings.TrimSuffix(v, "-fpm")
		running[unit] = "test -f /run/php/php" + v + "-fpm.pid && kill -0 $(cat /run/php/php" + v + "-fpm.pid)"
		start[unit] = "php-fpm" + v
		reload[unit] = "kill -USR2 $(cat /run/php/php" + v + "-fpm.pid)"
	}
	_, err := h.sh(ctx, running[unit])
	up := running[unit] != "" && err == nil
	switch verb {
	case "is-active":
		if !up {
			return "inactive", errors.New("inactive")
		}
	case "start", "restart", "reload", "reload-or-restart":
		if up && verb != "start" {
			if r := reload[unit]; r != "" && verb != "restart" {
				return h.sh(ctx, r)
			}
			if s := stop[unit]; s != "" {
				_, _ = h.sh(ctx, s)
				up = false
			} else if r := reload[unit]; r != "" {
				return h.sh(ctx, r)
			}
		}
		if !up && start[unit] != "" {
			// Daemons must not hold on to the output pipe.
			return h.sh(ctx, "("+start[unit]+") </dev/null >/dev/null 2>&1")
		}
	}
	return "", nil
}

func TestRealHost(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("needs root")
	}
	h := serviceHost{t: t}
	m := NewManager(h, "www-data")
	ctx := context.Background()
	for _, unit := range []string{"nginx", "php8.3-fpm"} {
		if _, err := h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"start", unit}}); err != nil {
			t.Fatalf("starting %s: %v", unit, err)
		}
	}

	res, err := m.Setup(ctx, SetupSpec{Hostname: testHost, HourlyLimit: 4, Webmail: true})
	if err != nil {
		t.Fatal(err)
	}
	t.Logf("setup: %+v", res)
	if os.Getenv("SERVIK_KEEP") == "" {
		t.Cleanup(func() { _, _ = m.DeleteDomain(context.Background(), testDomain, true) })
	}
	if !hasSystemd() {
		// No journal in containers: keep the mail logs in files.
		_, _ = h.sh(ctx, "postconf -e maillog_file=/var/log/servik-test-postfix.log && postfix reload")
	}

	// Sending limits count from zero.
	_, _ = h.sh(ctx, "redis-cli -s "+redisSocket+" FLUSHALL")

	out, err := exec.Command("doveadm", "pw", "-s", "SHA512-CRYPT", "-p", password).Output()
	if err != nil {
		t.Fatal(err)
	}
	hash := strings.TrimSpace(string(out))
	spec := DomainSpec{
		Domain:     testDomain,
		SpamFilter: true,
		Mailboxes: []Mailbox{
			{Name: "ali", PasswordHash: hash, QuotaMB: 50},
			{Name: "veli", PasswordHash: hash},
		},
		Aliases: []Alias{{Name: "satis", To: []string{"veli@" + testDomain}}},
	}
	applied, err := m.ApplyDomain(ctx, spec)
	if err != nil {
		t.Fatal(err)
	}
	waitPort(t, "127.0.0.1:25")
	waitPort(t, "127.0.0.1:587")
	waitPort(t, "127.0.0.1:993")
	waitPort(t, milterAddr)

	if st := m.Status(ctx); !st.Running {
		t.Fatalf("status = %+v", st)
	}

	ali, veli := "ali@"+testDomain, "veli@"+testDomain
	if err := submit(ali, password, ali, []string{veli}, "Merhaba", "Servik posta testi."); err != nil {
		t.Fatal(err)
	}
	if err := submit(ali, password, ali, []string{"satis@" + testDomain}, "Fiyat", "Forwarder testi."); err != nil {
		t.Fatal(err)
	}

	// Both messages (direct and through the forwarder) arrive, signed.
	msgs := waitMessages(t, veli, password, 2)
	if !strings.Contains(msgs[0], "Subject: Merhaba") || !strings.Contains(msgs[0], "DKIM-Signature:") {
		t.Fatalf("delivered message:\n%s", msgs[0])
	}
	verifyDKIM(t, msgs[0], applied.DKIM.Record)

	// A mailbox may not send as another mailbox.
	if err := submit(ali, password, veli, []string{veli}, "Sahte", "x"); err == nil || !strings.Contains(err.Error(), "553") {
		t.Fatalf("sending as someone else: %v", err)
	}
	// A wrong password is refused.
	if err := submit(ali, "yanlis", ali, []string{veli}, "x", "x"); err == nil || !strings.Contains(err.Error(), "535") {
		t.Fatalf("wrong password: %v", err)
	}

	// Autoreply: veli answers ali once.
	spec.Mailboxes[1].Autoreply = &Autoreply{Subject: "İzindeyim", Body: "Pazartesi döneceğim.", Until: time.Now().AddDate(0, 0, 3).Format("2006-01-02")}
	if _, err := m.ApplyDomain(ctx, spec); err != nil {
		t.Fatal(err)
	}
	if err := submit(ali, password, ali, []string{veli}, "Orada misin", "Selam"); err != nil {
		t.Fatal(err)
	}
	replies := waitMessages(t, ali, password, 1)
	if !strings.Contains(replies[0], "İzindeyim") && !strings.Contains(replies[0], "=?utf-8?") {
		t.Fatalf("autoreply:\n%s", replies[0])
	}

	// The hourly limit (4) stops the fifth message of the hour.
	limited := false
	for i := 0; i < 4 && !limited; i++ {
		err := submit(ali, password, ali, []string{veli}, "Sınır "+strconv.Itoa(i), "x")
		limited = err != nil && strings.Contains(err.Error(), "451")
	}
	if !limited {
		t.Fatal("the hourly sending limit did not apply")
	}

	if u, err := m.Usage(testDomain); err != nil || u.Mailboxes["veli"] == 0 {
		t.Fatalf("usage = %+v, %v", u, err)
	}

	webmail(t, m, veli)

	// Removing a mailbox stops its logins at once.
	spec.Mailboxes = spec.Mailboxes[:1]
	if _, err := m.ApplyDomain(ctx, spec); err != nil {
		t.Fatal(err)
	}
	if _, err := imapFetch(veli, password, 1); err == nil {
		t.Fatal("removed mailbox can still sign in")
	}
	if res, err := m.PurgeMailbox(ctx, testDomain, "veli"); err != nil || !res.Deleted {
		t.Fatalf("purge = %+v, %v", res, err)
	}
}

func waitPort(t *testing.T, addr string) {
	t.Helper()
	deadline := time.Now().Add(30 * time.Second)
	for {
		c, err := net.DialTimeout("tcp", addr, time.Second)
		if err == nil {
			c.Close()
			return
		}
		if time.Now().After(deadline) {
			t.Fatalf("%s does not answer: %v", addr, err)
		}
		time.Sleep(300 * time.Millisecond)
	}
}

// submit sends a message through the submission port with STARTTLS.
func submit(user, pass, from string, to []string, subject, body string) error {
	c, err := smtp.Dial("127.0.0.1:587")
	if err != nil {
		return err
	}
	defer c.Close()
	if err := c.Hello("client.servik.test"); err != nil {
		return err
	}
	if err := c.StartTLS(&tls.Config{ServerName: testHost, InsecureSkipVerify: true}); err != nil {
		return err
	}
	if err := c.Auth(smtp.PlainAuth("", user, pass, "127.0.0.1")); err != nil {
		return err
	}
	if err := c.Mail(from); err != nil {
		return err
	}
	for _, r := range to {
		if err := c.Rcpt(r); err != nil {
			return err
		}
	}
	w, err := c.Data()
	if err != nil {
		return err
	}
	msg := fmt.Sprintf("From: %s\r\nTo: %s\r\nSubject: %s\r\nDate: %s\r\nMessage-ID: <%d@client.servik.test>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n%s\r\n",
		from, strings.Join(to, ", "), subject, time.Now().Format(time.RFC1123Z), time.Now().UnixNano(), body)
	if _, err := io.WriteString(w, msg); err != nil {
		return err
	}
	if err := w.Close(); err != nil {
		return err
	}
	return c.Quit()
}

func waitMessages(t *testing.T, user, pass string, n int) []string {
	t.Helper()
	deadline := time.Now().Add(30 * time.Second)
	for {
		msgs, err := imapFetch(user, pass, n)
		if err == nil && len(msgs) >= n {
			return msgs
		}
		if time.Now().After(deadline) {
			t.Fatalf("%s: %d messages, want %d (%v)", user, len(msgs), n, err)
		}
		time.Sleep(500 * time.Millisecond)
	}
}

var literalRe = regexp.MustCompile(`\{(\d+)\}\r\n$`)

// imapFetch signs in over IMAPS and returns up to n messages of INBOX.
func imapFetch(user, pass string, n int) ([]string, error) {
	conn, err := tls.Dial("tcp", "127.0.0.1:993", &tls.Config{ServerName: testHost, InsecureSkipVerify: true})
	if err != nil {
		return nil, err
	}
	defer conn.Close()
	_ = conn.SetDeadline(time.Now().Add(10 * time.Second))
	r := bufio.NewReader(conn)
	if _, err := r.ReadString('\n'); err != nil {
		return nil, err
	}
	cmd := func(tag, line string) ([]string, error) {
		if _, err := fmt.Fprintf(conn, "%s %s\r\n", tag, line); err != nil {
			return nil, err
		}
		var lits []string
		for {
			l, err := r.ReadString('\n')
			if err != nil {
				return nil, err
			}
			if m := literalRe.FindStringSubmatch(l); m != nil {
				size, _ := strconv.Atoi(m[1])
				buf := make([]byte, size)
				if _, err := io.ReadFull(r, buf); err != nil {
					return nil, err
				}
				lits = append(lits, string(buf))
				continue
			}
			if strings.HasPrefix(l, tag+" ") {
				if !strings.HasPrefix(l, tag+" OK") {
					return nil, errors.New(strings.TrimSpace(l))
				}
				return lits, nil
			}
		}
	}
	if _, err := cmd("a1", fmt.Sprintf("LOGIN %q %q", user, pass)); err != nil {
		return nil, err
	}
	if _, err := cmd("a2", "SELECT INBOX"); err != nil {
		return nil, err
	}
	msgs, err := cmd("a3", fmt.Sprintf("FETCH 1:%d BODY.PEEK[]", n))
	if err != nil && strings.Contains(err.Error(), "Invalid messageset") {
		// Fewer than n messages so far.
		msgs, err = cmd("a3", "FETCH 1:* BODY.PEEK[]")
	}
	if err != nil {
		return nil, err
	}
	_, _ = cmd("a4", "LOGOUT")
	return msgs, nil
}

// verifyDKIM checks the signature with dkimpy, an independent verifier,
// using the record the agent returned instead of a DNS lookup.
func verifyDKIM(t *testing.T, msg, record string) {
	t.Helper()
	f, err := os.CreateTemp(t.TempDir(), "msg")
	if err != nil {
		t.Fatal(err)
	}
	f.WriteString(msg)
	f.Close()
	script := `import dkim, sys
msg = open(sys.argv[1], "rb").read()
print(dkim.verify(msg, dnsfunc=lambda name, timeout=5: sys.argv[2].encode()))`
	out, err := exec.Command("python3", "-c", script, f.Name(), record).CombinedOutput()
	if err != nil || strings.TrimSpace(string(out)) != "True" {
		t.Fatalf("DKIM verification: %s %v\n%s", out, err, msg)
	}
}

// webmail signs into Roundcube with a one-click token and opens the inbox.
func webmail(t *testing.T, m *Manager, address string) {
	t.Helper()
	login, err := m.WebmailLogin(address)
	if err != nil {
		t.Fatal(err)
	}
	jar, _ := cookiejar.New(nil)
	client := &http.Client{
		Jar:     jar,
		Timeout: 20 * time.Second,
		Transport: &http.Transport{
			Proxy:           nil,
			TLSClientConfig: &tls.Config{InsecureSkipVerify: true},
			DialContext: func(ctx context.Context, network, addr string) (net.Conn, error) {
				return (&net.Dialer{}).DialContext(ctx, network, "127.0.0.1:443")
			},
		},
	}
	resp, err := client.Get(login.URL)
	if err != nil {
		t.Fatal(err)
	}
	body, _ := io.ReadAll(resp.Body)
	resp.Body.Close()
	if resp.StatusCode != http.StatusOK || !strings.Contains(string(body), address) || !strings.Contains(string(body), `"task":"mail"`) {
		t.Fatalf("webmail after one-click login: %d\n%.2000s", resp.StatusCode, body)
	}
	// The token works once.
	resp, err = (&http.Client{Transport: client.Transport, Timeout: 20 * time.Second}).Get(login.URL)
	if err != nil {
		t.Fatal(err)
	}
	body, _ = io.ReadAll(resp.Body)
	resp.Body.Close()
	if strings.Contains(string(body), `"task":"mail"`) {
		t.Fatal("a used token signed in again")
	}
}
