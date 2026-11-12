package mail

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/calltech-app/serfex/agent/internal/cert"
	"github.com/calltech-app/serfex/agent/internal/host"
)

func installCert(t *testing.T, sim *host.Simulator, name string) {
	t.Helper()
	certFile, keyFile := cert.Files(name)
	for _, f := range []string{certFile, keyFile} {
		if err := os.MkdirAll(filepath.Dir(sim.Path(f)), 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(sim.Path(f), []byte("pem"), 0o600); err != nil {
			t.Fatal(err)
		}
	}
}

// Mail domains' own webmail addresses get their own server blocks and
// certificates; setups keep them; an empty list takes them away.
func TestWebmailHosts(t *testing.T) {
	m, sim := newTest(t)
	ctx := context.Background()
	if _, err := m.SetWebmailHosts(ctx, WebmailHostsSpec{}); !errors.Is(err, ErrNotSetUp) {
		t.Fatalf("before setup: %v", err)
	}
	setUp(t, m)
	vhost := read(t, sim, mailVhost)
	// Names without a certificate yet are sent on to the shared address.
	contains(t, "vhost", vhost, "server_name mail.example.net ~^autoconfig\\. ~^autodiscover\\. ~^webmail\\.;", "return 301 https://mail.example.net$request_uri;")

	for _, bad := range []WebmailHostsSpec{
		{Hosts: []WebmailHost{{Name: "mail.shop.example", Certificate: "webmail-shop.example"}}},
		{Hosts: []WebmailHost{{Name: "webmail.shop.example", Certificate: "../x"}}},
		{Hosts: []WebmailHost{{Name: "webmail.shop.example;", Certificate: "a"}}},
		{Hosts: []WebmailHost{{Name: "webmail.shop.example", Certificate: "a"}, {Name: "WEBMAIL.shop.example.", Certificate: "b"}}},
	} {
		if _, err := m.SetWebmailHosts(ctx, bad); !isInvalid(err) {
			t.Errorf("%+v: err = %v, want a validation error", bad, err)
		}
	}
	spec := WebmailHostsSpec{Hosts: []WebmailHost{
		{Name: "webmail.shop.example", Certificate: "webmail-shop.example"},
		{Name: "Webmail.Ab.Example", Certificate: "webmail-ab.example"},
	}}
	if _, err := m.SetWebmailHosts(ctx, spec); !isInvalid(err) || !strings.Contains(err.Error(), "not installed") {
		t.Fatalf("without certificates: %v", err)
	}
	if read(t, sim, mailVhost) != vhost {
		t.Fatal("the vhost changed on a refused request")
	}
	installCert(t, sim, "webmail-shop.example")
	installCert(t, sim, "webmail-ab.example")

	before := len(sim.Commands())
	res, err := m.SetWebmailHosts(ctx, spec)
	if err != nil || !res.Changed {
		t.Fatalf("set = %+v, %v", res, err)
	}
	var ran []string
	for _, c := range sim.Commands()[before:] {
		ran = append(ran, c.String())
	}
	if got := strings.Join(ran, "; "); got != "nginx -t; systemctl reload nginx" {
		t.Errorf("commands: %s", got)
	}
	vhost = read(t, sim, mailVhost)
	contains(t, "vhost", vhost,
		"server_name webmail.ab.example;\n",
		"return 301 https://webmail.shop.example$request_uri;",
		"ssl_certificate /etc/serfex/ssl/certs/webmail-shop.example/fullchain.pem;",
		"ssl_certificate_key /etc/serfex/ssl/certs/webmail-ab.example/privkey.pem;",
	)
	// Each address: port 80 and 443, both noindex, webmail on 443.
	if n := strings.Count(vhost, "server_name webmail.shop.example;"); n != 2 {
		t.Errorf("webmail.shop.example has %d server blocks", n)
	}
	if n := strings.Count(vhost, `add_header X-Robots-Tag "noindex, nofollow, noarchive, nosnippet" always;`); n != 6 {
		t.Errorf("%d noindex headers, want 6", n)
	}
	if n := strings.Count(vhost, "root /var/lib/roundcube/public_html;"); n != 3 {
		t.Errorf("%d webmail roots, want 3", n)
	}
	if strings.Index(vhost, "webmail.ab.example") > strings.Index(vhost, "webmail.shop.example") {
		t.Error("addresses are not sorted")
	}
	if res, err := m.SetWebmailHosts(ctx, spec); err != nil || res.Changed {
		t.Fatalf("same again = %+v, %v", res, err)
	}

	// Setup (another webmail app) keeps them.
	if _, err := m.Setup(ctx, SetupSpec{Hostname: "mail.example.net", HourlyLimit: 500, Webmail: true, WebmailApp: SnappyMail}); err != nil {
		t.Fatal(err)
	}
	vhost = read(t, sim, mailVhost)
	if n := strings.Count(vhost, "location = /serfex-sso.php"); n != 3 || !strings.Contains(vhost, "server_name webmail.shop.example;") {
		t.Fatalf("after setup:\n%s", vhost)
	}

	// Nginx refuses: everything stays as it was.
	sim.Fail = func(c host.Command) error {
		if c.String() == "nginx -t" {
			return errors.New("nginx: [emerg] bad")
		}
		return nil
	}
	if _, err := m.SetWebmailHosts(ctx, WebmailHostsSpec{Hosts: spec.Hosts[:1]}); err == nil {
		t.Fatal("accepted while nginx refuses")
	}
	sim.Fail = nil
	if read(t, sim, mailVhost) != vhost {
		t.Fatal("the vhost was not rolled back")
	}
	if s, _ := m.loadServer(); len(s.WebmailHosts) != 2 {
		t.Fatalf("stored hosts after rollback = %+v", s.WebmailHosts)
	}

	if res, err := m.SetWebmailHosts(ctx, WebmailHostsSpec{}); err != nil || !res.Changed {
		t.Fatalf("clear = %+v, %v", res, err)
	}
	if strings.Contains(read(t, sim, mailVhost), "server_name webmail.") {
		t.Fatal("addresses still served")
	}
	if s, _ := m.loadServer(); s.WebmailHosts != nil {
		t.Fatalf("stored hosts = %+v", s.WebmailHosts)
	}
}

func isInvalid(err error) bool { return errors.As(err, new(*ValidationError)) }
