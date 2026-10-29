// Package aptcache reads apt-cache's answers.
package aptcache

import "strings"

// Candidate is the version apt would install of a package and where it
// comes from.
type Candidate struct {
	Version string `json:"version"`
	// From is the archive as host and path ("archive.ubuntu.com/ubuntu"),
	// empty when apt knows the version only from dpkg's status.
	From string `json:"from,omitempty"`
	// Installed is the version installed now ("" when it is not), and
	// Security whether the candidate comes from a security pocket.
	Installed string `json:"-"`
	Security  bool   `json:"-"`
}

// Policy parses the output of "LC_ALL=C apt-cache policy PKG...". Packages
// without a candidate are left out.
func Policy(out string) map[string]Candidate {
	res := map[string]Candidate{}
	var (
		cur  string
		c    Candidate
		mine bool // in the candidate's lines of the version table
	)
	done := func() {
		if cur != "" && c.Version != "" {
			res[cur] = c
		}
	}
	for _, line := range strings.Split(out, "\n") {
		if line == "" {
			continue
		}
		if !strings.HasPrefix(line, " ") && strings.HasSuffix(line, ":") {
			done()
			cur, c, mine = strings.TrimSuffix(line, ":"), Candidate{}, false
			continue
		}
		trimmed := strings.TrimSpace(line)
		if v, ok := strings.CutPrefix(trimmed, "Installed:"); ok {
			if v = strings.TrimSpace(v); v != "(none)" {
				c.Installed = v
			}
			continue
		}
		if v, ok := strings.CutPrefix(trimmed, "Candidate:"); ok {
			if v = strings.TrimSpace(v); v != "(none)" {
				c.Version = v
			}
			continue
		}
		// The version table: "     VERSION PRIO" (" *** " marks the
		// installed one), then its archives indented further:
		// "        500 http://archive.ubuntu.com/ubuntu noble/main amd64 Packages".
		f := strings.Fields(trimmed)
		if len(f) > 0 && f[0] == "***" {
			f = f[1:]
		}
		if len(f) == 0 || c.Version == "" {
			continue
		}
		if len(line)-len(strings.TrimLeft(line, " ")) < 8 {
			mine = len(f) == 2 && f[0] == c.Version
			continue
		}
		if mine && c.From == "" && len(f) >= 2 {
			if _, rest, ok := strings.Cut(f[1], "://"); ok {
				c.From = strings.TrimSuffix(rest, "/")
			}
		}
		if mine && len(f) >= 3 && SecuritySuite(f[2]) {
			c.Security = true
		}
	}
	done()
	return res
}

// Upstream is a package version without its epoch and Debian revision:
// "18.1-1.pgdg24.04+2" is "18.1".
func Upstream(v string) string {
	if i := strings.IndexByte(v, ':'); i >= 0 {
		v = v[i+1:]
	}
	v, _, _ = strings.Cut(v, "-")
	return v
}
