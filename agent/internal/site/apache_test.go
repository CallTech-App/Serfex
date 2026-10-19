package site

import (
	"context"
	"errors"
	"os"
	"strings"
	"testing"

	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/txn"
)

// installApache makes the simulator look like Apache was set up by
// Servik.
func installApache(t *testing.T, sim *host.Simulator) {
	t.Helper()
	for file, data := range map[string]string{
		"/usr/sbin/apache2":                  "",
		ApachePorts:                          "# " + txn.Marker + "\nListen 127.0.0.1:7080\n",
		"/etc/apache2/sites-available/.keep": "",
		"/etc/apache2/sites-enabled/.keep":   "",
	} {
		if err := os.MkdirAll(sim.Path(file[:strings.LastIndex(file, "/")]), 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(sim.Path(file), []byte(data), 0o644); err != nil {
			t.Fatal(err)
		}
	}
}

func TestApplyBehindApache(t *testing.T) {
	m, sim := newTest(t)
	s := spec
	s.WebServer = WebApache

	var v *ValidationError
	if _, err := m.Apply(context.Background(), s); !errors.As(err, &v) || !strings.Contains(err.Error(), "Apache is not installed") {
		t.Fatalf("apache without Apache: %v", err)
	}

	installApache(t, sim)
	res, err := m.Apply(context.Background(), s)
	if err != nil {
		t.Fatal(err)
	}
	if res.ApacheConfig != "/etc/apache2/sites-available/kahveatolyesi.com.tr.conf" {
		t.Fatalf("apache config = %q", res.ApacheConfig)
	}
	vhost := read(t, sim, res.ApacheConfig)
	for _, want := range []string{
		"<VirtualHost 127.0.0.1:7080>",
		"ServerName kahveatolyesi.com.tr",
		"ServerAlias www.kahveatolyesi.com.tr",
		"DocumentRoot /home/kahveatolyesi/web/kahveatolyesi.com.tr/public_html",
		"AllowOverride All",
		`SetHandler "proxy:unix:/run/php/servik-kahveatolyesi.com.tr.sock|fcgi://servik-kahveatolyesi.com.tr/"`,
		"RemoteIPHeader X-Real-IP",
	} {
		if !strings.Contains(vhost, want) {
			t.Errorf("apache vhost lacks %q:\n%s", want, vhost)
		}
	}
	if target, err := os.Readlink(sim.Path("/etc/apache2/sites-enabled/kahveatolyesi.com.tr.conf")); err != nil || target != res.ApacheConfig {
		t.Errorf("apache site not enabled: %q %v", target, err)
	}
	nginx := read(t, sim, res.NginxConfig)
	for _, want := range []string{"proxy_pass http://127.0.0.1:7080;", "try_files $uri @apache;", "proxy_set_header X-Forwarded-Proto $scheme;"} {
		if !strings.Contains(nginx, want) {
			t.Errorf("nginx vhost lacks %q:\n%s", want, nginx)
		}
	}
	if strings.Contains(nginx, "fastcgi_pass") {
		t.Errorf("nginx still passes PHP itself:\n%s", nginx)
	}
	if !sim.Ran("apache2ctl configtest") || !sim.Ran("systemctl reload apache2") {
		t.Errorf("apache was not checked and reloaded: %v", sim.Commands())
	}

	// Back to Nginx alone: the Apache vhost goes.
	res, err = m.Apply(context.Background(), spec)
	if err != nil {
		t.Fatal(err)
	}
	if exists(sim, "/etc/apache2/sites-available/kahveatolyesi.com.tr.conf") || exists(sim, "/etc/apache2/sites-enabled/kahveatolyesi.com.tr.conf") {
		t.Error("apache vhost left behind")
	}
	if !strings.Contains(read(t, sim, res.NginxConfig), "fastcgi_pass unix:/run/php/servik-kahveatolyesi.com.tr.sock;") {
		t.Error("nginx does not pass PHP to PHP-FPM again")
	}
}

func TestApacheRejectionRollsBack(t *testing.T) {
	m, sim := newTest(t)
	installApache(t, sim)
	if _, err := m.Apply(context.Background(), spec); err != nil {
		t.Fatal(err)
	}
	before := read(t, sim, "/etc/nginx/sites-available/kahveatolyesi.com.tr.conf")
	sim.Fail = func(c host.Command) error {
		if c.String() == "apache2ctl configtest" {
			return errors.New("AH00526: Syntax error")
		}
		return nil
	}
	s := spec
	s.WebServer = WebApache
	if _, err := m.Apply(context.Background(), s); err == nil || !strings.Contains(err.Error(), "apache rejected") {
		t.Fatalf("err = %v", err)
	}
	if exists(sim, "/etc/apache2/sites-available/kahveatolyesi.com.tr.conf") {
		t.Error("apache vhost kept after a failed check")
	}
	if read(t, sim, "/etc/nginx/sites-available/kahveatolyesi.com.tr.conf") != before {
		t.Error("nginx vhost not rolled back")
	}
}

func TestDeleteRemovesApacheSite(t *testing.T) {
	m, sim := newTest(t)
	installApache(t, sim)
	s := spec
	s.WebServer = WebApache
	if _, err := m.Apply(context.Background(), s); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Delete(context.Background(), DeleteSpec{Domain: spec.Domain, User: spec.User}); err != nil {
		t.Fatal(err)
	}
	if exists(sim, "/etc/apache2/sites-available/kahveatolyesi.com.tr.conf") || exists(sim, "/etc/apache2/sites-enabled/kahveatolyesi.com.tr.conf") {
		t.Error("apache vhost left behind")
	}
}

func TestWebServerValidated(t *testing.T) {
	m, _ := newTest(t)
	s := spec
	s.WebServer = "lighttpd"
	var v *ValidationError
	if _, err := m.Apply(context.Background(), s); !errors.As(err, &v) {
		t.Fatalf("err = %v", err)
	}
}
