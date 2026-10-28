package laravel

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
)

const siteRoot = "/home/hizlikargo/web/api.hizlikargo.app"

func writeFile(t *testing.T, path, content string) {
	t.Helper()
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
		t.Fatal(err)
	}
}

func TestSiteScanFindsComposerAndNodeProjects(t *testing.T) {
	m, sim := newTest(t)
	writeFile(t, sim.Path(siteRoot+"/public_html/composer.json"), `{"require":{}}`)
	writeFile(t, sim.Path(siteRoot+"/public_html/composer.lock"), `{}`)
	writeFile(t, sim.Path(siteRoot+"/app/package.json"), `{"scripts":{"build":"vite build","dev":"vite","bad name":"x"}}`)
	writeFile(t, sim.Path(siteRoot+"/app/pnpm-lock.yaml"), "lockfileVersion: 9\n")
	writeFile(t, sim.Path(siteRoot+"/app/node_modules/vite/package.json"), `{"scripts":{"x":"y"}}`)
	writeFile(t, sim.Path(siteRoot+"/a/b/c/d/package.json"), `{}`)
	if err := os.MkdirAll(sim.Path(toolsDir+"/node/v22.21.1/bin"), 0o755); err != nil {
		t.Fatal(err)
	}

	tools, err := m.SiteScan(context.Background(), target)
	if err != nil {
		t.Fatal(err)
	}
	if tools.Node["22"] != "v22.21.1" || tools.Composer != "" {
		t.Errorf("tools %+v", tools)
	}
	paths := []string{}
	for _, p := range tools.Projects {
		paths = append(paths, p.Path)
	}
	if !slices.Equal(paths, []string{"app", "public_html"}) {
		t.Fatalf("projects %v", paths)
	}
	app := tools.Projects[0]
	if !app.Node || app.Manager != "pnpm" || !app.Lock || app.Modules == false || !slices.Equal(app.Scripts, []string{"build", "dev"}) {
		t.Errorf("app %+v", app)
	}
	if pub := tools.Projects[1]; !pub.Composer || !pub.ComposerLock || pub.Vendor || pub.Node {
		t.Errorf("public_html %+v", pub)
	}
}

func TestSiteRunUsesTheSitesNodeLine(t *testing.T) {
	m, sim := newTest(t)
	writeFile(t, sim.Path("/usr/bin/php8.4"), "")
	writeFile(t, sim.Path(siteRoot+"/app/package.json"), `{}`)
	for _, v := range []string{"v22.21.1", "v24.11.0"} {
		if err := os.MkdirAll(sim.Path(toolsDir+"/node/"+v+"/bin"), 0o755); err != nil {
			t.Fatal(err)
		}
	}
	if err := os.Symlink("v24.11.0", sim.Path(toolsDir+"/node/current")); err != nil {
		t.Fatal(err)
	}
	spec := SiteRunSpec{Target: target, Path: "app", Run: "site-npm-1", Tool: "npm", Args: []string{"run", "build"}}
	spec.Node = "22"
	res, err := m.SiteRun(context.Background(), spec)
	if err != nil || res.ExitCode != 0 {
		t.Fatalf("%+v %v", res, err)
	}
	if !sim.Ran("runuser -u hizlikargo -- env -C " + siteRoot + "/app") {
		t.Error("the command did not run as the account in the folder")
	}
	var path string
	for _, c := range sim.Commands() {
		for _, a := range c.Args {
			if strings.HasPrefix(a, "PATH=") && strings.Contains(c.String(), " npm run build") {
				path = a
			}
		}
	}
	if !strings.Contains(path, toolsDir+"/node/v22.21.1/bin") {
		t.Errorf("PATH = %q", path)
	}
	if log := read(t, sim, runDir(target.Domain)+"/site-npm-1.log"); !strings.Contains(log, "$ npm run build") {
		t.Errorf("log:\n%s", log)
	}

	// Composer is fetched on first use.
	if _, err := m.SiteRun(context.Background(), SiteRunSpec{Target: target, Run: "site-composer-1", Tool: "composer", Args: []string{"install"}}); err != nil {
		t.Fatal(err)
	}
	if !exists(sim, composerPhar()) {
		t.Error("composer was not installed")
	}

	for name, bad := range map[string]SiteRunSpec{
		"artisan":       {Target: target, Run: "r1", Tool: "artisan"},
		"missing":       {Target: target, Path: "yok", Run: "r1", Tool: "npm"},
		"escape":        {Target: target, Path: "../x", Run: "r1", Tool: "npm"},
		"no run":        {Target: target, Tool: "npm"},
		"bad node line": {Target: Target{Domain: target.Domain, User: target.User, PHPVersion: "8.4", Node: "v22"}, Run: "r1", Tool: "npm"},
	} {
		var v *ValidationError
		if _, err := m.SiteRun(context.Background(), bad); !errors.As(err, &v) {
			t.Errorf("%s: %v", name, err)
		}
	}
}
