package backup

import (
	"context"
	"errors"
	"os"
	"strings"
	"testing"

	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/site"
)

const (
	renamed    = "yenimagaza.com"
	renamedWeb = "/home/magaza/web/yenimagaza.com"
)

// newRenameTest is newTest with a Laravel application, WordPress, a
// storage link, Laravel and task state and a WAF snippet on the site.
func newRenameTest(t *testing.T) (*Manager, *host.Simulator) {
	t.Helper()
	m, sim := newTest(t)
	m.Sites = site.NewManager(sim, "www-data")
	wp := host.NewSimWP()
	wp.Installed = true
	wp.Options = map[string]string{"siteurl": "https://www.magazaonline.com.tr", "home": "https://www.magazaonline.com.tr", "blog_public": "1"}
	if err := sim.WriteSimWP(web+"/public_html", wp); err != nil {
		t.Fatal(err)
	}
	write(t, sim, "/usr/bin/php8.4", "")
	write(t, sim, web+"/app/.env", "APP_URL=https://magazaonline.com.tr\nASSET_URL=https://www.magazaonline.com.tr/assets\nLOG_PATH="+web+"/app/storage/logs\nMAIL_FROM_ADDRESS=info@magazaonline.com.tr\nPARTNER=https://magazaonline.com.tr.example.net\nOTHER="+web+".bak/x\n")
	write(t, sim, web+"/app/artisan", "<?php\n")
	write(t, sim, web+"/app/bootstrap/cache/config.php", "<?php return ['url' => 'https://magazaonline.com.tr'];")
	write(t, sim, web+"/app/storage/app/public/a.jpg", "JPG")
	if err := os.Symlink(web+"/app/storage/app/public", sim.Path(web+"/public_html/storage")); err != nil {
		t.Fatal(err)
	}
	write(t, sim, "/var/lib/serfex/laravel/"+domain+"/runs/r1.log", "deploy")
	write(t, sim, "/var/lib/serfex/backups/laravel/"+domain+"/b1.tar", "TAR")
	write(t, sim, "/home/magaza/.serfex/cron/"+domain+"/1/last", "ok")
	write(t, sim, "/etc/serfex/cron/"+domain+".json", `{"domain":"`+domain+`"}`)
	write(t, sim, stateDir+"/"+domain+".files.snar", "SNAR")
	write(t, sim, site.ExtraDir(domain)+"/waf.conf", "# Managed by Serfex\nmodsecurity_rules_file "+site.ExtraDir(domain)+"/waf.rules;\n")
	return m, sim
}

func renameSpec() RenameSpec {
	return RenameSpec{
		Run:       "rename-test",
		From:      domain,
		Site:      site.Spec{Domain: renamed, User: user, PHPVersion: "8.4", Aliases: []string{"www." + renamed}},
		Hosts:     [][2]string{{"www." + domain, "www." + renamed}, {domain, renamed}},
		WordPress: "public_html",
	}
}

func TestRenameMovesTheSite(t *testing.T) {
	m, sim := newRenameTest(t)
	var stopped []string
	m.StopRedis = func(_ context.Context, from, to string) ([][2]string, error) {
		stopped = append(stopped, from)
		return [][2]string{{"/var/lib/serfex/redis/" + from, "/var/lib/serfex/redis/" + to}}, nil
	}
	write(t, sim, "/var/lib/serfex/redis/"+domain+"/dump.rdb", "RDB")
	res, err := m.Rename(context.Background(), renameSpec())
	if err != nil {
		t.Fatal(err)
	}
	if res.Domain != renamed || res.DocumentRoot != renamedWeb+"/public_html" {
		t.Fatalf("result = %+v", res.RenameResult)
	}
	if len(stopped) != 1 || stopped[0] != domain {
		t.Errorf("Redis stopped for %v", stopped)
	}
	// The folder moved; the old path leads to it.
	if got := read(t, sim, renamedWeb+"/public_html/uploads/logo.png"); got != "PNG1" {
		t.Errorf("logo.png = %q", got)
	}
	if target, err := os.Readlink(sim.Path(web)); err != nil || target != renamed {
		t.Errorf("old folder link = %q, %v", target, err)
	}
	// Settings: addresses and the folder path change, nothing else.
	env := read(t, sim, renamedWeb+"/app/.env")
	for _, want := range []string{
		"APP_URL=https://yenimagaza.com\n",
		"ASSET_URL=https://www.yenimagaza.com/assets\n",
		"LOG_PATH=" + renamedWeb + "/app/storage/logs\n",
		"MAIL_FROM_ADDRESS=info@magazaonline.com.tr\n",
		"PARTNER=https://magazaonline.com.tr.example.net\n",
		"OTHER=" + web + ".bak/x\n",
	} {
		if !strings.Contains(env, want) {
			t.Errorf(".env lacks %q:\n%s", want, env)
		}
	}
	// The storage link points into the new folder.
	if target, _ := os.Readlink(sim.Path(renamedWeb + "/public_html/storage")); target != renamedWeb+"/app/storage/app/public" {
		t.Errorf("storage link = %q", target)
	}
	// WordPress got its new address.
	wp := simWP(t, sim, renamedWeb+"/public_html")
	if wp.Options["home"] != "https://www.yenimagaza.com" || wp.Options["siteurl"] != "https://www.yenimagaza.com" {
		t.Errorf("WordPress options = %v", wp.Options)
	}
	// What else is kept under the domain's name follows it.
	for _, p := range []string{
		"/var/lib/serfex/laravel/" + renamed + "/runs/r1.log",
		"/var/lib/serfex/backups/laravel/" + renamed + "/b1.tar",
		"/home/magaza/.serfex/cron/" + renamed + "/1/last",
		"/var/lib/serfex/redis/" + renamed + "/dump.rdb",
		"/var/log/serfex/sites/" + renamed,
		"/etc/nginx/sites-enabled/" + renamed + ".conf",
		"/etc/php/8.4/fpm/pool.d/" + renamed + ".conf",
	} {
		if !exists(sim, p) {
			t.Errorf("%s is missing", p)
		}
	}
	for _, p := range []string{
		"/var/lib/serfex/laravel/" + domain,
		"/etc/serfex/cron/" + domain + ".json",
		stateDir + "/" + domain + ".files.snar",
		"/var/log/serfex/sites/" + domain,
		"/etc/nginx/sites-enabled/" + domain + ".conf",
		"/etc/nginx/sites-available/" + domain + ".conf",
		"/etc/php/8.4/fpm/pool.d/" + domain + ".conf",
		site.ExtraDir(domain),
	} {
		if exists(sim, p) {
			t.Errorf("%s is still there", p)
		}
	}
	if got := read(t, sim, site.ExtraDir(renamed)+"/waf.conf"); !strings.Contains(got, site.ExtraDir(renamed)+"/waf.rules;") {
		t.Errorf("WAF snippet = %q", got)
	}
	vhost := read(t, sim, "/etc/nginx/sites-available/"+renamed+".conf")
	if !strings.Contains(vhost, "server_name yenimagaza.com www.yenimagaza.com;") || !strings.Contains(vhost, "root "+renamedWeb+"/public_html;") {
		t.Errorf("vhost:\n%s", vhost)
	}
	codes := map[string]string{}
	for _, n := range res.Notes {
		codes[n.Code] = n.Subject
	}
	if codes["config_cache"] != "app" || codes["links"] != "1" || codes["wordpress"] != "public_html" || !strings.Contains(codes["configs"], "app/.env") {
		t.Errorf("notes = %+v", res.Notes)
	}
	if !sim.Ran("runuser -u magaza -- env -C " + renamedWeb + "/app") {
		t.Error("the configuration cache was not built again")
	}
}

func TestRenameFailurePutsEverythingBack(t *testing.T) {
	m, sim := newRenameTest(t)
	envBefore := read(t, sim, web+"/app/.env")
	sim.Fail = func(c host.Command) error {
		if c.String() == "nginx -t" {
			return errors.New("nginx: [emerg] broken")
		}
		return nil
	}
	if _, err := m.Rename(context.Background(), renameSpec()); err == nil || !strings.Contains(err.Error(), "broken") {
		t.Fatalf("err = %v", err)
	}
	if fi, err := os.Lstat(sim.Path(web)); err != nil || !fi.IsDir() {
		t.Fatalf("the site folder is not back: %v", err)
	}
	if exists(sim, renamedWeb) {
		t.Error("the new folder is still there")
	}
	if got := read(t, sim, web+"/app/.env"); got != envBefore {
		t.Errorf(".env not put back:\n%s", got)
	}
	if got := read(t, sim, web+"/app/bootstrap/cache/config.php"); !strings.Contains(got, "magazaonline.com.tr") {
		t.Errorf("cached configuration not put back: %s", got)
	}
	if target, _ := os.Readlink(sim.Path(web + "/public_html/storage")); target != web+"/app/storage/app/public" {
		t.Errorf("storage link = %q", target)
	}
	if wp := simWP(t, sim, web+"/public_html"); wp.Options["home"] != "https://www.magazaonline.com.tr" {
		t.Errorf("WordPress not put back: %v", wp.Options)
	}
	for _, p := range []string{
		"/etc/nginx/sites-enabled/" + domain + ".conf",
		"/etc/php/8.4/fpm/pool.d/" + domain + ".conf",
		"/var/lib/serfex/laravel/" + domain + "/runs/r1.log",
		"/var/log/serfex/sites/" + domain,
		site.ExtraDir(domain) + "/waf.conf",
	} {
		if !exists(sim, p) {
			t.Errorf("%s is not back", p)
		}
	}
	for _, p := range []string{"/etc/nginx/sites-available/" + renamed + ".conf", "/etc/php/8.4/fpm/pool.d/" + renamed + ".conf"} {
		if exists(sim, p) {
			t.Errorf("%s survived the rollback", p)
		}
	}
	if got := read(t, sim, site.ExtraDir(domain)+"/waf.conf"); !strings.Contains(got, site.ExtraDir(domain)+"/waf.rules;") {
		t.Errorf("WAF snippet not put back: %q", got)
	}
}

func TestRenameRefusesTakenNames(t *testing.T) {
	m, sim := newRenameTest(t)
	if _, err := m.Sites.(*site.Manager).Apply(context.Background(), site.Spec{Domain: renamed, User: "baska", PHPVersion: "8.4"}); err != nil {
		t.Fatal(err)
	}
	_, err := m.Rename(context.Background(), renameSpec())
	var v *site.ValidationError
	if !errors.As(err, &v) || !strings.Contains(err.Error(), "already set up") {
		t.Fatalf("err = %v", err)
	}
	if !exists(sim, web+"/app/.env") || sim.Ran("systemctl disable") {
		t.Error("a refused rename changed something")
	}
}

func TestReplacePath(t *testing.T) {
	in := "a=/home/u/web/a.com\nb=/home/u/web/a.com/x\nc=/home/u/web/a.com.tr/x\nd='/home/u/web/a.com'"
	want := "a=/home/u/web/b.com\nb=/home/u/web/b.com/x\nc=/home/u/web/a.com.tr/x\nd='/home/u/web/b.com'"
	if got := string(replacePath([]byte(in), "/home/u/web/a.com", "/home/u/web/b.com")); got != want {
		t.Errorf("got\n%s", got)
	}
}

func exists(sim *host.Simulator, abs string) bool {
	_, err := os.Lstat(sim.Path(abs))
	return err == nil
}
