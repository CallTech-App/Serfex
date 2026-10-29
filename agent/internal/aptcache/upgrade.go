package aptcache

import (
	"regexp"
	"strings"
)

// Change is a package an apt run would install: an upgrade of an
// installed package, or a new one it brings along (a new kernel).
type Change struct {
	Name string `json:"name"`
	// Installed is the version installed now, empty for a new package.
	Installed string `json:"installed,omitempty"`
	Version   string `json:"version"`
	// Origin is the archive's label: "Ubuntu", "LP-PPA-ondrej-php",
	// "apt.postgresql.org".
	Origin string `json:"origin,omitempty"`
	// Security is true when the version comes from a security pocket
	// ("noble-security").
	Security bool `json:"security"`
}

// Plan is what "apt-get -s upgrade --with-new-pkgs" (or install) would
// do.
type Plan struct {
	Changes []Change
	// Removed are packages the run would remove; Serfex never runs those.
	Removed []string
	// KeptBack are upgrades apt leaves out (they need a removal or a
	// conflicting change), Phased the ones Ubuntu is still rolling out
	// in phases and holds back on this server for now.
	KeptBack []string
	Phased   []string
}

// "Inst NAME [OLD] (NEW ORIGIN, ORIGIN [ARCH])": OLD is missing for new
// packages, and a trailing "[]" lists broken dependencies.
var instRe = regexp.MustCompile(`^Inst (\S+) (?:\[([^\]]*)\] )?\((\S+)(?: ([^\[)]*?))?\s*(?:\[[^\]]*\])?\)`)

// Simulation parses the output of "LC_ALL=C apt-get -s ...".
func Simulation(out string) Plan {
	var p Plan
	var section *[]string
	for _, line := range strings.Split(out, "\n") {
		if strings.HasPrefix(line, "  ") && section != nil {
			*section = append(*section, strings.Fields(line)...)
			continue
		}
		section = nil
		switch {
		case strings.HasPrefix(line, "Inst "):
			m := instRe.FindStringSubmatch(line)
			if m == nil {
				continue
			}
			c := Change{Name: m[1], Installed: m[2], Version: m[3]}
			for i, o := range strings.Split(m[4], ",") {
				label, suite, _ := strings.Cut(strings.TrimSpace(o), "/")
				label, _, _ = strings.Cut(label, ":")
				if i == 0 {
					c.Origin = label
				}
				if strings.HasSuffix(suite, "-security") {
					c.Security = true
				}
			}
			p.Changes = append(p.Changes, c)
		case strings.HasPrefix(line, "Remv "):
			if f := strings.Fields(line); len(f) > 1 {
				p.Removed = append(p.Removed, f[1])
			}
		case strings.HasSuffix(line, "kept back:"):
			section = &p.KeptBack
		case strings.Contains(line, "deferred due to phasing:"):
			section = &p.Phased
		}
	}
	return p
}

// SecuritySuite reports whether an apt-cache policy archive line's suite
// ("noble-security/main") is a security pocket.
func SecuritySuite(suite string) bool {
	s, _, _ := strings.Cut(suite, "/")
	return strings.HasSuffix(s, "-security")
}
