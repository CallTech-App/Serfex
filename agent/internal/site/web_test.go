package site

import (
	"context"
	"errors"
	"strings"
	"testing"
)

func TestProxyAndWebSockets(t *testing.T) {
	m, sim := newTest(t)
	s := spec
	s.WebRoot = "laravel/current/public"
	s.Proxy = &ProxySpec{Port: 20001}
	s.WebSockets = []WebSocketSpec{{Path: "/app/", Port: 20002}, {Path: "/apps/", Port: 20002}}
	res, err := m.Apply(context.Background(), s)
	if err != nil {
		t.Fatal(err)
	}
	vhost := read(t, sim, res.NginxConfig)
	for _, want := range []string{
		"try_files $uri @app;",
		"proxy_pass http://127.0.0.1:20001;",
		"location ^~ /app/ {\n        proxy_pass http://127.0.0.1:20002;",
		"location ^~ /apps/ {",
		"proxy_set_header Connection $servik_connection_upgrade;",
	} {
		if !strings.Contains(vhost, want) {
			t.Errorf("vhost lacks %q:\n%s", want, vhost)
		}
	}
	if strings.Contains(vhost, "fastcgi_pass") {
		t.Errorf("an Octane site must not pass PHP to PHP-FPM:\n%s", vhost)
	}
	if conf := read(t, sim, websocketConf); !strings.Contains(conf, "map $http_upgrade $servik_connection_upgrade") {
		t.Errorf("websocket map:\n%s", conf)
	}

	// Without Octane, PHP-FPM serves the site again; Reverb stays.
	s.Proxy = nil
	res, err = m.Apply(context.Background(), s)
	if err != nil {
		t.Fatal(err)
	}
	vhost = read(t, sim, res.NginxConfig)
	if !strings.Contains(vhost, "fastcgi_pass unix:/run/php/servik-kahveatolyesi.com.tr.sock;") || strings.Contains(vhost, "@app") || !strings.Contains(vhost, "/app/") {
		t.Errorf("vhost:\n%s", vhost)
	}

	for _, bad := range []Spec{
		{Proxy: &ProxySpec{Port: 80}},
		{WebSockets: []WebSocketSpec{{Path: "/", Port: 20002}}},
		{WebSockets: []WebSocketSpec{{Path: "/app", Port: 20002}}},
		{WebSockets: []WebSocketSpec{{Path: "/a b/", Port: 20002}}},
		{WebSockets: []WebSocketSpec{{Path: "/app/", Port: 20002}, {Path: "/app/", Port: 20003}}},
	} {
		b := spec
		b.Proxy, b.WebSockets = bad.Proxy, bad.WebSockets
		var v *ValidationError
		if _, err := m.Apply(context.Background(), b); !errors.As(err, &v) {
			t.Errorf("%+v: %v", bad, err)
		}
	}
}

func TestMaintenance(t *testing.T) {
	m, sim := newTest(t)
	s := spec
	s.Maintenance = &MaintenanceSpec{Allow: []string{"78.186.41.12", "10.0.0.7/24", "2001:db8::1"}, Secret: "bakim-a7f3k9x2", Retry: 60}
	res, err := m.Apply(context.Background(), s)
	if err != nil {
		t.Fatal(err)
	}
	vhost := read(t, sim, res.NginxConfig)
	for _, want := range []string{
		"geo $servik_maint_ip_kahveatolyesi_dcom_dtr {\n    default 1;\n    78.186.41.12/32 0;\n    10.0.0.0/24 0;\n    2001:db8::1/128 0;\n}",
		`"bakim-a7f3k9x2" 0;`,
		`"/bakim-a7f3k9x2" 0;`,
		`"~^/\.well-known/acme-challenge/" 0;`,
		"error_page 503 @servik_maintenance;",
		"try_files /kahveatolyesi.com.tr.html =503;",
		"add_header Retry-After 60 always;",
		"location = /bakim-a7f3k9x2 {",
		"if ($servik_maint_kahveatolyesi_dcom_dtr) {\n        return 503;",
	} {
		if !strings.Contains(vhost, want) {
			t.Errorf("vhost lacks %q:\n%s", want, vhost)
		}
	}
	if strings.Contains(vhost, "; Secure") {
		t.Error("the bypass cookie of an HTTP-only site cannot be Secure")
	}
	page := read(t, sim, maintenancePage(s.Domain))
	if !strings.Contains(page, "Managed by Servik") || !strings.Contains(page, "<strong>kahveatolyesi.com.tr</strong>") {
		t.Errorf("default page:\n%s", page)
	}

	s.Maintenance = &MaintenanceSpec{HTML: "<h1>Yakında</h1>"}
	if _, err := m.Apply(context.Background(), s); err != nil {
		t.Fatal(err)
	}
	if page := read(t, sim, maintenancePage(s.Domain)); !strings.HasSuffix(page, "<h1>Yakında</h1>") {
		t.Errorf("custom page:\n%s", page)
	}
	if vhost := read(t, sim, res.NginxConfig); strings.Contains(vhost, "location = /") || !strings.Contains(vhost, "geo $servik_maint_ip_kahveatolyesi_dcom_dtr {\n    default 1;\n}") {
		t.Errorf("vhost without secret or addresses:\n%s", vhost)
	}

	s.Maintenance = nil
	if _, err := m.Apply(context.Background(), s); err != nil {
		t.Fatal(err)
	}
	if exists(sim, maintenancePage(s.Domain)) || strings.Contains(read(t, sim, res.NginxConfig), "servik_maint") {
		t.Error("maintenance must be gone")
	}

	for _, bad := range []MaintenanceSpec{{Allow: []string{"bogus"}}, {Secret: "short"}, {Secret: "has space here"}, {Retry: -1}} {
		b := spec
		b.Maintenance = &bad
		var v *ValidationError
		if _, err := m.Apply(context.Background(), b); !errors.As(err, &v) {
			t.Errorf("%+v: %v", bad, err)
		}
	}
	if maintenanceVar("a-b.com") == maintenanceVar("a.b.com") {
		t.Error("variable names must differ")
	}
}
