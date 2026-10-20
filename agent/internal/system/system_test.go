package system

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"

	"github.com/winchester37/servik/agent/internal/host"
)

func newSim(t *testing.T) (*Manager, *host.Simulator) {
	t.Helper()
	sim, err := host.NewSimulator(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	return NewManager(sim), sim
}

func write(t *testing.T, sim *host.Simulator, abs, content string) {
	t.Helper()
	if err := os.MkdirAll(filepath.Dir(sim.Path(abs)), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(sim.Path(abs), []byte(content), 0o644); err != nil {
		t.Fatal(err)
	}
}

func TestHostsWithName(t *testing.T) {
	for _, tt := range []struct{ name, in, host, want string }{
		{"replaces", "127.0.0.1 localhost\n127.0.1.1 old.example.com old\n::1 ip6-localhost\n", "srv1.example.com",
			"127.0.0.1 localhost\n127.0.1.1\tsrv1.example.com srv1\n::1 ip6-localhost\n"},
		{"adds after localhost", "# hosts\n127.0.0.1 localhost\n::1 ip6-localhost\n", "srv1",
			"# hosts\n127.0.0.1 localhost\n127.0.1.1\tsrv1\n::1 ip6-localhost\n"},
		{"empty", "", "a.example.com", "127.0.1.1\ta.example.com a\n"},
		{"keeps one line", "127.0.1.1 a\n127.0.0.1 localhost\n127.0.1.1 b\n", "c.example.com",
			"127.0.1.1\tc.example.com c\n127.0.0.1 localhost\n"},
	} {
		if got := HostsWithName(tt.in, tt.host); got != tt.want {
			t.Errorf("%s: got %q, want %q", tt.name, got, tt.want)
		}
	}
}

func TestValidateHostname(t *testing.T) {
	for name, ok := range map[string]bool{
		"srv1": true, "srv-ist-01.servik.test": true, "a.example.com": true,
		"": false, "-srv": false, "srv_1": false, "Srv": false, "a..b.com": false, "a b": false,
		strings.Repeat("a", 64): false, "srv.example.c0m": false,
	} {
		err := ValidateHostname(name)
		var v *ValidationError
		if ok && err != nil || !ok && !errors.As(err, &v) {
			t.Errorf("%q: %v", name, err)
		}
	}
}

func TestSimulatedInfoHostnameAndTimezone(t *testing.T) {
	m, sim := newSim(t)
	ctx := context.Background()
	in := m.Info()
	if in.Hostname != simHostname || in.FQDN != simHostname || in.OS != simOS || in.CPUs != 8 || in.MemoryBytes != 32<<30 || in.Timezone != "Europe/Istanbul" || in.UptimeSeconds < simUptime {
		t.Fatalf("info %+v", in)
	}

	write(t, sim, hostsFile, "127.0.0.1 localhost\n127.0.1.1 old old\n")
	if res, err := m.SetHostname(ctx, HostnameSpec{Hostname: "Panel.Example.COM."}); err != nil || res["hostname"] != "panel.example.com" {
		t.Fatalf("set %v %v", res, err)
	}
	if !sim.Ran("hostnamectl set-hostname panel.example.com") {
		t.Fatal("hostnamectl not run")
	}
	hosts, _ := os.ReadFile(sim.Path(hostsFile))
	if string(hosts) != "127.0.0.1 localhost\n127.0.1.1\tpanel.example.com panel\n" {
		t.Fatalf("hosts %q", hosts)
	}
	if in := m.Info(); in.Hostname != "panel.example.com" {
		t.Fatalf("info after set %+v", in)
	}

	// hostnamectl failing puts /etc/hosts back.
	sim.Fail = func(c host.Command) error {
		if c.Name == "hostnamectl" {
			return errors.New("Could not set hostname")
		}
		return nil
	}
	var op *OperationError
	if _, err := m.SetHostname(ctx, HostnameSpec{Hostname: "other"}); !errors.As(err, &op) {
		t.Fatalf("err = %v", err)
	}
	if after, _ := os.ReadFile(sim.Path(hostsFile)); string(after) != string(hosts) {
		t.Fatalf("hosts not rolled back: %q", after)
	}
	sim.Fail = nil
	var v *ValidationError
	if _, err := m.SetHostname(ctx, HostnameSpec{Hostname: "bad_name"}); !errors.As(err, &v) {
		t.Fatalf("err = %v", err)
	}

	// Without a time zone database a dry run knows a few zones.
	zones, err := m.Timezones()
	if err != nil || !reflect.DeepEqual(zones, simZones) {
		t.Fatalf("zones %v %v", zones, err)
	}
	if res, err := m.SetTimezone(ctx, TimezoneSpec{Timezone: "Europe/Berlin"}); err != nil || res["timezone"] != "Europe/Berlin" {
		t.Fatalf("set %v %v", res, err)
	}
	if !sim.Ran("timedatectl set-timezone Europe/Berlin") || m.Info().Timezone != "Europe/Berlin" {
		t.Fatal("time zone not set")
	}
	for _, tz := range []string{"Mars/Olympus", "../etc/passwd", "Europe/../../etc", "Europe/Berlin;rm", ""} {
		if _, err := m.SetTimezone(ctx, TimezoneSpec{Timezone: tz}); !errors.As(err, &v) {
			t.Errorf("%q: %v", tz, err)
		}
	}
}

func TestTimezonesFromDatabase(t *testing.T) {
	m, sim := newSim(t)
	tzif := "TZif2\x00\x00\x00"
	for _, z := range []string{"UTC", "Europe/Istanbul", "America/Argentina/Buenos_Aires", "US/Eastern", "posix/Europe/Berlin", "Etc/GMT+3"} {
		write(t, sim, ZoneInfo+"/"+z, tzif)
	}
	write(t, sim, ZoneInfo+"/Europe/notes.txt~", "x")
	write(t, sim, ZoneInfo+"/Asia/Fake", "not a zone file")
	zones, err := m.Timezones()
	want := []string{"America/Argentina/Buenos_Aires", "Asia/Fake", "Europe/Istanbul", "UTC"}
	if err != nil || !reflect.DeepEqual(zones, want) {
		t.Fatalf("zones %v %v", zones, err)
	}
	ctx := context.Background()
	if _, err := m.SetTimezone(ctx, TimezoneSpec{Timezone: "America/Argentina/Buenos_Aires"}); err != nil {
		t.Fatal(err)
	}
	var v *ValidationError
	if _, err := m.SetTimezone(ctx, TimezoneSpec{Timezone: "Asia/Fake"}); !errors.As(err, &v) {
		t.Fatalf("a file that is not a zone: %v", err)
	}
	// The database is there, so the dry run's own list no longer counts.
	if _, err := m.SetTimezone(ctx, TimezoneSpec{Timezone: "Asia/Dubai"}); !errors.As(err, &v) {
		t.Fatalf("zone missing from the database: %v", err)
	}
}

func TestRealInfoReaders(t *testing.T) {
	dir := t.TempDir()
	osr := filepath.Join(dir, "os-release")
	os.WriteFile(osr, []byte("NAME=\"Ubuntu\"\nPRETTY_NAME=\"Ubuntu 24.04.3 LTS\"\nID=ubuntu\n"), 0o644)
	if got := osRelease(osr); got != "Ubuntu 24.04.3 LTS" {
		t.Errorf("os-release %q", got)
	}
	mem := filepath.Join(dir, "meminfo")
	os.WriteFile(mem, []byte("MemTotal:       16303428 kB\nMemFree:  1 kB\n"), 0o644)
	if got := memTotal(mem); got != 16303428*1024 {
		t.Errorf("memory %d", got)
	}

	sim, _ := host.NewSimulator(t.TempDir())
	m := NewManager(sim)
	write(t, sim, hostsFile, "127.0.0.1 localhost\n127.0.1.1 srv1.example.com srv1\n")
	if got := m.fqdn("srv1"); got != "srv1.example.com" {
		t.Errorf("fqdn %q", got)
	}
	if err := os.Symlink("/usr/share/zoneinfo/Asia/Tokyo", sim.Path(localtime)); err != nil {
		t.Fatal(err)
	}
	if got := m.timezone(); got != "Asia/Tokyo" {
		t.Errorf("timezone %q", got)
	}
}
