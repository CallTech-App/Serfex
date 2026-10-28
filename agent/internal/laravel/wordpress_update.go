package laravel

import (
	"compress/gzip"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"time"

	"github.com/calltech-app/serfex/agent/internal/i18n"
)

// Updates of WordPress, its plugins and themes. Before an update the
// database and the folders the update replaces go into a restore point;
// when the site answered before the update and fails after it, the
// restore point is put back at once. A restore point can also be put
// back by hand (Geri al) while it is kept: wpPointKeep, at most
// wpPointMax per site.

const (
	wpPointKeep = 7 * 24 * time.Hour
	wpPointMax  = 3
)

// wpCoreFileRe are WordPress's own files at the top of its folder.
var wpCoreFileRe = regexp.MustCompile(`^(index\.php|license\.txt|readme\.html|xmlrpc\.php|wp-[a-z-]+\.php)$`)

// WPUpdateSpec updates WordPress itself (Core) and plugins and themes by
// name; ["*"] means every one with an update.
type WPUpdateSpec struct {
	App
	Run     string   `json:"run"`
	Core    bool     `json:"core"`
	Plugins []string `json:"plugins"`
	Themes  []string `json:"themes"`
	// Check is the address on the site requested before and after the
	// update to tell whether it broke the site, / normally.
	Check string `json:"check"`
}

// WPChange is one thing an update changed.
type WPChange struct {
	Kind string `json:"kind"`
	Name string `json:"name"`
	From string `json:"from"`
	To   string `json:"to"`
}

// WPUpdateResult describes a finished update.
type WPUpdateResult struct {
	// Point is the restore point taken, "" when there was nothing to do.
	Point   string     `json:"point"`
	Changes []WPChange `json:"changes"`
	// RolledBack is true when the site broke and the restore point was
	// put back.
	RolledBack bool `json:"rolled_back"`
	// Problem is what went wrong in an update that otherwise finished.
	Problem string `json:"problem,omitempty"`
	Millis  int64  `json:"ms"`
}

// wpPointMeta is meta.json of a restore point.
type wpPointMeta struct {
	Created time.Time  `json:"created"`
	Path    string     `json:"path"`
	Paths   []string   `json:"paths"`
	Version string     `json:"version"`
	Planned []WPChange `json:"planned"`
}

func (s WPUpdateSpec) validate() error {
	if err := s.App.validate(); err != nil {
		return err
	}
	if err := validateID("run", s.Run); err != nil {
		return err
	}
	if len(s.Plugins) > 500 || len(s.Themes) > 200 {
		return invalid("too many plugins or themes")
	}
	for _, n := range append(append([]string{}, s.Plugins...), s.Themes...) {
		if n != "*" && !wpSlugRe.MatchString(n) {
			return invalid("%q is not a plugin or theme name", n)
		}
	}
	if !strings.HasPrefix(s.Check, "/") || len(s.Check) > 200 || strings.ContainsAny(s.Check, " \r\n#") {
		return invalid("check must start with /")
	}
	return nil
}

// UpdateWordPress takes a restore point and runs the updates.
func (m *Manager) UpdateWordPress(ctx context.Context, spec WPUpdateSpec) (res WPUpdateResult, err error) {
	if err := spec.validate(); err != nil {
		return res, err
	}
	if err := m.validateWP(spec.App); err != nil {
		return res, err
	}
	unlock := m.lock(spec.Domain)
	defer unlock()
	log, err := m.openLog(wpRunDir(spec.Domain), spec.Run)
	if err != nil {
		return res, &OperationError{err}
	}
	defer log.Close()
	ctx, cancel := context.WithTimeout(ctx, wpTimeout)
	defer cancel()
	start := time.Now()
	r := &wpRun{deploy: &deploy{m: m, spec: DeploySpec{Target: spec.Target}, log: log, ctx: ctx, base: spec.dir(), dir: spec.dir()}, app: spec.App}
	defer func() {
		if err != nil {
			fmt.Fprintf(log, "✗ %s\n", err)
			err = &OperationError{err}
		}
	}()
	res.Changes = []WPChange{}

	r.step("Güncelleme başladı · %s", spec.Path)
	if err := m.ensureWPCLI(ctx, log); err != nil {
		return res, fmt.Errorf("WP-CLI could not be downloaded: %w", err)
	}
	plan, err := m.wpPlan(ctx, spec)
	if err != nil {
		return res, err
	}
	if len(plan) == 0 {
		r.step("Güncellenecek bir şey yok")
		fmt.Fprintf(log, "✓ %s\n", i18n.T(ctx, "Tamamlandı. Toplam %s.", humanDuration(ctx, time.Since(start))))
		return res, nil
	}
	for _, c := range plan {
		fmt.Fprintf(log, "  %s %s: %s → %s\n", c.Kind, c.Name, c.From, c.To)
	}
	before := m.Health(ctx, spec.Domain, spec.Check)

	r.step("Geri dönüş noktası alınıyor: veritabanı ve güncellenecek dosyalar")
	if err := m.wpTakePoint(ctx, spec, plan); err != nil {
		_ = os.RemoveAll(m.h.Path(wpPointDir(spec.Domain) + "/" + spec.Run))
		return res, fmt.Errorf("the restore point could not be taken, nothing was updated: %w", err)
	}
	res.Point = spec.Run

	var problems []string
	core, plugins, themes := []string{}, []string{}, []string{}
	for _, c := range plan {
		switch c.Kind {
		case "core":
			core = append(core, c.To)
		case "plugin":
			plugins = append(plugins, c.Name)
		case "theme":
			themes = append(themes, c.Name)
		}
	}
	if len(core) > 0 {
		r.step("WordPress güncelleniyor: %s", core[0])
		if err := r.wp("core update", "", "core", "update"); err != nil {
			problems = append(problems, err.Error())
		} else {
			if err := r.wp("core update-db", "", "core", "update-db"); err != nil {
				problems = append(problems, err.Error())
			}
			// New strings of the new version; a missing language pack is
			// not a problem.
			_ = r.wp("language core update", "", "language", "core", "update")
		}
	}
	if len(plugins) > 0 {
		r.step("Eklentiler güncelleniyor: %s", strings.Join(plugins, ", "))
		if err := r.wp("plugin update "+strings.Join(plugins, " "), "", append([]string{"plugin", "update"}, plugins...)...); err != nil {
			problems = append(problems, err.Error())
		}
	}
	if len(themes) > 0 {
		r.step("Temalar güncelleniyor: %s", strings.Join(themes, ", "))
		if err := r.wp("theme update "+strings.Join(themes, " "), "", append([]string{"theme", "update"}, themes...)...); err != nil {
			problems = append(problems, err.Error())
		}
	}
	res.Changes = m.wpChanges(ctx, spec.App, plan)

	after := m.Health(ctx, spec.Domain, spec.Check)
	if before.OK && !after.OK {
		code := fmt.Sprint(after.Status)
		if after.Status == 0 {
			code = after.Error
		}
		r.step("Site güncellemeden sonra yanıt vermiyor (HTTP %s); güncelleme öncesine dönülüyor", code)
		if err := m.wpRestore(r, spec.Run); err != nil {
			return res, fmt.Errorf("the site broke after the update and putting the restore point back failed: %w", err)
		}
		res.RolledBack = true
		res.Changes = []WPChange{}
		res.Problem = i18n.T(ctx, "Site güncellemeden sonra hata verdi (HTTP %s); güncelleme öncesine dönüldü.", code)
		fmt.Fprintf(log, "✗ %s\n", res.Problem)
		res.Millis = time.Since(start).Milliseconds()
		return res, nil
	}
	if after.Status != 0 {
		r.step("Site yanıt veriyor: HTTP %d", after.Status)
	}
	m.wpPrunePoints(spec.Domain, spec.Run)
	res.Millis = time.Since(start).Milliseconds()
	if len(problems) > 0 {
		res.Problem = strings.Join(problems, "; ")
		fmt.Fprintf(log, "✗ %s\n", res.Problem)
		return res, nil
	}
	fmt.Fprintf(log, "✓ %s\n", i18n.T(ctx, "Tamamlandı. Toplam %s.", humanDuration(ctx, time.Since(start))))
	return res, nil
}

// wpPlan is what the update will change: only what has an update.
func (m *Manager) wpPlan(ctx context.Context, spec WPUpdateSpec) ([]WPChange, error) {
	var plan []WPChange
	if spec.Core {
		version := ""
		if b, err := m.readFile(ctx, spec.Target, spec.dir()+"/wp-includes/version.php", 64<<10); err == nil {
			if mm := wpVersionRe.FindSubmatch(b); mm != nil {
				version = string(mm[1])
			}
		}
		var updates []struct {
			Version string `json:"version"`
		}
		if err := m.wpJSON(ctx, spec.App, true, &updates, "core", "check-update", "--format=json", "--fields=version"); err == nil {
			newest := ""
			for _, u := range updates {
				if newest == "" || wpVersionLess(newest, u.Version) {
					newest = u.Version
				}
			}
			if newest != "" && wpVersionLess(version, newest) {
				plan = append(plan, WPChange{Kind: "core", Name: "wordpress", From: version, To: newest})
			}
		}
	}
	for _, kind := range []string{"plugin", "theme"} {
		want := spec.Plugins
		if kind == "theme" {
			want = spec.Themes
		}
		if len(want) == 0 {
			continue
		}
		items, err := m.wpItems(ctx, spec.App, false, kind, "--fields=name,title,status,version,update_version,auto_update")
		if err != nil {
			if items, err = m.wpItems(ctx, spec.App, true, kind, "--fields=name,title,status,version,update_version,auto_update"); err != nil {
				return nil, fmt.Errorf("the %ss could not be listed: %w", kind, err)
			}
		}
		all := contains(want, "*")
		for _, it := range items {
			if it.Update != "" && (all || contains(want, it.Name)) && it.Status != "must-use" && it.Status != "dropin" {
				plan = append(plan, WPChange{Kind: kind, Name: it.Name, From: it.Version, To: it.Update})
			}
		}
	}
	return plan, nil
}

// wpChanges compares the versions after an update with the plan.
func (m *Manager) wpChanges(ctx context.Context, a App, plan []WPChange) []WPChange {
	now := map[string]string{}
	if b, err := m.readFile(ctx, a.Target, a.dir()+"/wp-includes/version.php", 64<<10); err == nil {
		if mm := wpVersionRe.FindSubmatch(b); mm != nil {
			now["core/wordpress"] = string(mm[1])
		}
	}
	for _, kind := range []string{"plugin", "theme"} {
		items, _ := m.wpItems(ctx, a, true, kind, "--fields=name,version")
		for _, it := range items {
			now[kind+"/"+it.Name] = it.Version
		}
	}
	out := []WPChange{}
	for _, c := range plan {
		if v := now[c.Kind+"/"+c.Name]; v != "" && v != c.From {
			out = append(out, WPChange{Kind: c.Kind, Name: c.Name, From: c.From, To: v})
		}
	}
	return out
}

// wpPointPaths are the folders and files an update replaces.
func (m *Manager) wpPointPaths(a App, plan []WPChange) []string {
	var paths []string
	add := func(rel string) {
		if m.exists(a.dir()+"/"+rel) && !contains(paths, rel) {
			paths = append(paths, rel)
		}
	}
	for _, c := range plan {
		switch c.Kind {
		case "core":
			add("wp-admin")
			add("wp-includes")
			add("wp-content/languages")
			entries, _ := os.ReadDir(m.h.Path(a.dir()))
			for _, e := range entries {
				if !e.IsDir() && e.Name() != "wp-config.php" && wpCoreFileRe.MatchString(e.Name()) {
					add(e.Name())
				}
			}
		case "plugin":
			if m.isDir(a.dir() + "/wp-content/plugins/" + c.Name) {
				add("wp-content/plugins/" + c.Name)
			} else {
				add("wp-content/plugins/" + c.Name + ".php")
			}
		case "theme":
			add("wp-content/themes/" + c.Name)
		}
	}
	sort.Strings(paths)
	return paths
}

// wpTakePoint writes the restore point of an update.
func (m *Manager) wpTakePoint(ctx context.Context, spec WPUpdateSpec, plan []WPChange) error {
	dir := wpPointDir(spec.Domain) + "/" + spec.Run
	if err := os.MkdirAll(m.h.Path(dir), 0o700); err != nil {
		return err
	}
	paths := m.wpPointPaths(spec.App, plan)
	if len(paths) > 0 {
		f, err := os.OpenFile(m.h.Path(dir+"/files.tar.gz"), os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o600)
		if err != nil {
			return err
		}
		defer f.Close()
		c := m.cmd(spec.Target, "/", nil, "tar", append([]string{"-czf", "-", "-C", spec.dir(), "--"}, paths...)...)
		c.Stdout = f
		if out, err := m.h.Run(ctx, c); err != nil {
			return fmt.Errorf("tar failed: %s", firstLine(out))
		}
		if err := f.Close(); err != nil {
			return err
		}
	}
	if err := m.toGzip(ctx, m.wpCmd(spec.App, nil, true, "db", "export", "-"), "wp db export", m.h.Path(dir+"/database.sql.gz")); err != nil {
		return err
	}
	version := ""
	if len(plan) > 0 && plan[0].Kind == "core" {
		version = plan[0].From
	}
	meta, _ := json.MarshalIndent(wpPointMeta{Created: time.Now().UTC(), Path: spec.Path, Paths: paths, Version: version, Planned: plan}, "", "  ")
	return os.WriteFile(m.h.Path(dir+"/meta.json"), meta, 0o600)
}

// WPRestoreSpec puts a restore point back.
type WPRestoreSpec struct {
	App
	Run   string `json:"run"`
	Point string `json:"point"`
}

// RestoreWordPress puts the database and files of a restore point back:
// everything changed since the update in them is lost.
func (m *Manager) RestoreWordPress(ctx context.Context, spec WPRestoreSpec) (err error) {
	if err := spec.App.validate(); err != nil {
		return err
	}
	if err := validateID("run", spec.Run); err != nil {
		return err
	}
	if err := validateID("point", spec.Point); err != nil {
		return err
	}
	if err := m.validateWP(spec.App); err != nil {
		return err
	}
	if !m.exists(wpPointDir(spec.Domain) + "/" + spec.Point + "/meta.json") {
		return invalid("restore point %s is no longer kept", spec.Point)
	}
	unlock := m.lock(spec.Domain)
	defer unlock()
	log, err := m.openLog(wpRunDir(spec.Domain), spec.Run)
	if err != nil {
		return &OperationError{err}
	}
	defer log.Close()
	ctx, cancel := context.WithTimeout(ctx, wpTimeout)
	defer cancel()
	start := time.Now()
	r := &wpRun{deploy: &deploy{m: m, spec: DeploySpec{Target: spec.Target}, log: log, ctx: ctx, base: spec.dir(), dir: spec.dir()}, app: spec.App}
	if err := m.ensureWPCLI(ctx, log); err != nil {
		fmt.Fprintf(log, "✗ %s\n", err)
		return &OperationError{err}
	}
	if err := m.wpRestore(r, spec.Point); err != nil {
		fmt.Fprintf(log, "✗ %s\n", err)
		return &OperationError{err}
	}
	fmt.Fprintf(log, "✓ %s\n", i18n.T(ctx, "Tamamlandı. Toplam %s.", humanDuration(ctx, time.Since(start))))
	return nil
}

// wpRestore puts restore point id back.
func (m *Manager) wpRestore(r *wpRun, id string) error {
	ctx, a := r.ctx, r.app
	dir := wpPointDir(a.Domain) + "/" + id
	var meta wpPointMeta
	b, err := os.ReadFile(m.h.Path(dir + "/meta.json"))
	if err != nil {
		return fmt.Errorf("restore point %s is no longer kept", id)
	}
	if err := json.Unmarshal(b, &meta); err != nil {
		return fmt.Errorf("restore point %s is damaged", id)
	}
	r.step("Geri dönüş noktası geri yükleniyor (%s)", meta.Created.Local().Format("2006-01-02 15:04"))
	if len(meta.Paths) > 0 {
		for _, p := range meta.Paths {
			if strings.Contains(p, "..") || strings.HasPrefix(p, "/") {
				return fmt.Errorf("restore point %s is damaged", id)
			}
			if err := r.user("rm", "-rf", "--one-file-system", a.dir()+"/"+p); err != nil {
				return err
			}
		}
		if err := m.userUntar(ctx, a.Target, m.h.Path(dir+"/files.tar.gz"), a.dir()); err != nil {
			return err
		}
		fmt.Fprintf(r.log, "  %s\n", strings.Join(meta.Paths, ", "))
	}
	f, err := os.Open(m.h.Path(dir + "/database.sql.gz"))
	if err != nil {
		return errors.New("the restore point has no database dump")
	}
	defer f.Close()
	gz, err := gzip.NewReader(f)
	if err != nil {
		return fmt.Errorf("the database dump is damaged: %w", err)
	}
	r.step("Veritabanı güncelleme öncesine döndürülüyor")
	c := m.wpCmd(a, nil, true, "db", "import", "-")
	c.Input = gz
	if out, err := m.h.Run(ctx, c); err != nil {
		return fmt.Errorf("wp db import failed: %s", firstLine(out))
	}
	return nil
}

// wpPrunePoints keeps the newest wpPointMax restore points of a site,
// none older than wpPointKeep; keep is never removed.
func (m *Manager) wpPrunePoints(domain, keep string) {
	base := m.h.Path(wpPointDir(domain))
	entries, err := os.ReadDir(base)
	if err != nil {
		return
	}
	type point struct {
		name string
		at   time.Time
	}
	var points []point
	for _, e := range entries {
		if fi, err := e.Info(); err == nil && e.IsDir() {
			points = append(points, point{e.Name(), fi.ModTime()})
		}
	}
	sort.Slice(points, func(i, j int) bool { return points[i].at.After(points[j].at) })
	for i, p := range points {
		if p.name != keep && (i >= wpPointMax || time.Since(p.at) > wpPointKeep) {
			_ = os.RemoveAll(filepath.Join(base, p.name))
		}
	}
}

// WordPressPoints lists the restore points kept for a site.
func (m *Manager) WordPressPoints(domain string) ([]string, error) {
	if err := (Target{Domain: domain, User: "x"}).validate(); err != nil {
		return nil, err
	}
	m.wpPrunePoints(domain, "")
	out := []string{}
	entries, _ := os.ReadDir(m.h.Path(wpPointDir(domain)))
	for _, e := range entries {
		if e.IsDir() && m.exists(wpPointDir(domain)+"/"+e.Name()+"/meta.json") {
			out = append(out, e.Name())
		}
	}
	return out, nil
}
