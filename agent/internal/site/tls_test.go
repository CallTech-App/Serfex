package site

import (
	"context"
	"errors"
	"strings"
	"testing"

	"github.com/calltech-app/serfex/agent/internal/cert"
)

func TestApplyServesHTTPS(t *testing.T) {
	m, sim := newTest(t)
	ctx := context.Background()
	if _, err := m.Apply(ctx, spec); err != nil {
		t.Fatal(err)
	}
	vhost := read(t, sim, "/etc/nginx/sites-available/"+spec.Domain+".conf")
	if !strings.Contains(vhost, "location ^~ /.well-known/acme-challenge/ {\n        root /var/lib/serfex/acme;") || strings.Contains(vhost, "443") {
		t.Fatalf("plain HTTP vhost should answer ACME validation only:\n%s", vhost)
	}
	if !exists(sim, cert.ChallengeDir) {
		t.Fatal("ACME webroot missing")
	}

	certs := cert.NewManager(sim, nil, &cert.Simulated{})
	if _, err := certs.Issue(ctx, cert.IssueSpec{Name: spec.Domain, Names: []string{spec.Domain, spec.Aliases[0]}}); err != nil {
		t.Fatal(err)
	}

	withTLS := spec
	withTLS.TLS = &TLSSpec{Certificate: spec.Domain, Redirect: true}
	res, err := m.Apply(ctx, withTLS)
	if err != nil || !res.Changed {
		t.Fatalf("apply with TLS: %+v %v", res, err)
	}
	vhost = read(t, sim, res.NginxConfig)
	for _, want := range []string{
		"return 301 https://$host$request_uri;",
		"listen 443 ssl http2;",
		"ssl_certificate /etc/serfex/ssl/certs/kahveatolyesi.com.tr/fullchain.pem;",
		"ssl_certificate_key /etc/serfex/ssl/certs/kahveatolyesi.com.tr/privkey.pem;",
		"include /etc/serfex/nginx/tls.conf;",
	} {
		if !strings.Contains(vhost, want) {
			t.Errorf("vhost lacks %q:\n%s", want, vhost)
		}
	}
	if strings.Contains(vhost, "ssl_stapling") {
		t.Error("stapling needs an OCSP responder in the certificate")
	}
	// The redirect block still answers validation, so renewals work.
	redirect := vhost[:strings.Index(vhost, "return 301")]
	if !strings.Contains(redirect, "/.well-known/acme-challenge/") {
		t.Errorf("redirect server lacks the ACME location:\n%s", vhost)
	}
	if strings.Count(vhost, "listen 80;") != 1 {
		t.Errorf("port 80 must be served once:\n%s", vhost)
	}
	if conf := read(t, sim, cert.TLSConf); !strings.Contains(conf, "ssl_protocols TLSv1.2 TLSv1.3;") {
		t.Errorf("tls.conf:\n%s", conf)
	}

	// Without the redirect, one server answers both ports.
	withTLS.TLS = &TLSSpec{Certificate: spec.Domain}
	if _, err := m.Apply(ctx, withTLS); err != nil {
		t.Fatal(err)
	}
	vhost = read(t, sim, res.NginxConfig)
	if strings.Contains(vhost, "return 301") || strings.Count(vhost, "server {") != 1 || !strings.Contains(vhost, "listen 80;") {
		t.Errorf("vhost without redirect:\n%s", vhost)
	}

	// The certificate cannot be deleted while the site uses it.
	if _, err := certs.Delete(spec.Domain); err == nil {
		t.Fatal("certificate in use was deleted")
	}

	// Back to HTTP only.
	if _, err := m.Apply(ctx, spec); err != nil {
		t.Fatal(err)
	}
	if vhost = read(t, sim, res.NginxConfig); strings.Contains(vhost, "443") {
		t.Errorf("TLS left in vhost:\n%s", vhost)
	}
}

func TestApplyRefusesMissingCertificate(t *testing.T) {
	m, sim := newTest(t)
	s := spec
	s.TLS = &TLSSpec{Certificate: "nope.com"}
	_, err := m.Apply(context.Background(), s)
	var v *ValidationError
	if !errors.As(err, &v) || !strings.Contains(err.Error(), "not installed") {
		t.Fatalf("err = %v", err)
	}
	if exists(sim, "/etc/nginx/sites-available/"+spec.Domain+".conf") {
		t.Fatal("vhost written for a failed apply")
	}
}
