package component

import (
	"archive/tar"
	"bufio"
	"compress/gzip"
	"context"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/winchester37/servik/agent/internal/aptcache"
	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/i18n"
	"github.com/winchester37/servik/agent/internal/txn"
)

// component describes one piece of software and how Servik handles it.
// Most are apt packages with optional Servik configuration; Node.js,
// Composer and the database servers bring their own install and remove.
type component struct {
	id string
	// packages are installed with apt; extra adds packages that depend on
	// what else is installed (PHP extensions for Redis, Memcached).
	packages []string
	extra    func(m *Manager) []string
	// purge are the apt patterns removal purges (default: packages).
	purge func(m *Manager) []string
	// marker is a file that exists while it is installed.
	marker string
	// versionPkg is the package whose version is shown.
	versionPkg string
	// units are started after an install and stopped before a removal.
	units []string
	// backup are archived before a removal.
	backup []string
	// required says why it can never be removed.
	required string
	// ppa adds the Ondřej Surý PHP archive when Ubuntu lacks the packages.
	ppa bool

	installed func(m *Manager) bool
	unitList  func(m *Manager) []string
	version   func(ctx context.Context, m *Manager) string
	latest    func(ctx context.Context, m *Manager) (string, error)
	// candidate is what an install would bring, for components without
	// versionPkg; probe allows reading archives apt does not list yet.
	// Without it, latest from the address in from tells (Node.js,
	// Composer).
	candidate     func(ctx context.Context, m *Manager, probe bool) (aptcache.Candidate, error)
	from          string
	inUse         func(m *Manager) []string
	isDefault     func(m *Manager) bool
	settings      func(m *Manager) map[string]any
	applySettings func(o *op, s Settings) error

	// Hooks of the apt flow, all optional. configure must be idempotent:
	// it also runs after updates and repairs.
	beforeInstall func(o *op) error
	configure     func(o *op) error
	check         func(o *op) error
	unconfigure   func(o *op) error

	// Own flows replace the apt flow.
	install func(o *op) error
	remove  func(o *op) error
	update  func(o *op) error
}

func (c *component) isInstalled(m *Manager) bool {
	if c.installed != nil {
		return c.installed(m)
	}
	return c.marker != "" && m.exists(c.marker)
}

func (c *component) pkg(m *Manager) string { return c.versionPkg }

func (c *component) unitsOf(m *Manager) []string {
	if c.unitList != nil {
		return c.unitList(m)
	}
	return c.units
}

func (c *component) allPackages(m *Manager) []string {
	pkgs := append([]string(nil), c.packages...)
	if c.extra != nil {
		pkgs = append(pkgs, c.extra(m)...)
	}
	return pkgs
}

// op is one running operation.
type op struct {
	ctx  context.Context
	m    *Manager
	c    *component
	w    io.Writer
	t    *txn.Tx
	port int
	// added are the packages this operation installed.
	added []string
	// ppaAdded is the archive file this operation added.
	ppaAdded string
}

func (o *op) step(format string, a ...any) {
	fmt.Fprintf(o.w, "▸ "+i18n.Translate(o.ctx, format)+"\n", a...)
}

// failpoint lets tests break an operation at a named step.
func (o *op) failpoint(step string) error {
	if o.m.Fail != nil {
		return o.m.Fail(o.c.id, step)
	}
	return nil
}

// run runs a command with its output in the log.
func (o *op) run(c host.Command) (string, error) {
	fmt.Fprintf(o.w, "$ %s\n", c)
	c.Stream = o.w
	return o.m.h.Run(o.ctx, c)
}

// quiet runs a command, logging only a failure.
func (o *op) quiet(c host.Command) (string, error) {
	out, err := o.m.h.Run(o.ctx, c)
	if err != nil {
		fmt.Fprintf(o.w, "$ %s\n%s\n", c, strings.TrimSpace(out))
	}
	return out, err
}

func (o *op) systemctl(args ...string) error {
	_, err := o.run(host.Command{Name: "systemctl", Args: args})
	return err
}

var aptEnv = []string{"DEBIAN_FRONTEND=noninteractive", "NEEDRESTART_MODE=a"}

// aptOpts keep configuration files Servik wrote before a package arrived
// (Apache's ports.conf) and never ask questions.
var aptOpts = []string{"-y", "-q", "--no-install-recommends", "-o", "Dpkg::Options::=--force-confdef", "-o", "Dpkg::Options::=--force-confold"}

// aptInstall installs the packages that are missing and remembers them
// for a rollback. Stale package lists are refreshed once.
func (o *op) aptInstall(pkgs []string) error {
	var missing []string
	for _, p := range pkgs {
		if !o.m.packageInstalled(o.ctx, p) {
			missing = append(missing, p)
		}
	}
	if len(missing) == 0 {
		return nil
	}
	if o.c.ppa {
		if err := o.ensureArchive(missing[0]); err != nil {
			return err
		}
	}
	o.step("Paketler kuruluyor: %s", strings.Join(missing, " "))
	// Recorded first: a half-finished install is purged too.
	o.added = append(o.added, missing...)
	inst := host.Command{Name: "apt-get", Args: append(append([]string{"install"}, aptOpts...), missing...), Env: aptEnv}
	if _, err := o.run(inst); err == nil {
		return nil
	}
	o.step("Paket listeleri yenileniyor")
	if _, err := o.run(host.Command{Name: "apt-get", Args: []string{"update", "-q"}, Env: aptEnv}); err != nil {
		return fmt.Errorf("apt-get update failed: %w", err)
	}
	if _, err := o.run(inst); err != nil {
		return fmt.Errorf("installing %s failed: %w", strings.Join(missing, " "), err)
	}
	return nil
}

// ppaFile is where add-apt-repository writes the PHP archive.
const ppaFile = "/etc/apt/sources.list.d/ondrej-ubuntu-php-noble.sources"

// ensureArchive adds the Ondřej Surý PHP archive when Ubuntu has no
// candidate for pkg (PHP versions other than the release's own).
func (o *op) ensureArchive(pkg string) error {
	if o.m.sim || o.m.candidates(o.ctx, []string{pkg})[pkg].Version != "" {
		return nil
	}
	entries, _ := filepath.Glob(o.m.h.Path("/etc/apt/sources.list.d/ondrej-ubuntu-php-*"))
	if len(entries) > 0 {
		o.step("Paket listeleri yenileniyor")
		_, err := o.run(host.Command{Name: "apt-get", Args: []string{"update", "-q"}, Env: aptEnv})
		return err
	}
	if !o.m.exists("/usr/bin/add-apt-repository") {
		if err := o.aptInstall([]string{"software-properties-common"}); err != nil {
			return err
		}
	}
	o.step("PHP paket deposu ekleniyor (ppa:ondrej/php)")
	if _, err := o.run(host.Command{Name: "add-apt-repository", Args: []string{"-y", "ppa:ondrej/php"}, Env: aptEnv}); err != nil {
		return err
	}
	matches, _ := filepath.Glob(o.m.h.Path("/etc/apt/sources.list.d/ondrej-ubuntu-php-*"))
	for _, f := range matches {
		o.ppaAdded = f
	}
	return nil
}

// install runs the apt flow: packages, Servik's configuration, services
// and a check. Anything that fails undoes what this operation did.
func (o *op) install() (err error) {
	c, m := o.c, o.m
	if c.install != nil {
		return c.install(o)
	}
	o.t = txn.New(m.h)
	wasInstalled := c.isInstalled(m)
	defer func() {
		if err != nil {
			o.rollback(wasInstalled)
		}
	}()
	if c.beforeInstall != nil {
		if err := c.beforeInstall(o); err != nil {
			return err
		}
	}
	if err := o.aptInstall(c.allPackages(m)); err != nil {
		return err
	}
	if err := o.failpoint("packages"); err != nil {
		return err
	}
	return o.finish(!wasInstalled || len(o.added) > 0)
}

// finish writes Servik's configuration, (re)starts the services and checks
// the result.
func (o *op) finish(restart bool) error {
	c := o.c
	if c.configure != nil {
		o.step("Servik ayarları yazılıyor")
		if err := c.configure(o); err != nil {
			return err
		}
		restart = restart || o.t.Changed
	}
	if err := o.failpoint("configure"); err != nil {
		return err
	}
	if units := c.unitsOf(o.m); len(units) > 0 {
		o.step("Hizmet başlatılıyor: %s", strings.Join(units, ", "))
		if err := o.systemctl(append([]string{"enable"}, units...)...); err != nil {
			return err
		}
		verb := "start"
		if restart {
			verb = "restart"
		}
		for _, u := range units {
			if err := o.systemctl(verb, u); err != nil {
				return fmt.Errorf("%s did not start (see journalctl -u %s): %w", u, u, err)
			}
		}
	}
	if c.check != nil {
		o.step("Çalıştığı denetleniyor")
		if err := c.check(o); err != nil {
			return err
		}
	}
	return o.failpoint("check")
}

// rollback undoes a failed install: Servik's files come back, services
// it started stop, and the packages it added are purged.
func (o *op) rollback(wasInstalled bool) {
	bg := context.WithoutCancel(o.ctx)
	o.ctx = bg
	fmt.Fprintln(o.w, "↺ "+i18n.T(o.ctx, "Değişiklikler geri alınıyor"))
	if o.t != nil {
		if err := o.t.Rollback(bg); err != nil {
			fmt.Fprintf(o.w, "  %s\n", i18n.T(o.ctx, "geri alma: %v", err))
		}
	}
	units := o.c.unitsOf(o.m)
	if !wasInstalled {
		for _, u := range units {
			_, _ = o.quiet(host.Command{Name: "systemctl", Args: []string{"disable", "--now", u}})
		}
	}
	if len(o.added) > 0 {
		fmt.Fprintf(o.w, "↺ %s\n", i18n.T(o.ctx, "Kurulan paketler kaldırılıyor: %s", strings.Join(o.added, " ")))
		_, _ = o.run(host.Command{Name: "apt-get", Args: append([]string{"purge", "-y", "-q"}, o.added...), Env: aptEnv})
		_, _ = o.run(host.Command{Name: "apt-get", Args: []string{"autoremove", "-y", "-q"}, Env: aptEnv})
	}
	if o.ppaAdded != "" {
		_ = os.Remove(o.ppaAdded)
		_, _ = o.quiet(host.Command{Name: "apt-get", Args: []string{"update", "-q"}, Env: aptEnv})
	}
	if wasInstalled {
		// It was working before; it gets its old configuration back.
		for _, u := range units {
			_, _ = o.quiet(host.Command{Name: "systemctl", Args: []string{"restart", u}})
		}
	}
}

// remove archives the configuration, takes Servik's configuration away,
// stops the services and purges the packages.
func (o *op) remove() (err error) {
	c, m := o.c, o.m
	if c.required != "" {
		return invalid("%s cannot be removed: %s", c.id, c.required)
	}
	if !c.isInstalled(m) {
		return invalid("%s is not installed", c.id)
	}
	if c.inUse != nil {
		if used := c.inUse(m); len(used) > 0 {
			return &InUseError{ID: c.id, UsedBy: used}
		}
	}
	if c.remove != nil {
		return c.remove(o)
	}
	o.t = txn.New(m.h)
	stopped := false
	defer func() {
		if err == nil {
			return
		}
		fmt.Fprintln(o.w, "↺ "+i18n.T(o.ctx, "Değişiklikler geri alınıyor"))
		bg := context.WithoutCancel(o.ctx)
		if rbErr := o.t.Rollback(bg); rbErr != nil {
			fmt.Fprintf(o.w, "  %s\n", i18n.T(o.ctx, "geri alma: %v", rbErr))
		}
		if stopped {
			for _, u := range c.unitsOf(m) {
				_, _ = m.h.Run(bg, host.Command{Name: "systemctl", Args: []string{"enable", "--now", u}})
			}
		}
	}()
	if err := o.archive(); err != nil {
		return err
	}
	if c.unconfigure != nil {
		o.step("Servik ayarları kaldırılıyor")
		if err := c.unconfigure(o); err != nil {
			return err
		}
	}
	if units := c.unitsOf(m); len(units) > 0 {
		o.step("Hizmet durduruluyor: %s", strings.Join(units, ", "))
		stopped = true
		if err := o.systemctl(append([]string{"disable", "--now"}, units...)...); err != nil {
			return err
		}
	}
	if err := o.failpoint("stop"); err != nil {
		return err
	}
	purge := c.packages
	if c.purge != nil {
		purge = c.purge(m)
	}
	if len(purge) > 0 {
		o.step("Paketler kaldırılıyor: %s", strings.Join(purge, " "))
		if _, err := o.run(host.Command{Name: "apt-get", Args: append([]string{"purge", "-y", "-q"}, purge...), Env: aptEnv}); err != nil {
			return err
		}
		_, _ = o.run(host.Command{Name: "apt-get", Args: []string{"autoremove", "-y", "-q"}, Env: aptEnv})
	}
	return nil
}

// archive keeps the component's configuration before a removal, as a
// tar.gz in BackupDir.
func (o *op) archive() error {
	var dirs []string
	for _, d := range o.c.backup {
		if o.m.exists(d) {
			dirs = append(dirs, d)
		}
	}
	if len(dirs) == 0 {
		return nil
	}
	if err := os.MkdirAll(o.m.h.Path(BackupDir), 0o700); err != nil {
		return err
	}
	file := fmt.Sprintf("%s/%s-%s.tar.gz", BackupDir, o.c.id, o.m.now().UTC().Format("20060102-150405"))
	o.step("Yapılandırma yedekleniyor: %s", file)
	f, err := os.OpenFile(o.m.h.Path(file), os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o600)
	if err != nil {
		return err
	}
	gz := gzip.NewWriter(f)
	tw := tar.NewWriter(gz)
	for _, d := range dirs {
		root := o.m.h.Path(d)
		err := filepath.Walk(root, func(path string, fi os.FileInfo, err error) error {
			if err != nil {
				return err
			}
			rel, _ := filepath.Rel(root, path)
			name := strings.TrimPrefix(filepath.Join(d, rel), "/")
			link := ""
			if fi.Mode()&os.ModeSymlink != 0 {
				link, _ = os.Readlink(path)
			}
			hdr, err := tar.FileInfoHeader(fi, link)
			if err != nil {
				return err
			}
			hdr.Name = name
			if err := tw.WriteHeader(hdr); err != nil {
				return err
			}
			if fi.Mode().IsRegular() {
				src, err := os.Open(path)
				if err != nil {
					return err
				}
				_, err = io.Copy(tw, src)
				src.Close()
				return err
			}
			return nil
		})
		if err != nil {
			f.Close()
			return fmt.Errorf("archiving %s: %w", d, err)
		}
	}
	if err := tw.Close(); err != nil {
		f.Close()
		return err
	}
	if err := gz.Close(); err != nil {
		f.Close()
		return err
	}
	return f.Close()
}

// update installs newer packages, writes the configuration again and
// restarts the services. Packages cannot be downgraded, so a failed check
// only restores the configuration.
func (o *op) update() (err error) {
	c, m := o.c, o.m
	if !c.isInstalled(m) {
		return invalid("%s is not installed", c.id)
	}
	if c.update != nil {
		return c.update(o)
	}
	o.t = txn.New(m.h)
	defer func() {
		if err != nil {
			fmt.Fprintln(o.w, "↺ "+i18n.T(o.ctx, "Yapılandırma geri alınıyor"))
			bg := context.WithoutCancel(o.ctx)
			_ = o.t.Rollback(bg)
			for _, u := range c.unitsOf(m) {
				_, _ = m.h.Run(bg, host.Command{Name: "systemctl", Args: []string{"restart", u}})
			}
		}
	}()
	var have []string
	for _, p := range c.allPackages(m) {
		if m.packageInstalled(o.ctx, p) {
			have = append(have, p)
		}
	}
	o.step("Yeni sürüm kuruluyor: %s", strings.Join(have, " "))
	args := append(append([]string{"install", "--only-upgrade"}, aptOpts...), have...)
	if _, err := o.run(host.Command{Name: "apt-get", Args: args, Env: aptEnv}); err != nil {
		return err
	}
	// Extensions a newer component needs (php-redis for a new PHP) come
	// along.
	if err := o.aptInstall(c.allPackages(m)); err != nil {
		return err
	}
	return o.finish(true)
}

// packageInstalled asks dpkg whether a package is installed.
func (m *Manager) packageInstalled(ctx context.Context, pkg string) bool {
	if sim, ok := m.h.(*host.Simulator); ok {
		return sim.PackageInstalled(pkg)
	}
	out, err := m.h.Run(ctx, host.Command{Name: "dpkg-query", Args: []string{"-W", "-f=${db:Status-Status}", pkg}})
	return err == nil && strings.TrimSpace(out) == "installed"
}

// versions returns the installed version of each package.
func (m *Manager) versions(ctx context.Context, pkgs []string) map[string]string {
	out := map[string]string{}
	if len(pkgs) == 0 {
		return out
	}
	if m.sim {
		for _, p := range pkgs {
			out[p] = simVersions[p]
		}
		return out
	}
	// dpkg-query fails when one package is unknown but still prints the
	// others.
	res, _ := m.h.Run(ctx, host.Command{Name: "dpkg-query", Args: append([]string{"-W", "-f=${Package}\t${Version}\t${db:Status-Status}\n"}, pkgs...)})
	s := bufio.NewScanner(strings.NewReader(res))
	for s.Scan() {
		f := strings.Split(s.Text(), "\t")
		if len(f) == 3 && f[2] == "installed" {
			out[f[0]] = f[1]
		}
	}
	return out
}

// candidates returns the version apt would install for each package and
// its archive; packages without one are left out.
func (m *Manager) candidates(ctx context.Context, pkgs []string) map[string]aptcache.Candidate {
	out := map[string]aptcache.Candidate{}
	if len(pkgs) == 0 {
		return out
	}
	if m.sim {
		for _, p := range pkgs {
			v := simCandidates[p]
			if v == "" {
				v = simVersions[p]
			}
			if v != "" {
				out[p] = aptcache.Candidate{Version: v, From: simArchive(v)}
			}
		}
		return out
	}
	ctx, cancel := context.WithTimeout(ctx, 20*time.Second)
	defer cancel()
	res, _ := m.h.Run(ctx, host.Command{Name: "apt-cache", Args: append([]string{"policy"}, pkgs...), Env: []string{"LC_ALL=C"}})
	return aptcache.Policy(res)
}

// active reports which units are active, with one systemctl call.
func (m *Manager) active(ctx context.Context, units []string) map[string]bool {
	out := map[string]bool{}
	if len(units) == 0 {
		return out
	}
	if m.sim {
		for _, u := range units {
			out[u] = true
		}
		return out
	}
	res, _ := m.h.Run(ctx, host.Command{Name: "systemctl", Args: append([]string{"is-active"}, units...)})
	lines := strings.Split(strings.TrimSpace(res), "\n")
	for i, u := range units {
		out[u] = i < len(lines) && strings.TrimSpace(lines[i]) == "active"
	}
	return out
}
