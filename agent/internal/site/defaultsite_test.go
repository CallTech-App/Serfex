package site

import (
	"context"
	"os"
	"strings"
	"testing"
)

func TestApplyWritesDefaultServer(t *testing.T) {
	m, sim := newTest(t)
	ctx := context.Background()
	if err := os.MkdirAll(sim.Path("/etc/nginx/sites-enabled"), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(distroDefaultTarget, sim.Path(distroDefaultLink)); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Apply(ctx, spec); err != nil {
		t.Fatal(err)
	}
	conf := read(t, sim, defaultSiteAvail)
	for _, want := range []string{
		"listen 80 default_server;",
		"listen 443 ssl http2 default_server;",
		"ssl_certificate " + defaultSiteCert + ";",
		"include /etc/serfex/nginx/tls.conf;",
		"root " + DefaultSiteDir + ";",
		"location ^~ /.well-known/acme-challenge/ {",
		"ssi on;",
		"return 404;",
	} {
		if !strings.Contains(conf, want) {
			t.Errorf("default server lacks %q:\n%s", want, conf)
		}
	}
	// The "no site" page is not indexed (also from its own location,
	// whose add_header drops the server's), and robots.txt is left to
	// the customers' sites answered here before their certificate.
	if n := strings.Count(conf, `add_header X-Robots-Tag "noindex, nofollow" always;`); n != 2 || strings.Contains(conf, "location = /robots.txt") {
		t.Errorf("default server X-Robots-Tag %d:\n%s", n, conf)
	}
	if target, err := os.Readlink(sim.Path(defaultSiteEnabled)); err != nil || target != defaultSiteAvail {
		t.Fatalf("default server not enabled: %q %v", target, err)
	}
	if exists(sim, distroDefaultLink) {
		t.Fatal("Ubuntu's default site still claims port 80")
	}
	page := read(t, sim, DefaultSiteDir+"/index.html")
	if !strings.Contains(page, `<!--# echo var="host" default="" -->`) || !strings.Contains(page, "No site at this address") {
		t.Fatalf("default page:\n%s", page)
	}
	fi, err := os.Stat(sim.Path(defaultSiteKey))
	if err != nil || fi.Mode().Perm() != 0o600 {
		t.Fatalf("default key: %v %v", fi, err)
	}
	key := read(t, sim, defaultSiteKey)

	// The certificate is made once; nothing changes on the next apply.
	again, err := m.Apply(ctx, spec)
	if err != nil || again.Changed {
		t.Fatalf("second apply: changed=%v err=%v", again.Changed, err)
	}
	if read(t, sim, defaultSiteKey) != key {
		t.Fatal("the default certificate was made again")
	}
	if changed, err := m.EnsureDefaultSite(ctx); err != nil || changed {
		t.Fatalf("EnsureDefaultSite after apply: changed=%v err=%v", changed, err)
	}
}

func TestEnsureDefaultSite(t *testing.T) {
	m, sim := newTest(t)
	ctx := context.Background()
	// No Nginx: nothing to do.
	if changed, err := m.EnsureDefaultSite(ctx); err != nil || changed || exists(sim, defaultSiteAvail) {
		t.Fatalf("without Nginx: changed=%v err=%v", changed, err)
	}

	for _, d := range []string{"/usr/sbin", "/etc/nginx/sites-enabled"} {
		if err := os.MkdirAll(sim.Path(d), 0o755); err != nil {
			t.Fatal(err)
		}
	}
	if err := os.WriteFile(sim.Path("/usr/sbin/nginx"), nil, 0o755); err != nil {
		t.Fatal(err)
	}
	// A default link the administrator pointed elsewhere is left alone.
	if err := os.Symlink("/etc/nginx/sites-available/mine", sim.Path(distroDefaultLink)); err != nil {
		t.Fatal(err)
	}
	changed, err := m.EnsureDefaultSite(ctx)
	if err != nil || !changed {
		t.Fatalf("EnsureDefaultSite: changed=%v err=%v", changed, err)
	}
	if !exists(sim, defaultSiteEnabled) || !exists(sim, distroDefaultLink) {
		t.Fatal("default server missing, or the administrator's link removed")
	}
	if changed, err := m.EnsureDefaultSite(ctx); err != nil || changed {
		t.Fatalf("second run: changed=%v err=%v", changed, err)
	}
}
