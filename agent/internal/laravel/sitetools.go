package laravel

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"time"

	"github.com/calltech-app/serfex/agent/internal/i18n"
	"github.com/calltech-app/serfex/agent/internal/site"
)

// Composer and Node.js on any site (Site → Composer ve Node.js), not only
// in a Laravel application: the same tools, account, PATH and run logs as
// the Laravel commands, in a folder of the site the customer picks.
// Composer and a Node.js line are fetched on first use, as a Laravel
// deploy would.

// SiteRunSpec is a Composer or package manager command in a folder of a
// site.
type SiteRunSpec struct {
	Target
	// Path is the folder relative to the site directory; empty is the
	// site directory itself.
	Path string `json:"path"`
	Run  string `json:"run"`
	// Tool is composer, npm, pnpm, yarn or bun.
	Tool string   `json:"tool"`
	Args []string `json:"args"`
}

func (s SiteRunSpec) dir() string {
	if s.Path == "" {
		return siteDir(s.Target)
	}
	return siteDir(s.Target) + "/" + s.Path
}

func (s SiteRunSpec) validate() error {
	if err := s.Target.validatePHP(); err != nil {
		return err
	}
	if s.Path != "" {
		if err := site.ValidateRelPath(s.Path); err != nil {
			return invalid("path: %s", err.Error())
		}
	}
	if err := validateID("run", s.Run); err != nil {
		return err
	}
	switch s.Tool {
	case "composer", "npm", "pnpm", "yarn", "bun":
	default:
		return invalid("tool must be composer, npm, pnpm, yarn or bun")
	}
	if len(s.Args) > 64 {
		return invalid("at most 64 arguments are allowed")
	}
	for _, a := range s.Args {
		if len(a) > 4096 || strings.ContainsRune(a, 0) {
			return invalid("arguments must be shorter than 4096 bytes")
		}
	}
	return nil
}

// SiteRun runs a Composer or Node.js command as the site's account in a
// folder of the site, its output streaming to the run log (read with
// laravel.run.read). A non-zero exit code is a result, not an error.
func (m *Manager) SiteRun(ctx context.Context, spec SiteRunSpec) (RunResult, error) {
	if err := spec.validate(); err != nil {
		return RunResult{}, err
	}
	if err := m.requirePHP(spec.PHPVersion); err != nil {
		return RunResult{}, err
	}
	if !m.isDir(spec.dir()) {
		return RunResult{}, invalid("folder %s does not exist", spec.dir())
	}
	name, args, err := argv(spec.Target, spec.Tool, spec.Args)
	if err != nil {
		return RunResult{}, err
	}
	log, err := m.openRun(spec.Domain, spec.Run)
	if err != nil {
		return RunResult{}, &OperationError{err}
	}
	defer log.Close()
	ctx, cancel := context.WithTimeout(ctx, runTimeout)
	defer cancel()

	start := time.Now()
	if spec.Tool == "composer" && !m.exists(composerPhar()) {
		fmt.Fprintln(log, "▸ "+i18n.T(ctx, "Composer indiriliyor (getcomposer.org)"))
		if err := m.InstallComposer(ctx); err != nil {
			fmt.Fprintf(log, "\n✗ %s\n", err)
			return RunResult{}, &OperationError{err}
		}
	}
	if spec.Tool != "composer" {
		if len(m.NodeVersions()) == 0 {
			fmt.Fprintln(log, "▸ "+i18n.T(ctx, "Node.js indiriliyor (nodejs.org, v%s.x)", nodeLine))
			version, err := m.InstallNode(ctx, nodeLine)
			if err != nil {
				fmt.Fprintf(log, "\n✗ %s\n", err)
				return RunResult{}, &OperationError{err}
			}
			fmt.Fprintln(log, "  "+i18n.T(ctx, "Node.js %s kuruldu", version))
		} else if _, ok := m.NodeVersions()[spec.Node]; spec.Node != "" && !ok {
			fmt.Fprintln(log, "▸ "+i18n.T(ctx, "Node.js %s kurulu değil; sunucunun varsayılan sürümü kullanılıyor", spec.Node))
		}
	}
	if err := m.writeShims(spec.PHPVersion); err != nil {
		return RunResult{}, &OperationError{err}
	}
	fmt.Fprintf(log, "$ %s %s\n", display(spec.Tool), strings.Join(quoteArgs(spec.Args), " "))
	_, err = m.h.Run(ctx, m.cmd(spec.Target, spec.dir(), log, name, args...))
	code := host0(err)
	if errors.Is(ctx.Err(), context.DeadlineExceeded) {
		fmt.Fprintf(log, "\n✗ %s\n", i18n.T(ctx, "%d dakika sınırı aşıldı, komut durduruldu.", int(runTimeout.Minutes())))
	} else if code != 0 {
		fmt.Fprintf(log, "\n✗ %s\n", i18n.T(ctx, "Çıkış kodu %d", code))
	}
	return RunResult{ExitCode: code, Millis: time.Since(start).Milliseconds()}, nil
}

// SiteProject is a folder of a site with a composer.json or a
// package.json.
type SiteProject struct {
	Path     string `json:"path"`
	Composer bool   `json:"composer"`
	// ComposerLock and Vendor say whether composer.lock and vendor/ are
	// there.
	ComposerLock bool `json:"composer_lock"`
	Vendor       bool `json:"vendor"`
	Node         bool `json:"node"`
	// Manager is the package manager the lock file belongs to: npm, pnpm,
	// yarn or bun.
	Manager string `json:"manager,omitempty"`
	// Lock is whether a lock file is there, and Modules node_modules/.
	Lock    bool     `json:"lock"`
	Modules bool     `json:"modules"`
	Scripts []string `json:"scripts"`
}

// SiteTools is what the Composer and Node.js page shows.
type SiteTools struct {
	Composer string `json:"composer"`
	// Node maps each installed Node.js line to its version, and
	// DefaultNode is the line the server uses when a site picks none.
	Node        map[string]string `json:"node"`
	DefaultNode string            `json:"default_node"`
	Projects    []SiteProject     `json:"projects"`
}

const (
	maxProjects  = 20
	projectDepth = 4
)

// skipDirs are never looked into for projects.
var skipDirs = map[string]bool{
	"node_modules": true, "vendor": true, ".git": true, "logs": true, "storage": true, "cache": true,
	"releases": true, "shared": true, "uploads": true, "tmp": true, ".ssh": true,
	"wp-admin": true, "wp-includes": true,
}

// SiteScan lists the folders of a site (four levels deep, so a
// WordPress theme's public_html/wp-content/themes/<theme> is found) that hold a
// composer.json or a package.json, with the scripts package.json offers,
// and the installed Composer and Node.js versions. A Laravel application
// Serfex deploys is listed as laravel/current.
func (m *Manager) SiteScan(ctx context.Context, t Target) (SiteTools, error) {
	if err := t.validatePHP(); err != nil {
		return SiteTools{}, err
	}
	out := SiteTools{Node: m.NodeVersions(), DefaultNode: m.DefaultNode(), Projects: []SiteProject{}}
	if m.exists(composerPhar()) {
		out.Composer = m.ComposerVersion(ctx)
	}
	root := siteDir(t)
	if !m.isDir(root) {
		return out, invalid("site %s does not exist", t.Domain)
	}
	var dirs []string
	if m.isDir(root + "/" + CurrentPath) {
		dirs = append(dirs, CurrentPath)
	}
	// Metadata only; contents are read as the account below.
	_ = filepath.WalkDir(m.h.Path(root), func(p string, e fs.DirEntry, err error) error {
		if err != nil || !e.IsDir() {
			return nil
		}
		rel, _ := filepath.Rel(m.h.Path(root), p)
		if rel == "." {
			rel = ""
		}
		if rel != "" && (skipDirs[e.Name()] || strings.HasPrefix(e.Name(), ".")) {
			return filepath.SkipDir
		}
		if strings.Count(rel, "/") >= projectDepth-1 && rel != "" {
			dirs = append(dirs, rel)
			return filepath.SkipDir
		}
		dirs = append(dirs, rel)
		return nil
	})
	for _, rel := range dirs {
		if len(out.Projects) >= maxProjects {
			break
		}
		dir := root
		if rel != "" {
			dir += "/" + rel
		}
		p := SiteProject{Path: rel, Scripts: []string{}}
		p.Composer = m.exists(dir + "/composer.json")
		p.Node = m.exists(dir + "/package.json")
		if !p.Composer && !p.Node {
			continue
		}
		p.ComposerLock, p.Vendor = m.exists(dir+"/composer.lock"), m.isDir(dir+"/vendor")
		if p.Node {
			p.Manager = "npm"
			for _, l := range []struct{ file, manager string }{{"pnpm-lock.yaml", "pnpm"}, {"yarn.lock", "yarn"}, {"bun.lockb", "bun"}, {"bun.lock", "bun"}, {"package-lock.json", "npm"}} {
				if m.exists(dir + "/" + l.file) {
					p.Manager, p.Lock = l.manager, true
					break
				}
			}
			p.Modules = m.isDir(dir + "/node_modules")
			p.Scripts = m.packageScripts(ctx, t, dir+"/package.json")
		}
		out.Projects = append(out.Projects, p)
	}
	return out, nil
}

// packageScripts reads the script names of a package.json as the account.
func (m *Manager) packageScripts(ctx context.Context, t Target, file string) []string {
	if fi, err := os.Lstat(m.h.Path(file)); err != nil || !fi.Mode().IsRegular() || fi.Size() > 512<<10 {
		return []string{}
	}
	data, err := m.readFile(ctx, t, file, 512<<10)
	if err != nil {
		return []string{}
	}
	var pkg struct {
		Scripts map[string]json.RawMessage `json:"scripts"`
	}
	if json.Unmarshal(data, &pkg) != nil {
		return []string{}
	}
	names := make([]string, 0, len(pkg.Scripts))
	for k := range pkg.Scripts {
		if len(k) <= 100 && !strings.ContainsAny(k, " \t\r\n\"'") {
			names = append(names, k)
		}
	}
	sort.Strings(names)
	if len(names) > 30 {
		names = names[:30]
	}
	return names
}
