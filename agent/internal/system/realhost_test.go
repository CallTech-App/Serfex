//go:build realhost

// This test changes the machine's host name and time zone for real with
// hostnamectl and timedatectl, then puts them back. It needs root and
// systemd, so it only builds with -tags realhost:
//
//	sudo go test -tags realhost -run TestRealHost ./internal/system
package system

import (
	"context"
	"os"
	"strings"
	"testing"

	"github.com/calltech-app/serfex/agent/internal/host"
)

func TestRealHost(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("needs root")
	}
	if _, err := os.Stat("/run/systemd/system"); err != nil {
		t.Skip("needs systemd")
	}
	ctx := context.Background()
	m := NewManager(host.System{})

	before := m.Info()
	t.Cleanup(func() {
		_, _ = m.SetHostname(ctx, HostnameSpec{Hostname: before.Hostname})
		_, _ = m.SetTimezone(ctx, TimezoneSpec{Timezone: before.Timezone})
	})

	zones, err := m.Timezones()
	if err != nil {
		t.Fatal(err)
	}
	if !contains(zones, "Europe/Istanbul") || !contains(zones, "UTC") {
		t.Fatalf("time zones lack Europe/Istanbul or UTC: %d zones", len(zones))
	}

	want := "Europe/Istanbul"
	if before.Timezone == want {
		want = "Europe/Berlin"
	}
	if _, err := m.SetTimezone(ctx, TimezoneSpec{Timezone: want}); err != nil {
		t.Fatal(err)
	}
	if got := m.Info().Timezone; got != want {
		t.Fatalf("time zone after set: %q, want %q", got, want)
	}
	if _, err := m.SetTimezone(ctx, TimezoneSpec{Timezone: "Mars/Olympus"}); err == nil {
		t.Fatal("an unknown time zone was accepted")
	}

	if _, err := m.SetHostname(ctx, HostnameSpec{Hostname: "Servik-CI-01.example.com."}); err != nil {
		t.Fatal(err)
	}
	info := m.Info()
	if info.Hostname != "servik-ci-01.example.com" {
		t.Fatalf("host name after set: %q", info.Hostname)
	}
	hosts, _ := os.ReadFile(hostsFile)
	if !strings.Contains(string(hosts), "servik-ci-01.example.com") {
		t.Fatalf("/etc/hosts does not name the new host:\n%s", hosts)
	}
	if _, err := m.SetHostname(ctx, HostnameSpec{Hostname: "bad_name!"}); err == nil {
		t.Fatal("an invalid host name was accepted")
	}
}

func contains(list []string, s string) bool {
	for _, v := range list {
		if v == s {
			return true
		}
	}
	return false
}
