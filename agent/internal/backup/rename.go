package backup

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/calltech-app/serfex/agent/internal/cron"
	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/i18n"
	"github.com/calltech-app/serfex/agent/internal/laravel"
	"github.com/calltech-app/serfex/agent/internal/site"
)

// Alan adını değiştir: a site moves to another domain on the same account
// (site.Manager.Rename moves its folder and web server files). Here, in
// the backup slot so that no backup, restore or copy reads the site while
// it moves:
//
//   - its queue workers, scheduler, processes and Redis stop (the panel
//     starts them again under the new name);
//   - its Laravel run records, logs and backups, Git state, WordPress
//     restore points, Redis data and task history follow the new name;
//   - its incremental backup state is dropped, so its next backup under
//     the new name is a full one (the panel chains archives by name);
//   - settings files (.env, wp-config.php, config.php...) get the new
//     addresses (https://old → https://new) and the new folder path;
//   - a Laravel application's cached configuration is built again;
//   - a WordPress gets its new address in its database (search-replace).
//
// When a step fails everything is put back, the settings files and the
// WordPress database included.

// RenameSpec is site.rename's request.
type RenameSpec struct {
	Run  string `json:"run"`
	From string `json:"from"`
	// Site is the site.apply spec of the site under its new name.
	Site site.Spec `json:"site"`
	// Hosts pairs the old host names with the new ones ("www.old.com",
	// "www.new.com"), longest first.
	Hosts [][2]string `json:"hosts"`
	// WordPress is the folder of the site's WordPress, relative to the
	// site directory; empty when it has none.
	WordPress string `json:"wordpress"`
}

// RenameResult says what the rename did. Notes use CopyNote's codes:
// configs (Subject: the files changed), config_cache (an application
// whose cached configuration was built again), config_cache_removed (one
// whose cache could not be built and was removed), links (how many links
// now point into the new folder) and wordpress.
type RenameResult struct {
	site.RenameResult
	Notes  []CopyNote `json:"notes"`
	Millis int64      `json:"ms"`
}

func (s *RenameSpec) validate() error {
	if err := validateKey("run", s.Run); err != nil {
		return err
	}
	if err := site.ValidateDomain(s.From); err != nil {
		return invalid("%s", err.Error())
	}
	if err := site.ValidateDomain(s.Site.Domain); err != nil {
		return invalid("%s", err.Error())
	}
	if err := site.ValidateUser(s.Site.User); err != nil {
		return invalid("%s", err.Error())
	}
	if err := site.ValidatePHPVersion(s.Site.PHPVersion); err != nil {
		return invalid("%s", err.Error())
	}
	if len(s.Hosts) > maxCopyHosts {
		return invalid("at most %d host names are allowed", maxCopyHosts)
	}
	for _, h := range s.Hosts {
		for _, n := range h {
			if err := site.ValidateDomain(n); err != nil {
				return invalid("%s", err.Error())
			}
		}
	}
	if s.WordPress != "" {
		if err := site.ValidateRelPath(s.WordPress); err != nil {
			return invalid("wordpress: %s", err.Error())
		}
	}
	return nil
}

// Rename moves a site to its new domain. It waits for a backup, restore
// or copy in progress.
func (m *Manager) Rename(ctx context.Context, spec RenameSpec) (res RenameResult, err error) {
	if err := spec.validate(); err != nil {
		return RenameResult{}, err
	}
	if m.Sites == nil {
		return RenameResult{}, errors.New("renaming sites is not available")
	}
	release, err := m.acquire(ctx)
	if err != nil {
		return RenameResult{}, err
	}
	defer release()
	ctx, cancel := context.WithTimeout(ctx, opTimeout)
	defer cancel()
	log, closeLog := m.logger(spec.Run)
	defer closeLog()
	defer func() {
		if err != nil {
			fmt.Fprintf(log, "✗ %s\n", i18n.T(ctx, "Alan adı değiştirilemedi, her şey eski haline getirildi: %s", err))
			err = opError(err)
		}
	}()
	start := time.Now()
	from, to, user := spec.From, spec.Site.Domain, spec.Site.User
	fmt.Fprintf(log, "▸ %s\n", i18n.T(ctx, "Alan adı değiştiriliyor: %s → %s", from, to))
	res.Notes = []CopyNote{}

	r := site.RenameSpec{
		From:   from,
		Site:   spec.Site,
		Remove: []string{cron.CronFile(from)},
		Before: func(ctx context.Context) ([][2]string, error) {
			fmt.Fprintf(log, "▸ %s\n", i18n.T(ctx, "Kuyruk işçileri, zamanlayıcı ve Redis durduruluyor"))
			moves := laravel.RenamePaths(from, to)
			if m.laravel != nil {
				if err := m.laravel.StopForRename(ctx, from); err != nil {
					return nil, fmt.Errorf("stopping the queue workers failed: %w", err)
				}
			}
			if m.StopRedis != nil {
				mv, err := m.StopRedis(ctx, from, to)
				if err != nil {
					return nil, fmt.Errorf("stopping Redis failed: %w", err)
				}
				moves = append(moves, mv...)
			}
			moves = append(moves, [2]string{cron.SitesDir(user, from), cron.SitesDir(user, to)})
			// The panel writes the tasks again under the new name.
			if err := os.Remove(m.h.Path(cron.StateFile(from))); err != nil && !errors.Is(err, os.ErrNotExist) {
				return nil, err
			}
			// Archives chain by name: the next one starts afresh.
			snars, _ := filepath.Glob(m.h.Path(stateDir + "/" + from + ".*.snar"))
			for _, f := range snars {
				_ = os.Remove(f)
			}
			fmt.Fprintf(log, "▸ %s\n", i18n.T(ctx, "Klasör taşınıyor: %s → %s", "~/web/"+from, "~/web/"+to))
			return moves, nil
		},
		Fit: func(ctx context.Context) (func(context.Context), error) {
			undo, notes, err := m.fitRename(ctx, spec, log)
			res.Notes = append(res.Notes, notes...)
			return undo, err
		},
	}
	renamed, err := m.Sites.Rename(ctx, r)
	if err != nil {
		return RenameResult{}, err
	}
	res.RenameResult = renamed
	if renamed.Links > 0 {
		res.Notes = append(res.Notes, CopyNote{Code: "links", Subject: fmt.Sprint(renamed.Links)})
	}
	res.Millis = time.Since(start).Milliseconds()
	fmt.Fprintln(log, "✓ "+i18n.T(ctx, "Site artık %s adıyla açılıyor.", to))
	return res, nil
}

// fitRename fits the site's files, now in their new folder, to the new
// name. The returned undo puts the files and the WordPress database back.
func (m *Manager) fitRename(ctx context.Context, spec RenameSpec, log io.Writer) (undo func(context.Context), notes []CopyNote, err error) {
	user := spec.Site.User
	oldDir := "/home/" + user + "/web/" + spec.From
	dir := "/home/" + user + "/web/" + spec.Site.Domain
	write := func(ctx context.Context, abs string, b []byte) error {
		_, err := m.h.Run(ctx, host.AsUser(user, host.Command{Name: "dd", Args: []string{"of=" + abs, "status=none"}, Stdin: b}))
		return err
	}
	type saved struct {
		abs string
		b   []byte
	}
	var files []saved
	wpMoved := false
	var wp laravel.App
	undo = func(ctx context.Context) {
		if wpMoved {
			back := make([][2]string, 0, len(spec.Hosts))
			for _, h := range spec.Hosts {
				back = append(back, [2]string{h[1], h[0]})
			}
			_ = m.laravel.WPMove(ctx, laravel.WPMoveSpec{App: wp, Replace: back}, io.Discard)
		}
		for i := len(files) - 1; i >= 0; i-- {
			_ = write(ctx, files[i].abs, files[i].b)
		}
	}
	defer func() {
		if err != nil {
			undo(context.WithoutCancel(ctx))
		}
	}()

	var configs, apps []string
	seen := 0
	root := m.h.Path(dir)
	_ = filepath.WalkDir(root, func(p string, e fs.DirEntry, err error) error {
		if err != nil {
			return nil
		}
		if seen++; seen > copyWalkLimit {
			return filepath.SkipAll
		}
		rel, _ := filepath.Rel(root, p)
		depth := strings.Count(rel, string(filepath.Separator))
		switch {
		case e.IsDir():
			if rel != "." && (skipFit[e.Name()] || rel == "logs") {
				return filepath.SkipDir
			}
		case e.Type().IsRegular() && depth < configDepth:
			if configNames[e.Name()] {
				configs = append(configs, rel)
			}
			if e.Name() == "artisan" {
				apps = append(apps, filepath.Dir(rel))
			}
		}
		return nil
	})

	read := func(abs string) ([]byte, bool) {
		if fi, err := os.Lstat(m.h.Path(abs)); err != nil || !fi.Mode().IsRegular() || fi.Size() > configLimit {
			return nil, false
		}
		var buf bytes.Buffer
		if _, err := m.h.Run(ctx, host.AsUser(user, host.Command{Name: "cat", Args: []string{abs}, Stdout: &buf})); err != nil {
			return nil, false
		}
		return buf.Bytes(), true
	}
	var changed []string
	for _, rel := range configs {
		abs := dir + "/" + rel
		b, ok := read(abs)
		if !ok {
			continue
		}
		out, _ := fitConfig(b, nil, spec.Hosts)
		out = replacePath(out, oldDir, dir)
		if bytes.Equal(out, b) {
			continue
		}
		files = append(files, saved{abs, b})
		if err := write(ctx, abs, out); err != nil {
			return nil, nil, fmt.Errorf("writing %s failed", rel)
		}
		changed = append(changed, rel)
		fmt.Fprintf(log, "  %s\n", i18n.T(ctx, "%s yeni adrese göre düzenlendi", rel))
	}
	if len(changed) > 0 {
		notes = append(notes, CopyNote{Code: "configs", Subject: strings.Join(changed, ", ")})
	}

	// Laravel keeps its configuration, the address in it, in one cached
	// file; it is built again from the new .env.
	for _, app := range apps {
		appDir := dir
		if app != "." {
			appDir = dir + "/" + app
		}
		cached := appDir + "/bootstrap/cache/config.php"
		b, ok := read(cached)
		if !ok {
			continue
		}
		files = append(files, saved{cached, b})
		fmt.Fprintf(log, "▸ %s\n", i18n.T(ctx, "Laravel yapılandırma önbelleği yenileniyor: %s", app))
		target := laravel.Target{Domain: spec.Site.Domain, User: user, PHPVersion: spec.Site.PHPVersion}
		if m.laravel != nil {
			if _, err := m.laravel.Artisan(ctx, target, appDir, "config:cache"); err == nil {
				notes = append(notes, CopyNote{Code: "config_cache", Subject: app})
				continue
			}
		}
		// Without the cache Laravel reads .env itself.
		if _, err := m.h.Run(ctx, host.AsUser(user, host.Command{Name: "rm", Args: []string{"-f", cached}})); err != nil {
			return nil, nil, fmt.Errorf("removing %s failed", cached)
		}
		notes = append(notes, CopyNote{Code: "config_cache_removed", Subject: app})
	}

	if spec.WordPress != "" && m.laravel != nil && m.exists(dir+"/"+spec.WordPress+"/wp-includes/version.php") {
		wp = laravel.App{Target: laravel.Target{Domain: spec.Site.Domain, User: user, PHPVersion: spec.Site.PHPVersion}, Path: spec.WordPress}
		if b, ok := read(dir + "/" + spec.WordPress + "/wp-config.php"); ok {
			files = append(files, saved{dir + "/" + spec.WordPress + "/wp-config.php", b})
		}
		fmt.Fprintf(log, "▸ %s\n", i18n.T(ctx, "WordPress yeni adresine göre düzenleniyor"))
		wpMoved = true
		if err := m.laravel.WPMove(ctx, laravel.WPMoveSpec{App: wp, Replace: spec.Hosts}, log); err != nil {
			return nil, nil, fmt.Errorf("wordpress: %w", err)
		}
		notes = append(notes, CopyNote{Code: "wordpress", Subject: spec.WordPress})
	}
	return undo, notes, nil
}

// replacePath turns the folder path from into to where from is the whole
// folder name: /home/u/web/old.com/x changes, /home/u/web/old.com.tr does
// not.
func replacePath(b []byte, from, to string) []byte {
	if from == to {
		return b
	}
	old := []byte(from)
	var out []byte
	rest := b
	for {
		i := bytes.Index(rest, old)
		if i < 0 {
			return append(out, rest...)
		}
		end := i + len(old)
		if end < len(rest) && isHostByte(rest[end]) {
			out = append(out, rest[:end]...)
		} else {
			out = append(append(out, rest[:i]...), to...)
		}
		rest = rest[end:]
	}
}
