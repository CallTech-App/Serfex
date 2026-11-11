package aptrepo

import (
	"context"
	"os"
	"strings"
	"testing"

	"github.com/calltech-app/serfex/agent/internal/aptcache"
	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/txn"
)

var test = Archive{
	Name: "serfex-test", Title: "Test 4.x for Serfex",
	URI: "https://apt.example.org/stable", Host: "apt.example.org", Component: "main",
	KeyURL: "https://apt.example.org/key.asc", Fingerprint: "3FA347D5E599BE4595CA2576FFA232EDBF21E25E",
	Packages: "test*", Why: "Test comes from its own archive.",
}

func TestCheckKeys(t *testing.T) {
	const fpr = "3FA347D5E599BE4595CA2576FFA232EDBF21E25E"
	one := "pub:-:4096:1:FFA232EDBF21E25E:1433861228:::-:::scSC::::::23::0:\nfpr:::::::::" + fpr + ":\nuid:-::::1433861228::X::Test <apt@example.org>::::::::::0:\nsub:-:4096:1:AAAA:1433861228::::::e::::::23:\nfpr:::::::::0000000000000000000000000000000000000001:\n"
	other := "pub:-:4096:1:0000000000000002:1433861228:::-:::scSC::::::23::0:\nfpr:::::::::0000000000000000000000000000000000000002:\n"
	for name, c := range map[string]struct {
		out, fpr string
		ok       bool
	}{
		"the key":                 {one, fpr, true},
		"lower-case fingerprint":  {one, strings.ToLower(fpr), true},
		"another key":             {other, fpr, false},
		"a second key beside it":  {one + other, fpr, false},
		"no key":                  {"", fpr, false},
		"a key without its print": {"pub:-:4096:1:FFA232EDBF21E25E:1433861228:::-:::scSC::::::23::0:\n", fpr, false},
	} {
		if err := checkKeys(c.out, c.fpr); (err == nil) != c.ok {
			t.Errorf("%s: err = %v", name, err)
		}
	}
}

func TestFiles(t *testing.T) {
	src := string(test.SourcesFile("noble"))
	for _, want := range []string{"Types: deb\n", "URIs: https://apt.example.org/stable\n", "Suites: noble\n", "Components: main\n", "Signed-By: /etc/apt/keyrings/serfex-test.asc\n"} {
		if !strings.Contains(src, want) {
			t.Errorf("sources file lacks %q:\n%s", want, src)
		}
	}
	if pin := string(test.PinFile()); !strings.Contains(pin, "Package: test*\nPin: origin apt.example.org\nPin-Priority: 600\n") {
		t.Errorf("pin:\n%s", pin)
	}
	v := test
	v.Versions = "4.*"
	if pin := string(v.PinFile()); !strings.Contains(pin, "Package: test*\nPin: version 4.*\nPin-Priority: 600\n") {
		t.Errorf("version pin:\n%s", pin)
	}
	if !test.Provides(aptcache.Candidate{Version: "4.2.3-1", From: "apt.example.org/stable"}) || test.Provides(aptcache.Candidate{Version: "3.8.1-1ubuntu3", From: "archive.ubuntu.com/ubuntu"}) {
		t.Error("Provides does not tell the archive's packages")
	}
}

func TestAddUndoAndDrop(t *testing.T) {
	sim, err := host.NewSimulator(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	ctx := context.Background()
	key, err := test.FetchKey(ctx, sim, nil, true)
	if err != nil {
		t.Fatal(err)
	}
	tx := txn.New(sim)
	if err := test.Add(ctx, sim, tx, "noble", key, nil); err != nil {
		t.Fatal(err)
	}
	if !test.Listed(sim) {
		t.Fatal("not listed after Add")
	}
	for _, f := range test.Files() {
		if _, err := os.Stat(sim.Path(f)); err != nil {
			t.Errorf("%s: %v", f, err)
		}
	}
	if err := tx.Rollback(ctx); err != nil {
		t.Fatal(err)
	}
	for _, f := range test.Files() {
		if _, err := os.Stat(sim.Path(f)); err == nil {
			t.Errorf("%s left after the undo", f)
		}
	}
	// The lists were refreshed after the files went in and again after
	// they went.
	n := 0
	for _, c := range sim.Commands() {
		if c.String() == "apt-get update -q" {
			n++
		}
	}
	if n != 2 {
		t.Errorf("apt-get update ran %d times", n)
	}

	if err := test.Add(ctx, sim, txn.New(sim), "noble", key, nil); err != nil {
		t.Fatal(err)
	}
	if err := test.Drop(ctx, sim, nil); err != nil {
		t.Fatal(err)
	}
	if test.Listed(sim) {
		t.Error("listed after Drop")
	}
}

func TestKeptPackages(t *testing.T) {
	sim, err := host.NewSimulator(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	ctx := context.Background()
	dir := "/var/lib/serfex/test/packages"
	if err := SaveDebs(ctx, sim, dir, map[string]string{"test": "3.8.1-1ubuntu3", "test-common": "3.8.1-1ubuntu3"}, nil); err != nil {
		t.Fatal(err)
	}
	if !sim.Ran("env -C " + dir + " apt-get download -q test-common=3.8.1-1ubuntu3 test=3.8.1-1ubuntu3") {
		t.Errorf("commands: %v", sim.Commands())
	}
	if err := SaveDebs(ctx, sim, dir, map[string]string{"test": ""}, nil); err == nil {
		t.Error("kept a package that is not installed")
	}
	if err := InstallDebs(ctx, sim, dir, nil); err != nil {
		t.Fatal(err)
	}
	if !sim.Ran("dpkg --force-confdef --force-confold -i " + dir + "/test-common_3.8.1-1ubuntu3_amd64.deb " + dir + "/test_3.8.1-1ubuntu3_amd64.deb") {
		t.Errorf("commands: %v", sim.Commands())
	}
	if err := InstallDebs(ctx, sim, "/var/lib/serfex/none", nil); err == nil {
		t.Error("installed from an empty directory")
	}
}
