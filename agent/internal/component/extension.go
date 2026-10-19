package component

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"slices"

	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/php"
)

// ExtensionSpec installs or removes an optional PHP extension of one
// version (php.Optional), with its progress in a run log like any other
// software operation.
type ExtensionSpec struct {
	Version string `json:"version"`
	Name    string `json:"name"`
	Remove  bool   `json:"remove"`
	Run     string `json:"run"`
}

// Extension installs or removes the package php<version>-<name> and
// reloads that version's PHP-FPM. A failed install is purged again.
func (m *Manager) Extension(ctx context.Context, spec ExtensionSpec) (Result, error) {
	c, err := m.get("php" + spec.Version)
	if err != nil {
		return Result{}, err
	}
	if !slices.Contains(php.Optional, spec.Name) {
		return Result{}, invalid("extension %q cannot be installed here", spec.Name)
	}
	if !runRe.MatchString(spec.Run) {
		return Result{}, invalid("run %q must be 1-64 letters, digits, - or _", spec.Run)
	}
	if !c.isInstalled(m) {
		return Result{}, invalid("PHP %s is not installed", spec.Version)
	}
	if spec.Remove && (spec.Name == "redis" || spec.Name == "memcached") && m.comps[spec.Name].isInstalled(m) {
		return Result{}, &InUseError{ID: spec.Name + " extension", UsedBy: []string{componentName[spec.Name]}}
	}
	if !m.op.TryLock() {
		return Result{}, ErrBusy
	}
	defer m.op.Unlock()
	w, err := m.openRun(spec.Run)
	if err != nil {
		return Result{}, opError(err)
	}
	defer w.Close()

	action := "extension"
	m.mu.Lock()
	m.busy[c.id] = action
	m.mu.Unlock()
	defer func() {
		m.mu.Lock()
		delete(m.busy, c.id)
		m.mu.Unlock()
	}()

	o := &op{ctx: ctx, m: m, c: c, w: w}
	if spec.Remove {
		err = o.removeExtension(spec.Version, spec.Name)
	} else {
		err = o.installExtension(spec.Version, spec.Name)
	}
	if err != nil {
		fmt.Fprintf(w, "✗ %s\n", lastLine(err.Error()))
		return Result{}, opError(err)
	}
	if spec.Remove {
		fmt.Fprintln(w, "✓ Kaldırıldı")
	} else {
		fmt.Fprintln(w, "✓ Kuruldu")
	}
	for _, s := range m.List(ctx).Components {
		if s.ID == c.id {
			return Result{Action: action, Status: s}, nil
		}
	}
	return Result{Action: action}, nil
}

var componentName = map[string]string{"redis": "Redis", "memcached": "Memcached"}

func (o *op) installExtension(v, name string) (err error) {
	pkg := "php" + v + "-" + name
	defer func() {
		if err != nil {
			o.rollback(true)
		}
	}()
	if err := o.aptInstall([]string{pkg}); err != nil {
		return err
	}
	if o.m.sim {
		if err := simExtension(o.m.h, v, name, true); err != nil {
			return err
		}
	} else if _, err := o.run(host.Command{Name: "phpenmod", Args: []string{"-v", v, name}}); err != nil {
		return err
	}
	if err := o.failpoint("extension"); err != nil {
		return err
	}
	return o.reloadPHP(v)
}

func (o *op) removeExtension(v, name string) error {
	pkg := "php" + v + "-" + name
	if !o.m.exists("/etc/php/" + v + "/mods-available/" + name + ".ini") {
		return invalid("extension %s is not installed for PHP %s", name, v)
	}
	o.step("Paket kaldırılıyor: %s", pkg)
	if _, err := o.run(host.Command{Name: "apt-get", Args: []string{"purge", "-y", "-q", pkg}, Env: aptEnv}); err != nil {
		return err
	}
	if o.m.sim {
		if err := simExtension(o.m.h, v, name, false); err != nil {
			return err
		}
	}
	return o.reloadPHP(v)
}

// reloadPHP checks PHP-FPM's configuration and reloads it.
func (o *op) reloadPHP(v string) error {
	o.step("PHP-FPM %s denetleniyor ve yeniden yükleniyor", v)
	if _, err := o.run(host.Command{Name: "php-fpm" + v, Args: []string{"-t"}}); err != nil {
		return err
	}
	return o.systemctl("reload", "php"+v+"-fpm")
}

// simExtension creates or deletes what the extension's package would, on
// the simulator.
func simExtension(h host.Host, v, name string, install bool) error {
	file := h.Path("/etc/php/" + v + "/mods-available/" + name + ".ini")
	if !install {
		if err := php.SimToggle(h, v, name, false); err != nil {
			return err
		}
		return os.Remove(file)
	}
	if err := os.MkdirAll(filepath.Dir(file), 0o755); err != nil {
		return err
	}
	if err := os.WriteFile(file, fmt.Appendf(nil, "; priority=20\nextension=%s.so\n", name), 0o644); err != nil {
		return err
	}
	return php.SimToggle(h, v, name, true)
}
