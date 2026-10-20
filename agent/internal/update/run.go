package update

import (
	"archive/tar"
	"bufio"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"crypto/tls"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"

	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/i18n"
	"github.com/winchester37/servik/agent/internal/panel"
)

// runSpec is what a run does, written by Start or Rollback for Execute.
type runSpec struct {
	Kind    string `json:"kind"` // upgrade or rollback
	Run     string `json:"run"`
	From    string `json:"from"`
	To      string `json:"to"`
	Archive string `json:"archive,omitempty"`
	Sums    string `json:"sums,omitempty"`
	// Batch is the migration batch a rollback undoes.
	Batch int `json:"batch,omitempty"`
	// Locale is the language of the person who started it, for the log.
	Locale string `json:"locale,omitempty"`
}

// Execute performs a started run and records how it ended. The helper
// process runs it ("servik-agent -update-run RUN"); it holds the update
// lock throughout.
func (m *Manager) Execute(ctx context.Context, run string) error {
	if !runRe.MatchString(run) {
		return invalid("run %q is not valid", run)
	}
	spec, err := m.loadSpec(run)
	if err != nil {
		return opError(err)
	}
	ctx = i18n.WithLocale(ctx, spec.Locale)
	w, err := os.OpenFile(m.h.Path(runDir+"/"+run+".log"), os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o600)
	if err != nil {
		return opError(err)
	}
	defer w.Close()
	unlock, err := m.lock()
	if err != nil {
		fmt.Fprintln(w, "✗ "+i18n.T(ctx, "Başka bir güncelleme sürüyor."))
		return err
	}
	defer unlock()

	x := &execution{ctx: ctx, m: m, spec: spec, w: w}
	if spec.Kind == "rollback" {
		err = x.rollback()
	} else {
		err = x.upgrade()
	}

	m.mu.Lock()
	defer m.mu.Unlock()
	st, lerr := m.loadState()
	if lerr != nil {
		st = &state{}
	}
	out := &Outcome{Run: run, Kind: spec.Kind, From: spec.From, To: spec.To, Status: "succeeded", FinishedAt: m.now().UTC().Format(time.RFC3339)}
	switch {
	case err != nil && x.recovered:
		out.Status, out.Error = "rolled_back", lastLine(err.Error())
	case err != nil:
		out.Status, out.Error = "failed", lastLine(err.Error())
	case spec.Kind == "rollback":
		st.Previous, st.Batch = "", 0
	default:
		st.Previous, st.Batch = spec.From, x.batch
	}
	st.Last, st.Running = out, ""
	if serr := m.saveState(st); serr != nil && err == nil {
		err = serr
	}
	switch {
	case err == nil && spec.Kind == "rollback":
		fmt.Fprintf(w, "✓ %s\n", i18n.T(ctx, "Servik %s sürümüne dönüldü.", spec.To))
	case err == nil:
		fmt.Fprintf(w, "✓ %s\n", i18n.T(ctx, "Servik %s kuruldu.", spec.To))
	case x.recovered:
		fmt.Fprintf(w, "✗ %s\n", i18n.T(ctx, "%s Servik %s ile çalışmaya devam ediyor.", lastLine(err.Error()), spec.From))
	default:
		fmt.Fprintf(w, "✗ %s\n", lastLine(err.Error()))
	}
	return opError(err)
}

func lastLine(s string) string {
	s = strings.TrimSpace(s)
	if len(s) > 600 {
		s = "..." + s[len(s)-600:]
	}
	return strings.ReplaceAll(s, "\n", " ")
}

// execution is one running update or rollback.
type execution struct {
	ctx  context.Context
	m    *Manager
	spec runSpec
	w    io.Writer
	cfg  panel.Config
	db   dbConn

	// batch is the migration batch the upgrade added (0: none).
	batch int
	// What has been changed, for the way back.
	down, stopped, snapshot, migrated, switched, restarted bool
	// recovered is true once the previous release runs again.
	recovered bool
}

func (x *execution) step(format string, a ...any) {
	fmt.Fprintf(x.w, "▸ "+i18n.Translate(x.ctx, format)+"\n", a...)
}

func (x *execution) failpoint(step string) error {
	if x.m.Fail != nil {
		return x.m.Fail(step)
	}
	return nil
}

// run runs a command with its output in the log.
func (x *execution) run(c host.Command) (string, error) {
	fmt.Fprintf(x.w, "$ %s\n", c)
	c.Stream = x.w
	return x.m.h.Run(x.ctx, c)
}

func (x *execution) quiet(c host.Command) (string, error) {
	out, err := x.m.h.Run(x.ctx, c)
	if err != nil {
		fmt.Fprintf(x.w, "$ %s\n%s\n", c, strings.TrimSpace(out))
	}
	return out, err
}

// artisan runs an Artisan command of a release as the panel's account.
func (x *execution) artisan(version string, args ...string) error {
	dir := panel.ReleasesDir + "/" + version + "/panel"
	_, err := x.run(host.AsUser(panel.User, host.Command{Name: "env", Args: append([]string{"-C", dir, "php" + x.cfg.PHP, "artisan"}, args...)}))
	return err
}

func (x *execution) systemctl(args ...string) error {
	_, err := x.run(host.Command{Name: "systemctl", Args: args})
	return err
}

func (x *execution) prepare() error {
	cfg, ok := panel.Load(x.m.h)
	if !ok {
		return fmt.Errorf("%s is missing: Servik was not installed with install.sh", panel.ConfigFile)
	}
	x.cfg = cfg
	env, err := panel.Env(x.m.h)
	if err != nil {
		return fmt.Errorf("reading the panel's settings: %w", err)
	}
	x.db = dbConn{host: env["DB_HOST"], port: env["DB_PORT"], name: env["DB_DATABASE"], user: env["DB_USERNAME"], password: env["DB_PASSWORD"]}
	if x.db.host == "" {
		x.db.host = "127.0.0.1"
	}
	if x.db.port == "" {
		x.db.port = "5432"
	}
	if env["DB_CONNECTION"] != "pgsql" || x.db.name == "" || x.db.user == "" {
		return errors.New("the panel's database settings (DB_* in " + panel.EnvFile + ") are incomplete")
	}
	if !x.m.exists(panel.ReleasesDir + "/" + x.spec.From + "/VERSION") {
		return fmt.Errorf("the running release %s is not in %s", x.spec.From, panel.ReleasesDir)
	}
	return nil
}

// upgrade installs spec.To. Until the panel goes into maintenance
// nothing the server runs has changed; after that, any failure brings
// the previous release back.
func (x *execution) upgrade() (err error) {
	s := x.spec
	fmt.Fprintf(x.w, "Servik %s → %s\n", s.From, s.To)
	if err := x.prepare(); err != nil {
		return err
	}
	dir := panel.ReleasesDir + "/" + s.To
	if err := x.fetch(dir); err != nil {
		_ = os.RemoveAll(x.m.h.Path(dir + ".tmp"))
		return err
	}
	keep := false
	defer func() {
		if !keep {
			_ = os.RemoveAll(x.m.h.Path(dir))
		}
	}()
	if err := x.link(s.To); err != nil {
		return err
	}
	x.step("Yeni sürüm hazırlanıyor")
	if err := x.failpoint("optimize"); err != nil {
		return err
	}
	if err := x.artisan(s.To, "optimize"); err != nil {
		return fmt.Errorf("the new release could not prepare itself: %w", err)
	}

	defer func() {
		if err != nil {
			x.recover(s.From, s.To)
		}
	}()
	if err := x.maintenance(s.From); err != nil {
		return err
	}
	before, err := x.batchNow()
	if err != nil {
		return err
	}
	x.step("Veritabanı güncelleniyor")
	x.migrated = true
	if err := x.failpoint("migrate"); err != nil {
		return err
	}
	if err := x.artisan(s.To, "migrate", "--force", "--no-interaction"); err != nil {
		return fmt.Errorf("the database migrations failed: %w", err)
	}
	if after, err := x.batchNow(); err == nil && after > before {
		x.batch = after
	}
	if err := x.switchTo(s.To); err != nil {
		return err
	}
	if err := x.restart(s.To); err != nil {
		return err
	}
	if err := x.check(); err != nil {
		return err
	}
	keep = true
	x.cleanup(s.To, s.From)
	return nil
}

// rollback returns to spec.To, the previous release: the migrations the
// running release added are undone with its own code, then the previous
// release takes over. A failure brings the running release back.
func (x *execution) rollback() (err error) {
	s := x.spec
	fmt.Fprintf(x.w, "%s\n", i18n.T(x.ctx, "Servik %s → %s (önceki sürüm)", s.From, s.To))
	if err := x.prepare(); err != nil {
		return err
	}
	if !x.m.exists(panel.ReleasesDir + "/" + s.To + "/panel/artisan") {
		return fmt.Errorf("the previous release %s is no longer on this server", s.To)
	}
	defer func() {
		if err != nil {
			x.recover(s.From, s.To)
		}
	}()
	if err := x.maintenance(s.From); err != nil {
		return err
	}
	if s.Batch > 0 {
		x.step("Bu sürümün veritabanı değişiklikleri geri alınıyor")
		x.migrated = true
		if err := x.failpoint("migrate"); err != nil {
			return err
		}
		if err := x.artisan(s.From, "migrate:rollback", "--batch="+strconv.Itoa(s.Batch), "--force", "--no-interaction"); err != nil {
			return fmt.Errorf("undoing the database changes failed: %w", err)
		}
	}
	if err := x.artisan(s.To, "optimize"); err != nil {
		return fmt.Errorf("the previous release could not prepare itself: %w", err)
	}
	if err := x.switchTo(s.To); err != nil {
		return err
	}
	if err := x.restart(s.To); err != nil {
		return err
	}
	if err := x.check(); err != nil {
		return err
	}
	// The release returned from stays on disk until the next update:
	// processes still running its code (servik:update, an open page's
	// request) would fail on files that disappear under them.
	x.cleanup(s.To, s.From)
	return nil
}

// fetch downloads, verifies and unpacks the archive into dir.
func (x *execution) fetch(dir string) error {
	s := x.spec
	name := ArchiveName(s.To, x.m.Arch)
	dl := downloadDir + "/" + s.To
	if err := os.MkdirAll(x.m.h.Path(dl), 0o700); err != nil {
		return err
	}
	defer os.RemoveAll(x.m.h.Path(dl))

	x.step("İndiriliyor: %s", name)
	if err := x.failpoint("download"); err != nil {
		return err
	}
	archive := x.m.h.Path(dl + "/" + name)
	size, sum, err := x.download(s.Archive, archive)
	if err != nil {
		return fmt.Errorf("downloading %s: %w", name, err)
	}
	fmt.Fprintln(x.w, i18n.T(x.ctx, "%s indirildi", humanBytes(size)))

	x.step("Doğrulanıyor: SHA-256")
	if err := x.failpoint("verify"); err != nil {
		return err
	}
	sums := x.m.h.Path(dl + "/" + SumsAsset)
	if _, _, err := x.download(s.Sums, sums); err != nil {
		return fmt.Errorf("downloading %s: %w", SumsAsset, err)
	}
	want, err := sumFor(sums, name)
	if err != nil {
		return err
	}
	if want != sum {
		return fmt.Errorf("%s does not match its SHA-256 sum (got %s, the release lists %s)", name, sum, want)
	}
	fmt.Fprintf(x.w, "%s  %s\n", sum, name)

	x.step("Açılıyor: %s", dir)
	tmp := dir + ".tmp"
	_ = os.RemoveAll(x.m.h.Path(tmp))
	_ = os.RemoveAll(x.m.h.Path(dir))
	if err := unpack(archive, x.m.h.Path(tmp)); err != nil {
		return fmt.Errorf("unpacking %s: %w", name, err)
	}
	data, err := os.ReadFile(x.m.h.Path(tmp + "/VERSION"))
	if err != nil || strings.TrimSpace(string(data)) != s.To {
		return fmt.Errorf("the archive is not release %s (VERSION says %q)", s.To, strings.TrimSpace(string(data)))
	}
	for _, f := range []string{"/bin/servik-agent", "/panel/artisan", "/panel/public/index.php", "/panel/vendor/autoload.php"} {
		if !x.m.exists(tmp + f) {
			return fmt.Errorf("the archive has no %s", strings.TrimPrefix(f, "/"))
		}
	}
	if !x.m.sim {
		out, err := x.m.h.Run(x.ctx, host.Command{Name: x.m.h.Path(tmp + "/bin/servik-agent"), Args: []string{"-version"}})
		if err != nil || strings.TrimSpace(out) != s.To {
			return fmt.Errorf("the new agent does not run or is not %s: %s", s.To, strings.TrimSpace(out))
		}
	}
	return os.Rename(x.m.h.Path(tmp), x.m.h.Path(dir))
}

// download saves url to file and returns its size and SHA-256.
func (x *execution) download(url, file string) (int64, string, error) {
	req, err := http.NewRequestWithContext(x.ctx, http.MethodGet, url, nil)
	if err != nil {
		return 0, "", err
	}
	req.Header.Set("User-Agent", "servik-agent/"+x.m.Agent)
	req.Header.Set("Accept", "application/octet-stream")
	resp, err := x.m.HTTP.Do(req)
	if err != nil {
		return 0, "", err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return 0, "", fmt.Errorf("HTTP %d", resp.StatusCode)
	}
	f, err := os.OpenFile(file, os.O_CREATE|os.O_TRUNC|os.O_WRONLY, 0o600)
	if err != nil {
		return 0, "", err
	}
	defer f.Close()
	h := sha256.New()
	n, err := io.Copy(io.MultiWriter(f, h), io.LimitReader(resp.Body, 2<<30))
	if err != nil {
		return 0, "", err
	}
	return n, hex.EncodeToString(h.Sum(nil)), f.Close()
}

// sumFor finds name's sum in a sha256sum(1) file.
func sumFor(file, name string) (string, error) {
	f, err := os.Open(file)
	if err != nil {
		return "", err
	}
	defer f.Close()
	sc := bufio.NewScanner(f)
	for sc.Scan() {
		fields := strings.Fields(sc.Text())
		if len(fields) == 2 && strings.TrimPrefix(fields[1], "*") == name && len(fields[0]) == 64 {
			return strings.ToLower(fields[0]), nil
		}
	}
	return "", fmt.Errorf("%s does not list %s", SumsAsset, name)
}

// unpack extracts a release archive into dir. Entries must stay inside
// dir; files are root's, readable by everyone, never setuid.
func unpack(archive, dir string) error {
	f, err := os.Open(archive)
	if err != nil {
		return err
	}
	defer f.Close()
	gz, err := gzip.NewReader(f)
	if err != nil {
		return err
	}
	tr := tar.NewReader(gz)
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return err
	}
	for {
		hd, err := tr.Next()
		if errors.Is(err, io.EOF) {
			return nil
		}
		if err != nil {
			return err
		}
		name := filepath.Clean(strings.TrimPrefix(hd.Name, "./"))
		if name == "." {
			continue
		}
		if filepath.IsAbs(name) || name == ".." || strings.HasPrefix(name, "../") {
			return fmt.Errorf("entry %q leaves the release directory", hd.Name)
		}
		target := filepath.Join(dir, name)
		switch hd.Typeflag {
		case tar.TypeDir:
			if err := os.MkdirAll(target, 0o755); err != nil {
				return err
			}
		case tar.TypeReg:
			if err := os.MkdirAll(filepath.Dir(target), 0o755); err != nil {
				return err
			}
			mode := os.FileMode(0o644)
			if hd.Mode&0o111 != 0 {
				mode = 0o755
			}
			out, err := os.OpenFile(target, os.O_CREATE|os.O_TRUNC|os.O_WRONLY, mode)
			if err != nil {
				return err
			}
			if _, err := io.Copy(out, io.LimitReader(tr, 1<<30)); err != nil {
				out.Close()
				return err
			}
			if err := out.Close(); err != nil {
				return err
			}
		case tar.TypeSymlink:
			if filepath.IsAbs(hd.Linkname) {
				return fmt.Errorf("symlink %q points outside the release", hd.Name)
			}
			dest := filepath.Clean(filepath.Join(filepath.Dir(target), hd.Linkname))
			if rel, err := filepath.Rel(dir, dest); err != nil || rel == ".." || strings.HasPrefix(rel, "../") {
				return fmt.Errorf("symlink %q points outside the release", hd.Name)
			}
			if err := os.MkdirAll(filepath.Dir(target), 0o755); err != nil {
				return err
			}
			if err := os.Symlink(hd.Linkname, target); err != nil {
				return err
			}
		default:
			return fmt.Errorf("entry %q has an unsupported type", hd.Name)
		}
	}
}

// link connects a release to the panel's shared settings and storage,
// puts the command line on the PATH and lets the panel write its
// bootstrap cache.
func (x *execution) link(version string) error {
	base := panel.ReleasesDir + "/" + version + "/panel"
	for link, target := range map[string]string{base + "/.env": panel.EnvFile, base + "/storage": panel.StorageDir} {
		p := x.m.h.Path(link)
		_ = os.RemoveAll(p)
		rel, err := filepath.Rel(filepath.Dir(link), target)
		if err != nil {
			return err
		}
		if err := os.Symlink(rel, p); err != nil {
			return err
		}
	}
	// The servik command line follows current; servers installed before
	// it shipped get the link with their first release that has it.
	if _, err := os.Stat(x.m.h.Path(panel.ReleasesDir + "/" + version + "/bin/servik")); err == nil {
		if _, err := os.Lstat(x.m.h.Path(panel.CLILink)); errors.Is(err, os.ErrNotExist) {
			_ = os.MkdirAll(filepath.Dir(x.m.h.Path(panel.CLILink)), 0o755)
			if err := os.Symlink(panel.CurrentLink+"/bin/servik", x.m.h.Path(panel.CLILink)); err != nil {
				return err
			}
		}
	}
	u, err := x.m.h.LookupUser(panel.User)
	if err != nil {
		return fmt.Errorf("the panel's account %s: %w", panel.User, err)
	}
	cache := base + "/bootstrap/cache"
	if err := os.MkdirAll(x.m.h.Path(cache), 0o755); err != nil {
		return err
	}
	entries, _ := os.ReadDir(x.m.h.Path(cache))
	for _, e := range entries {
		if e.Name() != ".gitignore" {
			_ = os.Remove(x.m.h.Path(cache + "/" + e.Name()))
		}
	}
	return x.m.h.Chown(x.m.h.Path(cache), u.UID, u.GID)
}

// maintenance shows the update notice, stops the panel's workers and
// takes a snapshot of the panel database to return to.
func (x *execution) maintenance(running string) error {
	x.step("Panel bakım moduna alınıyor")
	if err := x.failpoint("down"); err != nil {
		return err
	}
	x.down = true
	if err := x.artisan(running, "down", "--render=errors::updating", "--retry=10"); err != nil {
		return err
	}
	x.stopped = true
	if err := x.systemctl(append([]string{"stop"}, panel.Units...)...); err != nil {
		return err
	}
	x.step("Panel veritabanının anlık görüntüsü alınıyor")
	if err := x.failpoint("snapshot"); err != nil {
		return err
	}
	if err := os.MkdirAll(x.m.h.Path(snapshotDir), 0o700); err != nil {
		return err
	}
	file := x.snapshotFile()
	out, err := os.OpenFile(x.m.h.Path(file), os.O_CREATE|os.O_TRUNC|os.O_WRONLY, 0o600)
	if err != nil {
		return err
	}
	_, err = x.quietTo(x.db.command("pg_dump", "--format=custom", "--no-owner", "--no-privileges", "--dbname="+x.db.name), out)
	if cerr := out.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		return fmt.Errorf("the panel database could not be saved, nothing was changed: %w", err)
	}
	x.snapshot = true
	return nil
}

// dropAll empties the panel's schema, so tables the newer release's
// migrations added go too: pg_restore --clean drops only what it restores.
const dropAll = `DO $$
DECLARE r record;
BEGIN
	FOR r IN SELECT format('DROP VIEW IF EXISTS public.%I CASCADE', viewname) AS q FROM pg_views WHERE schemaname = 'public'
		UNION ALL SELECT format('DROP MATERIALIZED VIEW IF EXISTS public.%I CASCADE', matviewname) FROM pg_matviews WHERE schemaname = 'public'
		UNION ALL SELECT format('DROP TABLE IF EXISTS public.%I CASCADE', tablename) FROM pg_tables WHERE schemaname = 'public'
		UNION ALL SELECT format('DROP SEQUENCE IF EXISTS public.%I CASCADE', sequencename) FROM pg_sequences WHERE schemaname = 'public'
	LOOP
		EXECUTE r.q;
	END LOOP;
END $$;`

// restoreSnapshot puts the panel database back as it was before the
// migrations, in one transaction: either all of it or none.
func (x *execution) restoreSnapshot() error {
	script := x.m.h.Path(strings.TrimSuffix(x.snapshotFile(), ".dump") + ".sql")
	defer os.Remove(script)
	if _, err := x.quiet(x.db.command("pg_restore", "--clean", "--if-exists", "--no-owner", "--no-privileges", "--file="+script, x.m.h.Path(x.snapshotFile()))); err != nil {
		return err
	}
	_, err := x.quiet(x.db.command("psql", "-X", "-q", "-v", "ON_ERROR_STOP=1", "--single-transaction", "--dbname="+x.db.name, "-c", dropAll, "-f", script))
	return err
}

func (x *execution) snapshotFile() string {
	return snapshotDir + "/" + x.spec.From + "-" + x.spec.Run + ".dump"
}

// batchNow is the newest migration batch in the panel database.
func (x *execution) batchNow() (int, error) {
	out, err := x.quiet(x.db.command("psql", "-X", "-A", "-t", "--dbname="+x.db.name, "-c", "SELECT coalesce(max(batch), 0) FROM migrations"))
	if err != nil {
		return 0, fmt.Errorf("reading the panel's migrations: %w", err)
	}
	n, _ := strconv.Atoi(strings.TrimSpace(out))
	return n, nil
}

// switchTo points current at version, atomically.
func (x *execution) switchTo(version string) error {
	x.step("Servik %s etkinleştiriliyor", version)
	if err := x.failpoint("switch"); err != nil {
		return err
	}
	if err := setCurrent(x.m.h, version); err != nil {
		return err
	}
	x.switched = true
	return nil
}

// setCurrent points panel.CurrentLink at a release.
func setCurrent(h host.Host, version string) error {
	link := h.Path(panel.CurrentLink)
	tmp := link + ".new"
	_ = os.Remove(tmp)
	if err := os.Symlink("releases/"+version, tmp); err != nil {
		return err
	}
	return os.Rename(tmp, link)
}

// restart brings the services up on the release current points at: the
// agent (which then applies its own panel setup), servik-php, the
// workers and the panel itself.
func (x *execution) restart(version string) error {
	x.step("Hizmetler yeniden başlatılıyor")
	if err := x.failpoint("restart"); err != nil {
		return err
	}
	x.restarted = true
	if x.m.RestartAgent != nil {
		fmt.Fprintf(x.w, "$ systemctl restart %s\n", AgentUnit)
		if err := x.m.RestartAgent(x.ctx, version); err != nil {
			return fmt.Errorf("the agent of %s did not start: %w", version, err)
		}
	}
	if x.m.ApplyPanel != nil {
		if err := x.m.ApplyPanel(x.ctx); err != nil {
			return fmt.Errorf("setting the panel up failed: %w", err)
		}
	}
	if err := x.systemctl("reload-or-restart", "servik-php"); err != nil {
		return err
	}
	x.stopped = false
	if err := x.systemctl(append([]string{"start"}, panel.Units...)...); err != nil {
		return err
	}
	if err := x.artisan(version, "up"); err != nil {
		return err
	}
	x.down = false
	return nil
}

// check is the health check: the panel answers on servik-web and its
// services run.
func (x *execution) check() error {
	x.step("Denetleniyor: panel yanıt veriyor mu")
	if err := x.failpoint("health"); err != nil {
		return err
	}
	if err := x.m.health(x.ctx); err != nil {
		return fmt.Errorf("health check failed: %w", err)
	}
	fmt.Fprintln(x.w, i18n.T(x.ctx, "Panel yanıt veriyor, hizmetler çalışıyor."))
	return nil
}

// recover brings back the release that ran before (from) after a
// failure on the way to the other one.
func (x *execution) recover(from, to string) {
	ctx := context.WithoutCancel(x.ctx)
	x.ctx = ctx
	fmt.Fprintf(x.w, "↺ %s\n", i18n.T(x.ctx, "Servik %s sürümüne geri dönülüyor", from))
	if !x.down {
		_ = x.artisan(currentOr(x.m.h, from), "down", "--render=errors::updating", "--retry=10")
	}
	_ = x.systemctl(append([]string{"stop"}, panel.Units...)...)
	if x.switched {
		fmt.Fprintf(x.w, "↺ %s\n", i18n.T(x.ctx, "Servik %s yeniden etkinleştiriliyor", from))
		if err := setCurrent(x.m.h, from); err != nil {
			fmt.Fprintf(x.w, "↺ %s\n", err)
			return
		}
		x.switched = false
	}
	if x.migrated && x.snapshot {
		fmt.Fprintln(x.w, "↺ "+i18n.T(x.ctx, "Panel veritabanı güncelleme öncesine döndürülüyor"))
		if err := x.restoreSnapshot(); err != nil {
			fmt.Fprintf(x.w, "↺ %s\n", i18n.T(x.ctx, "Veritabanı geri yüklenemedi: %s", lastLine(err.Error())))
			return
		}
	}
	if x.restarted {
		if x.m.RestartAgent != nil {
			fmt.Fprintf(x.w, "$ systemctl restart %s\n", AgentUnit)
			if err := x.m.RestartAgent(ctx, from); err != nil {
				fmt.Fprintf(x.w, "↺ %s\n", i18n.T(x.ctx, "Ajan başlatılamadı: %s", lastLine(err.Error())))
			}
		}
		if x.m.ApplyPanel != nil {
			if err := x.m.ApplyPanel(ctx); err != nil {
				fmt.Fprintf(x.w, "↺ %s\n", i18n.T(x.ctx, "Panel ayarları uygulanamadı: %s", lastLine(err.Error())))
			}
		}
	}
	_ = x.systemctl("reload-or-restart", "servik-php")
	if err := x.systemctl(append([]string{"start"}, panel.Units...)...); err != nil {
		return
	}
	if err := x.artisan(from, "up"); err != nil {
		return
	}
	if err := x.m.health(ctx); err != nil {
		fmt.Fprintf(x.w, "↺ %s\n", i18n.T(x.ctx, "Servik %s de yanıt vermiyor: %s", from, lastLine(err.Error())))
		return
	}
	x.recovered = true
	fmt.Fprintf(x.w, "↺ %s\n", i18n.T(x.ctx, "Servik %s yeniden çalışıyor", from))
}

// currentOr is the release current points at, or fallback.
func currentOr(h host.Host, fallback string) string {
	if v, err := panel.CurrentVersion(h); err == nil && v != "" {
		return v
	}
	return fallback
}

// cleanup keeps the running release and the one a rollback returns to,
// and the newest snapshot.
func (x *execution) cleanup(keep, previous string) {
	entries, _ := os.ReadDir(x.m.h.Path(panel.ReleasesDir))
	for _, e := range entries {
		if n := e.Name(); n != keep && n != previous {
			_ = os.RemoveAll(x.m.h.Path(panel.ReleasesDir + "/" + n))
		}
	}
	snaps, _ := os.ReadDir(x.m.h.Path(snapshotDir))
	for _, e := range snaps {
		if snapshotDir+"/"+e.Name() != x.snapshotFile() {
			_ = os.Remove(x.m.h.Path(snapshotDir + "/" + e.Name()))
		}
	}
}

// health runs the configured check.
func (m *Manager) health(ctx context.Context) error {
	if m.Health != nil {
		return m.Health(ctx)
	}
	if m.sim {
		return nil
	}
	port := 8443
	if m.WebPort != nil {
		port = m.WebPort()
	}
	cfg, _ := panel.Load(m.h)
	client := &http.Client{
		Timeout: 10 * time.Second,
		// The panel's own address, over loopback: the certificate names
		// the public hostname.
		Transport: &http.Transport{TLSClientConfig: &tls.Config{InsecureSkipVerify: true}},
	}
	url := fmt.Sprintf("https://127.0.0.1:%d/up", port)
	deadline := m.now().Add(90 * time.Second)
	var last error
	for {
		req, _ := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
		if cfg.Hostname != "" {
			req.Host = cfg.Hostname
		}
		resp, err := client.Do(req)
		if err == nil {
			resp.Body.Close()
			if resp.StatusCode == http.StatusOK {
				break
			}
			err = fmt.Errorf("%s answered HTTP %d", url, resp.StatusCode)
		}
		last = err
		if m.now().After(deadline) {
			return last
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(m.wait):
		}
	}
	units := append([]string{"servik-web", "servik-php"}, panel.Units...)
	out, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: append([]string{"is-active"}, units...)})
	if err != nil {
		var down []string
		for i, line := range strings.Split(strings.TrimSpace(out), "\n") {
			if i < len(units) && strings.TrimSpace(line) != "active" {
				down = append(down, units[i])
			}
		}
		return fmt.Errorf("not running: %s", strings.Join(down, ", "))
	}
	return nil
}

// dbConn reaches the panel's database with its own credentials.
type dbConn struct{ host, port, name, user, password string }

func (d dbConn) command(name string, args ...string) host.Command {
	return host.Command{
		Name: name,
		Args: append([]string{"--host=" + d.host, "--port=" + d.port, "--username=" + d.user, "--no-password"}, args...),
		Env:  []string{"PGPASSWORD=" + d.password, "PGCONNECT_TIMEOUT=10"},
	}
}

func (x *execution) quietTo(c host.Command, out io.Writer) (string, error) {
	c.Stdout = out
	return x.quiet(c)
}

func humanBytes(n int64) string {
	switch {
	case n >= 1<<30:
		return fmt.Sprintf("%.1f GB", float64(n)/(1<<30))
	case n >= 1<<20:
		return fmt.Sprintf("%.1f MB", float64(n)/(1<<20))
	case n >= 1<<10:
		return fmt.Sprintf("%.0f KB", float64(n)/(1<<10))
	}
	return fmt.Sprintf("%d B", n)
}
