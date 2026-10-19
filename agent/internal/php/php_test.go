package php

import (
	"context"
	"errors"
	"os"
	"strings"
	"testing"

	"github.com/winchester37/servik/agent/internal/host"
)

func newTest(t *testing.T) (*Manager, *host.Simulator) {
	t.Helper()
	sim, err := host.NewSimulator(t.TempDir(), "8.4")
	if err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(sim.Path("/usr/sbin"), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(sim.Path("/usr/sbin/php-fpm8.4"), nil, 0o755); err != nil {
		t.Fatal(err)
	}
	if err := SeedSimulator(sim, "8.4"); err != nil {
		t.Fatal(err)
	}
	return NewManager(sim), sim
}

func TestInfoAndSettings(t *testing.T) {
	m, sim := newTest(t)
	info, err := m.Info("8.4")
	if err != nil {
		t.Fatal(err)
	}
	if !info.Managed || info.Values["memory_limit"] != "256M" || info.Values["upload_max_filesize"] != "64M" || info.Values["opcache.enable"] != "1" {
		t.Errorf("seeded values: %+v", info.Values)
	}
	ext := map[string]Extension{}
	for _, e := range info.Extensions {
		ext[e.Name] = e
	}
	if !ext["intl"].Enabled || !ext["imagick"].Optional || ext["imagick"].Installed || !ext["redis"].Installed {
		t.Errorf("extensions: %+v", info.Extensions)
	}

	values := Defaults("Europe/Istanbul")
	values["memory_limit"] = "512M"
	info, err = m.Apply(context.Background(), SettingsSpec{Version: "8.4", Values: values})
	if err != nil {
		t.Fatal(err)
	}
	if info.Values["memory_limit"] != "512M" || info.Values["date.timezone"] != "Europe/Istanbul" {
		t.Errorf("after apply: %+v", info.Values)
	}
	if !sim.Ran("systemctl reload php8.4-fpm") {
		t.Error("PHP-FPM was not reloaded")
	}
	b, _ := os.ReadFile(sim.Path(IniFile("8.4")))
	if !strings.HasPrefix(string(b), "; Managed by Servik") || !strings.Contains(string(b), "memory_limit = 512M\n") {
		t.Errorf("ini file:\n%s", b)
	}

	// PHP-FPM refusing the settings keeps the previous file.
	sim.Fail = func(c host.Command) error {
		if c.String() == "php-fpm8.4 -t" {
			return errors.New("ERROR: failed to load configuration file")
		}
		return nil
	}
	values["memory_limit"] = "1G"
	if _, err := m.Apply(context.Background(), SettingsSpec{Version: "8.4", Values: values}); err == nil {
		t.Fatal("refused settings were kept")
	}
	sim.Fail = nil
	if b, _ := os.ReadFile(sim.Path(IniFile("8.4"))); !strings.Contains(string(b), "memory_limit = 512M\n") {
		t.Errorf("not rolled back:\n%s", b)
	}

	var v *ValidationError
	for _, bad := range []map[string]string{
		{"open_basedir": "/"},
		{"extension": "evil.so"},
		{"memory_limit": "1G\nextension=evil.so"},
	} {
		if _, err := m.Apply(context.Background(), SettingsSpec{Version: "8.4", Values: bad}); !errors.As(err, &v) {
			t.Errorf("%v: %v", bad, err)
		}
	}
	if _, err := m.Info("7.4"); !errors.As(err, &v) {
		t.Errorf("unknown version: %v", err)
	}
}

func TestExtensions(t *testing.T) {
	m, sim := newTest(t)
	ctx := context.Background()
	info, err := m.SetExtension(ctx, ExtensionSpec{Version: "8.4", Name: "intl", Enabled: false})
	if err != nil {
		t.Fatal(err)
	}
	for _, e := range info.Extensions {
		if e.Name == "intl" && e.Enabled {
			t.Error("intl still enabled")
		}
	}
	if !sim.Ran("phpdismod -v 8.4 intl") || !sim.Ran("systemctl reload php8.4-fpm") {
		t.Errorf("commands: %v", sim.Commands())
	}
	if _, err := m.SetExtension(ctx, ExtensionSpec{Version: "8.4", Name: "intl", Enabled: true}); err != nil {
		t.Fatal(err)
	}
	if !m.enabled("8.4", "intl") {
		t.Error("intl not enabled again")
	}
	var v *ValidationError
	if _, err := m.SetExtension(ctx, ExtensionSpec{Version: "8.4", Name: "imagick", Enabled: true}); !errors.As(err, &v) {
		t.Errorf("not installed extension: %v", err)
	}
}
