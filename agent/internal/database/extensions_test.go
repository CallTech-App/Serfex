package database

import (
	"context"
	"encoding/json"
	"errors"
	"os"
	"strings"
	"testing"

	"github.com/calltech-app/serfex/agent/internal/host"
)

func TestExtensions(t *testing.T) {
	m, sim := newSim(t)
	ctx := context.Background()
	if _, err := m.Install(ctx, InstallSpec{Product: PostgreSQL}); err != nil {
		t.Fatal(err)
	}
	// The server comes with pgvector's package.
	if !sim.Ran("apt-get install -y -q --no-install-recommends postgresql-18-pgvector") {
		t.Errorf("commands: %v", sim.Commands())
	}
	if got := m.ExtensionPackages(); len(got) != 1 || got[0] != "postgresql-18-pgvector" {
		t.Errorf("extension packages %v", got)
	}
	if _, err := m.CreateDatabase(ctx, DatabaseSpec{Server: PostgreSQL, Name: "acme_shop"}); err != nil {
		t.Fatal(err)
	}
	ref := ExtensionRef{Server: PostgreSQL, Database: "acme_shop"}
	list, err := m.Extensions(ctx, ref)
	if err != nil {
		t.Fatal(err)
	}
	if len(list) != 1 || list[0] != (Extension{Name: "vector", Available: "0.8.1"}) {
		t.Fatalf("extensions %+v", list)
	}

	// A package removed by hand comes back when the extension is turned on.
	if _, err := sim.Run(ctx, host.Command{Name: "apt-get", Args: []string{"purge", "-y", "postgresql-18-pgvector"}}); err != nil {
		t.Fatal(err)
	}
	x, err := m.SetExtension(ctx, ExtensionSpec{Server: PostgreSQL, Database: "acme_shop", Name: "vector", Enabled: true})
	if err != nil {
		t.Fatal(err)
	}
	if x != (Extension{Name: "vector", Enabled: true, Version: "0.8.1", Available: "0.8.1"}) {
		t.Errorf("enabled %+v", x)
	}
	n := 0
	for _, c := range sim.Commands() {
		if c.String() == "apt-get install -y -q --no-install-recommends postgresql-18-pgvector" {
			n++
		}
	}
	if n != 2 {
		t.Errorf("package installs %d: %v", n, sim.Commands())
	}
	// Turning it on twice changes nothing.
	if _, err := m.SetExtension(ctx, ExtensionSpec{Server: PostgreSQL, Database: "acme_shop", Name: "vector", Enabled: true}); err != nil {
		t.Fatal(err)
	}

	// Columns that use it keep it on.
	if err := markInUse(sim.Path("/var/lib/serfex-sim/databases-postgresql.json"), "acme_shop/vector"); err != nil {
		t.Fatal(err)
	}
	var v *ValidationError
	if _, err := m.SetExtension(ctx, ExtensionSpec{Server: PostgreSQL, Database: "acme_shop", Name: "vector"}); !errors.As(err, &v) || !strings.Contains(err.Error(), "remove them first") {
		t.Errorf("drop while in use: %v", err)
	}
	if err := markInUse(sim.Path("/var/lib/serfex-sim/databases-postgresql.json"), ""); err != nil {
		t.Fatal(err)
	}
	x, err = m.SetExtension(ctx, ExtensionSpec{Server: PostgreSQL, Database: "acme_shop", Name: "vector"})
	if err != nil || x.Enabled {
		t.Errorf("disabled %+v %v", x, err)
	}
}

func TestExtensionRefusals(t *testing.T) {
	m, _ := newSim(t)
	ctx := context.Background()
	if _, err := m.Extensions(ctx, ExtensionRef{Server: PostgreSQL, Database: "acme_shop"}); !errors.Is(err, ErrNotInstalled) {
		t.Errorf("before install: %v", err)
	}
	if _, err := m.Install(ctx, InstallSpec{Product: PostgreSQL}); err != nil {
		t.Fatal(err)
	}
	for _, c := range []struct {
		spec ExtensionSpec
		want string
	}{
		{ExtensionSpec{Server: PostgreSQL, Database: "acme_shop", Name: "plpython3u", Enabled: true}, "not offered"},
		{ExtensionSpec{Server: MySQL, Database: "acme_shop", Name: "vector", Enabled: true}, "PostgreSQL only"},
		{ExtensionSpec{Server: PostgreSQL, Database: "postgres", Name: "vector", Enabled: true}, "reserved"},
		{ExtensionSpec{Server: PostgreSQL, Database: "acme_none", Name: "vector", Enabled: true}, "does not exist"},
	} {
		var v *ValidationError
		if _, err := m.SetExtension(ctx, c.spec); !errors.As(err, &v) || !strings.Contains(err.Error(), c.want) {
			t.Errorf("%+v: %v", c.spec, err)
		}
	}
}

func TestPackagedVersion(t *testing.T) {
	m, sim := newSim(t)
	f := sim.Path(controlFile("18", "vector"))
	if err := os.MkdirAll(f[:strings.LastIndex(f, "/")], 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(f, []byte("comment = 'vector data type'\ndefault_version = '0.8.1'\nmodule_pathname = '$libdir/vector'\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	if got := m.packagedVersion("18", "vector"); got != "0.8.1" {
		t.Errorf("version %q", got)
	}
	if got := m.packagedVersion("17", "vector"); got != "" {
		t.Errorf("missing package: %q", got)
	}
}

// markInUse makes the simulated server report what depends on an
// extension ("<database>/<extension>"; "" for nothing).
func markInUse(file, what string) error {
	b, err := os.ReadFile(file)
	if err != nil {
		return err
	}
	var d simData
	if err := json.Unmarshal(b, &d); err != nil {
		return err
	}
	d.InUse = nil
	if what != "" {
		d.InUse = []string{what}
	}
	b, _ = json.Marshal(d)
	return os.WriteFile(file, b, 0o600)
}
