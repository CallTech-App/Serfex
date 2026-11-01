package laravel

import (
	"context"
	"strings"
)

// A site renamed to another domain (site.rename) keeps its application;
// what the agent keeps under the domain's name follows it.

// StopForRename stops and removes the queue workers, scheduler and
// processes of the site called domain. The panel applies them again under
// the site's name afterwards (or under the old one when the rename fails).
func (m *Manager) StopForRename(ctx context.Context, domain string) error {
	unlock := m.lock(domain)
	defer unlock()
	if err := m.removeUnits(ctx, domain); err != nil {
		return &OperationError{err}
	}
	return nil
}

// RenamePaths are the run records, logs, backups, Git state and WordPress
// restore points of a site, as [old, new] pairs.
func RenamePaths(from, to string) [][2]string {
	var out [][2]string
	for _, dir := range []string{stateDir, logDir, backupDir, gitStateDir, wpStateDir} {
		out = append(out, [2]string{dir + "/" + from, dir + "/" + to})
	}
	return out
}

// Artisan runs php artisan with args in the application folder dir (an
// absolute path) as the site's user.
func (m *Manager) Artisan(ctx context.Context, t Target, dir string, args ...string) (string, error) {
	if err := m.requirePHP(t.PHPVersion); err != nil {
		return "", err
	}
	out, err := m.userRun(ctx, t, dir, phpBin(t.PHPVersion), append([]string{"artisan"}, args...)...)
	return strings.TrimSpace(out), err
}
