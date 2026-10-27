package php

import (
	"context"
	"errors"
	"os"
	"strings"
	"testing"

	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/txn"
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

	// The command line gets them without the web-only limits.
	cli, _ := os.ReadFile(sim.Path(CLIIniFile("8.4")))
	for _, want := range []string{"; Managed by Servik", "date.timezone = Europe/Istanbul\n", "upload_max_filesize = 64M\n"} {
		if !strings.Contains(string(cli), want) {
			t.Errorf("cli ini lacks %q:\n%s", want, cli)
		}
	}
	for _, unwanted := range []string{"memory_limit", "max_execution_time", "max_input_time", "display_errors"} {
		if strings.Contains(string(cli), "\n"+unwanted+" =") {
			t.Errorf("cli ini sets %s:\n%s", unwanted, cli)
		}
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
	if b, _ := os.ReadFile(sim.Path(CLIIniFile("8.4"))); string(b) != string(cli) {
		t.Errorf("cli ini not rolled back:\n%s", b)
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

func TestSyncCLI(t *testing.T) {
	m, sim := newTest(t)
	ctx := context.Background()
	// An older agent wrote the PHP-FPM file only.
	values := Defaults("UTC")
	values["upload_max_filesize"] = "128M"
	if _, err := m.Apply(ctx, SettingsSpec{Version: "8.4", Values: values}); err != nil {
		t.Fatal(err)
	}
	if err := os.Remove(sim.Path(CLIIniFile("8.4"))); err != nil {
		t.Fatal(err)
	}
	// Someone else's file is left alone.
	if err := os.MkdirAll(sim.Path("/etc/php/8.3/fpm/conf.d"), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(sim.Path(IniFile("8.3")), []byte("memory_limit = 1G\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := m.SyncCLI(ctx); err != nil {
		t.Fatal(err)
	}
	b, err := os.ReadFile(sim.Path(CLIIniFile("8.4")))
	if err != nil || !strings.Contains(string(b), "upload_max_filesize = 128M\n") || strings.Contains(string(b), "memory_limit") {
		t.Errorf("cli ini: %v\n%s", err, b)
	}
	if _, err := os.Stat(sim.Path(CLIIniFile("8.3"))); err == nil {
		t.Error("a file Servik does not manage was copied")
	}
	// A second run changes nothing.
	before, _ := os.Stat(sim.Path(CLIIniFile("8.4")))
	if err := m.SyncCLI(ctx); err != nil {
		t.Fatal(err)
	}
	if after, _ := os.Stat(sim.Path(CLIIniFile("8.4"))); !after.ModTime().Equal(before.ModTime()) {
		t.Error("an unchanged file was written again")
	}

	// Reinstalling a version that kept its defaults restores the copy.
	if err := os.Remove(sim.Path(CLIIniFile("8.4"))); err != nil {
		t.Fatal(err)
	}
	if err := m.WriteDefaults(txn.New(sim), "8.4"); err != nil {
		t.Fatal(err)
	}
	if b, _ := os.ReadFile(sim.Path(CLIIniFile("8.4"))); !strings.Contains(string(b), "upload_max_filesize = 128M\n") {
		t.Errorf("after reinstall:\n%s", b)
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
