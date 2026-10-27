package laravel

import (
	"archive/tar"
	"compress/gzip"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"

	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/i18n"
	"github.com/calltech-app/serfex/agent/internal/txn"
)

// Backups of one application: its live code, .env, storage and database,
// in one tar file per backup under /var/lib/serfex/backups/laravel/<domain>,
// readable only by root. The customer's account produces and consumes the
// archives (tar, the database tools) through pipes, so nothing in the
// customer's home is ever read or written as root, and the customer can
// neither change nor delete a backup.
//
// Inside a backup:
//
//	manifest.json        what is in it, and the release and database
//	code.tar.gz          the live release (node_modules left out)
//	env                  the .env file
//	storage.tar.gz       storage/ without caches, sessions and logs
//	database.sql.gz      mysqldump or pg_dump output
//	database.sqlite.gz   or the SQLite database file

const (
	backupDir     = "/var/lib/serfex/backups/laravel"
	backupTimeout = 2 * time.Hour
	readChunkSize = 4 << 20
)

// Parts of an application a backup can hold.
var backupParts = []string{"code", "env", "storage", "database"}

// BackupSpec takes a backup.
type BackupSpec struct {
	App
	ID    string   `json:"id"`
	Parts []string `json:"parts"`
	// Run, when set, is the run log progress is appended to.
	Run string `json:"run"`
}

// BackupResult describes a backup taken.
type BackupResult struct {
	ID   string `json:"id"`
	Size int64  `json:"size"`
	// Parts are the sizes of what the backup holds.
	Parts map[string]int64 `json:"parts"`
	// Skipped are parts asked for but not taken, with the reason.
	Skipped  map[string]string `json:"skipped"`
	Release  string            `json:"release"`
	Database string            `json:"database"`
}

// Manifest is manifest.json.
type Manifest struct {
	Version  int              `json:"version"`
	Domain   string           `json:"domain"`
	Path     string           `json:"path"`
	Release  string           `json:"release"`
	Created  time.Time        `json:"created"`
	Parts    map[string]int64 `json:"parts"`
	Database string           `json:"database"`
}

// RestoreSpec puts parts of a backup back.
type RestoreSpec struct {
	App
	ID    string   `json:"id"`
	Parts []string `json:"parts"`
	Run   string   `json:"run"`
}

// RestoreResult says what was restored.
type RestoreResult struct {
	Restored []string `json:"restored"`
	Current  string   `json:"current"`
}

func validParts(parts []string) error {
	if len(parts) == 0 {
		return invalid("choose at least one of code, env, storage and database")
	}
	for _, p := range parts {
		if !contains(backupParts, p) {
			return invalid("part %q must be code, env, storage or database", p)
		}
	}
	return nil
}

func backupFile(domain, id string) string { return backupDir + "/" + domain + "/" + id + ".tar" }

// BackupFile is where the backup id of the application on domain is kept.
func BackupFile(domain, id string) string { return backupFile(domain, id) }

// envPath and storagePath are where an application keeps its .env and
// storage: shared/ for releases Serfex deploys, the application directory
// otherwise.
func (a App) envPath() string {
	if a.Path == CurrentPath {
		return baseDir(a.Target) + "/shared/.env"
	}
	return a.dir() + "/.env"
}

func (a App) storagePath() string {
	if a.Path == CurrentPath {
		return baseDir(a.Target) + "/shared/storage"
	}
	return a.dir() + "/storage"
}

// logger appends progress to a run log, or discards it.
func (m *Manager) logger(domain, run string) (io.Writer, func()) {
	if run == "" {
		return io.Discard, func() {}
	}
	f, err := m.openRun(domain, run)
	if err != nil {
		return io.Discard, func() {}
	}
	return f, func() { f.Close() }
}

// Backup takes a backup of the application.
func (m *Manager) Backup(ctx context.Context, spec BackupSpec) (res BackupResult, err error) {
	if err := spec.App.validate(); err != nil {
		return BackupResult{}, err
	}
	if err := validateID("backup", spec.ID); err != nil {
		return BackupResult{}, err
	}
	if err := validParts(spec.Parts); err != nil {
		return BackupResult{}, err
	}
	if spec.Run != "" {
		if err := validateID("run", spec.Run); err != nil {
			return BackupResult{}, err
		}
	}
	if err := m.requireApp(spec.App); err != nil {
		return BackupResult{}, err
	}
	ctx, cancel := context.WithTimeout(ctx, backupTimeout)
	defer cancel()
	log, closeLog := m.logger(spec.Domain, spec.Run)
	defer closeLog()
	defer func() {
		if err != nil {
			fmt.Fprintf(log, "✗ %s\n", i18n.T(ctx, "Yedek alınamadı: %s", err))
			err = &OperationError{err}
		}
	}()

	dir := backupDir + "/" + spec.Domain
	if err := m.backupDirs(spec.Domain); err != nil {
		return BackupResult{}, err
	}
	if m.exists(backupFile(spec.Domain, spec.ID)) {
		return BackupResult{}, fmt.Errorf("backup %s exists already", spec.ID)
	}
	tmp := m.h.Path(dir + "/." + spec.ID + ".parts")
	_ = os.RemoveAll(tmp)
	if err := os.Mkdir(tmp, 0o700); err != nil {
		return BackupResult{}, err
	}
	defer os.RemoveAll(tmp)

	res = BackupResult{ID: spec.ID, Parts: map[string]int64{}, Skipped: map[string]string{}}
	man := Manifest{Version: 1, Domain: spec.Domain, Path: spec.Path, Created: time.Now().UTC(), Parts: res.Parts}
	if spec.Path == CurrentPath {
		res.Release = m.currentRelease(spec.Target)
		man.Release = res.Release
	}
	env := parseEnv(m.envContent(ctx, spec.Target, spec.envPath()))
	var files []string
	for _, part := range backupParts {
		if !contains(spec.Parts, part) {
			continue
		}
		name, skip, err := m.backupPart(ctx, spec.App, part, env, tmp, log, &res)
		if err != nil {
			return BackupResult{}, err
		}
		if skip != "" {
			res.Skipped[part] = skip
			fmt.Fprintf(log, "  %s\n", i18n.T(ctx, "%s alınmadı: %s", partLabel(ctx, part), skip))
			continue
		}
		fi, err := os.Stat(filepath.Join(tmp, name))
		if err != nil {
			return BackupResult{}, err
		}
		res.Parts[part] = fi.Size()
		files = append(files, name)
	}
	if len(files) == 0 {
		return BackupResult{}, errors.New("there was nothing to back up")
	}
	man.Database = res.Database
	mb, _ := json.MarshalIndent(man, "", "  ")
	if err := os.WriteFile(filepath.Join(tmp, "manifest.json"), mb, 0o600); err != nil {
		return BackupResult{}, err
	}
	out := m.h.Path(backupFile(spec.Domain, spec.ID))
	if err := writeTar(out+".part", tmp, append([]string{"manifest.json"}, files...)); err != nil {
		os.Remove(out + ".part")
		return BackupResult{}, err
	}
	if err := os.Rename(out+".part", out); err != nil {
		return BackupResult{}, err
	}
	fi, err := os.Stat(out)
	if err != nil {
		return BackupResult{}, err
	}
	res.Size = fi.Size()
	fmt.Fprintf(log, "✓ %s\n", i18n.T(ctx, "Yedek alındı: %s", humanSize(res.Size)))
	return res, nil
}

// partLabel names a part of a backup in the log.
func partLabel(ctx context.Context, part string) string {
	if l := i18n.T(ctx, map[string]string{"code": "Kod", "database": "Veritabanı"}[part]); l != "" {
		return l
	}
	return part
}

// backupDirs creates /var/lib/serfex/backups/laravel/<domain>, root only.
func (m *Manager) backupDirs(domain string) error {
	t := txn.New(m.h)
	for _, d := range []string{"/var/lib/serfex", "/var/lib/serfex/backups", backupDir, backupDir + "/" + domain} {
		mode := os.FileMode(0o700)
		if d == "/var/lib/serfex" {
			mode = 0o755
		}
		if err := t.RootDir(d, mode, 0, 0); err != nil {
			return err
		}
	}
	return nil
}

// backupPart writes one part into tmp and returns its file name, or why
// it was skipped.
func (m *Manager) backupPart(ctx context.Context, a App, part string, env map[string]string, tmp string, log io.Writer, res *BackupResult) (string, string, error) {
	switch part {
	case "code":
		src := a.dir()
		excludes := []string{"--exclude=./node_modules"}
		if a.Path == CurrentPath {
			if res.Release == "" {
				return "", i18n.T(ctx, "yayında bir sürüm yok"), nil
			}
			src = baseDir(a.Target) + "/releases/" + res.Release
		} else {
			excludes = append(excludes, "--exclude=./storage", "--exclude=./.env")
		}
		fmt.Fprintln(log, "▸ "+i18n.T(ctx, "Kod arşivleniyor"))
		return "code.tar.gz", "", m.userTar(ctx, a.Target, src, excludes, filepath.Join(tmp, "code.tar.gz"))
	case "env":
		if !m.exists(a.envPath()) {
			return "", i18n.T(ctx, ".env dosyası yok"), nil
		}
		b, err := m.readFile(ctx, a.Target, a.envPath(), maxEnv)
		if err != nil {
			return "", "", err
		}
		fmt.Fprintln(log, "▸ "+i18n.T(ctx, ".env kopyalandı"))
		return "env", "", os.WriteFile(filepath.Join(tmp, "env"), b, 0o600)
	case "storage":
		if !m.isDir(a.storagePath()) {
			return "", "storage dizini yok", nil
		}
		fmt.Fprintln(log, "▸ "+i18n.T(ctx, "storage arşivleniyor (önbellek, oturumlar ve günlükler hariç)"))
		return "storage.tar.gz", "", m.userTar(ctx, a.Target, a.storagePath(),
			[]string{"--exclude=./framework/cache", "--exclude=./framework/sessions", "--exclude=./framework/views", "--exclude=./logs"}, filepath.Join(tmp, "storage.tar.gz"))
	}
	db, err := dbConfig(ctx, a, env)
	if err != nil {
		return "", err.Error(), nil
	}
	res.Database = db.Driver
	if db.Driver == "sqlite" {
		if !m.exists(db.Database) {
			return "", i18n.T(ctx, "SQLite dosyası yok"), nil
		}
		fmt.Fprintf(log, "▸ %s\n", i18n.T(ctx, "SQLite veritabanı kopyalanıyor (%s)", strings.TrimPrefix(db.Database, siteDir(a.Target)+"/")))
		return "database.sqlite.gz", "", m.toGzip(ctx, m.cmd(a.Target, "/", nil, "cat", db.Database), "reading the SQLite database", filepath.Join(tmp, "database.sqlite.gz"))
	}
	c, err := m.dumpCommand(ctx, a.Target, db)
	if err != nil {
		return "", err.Error(), nil
	}
	tool := "mysqldump"
	if db.Driver == "pgsql" {
		tool = "pg_dump"
	}
	fmt.Fprintf(log, "▸ %s\n", i18n.T(ctx, "Veritabanı dökümü alınıyor (%s, %s)", db.Driver, db.Database))
	return "database.sql.gz", "", m.toGzip(ctx, c, tool, filepath.Join(tmp, "database.sql.gz"))
}

// userTar archives dir as the site's account into file.
func (m *Manager) userTar(ctx context.Context, t Target, dir string, excludes []string, file string) error {
	f, err := os.OpenFile(file, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o600)
	if err != nil {
		return err
	}
	defer f.Close()
	args := append(append([]string{"-czf", "-"}, excludes...), "-C", dir, ".")
	c := m.cmd(t, "/", nil, "tar", args...)
	c.Stdout = f
	if out, err := m.h.Run(ctx, c); err != nil {
		return fmt.Errorf("tar failed: %s", firstLine(out))
	}
	return f.Close()
}

// toGzip runs c and compresses its output into file.
func (m *Manager) toGzip(ctx context.Context, c host.Command, what, file string) error {
	f, err := os.OpenFile(file, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o600)
	if err != nil {
		return err
	}
	defer f.Close()
	gz := gzip.NewWriter(f)
	c.Stdout = gz
	if out, err := m.h.Run(ctx, c); err != nil {
		return fmt.Errorf("%s failed: %s", what, firstLine(out))
	}
	if err := gz.Close(); err != nil {
		return err
	}
	return f.Close()
}

// DBConfig is the database connection of an application's .env.
type DBConfig struct {
	Driver   string
	Host     string
	Port     string
	Database string
	Username string
	Password string
}

// dbWordRe keeps connection settings from being read as options.
var dbWordRe = regexp.MustCompile(`^[A-Za-z0-9_][A-Za-z0-9_.@:%-]*$`)

func dbConfig(ctx context.Context, a App, env map[string]string) (DBConfig, error) {
	db := DBConfig{Driver: env["DB_CONNECTION"], Host: env["DB_HOST"], Port: env["DB_PORT"], Database: env["DB_DATABASE"], Username: env["DB_USERNAME"], Password: env["DB_PASSWORD"]}
	switch db.Driver {
	case "sqlite":
		switch {
		case db.Database == "":
			db.Database = a.dir() + "/database/database.sqlite"
		case !strings.HasPrefix(db.Database, "/"):
			db.Database = a.dir() + "/" + db.Database
		}
		if strings.Contains(db.Database, "..") || !strings.HasPrefix(db.Database, "/home/"+a.User+"/") {
			return DBConfig{}, errors.New(i18n.T(ctx, "SQLite dosyası sitenin dizininde değil"))
		}
		return db, nil
	case "mysql", "mariadb", "pgsql":
	case "":
		return DBConfig{}, errors.New(i18n.T(ctx, ".env bir veritabanı bağlantısı tanımlamıyor"))
	default:
		return DBConfig{}, errors.New(i18n.T(ctx, "%s veritabanları yedeklenemiyor", db.Driver))
	}
	if db.Host == "" || db.Host == "localhost" {
		db.Host = "127.0.0.1"
	}
	if db.Port == "" {
		db.Port = map[string]string{"pgsql": "5432"}[db.Driver]
		if db.Port == "" {
			db.Port = "3306"
		}
	}
	for _, w := range []string{db.Host, db.Port, db.Database, db.Username} {
		if !dbWordRe.MatchString(w) {
			return DBConfig{}, errors.New(i18n.T(ctx, ".env içindeki veritabanı ayarları okunamadı"))
		}
	}
	return db, nil
}

// dumpCommand is mysqldump or pg_dump for db, run as the site's account.
// The password goes in the environment, never on the command line.
func (m *Manager) dumpCommand(ctx context.Context, t Target, db DBConfig) (host.Command, error) {
	var c host.Command
	switch db.Driver {
	case "pgsql":
		if !m.sim && !m.exists("/usr/bin/pg_dump") {
			return c, errors.New(i18n.T(ctx, "pg_dump kurulu değil (postgresql-client)"))
		}
		c = m.cmd(t, "/", nil, "pg_dump", "--clean", "--if-exists", "--no-owner", "--no-privileges", "--host="+db.Host, "--port="+db.Port, "--username="+db.Username, "--dbname="+db.Database)
		c.Env = []string{"PGPASSWORD=" + db.Password}
	default:
		if !m.sim && !m.exists("/usr/bin/mysqldump") {
			return c, errors.New(i18n.T(ctx, "mysqldump kurulu değil (mysql-client)"))
		}
		c = m.cmd(t, "/", nil, "mysqldump", "--single-transaction", "--quick", "--routines", "--triggers", "--no-tablespaces",
			"--host="+db.Host, "--port="+db.Port, "--user="+db.Username, "--", db.Database)
		c.Env = []string{"MYSQL_PWD=" + db.Password}
	}
	return c, nil
}

// restoreCommand is mysql or psql reading a dump on standard input.
func (m *Manager) restoreCommand(t Target, db DBConfig) (host.Command, error) {
	var c host.Command
	switch db.Driver {
	case "pgsql":
		if !m.sim && !m.exists("/usr/bin/psql") {
			return c, errors.New("psql is not installed (postgresql-client)")
		}
		c = m.cmd(t, "/", nil, "psql", "--quiet", "--set=ON_ERROR_STOP=1", "--host="+db.Host, "--port="+db.Port, "--username="+db.Username, "--dbname="+db.Database)
		c.Env = []string{"PGPASSWORD=" + db.Password}
	default:
		if !m.sim && !m.exists("/usr/bin/mysql") {
			return c, errors.New("mysql is not installed (mysql-client)")
		}
		c = m.cmd(t, "/", nil, "mysql", "--host="+db.Host, "--port="+db.Port, "--user="+db.Username, "--", db.Database)
		c.Env = []string{"MYSQL_PWD=" + db.Password}
	}
	return c, nil
}

// writeTar packs files of dir into an uncompressed tar.
func writeTar(out, dir string, files []string) error {
	f, err := os.OpenFile(out, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o600)
	if err != nil {
		return err
	}
	defer f.Close()
	tw := tar.NewWriter(f)
	for _, name := range files {
		src, err := os.Open(filepath.Join(dir, name))
		if err != nil {
			return err
		}
		fi, err := src.Stat()
		if err != nil {
			src.Close()
			return err
		}
		hdr := &tar.Header{Name: name, Mode: 0o600, Size: fi.Size(), ModTime: fi.ModTime(), Typeflag: tar.TypeReg}
		if err := tw.WriteHeader(hdr); err != nil {
			src.Close()
			return err
		}
		if _, err := io.Copy(tw, src); err != nil {
			src.Close()
			return err
		}
		src.Close()
	}
	if err := tw.Close(); err != nil {
		return err
	}
	return f.Close()
}

// extractTar unpacks the members of a backup into dir. Only plain files
// with the names Serfex writes are taken.
func extractTar(file, dir string) (Manifest, error) {
	var man Manifest
	f, err := os.Open(file)
	if err != nil {
		return man, err
	}
	defer f.Close()
	known := map[string]bool{"manifest.json": true, "code.tar.gz": true, "env": true, "storage.tar.gz": true, "database.sql.gz": true, "database.sqlite.gz": true}
	tr := tar.NewReader(f)
	for {
		hdr, err := tr.Next()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			return man, fmt.Errorf("the backup is damaged: %w", err)
		}
		if hdr.Typeflag != tar.TypeReg || !known[hdr.Name] {
			continue
		}
		out, err := os.OpenFile(filepath.Join(dir, hdr.Name), os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o600)
		if err != nil {
			return man, err
		}
		if _, err := io.Copy(out, tr); err != nil {
			out.Close()
			return man, fmt.Errorf("the backup is damaged: %w", err)
		}
		if err := out.Close(); err != nil {
			return man, err
		}
	}
	b, err := os.ReadFile(filepath.Join(dir, "manifest.json"))
	if err != nil {
		return man, errors.New("the backup has no manifest.json")
	}
	if err := json.Unmarshal(b, &man); err != nil {
		return man, errors.New("the backup's manifest.json is damaged")
	}
	return man, nil
}

// Restore puts the chosen parts of a backup back. The code comes back as a
// release that is switched to like a deploy; storage is unpacked next to
// the live one and swapped in; the database dump is loaded into the
// database the .env names.
func (m *Manager) Restore(ctx context.Context, spec RestoreSpec) (res RestoreResult, err error) {
	if err := spec.App.validate(); err != nil {
		return RestoreResult{}, err
	}
	if err := validateID("backup", spec.ID); err != nil {
		return RestoreResult{}, err
	}
	if err := validParts(spec.Parts); err != nil {
		return RestoreResult{}, err
	}
	if spec.Run != "" {
		if err := validateID("run", spec.Run); err != nil {
			return RestoreResult{}, err
		}
	}
	if contains(spec.Parts, "code") && spec.Path != CurrentPath {
		return RestoreResult{}, invalid("code can only be restored for applications Serfex deploys")
	}
	if !m.exists(backupFile(spec.Domain, spec.ID)) {
		return RestoreResult{}, invalid("backup %s is no longer on the server", spec.ID)
	}
	if err := m.requirePHP(spec.PHPVersion); err != nil {
		return RestoreResult{}, err
	}
	unlock := m.lock(spec.Domain)
	defer unlock()
	ctx, cancel := context.WithTimeout(ctx, backupTimeout)
	defer cancel()
	log, closeLog := m.logger(spec.Domain, spec.Run)
	defer closeLog()
	defer func() {
		if err != nil {
			fmt.Fprintf(log, "✗ %s\n", i18n.T(ctx, "Geri yükleme durdu: %s", err))
			err = &OperationError{err}
		}
	}()

	tmp := m.h.Path(backupDir + "/" + spec.Domain + "/." + spec.ID + ".restore")
	_ = os.RemoveAll(tmp)
	if err := os.Mkdir(tmp, 0o700); err != nil {
		return RestoreResult{}, err
	}
	defer os.RemoveAll(tmp)
	man, err := extractTar(m.h.Path(backupFile(spec.Domain, spec.ID)), tmp)
	if err != nil {
		return RestoreResult{}, err
	}
	has := func(name string) bool {
		_, err := os.Stat(filepath.Join(tmp, name))
		return err == nil
	}
	for _, p := range spec.Parts {
		ok := map[string]bool{"code": has("code.tar.gz"), "env": has("env"), "storage": has("storage.tar.gz"), "database": has("database.sql.gz") || has("database.sqlite.gz")}[p]
		if !ok {
			return RestoreResult{}, &ValidationError{fmt.Sprintf("the backup holds no %s", p)}
		}
	}
	fmt.Fprintf(log, "▸ %s\n", i18n.T(ctx, "Geri yükleme başladı · yedek %s (%s)", spec.ID, man.Created.Format("2006-01-02 15:04")))

	if contains(spec.Parts, "env") {
		b, err := os.ReadFile(filepath.Join(tmp, "env"))
		if err != nil {
			return RestoreResult{}, err
		}
		if err := m.writeFile(ctx, spec.Target, spec.envPath(), b, "600"); err != nil {
			return RestoreResult{}, err
		}
		fmt.Fprintln(log, "▸ "+i18n.T(ctx, ".env geri yüklendi"))
		res.Restored = append(res.Restored, "env")
	}
	if contains(spec.Parts, "storage") {
		if err := m.restoreStorage(ctx, spec.App, filepath.Join(tmp, "storage.tar.gz")); err != nil {
			return RestoreResult{}, err
		}
		fmt.Fprintln(log, "▸ "+i18n.T(ctx, "storage geri yüklendi"))
		res.Restored = append(res.Restored, "storage")
	}
	if contains(spec.Parts, "database") {
		if err := m.restoreDatabase(ctx, spec.App, tmp, log); err != nil {
			return RestoreResult{}, err
		}
		res.Restored = append(res.Restored, "database")
	}
	if contains(spec.Parts, "code") {
		release, err := m.restoreCode(ctx, spec.App, man, spec.ID, filepath.Join(tmp, "code.tar.gz"), log)
		if err != nil {
			return RestoreResult{}, err
		}
		if err := m.activate(ctx, spec.Target, release); err != nil {
			return RestoreResult{}, err
		}
		fmt.Fprintf(log, "▸ current → releases/%s\n", release)
		res.Restored = append(res.Restored, "code")
	}
	if spec.Path == CurrentPath {
		// The configuration cache must match the restored .env and code.
		if out, err := m.userRun(ctx, spec.Target, spec.dir(), phpBin(spec.PHPVersion), "artisan", "optimize"); err != nil {
			fmt.Fprintf(log, "⚠ %s\n", i18n.T(ctx, "php artisan optimize başarısız: %s", firstLine(out)))
		}
	}
	m.afterSwitch(ctx, log, spec.Target)
	res.Current = m.currentRelease(spec.Target)
	fmt.Fprintln(log, "✓ "+i18n.T(ctx, "Geri yükleme bitti."))
	return res, nil
}

// restoreCode makes the backup's release available: still on disk, or
// unpacked again as the site's account.
func (m *Manager) restoreCode(ctx context.Context, a App, man Manifest, id, archive string, log io.Writer) (string, error) {
	release := man.Release
	if release == "" || validateID("release", release) != nil {
		release = "restore-" + id
	}
	dir := baseDir(a.Target) + "/releases/" + release
	if m.isDir(dir) && m.exists(dir+"/artisan") {
		fmt.Fprintf(log, "▸ %s\n", i18n.T(ctx, "releases/%s sunucuda duruyor, yeniden kullanılıyor", release))
		return release, nil
	}
	if m.exists(dir) {
		if _, err := m.userRun(ctx, a.Target, "/", "rm", "-rf", "--one-file-system", dir); err != nil {
			return "", fmt.Errorf("removing %s failed", dir)
		}
	}
	if _, err := m.userRun(ctx, a.Target, "/", "mkdir", "-p", dir); err != nil {
		return "", fmt.Errorf("creating %s failed", dir)
	}
	if err := m.userUntar(ctx, a.Target, archive, dir); err != nil {
		_, _ = m.userRun(context.WithoutCancel(ctx), a.Target, "/", "rm", "-rf", "--one-file-system", dir)
		return "", err
	}
	fmt.Fprintf(log, "▸ %s\n", i18n.T(ctx, "Kod releases/%s dizinine açıldı", release))
	return release, nil
}

// restoreStorage unpacks storage next to the live one and swaps them.
func (m *Manager) restoreStorage(ctx context.Context, a App, archive string) error {
	live := a.storagePath()
	next, old := live+".serfex-restore", live+".serfex-old"
	for _, p := range []string{next, old} {
		if m.exists(p) {
			if _, err := m.userRun(ctx, a.Target, "/", "rm", "-rf", "--one-file-system", p); err != nil {
				return fmt.Errorf("removing %s failed", p)
			}
		}
	}
	if _, err := m.userRun(ctx, a.Target, "/", "mkdir", "-p", next); err != nil {
		return fmt.Errorf("creating %s failed", next)
	}
	if err := m.userUntar(ctx, a.Target, archive, next); err != nil {
		_, _ = m.userRun(context.WithoutCancel(ctx), a.Target, "/", "rm", "-rf", "--one-file-system", next)
		return err
	}
	// The backup left these out.
	if _, err := m.userRun(ctx, a.Target, "/", "mkdir", "-p", next+"/framework/cache/data", next+"/framework/sessions", next+"/framework/views", next+"/logs", next+"/app/public"); err != nil {
		return errors.New("creating the storage directories failed")
	}
	if m.exists(live) {
		if _, err := m.userRun(ctx, a.Target, "/", "mv", "-T", live, old); err != nil {
			return errors.New("moving the live storage aside failed")
		}
	}
	if _, err := m.userRun(ctx, a.Target, "/", "mv", "-T", next, live); err != nil {
		_, _ = m.userRun(context.WithoutCancel(ctx), a.Target, "/", "mv", "-T", old, live)
		return errors.New("putting the restored storage in place failed")
	}
	if m.exists(old) {
		_, _ = m.userRun(ctx, a.Target, "/", "rm", "-rf", "--one-file-system", old)
	}
	return nil
}

// restoreDatabase loads the dump into the database of the current .env.
func (m *Manager) restoreDatabase(ctx context.Context, a App, tmp string, log io.Writer) error {
	db, err := dbConfig(ctx, a, parseEnv(m.envContent(ctx, a.Target, a.envPath())))
	if err != nil {
		return errors.New(err.Error())
	}
	dump := filepath.Join(tmp, "database.sql.gz")
	if db.Driver == "sqlite" {
		dump = filepath.Join(tmp, "database.sqlite.gz")
	}
	f, err := os.Open(dump)
	if err != nil {
		return fmt.Errorf("the backup has no %s database", db.Driver)
	}
	defer f.Close()
	gz, err := gzip.NewReader(f)
	if err != nil {
		return fmt.Errorf("the database dump is damaged: %w", err)
	}
	if db.Driver == "sqlite" {
		next := db.Database + ".serfex-restore"
		c := m.cmd(a.Target, "/", nil, "dd", "of="+next, "status=none")
		c.Input = gz
		if _, err := m.h.Run(ctx, c); err != nil {
			return errors.New("writing the SQLite database failed")
		}
		if _, err := m.userRun(ctx, a.Target, "/", "mv", "-T", next, db.Database); err != nil {
			return errors.New("putting the SQLite database in place failed")
		}
		fmt.Fprintln(log, "▸ "+i18n.T(ctx, "SQLite veritabanı geri yüklendi"))
		return nil
	}
	c, err := m.restoreCommand(a.Target, db)
	if err != nil {
		return err
	}
	c.Input = gz
	fmt.Fprintf(log, "▸ %s\n", i18n.T(ctx, "Veritabanı dökümü yükleniyor (%s, %s)", db.Driver, db.Database))
	if out, err := m.h.Run(ctx, c); err != nil {
		return fmt.Errorf("loading the database dump failed: %s", firstLine(out))
	}
	return nil
}

// userUntar unpacks a .tar.gz into dir as the site's account.
func (m *Manager) userUntar(ctx context.Context, t Target, archive, dir string) error {
	f, err := os.Open(archive)
	if err != nil {
		return err
	}
	defer f.Close()
	c := m.cmd(t, "/", nil, "tar", "-xzf", "-", "--no-same-owner", "-C", dir)
	c.Input = f
	if out, err := m.h.Run(ctx, c); err != nil {
		return fmt.Errorf("unpacking failed: %s", firstLine(out))
	}
	return nil
}

// BackupRef names a backup.
type BackupRef struct {
	Domain string `json:"domain"`
	ID     string `json:"id"`
}

func (r BackupRef) validate() error {
	if err := (Target{Domain: r.Domain, User: "x"}).validate(); err != nil {
		return err
	}
	return validateID("backup", r.ID)
}

// DeleteBackup removes a backup from the server.
func (m *Manager) DeleteBackup(r BackupRef) (map[string]bool, error) {
	if err := r.validate(); err != nil {
		return nil, err
	}
	err := os.Remove(m.h.Path(backupFile(r.Domain, r.ID)))
	if err != nil && !errors.Is(err, os.ErrNotExist) {
		return nil, &OperationError{err}
	}
	return map[string]bool{"deleted": err == nil}, nil
}

// BackupChunk is part of a backup file, for downloads.
type BackupChunk struct {
	Data   []byte `json:"data"`
	Offset int64  `json:"offset"`
	Size   int64  `json:"size"`
}

// ReadBackup returns up to 4 MB of a backup file from offset on.
func (m *Manager) ReadBackup(r BackupRef, offset int64) (BackupChunk, error) {
	if err := r.validate(); err != nil {
		return BackupChunk{}, err
	}
	f, err := os.Open(m.h.Path(backupFile(r.Domain, r.ID)))
	if errors.Is(err, os.ErrNotExist) {
		return BackupChunk{}, invalid("backup %s is no longer on the server", r.ID)
	}
	if err != nil {
		return BackupChunk{}, &OperationError{err}
	}
	defer f.Close()
	fi, err := f.Stat()
	if err != nil {
		return BackupChunk{}, &OperationError{err}
	}
	if offset < 0 || offset > fi.Size() {
		return BackupChunk{}, invalid("offset is outside the file")
	}
	buf := make([]byte, readChunkSize)
	n, err := f.ReadAt(buf, offset)
	if err != nil && !errors.Is(err, io.EOF) {
		return BackupChunk{}, &OperationError{err}
	}
	return BackupChunk{Data: buf[:n], Offset: offset + int64(n), Size: fi.Size()}, nil
}

func humanSize(n int64) string {
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
