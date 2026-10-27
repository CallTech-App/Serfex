// Package php manages the server-wide settings of installed PHP versions
// (the panel's Hizmetler → PHP sürümleri page): the php.ini defaults every
// site of a version starts from, and which extensions are switched on.
// Installing and removing versions and extension packages is package
// component's job; a site's own settings are package site's.
//
// Layout:
//
//	/etc/php/<v>/fpm/conf.d/90-serfex.ini   Serfex's defaults for PHP-FPM, read after php.ini
//	/etc/php/<v>/cli/conf.d/90-serfex.ini   the same for the command line, without WebOnly
//	/etc/php/<v>/mods-available/<ext>.ini   extensions, switched on with phpenmod
package php

import (
	"bufio"
	"context"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"sync"

	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/txn"
)

// ValidationError reports a request that can never succeed as given.
type ValidationError struct{ msg string }

func (e *ValidationError) Error() string { return e.msg }

func invalid(format string, a ...any) error { return &ValidationError{fmt.Sprintf(format, a...)} }

// OperationError reports a valid request that failed on the server.
type OperationError struct{ Err error }

func (e *OperationError) Error() string { return e.Err.Error() }
func (e *OperationError) Unwrap() error { return e.Err }

// Optional are extensions Serfex offers to install, each from the package
// php<v>-<name>. The others (mysql, intl, gd, ...) come with every version.
var Optional = []string{"apcu", "bz2", "gmp", "igbinary", "imagick", "ldap", "memcached", "mongodb", "redis", "soap", "tidy", "xdebug"}

// Defaults are the php.ini settings Serfex gives a new PHP version:
// enough memory, uploads and input variables for WordPress, Laravel and
// shop software, errors logged rather than shown, OPcache on.
func Defaults(timezone string) map[string]string {
	if timezone == "" {
		timezone = "UTC"
	}
	return map[string]string{
		"memory_limit":                    "256M",
		"upload_max_filesize":             "64M",
		"post_max_size":                   "64M",
		"max_execution_time":              "60",
		"max_input_time":                  "60",
		"max_input_vars":                  "3000",
		"max_file_uploads":                "20",
		"display_errors":                  "Off",
		"expose_php":                      "Off",
		"allow_url_fopen":                 "On",
		"date.timezone":                   timezone,
		"session.gc_maxlifetime":          "1440",
		"opcache.enable":                  "1",
		"opcache.memory_consumption":      "128",
		"opcache.interned_strings_buffer": "16",
		"opcache.max_accelerated_files":   "20000",
		"opcache.validate_timestamps":     "1",
		"opcache.revalidate_freq":         "2",
	}
}

var (
	versionRe = regexp.MustCompile(`^\d\.\d$`)
	keyRe     = regexp.MustCompile(`^[a-z][a-z0-9_]*(\.[a-z0-9_]+)*$`)
	valueRe   = regexp.MustCompile(`^[A-Za-z0-9_.,:/ +*@~=%-]*$`)
	extRe     = regexp.MustCompile(`^[a-z][a-z0-9_]{0,31}$`)
	prioRe    = regexp.MustCompile(`(?m)^;\s*priority\s*=\s*(\d+)`)
)

// reserved are settings Serfex keeps per site or that load code.
var reserved = map[string]bool{
	"open_basedir": true, "upload_tmp_dir": true, "sys_temp_dir": true, "session.save_path": true,
	"error_log": true, "extension": true, "zend_extension": true, "extension_dir": true,
	"disable_functions": true, "disable_classes": true, "auto_prepend_file": true, "auto_append_file": true,
}

// Manager changes PHP versions' settings, one change at a time.
type Manager struct {
	h   host.Host
	sim bool
	mu  sync.Mutex
}

// NewManager returns a Manager.
func NewManager(h host.Host) *Manager {
	_, sim := h.(*host.Simulator)
	return &Manager{h: h, sim: sim}
}

// IniFile is where Serfex's defaults for a version live.
func IniFile(v string) string { return "/etc/php/" + v + "/fpm/conf.d/90-serfex.ini" }

// CLIIniFile is the command line's copy of them: artisan, queue workers,
// the scheduler, cron tasks and Composer read it.
func CLIIniFile(v string) string { return "/etc/php/" + v + "/cli/conf.d/90-serfex.ini" }

// WebOnly are defaults the command line goes without: long-running and
// heavy commands keep PHP's own command-line values (no memory or time
// limit on Ubuntu) and print their errors to the terminal.
var WebOnly = map[string]bool{
	"memory_limit": true, "max_execution_time": true, "max_input_time": true, "display_errors": true,
}

func (m *Manager) checkVersion(v string) error {
	if !versionRe.MatchString(v) {
		return invalid("version %q must look like 8.4", v)
	}
	if _, err := os.Stat(m.h.Path("/usr/sbin/php-fpm" + v)); err != nil {
		return invalid("PHP %s is not installed", v)
	}
	return nil
}

// Extension is a PHP extension of one version.
type Extension struct {
	Name string `json:"name"`
	// Enabled is true when PHP-FPM loads it.
	Enabled bool `json:"enabled"`
	// Optional extensions can be installed and removed (Optional).
	Optional  bool `json:"optional,omitempty"`
	Installed bool `json:"installed"`
}

// Info is what the panel shows of a version.
type Info struct {
	Version string `json:"version"`
	// Values are the effective php.ini settings of PHP-FPM: php.ini with
	// Serfex's defaults on top.
	Values map[string]string `json:"values"`
	// Managed is false until Serfex's defaults file exists.
	Managed    bool              `json:"managed"`
	Defaults   map[string]string `json:"defaults"`
	Extensions []Extension       `json:"extensions"`
}

// Info reads a version's settings and extensions.
func (m *Manager) Info(v string) (Info, error) {
	if err := m.checkVersion(v); err != nil {
		return Info{}, err
	}
	info := Info{Version: v, Values: map[string]string{}, Defaults: Defaults(m.timezone())}
	for k, val := range parseINI(m.h.Path("/etc/php/" + v + "/fpm/php.ini")) {
		info.Values[k] = val
	}
	if own := parseINI(m.h.Path(IniFile(v))); own != nil {
		info.Managed = true
		for k, val := range own {
			info.Values[k] = val
		}
	} else if _, err := os.Stat(m.h.Path(IniFile(v))); err == nil {
		info.Managed = true
	}
	info.Extensions = m.extensions(v)
	return info, nil
}

// timezone is the server's time zone, the default date.timezone.
func (m *Manager) timezone() string {
	if b, err := os.ReadFile(m.h.Path("/etc/timezone")); err == nil {
		if tz := strings.TrimSpace(string(b)); tz != "" && valueRe.MatchString(tz) {
			return tz
		}
	}
	if l, err := os.Readlink(m.h.Path("/etc/localtime")); err == nil {
		if _, tz, ok := strings.Cut(l, "zoneinfo/"); ok && valueRe.MatchString(tz) {
			return tz
		}
	}
	return "UTC"
}

// parseINI reads key = value lines; nil when the file cannot be read.
func parseINI(file string) map[string]string {
	f, err := os.Open(file)
	if err != nil {
		return nil
	}
	defer f.Close()
	out := map[string]string{}
	s := bufio.NewScanner(f)
	for s.Scan() {
		line := strings.TrimSpace(s.Text())
		if line == "" || line[0] == ';' || line[0] == '[' || line[0] == '#' {
			continue
		}
		k, v, ok := strings.Cut(line, "=")
		if !ok {
			continue
		}
		k, v = strings.TrimSpace(k), strings.TrimSpace(v)
		if i := strings.Index(v, " ;"); i >= 0 {
			v = strings.TrimSpace(v[:i])
		}
		out[k] = strings.Trim(v, `"`)
	}
	return out
}

func (m *Manager) extensions(v string) []Extension {
	files, _ := filepath.Glob(m.h.Path("/etc/php/" + v + "/mods-available/*.ini"))
	found := map[string]Extension{}
	for _, f := range files {
		name := strings.TrimSuffix(filepath.Base(f), ".ini")
		if !extRe.MatchString(name) {
			continue
		}
		found[name] = Extension{Name: name, Installed: true, Enabled: m.enabled(v, name)}
	}
	for _, name := range Optional {
		e := found[name]
		e.Name, e.Optional = name, true
		found[name] = e
	}
	out := make([]Extension, 0, len(found))
	for _, e := range found {
		out = append(out, e)
	}
	sort.Slice(out, func(i, j int) bool { return out[i].Name < out[j].Name })
	return out
}

// enabled reports whether PHP-FPM of v loads ext: phpenmod links
// conf.d/<priority>-<ext>.ini to mods-available.
func (m *Manager) enabled(v, ext string) bool {
	links, _ := filepath.Glob(m.h.Path("/etc/php/" + v + "/fpm/conf.d/*-" + ext + ".ini"))
	for _, l := range links {
		base := filepath.Base(l)
		if prio, _, ok := strings.Cut(base, "-"); ok && strings.TrimSuffix(base[len(prio)+1:], ".ini") == ext {
			if _, err := strconv.Atoi(prio); err == nil {
				return true
			}
		}
	}
	return false
}

// SettingsSpec replaces Serfex's php.ini defaults of a version.
type SettingsSpec struct {
	Version string            `json:"version"`
	Values  map[string]string `json:"values"`
}

// Apply writes a version's defaults and reloads its PHP-FPM; settings PHP
// refuses leave the previous ones in place.
func (m *Manager) Apply(ctx context.Context, spec SettingsSpec) (Info, error) {
	if err := m.checkVersion(spec.Version); err != nil {
		return Info{}, err
	}
	if len(spec.Values) > 60 {
		return Info{}, invalid("at most 60 settings are allowed")
	}
	for k, v := range spec.Values {
		if len(k) > 64 || !keyRe.MatchString(k) || reserved[k] {
			return Info{}, invalid("%q cannot be set here", k)
		}
		if len(v) > 200 || !valueRe.MatchString(v) {
			return Info{}, invalid("value of %s may contain letters, digits, spaces and . , : / + * @ ~ = %% _ - only", k)
		}
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	t := txn.New(m.h)
	if err := m.write(t, spec.Version, spec.Values); err != nil {
		return Info{}, &OperationError{errors.Join(err, t.Rollback(context.WithoutCancel(ctx)))}
	}
	if t.Changed {
		if err := m.reload(ctx, spec.Version); err != nil {
			rb := t.Rollback(context.WithoutCancel(ctx))
			_ = m.reload(context.WithoutCancel(ctx), spec.Version)
			return Info{}, &OperationError{errors.Join(err, rb)}
		}
	}
	return m.Info(spec.Version)
}

// WriteDefaults gives a newly installed version Serfex's defaults, unless
// it has its own already; those reach the command line too.
func (m *Manager) WriteDefaults(t *txn.Tx, v string) error {
	if own := parseINI(m.h.Path(IniFile(v))); own != nil {
		return m.writeCLI(t, v, own)
	}
	return m.write(t, v, Defaults(m.timezone()))
}

// SyncCLI copies every version's defaults to its command line, for
// versions set up before the command line had them. The agent calls it
// when it starts.
func (m *Manager) SyncCLI(ctx context.Context) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	files, _ := filepath.Glob(m.h.Path("/etc/php/*/fpm/conf.d/90-serfex.ini"))
	var errs []error
	for _, f := range files {
		v := filepath.Base(filepath.Dir(filepath.Dir(filepath.Dir(f))))
		if !versionRe.MatchString(v) {
			continue
		}
		// A file without the marker is someone else's.
		if data, err := os.ReadFile(f); err != nil || !txn.HasMarker(data) {
			continue
		}
		t := txn.New(m.h)
		if err := m.writeCLI(t, v, parseINI(f)); err != nil {
			errs = append(errs, fmt.Errorf("PHP %s: %w", v, errors.Join(err, t.Rollback(context.WithoutCancel(ctx)))))
		}
	}
	return errors.Join(errs...)
}

func (m *Manager) write(t *txn.Tx, v string, values map[string]string) error {
	var b strings.Builder
	fmt.Fprintf(&b, "; %s. Change these on the panel's Hizmetler → PHP sürümleri page.\n", txn.Marker)
	b.WriteString("; Server-wide defaults of PHP-FPM " + v + "; sites may override some of them.\n")
	writeValues(&b, values, nil)
	if err := t.RootDir("/etc/php/"+v+"/fpm/conf.d", 0o755, 0, 0); err != nil {
		return err
	}
	if err := t.WriteFile(IniFile(v), []byte(b.String())); err != nil {
		return err
	}
	return m.writeCLI(t, v, values)
}

func (m *Manager) writeCLI(t *txn.Tx, v string, values map[string]string) error {
	var b strings.Builder
	fmt.Fprintf(&b, "; %s. Change these on the panel's Hizmetler → PHP sürümleri page.\n", txn.Marker)
	b.WriteString("; Server-wide defaults of PHP " + v + " on the command line (artisan, queues, cron, Composer).\n")
	b.WriteString("; Memory and time limits and showing errors stay the command line's own.\n")
	writeValues(&b, values, WebOnly)
	if err := t.RootDir("/etc/php/"+v+"/cli/conf.d", 0o755, 0, 0); err != nil {
		return err
	}
	return t.WriteFile(CLIIniFile(v), []byte(b.String()))
}

func writeValues(b *strings.Builder, values map[string]string, skip map[string]bool) {
	keys := make([]string, 0, len(values))
	for k := range values {
		if !skip[k] {
			keys = append(keys, k)
		}
	}
	sort.Strings(keys)
	for _, k := range keys {
		fmt.Fprintf(b, "%s = %s\n", k, values[k])
	}
}

// reload checks the configuration and reloads PHP-FPM.
func (m *Manager) reload(ctx context.Context, v string) error {
	if out, err := m.h.Run(ctx, host.Command{Name: "php-fpm" + v, Args: []string{"-t"}}); err != nil {
		return fmt.Errorf("PHP-FPM %s rejected the settings: %s", v, lastLines(out, err))
	}
	if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"reload", "php" + v + "-fpm"}}); err != nil {
		return fmt.Errorf("reloading php%s-fpm failed: %w", v, err)
	}
	return nil
}

func lastLines(out string, err error) string {
	out = strings.TrimSpace(out)
	if out == "" {
		return err.Error()
	}
	lines := strings.Split(out, "\n")
	if len(lines) > 3 {
		lines = lines[len(lines)-3:]
	}
	return strings.Join(lines, " ")
}

// ExtensionSpec switches an installed extension on or off.
type ExtensionSpec struct {
	Version string `json:"version"`
	Name    string `json:"name"`
	Enabled bool   `json:"enabled"`
}

// SetExtension switches an extension on or off for PHP-FPM and the
// command line, and reloads PHP-FPM; when PHP-FPM refuses the change it
// is undone.
func (m *Manager) SetExtension(ctx context.Context, spec ExtensionSpec) (Info, error) {
	if err := m.checkVersion(spec.Version); err != nil {
		return Info{}, err
	}
	if !extRe.MatchString(spec.Name) {
		return Info{}, invalid("extension %q is not valid", spec.Name)
	}
	if _, err := os.Stat(m.h.Path("/etc/php/" + spec.Version + "/mods-available/" + spec.Name + ".ini")); err != nil {
		return Info{}, invalid("extension %s is not installed for PHP %s", spec.Name, spec.Version)
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.enabled(spec.Version, spec.Name) == spec.Enabled {
		return m.Info(spec.Version)
	}
	if err := m.toggle(ctx, spec.Version, spec.Name, spec.Enabled); err != nil {
		return Info{}, &OperationError{err}
	}
	if err := m.reload(ctx, spec.Version); err != nil {
		undo := m.toggle(context.WithoutCancel(ctx), spec.Version, spec.Name, !spec.Enabled)
		_ = m.reload(context.WithoutCancel(ctx), spec.Version)
		return Info{}, &OperationError{errors.Join(err, undo)}
	}
	return m.Info(spec.Version)
}

func (m *Manager) toggle(ctx context.Context, v, ext string, on bool) error {
	cmd := "phpdismod"
	if on {
		cmd = "phpenmod"
	}
	if _, err := m.h.Run(ctx, host.Command{Name: cmd, Args: []string{"-v", v, ext}}); err != nil {
		return err
	}
	if m.sim {
		return SimToggle(m.h, v, ext, on)
	}
	return nil
}

// SimToggle does what phpenmod and phpdismod do, for the simulator:
// link or unlink conf.d/<priority>-<ext>.ini of both SAPIs.
func SimToggle(h host.Host, v, ext string, on bool) error {
	prio := "20"
	if b, err := os.ReadFile(h.Path("/etc/php/" + v + "/mods-available/" + ext + ".ini")); err == nil {
		if m := prioRe.FindSubmatch(b); m != nil {
			prio = string(m[1])
		}
	}
	for _, sapi := range []string{"fpm", "cli"} {
		dir := h.Path("/etc/php/" + v + "/" + sapi + "/conf.d")
		link := dir + "/" + prio + "-" + ext + ".ini"
		if !on {
			if err := os.Remove(link); err != nil && !errors.Is(err, os.ErrNotExist) {
				return err
			}
			continue
		}
		if err := os.MkdirAll(dir, 0o755); err != nil {
			return err
		}
		if _, err := os.Lstat(link); err == nil {
			continue
		}
		if err := os.Symlink("/etc/php/"+v+"/mods-available/"+ext+".ini", link); err != nil {
			return err
		}
	}
	return nil
}

// simExtensions are the extensions a fresh version has on Ubuntu, with
// their load priority.
var simExtensions = map[string]string{
	"opcache": "10", "pdo": "10", "mysqlnd": "10", "xml": "15",
	"bcmath": "20", "calendar": "20", "ctype": "20", "curl": "20", "dom": "20", "exif": "20", "fileinfo": "20",
	"ftp": "20", "gd": "20", "gettext": "20", "iconv": "20", "intl": "20", "mbstring": "20", "mysqli": "20",
	"pdo_mysql": "20", "pdo_pgsql": "20", "pdo_sqlite": "20", "pgsql": "20", "phar": "20", "posix": "20",
	"readline": "20", "simplexml": "20", "sockets": "20", "sqlite3": "20", "tokenizer": "20", "xmlreader": "20",
	"xmlwriter": "20", "xsl": "20", "zip": "20", "sodium": "20", "shmop": "20", "sysvmsg": "20",
	"redis": "20", "igbinary": "20",
}

// simPHPIni are Ubuntu's php.ini (production) values of the settings the
// panel shows.
const simPHPIni = `[PHP]
memory_limit = 128M
upload_max_filesize = 2M
post_max_size = 8M
max_execution_time = 30
max_input_time = 60
;max_input_vars = 1000
max_file_uploads = 20
display_errors = Off
expose_php = Off
allow_url_fopen = On
session.gc_maxlifetime = 1440
`

// SeedSimulator gives a dry run's PHP versions what Ubuntu's packages
// would: php.ini, the usual extensions switched on and Serfex's defaults.
func SeedSimulator(h host.Host, versions ...string) error {
	m := NewManager(h)
	for _, v := range versions {
		ini := h.Path("/etc/php/" + v + "/fpm/php.ini")
		if _, err := os.Stat(ini); err == nil {
			continue
		}
		if err := os.MkdirAll(filepath.Dir(ini), 0o755); err != nil {
			return err
		}
		if err := os.WriteFile(ini, []byte(simPHPIni), 0o644); err != nil {
			return err
		}
		mods := h.Path("/etc/php/" + v + "/mods-available")
		if err := os.MkdirAll(mods, 0o755); err != nil {
			return err
		}
		for ext, prio := range simExtensions {
			if err := os.WriteFile(mods+"/"+ext+".ini", []byte("; priority="+prio+"\nextension="+ext+".so\n"), 0o644); err != nil {
				return err
			}
			if err := SimToggle(h, v, ext, true); err != nil {
				return err
			}
		}
		t := txn.New(h)
		if err := m.WriteDefaults(t, v); err != nil {
			return err
		}
	}
	return nil
}
