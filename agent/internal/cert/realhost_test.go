//go:build realhost

// This test obtains real certificates from an ACME server for a site
// served by real Nginx and PowerDNS. It needs root and changes the
// machine, so it only builds with -tags realhost. It talks to Pebble, Let's
// Encrypt's test server, which must resolve names through this machine's
// PowerDNS and validate HTTP-01 on port 80:
//
//	pebble -config pebble.json -dnsserver 127.0.0.1:53
//	sudo SERVIK_ACME_DIRECTORY=https://localhost:14000/dir SERVIK_ACME_CA=pebble.minica.pem \
//	    go test -tags realhost -run TestRealHost ./internal/cert
//
// CI runs it on a throwaway runner.
package cert_test

import (
	"context"
	"crypto/tls"
	"crypto/x509"
	"io"
	"net"
	"net/http"
	"os"
	"os/exec"
	"strings"
	"testing"
	"time"

	"github.com/calltech-app/serfex/agent/internal/cert"
	"github.com/calltech-app/serfex/agent/internal/dns"
	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/site"
)

func hasSystemd() bool {
	b, err := os.ReadFile("/proc/1/comm")
	return err == nil && strings.TrimSpace(string(b)) == "systemd"
}

// serviceHost is the real system. Where systemd is not PID 1 (containers)
// it reloads Nginx and PHP-FPM with signals and runs PowerDNS itself.
type serviceHost struct {
	host.System
	t *testing.T
}

func (h serviceHost) Run(ctx context.Context, c host.Command) (string, error) {
	if c.Name != "systemctl" || hasSystemd() {
		return h.System.Run(ctx, c)
	}
	if len(c.Args) != 2 {
		return "", nil
	}
	switch unit := c.Args[1]; {
	case c.Args[0] == "reload" && unit == "nginx":
		return h.System.Run(ctx, host.Command{Name: "nginx", Args: []string{"-s", "reload"}})
	case c.Args[0] == "reload" && strings.HasPrefix(unit, "php"):
		ver := strings.TrimSuffix(strings.TrimPrefix(unit, "php"), "-fpm")
		return h.System.Run(ctx, host.Command{Name: "sh", Args: []string{"-c", "kill -USR2 $(cat /run/php/php" + ver + "-fpm.pid)"}})
	case c.Args[0] == "restart" && unit == "pdns@servik":
		_ = exec.Command("pkill", "-f", "pdns_server --config-name=servik").Run()
		time.Sleep(300 * time.Millisecond)
		_ = os.MkdirAll("/run/pdns-servik", 0o755)
		_ = exec.Command("chown", "pdns:pdns", "/run/pdns-servik").Run()
		cmd := exec.Command("/usr/sbin/pdns_server", "--config-name=servik", "--guardian=no", "--daemon=no",
			"--setuid=pdns", "--setgid=pdns", "--socket-dir=/run/pdns-servik")
		if err := cmd.Start(); err != nil {
			return "", err
		}
		h.t.Cleanup(func() { _ = cmd.Process.Kill(); _ = cmd.Wait() })
	}
	return "", nil
}

// start makes sure a service runs: through systemd where it is PID 1, so
// the agent's "systemctl reload" works, and directly otherwise.
func start(t *testing.T, unit, pidFile, bin string) {
	t.Helper()
	cmd := exec.Command(bin)
	if hasSystemd() {
		cmd = exec.Command("systemctl", "start", unit)
	} else if fileExists(pidFile) {
		return
	}
	if out, err := cmd.CombinedOutput(); err != nil {
		t.Fatalf("start %s: %v: %s", unit, err, out)
	}
}

func TestRealHost(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("needs root")
	}
	directory, caFile := os.Getenv("SERVIK_ACME_DIRECTORY"), os.Getenv("SERVIK_ACME_CA")
	if directory == "" || caFile == "" {
		t.Skip("SERVIK_ACME_DIRECTORY and SERVIK_ACME_CA are not set")
	}
	ca, err := os.ReadFile(caFile)
	if err != nil {
		t.Fatal(err)
	}
	pool := x509.NewCertPool()
	pool.AppendCertsFromPEM(ca)
	acmeClient := &http.Client{Timeout: 30 * time.Second, Transport: &http.Transport{TLSClientConfig: &tls.Config{RootCAs: pool}}}

	h := serviceHost{t: t}
	ctx := context.Background()
	start(t, "nginx", "/run/nginx.pid", "nginx")

	// PowerDNS answers for the test zone, so Pebble's validation finds this
	// machine and the DNS-01 records.
	dnsm := dns.NewManager(h)
	if _, err := dnsm.Setup(ctx); err != nil {
		t.Fatal(err)
	}
	const zone = "servik-ssl.example"
	t.Cleanup(func() { _, _ = dnsm.DeleteZone(ctx, zone) })
	if _, err := dnsm.ApplyZone(ctx, dns.ZoneSpec{
		Zone: zone,
		SOA:  dns.SOA{Primary: "ns1." + zone, Hostmaster: "hostmaster@" + zone, Refresh: 10800, Retry: 3600, Expire: 604800, Minimum: 3600, TTL: 3600},
		Records: []dns.Record{
			{Name: "@", Type: "NS", TTL: 3600, Content: "ns1." + zone},
			{Name: "@", Type: "A", TTL: 60, Content: "127.0.0.1"},
			{Name: "www", Type: "A", TTL: 60, Content: "127.0.0.1"},
			{Name: "panel", Type: "A", TTL: 60, Content: "127.0.0.1"},
		},
	}); err != nil {
		t.Fatal(err)
	}

	sites := site.NewManager(h, "www-data")
	versions, err := sites.PHPVersions()
	if err != nil || len(versions) == 0 {
		t.Fatalf("no PHP-FPM installed: %v", err)
	}
	start(t, "php"+versions[0]+"-fpm", "/run/php/php"+versions[0]+"-fpm.pid", "php-fpm"+versions[0])
	spec := site.Spec{Domain: zone, User: "servikssl", PHPVersion: versions[0], Aliases: []string{"www." + zone}}
	t.Cleanup(func() {
		_, _ = sites.Delete(ctx, site.DeleteSpec{Domain: spec.Domain, User: spec.User, RemoveFiles: true, RemoveUser: true})
	})
	if _, err := sites.Apply(ctx, spec); err != nil {
		t.Fatal(err)
	}

	certs := cert.NewManager(h, dnsm, &cert.ACME{H: h, DirectoryURL: directory, HTTPClient: acmeClient})
	t.Cleanup(func() {
		_ = os.Remove("/etc/nginx/sites-enabled/servik-panel.conf")
		_ = os.Remove("/etc/nginx/sites-available/servik-panel.conf")
		_, _ = certs.Delete(zone)
		_, _ = certs.Delete("wild")
		_ = os.RemoveAll(cert.PanelDir)
	})

	// HTTP-01 through Nginx.
	res, err := certs.Issue(ctx, cert.IssueSpec{Name: zone, Names: []string{zone, "www." + zone}, Email: "admin@" + zone})
	if err != nil {
		t.Fatal(err)
	}
	t.Logf("issued %v by %s until %s", res.Names, res.Issuer, res.NotAfter)
	if len(res.Names) != 2 || res.Issuer == cert.SimulatedIssuer {
		t.Fatalf("unexpected certificate %+v", res)
	}

	spec.TLS = &site.TLSSpec{Certificate: zone, Redirect: true}
	if _, err := sites.Apply(ctx, spec); err != nil {
		t.Fatal(err)
	}
	served := waitForTLS(t, "www."+zone)
	if served.SerialNumber.Text(16) != strings.ToLower(res.Serial) {
		t.Fatalf("Nginx serves serial %s, want %s", served.SerialNumber.Text(16), res.Serial)
	}
	assertRedirect(t, "www."+zone)

	// Renewal swaps the files under the running site.
	again, err := certs.Issue(ctx, cert.IssueSpec{Name: zone, Names: []string{zone, "www." + zone}, Email: "admin@" + zone})
	if err != nil {
		t.Fatal(err)
	}
	if !again.Reloaded {
		t.Fatal("renewal did not reload Nginx")
	}
	waitFor(t, func() bool {
		c := servedCert(zone)
		return c != nil && c.SerialNumber.Text(16) == strings.ToLower(again.Serial)
	})

	// DNS-01 through PowerDNS, with a wildcard.
	wild, err := certs.Issue(ctx, cert.IssueSpec{Name: "wild", Names: []string{zone, "*." + zone}, Challenge: cert.DNS01, DNSZone: zone})
	if err != nil {
		t.Fatal(err)
	}
	if strings.Join(wild.Names, " ") != zone+" *."+zone && strings.Join(wild.Names, " ") != "*."+zone+" "+zone {
		t.Fatalf("wildcard names %v", wild.Names)
	}
	z, err := dnsm.GetZone(ctx, zone)
	if err != nil {
		t.Fatal(err)
	}
	for _, r := range z.Records {
		if r.Type == "TXT" {
			t.Fatalf("challenge record left behind: %+v", r)
		}
	}

	// The panel's own address.
	panel, err := certs.IssuePanel(ctx, cert.PanelSpec{Hostname: "panel." + zone})
	if err != nil {
		t.Fatal(err)
	}
	if info, err := certs.GetPanel(); err != nil || info == nil || info.Serial != panel.Serial {
		t.Fatalf("panel certificate: %+v %v", info, err)
	}

	// Back to HTTP only, then the certificate can go.
	spec.TLS = nil
	if _, err := sites.Apply(ctx, spec); err != nil {
		t.Fatal(err)
	}
	if del, err := certs.Delete(zone); err != nil || !del.Deleted {
		t.Fatalf("delete: %+v %v", del, err)
	}
}

// servedCert returns the certificate Nginx presents for name, or nil.
func servedCert(name string) *x509.Certificate {
	conn, err := tls.DialWithDialer(&net.Dialer{Timeout: 2 * time.Second}, "tcp", "127.0.0.1:443", &tls.Config{ServerName: name, InsecureSkipVerify: true})
	if err != nil {
		return nil
	}
	defer conn.Close()
	return conn.ConnectionState().PeerCertificates[0]
}

func waitForTLS(t *testing.T, name string) *x509.Certificate {
	t.Helper()
	var c *x509.Certificate
	waitFor(t, func() bool { c = servedCert(name); return c != nil })
	if err := c.VerifyHostname(name); err != nil {
		t.Fatal(err)
	}
	return c
}

func assertRedirect(t *testing.T, name string) {
	t.Helper()
	client := &http.Client{CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	want := "https://" + name + "/yol?a=1"
	var status int
	var location string
	// Old Nginx workers may answer for a moment after a reload.
	for i := 0; i < 50; i++ {
		req, _ := http.NewRequest("GET", "http://127.0.0.1/yol?a=1", nil)
		req.Host = name
		resp, err := client.Do(req)
		if err != nil {
			t.Fatal(err)
		}
		io.Copy(io.Discard, resp.Body)
		resp.Body.Close()
		status, location = resp.StatusCode, resp.Header.Get("Location")
		if status == 301 && location == want {
			return
		}
		time.Sleep(100 * time.Millisecond)
	}
	t.Fatalf("redirect = %d %s", status, location)
}

func waitFor(t *testing.T, ok func() bool) {
	t.Helper()
	for i := 0; i < 100; i++ {
		if ok() {
			return
		}
		time.Sleep(100 * time.Millisecond)
	}
	t.Fatal("timed out")
}

func fileExists(p string) bool {
	_, err := os.Stat(p)
	return err == nil
}
