package monitor

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/calltech-app/serfex/agent/internal/host"
)

// Reading PostgreSQL's connections every sample must not open a login
// session for postgres each time (pam_unix lines every two seconds).
func TestReadDatabasesQueriesPostgresWithoutLoginSession(t *testing.T) {
	sim, err := host.NewSimulator(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	sock := sim.Path(postgresSocket)
	if err := os.MkdirAll(filepath.Dir(sock), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(sock, nil, 0o600); err != nil {
		t.Fatal(err)
	}

	readDatabases(context.Background(), sim)

	var ran []string
	for _, c := range sim.Commands() {
		ran = append(ran, c.String())
	}
	if len(ran) != 1 || !strings.HasPrefix(ran[0], "setpriv --reuid=postgres --regid=postgres --init-groups --reset-env -- psql -XAtq") {
		t.Fatalf("commands = %q, want one psql through setpriv", ran)
	}
}
