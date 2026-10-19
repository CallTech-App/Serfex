package site

import (
	"context"
	"os"
	"strings"
	"testing"
)

func TestLogsReturnsTheEndOfALog(t *testing.T) {
	m, sim := newTest(t)
	if _, err := m.Apply(context.Background(), spec); err != nil {
		t.Fatal(err)
	}
	access := "/var/log/servik/sites/kahveatolyesi.com.tr/access.log"
	if err := os.WriteFile(sim.Path(access), []byte(strings.Repeat("a", 100)+"\nlast line\n"), 0o640); err != nil {
		t.Fatal(err)
	}
	got, err := m.Logs(LogsSpec{Domain: spec.Domain, User: spec.User, Kind: "access", Bytes: 10})
	if err != nil {
		t.Fatal(err)
	}
	if !got.Exists || got.Content != "last line\n" || !got.Truncated || got.Size != 111 {
		t.Fatalf("unexpected log %+v", got)
	}

	missing, err := m.Logs(LogsSpec{Domain: spec.Domain, User: spec.User, Kind: "php"})
	if err != nil || missing.Exists {
		t.Fatalf("missing php log: %+v, %v", missing, err)
	}
}

func TestLogsRefusesSymlinksInTheCustomersDirectory(t *testing.T) {
	m, sim := newTest(t)
	if _, err := m.Apply(context.Background(), spec); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(sim.Path("/etc/shadow"), []byte("root:secret\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(sim.Path("/etc/shadow"), sim.Path("/home/kahveatolyesi/web/kahveatolyesi.com.tr/logs/php-error.log")); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Logs(LogsSpec{Domain: spec.Domain, User: spec.User, Kind: "php"}); err == nil {
		t.Fatal("a symlinked PHP log was read")
	}

	if _, err := m.Logs(LogsSpec{Domain: spec.Domain, User: spec.User, Kind: "../../etc/shadow"}); err == nil {
		t.Fatal("an unknown kind was accepted")
	}
}
