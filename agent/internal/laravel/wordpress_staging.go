package laravel

import (
	"bytes"
	"context"
	_ "embed"
	"errors"
	"fmt"
	"strings"
)

// A staging copy of a WordPress (Siteyi kopyala) runs on the live site's
// data: without care it e-mails the live site's customers (orders,
// password resets, newsletters) and runs their scheduled tasks
// (subscriptions, imports). The must-use plugin below stops either while
// the installation's address is the copy's; it is removed when the copy
// is published onto the live site.

// wpStagingPlugin is the staging plugin, relative to the folder.
const wpStagingPlugin = "wp-content/mu-plugins/serfex-staging.php"

//go:embed wordpress-staging.php
var wpStagingPHP []byte

// WPStaging says what a staging copy keeps to itself.
type WPStaging struct {
	// Mail stops e-mail from leaving.
	Mail bool `json:"mail"`
	// Cron stops scheduled tasks (WP-Cron) from running.
	Cron bool `json:"cron"`
}

// WPStagingSpec puts the staging plugin in place for an installation, or
// removes it when Staging is nil or stops nothing.
type WPStagingSpec struct {
	App
	Staging *WPStaging `json:"staging"`
}

// wpStagingContent is the plugin for the site's addresses (the domain and
// its www. twin).
func wpStagingContent(domain string, s WPStaging) []byte {
	domain = strings.ToLower(domain)
	hosts := fmt.Sprintf("['%s', '%s']", domain, "www."+domain)
	if twin, ok := strings.CutPrefix(domain, "www."); ok {
		hosts = fmt.Sprintf("['%s', '%s']", domain, twin)
	}
	b := bytes.Replace(wpStagingPHP, []byte("/*HOSTS*/[]"), []byte(hosts), 1)
	b = bytes.Replace(b, []byte("/*MAIL*/false"), []byte(fmt.Sprint(s.Mail)), 1)
	return bytes.Replace(b, []byte("/*CRON*/false"), []byte(fmt.Sprint(s.Cron)), 1)
}

// WordPressStaging writes or removes the staging plugin. It reports
// whether the plugin is in place afterwards.
func (m *Manager) WordPressStaging(ctx context.Context, spec WPStagingSpec) (bool, error) {
	if err := m.validateWP(spec.App); err != nil {
		return false, err
	}
	abs := spec.dir() + "/" + wpStagingPlugin
	if spec.Staging == nil || (!spec.Staging.Mail && !spec.Staging.Cron) {
		if m.exists(abs) {
			if _, err := m.userRun(ctx, spec.Target, "/", "rm", "-f", abs); err != nil {
				return true, &OperationError{errors.New("removing the staging plugin failed")}
			}
		}
		return false, nil
	}
	want := wpStagingContent(spec.Domain, *spec.Staging)
	if have, err := m.readFile(ctx, spec.Target, abs, 64<<10); err == nil && bytes.Equal(have, want) {
		return true, nil
	}
	if !m.isDir(spec.dir() + "/wp-content") {
		return false, &OperationError{errors.New("this WordPress has no wp-content folder")}
	}
	if _, err := m.userRun(ctx, spec.Target, "/", "mkdir", "-p", spec.dir()+"/wp-content/mu-plugins"); err != nil {
		return false, &OperationError{errors.New("creating wp-content/mu-plugins failed")}
	}
	if err := m.writeFile(ctx, spec.Target, abs, want, "0644"); err != nil {
		return false, &OperationError{err}
	}
	return true, nil
}
