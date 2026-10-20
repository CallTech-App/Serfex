package laravel

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"regexp"
	"sort"
	"strings"
	"time"

	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/i18n"
)

// DeploySpec is one deploy of an application into laravel/releases.
type DeploySpec struct {
	Target
	// Run names the log the deploy writes to while it runs.
	Run string `json:"run"`
	// Release is the directory name of the new release.
	Release string `json:"release"`
	Source  Source `json:"source"`
	Build   Build  `json:"build"`
	Hooks   Hooks  `json:"hooks"`
	// Env is written into shared/.env on the first deploy, on top of the
	// application's .env.example (database settings, APP_KEY, APP_URL).
	Env map[string]string `json:"env"`
	// SeedFrom is an existing application directory (relative to the site
	// directory) whose .env and storage seed shared/ on the first deploy,
	// when an application found on the site moves to Servik deploys.
	SeedFrom string `json:"seed_from"`
	// Health is requested after the switch; nil skips the check.
	Health *HealthSpec `json:"health"`
	// Rollback switches back to the previous release when the health check
	// or the after-activate hook fails.
	Rollback bool `json:"rollback"`
	// Keep is how many releases stay on disk (default 5, at least 2).
	Keep int `json:"keep"`
	// KeyFrom is another site of the same account whose deploy key this
	// application uses when it has none (a preview environment uses the
	// key of the application it previews).
	KeyFrom string `json:"key_from"`
}

// Source is where a release's code comes from.
type Source struct {
	// Type is "git" (clone Repository at Ref) or "create" (composer
	// create-project of Package, for a new application).
	Type       string `json:"type"`
	Repository string `json:"repository"`
	Ref        string `json:"ref"`
	Package    string `json:"package"`
}

// Build says which build steps Servik runs.
type Build struct {
	Frontend bool `json:"frontend"`
	// PackageManager is npm, pnpm, yarn or bun; empty picks it from the
	// lock file.
	PackageManager string `json:"package_manager"`
	Migrate        bool   `json:"migrate"`
}

// Hooks are the customer's own shell scripts, run with bash -e as the
// site's account in the release directory.
type Hooks struct {
	// BeforeBuild runs after the code is fetched and shared/ is linked.
	BeforeBuild string `json:"before_build"`
	// BeforeActivate runs after the build and migrations, before the switch.
	BeforeActivate string `json:"before_activate"`
	// AfterActivate runs after the switch; a failure counts like a failed
	// health check.
	AfterActivate string `json:"after_activate"`
}

// HealthSpec is the URL path requested after the switch.
type HealthSpec struct {
	Path string `json:"path"`
}

// HealthResult is the answer to a health check.
type HealthResult struct {
	OK     bool   `json:"ok"`
	Status int    `json:"status"`
	Millis int64  `json:"ms"`
	Error  string `json:"error,omitempty"`
}

// DeployResult describes a finished deploy.
type DeployResult struct {
	Release        string        `json:"release"`
	Commit         string        `json:"commit"`
	Message        string        `json:"message"`
	Laravel        string        `json:"laravel"`
	PHPRequirement string        `json:"php_requirement"`
	PackageManager string        `json:"package_manager"`
	Health         *HealthResult `json:"health"`
	// Problem is set when the health check or the after-activate hook
	// failed. RolledBack says whether the previous release is live again;
	// otherwise the new release stays live (there was no previous one, or
	// Rollback was off).
	Problem    string    `json:"problem,omitempty"`
	RolledBack bool      `json:"rolled_back"`
	Previous   string    `json:"previous"`
	Current    string    `json:"current"`
	Releases   []Release `json:"releases"`
	Millis     int64     `json:"ms"`
}

// Release is a release directory on disk.
type Release struct {
	Name     string    `json:"name"`
	Modified time.Time `json:"modified"`
}

var (
	refRe     = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._/-]{0,199}$`)
	packageRe = regexp.MustCompile(`^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$`)
	repoRe    = regexp.MustCompile(`^(https://[A-Za-z0-9.-]+(:[0-9]+)?/[A-Za-z0-9._~/-]+|ssh://[A-Za-z0-9._-]+@[A-Za-z0-9.-]+(:[0-9]+)?/[A-Za-z0-9._~/-]+|[A-Za-z0-9._-]+@[A-Za-z0-9.-]+:[A-Za-z0-9._~/-]+)$`)
)

const deployTimeout = 30 * time.Minute

func (s DeploySpec) validate() error {
	if err := s.Target.validatePHP(); err != nil {
		return err
	}
	if err := validateID("run", s.Run); err != nil {
		return err
	}
	if err := validateID("release", s.Release); err != nil {
		return err
	}
	switch s.Source.Type {
	case "git":
		if !repoRe.MatchString(s.Source.Repository) || strings.Contains(s.Source.Repository, "..") {
			return invalid("repository %q must be an https:// or SSH address such as git@github.com:owner/app.git", s.Source.Repository)
		}
		if !refRe.MatchString(s.Source.Ref) || strings.Contains(s.Source.Ref, "..") {
			return invalid("ref %q must be a branch or tag name", s.Source.Ref)
		}
	case "create":
		if !packageRe.MatchString(s.Source.Package) {
			return invalid("package %q must be a Composer package such as laravel/laravel", s.Source.Package)
		}
	default:
		return invalid(`source.type must be "git" or "create"`)
	}
	switch s.Build.PackageManager {
	case "", "npm", "pnpm", "yarn", "bun":
	default:
		return invalid("package_manager must be npm, pnpm, yarn or bun")
	}
	for k := range s.Env {
		if !envKeyRe.MatchString(k) {
			return invalid("env key %q is not a valid name", k)
		}
	}
	if s.SeedFrom != "" {
		if err := validRel(s.SeedFrom); err != nil {
			return err
		}
		if s.SeedFrom == Base || strings.HasPrefix(s.SeedFrom, Base+"/") {
			return invalid("seed_from must be outside %s/", Base)
		}
	}
	if s.Health != nil && (!strings.HasPrefix(s.Health.Path, "/") || len(s.Health.Path) > 200 || strings.ContainsAny(s.Health.Path, " \r\n#")) {
		return invalid("health.path must start with / (for example /up)")
	}
	for _, h := range []string{s.Hooks.BeforeBuild, s.Hooks.BeforeActivate, s.Hooks.AfterActivate} {
		if len(h) > 16<<10 {
			return invalid("hooks must be shorter than 16 KB")
		}
	}
	if s.KeyFrom != "" {
		if err := (Target{Domain: s.KeyFrom, User: s.User}).validate(); err != nil {
			return err
		}
	}
	if s.Keep < 0 || s.Keep > 50 {
		return invalid("keep must be between 2 and 50")
	}
	return nil
}

func validRel(p string) error {
	if err := (App{Target: Target{Domain: "example.com", User: "x", PHPVersion: "8.4"}, Path: p}).validate(); err != nil {
		return err
	}
	return nil
}

// deploy holds the state of one running deploy.
type deploy struct {
	m    *Manager
	spec DeploySpec
	log  io.Writer
	ctx  context.Context
	base string
	dir  string
	// env is added to the environment of every step (Git's SSH settings).
	env []string
}

func (d *deploy) step(format string, a ...any) {
	fmt.Fprintf(d.log, "▸ "+i18n.Translate(d.ctx, format)+"\n", a...)
}

// exec runs a step as the site's account in dir, streaming its output.
func (d *deploy) exec(dir, shown, name string, args ...string) error {
	fmt.Fprintf(d.log, "$ %s\n", shown)
	_, err := d.m.h.Run(d.ctx, d.m.cmdEnv(d.spec.Target, dir, d.log, d.env, name, args...))
	if err != nil {
		if d.ctx.Err() != nil {
			return fmt.Errorf("%s stopped: the deploy took longer than %d minutes", shown, int(deployTimeout.Minutes()))
		}
		return fmt.Errorf("%s failed with exit code %d", shown, host0(err))
	}
	return nil
}

// user runs a quiet helper command (mkdir, ln, mv) as the site's account.
func (d *deploy) user(name string, args ...string) error {
	if _, err := d.m.userRun(d.ctx, d.spec.Target, "/", name, args...); err != nil {
		return fmt.Errorf("%s %s failed", name, strings.Join(args, " "))
	}
	return nil
}

func (d *deploy) hook(name, script string) error {
	if strings.TrimSpace(script) == "" {
		return nil
	}
	d.step("Kanca: %s", name)
	fmt.Fprintln(d.log, indent(strings.TrimSpace(script), "  "))
	if _, err := d.m.h.Run(d.ctx, d.m.cmd(d.spec.Target, d.dir, d.log, "bash", "-e", "-o", "pipefail", "-c", script)); err != nil {
		return fmt.Errorf("the %s hook failed with exit code %d", name, host0(err))
	}
	return nil
}

func (d *deploy) artisan(args ...string) error {
	return d.exec(d.dir, "php artisan "+strings.Join(args, " "), phpBin(d.spec.PHPVersion), append([]string{"artisan"}, args...)...)
}

// Deploy builds a new release and switches the site to it. Until the
// switch nothing the visitors see changes; a failure removes the new
// release. After the switch, a failed health check switches back.
func (m *Manager) Deploy(ctx context.Context, spec DeploySpec) (res DeployResult, err error) {
	if err := spec.validate(); err != nil {
		return DeployResult{}, err
	}
	if spec.Keep == 0 {
		spec.Keep = 5
	}
	if spec.Keep < 2 {
		spec.Keep = 2
	}
	if !m.isDir(siteDir(spec.Target)) {
		return DeployResult{}, invalid("site %s does not exist", spec.Domain)
	}
	if spec.SeedFrom != "" && !m.exists(siteDir(spec.Target)+"/"+spec.SeedFrom+"/artisan") {
		return DeployResult{}, invalid("no Laravel application at %s", spec.SeedFrom)
	}
	unlock := m.lock(spec.Domain)
	defer unlock()

	log, err := m.openRun(spec.Domain, spec.Run)
	if err != nil {
		return DeployResult{}, &OperationError{err}
	}
	defer log.Close()
	ctx, cancel := context.WithTimeout(ctx, deployTimeout)
	defer cancel()

	start := time.Now()
	base := baseDir(spec.Target)
	d := &deploy{m: m, spec: spec, log: log, ctx: ctx, base: base, dir: base + "/releases/" + spec.Release}
	defer func() {
		if err != nil {
			fmt.Fprintf(log, "✗ %s\n", err)
			err = &OperationError{err}
		}
	}()

	if spec.Source.Type == "git" {
		d.step("Dağıtım başladı · %s · %s", spec.Source.Ref, spec.Source.Repository)
	} else {
		d.step("Kurulum başladı · %s", spec.Source.Package)
	}
	if err := m.ensureTools(ctx, log, spec.PHPVersion, spec.Build.Frontend); err != nil {
		return DeployResult{}, err
	}
	if err := d.user("mkdir", "-p", base+"/releases", base+"/shared"); err != nil {
		return DeployResult{}, err
	}
	previous := m.currentRelease(spec.Target)
	if spec.Release == previous {
		return DeployResult{}, fmt.Errorf("release %s is the live release", spec.Release)
	}
	if m.exists(d.dir) {
		if err := d.user("rm", "-rf", "--one-file-system", d.dir); err != nil {
			return DeployResult{}, err
		}
	}

	activated := false
	defer func() {
		if err != nil && !activated {
			// Nothing served the new release yet, so it simply goes.
			_, _ = m.userRun(context.WithoutCancel(ctx), spec.Target, "/", "rm", "-rf", "--one-file-system", d.dir)
		}
	}()

	if err := d.fetch(&res); err != nil {
		return DeployResult{}, err
	}
	if err := d.linkShared(); err != nil {
		return DeployResult{}, err
	}
	if err := d.hook(i18n.T(ctx, "derlemeden önce"), spec.Hooks.BeforeBuild); err != nil {
		return DeployResult{}, err
	}
	if err := d.build(&res); err != nil {
		return DeployResult{}, err
	}
	if err := d.hook(i18n.T(ctx, "yayına almadan önce"), spec.Hooks.BeforeActivate); err != nil {
		return DeployResult{}, err
	}

	// The switch: one rename of a symlink.
	if err := m.activate(ctx, spec.Target, spec.Release); err != nil {
		return DeployResult{}, err
	}
	activated = true
	d.step("current → releases/%s (tek adımda geçiş)", spec.Release)
	m.afterSwitch(ctx, log, spec.Target)

	problem := ""
	if err := d.hook(i18n.T(ctx, "yayına aldıktan sonra"), spec.Hooks.AfterActivate); err != nil {
		problem = err.Error()
	}
	if problem == "" && spec.Health != nil {
		h := m.Health(ctx, spec.Domain, spec.Health.Path)
		res.Health = &h
		if h.OK {
			d.step("Sağlık kontrolü: GET %s → %d (%d ms) ✓", spec.Health.Path, h.Status, h.Millis)
		} else {
			what := h.Error
			if what == "" {
				what = fmt.Sprintf("HTTP %d", h.Status)
			}
			d.step("Sağlık kontrolü: GET %s → %s ✗", spec.Health.Path, what)
			problem = "health check failed: " + what
		}
	}
	res.Previous, res.Problem = previous, problem
	if problem != "" && previous != "" && spec.Rollback {
		if err := m.activate(ctx, spec.Target, previous); err != nil {
			return DeployResult{}, fmt.Errorf("%s, and switching back to %s failed: %w", problem, previous, err)
		}
		m.afterSwitch(ctx, log, spec.Target)
		_, _ = m.userRun(ctx, spec.Target, "/", "rm", "-rf", "--one-file-system", d.dir)
		fmt.Fprintf(log, "✗ %s\n", i18n.T(ctx, "Sürüm geri alındı: current → releases/%s. Ziyaretçiler önceki sürümü görmeye devam etti.", previous))
		res.RolledBack = true
	} else if problem != "" {
		fmt.Fprintln(log, "⚠ "+i18n.T(ctx, "Yeni sürüm yayında, ama sorun giderilmeli."))
	}

	m.prune(ctx, spec.Target, spec.Keep, log)
	res.Release = spec.Release
	res.Current = m.currentRelease(spec.Target)
	res.Releases = m.releases(spec.Target)
	res.Millis = time.Since(start).Milliseconds()
	if problem == "" {
		fmt.Fprintf(log, "✓ %s\n", i18n.T(ctx, "releases/%s yayında. Toplam %s.", spec.Release, humanDuration(time.Since(start))))
	}
	return res, nil
}

// fetch puts the code of the release in place.
func (d *deploy) fetch(res *DeployResult) error {
	s := d.spec.Source
	if s.Type == "create" {
		// --no-install: dependencies are installed by the build step, after
		// shared/.env is in place, like for every later release.
		return d.exec(d.base, "composer create-project "+s.Package, phpBin(d.spec.PHPVersion), composerPhar(),
			"create-project", "--no-install", "--no-scripts", "--no-interaction", "--prefer-dist", s.Package, d.dir)
	}
	d.env = gitEnv(d.base)
	if err := d.copyKey(); err != nil {
		return err
	}
	if err := d.exec(d.base, "git clone --depth 1 --branch "+s.Ref+" "+s.Repository, "git",
		"clone", "--depth", "1", "--branch", s.Ref, "--single-branch", "--recurse-submodules", "--shallow-submodules", "--", s.Repository, d.dir); err != nil {
		return err
	}
	if out, err := d.m.userRun(d.ctx, d.spec.Target, d.dir, "git", "rev-parse", "HEAD"); err == nil {
		res.Commit = firstLine(out)
	}
	if out, err := d.m.userRun(d.ctx, d.spec.Target, d.dir, "git", "log", "-1", "--format=%s"); err == nil {
		res.Message = firstLine(out)
	}
	if len(res.Commit) >= 7 {
		d.step("Kod çekildi: %s %s", res.Commit[:7], res.Message)
	}
	return nil
}

// copyKey copies the deploy key of KeyFrom when the application has none.
// Both sites belong to the same account, which does the copying.
func (d *deploy) copyKey() error {
	if d.spec.KeyFrom == "" || d.m.exists(d.base+"/.ssh/id_ed25519") {
		return nil
	}
	from := baseDir(Target{Domain: d.spec.KeyFrom, User: d.spec.User}) + "/.ssh"
	if !d.m.exists(from + "/id_ed25519") {
		return nil
	}
	if err := d.user("mkdir", "-p", d.base+"/.ssh"); err != nil {
		return err
	}
	if err := d.user("chmod", "700", d.base+"/.ssh"); err != nil {
		return err
	}
	for _, f := range []string{"id_ed25519", "id_ed25519.pub", "known_hosts"} {
		if d.m.exists(from + "/" + f) {
			if err := d.user("cp", "-a", from+"/"+f, d.base+"/.ssh/"+f); err != nil {
				return err
			}
		}
	}
	d.step("Dağıtım anahtarı %s uygulamasından alındı", d.spec.KeyFrom)
	return nil
}

// linkShared links .env and storage/ of the release to shared/, creating
// them on the first deploy.
func (d *deploy) linkShared() error {
	m, t := d.m, d.spec.Target
	shared := d.base + "/shared"
	site := siteDir(t)
	if !m.exists(shared + "/.env") {
		content := ""
		switch {
		case d.spec.SeedFrom != "" && m.exists(site+"/"+d.spec.SeedFrom+"/.env"):
			b, err := m.readFile(d.ctx, t, site+"/"+d.spec.SeedFrom+"/.env", maxEnv)
			if err != nil {
				return err
			}
			content = string(b)
			d.step(".env, %s içinden kopyalandı", d.spec.SeedFrom)
		case m.exists(d.dir + "/.env.example"):
			b, err := m.readFile(d.ctx, t, d.dir+"/.env.example", maxEnv)
			if err != nil {
				return err
			}
			content = string(b)
			d.step(".env, .env.example temel alınarak oluşturuldu")
		}
		if len(d.spec.Env) > 0 {
			content = setEnv(content, d.spec.Env)
		}
		if err := m.writeFile(d.ctx, t, shared+"/.env", []byte(content), "600"); err != nil {
			return err
		}
	}
	if !m.exists(shared + "/storage") {
		switch {
		case d.spec.SeedFrom != "" && m.isDir(site+"/"+d.spec.SeedFrom+"/storage"):
			if err := d.user("cp", "-a", site+"/"+d.spec.SeedFrom+"/storage", shared+"/storage"); err != nil {
				return err
			}
		case m.isDir(d.dir + "/storage"):
			if err := d.user("mv", "-T", d.dir+"/storage", shared+"/storage"); err != nil {
				return err
			}
		}
	}
	if err := d.user("mkdir", "-p", shared+"/storage/app/public", shared+"/storage/framework/cache/data",
		shared+"/storage/framework/sessions", shared+"/storage/framework/views", shared+"/storage/logs"); err != nil {
		return err
	}
	// A SQLite database in shared/ survives releases.
	env := parseEnv(m.envContent(d.ctx, t, shared+"/.env"))
	if db := env["DB_DATABASE"]; env["DB_CONNECTION"] == "sqlite" && strings.HasPrefix(db, shared+"/") && !m.exists(db) {
		if err := d.user("mkdir", "-p", dirOf(db)); err != nil {
			return err
		}
		if err := m.writeFile(d.ctx, t, db, nil, "600"); err != nil {
			return err
		}
		d.step("SQLite veritabanı oluşturuldu: %s", strings.TrimPrefix(db, site+"/"))
	}
	for _, name := range []string{"storage", ".env"} {
		if m.exists(d.dir + "/" + name) {
			if err := d.user("rm", "-rf", "--one-file-system", d.dir+"/"+name); err != nil {
				return err
			}
		}
	}
	if err := d.user("ln", "-s", "../../shared/storage", d.dir+"/storage"); err != nil {
		return err
	}
	if err := d.user("ln", "-s", "../../shared/.env", d.dir+"/.env"); err != nil {
		return err
	}
	d.step("storage/ ve .env paylaşılan dizine bağlandı")
	return nil
}

func (m *Manager) envContent(ctx context.Context, t Target, abs string) string {
	b, err := m.readFile(ctx, t, abs, maxEnv)
	if err != nil {
		return ""
	}
	return string(b)
}

// build installs dependencies, builds the front end and prepares Laravel.
func (d *deploy) build(res *DeployResult) error {
	m := d.m
	if m.exists(d.dir + "/composer.json") {
		if err := d.exec(d.dir, "composer install --no-dev --optimize-autoloader", phpBin(d.spec.PHPVersion), composerPhar(),
			"install", "--no-dev", "--no-interaction", "--prefer-dist", "--optimize-autoloader", "--no-progress"); err != nil {
			return err
		}
	}
	if !m.exists(d.dir + "/artisan") {
		return errors.New("the code has no artisan file; is this a Laravel application?")
	}
	info := m.appInfo(d.ctx, App{Target: d.spec.Target, Path: Base + "/releases/" + d.spec.Release})
	res.Laravel, res.PHPRequirement = info.Laravel, info.PHPRequirement
	if env := parseEnv(m.envContent(d.ctx, d.spec.Target, d.base+"/shared/.env")); env["APP_KEY"] == "" {
		if err := d.artisan("key:generate", "--force"); err != nil {
			return err
		}
	}
	pm := d.spec.Build.PackageManager
	if pm == "" {
		pm = info.PackageManager
	}
	res.PackageManager = pm
	if d.spec.Build.Frontend && m.exists(d.dir+"/package.json") {
		install := map[string][]string{
			"npm":  {"npm", "install", "--no-audit"},
			"pnpm": {"corepack", "pnpm", "install", "--frozen-lockfile"},
			"yarn": {"corepack", "yarn", "install", "--frozen-lockfile"},
			"bun":  {"npx", "--yes", "bun", "install", "--frozen-lockfile"},
		}[pm]
		if pm == "npm" && m.exists(d.dir+"/package-lock.json") {
			install = []string{"npm", "ci", "--no-audit"}
		}
		run := map[string][]string{
			"npm":  {"npm", "run", "build"},
			"pnpm": {"corepack", "pnpm", "run", "build"},
			"yarn": {"corepack", "yarn", "run", "build"},
			"bun":  {"npx", "--yes", "bun", "run", "build"},
		}[pm]
		if err := d.exec(d.dir, strings.Join(trimCorepack(install), " "), install[0], install[1:]...); err != nil {
			return err
		}
		if err := d.exec(d.dir, strings.Join(trimCorepack(run), " "), run[0], run[1:]...); err != nil {
			return err
		}
	}
	// storage:link fails when the link exists; that is fine.
	_, _ = m.h.Run(d.ctx, m.cmd(d.spec.Target, d.dir, nil, phpBin(d.spec.PHPVersion), "artisan", "storage:link"))
	if d.spec.Build.Migrate {
		if err := d.artisan("migrate", "--force"); err != nil {
			return err
		}
	}
	return d.artisan("optimize")
}

func trimCorepack(args []string) []string {
	if args[0] == "corepack" || (args[0] == "npx" && len(args) > 2 && args[2] == "bun") {
		if args[0] == "npx" {
			return args[2:]
		}
		return args[1:]
	}
	return args
}

// gitEnv makes Git use the application's deploy key, when it has one.
// Host keys are learnt on first use and pinned in known_hosts.
func gitEnv(base string) []string {
	key := base + "/.ssh/id_ed25519"
	return []string{"GIT_SSH_COMMAND=ssh -i " + key + " -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=" + base + "/.ssh/known_hosts -o BatchMode=yes", "GIT_TERMINAL_PROMPT=0"}
}

// activate points current at release with an atomic rename.
func (m *Manager) activate(ctx context.Context, t Target, release string) error {
	base := baseDir(t)
	tmp := base + "/.current-new"
	if m.exists(tmp) {
		if _, err := m.userRun(ctx, t, "/", "rm", "-f", tmp); err != nil {
			return errors.New("removing a stale .current-new failed")
		}
	}
	if _, err := m.userRun(ctx, t, "/", "ln", "-s", "releases/"+release, tmp); err != nil {
		return errors.New("creating the current link failed")
	}
	if _, err := m.userRun(ctx, t, "/", "mv", "-T", tmp, base+"/current"); err != nil {
		return errors.New("switching the current link failed")
	}
	return nil
}

// afterSwitch makes the running processes pick up the live release:
// PHP-FPM drops its opcode cache and queue workers finish their job and
// restart in the new release.
func (m *Manager) afterSwitch(ctx context.Context, w io.Writer, t Target) {
	ctx = context.WithoutCancel(ctx)
	msg := i18n.T(ctx, "PHP-FPM yenilendi")
	if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"reload", "php" + t.PHPVersion + "-fpm"}}); err != nil {
		msg = i18n.T(ctx, "PHP-FPM yenilenemedi")
	}
	if m.hasWorkers(t.Domain) {
		if m.restartWorkers(ctx, t.Domain) == nil {
			msg += " · " + i18n.T(ctx, "kuyruk işçileri yeniden başlatıldı")
		} else {
			msg += " · " + i18n.T(ctx, "kuyruk işçileri yeniden başlatılamadı")
		}
	}
	if len(m.enabledProcesses(t.Domain)) > 0 {
		if m.restartProcesses(ctx, t.Domain) == nil {
			msg += " · " + i18n.T(ctx, "süreçler yeniden başlatıldı")
		} else {
			msg += " · " + i18n.T(ctx, "süreçler yeniden başlatılamadı")
		}
	}
	fmt.Fprintf(w, "▸ %s\n", msg)
}

// currentRelease returns the release current points at, or "".
func (m *Manager) currentRelease(t Target) string {
	target, err := os.Readlink(m.h.Path(baseDir(t) + "/current"))
	if err != nil {
		return ""
	}
	name, ok := strings.CutPrefix(target, "releases/")
	if !ok || strings.Contains(name, "/") {
		return ""
	}
	return name
}

// releases lists the release directories, newest first.
func (m *Manager) releases(t Target) []Release {
	entries, err := os.ReadDir(m.h.Path(baseDir(t) + "/releases"))
	if err != nil {
		return []Release{}
	}
	out := []Release{}
	for _, e := range entries {
		fi, err := e.Info()
		if err != nil || !fi.IsDir() || !idRe.MatchString(e.Name()) {
			continue
		}
		out = append(out, Release{Name: e.Name(), Modified: fi.ModTime().UTC()})
	}
	sort.Slice(out, func(i, j int) bool {
		if !out[i].Modified.Equal(out[j].Modified) {
			return out[i].Modified.After(out[j].Modified)
		}
		return out[i].Name > out[j].Name
	})
	return out
}

// prune keeps the newest keep releases, and always current.
func (m *Manager) prune(ctx context.Context, t Target, keep int, w io.Writer) {
	current := m.currentRelease(t)
	var removed []string
	for i, r := range m.releases(t) {
		if i < keep || r.Name == current {
			continue
		}
		if _, err := m.userRun(ctx, t, "/", "rm", "-rf", "--one-file-system", baseDir(t)+"/releases/"+r.Name); err == nil {
			removed = append(removed, r.Name)
		}
	}
	if len(removed) > 0 {
		fmt.Fprintf(w, "▸ %s\n", i18n.T(ctx, "Eski sürümler silindi: %s (son %d sürüm saklanıyor)", strings.Join(removed, ", "), keep))
	}
}

// RollbackSpec switches an application to an earlier release.
type RollbackSpec struct {
	Target
	Release string `json:"release"`
}

// RollbackResult says which release is live now.
type RollbackResult struct {
	Previous string `json:"previous"`
	Current  string `json:"current"`
}

// Rollback makes an existing release live again. Migrations are not
// reverted.
func (m *Manager) Rollback(ctx context.Context, spec RollbackSpec) (RollbackResult, error) {
	if err := spec.Target.validatePHP(); err != nil {
		return RollbackResult{}, err
	}
	if err := validateID("release", spec.Release); err != nil {
		return RollbackResult{}, err
	}
	unlock := m.lock(spec.Domain)
	defer unlock()
	if !m.isDir(baseDir(spec.Target) + "/releases/" + spec.Release) {
		return RollbackResult{}, invalid("release %s is no longer on the server", spec.Release)
	}
	previous := m.currentRelease(spec.Target)
	if previous == spec.Release {
		return RollbackResult{Previous: previous, Current: previous}, nil
	}
	if err := m.activate(ctx, spec.Target, spec.Release); err != nil {
		return RollbackResult{}, &OperationError{err}
	}
	m.afterSwitch(ctx, io.Discard, spec.Target)
	return RollbackResult{Previous: previous, Current: spec.Release}, nil
}

// AppInfo is what the code says about itself.
type AppInfo struct {
	Laravel        string `json:"laravel"`
	PHPRequirement string `json:"php_requirement"`
	PackageManager string `json:"package_manager"`
	// Packages are the versions of the Laravel packages Servik can run
	// processes for (Horizon, Octane, Reverb), when installed.
	Packages map[string]string `json:"packages"`
}

// processPackages are the packages AppInfo reports.
var processPackages = map[string]bool{"laravel/horizon": true, "laravel/octane": true, "laravel/reverb": true, "laravel/pulse": true}

// appInfo reads the Laravel version from composer.lock, the PHP
// requirement from composer.json and the package manager from the lock
// file the application ships.
func (m *Manager) appInfo(ctx context.Context, a App) AppInfo {
	dir := a.dir()
	info := AppInfo{PackageManager: "npm", Packages: map[string]string{}}
	for _, l := range []struct{ file, pm string }{{"pnpm-lock.yaml", "pnpm"}, {"yarn.lock", "yarn"}, {"bun.lock", "bun"}, {"bun.lockb", "bun"}} {
		if m.exists(dir + "/" + l.file) {
			info.PackageManager = l.pm
			break
		}
	}
	if b, err := m.readFile(ctx, a.Target, dir+"/composer.json", 1<<20); err == nil {
		var c struct {
			Require map[string]string `json:"require"`
		}
		if json.Unmarshal(b, &c) == nil {
			info.PHPRequirement = c.Require["php"]
		}
	}
	if b, err := m.readFile(ctx, a.Target, dir+"/composer.lock", 16<<20); err == nil {
		var lock struct {
			Packages []struct {
				Name    string `json:"name"`
				Version string `json:"version"`
			} `json:"packages"`
			Platform map[string]string `json:"platform"`
		}
		if json.Unmarshal(b, &lock) == nil {
			for _, p := range lock.Packages {
				if p.Name == "laravel/framework" {
					info.Laravel = strings.TrimPrefix(p.Version, "v")
				}
				if processPackages[p.Name] {
					info.Packages[p.Name] = strings.TrimPrefix(p.Version, "v")
				}
			}
			if info.PHPRequirement == "" {
				info.PHPRequirement = lock.Platform["php"]
			}
		}
	}
	return info
}

func dirOf(p string) string {
	i := strings.LastIndexByte(p, '/')
	if i <= 0 {
		return "/"
	}
	return p[:i]
}

func indent(s, prefix string) string {
	return prefix + strings.ReplaceAll(s, "\n", "\n"+prefix)
}

func humanDuration(d time.Duration) string {
	d = d.Round(time.Second)
	if d < time.Minute {
		return fmt.Sprintf("%d sn", int(d.Seconds()))
	}
	return fmt.Sprintf("%d dk %02d sn", int(d.Minutes()), int(d.Seconds())%60)
}
