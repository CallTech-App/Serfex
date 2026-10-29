package aptcache

import (
	"reflect"
	"testing"
)

// Shaped after Ubuntu 24.04's "LC_ALL=C apt-get -s upgrade --with-new-pkgs".
const simulation = `NOTE: This is only a simulation!
      apt-get needs root privileges for real execution.
      Keep also in mind that locking is deactivated,
      so don't depend on the relevance to the real current situation!
Reading package lists...
Building dependency tree...
Reading state information...
Calculating upgrade...
The following NEW packages will be installed:
  linux-image-6.8.0-90-generic linux-modules-6.8.0-90-generic
The following packages have been kept back:
  ubuntu-drivers-common
  netplan.io
The following upgrades have been deferred due to phasing:
  python3-distupgrade ubuntu-release-upgrader-core
The following packages will be upgraded:
  curl libssl3t64 linux-generic php8.4-fpm postgresql-18
5 upgraded, 2 newly installed, 0 to remove and 4 not upgraded.
Inst libssl3t64 [3.0.13-0ubuntu3.5] (3.0.13-0ubuntu3.6 Ubuntu:24.04/noble-updates, Ubuntu:24.04/noble-security [amd64])
Inst curl [8.5.0-2ubuntu10.6] (8.5.0-2ubuntu10.7 Ubuntu:24.04/noble-updates [amd64]) []
Inst linux-modules-6.8.0-90-generic (6.8.0-90.91 Ubuntu:24.04/noble-updates, Ubuntu:24.04/noble-security [amd64])
Inst linux-image-6.8.0-90-generic (6.8.0-90.91 Ubuntu:24.04/noble-updates, Ubuntu:24.04/noble-security [amd64])
Inst linux-generic [6.8.0-88.89] (6.8.0-90.91 Ubuntu:24.04/noble-updates, Ubuntu:24.04/noble-security [amd64])
Inst php8.4-fpm [8.4.13-1+ubuntu24.04.1+deb.sury.org+1] (8.4.14-1+ubuntu24.04.1+deb.sury.org+1 LP-PPA-ondrej-php:24.04/noble [amd64])
Inst postgresql-18 [18.1-1.pgdg24.04+2] (18.2-1.pgdg24.04+1 apt.postgresql.org:24.04-pgdg/noble-pgdg [amd64])
Inst tzdata [2025b-0ubuntu0.24.04.1] (2025b-0ubuntu0.24.04.2 Ubuntu:24.04/noble-updates [all])
Conf libssl3t64 (3.0.13-0ubuntu3.6 Ubuntu:24.04/noble-updates, Ubuntu:24.04/noble-security [amd64])
Conf curl (8.5.0-2ubuntu10.7 Ubuntu:24.04/noble-updates [amd64])
`

func TestSimulation(t *testing.T) {
	p := Simulation(simulation)
	want := []Change{
		{Name: "libssl3t64", Installed: "3.0.13-0ubuntu3.5", Version: "3.0.13-0ubuntu3.6", Origin: "Ubuntu", Security: true},
		{Name: "curl", Installed: "8.5.0-2ubuntu10.6", Version: "8.5.0-2ubuntu10.7", Origin: "Ubuntu"},
		{Name: "linux-modules-6.8.0-90-generic", Version: "6.8.0-90.91", Origin: "Ubuntu", Security: true},
		{Name: "linux-image-6.8.0-90-generic", Version: "6.8.0-90.91", Origin: "Ubuntu", Security: true},
		{Name: "linux-generic", Installed: "6.8.0-88.89", Version: "6.8.0-90.91", Origin: "Ubuntu", Security: true},
		{Name: "php8.4-fpm", Installed: "8.4.13-1+ubuntu24.04.1+deb.sury.org+1", Version: "8.4.14-1+ubuntu24.04.1+deb.sury.org+1", Origin: "LP-PPA-ondrej-php"},
		{Name: "postgresql-18", Installed: "18.1-1.pgdg24.04+2", Version: "18.2-1.pgdg24.04+1", Origin: "apt.postgresql.org"},
		{Name: "tzdata", Installed: "2025b-0ubuntu0.24.04.1", Version: "2025b-0ubuntu0.24.04.2", Origin: "Ubuntu"},
	}
	if !reflect.DeepEqual(p.Changes, want) {
		t.Errorf("changes:\n got %+v\nwant %+v", p.Changes, want)
	}
	if !reflect.DeepEqual(p.KeptBack, []string{"ubuntu-drivers-common", "netplan.io"}) {
		t.Errorf("kept back: %v", p.KeptBack)
	}
	if !reflect.DeepEqual(p.Phased, []string{"python3-distupgrade", "ubuntu-release-upgrader-core"}) {
		t.Errorf("phased: %v", p.Phased)
	}
	if len(p.Removed) != 0 {
		t.Errorf("removed: %v", p.Removed)
	}
}

func TestSimulationRemovals(t *testing.T) {
	p := Simulation("Remv libfoo1 [1.0-1]\nInst libfoo2 (2.0-1 Ubuntu:24.04/noble-updates [amd64])\n")
	if !reflect.DeepEqual(p.Removed, []string{"libfoo1"}) || len(p.Changes) != 1 || p.Changes[0].Installed != "" {
		t.Errorf("got %+v", p)
	}
}
