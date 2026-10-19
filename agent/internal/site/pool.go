package site

import (
	"context"
	"errors"
	"fmt"
	"regexp"

	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/txn"
)

var maxChildrenRe = regexp.MustCompile(`(?m)^pm\.max_children = \d+$`)

// SetMaxChildren changes how many PHP-FPM workers a site's pool may run,
// without touching anything else of the site; n = 0 restores
// DefaultMaxChildren. It is how temporary limits take effect at once.
// Later Apply calls keep the value MaxChildren returns.
func (m *Manager) SetMaxChildren(ctx context.Context, domain string, n int) (err error) {
	if err := ValidateDomain(domain); err != nil {
		return err
	}
	if n < 0 || n > 1000 {
		return invalid("PHP workers must be between 1 and 1000")
	}
	if n == 0 {
		n = DefaultMaxChildren
	}
	m.mu.Lock()
	defer m.mu.Unlock()

	versions, err := m.PHPVersions()
	if err != nil {
		return &OperationError{err}
	}
	for _, v := range versions {
		pool := fpmPool(v, domain)
		t := txn.New(m.h)
		old, err := t.ReadManaged(pool)
		if err != nil {
			return &OperationError{err}
		}
		if old == nil {
			continue
		}
		if !maxChildrenRe.Match(old) {
			return &OperationError{fmt.Errorf("%s has no pm.max_children line", pool)}
		}
		if err := t.WriteFile(pool, maxChildrenRe.ReplaceAll(old, fmt.Appendf(nil, "pm.max_children = %d", n))); err != nil {
			return &OperationError{err}
		}
		if !t.Changed {
			return nil
		}
		if _, err := m.h.Run(ctx, host.Command{Name: "php-fpm" + v, Args: []string{"-t"}}); err != nil {
			return &OperationError{errors.Join(fmt.Errorf("PHP-FPM %s rejected the configuration: %w", v, err), t.Rollback(context.WithoutCancel(ctx)))}
		}
		if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"reload", "php" + v + "-fpm"}}); err != nil {
			rbErr := t.Rollback(context.WithoutCancel(ctx))
			_, _ = m.h.Run(context.WithoutCancel(ctx), host.Command{Name: "systemctl", Args: []string{"reload", "php" + v + "-fpm"}})
			return &OperationError{errors.Join(fmt.Errorf("reloading php%s-fpm failed: %w", v, err), rbErr)}
		}
		return nil
	}
	return invalid("site %s has no PHP-FPM pool", domain)
}
