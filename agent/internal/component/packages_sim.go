package component

import (
	"encoding/json"
	"fmt"
	"os"
	"slices"
	"strings"

	"github.com/calltech-app/serfex/agent/internal/aptcache"
)

// A dry run shows a plausible Ubuntu 24.04 server with updates waiting;
// the ones it "installs" are remembered in simUpgradedFile.
const simUpgradedFile = stateDir + "/sim-upgraded.json"

type simPkg struct {
	name, from, to, origin string
	security, phased, kept bool
}

var simPackageUpdates = []simPkg{
	{name: "libssl3t64", from: "3.0.13-0ubuntu3.5", to: "3.0.13-0ubuntu3.6", origin: "Ubuntu", security: true},
	{name: "openssl", from: "3.0.13-0ubuntu3.5", to: "3.0.13-0ubuntu3.6", origin: "Ubuntu", security: true},
	{name: "linux-generic", from: "6.8.0-88.89", to: "6.8.0-90.91", origin: "Ubuntu", security: true},
	{name: "linux-image-generic", from: "6.8.0-88.89", to: "6.8.0-90.91", origin: "Ubuntu", security: true},
	{name: "linux-image-6.8.0-90-generic", to: "6.8.0-90.91", origin: "Ubuntu", security: true},
	{name: "linux-modules-6.8.0-90-generic", to: "6.8.0-90.91", origin: "Ubuntu", security: true},
	{name: "curl", from: "8.5.0-2ubuntu10.6", to: "8.5.0-2ubuntu10.7", origin: "Ubuntu"},
	{name: "libcurl4t64", from: "8.5.0-2ubuntu10.6", to: "8.5.0-2ubuntu10.7", origin: "Ubuntu"},
	{name: "tzdata", from: "2025b-0ubuntu0.24.04.1", to: "2025b-0ubuntu0.24.04.2", origin: "Ubuntu"},
	{name: "nginx", from: "1.24.0-2ubuntu7.5", to: "1.24.0-2ubuntu7.6", origin: "Ubuntu"},
	{name: "nginx-common", from: "1.24.0-2ubuntu7.5", to: "1.24.0-2ubuntu7.6", origin: "Ubuntu"},
	{name: "php8.3-fpm", from: "8.3.6-0ubuntu0.24.04.5", to: "8.3.6-0ubuntu0.24.04.6", origin: "Ubuntu", security: true},
	{name: "php8.3-cli", from: "8.3.6-0ubuntu0.24.04.5", to: "8.3.6-0ubuntu0.24.04.6", origin: "Ubuntu", security: true},
	{name: "php8.3-common", from: "8.3.6-0ubuntu0.24.04.5", to: "8.3.6-0ubuntu0.24.04.6", origin: "Ubuntu", security: true},
	{name: "python3-distupgrade", from: "1:24.04.26", to: "1:24.04.27", origin: "Ubuntu", phased: true},
	{name: "ubuntu-drivers-common", from: "1:0.9.7.6ubuntu3.2", to: "1:0.9.7.6ubuntu3.4", origin: "Ubuntu", kept: true},
}

// simHeld is the package a dry run has on hold, with its update.
var simHeld = map[string]aptcache.Candidate{
	"cloud-init": {Installed: "25.1.4-0ubuntu0~24.04.1", Version: "25.2-0ubuntu1~24.04.1", From: "archive.ubuntu.com/ubuntu"},
}

// simRebootPackages ask for a restart when a dry run installs them.
var simRebootPackages = []string{"linux-image-6.8.0-90-generic", "libssl3t64"}

func (m *Manager) simUpgraded() map[string]bool {
	out := map[string]bool{}
	if data, err := os.ReadFile(m.h.Path(simUpgradedFile)); err == nil {
		var names []string
		_ = json.Unmarshal(data, &names)
		for _, n := range names {
			out[n] = true
		}
	}
	return out
}

// simUpgrade is "apt-get -s" output for the dry run's updates.
func (m *Manager) simUpgrade(args []string) string {
	done := m.simUpgraded()
	var chosen []string
	if len(args) > 0 && args[0] == "install" {
		for _, a := range args[1:] {
			if !strings.HasPrefix(a, "-") {
				chosen = append(chosen, a)
			}
		}
	}
	var b, kept, phased strings.Builder
	for _, p := range simPackageUpdates {
		if done[p.name] {
			continue
		}
		if chosen != nil && !slices.Contains(chosen, p.name) {
			// A new kernel's image comes with its metapackage.
			if !(p.from == "" && slices.Contains(chosen, "linux-generic")) {
				continue
			}
		}
		if chosen == nil && p.phased {
			phased.WriteString(" " + p.name)
			continue
		}
		if chosen == nil && p.kept {
			kept.WriteString(" " + p.name)
			continue
		}
		suites := p.origin + ":24.04/noble-updates"
		if p.security {
			suites += ", " + p.origin + ":24.04/noble-security"
		}
		if p.from == "" {
			fmt.Fprintf(&b, "Inst %s (%s %s [amd64])\n", p.name, p.to, suites)
		} else {
			fmt.Fprintf(&b, "Inst %s [%s] (%s %s [amd64])\n", p.name, p.from, p.to, suites)
		}
	}
	if chosen == nil {
		kept.WriteString(" cloud-init")
	}
	if slices.Contains(chosen, "ubuntu-drivers-common") && !done["ubuntu-drivers-common"] {
		// Why it was kept back: it replaces a package.
		b.WriteString("Remv ubuntu-drivers-common-legacy [1:0.9.7.6ubuntu3.2]\n")
	}
	head := "Reading package lists...\nCalculating upgrade...\n"
	if kept.Len() > 0 {
		head += "The following packages have been kept back:\n " + kept.String() + "\n"
	}
	if phased.Len() > 0 {
		head += "The following upgrades have been deferred due to phasing:\n " + phased.String() + "\n"
	}
	return head + b.String()
}

// simInstall records what a dry run upgraded, with Ubuntu's reboot
// notice when a new kernel or OpenSSL came.
func (m *Manager) simInstall(changes []aptcache.Change) {
	done := m.simUpgraded()
	var reboot []string
	for _, c := range changes {
		done[c.Name] = true
		if slices.Contains(simRebootPackages, c.Name) {
			reboot = append(reboot, c.Name)
		}
	}
	names := make([]string, 0, len(done))
	for n := range done {
		names = append(names, n)
	}
	slices.Sort(names)
	data, _ := json.Marshal(names)
	_ = os.MkdirAll(m.h.Path(stateDir), 0o755)
	_ = os.WriteFile(m.h.Path(simUpgradedFile), data, 0o644)
	// dpkg's status changes with every install.
	_ = os.MkdirAll(m.h.Path("/var/lib/dpkg"), 0o755)
	_ = os.WriteFile(m.h.Path(dpkgStatus), data, 0o644)
	if len(reboot) > 0 {
		_ = os.MkdirAll(m.h.Path("/run"), 0o755)
		_ = os.WriteFile(m.h.Path(rebootRequired), []byte("*** System restart required ***\n"), 0o644)
		f, err := os.OpenFile(m.h.Path(rebootPackages), os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o644)
		if err == nil {
			fmt.Fprintln(f, strings.Join(reboot, "\n"))
			f.Close()
		}
	}
}
