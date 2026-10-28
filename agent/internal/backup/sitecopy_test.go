package backup

import (
	"context"
	"encoding/json"
	"errors"
	"os"
	"slices"
	"strings"
	"testing"

	"github.com/calltech-app/serfex/agent/internal/database"
	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/site"
)

const (
	staging    = "staging.magazaonline.com.tr"
	stagingWeb = "/home/magaza/web/staging.magazaonline.com.tr"
)

// newCopyTest is newTest with WordPress on the live site and an empty
// staging site of the same account with its own database.
func newCopyTest(t *testing.T) (*Manager, *host.Simulator) {
	t.Helper()
	m, sim := newTest(t)
	ctx := context.Background()
	if _, err := site.NewManager(sim, "www-data").Apply(ctx, site.Spec{Domain: staging, User: user, PHPVersion: "8.4"}); err != nil {
		t.Fatal(err)
	}
	if _, err := m.db.CreateDatabase(ctx, database.DatabaseSpec{Server: "mysql", Name: "magaza_shop_copy", Site: staging}); err != nil {
		t.Fatal(err)
	}
	if _, err := m.db.ApplyUser(ctx, database.UserSpec{Server: "mysql", Name: "magaza_app", Grants: []database.Grant{{Database: "magaza_shop", Access: "all"}, {Database: "magaza_shop_copy", Access: "all"}}}); err != nil {
		t.Fatal(err)
	}
	wp := host.NewSimWP()
	wp.Installed = true
	wp.Constants = map[string]string{"DB_NAME": "'magaza_shop'", "DB_USER": "'magaza_app'", "WP_HOME": "'https://www.magazaonline.com.tr'"}
	wp.Options = map[string]string{"siteurl": "https://www.magazaonline.com.tr", "home": "https://www.magazaonline.com.tr", "blog_public": "1"}
	if err := sim.WriteSimWP(web+"/public_html", wp); err != nil {
		t.Fatal(err)
	}
	write(t, sim, "/usr/bin/php8.4", "")
	write(t, sim, web+"/app/.env", "APP_URL=https://magazaonline.com.tr\nDB_DATABASE=magaza_shop\nDB_USERNAME=magaza_shop\nMAIL_FROM_ADDRESS=info@magazaonline.com.tr\nPARTNER=https://magazaonline.com.tr.example.net\n")
	write(t, sim, web+"/app/artisan", "<?php\n")
	write(t, sim, web+"/app/bootstrap/cache/config.php", "<?php return ['database' => 'magaza_shop'];")
	write(t, sim, web+"/shared/media/a.jpg", "JPG")
	write(t, sim, web+"/logs/php-error.log", "live errors\n")
	if err := os.Symlink(web+"/shared/media", sim.Path(web+"/public_html/media")); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink("../shared", sim.Path(web+"/public_html/shared")); err != nil {
		t.Fatal(err)
	}
	write(t, sim, stagingWeb+"/public_html/old.txt", "old staging file")
	write(t, sim, stagingWeb+"/logs/php-error.log", "staging errors\n")
	return m, sim
}

func copySpec(run string) CopySpec {
	return CopySpec{
		Run:       run,
		From:      CopySite{Domain: domain, User: user, PHPVersion: "8.4"},
		To:        CopySite{Domain: staging, User: user, PHPVersion: "8.4"},
		Files:     true,
		Databases: []CopyDatabase{{Server: "mysql", From: "magaza_shop", To: "magaza_shop_copy"}},
		Hosts:     [][2]string{{"www.magazaonline.com.tr", staging}, {domain, staging}},
		WordPress: "public_html",
		Public:    "0",
	}
}

func simWP(t *testing.T, sim *host.Simulator, dir string) host.SimWP {
	t.Helper()
	var wp host.SimWP
	if err := json.Unmarshal([]byte(read(t, sim, dir+"/"+host.SimWPState)), &wp); err != nil {
		t.Fatal(err)
	}
	return wp
}

func TestCopyMakesAStagingCopy(t *testing.T) {
	m, sim := newCopyTest(t)
	res, err := m.Copy(context.Background(), copySpec("copy-1"))
	if err != nil {
		t.Fatal(err)
	}
	if got := read(t, sim, stagingWeb+"/public_html/index.php"); !strings.Contains(got, "wp-blog-header") {
		t.Errorf("index.php = %q", got)
	}
	if _, err := os.Stat(sim.Path(stagingWeb + "/public_html/old.txt")); !errors.Is(err, os.ErrNotExist) {
		t.Error("the staging site's old files are still there")
	}
	if got := read(t, sim, stagingWeb+"/logs/php-error.log"); got != "staging errors\n" {
		t.Errorf("the staging site's logs = %q", got)
	}
	if got := read(t, sim, web+"/logs/php-error.log"); got != "live errors\n" {
		t.Errorf("the live logs = %q", got)
	}

	cfg := read(t, sim, stagingWeb+"/public_html/wp-config.php")
	if !strings.Contains(cfg, "define( 'DB_NAME', 'magaza_shop_copy' );") || !strings.Contains(cfg, "'https://staging.magazaonline.com.tr'") || !strings.Contains(cfg, "define( 'DB_USER', 'magaza_app' );") {
		t.Errorf("wp-config.php:\n%s", cfg)
	}
	env := read(t, sim, stagingWeb+"/app/.env")
	for _, want := range []string{"APP_URL=https://staging.magazaonline.com.tr\n", "DB_DATABASE=magaza_shop_copy\n", "DB_USERNAME=magaza_shop\n", "info@magazaonline.com.tr", "https://magazaonline.com.tr.example.net"} {
		if !strings.Contains(env, want) {
			t.Errorf(".env lacks %q:\n%s", want, env)
		}
	}
	if _, err := os.Stat(sim.Path(stagingWeb + "/app/bootstrap/cache/config.php")); !errors.Is(err, os.ErrNotExist) {
		t.Error("Laravel's cached configuration was copied")
	}
	if got, _ := os.Readlink(sim.Path(stagingWeb + "/public_html/media")); got != stagingWeb+"/shared/media" {
		t.Errorf("absolute link points at %q", got)
	}
	if got, _ := os.Readlink(sim.Path(stagingWeb + "/public_html/shared")); got != "../shared" {
		t.Errorf("relative link points at %q", got)
	}
	wp := simWP(t, sim, stagingWeb+"/public_html")
	if wp.Options["siteurl"] != "https://staging.magazaonline.com.tr" || wp.Options["blog_public"] != "0" {
		t.Errorf("WordPress options %v", wp.Options)
	}
	if live := simWP(t, sim, web+"/public_html"); live.Options["siteurl"] != "https://www.magazaonline.com.tr" || live.Options["blog_public"] != "1" {
		t.Errorf("the live WordPress changed: %v", live.Options)
	}
	codes := []string{}
	for _, n := range res.Notes {
		codes = append(codes, n.Code+":"+n.Subject)
	}
	if !slices.Contains(codes, "links:1") || !slices.Contains(codes, "config_cache:app") || slices.ContainsFunc(codes, func(c string) bool { return strings.HasPrefix(c, "db_not_in_config") }) {
		t.Errorf("notes %v", codes)
	}
	if !sim.Ran("nice -n 19 ionice -c 3 runuser -u magaza -- tar -cf - --one-file-system --exclude=./logs") {
		t.Error("the files were not packed by the account")
	}
	if log := read(t, sim, runsDir+"/copy-1.log"); !strings.Contains(log, "Kopyalama bitti.") || !strings.Contains(log, "magaza_shop → magaza_shop_copy") {
		t.Errorf("log:\n%s", log)
	}
}

func TestPublishKeepsTheLiveSitesSearchSetting(t *testing.T) {
	m, sim := newCopyTest(t)
	ctx := context.Background()
	if _, err := m.Copy(ctx, copySpec("copy-1")); err != nil {
		t.Fatal(err)
	}
	write(t, sim, stagingWeb+"/public_html/new-page.php", "<?php echo 'new';")
	back := CopySpec{
		Run:       "publish-1",
		From:      CopySite{Domain: staging, User: user, PHPVersion: "8.4"},
		To:        CopySite{Domain: domain, User: user, PHPVersion: "8.4"},
		Files:     true,
		Databases: []CopyDatabase{{Server: "mysql", From: "magaza_shop_copy", To: "magaza_shop"}},
		Hosts:     [][2]string{{staging, "www.magazaonline.com.tr"}},
		WordPress: "public_html",
		Public:    "keep",
	}
	if _, err := m.Copy(ctx, back); err != nil {
		t.Fatal(err)
	}
	if got := read(t, sim, web+"/public_html/new-page.php"); got != "<?php echo 'new';" {
		t.Errorf("new page = %q", got)
	}
	cfg := read(t, sim, web+"/public_html/wp-config.php")
	if !strings.Contains(cfg, "'magaza_shop'") || strings.Contains(cfg, "magaza_shop_copy") || !strings.Contains(cfg, "'https://www.magazaonline.com.tr'") {
		t.Errorf("wp-config.php:\n%s", cfg)
	}
	wp := simWP(t, sim, web+"/public_html")
	if wp.Options["siteurl"] != "https://www.magazaonline.com.tr" || wp.Options["blog_public"] != "1" {
		t.Errorf("live WordPress options %v", wp.Options)
	}
	if got := read(t, sim, web+"/logs/php-error.log"); got != "live errors\n" {
		t.Errorf("the live logs = %q", got)
	}
}

func TestCopyNotesADatabaseNoSettingsFileNames(t *testing.T) {
	m, sim := newCopyTest(t)
	ctx := context.Background()
	if _, err := m.db.CreateDatabase(ctx, database.DatabaseSpec{Server: "mysql", Name: "magaza_logs", Site: domain}); err != nil {
		t.Fatal(err)
	}
	if _, err := m.db.CreateDatabase(ctx, database.DatabaseSpec{Server: "mysql", Name: "magaza_logs_copy", Site: staging}); err != nil {
		t.Fatal(err)
	}
	s := copySpec("copy-2")
	s.Databases = append(s.Databases, CopyDatabase{Server: "mysql", From: "magaza_logs", To: "magaza_logs_copy"})
	res, err := m.Copy(ctx, s)
	if err != nil {
		t.Fatal(err)
	}
	if !slices.Contains(res.Notes, CopyNote{Code: "db_not_in_config", Subject: "magaza_logs"}) {
		t.Errorf("notes %v", res.Notes)
	}
	// Databases only: the files stay.
	write(t, sim, stagingWeb+"/public_html/kept.txt", "kept")
	s.Files, s.Run = false, "copy-3"
	if _, err := m.Copy(ctx, s); err != nil {
		t.Fatal(err)
	}
	if read(t, sim, stagingWeb+"/public_html/kept.txt") != "kept" {
		t.Error("a database-only copy changed the files")
	}
}

func TestCopyValidation(t *testing.T) {
	m, _ := newCopyTest(t)
	for name, change := range map[string]func(*CopySpec){
		"other account":   func(s *CopySpec) { s.To.User = "baska" },
		"onto itself":     func(s *CopySpec) { s.To = s.From },
		"nothing":         func(s *CopySpec) { s.Files, s.Databases = false, nil },
		"same database":   func(s *CopySpec) { s.Databases[0].To = s.Databases[0].From },
		"bad host":        func(s *CopySpec) { s.Hosts = [][2]string{{"a b", "c.com"}} },
		"bad public":      func(s *CopySpec) { s.Public = "yes" },
		"bad wordpress":   func(s *CopySpec) { s.WordPress = "../x" },
		"missing target":  func(s *CopySpec) { s.To.Domain = "yok.magazaonline.com.tr" },
		"bad php version": func(s *CopySpec) { s.To.PHPVersion = "8" },
	} {
		s := copySpec("copy-v")
		change(&s)
		var v *ValidationError
		if _, err := m.Copy(context.Background(), s); !errors.As(err, &v) {
			t.Errorf("%s: %v", name, err)
		}
	}
}

func TestReplaceHostKeepsLongerNames(t *testing.T) {
	in := `a https://shop.com/x b http://shop.com c https://shop.com.tr d "https:\/\/shop.com\/y" e //shop.com f https://shop.community`
	got := string(replaceHost([]byte(in), "shop.com", "staging.shop.com"))
	want := `a https://staging.shop.com/x b http://staging.shop.com c https://shop.com.tr d "https:\/\/staging.shop.com\/y" e //shop.com f https://shop.community`
	if got != want {
		t.Errorf("got  %s\nwant %s", got, want)
	}
}
