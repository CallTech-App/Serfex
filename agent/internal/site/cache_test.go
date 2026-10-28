package site

import (
	"context"
	"errors"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
)

func TestPageCache(t *testing.T) {
	m, sim := newTest(t)
	s := spec
	s.Cache = &CacheSpec{Page: true, PageMinutes: 10, Exclude: []string{"/shop/cart"}, Browser: true, BrowserDays: 30, Gzip: true}
	s.NoIndex = true
	res, err := m.Apply(context.Background(), s)
	if err != nil {
		t.Fatal(err)
	}
	vhost := read(t, sim, res.NginxConfig)
	for _, want := range []string{
		"fastcgi_cache_path /var/cache/serfex/nginx/kahveatolyesi.com.tr levels=1:2 keys_zone=serfex_kahveatolyesi_dcom_dtr:10m max_size=256m inactive=1d use_temp_path=off;",
		"map $http_cookie $serfex_cache_cookie_kahveatolyesi_dcom_dtr {",
		`"~^/wp-admin/" 1;`,
		`"~^/shop/cart" 1;`,
		`"~^/wp-login\.php" 1;`,
		"fastcgi_cache serfex_kahveatolyesi_dcom_dtr;",
		"fastcgi_cache_valid 200 301 302 10m;",
		"fastcgi_no_cache $serfex_cache_skip_kahveatolyesi_dcom_dtr;",
		"add_header X-Serfex-Cache $upstream_cache_status;",
		`"~^image/" 30d;`,
		"expires $serfex_expires_kahveatolyesi_dcom_dtr;",
		"gzip_types text/plain text/css",
		`add_header X-Robots-Tag "noindex, nofollow" always;`,
	} {
		if !strings.Contains(vhost, want) {
			t.Errorf("vhost lacks %q:\n%s", want, vhost)
		}
	}
	fi, err := os.Stat(sim.Path(CacheDir(s.Domain)))
	if err != nil || fi.Mode().Perm() != 0o700 {
		t.Fatalf("cache dir: %v %v", fi, err)
	}
	nginxCheck(t, sim, vhost)

	// Behind Apache the proxied requests are cached instead.
	installApache(t, sim)
	s.WebServer = WebApache
	if res, err = m.Apply(context.Background(), s); err != nil {
		t.Fatal(err)
	}
	vhost = read(t, sim, res.NginxConfig)
	if !strings.Contains(vhost, "proxy_cache_path /var/cache/serfex/nginx/") || strings.Count(vhost, "proxy_cache serfex_kahveatolyesi_dcom_dtr;") != 2 || strings.Contains(vhost, "fastcgi_cache") {
		t.Errorf("apache vhost:\n%s", vhost)
	}
	nginxCheck(t, sim, vhost)

	// Cached pages are measured and purged.
	if err := os.MkdirAll(sim.Path(CacheDir(s.Domain)+"/a/bc"), 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(sim.Path(CacheDir(s.Domain)+"/a/bc/0123"), []byte("cached page"), 0o600); err != nil {
		t.Fatal(err)
	}
	if u, err := m.CacheStatus(s.Domain); err != nil || u.Files != 1 || u.Bytes != 11 {
		t.Fatalf("status = %+v, %v", u, err)
	}
	if u, err := m.PurgeCache(s.Domain); err != nil || u.Files != 1 {
		t.Fatalf("purge = %+v, %v", u, err)
	}
	if u, _ := m.CacheStatus(s.Domain); u.Files != 0 || !exists(sim, CacheDir(s.Domain)) {
		t.Fatalf("after purge = %+v", u)
	}

	// Off again: nothing is cached and the kept pages go.
	s.WebServer, s.Cache, s.NoIndex = "", &CacheSpec{Gzip: true}, false
	if res, err = m.Apply(context.Background(), s); err != nil {
		t.Fatal(err)
	}
	vhost = read(t, sim, res.NginxConfig)
	if strings.Contains(vhost, "cache") || strings.Contains(vhost, "X-Robots-Tag") || !strings.Contains(vhost, "gzip on;") {
		t.Errorf("vhost:\n%s", vhost)
	}
	if exists(sim, CacheDir(s.Domain)) {
		t.Error("the cache directory is still there")
	}

	// An Octane site keeps no pages; a suspended one neither.
	s.Cache = &CacheSpec{Page: true, PageMinutes: 5}
	s.WebRoot, s.Proxy = "laravel/current/public", &ProxySpec{Port: 20001}
	if res, err = m.Apply(context.Background(), s); err != nil {
		t.Fatal(err)
	}
	if vhost = read(t, sim, res.NginxConfig); strings.Contains(vhost, "cache") {
		t.Errorf("octane vhost:\n%s", vhost)
	}

	for _, bad := range []*CacheSpec{
		{Page: true, PageMinutes: 0},
		{Browser: true, BrowserDays: 400},
		{Exclude: []string{"cart"}},
		{Exclude: []string{"/a b"}},
		{Exclude: []string{`/a"b`}},
		{Exclude: []string{"/../x"}},
	} {
		b := spec
		b.Cache = bad
		var v *ValidationError
		if _, err := m.Apply(context.Background(), b); !errors.As(err, &v) {
			t.Errorf("%+v: %v", bad, err)
		}
	}
}

// nginxCheck runs nginx -t on the vhost when Nginx is installed, with the
// server's paths moved into a scratch directory and port 80 moved to a high
// port (nginx -t binds the ports, which needs root below 1024).
func nginxCheck(t *testing.T, sim interface{ Path(string) string }, vhost string) {
	t.Helper()
	bin, err := exec.LookPath("nginx")
	if err != nil {
		return
	}
	dir := t.TempDir()
	vhost = strings.NewReplacer(
		"/var/cache/serfex/", dir+"/cache/",
		"/var/log/serfex/", dir+"/log/",
		"/etc/serfex/", dir+"/etc/",
		"/var/lib/serfex/", dir+"/lib/",
		"/home/", dir+"/home/",
		"include fastcgi_params;", "include /etc/nginx/fastcgi_params;",
		"listen 80;", "listen 127.0.0.1:18080;",
		"listen [::]:80;", "",
	).Replace(vhost)
	for _, d := range []string{"cache/nginx", "log/sites/kahveatolyesi.com.tr", "etc"} {
		if err := os.MkdirAll(filepath.Join(dir, d), 0o755); err != nil {
			t.Fatal(err)
		}
	}
	conf := "pid " + dir + "/nginx.pid;\nerror_log " + dir + "/error.log;\nevents {}\nhttp {\n    access_log off;\n" +
		"    map $http_upgrade $serfex_connection_upgrade { default upgrade; '' close; }\n" + vhost + "\n}\n"
	file := filepath.Join(dir, "nginx.conf")
	if err := os.WriteFile(file, []byte(conf), 0o644); err != nil {
		t.Fatal(err)
	}
	if out, err := exec.Command(bin, "-t", "-c", file, "-p", dir+"/").CombinedOutput(); err != nil {
		t.Errorf("nginx -t: %v\n%s\n%s", err, out, vhost)
	}
}
