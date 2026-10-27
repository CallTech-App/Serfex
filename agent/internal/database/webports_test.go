package database

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"

	"github.com/calltech-app/serfex/agent/internal/panel"
)

func installPanel(t *testing.T, m *Manager) {
	t.Helper()
	sim := m.h
	for abs, content := range map[string]string{
		panel.ConfigFile: `{"php":"8.3"}`,
		panel.ReleasesDir + "/0.1.0/panel/public/index.php": "<?php\n",
	} {
		if err := os.MkdirAll(filepath.Dir(sim.Path(abs)), 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(sim.Path(abs), []byte(content), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	if err := os.Symlink("releases/0.1.0", sim.Path(panel.CurrentLink)); err != nil {
		t.Fatal(err)
	}
}

func TestWebPorts(t *testing.T) {
	m, sim := newSim(t)
	ctx := context.Background()
	if st, err := m.WebPortsStatus(); err != nil || !reflect.DeepEqual(st, WebPorts{Ports: []int{DefaultPort}, Port: DefaultPort}) {
		t.Fatalf("status %+v %v", st, err)
	}

	// Without serfex-web (a development setup) the ports are only kept.
	res, err := m.SetWebPorts(ctx, WebPortsSpec{Ports: []int{9443}})
	if err != nil || res.Port != 9443 || m.WebPort() != 9443 {
		t.Fatalf("set %+v %v (web port %d)", res, err, m.WebPort())
	}
	if _, err := os.Stat(sim.Path(webConf)); !errors.Is(err, os.ErrNotExist) {
		t.Fatal("serfex-web configured without a panel")
	}

	for name, ports := range map[string][]int{
		"none":       {},
		"too many":   {9443, 9444, 9445, 9446},
		"twice":      {9443, 9443},
		"http":       {80},
		"https":      {9443, 443},
		"apache":     {7080},
		"dns api":    {8081},
		"privileged": {9443, 1000},
		"zero":       {0},
		"too big":    {70000},
	} {
		var v *ValidationError
		if _, err := m.SetWebPorts(ctx, WebPortsSpec{Ports: ports}); !errors.As(err, &v) {
			t.Errorf("%s: %v", name, err)
		}
	}

	// With the panel installed, serfex-web listens on every port while a
	// change waits for confirmation.
	installPanel(t, m)
	if _, err := m.SetupPanelWeb(ctx); err != nil {
		t.Fatal(err)
	}
	contains(t, "nginx", read(t, sim, webConf), "listen 9443 ssl http2 default_server;")
	res, err = m.SetWebPorts(ctx, WebPortsSpec{Ports: []int{9443, 10443}})
	if err != nil || !reflect.DeepEqual(res, WebPorts{Ports: []int{9443, 10443}, Port: 9443}) {
		t.Fatalf("set %+v %v", res, err)
	}
	conf := read(t, sim, webConf)
	contains(t, "nginx", conf, "    server {\n        listen 9443 ssl http2 default_server;\n        listen 10443 ssl http2 default_server;\n        ssl_certificate ")
	if !sim.Ran("systemctl restart serfex-php serfex-web") && !sim.Ran("systemctl restart serfex-web") {
		t.Errorf("serfex-web not restarted: %v", sim.Commands())
	}
	if res, err := m.SetWebPorts(ctx, WebPortsSpec{Ports: []int{10443}}); err != nil || res.Port != 10443 || m.WebPort() != 10443 {
		t.Fatalf("confirm %+v %v", res, err)
	}
	if conf := read(t, sim, webConf); strings.Contains(conf, "9443") {
		t.Errorf("old port still configured:\n%s", conf)
	}

	// A port another program listens on is refused.
	m.Listening = func(context.Context) (map[int]bool, error) { return map[int]bool{3000: true}, nil }
	var v *ValidationError
	if _, err := m.SetWebPorts(ctx, WebPortsSpec{Ports: []int{10443, 3000}}); !errors.As(err, &v) || !strings.Contains(err.Error(), "3000") {
		t.Fatalf("busy port: %v", err)
	}
	if m.WebPort() != 10443 {
		t.Fatal("a refused change moved the port")
	}

	// The database manager keeps the ports unless it is given one.
	if _, err := m.Install(ctx, InstallSpec{Product: MySQL}); err != nil {
		t.Fatal(err)
	}
	if ms, err := m.SetupManager(ctx, ManagerSpec{}); err != nil || ms.Port != 10443 {
		t.Fatalf("manager %+v %v", ms, err)
	}
	if ms, err := m.SetupManager(ctx, ManagerSpec{Port: 8443}); err != nil || ms.Port != 8443 {
		t.Fatalf("manager %+v %v", ms, err)
	}
	if st, _ := m.WebPortsStatus(); !reflect.DeepEqual(st.Ports, []int{8443}) {
		t.Fatalf("ports after the manager's setup: %v", st.Ports)
	}
}

func TestParseListeners(t *testing.T) {
	out := `LISTEN 0 511 0.0.0.0:8443 0.0.0.0:* users:(("nginx",pid=900,fd=6),("nginx",pid=901,fd=6))
LISTEN 0 511 0.0.0.0:80 0.0.0.0:* users:(("nginx",pid=700,fd=6))
LISTEN 0 4096 127.0.0.1:3000 0.0.0.0:* users:(("node",pid=1200,fd=20))
LISTEN 0 511 [::]:9443 [::]:* users:(("nginx",pid=900,fd=7))
LISTEN 0 128 [::]:22 [::]:*
tcp LISTEN 0 128 0.0.0.0:5432 0.0.0.0:* users:(("postgres",pid=55,fd=6))
`
	got := parseListeners(out, func(pid int) bool { return pid == 900 || pid == 901 })
	want := map[int]bool{80: true, 3000: true, 22: true, 5432: true}
	if !reflect.DeepEqual(got, want) {
		t.Fatalf("got %v, want %v", got, want)
	}
}
