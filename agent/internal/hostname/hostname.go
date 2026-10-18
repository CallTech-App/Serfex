// Package hostname validates DNS host names the way every part of the
// agent expects them: lowercase ASCII (punycode for international names),
// at least two labels and a real top-level domain.
package hostname

import (
	"fmt"
	"regexp"
	"strings"
)

var (
	labelRe = regexp.MustCompile(`^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$`)
	tldRe   = regexp.MustCompile(`^([a-z]{2,63}|xn--[a-z0-9-]{1,59})$`)
)

// Validate checks a host name such as kahveatolyesi.com.tr.
func Validate(d string) error {
	if d == "" {
		return fmt.Errorf("domain is required")
	}
	if len(d) > 253 {
		return fmt.Errorf("domain %q is too long", d)
	}
	labels := strings.Split(d, ".")
	if len(labels) < 2 {
		return fmt.Errorf("domain %q needs at least two labels", d)
	}
	for _, l := range labels {
		if !labelRe.MatchString(l) {
			return fmt.Errorf("domain %q is not a valid host name", d)
		}
	}
	if !tldRe.MatchString(labels[len(labels)-1]) {
		return fmt.Errorf("domain %q has an invalid top-level domain", d)
	}
	return nil
}
