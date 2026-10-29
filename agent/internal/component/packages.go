package component

// System packages (Yazılım Yönetimi → Ubuntu güncellemeleri): the apt
// packages that have a newer version, upgraded all at once or one by one,
// Ubuntu's automatic security updates and the reboot some upgrades ask
// for.
//
// What "update all" installs is what "apt upgrade" would: every upgrade
// apt can make without removing a package, with the new packages they
// need (a new kernel). Serfex never removes a package while updating:
// a selection apt can only install by removing something is refused.
// Held packages (apt-mark hold) are left alone, and so are the updates
// Ubuntu still rolls out in phases. Configuration files changed on the
// server are kept. When an upgrade touches a Serfex component (Nginx, a
// PHP version, a database server...), Serfex writes its configuration
// again, restarts it and checks it, as the component's own update does.
//
// The list is worked out with "apt-get -s" (nothing is installed) and
// kept in packages.json; it is worked out again when dpkg's status or
// the package lists change, so upgrades installed by unattended-upgrades
// or by hand show up without a check.

import (
	"bufio"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"regexp"
	"slices"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/calltech-app/serfex/agent/internal/aptcache"
	"github.com/calltech-app/serfex/agent/internal/database"
	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/i18n"
	"github.com/calltech-app/serfex/agent/internal/txn"
)

const (
	packagesFile = stateDir + "/packages.json"

	dpkgStatus        = "/var/lib/dpkg/status"
	aptLists          = "/var/lib/apt/lists"
	updateStamp       = "/var/lib/apt/periodic/update-success-stamp"
	unattendedStamp   = "/var/lib/apt/periodic/unattended-upgrades-stamp"
	rebootRequired    = "/run/reboot-required"
	rebootPackages    = "/run/reboot-required.pkgs"
	shutdownScheduled = "/run/systemd/shutdown/scheduled"
	// AutoUpgradesFile is where Ubuntu (dpkg-reconfigure
	// unattended-upgrades) turns the automatic updates on and off.
	AutoUpgradesFile = "/etc/apt/apt.conf.d/20auto-upgrades"

	// packagesBusy is the busy key of a package upgrade.
	packagesBusy = "packages"
	maxPackages  = 500
)

// pkgRe is a Debian package name, with an optional architecture.
var pkgRe = regexp.MustCompile(`^[a-z0-9][a-z0-9+.-]{0,99}(:[a-z0-9]{1,16})?$`)

// upgradeOpts never ask, keep configuration files changed on the server
// and wait for unattended-upgrades to let go of the lock.
var upgradeOpts = []string{"-y", "-q", "-o", "DPkg::Lock::Timeout=600", "-o", "Dpkg::Options::=--force-confdef", "-o", "Dpkg::Options::=--force-confold"}

// Package is an installed package with a newer version, or a new package
// the upgrades bring along.
type Package struct {
	aptcache.Change
	// New marks a package an upgrade brings along (a new kernel's image);
	// it comes with the upgrade and cannot be chosen alone.
	New bool `json:"new,omitempty"`
	// Held packages (apt-mark hold) are never upgraded; KeptBack ones are
	// left out of "update all" because apt would have to change more
	// (they can be chosen alone); Phased ones are updates Ubuntu still
	// rolls out step by step and holds back on this server for now.
	Held     bool `json:"held,omitempty"`
	KeptBack bool `json:"kept_back,omitempty"`
	Phased   bool `json:"phased,omitempty"`
	// Component is the Serfex component the package belongs to.
	Component string `json:"component,omitempty"`
}

func (p Package) selectable() bool { return !p.New && !p.Held && !p.Phased }

// Reboot is whether the server needs a restart for an upgrade to take
// effect (a new kernel or C library), and a restart started from the
// panel.
type Reboot struct {
	Required bool `json:"required"`
	// Since is when the first package asked for it, Packages which did.
	Since    string   `json:"since,omitempty"`
	Packages []string `json:"packages,omitempty"`
	// Scheduled is when a restart started with Reboot happens.
	Scheduled string `json:"scheduled,omitempty"`
}

// AutoUpdates is the state of Ubuntu's unattended-upgrades.
type AutoUpdates struct {
	Installed bool `json:"installed"`
	Enabled   bool `json:"enabled"`
	// SecurityOnly is Ubuntu's default: only the security pocket (and the
	// release itself) is allowed.
	SecurityOnly bool `json:"security_only"`
	// Reboot is Unattended-Upgrade::Automatic-Reboot (off by default).
	Reboot  bool   `json:"reboot"`
	LastRun string `json:"last_run,omitempty"`
}

// Packages is the page's state.
type Packages struct {
	Packages []Package `json:"packages"`
	// CheckedAt is when the package lists were last refreshed, by
	// component.check or Ubuntu's own daily apt run.
	CheckedAt string      `json:"checked_at,omitempty"`
	Reboot    Reboot      `json:"reboot"`
	Auto      AutoUpdates `json:"auto"`
	// Busy is true while an upgrade runs.
	Busy bool `json:"busy,omitempty"`
}

type packagesState struct {
	Packages []Package `json:"packages"`
	// Key is the state of dpkg and the package lists the list was
	// worked out from.
	Key       string `json:"key"`
	CheckedAt string `json:"checked_at,omitempty"`
}

// Packages lists the packages with updates, the reboot notice and the
// automatic updates. Nothing is changed.
func (m *Manager) Packages(ctx context.Context) (Packages, error) {
	m.mu.Lock()
	busy := m.busy[packagesBusy] != ""
	m.mu.Unlock()
	st := m.loadPackages()
	if !busy && st.Key != m.packagesKey() {
		fresh, err := m.computePackages(ctx)
		if err != nil {
			return Packages{}, opError(err)
		}
		fresh.CheckedAt = st.CheckedAt
		st = fresh
		_ = m.savePackages(st)
	}
	out := Packages{Packages: st.Packages, CheckedAt: st.CheckedAt, Busy: busy}
	if out.Packages == nil {
		out.Packages = []Package{}
	}
	if t := m.mtime(updateStamp); t.After(m.parseTime(out.CheckedAt)) {
		out.CheckedAt = t.UTC().Format(time.RFC3339)
	}
	out.Reboot = m.rebootState()
	out.Auto = m.autoUpdates(ctx)
	return out, nil
}

// refreshPackages works the list out again after the package lists were
// refreshed (component.check).
func (m *Manager) refreshPackages(ctx context.Context) {
	st, err := m.computePackages(ctx)
	if err != nil {
		return
	}
	st.CheckedAt = m.now().UTC().Format(time.RFC3339)
	_ = m.savePackages(st)
}

// computePackages asks apt what "update all" would do and what it leaves
// out.
func (m *Manager) computePackages(ctx context.Context) (packagesState, error) {
	key := m.packagesKey()
	plan, err := m.simulate(ctx, []string{"upgrade", "--with-new-pkgs"})
	if err != nil {
		return packagesState{}, err
	}
	held := map[string]bool{}
	for _, p := range m.held(ctx) {
		held[p] = true
	}
	var rows []Package
	seen := map[string]bool{}
	for _, c := range plan.Changes {
		seen[c.Name] = true
		rows = append(rows, Package{Change: c, New: c.Installed == ""})
	}
	// What "update all" leaves out, with the versions apt-cache knows.
	var rest []string
	kept := map[string]bool{}
	phased := map[string]bool{}
	for _, n := range plan.KeptBack {
		kept[n] = true
		rest = append(rest, n)
	}
	for _, n := range plan.Phased {
		phased[n] = true
		rest = append(rest, n)
	}
	for n := range held {
		rest = append(rest, n)
	}
	sort.Strings(rest)
	rest = slices.Compact(rest)
	if len(rest) > 0 {
		policy := m.policy(ctx, rest)
		for _, n := range rest {
			c, ok := policy[n]
			if seen[n] || !ok || c.Installed == "" || c.Version == c.Installed {
				continue
			}
			seen[n] = true
			rows = append(rows, Package{
				Change:   aptcache.Change{Name: n, Installed: c.Installed, Version: c.Version, Origin: c.From, Security: c.Security},
				Held:     held[n],
				KeptBack: kept[n] && !held[n],
				Phased:   phased[n] && !held[n],
			})
		}
	}
	names := make([]string, len(rows))
	for i, r := range rows {
		names[i] = r.Name
	}
	owners := m.owners(ctx, names)
	for i := range rows {
		rows[i].Component = owners[rows[i].Name]
	}
	sort.Slice(rows, func(i, j int) bool { return rows[i].Name < rows[j].Name })
	return packagesState{Packages: rows, Key: key}, nil
}

// simulate runs "apt-get -s" with args; nothing is installed.
func (m *Manager) simulate(ctx context.Context, args []string) (aptcache.Plan, error) {
	if m.sim {
		return aptcache.Simulation(m.simUpgrade(args)), nil
	}
	ctx, cancel := context.WithTimeout(ctx, 2*time.Minute)
	defer cancel()
	out, err := m.h.Run(ctx, host.Command{Name: "apt-get", Args: append([]string{"-s", "-q", "-o", "Debug::NoLocking=1"}, args...), Env: aptEnv})
	if err != nil {
		return aptcache.Plan{}, fmt.Errorf("apt-get could not work out the upgrades: %w", err)
	}
	return aptcache.Simulation(out), nil
}

// held lists the packages on hold.
func (m *Manager) held(ctx context.Context) []string {
	if m.sim {
		return []string{"cloud-init"}
	}
	out, _ := m.h.Run(ctx, host.Command{Name: "apt-mark", Args: []string{"showhold"}})
	return strings.Fields(out)
}

// policy is apt-cache policy for the packages.
func (m *Manager) policy(ctx context.Context, pkgs []string) map[string]aptcache.Candidate {
	if m.sim {
		out := map[string]aptcache.Candidate{}
		done := m.simUpgraded()
		for _, p := range pkgs {
			if v, ok := simHeld[p]; ok {
				out[p] = v
			}
			for _, u := range simPackageUpdates {
				if u.name == p && !done[p] {
					out[p] = aptcache.Candidate{Installed: u.from, Version: u.to, From: "archive.ubuntu.com/ubuntu", Security: u.security}
				}
			}
		}
		return out
	}
	ctx, cancel := context.WithTimeout(ctx, 30*time.Second)
	defer cancel()
	res, _ := m.h.Run(ctx, host.Command{Name: "apt-cache", Args: append([]string{"policy"}, pkgs...)})
	return aptcache.Policy(res)
}

// owners maps packages to the installed Serfex component they belong
// to: one of its own packages, or a package built from the same source
// (php8.4-common comes with php8.4-fpm, mysql-server-8.0 with
// mysql-server).
func (m *Manager) owners(ctx context.Context, pkgs []string) map[string]string {
	byName := map[string]string{}
	ids := make([]string, 0, len(m.comps))
	for id := range m.comps {
		ids = append(ids, id)
	}
	sort.Strings(ids)
	for _, id := range ids {
		c := m.comps[id]
		if !c.isInstalled(m) {
			continue
		}
		for _, p := range m.ownPackages(c) {
			if _, ok := byName[p]; !ok {
				byName[p] = id
			}
		}
	}
	all := append(slices.Clone(pkgs), mapKeys(byName)...)
	src := m.sources(ctx, all)
	bySource := map[string][]string{}
	for p, id := range byName {
		if s := src[p]; s != "" && !slices.Contains(bySource[s], id) {
			bySource[s] = append(bySource[s], id)
		}
	}
	out := map[string]string{}
	for _, p := range pkgs {
		if id, ok := byName[p]; ok {
			out[p] = id
		} else if ids := bySource[src[p]]; len(ids) == 1 {
			out[p] = ids[0]
		}
	}
	return out
}

// ownPackages are the apt packages a component installs.
func (m *Manager) ownPackages(c *component) []string {
	pkgs := c.allPackages(m)
	if c.versionPkg != "" {
		pkgs = append(pkgs, c.versionPkg)
	}
	if c.id == "postgresql" {
		if v := m.db.PGVersion(); v != "" {
			pkgs = append(pkgs, "postgresql-"+v, "postgresql-client-"+v)
		}
		pkgs = append(pkgs, m.db.ExtensionPackages()...)
	}
	return pkgs
}

// sources returns the source package each package is built from.
func (m *Manager) sources(ctx context.Context, pkgs []string) map[string]string {
	out := map[string]string{}
	if len(pkgs) == 0 {
		return out
	}
	if m.sim {
		for _, p := range pkgs {
			out[p] = p
			if v, _, ok := strings.Cut(p, "-"); ok && phpBinary.MatchString(v) {
				out[p] = v
			}
			if base, ok := strings.CutSuffix(p, "-common"); ok {
				out[p] = base
			}
		}
		return out
	}
	res, _ := m.h.Run(ctx, host.Command{Name: "dpkg-query", Args: append([]string{"-W", "-f=${Package}\t${source:Package}\n"}, pkgs...)})
	s := bufio.NewScanner(strings.NewReader(res))
	for s.Scan() {
		if name, source, ok := strings.Cut(s.Text(), "\t"); ok && source != "" {
			out[name] = source
		}
	}
	return out
}

var phpBinary = regexp.MustCompile(`^php\d\.\d$`)

func mapKeys(m map[string]string) []string {
	out := make([]string, 0, len(m))
	for k := range m {
		out = append(out, k)
	}
	sort.Strings(out)
	return out
}

// packagesKey changes whenever a package is installed or the package
// lists are refreshed.
func (m *Manager) packagesKey() string {
	return fmt.Sprintf("%d/%d", m.mtime(dpkgStatus).UnixNano(), m.mtime(aptLists).UnixNano())
}

func (m *Manager) mtime(abs string) time.Time {
	fi, err := os.Stat(m.h.Path(abs))
	if err != nil {
		return time.Time{}
	}
	return fi.ModTime()
}

func (m *Manager) parseTime(s string) time.Time {
	t, _ := time.Parse(time.RFC3339, s)
	return t
}

func (m *Manager) loadPackages() packagesState {
	var st packagesState
	if data, err := os.ReadFile(m.h.Path(packagesFile)); err == nil {
		_ = json.Unmarshal(data, &st)
	}
	return st
}

func (m *Manager) savePackages(st packagesState) error {
	if err := os.MkdirAll(m.h.Path(stateDir), 0o755); err != nil {
		return err
	}
	data, _ := json.MarshalIndent(st, "", "  ")
	return os.WriteFile(m.h.Path(packagesFile), data, 0o644)
}

// rebootState reads Ubuntu's reboot notice and a restart systemd has
// scheduled.
func (m *Manager) rebootState() Reboot {
	var r Reboot
	if t := m.mtime(rebootRequired); !t.IsZero() {
		r.Required = true
		r.Since = t.UTC().Format(time.RFC3339)
		if data, err := os.ReadFile(m.h.Path(rebootPackages)); err == nil {
			for _, p := range strings.Fields(string(data)) {
				if !slices.Contains(r.Packages, p) {
					r.Packages = append(r.Packages, p)
				}
			}
		}
	}
	if data, err := os.ReadFile(m.h.Path(shutdownScheduled)); err == nil {
		var usec int64
		reboot := false
		for _, line := range strings.Split(string(data), "\n") {
			if v, ok := strings.CutPrefix(line, "USEC="); ok {
				usec, _ = strconv.ParseInt(strings.TrimSpace(v), 10, 64)
			}
			if strings.TrimSpace(line) == "MODE=reboot" {
				reboot = true
			}
		}
		if reboot && usec > 0 {
			r.Scheduled = time.UnixMicro(usec).UTC().Format(time.RFC3339)
		}
	}
	return r
}

// autoUpdates reads unattended-upgrades' effective settings.
func (m *Manager) autoUpdates(ctx context.Context) AutoUpdates {
	var dump string
	a := AutoUpdates{}
	if m.sim {
		a.Installed = true
		data, err := os.ReadFile(m.h.Path(AutoUpgradesFile))
		if err != nil {
			data = autoUpgradesConf(true)
		}
		dump = string(data) + simUnattendedDefaults
		if t := m.mtime(unattendedStamp); !t.IsZero() {
			a.LastRun = t.UTC().Format(time.RFC3339)
		} else {
			a.LastRun = m.now().Add(-3 * time.Hour).UTC().Format(time.RFC3339)
		}
	} else {
		a.Installed = m.packageInstalled(ctx, "unattended-upgrades")
		dump, _ = m.h.Run(ctx, host.Command{Name: "apt-config", Args: []string{"dump"}})
		if t := m.mtime(unattendedStamp); !t.IsZero() {
			a.LastRun = t.UTC().Format(time.RFC3339)
		}
	}
	conf := aptConfig(dump)
	a.Enabled = a.Installed && conf.on("APT::Periodic::Update-Package-Lists") && conf.on("APT::Periodic::Unattended-Upgrade")
	a.Reboot = conf.on("Unattended-Upgrade::Automatic-Reboot")
	a.SecurityOnly = true
	for _, o := range conf["Unattended-Upgrade::Allowed-Origins::"] {
		suite := o[strings.LastIndexByte(o, ':')+1:]
		if strings.Contains(suite, "-") && !strings.HasSuffix(suite, "-security") {
			a.SecurityOnly = false
		}
	}
	for _, o := range conf["Unattended-Upgrade::Origins-Pattern::"] {
		if !strings.Contains(o, "security") {
			a.SecurityOnly = false
		}
	}
	return a
}

// simUnattendedDefaults is what Ubuntu's 50unattended-upgrades sets.
const simUnattendedDefaults = `Unattended-Upgrade::Allowed-Origins:: "${distro_id}:${distro_codename}";
Unattended-Upgrade::Allowed-Origins:: "${distro_id}:${distro_codename}-security";
Unattended-Upgrade::Allowed-Origins:: "${distro_id}ESMApps:${distro_codename}-apps-security";
Unattended-Upgrade::Allowed-Origins:: "${distro_id}ESM:${distro_codename}-infra-security";
`

// aptConf holds "apt-config dump" values by name; lists repeat a name.
type aptConf map[string][]string

var aptConfRe = regexp.MustCompile(`^\s*([A-Za-z0-9:_.-]+)\s+"([^"]*)";`)

func aptConfig(dump string) aptConf {
	c := aptConf{}
	for _, line := range strings.Split(dump, "\n") {
		if m := aptConfRe.FindStringSubmatch(line); m != nil {
			c[m[1]] = append(c[m[1]], m[2])
		}
	}
	return c
}

// on reads a boolean or periodic value the way apt does: the last one
// counts, and "0", "false", "no" and "off" are off.
func (c aptConf) on(name string) bool {
	v := c[name]
	if len(v) == 0 {
		return false
	}
	switch strings.ToLower(v[len(v)-1]) {
	case "", "0", "false", "no", "off":
		return false
	}
	return true
}

func autoUpgradesConf(on bool) []byte {
	v := "0"
	if on {
		v = "1"
	}
	return []byte("APT::Periodic::Update-Package-Lists \"" + v + "\";\nAPT::Periodic::Unattended-Upgrade \"" + v + "\";\n")
}

// UpgradeSpec asks for package upgrades.
type UpgradeSpec struct {
	// Run names the log the progress goes to (component.run.read).
	Run string `json:"run"`
	// Packages are the packages to upgrade; empty upgrades everything
	// "update all" offers.
	Packages []string `json:"packages,omitempty"`
}

// UpgradePackages installs the newer versions, then checks the Serfex components
// the upgrade touched.
func (m *Manager) UpgradePackages(ctx context.Context, spec UpgradeSpec) (Packages, error) {
	if !runRe.MatchString(spec.Run) {
		return Packages{}, invalid("run %q must be 1-64 letters, digits, - or _", spec.Run)
	}
	if len(spec.Packages) > maxPackages {
		return Packages{}, invalid("at most %d packages can be upgraded at once", maxPackages)
	}
	for _, p := range spec.Packages {
		if !pkgRe.MatchString(p) {
			return Packages{}, invalid("package name %q is not valid", p)
		}
	}
	if !m.op.TryLock() {
		return Packages{}, ErrBusy
	}
	defer m.op.Unlock()
	w, err := m.openRun(spec.Run)
	if err != nil {
		return Packages{}, opError(err)
	}
	defer w.Close()
	m.setBusy(packagesBusy, "upgrade")
	err = m.upgrade(ctx, w, spec.Packages)
	m.setBusy(packagesBusy, "")
	if err != nil {
		fmt.Fprintf(w, "✗ %s\n", lastLine(err.Error()))
		return Packages{}, opError(err)
	}
	fmt.Fprintln(w, "✓ "+i18n.T(ctx, "Güncellendi"))
	return m.Packages(ctx)
}

func (m *Manager) setBusy(id, action string) {
	m.mu.Lock()
	defer m.mu.Unlock()
	if action == "" {
		delete(m.busy, id)
	} else {
		m.busy[id] = action
	}
}

func (m *Manager) upgrade(ctx context.Context, w io.Writer, chosen []string) error {
	step := func(format string, a ...any) { fmt.Fprintf(w, "▸ "+i18n.Translate(ctx, format)+"\n", a...) }
	step("Güncellenecek paketler belirleniyor")
	st, err := m.computePackages(ctx)
	if err != nil {
		return err
	}
	rows := map[string]Package{}
	for _, p := range st.Packages {
		rows[p.Name] = p
	}
	args := append(append([]string{"upgrade"}, upgradeOpts...), "--with-new-pkgs")
	sim := []string{"upgrade", "--with-new-pkgs"}
	if len(chosen) > 0 {
		for _, n := range chosen {
			p, ok := rows[n]
			switch {
			case !ok:
				return invalid("%s has no update", n)
			case p.Held:
				return invalid("%s is held (apt-mark hold); release it on the server first", n)
			case p.Phased:
				return invalid("Ubuntu is still rolling out the update of %s in phases; it comes on its own", n)
			case p.New:
				return invalid("%s is not installed; it comes with the upgrade that needs it", n)
			}
		}
		args = append(append([]string{"install", "--only-upgrade"}, upgradeOpts...), chosen...)
		sim = append([]string{"install", "--only-upgrade"}, chosen...)
	}
	plan, err := m.simulate(ctx, sim)
	if err != nil {
		return err
	}
	if len(plan.Removed) > 0 {
		return invalid("apt would remove %s to install this; Serfex never removes packages while updating. Update it on the server with apt", strings.Join(plan.Removed, " "))
	}
	if len(plan.Changes) == 0 {
		fmt.Fprintln(w, i18n.T(ctx, "Güncellenecek paket yok."))
		return nil
	}
	affected := map[string]bool{}
	for _, c := range plan.Changes {
		if id := rows[c.Name].Component; id != "" {
			affected[id] = true
		}
	}
	// The component pages show them updating.
	for id := range affected {
		m.setBusy(id, "update")
		defer m.setBusy(id, "")
	}
	step("Paketler güncelleniyor: %d paket", len(plan.Changes))
	cmd := host.Command{Name: "apt-get", Args: args, Env: aptEnv, Stream: w}
	fmt.Fprintf(w, "$ %s\n", cmd)
	if m.sim {
		m.simInstall(plan.Changes)
	} else if _, err := m.h.Run(ctx, cmd); err != nil {
		// A run cut short leaves packages unpacked but not configured.
		step("Yarım kalan paket kurulumları tamamlanıyor")
		fix := host.Command{Name: "dpkg", Args: []string{"--configure", "-a", "--force-confdef", "--force-confold"}, Env: aptEnv, Stream: w}
		fmt.Fprintf(w, "$ %s\n", fix)
		_, _ = m.h.Run(context.WithoutCancel(ctx), fix)
		return fmt.Errorf("apt-get failed: %w", err)
	}
	if err := m.failCheck("packages"); err != nil {
		return err
	}

	var failed []string
	ids := mapKeysBool(affected)
	for _, id := range ids {
		c := m.comps[id]
		if c == nil || !c.isInstalled(m) {
			continue
		}
		step("%s denetleniyor", id)
		o := &op{ctx: ctx, m: m, c: c, w: w}
		if err := o.afterUpgrade(); err != nil {
			fmt.Fprintf(w, "  %s\n", lastLine(err.Error()))
			failed = append(failed, id)
		}
	}
	if r := m.rebootState(); r.Required {
		fmt.Fprintln(w, i18n.T(ctx, "Yeni sürümlerin hepsinin devreye girmesi için sunucunun yeniden başlatılması gerekiyor."))
	}
	if len(failed) > 0 {
		return fmt.Errorf("the packages were updated, but %s did not pass its check; its previous configuration is back", strings.Join(failed, ", "))
	}
	return nil
}

// failCheck lets tests break an upgrade at a named step.
func (m *Manager) failCheck(step string) error {
	if m.Fail != nil {
		return m.Fail(packagesBusy, step)
	}
	return nil
}

func mapKeysBool(m map[string]bool) []string {
	out := make([]string, 0, len(m))
	for k := range m {
		out = append(out, k)
	}
	sort.Strings(out)
	return out
}

// afterUpgrade does what a component's update does once apt has brought
// its new packages: Serfex's configuration is written again and the
// services restart and are checked; a failed check puts the previous
// configuration back. Database servers are checked by their own setup.
func (o *op) afterUpgrade() (err error) {
	c, m := o.c, o.m
	if c.choices != nil {
		_, err := m.db.Install(o.ctx, database.InstallSpec{Product: c.id, Log: o.w})
		return err
	}
	if c.install != nil || c.update != nil {
		// Not apt's (Node.js, Composer).
		return nil
	}
	if err := o.failpoint("after-upgrade"); err != nil {
		return err
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
	// Extensions a newer component needs come along.
	if err := o.aptInstall(c.allPackages(m)); err != nil {
		return err
	}
	return o.finish(true)
}

// SetAutoUpdates turns Ubuntu's automatic security updates on or off,
// installing unattended-upgrades when it is missing.
func (m *Manager) SetAutoUpdates(ctx context.Context, on bool) (AutoUpdates, error) {
	if !m.op.TryLock() {
		return AutoUpdates{}, ErrBusy
	}
	defer m.op.Unlock()
	if on && !m.sim && !m.packageInstalled(ctx, "unattended-upgrades") {
		inst := host.Command{Name: "apt-get", Args: append(append([]string{"install"}, upgradeOpts...), "unattended-upgrades"), Env: aptEnv}
		if _, err := m.h.Run(ctx, inst); err != nil {
			return AutoUpdates{}, opError(fmt.Errorf("installing unattended-upgrades failed: %w", err))
		}
	}
	if err := os.MkdirAll(m.h.Path("/etc/apt/apt.conf.d"), 0o755); err != nil {
		return AutoUpdates{}, opError(err)
	}
	t := txn.New(m.h)
	if err := t.WriteOwned(AutoUpgradesFile, autoUpgradesConf(on), 0o644, 0, 0); err != nil {
		return AutoUpdates{}, opError(err)
	}
	a := m.autoUpdates(ctx)
	if a.Enabled != on {
		_ = t.Rollback(context.WithoutCancel(ctx))
		return AutoUpdates{}, opError(errors.New("another file in /etc/apt/apt.conf.d sets APT::Periodic differently; change it there"))
	}
	return a, nil
}

// RebootSpec starts or cancels a restart of the server.
type RebootSpec struct {
	Cancel bool `json:"cancel,omitempty"`
}

// Reboot restarts the server in a minute (shutdown -r +1), so the panel
// can still answer and the restart can be cancelled.
func (m *Manager) Reboot(ctx context.Context, spec RebootSpec) (Reboot, error) {
	if !spec.Cancel {
		m.mu.Lock()
		busy := len(m.busy) > 0
		m.mu.Unlock()
		if busy {
			return Reboot{}, ErrBusy
		}
	}
	if m.sim {
		f := m.h.Path(shutdownScheduled)
		if spec.Cancel {
			_ = os.Remove(f)
		} else {
			if err := os.MkdirAll(m.h.Path("/run/systemd/shutdown"), 0o755); err != nil {
				return Reboot{}, opError(err)
			}
			at := m.now().Add(time.Minute)
			if err := os.WriteFile(f, []byte(fmt.Sprintf("USEC=%d\nWARN_WALL=1\nMODE=reboot\n", at.UnixMicro())), 0o644); err != nil {
				return Reboot{}, opError(err)
			}
		}
		return m.rebootState(), nil
	}
	args := []string{"-r", "+1", "Serfex: restart started from the panel"}
	if spec.Cancel {
		args = []string{"-c"}
	}
	if _, err := m.h.Run(ctx, host.Command{Name: "shutdown", Args: args}); err != nil {
		return Reboot{}, opError(err)
	}
	return m.rebootState(), nil
}
