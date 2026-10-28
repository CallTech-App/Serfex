package laravel

import (
	"context"
	"fmt"
	"io"
	"strings"

	"github.com/calltech-app/serfex/agent/internal/i18n"
)

// A WordPress copied to another site (Siteyi kopyala) still has the old
// address in its database: in options, post contents, menus and the
// serialized settings of plugins. WP-CLI's search-replace rewrites them,
// serialized data included.

// WPMoveSpec says how a copied installation fits its new site.
type WPMoveSpec struct {
	App
	// Replace are the old and new host names ("www.example.com",
	// "staging.example.com"); //old becomes //new in every table of the
	// installation, in plain and JSON-escaped form.
	Replace [][2]string `json:"replace"`
	// Public sets blog_public: "0" asks search engines to stay away, "1"
	// lets them in, "" leaves it as copied.
	Public string `json:"public"`
}

// WPMove rewrites a copied installation for its new site. Output goes to
// log. The guid column is left alone, as WordPress asks.
func (m *Manager) WPMove(ctx context.Context, spec WPMoveSpec, log io.Writer) error {
	if err := m.validateWP(spec.App); err != nil {
		return err
	}
	if spec.Public != "" && spec.Public != "0" && spec.Public != "1" {
		return invalid("public must be 0, 1 or empty")
	}
	if err := m.ensureWPCLI(ctx, log); err != nil {
		return fmt.Errorf("WP-CLI could not be downloaded: %w", err)
	}
	run := func(args ...string) error {
		fmt.Fprintf(log, "$ wp %s\n", strings.Join(quoteArgs(args), " "))
		c := m.wpCmd(spec.App, log, true, args...)
		if _, err := m.h.Run(ctx, c); err != nil {
			return fmt.Errorf("wp %s failed with exit code %d", args[0], host0(err))
		}
		return nil
	}
	for _, r := range spec.Replace {
		if r[0] == "" || r[0] == r[1] {
			continue
		}
		for _, sep := range []string{"//", `\/\/`} {
			if err := run("search-replace", sep+r[0], sep+r[1], "--all-tables-with-prefix", "--skip-columns=guid", "--report-changed-only"); err != nil {
				return err
			}
		}
	}
	if spec.Public != "" {
		if err := run("option", "update", "blog_public", spec.Public); err != nil {
			return err
		}
	}
	// A persistent object cache (Redis, Memcached) shared with the source
	// would hand the copy the source's cached options.
	if m.exists(spec.dir() + "/wp-content/object-cache.php") {
		for _, k := range []string{"WP_CACHE_KEY_SALT", "WP_REDIS_PREFIX"} {
			if err := run("config", "set", k, spec.Domain+":"); err != nil {
				return err
			}
		}
		fmt.Fprintln(log, "  "+i18n.T(ctx, "Nesne önbelleği için bu siteye ait bir önek ayarlandı"))
	}
	if err := run("cache", "flush"); err != nil {
		fmt.Fprintln(log, "  "+i18n.T(ctx, "Önbellek boşaltılamadı; sorun değil, kendiliğinden yenilenir"))
	}
	return nil
}

// WPOption reads one option of an installation, "" when it is not set.
func (m *Manager) WPOption(ctx context.Context, a App, name string) (string, error) {
	if err := m.validateWP(a); err != nil {
		return "", err
	}
	if !wpSlugRe.MatchString(name) {
		return "", invalid("option %q is not valid", name)
	}
	if err := m.ensureWPCLI(ctx, nil); err != nil {
		return "", err
	}
	out, err := m.wp(ctx, a, true, "option", "get", name)
	if err != nil {
		return "", nil
	}
	return strings.TrimSpace(out), nil
}
