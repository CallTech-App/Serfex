//go:build realhost

// This test installs real database servers, creates databases and users,
// connects with them, opens phpMyAdmin and Adminer through servik-web and
// deletes a database with a backup. It needs root and changes the
// machine, so it only builds with -tags realhost:
//
//	sudo SERVIK_DB_PRODUCT=mysql go test -tags realhost -run TestRealHost ./internal/database
//
// SERVIK_DB_PRODUCT is mysql (default), mariadb or postgresql. CI runs it
// on throwaway runners after removing their preinstalled servers.
package database

import (
	"compress/gzip"
	"context"
	"crypto/tls"
	"errors"
	"io"
	"net"
	"net/http"
	"net/http/cookiejar"
	"os"
	"os/exec"
	"regexp"
	"slices"
	"strconv"
	"strings"
	"testing"
	"time"

	"github.com/winchester37/servik/agent/internal/host"
)

const realPassword = "Gizli-parola-42"

func hasSystemd() bool {
	b, err := os.ReadFile("/proc/1/comm")
	return err == nil && strings.TrimSpace(string(b)) == "systemd"
}

// serviceHost is the real system. Where systemd is not PID 1
// (containers) it starts and reloads the daemons itself.
type serviceHost struct {
	host.System
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
	switch verb {
	case "start", "restart", "reload", "reload-or-restart", "is-active", "stop":
	default:
		return "", nil
	}
	if verb == "reload-or-restart" {
		verb = "reload"
	}
	if v, ok := strings.CutPrefix(unit, "postgresql@"); ok {
		v = strings.TrimSuffix(v, "-main")
		if verb == "is-active" {
			verb = "status"
		}
		if verb == "start" {
			if _, err := h.sh(ctx, "pg_ctlcluster "+v+" main status"); err == nil {
				return "", nil
			}
		}
		return h.sh(ctx, "pg_ctlcluster "+v+" main "+verb)
	}
	switch unit {
	case "mysql", "mariadb":
		if verb == "is-active" {
			verb = "status"
		}
		return h.sh(ctx, "service "+unit+" "+verb)
	case WebUnit:
		running := "test -f /run/servik-web.pid && kill -0 $(cat /run/servik-web.pid)"
		switch verb {
		case "is-active":
			return h.sh(ctx, running)
		case "reload", "restart":
			if _, err := h.sh(ctx, running); err == nil {
				return h.sh(ctx, "nginx -c "+webConf+" -s reload && sleep 1")
			}
		case "stop":
			return h.sh(ctx, "nginx -c "+webConf+" -s quit")
		case "start":
			if _, err := h.sh(ctx, running); err == nil {
				return "", nil
			}
		}
		return h.sh(ctx, "nginx -c "+webConf)
	case PHPUnit:
		pid := "/run/servik-php/php-fpm.pid"
		running := "test -f " + pid + " && kill -0 $(cat " + pid + ")"
		switch verb {
		case "is-active":
			return h.sh(ctx, running)
		case "reload":
			if _, err := h.sh(ctx, running); err == nil {
				return h.sh(ctx, "kill -USR2 $(cat "+pid+")")
			}
		case "stop", "restart":
			_, _ = h.sh(ctx, "kill $(cat "+pid+") && sleep 1")
			if verb == "stop" {
				return "", nil
			}
		case "start":
			if _, err := h.sh(ctx, running); err == nil {
				return "", nil
			}
		}
		unitFile, err := os.ReadFile(phpUnitFile)
		if err != nil {
			return "", err
		}
		start := regexp.MustCompile(`(?m)^ExecStart=(.*)$`).FindSubmatch(unitFile)
		return h.sh(ctx, "install -d -m 0755 /run/servik-php && ("+string(start[1])+" </dev/null >/dev/null 2>&1 &) && sleep 1")
	}
	return "", nil
}

// client runs a query as a database user over TCP.
func client(server, addr, user, password, db, query string) (string, error) {
	var cmd *exec.Cmd
	if server == MySQL {
		cmd = exec.Command("mysql", "--batch", "--skip-column-names", "-h", addr, "-u", user, db, "-e", query)
		cmd.Env = append(os.Environ(), "MYSQL_PWD="+password)
	} else {
		cmd = exec.Command("psql", "-X", "-q", "-A", "-t", "-v", "ON_ERROR_STOP=1",
			"host="+addr+" user="+user+" dbname="+db+" connect_timeout=5", "-c", query)
		cmd.Env = append(os.Environ(), "PGPASSWORD="+password)
	}
	out, err := cmd.CombinedOutput()
	if err != nil {
		return string(out), errors.New(strings.TrimSpace(string(out)))
	}
	return strings.TrimSpace(string(out)), nil
}

// publicIP is an address of this machine that is not loopback.
func publicIP(t *testing.T) string {
	addrs, err := net.InterfaceAddrs()
	if err != nil {
		t.Fatal(err)
	}
	for _, a := range addrs {
		if n, ok := a.(*net.IPNet); ok && n.IP.To4() != nil && !n.IP.IsLoopback() {
			return n.IP.String()
		}
	}
	t.Skip("no non-loopback IPv4 address")
	return ""
}

func TestRealHost(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("needs root")
	}
	product := os.Getenv("SERVIK_DB_PRODUCT")
	if product == "" {
		product = MySQL
	}
	h := serviceHost{}
	m := NewManager(h)
	ctx := context.Background()

	res, err := m.Install(ctx, InstallSpec{Product: product})
	if err != nil {
		t.Fatal(err)
	}
	t.Logf("install: %+v", res)
	server := res.Server
	if st := m.Status(ctx).Servers[server]; !st.Running || st.Product != product {
		t.Fatalf("status = %+v", st)
	}
	// Installing again repairs and changes nothing.
	if _, err := m.Install(ctx, InstallSpec{Product: product}); err != nil {
		t.Fatal(err)
	}
	if server == MySQL {
		other := MariaDB
		if product == MariaDB {
			other = MySQL
		}
		var verr *ValidationError
		if _, err := m.Install(ctx, InstallSpec{Product: other}); !errors.As(err, &verr) {
			t.Fatalf("installing %s next to %s: %v", other, product, err)
		}
	}

	const (
		shop    = "acme_shop"
		blog    = "acme_blog"
		app     = "acme_app"
		reader  = "acme_reader"
		outside = "acme_remote"
	)
	if os.Getenv("SERVIK_KEEP") == "" {
		t.Cleanup(func() {
			ctx := context.Background()
			for _, u := range []string{app, reader, outside} {
				_, _ = m.DeleteUser(ctx, UserRef{Server: server, Name: u})
			}
			for _, d := range []string{shop, blog} {
				_, _ = m.DeleteDatabase(ctx, DeleteSpec{Server: server, Name: d})
			}
		})
	}
	for _, d := range []string{shop, blog} {
		if _, err := m.CreateDatabase(ctx, DatabaseSpec{Server: server, Name: d, Site: "acme.test"}); err != nil {
			t.Fatal(err)
		}
	}
	// Creating again is fine.
	if _, err := m.CreateDatabase(ctx, DatabaseSpec{Server: server, Name: shop, Site: "acme.test"}); err != nil {
		t.Fatal(err)
	}
	if got := m.Sites()[shop]; got != "acme.test" {
		t.Fatalf("site of %s = %q", shop, got)
	}

	users := []UserSpec{
		{Server: server, Name: app, Password: realPassword, Grants: []Grant{{shop, AccessAll}, {blog, AccessAll}}},
		{Server: server, Name: reader, Password: realPassword, Grants: []Grant{{shop, AccessReadOnly}}},
	}
	for _, u := range users {
		if _, err := m.ApplyUser(ctx, u); err != nil {
			t.Fatal(err)
		}
	}

	// The owner builds a table; the read-only user reads it but cannot
	// write; nobody reaches a database they were not given.
	for _, addr := range []string{"127.0.0.1", "localhost"} {
		if server == PostgreSQL && addr == "localhost" {
			continue
		}
		if _, err := client(server, addr, app, realPassword, shop, "SELECT 1"); err != nil {
			t.Fatalf("%s via %s: %v", app, addr, err)
		}
	}
	if _, err := client(server, "127.0.0.1", app, realPassword, shop,
		"CREATE TABLE urunler (id INT PRIMARY KEY, ad VARCHAR(40)); INSERT INTO urunler VALUES (1, 'Kalem'), (2, 'Defter');"); err != nil {
		t.Fatal(err)
	}
	if out, err := client(server, "127.0.0.1", reader, realPassword, shop, "SELECT ad FROM urunler WHERE id = 2"); err != nil || out != "Defter" {
		t.Fatalf("read-only select: %q %v", out, err)
	}
	if _, err := client(server, "127.0.0.1", reader, realPassword, shop, "INSERT INTO urunler VALUES (3, 'Silgi')"); err == nil {
		t.Fatal("read-only user could insert")
	}
	if _, err := client(server, "127.0.0.1", reader, realPassword, blog, "SELECT 1"); err == nil {
		t.Fatal("read-only user reached a database it was not given")
	}
	if _, err := client(server, "127.0.0.1", app, "yanlis-parola", shop, "SELECT 1"); err == nil {
		t.Fatal("wrong password accepted")
	}

	// A new password works, the old one stops working.
	users[0].Password = realPassword + "-yeni"
	if _, err := m.ApplyUser(ctx, users[0]); err != nil {
		t.Fatal(err)
	}
	if _, err := client(server, "127.0.0.1", app, realPassword, shop, "SELECT 1"); err == nil {
		t.Fatal("old password still works")
	}
	if _, err := client(server, "127.0.0.1", app, users[0].Password, shop, "SELECT COUNT(*) FROM urunler"); err != nil {
		t.Fatal(err)
	}

	// The listing has the databases with sizes and the users.
	l, err := m.List(ctx, server)
	if err != nil {
		t.Fatal(err)
	}
	t.Logf("list: %+v", l)
	var found bool
	for _, d := range l.Databases {
		if d.Name == shop {
			found = d.Size > 0 && d.Tables == 1
		}
	}
	if !found {
		t.Fatalf("%s missing or empty in %+v", shop, l.Databases)
	}
	for _, u := range []string{app, reader} {
		if !slices.ContainsFunc(l.Users, func(i UserInfo) bool { return i.Name == u }) {
			t.Fatalf("user %s missing in %+v", u, l.Users)
		}
	}

	// Remote access: the server listens outside only while a user may
	// connect from elsewhere.
	ip := publicIP(t)
	if _, err := client(server, ip, app, users[0].Password, shop, "SELECT 1"); err == nil {
		t.Fatal("reachable from outside before remote access")
	}
	remote := UserSpec{Server: server, Name: outside, Password: realPassword, Hosts: []string{AnyHost}, Grants: []Grant{{shop, AccessReadWrite}}}
	if _, err := m.ApplyUser(ctx, remote); err != nil {
		t.Fatal(err)
	}
	// Another host without a password keeps the user's password.
	remote.Password, remote.Hosts = "", []string{AnyHost, "10.8.0.0/24"}
	if _, err := m.ApplyUser(ctx, remote); err != nil {
		t.Fatal(err)
	}
	if !m.Status(ctx).Servers[server].Remote {
		t.Fatal("status does not show remote access")
	}
	if out, err := client(server, ip, outside, realPassword, shop, "SELECT COUNT(*) FROM urunler"); err != nil || out != "2" {
		p, _ := m.installedProduct(server)
		conf, _ := os.ReadFile(p.conf)
		listen, _ := exec.Command("ss", "-ltnp").CombinedOutput()
		t.Fatalf("remote connection: %q %v\nconfig:\n%s\nlistening:\n%s", out, err, conf, listen)
	}
	if _, err := client(server, ip, app, users[0].Password, shop, "SELECT 1"); err == nil {
		t.Fatal("a local-only user connected from outside")
	}
	remote.Hosts = nil
	if _, err := m.ApplyUser(ctx, remote); err != nil {
		t.Fatal(err)
	}
	if _, err := client(server, ip, outside, realPassword, shop, "SELECT 1"); err == nil {
		t.Fatal("still reachable from outside after remote access was turned off")
	}
	if _, err := client(server, "127.0.0.1", outside, realPassword, shop, "SELECT 1"); err != nil {
		t.Fatal(err)
	}

	// One-click sign-in to the web manager.
	ms, err := m.SetupManager(ctx, ManagerSpec{Port: DefaultPort})
	if err != nil {
		t.Fatal(err)
	}
	t.Logf("manager: %+v", ms)
	login, err := m.Login(ctx, LoginSpec{Server: server, Database: shop})
	if err != nil {
		t.Fatal(err)
	}
	link := "https://127.0.0.1:" + strconv.Itoa(login.Port) + login.Path
	jar, _ := cookiejar.New(nil)
	hc := &http.Client{Jar: jar, Timeout: 30 * time.Second, Transport: &http.Transport{TLSClientConfig: &tls.Config{InsecureSkipVerify: true}}}
	get := func(u string) (int, string) {
		t.Helper()
		var resp *http.Response
		var err error
		for i := 0; i < 20; i++ {
			if resp, err = hc.Get(u); err == nil {
				break
			}
			time.Sleep(500 * time.Millisecond)
		}
		if err != nil {
			t.Fatal(err)
		}
		defer resp.Body.Close()
		b, _ := io.ReadAll(resp.Body)
		return resp.StatusCode, string(b)
	}
	code, body := get(link)
	if code != http.StatusOK || !strings.Contains(body, "urunler") {
		t.Fatalf("%s sign-in: %d\n%s", login.Tool, code, body)
	}
	// The link works once.
	jar2, _ := cookiejar.New(nil)
	hc.Jar = jar2
	if code, body := get(link); code != http.StatusForbidden || !strings.Contains(body, "Oturum geçersiz") {
		t.Fatalf("second use of the link: %d\n%s", code, body)
	}
	hc.Jar = jar

	// The panel's port moves in two steps: the old and the new port
	// together, then the new one alone; the sign-in keeps working.
	dial := func(port int) bool {
		for i := 0; i < 20; i++ {
			c, err := net.DialTimeout("tcp", "127.0.0.1:"+strconv.Itoa(port), time.Second)
			if err == nil {
				c.Close()
				return true
			}
			time.Sleep(250 * time.Millisecond)
		}
		return false
	}
	if _, err := m.SetWebPorts(ctx, WebPortsSpec{Ports: []int{9443, DefaultPort}}); err != nil {
		t.Fatal(err)
	}
	if !dial(9443) || !dial(DefaultPort) {
		t.Fatal("servik-web does not listen on both ports during a port change")
	}
	if _, err := m.SetWebPorts(ctx, WebPortsSpec{Ports: []int{9443}}); err != nil {
		t.Fatal(err)
	}
	if !dial(9443) {
		t.Fatal("servik-web does not listen on the new port")
	}
	time.Sleep(time.Second)
	if c, err := net.DialTimeout("tcp", "127.0.0.1:"+strconv.Itoa(DefaultPort), time.Second); err == nil {
		c.Close()
		t.Fatal("servik-web still listens on the old port")
	}
	login2, err := m.Login(ctx, LoginSpec{Server: server, Database: shop})
	if err != nil || login2.Port != 9443 {
		t.Fatalf("sign-in after the port change: %+v, %v", login2, err)
	}
	if code, _ := get("https://127.0.0.1:9443" + login2.Path); code != http.StatusOK {
		t.Fatalf("sign-in on the new port: %d", code)
	}
	if _, err := m.SetWebPorts(ctx, WebPortsSpec{Ports: []int{DefaultPort}}); err != nil {
		t.Fatal(err)
	}

	// Deleting with a backup keeps a dump, removes the database and keeps
	// the users' other grants.
	del, err := m.DeleteDatabase(ctx, DeleteSpec{Server: server, Name: shop, Backup: true})
	if err != nil {
		t.Fatal(err)
	}
	f, err := os.Open(del.Backup)
	if err != nil {
		t.Fatal(err)
	}
	zr, err := gzip.NewReader(f)
	if err != nil {
		t.Fatal(err)
	}
	dump, _ := io.ReadAll(zr)
	f.Close()
	if !strings.Contains(string(dump), "urunler") || !strings.Contains(string(dump), "Defter") {
		t.Fatalf("backup %s does not hold the data:\n%.2000s", del.Backup, dump)
	}
	if _, err := client(server, "127.0.0.1", app, users[0].Password, shop, "SELECT 1"); err == nil {
		t.Fatal("deleted database still reachable")
	}
	if _, err := client(server, "127.0.0.1", app, users[0].Password, blog, "SELECT 1"); err != nil {
		t.Fatalf("other grant lost: %v", err)
	}
	if _, ok := m.Sites()[shop]; ok {
		t.Fatal("deleted database still mapped to its site")
	}

	// A deleted user cannot sign in.
	if _, err := m.DeleteUser(ctx, UserRef{Server: server, Name: reader}); err != nil {
		t.Fatal(err)
	}
	if _, err := client(server, "127.0.0.1", reader, realPassword, blog, "SELECT 1"); err == nil {
		t.Fatal("deleted user still connects")
	}

	// Sign-in users expire.
	if _, err := m.Login(ctx, LoginSpec{Server: server, Database: blog}); err != nil {
		t.Fatal(err)
	}
	m.now = func() time.Time { return time.Now().Add(SessionTTL + time.Minute) }
	m.Sweep(ctx)
	st, err := m.loadState()
	if err != nil {
		t.Fatal(err)
	}
	if len(st.Sessions) != 0 {
		t.Fatalf("sessions after sweep: %+v", st.Sessions)
	}
}
