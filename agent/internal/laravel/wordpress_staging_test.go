package laravel

import (
	"context"
	"os"
	"strings"
	"testing"
)

func TestStagingPluginComesAndGoes(t *testing.T) {
	m, sim := newWPTest(t)
	ctx := context.Background()
	if _, err := m.InstallWordPress(ctx, installSpec("wp1")); err != nil {
		t.Fatal(err)
	}
	path := sim.Path(wpDir + "/" + wpStagingPlugin)

	on, err := m.WordPressStaging(ctx, WPStagingSpec{App: wpApp, Staging: &WPStaging{Mail: true, Cron: true}})
	if err != nil || !on {
		t.Fatalf("on=%v err=%v", on, err)
	}
	b, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	php := string(b)
	for _, want := range []string{"$hosts = ['api.hizlikargo.app', 'www.api.hizlikargo.app'];", "$blockMail = true;", "$blockCron = true;", "pre_wp_mail", "pre_get_ready_cron_jobs"} {
		if !strings.Contains(php, want) {
			t.Fatalf("plugin lacks %q:\n%s", want, php)
		}
	}

	// Mail only.
	if on, err := m.WordPressStaging(ctx, WPStagingSpec{App: wpApp, Staging: &WPStaging{Mail: true}}); err != nil || !on {
		t.Fatalf("on=%v err=%v", on, err)
	}
	if b, _ := os.ReadFile(path); !strings.Contains(string(b), "$blockCron = false;") || !strings.Contains(string(b), "$blockMail = true;") {
		t.Fatalf("not rewritten:\n%s", b)
	}

	// Nothing to stop, or a publish: the plugin goes.
	for _, s := range []*WPStaging{{}, nil} {
		if _, err := m.WordPressStaging(ctx, WPStagingSpec{App: wpApp, Staging: &WPStaging{Cron: true}}); err != nil {
			t.Fatal(err)
		}
		if on, err := m.WordPressStaging(ctx, WPStagingSpec{App: wpApp, Staging: s}); err != nil || on {
			t.Fatalf("off: on=%v err=%v", on, err)
		}
		if _, err := os.Stat(path); !os.IsNotExist(err) {
			t.Fatalf("plugin still there: %v", err)
		}
	}
	// Removing what is not there is fine.
	if on, err := m.WordPressStaging(ctx, WPStagingSpec{App: wpApp}); err != nil || on {
		t.Fatalf("again: on=%v err=%v", on, err)
	}
}

func TestStagingPluginHostsOfAWwwDomain(t *testing.T) {
	php := string(wpStagingContent("WWW.Staging.Example.com", WPStaging{Cron: true}))
	if !strings.Contains(php, "$hosts = ['www.staging.example.com', 'staging.example.com'];") || !strings.Contains(php, "$blockMail = false;") {
		t.Fatal(php)
	}
}
