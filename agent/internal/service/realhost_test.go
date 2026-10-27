//go:build realhost

// This test manages real services and PHP: lists them, stops and starts
// PHP-FPM under a site, refuses a restart with a broken Nginx
// configuration, reads the journal, changes Nginx's body limit, a PHP
// version's php.ini defaults (also on the command line) and a site's own
// PHP settings and pool, switches an extension off and on, and installs
// and removes an optional one. It needs root, systemd and apt, so it only
// builds with -tags realhost:
//
//	sudo go test -tags realhost -run TestRealHost ./internal/service
//
// CI runs it on a throwaway runner with Nginx and PHP-FPM installed.
package service

import (
	"bytes"
	"context"
	"errors"
	"io"
	"net/http"
	"os"
	"strings"
	"testing"
	"time"

	"github.com/calltech-app/serfex/agent/internal/component"
	"github.com/calltech-app/serfex/agent/internal/database"
	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/laravel"
	"github.com/calltech-app/serfex/agent/internal/php"
	"github.com/calltech-app/serfex/agent/internal/site"
	"github.com/calltech-app/serfex/agent/internal/tuning"
)

const (
	rhDomain = "servik-svc.example"
	rhUser   = "servikvc"
)

var fresh = &http.Client{Transport: &http.Transport{DisableKeepAlives: true}, Timeout: 10 * time.Second}

func TestRealHost(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("needs root")
	}
	ctx := context.Background()
	h := host.System{}
	sites := site.NewManager(h, "www-data")
	versions, err := sites.PHPVersions()
	if err != nil || len(versions) == 0 {
		t.Fatalf("no PHP-FPM installed: %v", err)
	}
	v := versions[len(versions)-1]
	fpm := "php" + v + "-fpm"
	pm := php.NewManager(h)
	comps := component.NewManager(h, sites, database.NewManager(h), laravel.NewManager(h))
	comps.PHP = pm
	m := NewManager(h)

	spec := site.Spec{Domain: rhDomain, User: rhUser, PHPVersion: v}
	t.Cleanup(func() {
		_ = os.Remove("/etc/nginx/conf.d/zz-servik-broken.conf")
		defaults := tuning.Defaults().Nginx
		_, _ = m.ApplySettings(context.Background(), SettingsSpec{Unit: "nginx", Nginx: &defaults})
		_, _ = sites.Delete(ctx, site.DeleteSpec{Domain: rhDomain, User: rhUser, RemoveFiles: true, RemoveUser: true})
	})
	res, err := sites.Apply(ctx, spec)
	if err != nil {
		t.Fatal(err)
	}
	writeAs(t, h, rhUser, res.DocumentRoot+"/index.php", `<?php
echo 'mem=', ini_get('memory_limit'), ' up=', ini_get('upload_max_filesize'),
  ' exec=', function_exists('exec') ? 'on' : 'off', ' calendar=', extension_loaded('calendar') ? 'on' : 'off',
  ' apcu=', extension_loaded('apcu') ? 'on' : 'off', ' len=', strlen(file_get_contents('php://input')), "\n";
`)
	expect(t, "GET", 0, 200, "mem=")

	// --- The list ---
	list, err := m.List(ctx)
	if err != nil {
		t.Fatal(err)
	}
	byUnit := map[string]Status{}
	for _, s := range list {
		byUnit[s.Unit] = s
	}
	for _, u := range []string{"nginx", fpm, "ssh"} {
		s, ok := byUnit[u]
		if !ok {
			t.Fatalf("%s missing from the list: %+v", u, list)
		}
		if s.State != "active" || s.Since == nil || s.PID == 0 {
			t.Fatalf("%s: %+v", u, s)
		}
	}
	if byUnit["ssh"].Protected == "" || byUnit["nginx"].Settings != "nginx" || !byUnit["nginx"].CanReload {
		t.Fatalf("catalog facts: ssh %+v nginx %+v", byUnit["ssh"], byUnit["nginx"])
	}

	// --- Stop, start, restart, reload, boot setting ---
	if s, err := m.Do(ctx, ActionSpec{Unit: fpm, Action: "stop"}); err != nil || s.State != "inactive" {
		t.Fatalf("stop: %+v %v", s, err)
	}
	expect(t, "GET", 0, 502, "")
	if s, err := m.Do(ctx, ActionSpec{Unit: fpm, Action: "start"}); err != nil || s.State != "active" {
		t.Fatalf("start: %+v %v", s, err)
	}
	expect(t, "GET", 0, 200, "mem=")
	if _, err := m.Do(ctx, ActionSpec{Unit: "nginx", Action: "reload"}); err != nil {
		t.Fatal(err)
	}
	if s, err := m.Do(ctx, ActionSpec{Unit: fpm, Action: "disable"}); err != nil || s.Enabled != "disabled" {
		t.Fatalf("disable: %+v %v", s, err)
	}
	if s, err := m.Do(ctx, ActionSpec{Unit: fpm, Action: "enable"}); err != nil || s.Enabled != "enabled" {
		t.Fatalf("enable: %+v %v", s, err)
	}
	var verr *ValidationError
	if _, err := m.Do(ctx, ActionSpec{Unit: "ssh", Action: "stop"}); !errors.As(err, &verr) {
		t.Fatalf("stopping ssh: %v", err)
	}

	// A broken configuration: the restart is refused and Nginx keeps
	// serving.
	before := byUnit["nginx"].PID
	if err := os.WriteFile("/etc/nginx/conf.d/zz-servik-broken.conf", []byte("this is not nginx;\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Do(ctx, ActionSpec{Unit: "nginx", Action: "restart"}); !errors.As(err, &verr) || !strings.Contains(err.Error(), "configuration test failed") {
		t.Fatalf("restart with a broken configuration: %v", err)
	}
	_ = os.Remove("/etc/nginx/conf.d/zz-servik-broken.conf")
	expect(t, "GET", 0, 200, "mem=")
	s, err := m.Do(ctx, ActionSpec{Unit: "nginx", Action: "restart"})
	if err != nil || s.State != "active" || s.PID == before {
		t.Fatalf("restart: %+v %v (pid before %d)", s, err, before)
	}

	// --- Logs ---
	logs, err := m.Logs(ctx, LogsSpec{Unit: fpm, Lines: 20})
	if err != nil || len(logs.Lines) == 0 {
		t.Fatalf("logs: %+v %v", logs, err)
	}

	// --- Nginx's body limit ---
	n := tuning.Defaults().Nginx
	n.ClientMaxBodyMB = 1
	if _, err := m.ApplySettings(ctx, SettingsSpec{Unit: "nginx", Nginx: &n}); err != nil {
		t.Fatal(err)
	}
	expect(t, "POST", 2<<20, 413, "")
	n.ClientMaxBodyMB = 64
	n.WorkerConnections = 1024
	got, err := m.ApplySettings(ctx, SettingsSpec{Unit: "nginx", Nginx: &n})
	if err != nil || got.Nginx.WorkerConnections != 1024 || got.Nginx.ClientMaxBodyMB != 64 {
		t.Fatalf("settings: %+v %v", got.Nginx, err)
	}
	expect(t, "POST", 2<<20, 200, "len=2097152")

	// --- A version's php.ini defaults ---
	values := php.Defaults("UTC")
	values["memory_limit"] = "300M"
	info, err := pm.Apply(ctx, php.SettingsSpec{Version: v, Values: values})
	if err != nil || !info.Managed || info.Values["memory_limit"] != "300M" {
		t.Fatalf("php settings: %+v %v", info.Values, err)
	}
	expect(t, "GET", 0, 200, "mem=300M up=64M exec=on")
	// The command line gets the defaults but keeps its own memory limit.
	if out, err := h.Run(ctx, host.Command{Name: "php" + v, Args: []string{"-r", `echo ini_get('memory_limit'), ' ', ini_get('upload_max_filesize'), ' ', ini_get('date.timezone');`}}); err != nil || strings.TrimSpace(out) != "-1 64M UTC" {
		t.Fatalf("command line PHP: %q %v", out, err)
	}
	values["open_basedir"] = "/"
	if _, err := pm.Apply(ctx, php.SettingsSpec{Version: v, Values: values}); err == nil {
		t.Fatal("open_basedir was accepted as a server default")
	}
	expect(t, "GET", 0, 200, "mem=300M")

	// --- The site's own settings and pool ---
	spec.PHP = &site.PHPSpec{
		Values:           map[string]string{"upload_max_filesize": "16M"},
		AdminValues:      map[string]string{"memory_limit": "200M"},
		DisableFunctions: []string{"exec", "shell_exec"},
		PM:               "dynamic", MaxChildren: 4, RequestTimeout: 30,
	}
	if _, err := sites.Apply(ctx, spec); err != nil {
		t.Fatal(err)
	}
	expect(t, "GET", 0, 200, "mem=200M up=16M exec=off")
	pool, _ := os.ReadFile("/etc/php/" + v + "/fpm/pool.d/" + rhDomain + ".conf")
	for _, want := range []string{"pm = dynamic", "pm.max_children = 4", "request_terminate_timeout = 30"} {
		if !bytes.Contains(pool, []byte(want)) {
			t.Fatalf("pool lacks %q:\n%s", want, pool)
		}
	}

	// --- Extensions ---
	if _, err := pm.SetExtension(ctx, php.ExtensionSpec{Version: v, Name: "calendar", Enabled: false}); err != nil {
		t.Fatal(err)
	}
	expect(t, "GET", 0, 200, "calendar=off")
	if _, err := pm.SetExtension(ctx, php.ExtensionSpec{Version: v, Name: "calendar", Enabled: true}); err != nil {
		t.Fatal(err)
	}
	expect(t, "GET", 0, 200, "calendar=on")

	if _, err := comps.Extension(ctx, component.ExtensionSpec{Version: v, Name: "apcu", Run: "rh-ext-apcu"}); err != nil {
		b, _ := os.ReadFile("/var/lib/servik/components/runs/rh-ext-apcu.log")
		t.Fatalf("install apcu: %v\n%s", err, b)
	}
	expect(t, "GET", 0, 200, "apcu=on")
	if _, err := comps.Extension(ctx, component.ExtensionSpec{Version: v, Name: "apcu", Remove: true, Run: "rh-ext-apcu-rm"}); err != nil {
		t.Fatal(err)
	}
	expect(t, "GET", 0, 200, "apcu=off")
}

func writeAs(t *testing.T, h host.Host, user, path, content string) {
	t.Helper()
	if _, err := h.Run(context.Background(), host.AsUser(user, host.Command{Name: "tee", Args: []string{path}, Stdin: []byte(content)})); err != nil {
		t.Fatal(err)
	}
}

// expect requests the site until it answers code with want in the body;
// a POST sends size bytes.
func expect(t *testing.T, method string, size, code int, want string) {
	t.Helper()
	var got int
	var body string
	for i := 0; i < 60; i++ {
		var r io.Reader
		if size > 0 {
			r = bytes.NewReader(bytes.Repeat([]byte("a"), size))
		}
		req, _ := http.NewRequest(method, "http://127.0.0.1/", r)
		req.Host = rhDomain
		if resp, err := fresh.Do(req); err == nil {
			b, _ := io.ReadAll(resp.Body)
			resp.Body.Close()
			got, body = resp.StatusCode, string(b)
			if got == code && strings.Contains(body, want) {
				return
			}
		}
		time.Sleep(250 * time.Millisecond)
	}
	t.Fatalf("%s /: %d %q, want %d with %q", method, got, body, code, want)
}
