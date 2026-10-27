package backup

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
	"slices"
	"sort"
	"strings"
	"syscall"
	"time"

	"github.com/calltech-app/serfex/agent/internal/cert"
	"github.com/calltech-app/serfex/agent/internal/database"
	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/i18n"
	"github.com/calltech-app/serfex/agent/internal/laravel"
	"github.com/calltech-app/serfex/agent/internal/site"
)

// SiteRef names a site and the account that owns it.
type SiteRef struct {
	Domain string `json:"domain"`
	User   string `json:"user"`
}

func (s SiteRef) validate() error {
	if err := site.ValidateDomain(s.Domain); err != nil {
		return invalid("%s", err.Error())
	}
	if err := site.ValidateUser(s.User); err != nil {
		return invalid("%s", err.Error())
	}
	return nil
}

func (s SiteRef) home() string   { return "/home/" + s.User }
func (s SiteRef) webDir() string { return s.home() + "/web/" + s.Domain }

// DatabaseRef names a database.
type DatabaseRef struct {
	Server string `json:"server"`
	Name   string `json:"name"`
}

// LaravelSpec is a Laravel application whose database is backed up with
// the application's own backup (one Serfex does not manage, or SQLite).
type LaravelSpec struct {
	laravel.App
}

// ItemSpec is one archive of a backup: a site, or the panel.
type ItemSpec struct {
	// Name is the site's domain, or "panel".
	Name string   `json:"name"`
	Site *SiteRef `json:"site,omitempty"`
	// Home adds the account's files outside web/ (once per account).
	Home          bool               `json:"home,omitempty"`
	Databases     []DatabaseRef      `json:"databases,omitempty"`
	DatabaseUsers []database.UserRef `json:"database_users,omitempty"`
	Mail          bool               `json:"mail,omitempty"`
	Certificates  []string           `json:"certificates,omitempty"`
	Laravel       []LaravelSpec      `json:"laravel,omitempty"`
	// Meta is the panel's description of the site, kept as site.json.
	Meta json.RawMessage `json:"meta,omitempty"`
	// Uploads are files the panel uploaded first (backup.upload.write),
	// by member name: panel.sql.gz and panel.env.
	Uploads map[string]string `json:"uploads,omitempty"`
}

// CreateSpec takes a backup.
type CreateSpec struct {
	Key string `json:"key"`
	Run string `json:"run"`
	// Scope is "server" or "site".
	Scope string `json:"scope"`
	// Server is this server's name, recorded for imports elsewhere.
	Server string `json:"server"`
	// Incremental takes only what changed in files and mail since the
	// previous backup of the chain; Chain makes this backup the chain's
	// newest (scheduled backups do, manual ones do not).
	Incremental bool       `json:"incremental"`
	Chain       bool       `json:"chain"`
	Base        string     `json:"base"`
	Items       []ItemSpec `json:"items"`
	// Target, when set, gets a copy; KeepLocal keeps the local one too.
	Target    *TargetSpec `json:"target,omitempty"`
	KeepLocal bool        `json:"keep_local"`
	// Passphrase encrypts the copy sent to the target.
	Passphrase string `json:"passphrase,omitempty"`
	// Exclude are tar patterns left out of site files.
	Exclude []string `json:"exclude,omitempty"`
}

// ItemResult describes an archive taken.
type ItemResult struct {
	Name  string           `json:"name"`
	Size  int64            `json:"size"`
	Parts map[string]int64 `json:"parts"`
	// Skipped are parts asked for but not taken, with the reason.
	Skipped map[string]string `json:"skipped"`
	// Incremental lists parts that hold only changes.
	Incremental []string `json:"incremental"`
	// Warnings did not stop the archive (files changed while read).
	Warnings []string `json:"warnings"`
	Error    string   `json:"error,omitempty"`
}

// CreateResult describes a backup taken.
type CreateResult struct {
	Key         string       `json:"key"`
	Items       []ItemResult `json:"items"`
	Size        int64        `json:"size"`
	Local       bool         `json:"local"`
	Remote      bool         `json:"remote"`
	Encrypted   bool         `json:"encrypted"`
	RemoteError string       `json:"remote_error,omitempty"`
	Seconds     int          `json:"seconds"`
}

// Manifest is manifest.json.
type Manifest struct {
	Version     int              `json:"version"`
	Kind        string           `json:"kind"`
	Name        string           `json:"name"`
	Key         string           `json:"key"`
	Server      string           `json:"server"`
	Created     time.Time        `json:"created"`
	User        string           `json:"user,omitempty"`
	Base        string           `json:"base,omitempty"`
	Parts       map[string]int64 `json:"parts"`
	Incremental []string         `json:"incremental,omitempty"`
	Databases   []DatabaseRef    `json:"databases,omitempty"`
	Laravel     []string         `json:"laravel,omitempty"`
}

// Descriptor is backup.json: what a backup set holds.
type Descriptor struct {
	Version     int          `json:"version"`
	Key         string       `json:"key"`
	Server      string       `json:"server"`
	Scope       string       `json:"scope"`
	Created     time.Time    `json:"created"`
	Incremental bool         `json:"incremental"`
	Base        string       `json:"base,omitempty"`
	Encrypted   bool         `json:"encrypted"`
	Items       []ItemResult `json:"items"`
}

// Members a site or panel archive may hold.
var memberRe = []string{"manifest.json", "site.json", "files.tar.gz", "home.tar.gz", "database-users.json", "mail.tar.gz", "certificates.json", "panel.sql.gz", "panel.env", "etc-serfex.tar.gz"}

func knownMember(name string) bool {
	if slices.Contains(memberRe, name) {
		return true
	}
	if rest, ok := strings.CutPrefix(name, "databases/"); ok {
		srv, db, ok := strings.Cut(strings.TrimSuffix(rest, ".sql.gz"), "-")
		return ok && strings.HasSuffix(rest, ".sql.gz") && (srv == database.MySQL || srv == database.PostgreSQL) && database.ValidateDatabaseName(db) == nil
	}
	if rest, ok := strings.CutPrefix(name, "laravel-"); ok {
		return strings.HasSuffix(rest, ".tar") && len(rest) <= 8
	}
	return false
}

func dumpMember(server, name string) string { return "databases/" + server + "-" + name + ".sql.gz" }

func (s *CreateSpec) validate() error {
	if err := validateKey("backup", s.Key); err != nil {
		return err
	}
	if s.Run != "" {
		if err := validateKey("run", s.Run); err != nil {
			return err
		}
	}
	if s.Scope != "server" && s.Scope != "site" {
		return invalid("scope must be server or site")
	}
	if s.Base != "" {
		if err := validateKey("base", s.Base); err != nil {
			return err
		}
	}
	if len(s.Items) == 0 {
		return invalid("there is nothing to back up")
	}
	seen := map[string]bool{}
	for _, it := range s.Items {
		if err := it.validate(); err != nil {
			return err
		}
		if seen[it.Name] {
			return invalid("%s is listed twice", it.Name)
		}
		seen[it.Name] = true
	}
	if s.Target != nil {
		if err := s.Target.validate(); err != nil {
			return err
		}
	}
	if len(s.Passphrase) > 0 && len(s.Passphrase) < 12 {
		return invalid("the encryption passphrase must be at least 12 characters")
	}
	for _, e := range s.Exclude {
		if !excluRe.MatchString(e) || strings.HasPrefix(e, "-") {
			return invalid("exclude pattern %q is not valid", e)
		}
	}
	return nil
}

func (it ItemSpec) validate() error {
	if it.Name == "panel" {
		if it.Site != nil {
			return invalid("the panel archive has no site")
		}
		for member, id := range it.Uploads {
			if member != "panel.sql.gz" && member != "panel.env" {
				return invalid("upload %q is not part of a panel backup", member)
			}
			if err := validateKey("upload", id); err != nil {
				return err
			}
		}
		return nil
	}
	if it.Site == nil {
		return invalid("%s: a site archive needs its site", it.Name)
	}
	if err := it.Site.validate(); err != nil {
		return err
	}
	if it.Name != it.Site.Domain {
		return invalid("archive %s must be named after its site", it.Name)
	}
	if len(it.Uploads) > 0 {
		return invalid("uploads belong to the panel archive")
	}
	for _, d := range it.Databases {
		if err := (database.DatabaseSpec{Server: d.Server, Name: d.Name}).Validate(); err != nil {
			return err
		}
	}
	for _, u := range it.DatabaseUsers {
		if u.Server != database.MySQL && u.Server != database.PostgreSQL {
			return invalid("database server must be mysql or postgresql")
		}
		if err := database.ValidateUserName(u.Name); err != nil {
			return err
		}
	}
	for _, c := range it.Certificates {
		if err := cert.ValidateName(c); err != nil {
			return err
		}
	}
	for _, l := range it.Laravel {
		if l.Domain != it.Site.Domain || l.User != it.Site.User {
			return invalid("the Laravel application must be the site's")
		}
	}
	if len(it.Meta) > 4<<20 {
		return invalid("the site description is too big")
	}
	return nil
}

// Create takes a backup and sends it to the target.
func (m *Manager) Create(ctx context.Context, spec CreateSpec) (res CreateResult, err error) {
	if err := spec.validate(); err != nil {
		return CreateResult{}, err
	}
	release, err := m.acquire(ctx)
	if err != nil {
		return CreateResult{}, err
	}
	defer release()
	ctx, cancel := context.WithTimeout(ctx, opTimeout)
	defer cancel()
	started := m.now()
	log, closeLog := m.logger(spec.Run)
	defer closeLog()
	defer func() {
		if err != nil {
			fmt.Fprintf(log, "✗ %s\n", i18n.T(ctx, "Yedek alınamadı: %s", err))
			err = opError(err)
		}
	}()
	if err := m.dirs(); err != nil {
		return CreateResult{}, err
	}
	dir := m.h.Path(setDir(spec.Key))
	if err := os.Mkdir(dir, 0o700); err != nil {
		if errors.Is(err, os.ErrExist) {
			return CreateResult{}, invalid("backup %s exists already", spec.Key)
		}
		return CreateResult{}, err
	}
	kind := i18n.T(ctx, map[bool]string{true: "artımlı", false: "tam"}[spec.Incremental])
	fmt.Fprintf(log, "▸ %s\n", i18n.T(ctx, "Yedek başladı (%s, %d arşiv) · %s", kind, len(spec.Items), started.Format("2006-01-02 15:04")))

	res = CreateResult{Key: spec.Key, Local: true}
	failed := 0
	for _, it := range spec.Items {
		if ctx.Err() != nil {
			return CreateResult{}, errors.New("the backup took too long and was stopped")
		}
		ir := m.item(ctx, spec, it, log)
		if ir.Error != "" {
			failed++
			fmt.Fprintf(log, "✗ %s: %s\n", it.Name, ir.Error)
		} else {
			fmt.Fprintf(log, "✓ %s: %s\n", it.Name, humanSize(ir.Size))
		}
		res.Size += ir.Size
		res.Items = append(res.Items, ir)
	}
	if failed == len(spec.Items) {
		_ = os.RemoveAll(dir)
		return CreateResult{}, fmt.Errorf("no archive could be taken: %s", res.Items[0].Error)
	}
	desc := Descriptor{Version: 1, Key: spec.Key, Server: spec.Server, Scope: spec.Scope, Created: started.UTC(), Incremental: spec.Incremental, Base: spec.Base, Items: res.Items}
	if spec.Target != nil {
		res.Encrypted = spec.Passphrase != ""
		desc.Encrypted = res.Encrypted
		if err := m.upload(ctx, spec, desc, log); err != nil {
			res.RemoteError = err.Error()
			fmt.Fprintf(log, "✗ %s\n", i18n.T(ctx, "Uzak hedefe gönderilemedi, yerel kopya saklandı: %s", err))
		} else {
			res.Remote = true
		}
	}
	if err := writeJSON(filepath.Join(dir, "backup.json"), desc); err != nil {
		return CreateResult{}, err
	}
	if res.Remote && !spec.KeepLocal {
		_ = os.RemoveAll(dir)
		res.Local = false
		fmt.Fprintln(log, "▸ "+i18n.T(ctx, "Yerel kopya silindi (yedek uzak hedefte)"))
	}
	res.Seconds = int(m.now().Sub(started).Seconds())
	fmt.Fprintf(log, "✓ %s\n", i18n.T(ctx, "Yedek tamamlandı: %s, %s", humanSize(res.Size), (time.Duration(res.Seconds)*time.Second).String()))
	return res, nil
}

func writeJSON(file string, v any) error {
	b, err := json.MarshalIndent(v, "", "  ")
	if err != nil {
		return err
	}
	return os.WriteFile(file, b, 0o600)
}

// item takes one archive. Errors are reported in the result, so the other
// archives of a server backup are still taken.
func (m *Manager) item(ctx context.Context, spec CreateSpec, it ItemSpec, log io.Writer) ItemResult {
	ir := ItemResult{Name: it.Name, Parts: map[string]int64{}, Skipped: map[string]string{}, Incremental: []string{}, Warnings: []string{}}
	tmp := m.h.Path(setDir(spec.Key) + "/." + it.Name + ".parts")
	_ = os.RemoveAll(tmp)
	if err := os.MkdirAll(tmp+"/databases", 0o700); err != nil {
		ir.Error = err.Error()
		return ir
	}
	defer os.RemoveAll(tmp)
	man := Manifest{Version: 1, Name: it.Name, Key: spec.Key, Server: spec.Server, Created: m.now().UTC(), Base: spec.Base, Parts: ir.Parts}
	var pending []func() error
	var err error
	if it.Name == "panel" {
		man.Kind = "panel"
		fmt.Fprintln(log, "▸ "+i18n.T(ctx, "Panel yapılandırması"))
		err = m.panelParts(ctx, it, tmp, &ir)
	} else {
		man.Kind = "site"
		man.User = it.Site.User
		fmt.Fprintf(log, "▸ %s\n", it.Name)
		pending, err = m.siteParts(ctx, spec, it, tmp, &ir, &man, log)
	}
	if err != nil {
		ir.Error = err.Error()
		return ir
	}
	man.Incremental = ir.Incremental
	if err := writeJSON(filepath.Join(tmp, "manifest.json"), man); err != nil {
		ir.Error = err.Error()
		return ir
	}
	out := m.h.Path(archivePath(spec.Key, it.Name))
	if err := packDir(out+".part", tmp); err != nil {
		os.Remove(out + ".part")
		ir.Error = err.Error()
		return ir
	}
	if err := os.Rename(out+".part", out); err != nil {
		ir.Error = err.Error()
		return ir
	}
	if fi, err := os.Stat(out); err == nil {
		ir.Size = fi.Size()
	}
	// The chain moves on only once the archive is safe.
	for _, f := range pending {
		if err := f(); err != nil {
			ir.Warnings = append(ir.Warnings, "the incremental state was not saved: "+err.Error())
		}
	}
	return ir
}

// siteParts writes a site's parts into tmp. It returns what to do once
// the archive is written (saving incremental state).
func (m *Manager) siteParts(ctx context.Context, spec CreateSpec, it ItemSpec, tmp string, ir *ItemResult, man *Manifest, log io.Writer) ([]func() error, error) {
	s := *it.Site
	var pending []func() error
	if len(it.Meta) > 0 {
		if !json.Valid(it.Meta) {
			return nil, invalid("the site description is not JSON")
		}
		if err := os.WriteFile(filepath.Join(tmp, "site.json"), it.Meta, 0o600); err != nil {
			return nil, err
		}
	}
	account, err := m.h.LookupUser(s.User)
	if err != nil {
		return nil, fmt.Errorf("account %s: %w", s.User, err)
	}

	// Files.
	if !m.isDir(s.webDir()) {
		ir.Skipped["files"] = "site dizini yok"
	} else {
		excludes := make([]string, 0, len(spec.Exclude))
		for _, e := range spec.Exclude {
			excludes = append(excludes, "--exclude="+e)
		}
		fmt.Fprintln(log, "  "+i18n.T(ctx, "Dosyalar arşivleniyor"))
		commit, incr, warn, err := m.tarAs(ctx, account, "files", s.Domain, spec, s.webDir(), excludes, filepath.Join(tmp, "files.tar.gz"))
		if err != nil {
			return nil, fmt.Errorf("files: %w", err)
		}
		if incr {
			ir.Incremental = append(ir.Incremental, "files")
		}
		if warn != "" {
			ir.Warnings = append(ir.Warnings, warn)
			fmt.Fprintf(log, "  ⚠ %s\n", warn)
		}
		if commit != nil {
			pending = append(pending, commit)
		}
		ir.Parts["files"] = fileSize(filepath.Join(tmp, "files.tar.gz"))
	}
	if it.Home {
		fmt.Fprintln(log, "  "+i18n.T(ctx, "Hesabın diğer dosyaları arşivleniyor"))
		_, _, warn, err := m.tarAs(ctx, account, "", s.Domain, CreateSpec{}, s.home(),
			[]string{"--exclude=./web", "--exclude=./tmp", "--exclude=./.cache"}, filepath.Join(tmp, "home.tar.gz"))
		if err != nil {
			return nil, fmt.Errorf("home: %w", err)
		}
		if warn != "" {
			ir.Warnings = append(ir.Warnings, warn)
		}
		ir.Parts["home"] = fileSize(filepath.Join(tmp, "home.tar.gz"))
	}

	// Databases.
	for _, d := range it.Databases {
		fmt.Fprintf(log, "  %s\n", i18n.T(ctx, "Veritabanı dökümü: %s", d.Name))
		if err := m.dumpDatabase(ctx, d, filepath.Join(tmp, dumpMember(d.Server, d.Name))); err != nil {
			if errors.Is(err, database.ErrNotInstalled) {
				ir.Skipped["database:"+d.Name] = i18n.T(ctx, "veritabanı sunucusu kurulu değil")
				continue
			}
			return nil, fmt.Errorf("database %s: %w", d.Name, err)
		}
		man.Databases = append(man.Databases, d)
		ir.Parts["databases"] += fileSize(filepath.Join(tmp, dumpMember(d.Server, d.Name)))
	}
	if len(it.DatabaseUsers) > 0 {
		users, err := m.db.ExportUsers(ctx, it.DatabaseUsers)
		if err != nil {
			return nil, fmt.Errorf("database users: %w", err)
		}
		if err := writeJSON(filepath.Join(tmp, "database-users.json"), users); err != nil {
			return nil, err
		}
	}

	// Mail.
	if it.Mail {
		dir := vmailDir + "/" + s.Domain
		vmail, err := m.h.LookupUser(vmailUser)
		switch {
		case err != nil || !m.isDir(dir):
			ir.Skipped["mail"] = i18n.T(ctx, "bu alan adında saklanan posta yok")
		default:
			fmt.Fprintln(log, "  "+i18n.T(ctx, "Posta kutuları arşivleniyor"))
			commit, incr, warn, err := m.tarAs(ctx, vmail, "mail", s.Domain, spec, dir, nil, filepath.Join(tmp, "mail.tar.gz"))
			if err != nil {
				return nil, fmt.Errorf("mail: %w", err)
			}
			if incr {
				ir.Incremental = append(ir.Incremental, "mail")
			}
			if warn != "" {
				ir.Warnings = append(ir.Warnings, warn)
			}
			if commit != nil {
				pending = append(pending, commit)
			}
			ir.Parts["mail"] = fileSize(filepath.Join(tmp, "mail.tar.gz"))
		}
	}

	// Certificates.
	if len(it.Certificates) > 0 {
		var certs []certFile
		for _, name := range it.Certificates {
			full, key := cert.Files(name)
			c, err1 := os.ReadFile(m.h.Path(full))
			k, err2 := os.ReadFile(m.h.Path(key))
			if err1 != nil || err2 != nil {
				ir.Skipped["certificate:"+name] = "sertifika sunucuda yok"
				continue
			}
			certs = append(certs, certFile{Name: name, Certificate: string(c), PrivateKey: string(k)})
		}
		if len(certs) > 0 {
			if err := writeJSON(filepath.Join(tmp, "certificates.json"), certs); err != nil {
				return nil, err
			}
			ir.Parts["certificates"] = fileSize(filepath.Join(tmp, "certificates.json"))
		}
	}

	// Laravel databases Serfex does not manage.
	for i, app := range it.Laravel {
		id := "site-" + spec.Key
		if len(id) > 60 {
			id = id[:60]
		}
		fmt.Fprintf(log, "  %s\n", i18n.T(ctx, "Laravel veritabanı yedeği (%s)", app.Path))
		r, err := m.laravel.Backup(ctx, laravel.BackupSpec{App: app.App, ID: id, Parts: []string{"database"}})
		if err != nil {
			ir.Skipped["laravel"] = err.Error()
			continue
		}
		member := fmt.Sprintf("laravel-%d.tar", i)
		if err := os.Rename(m.h.Path(laravel.BackupFile(app.Domain, id)), filepath.Join(tmp, member)); err != nil {
			return nil, err
		}
		man.Laravel = append(man.Laravel, app.Path)
		ir.Parts["laravel"] += r.Size
	}
	return pending, nil
}

type certFile struct {
	Name        string `json:"name"`
	Certificate string `json:"certificate"`
	PrivateKey  string `json:"private_key"`
}

// panelParts writes the panel archive: the panel's database and settings
// (uploaded by the panel) and the agent's state in /etc/serfex.
func (m *Manager) panelParts(ctx context.Context, it ItemSpec, tmp string, ir *ItemResult) error {
	members := make([]string, 0, len(it.Uploads))
	for member := range it.Uploads {
		members = append(members, member)
	}
	sort.Strings(members)
	for _, member := range members {
		src := m.h.Path(uploadsDir + "/" + it.Uploads[member] + ".part")
		if err := os.Rename(src, filepath.Join(tmp, member)); err != nil {
			return fmt.Errorf("%s was not uploaded: %w", member, err)
		}
		ir.Parts[map[string]string{"panel.sql.gz": "database", "panel.env": "settings"}[member]] = fileSize(filepath.Join(tmp, member))
	}
	if m.isDir("/etc/serfex") {
		f, err := os.OpenFile(filepath.Join(tmp, "etc-serfex.tar.gz"), os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o600)
		if err != nil {
			return err
		}
		c := m.slow(host.Command{Name: "tar", Args: []string{"-czf", "-", "--exclude=./backups/known_hosts", "-C", "/etc/serfex", "."}, Stdout: f})
		_, err = m.h.Run(ctx, c)
		f.Close()
		if err != nil {
			return fmt.Errorf("archiving /etc/serfex: %w", err)
		}
		ir.Parts["agent"] = fileSize(filepath.Join(tmp, "etc-serfex.tar.gz"))
	}
	return nil
}

func fileSize(p string) int64 {
	fi, err := os.Stat(p)
	if err != nil {
		return 0
	}
	return fi.Size()
}

func (m *Manager) isDir(abs string) bool {
	fi, err := os.Lstat(m.h.Path(abs))
	return err == nil && fi.IsDir()
}

func (m *Manager) dumpDatabase(ctx context.Context, d DatabaseRef, file string) error {
	f, err := os.OpenFile(file, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o600)
	if err != nil {
		return err
	}
	defer f.Close()
	gz := gzip.NewWriter(f)
	if err := m.db.Dump(ctx, d.Server, d.Name, gz); err != nil {
		return err
	}
	if err := gz.Close(); err != nil {
		return err
	}
	return f.Close()
}

// tarAs archives dir as account into file. With a part name, the archive
// is a listed-incremental one: incremental on the chain's saved state when
// asked for and there is one, a full one otherwise. The returned commit
// saves the new state (only for chained backups). warn is set when tar
// read files that changed meanwhile, which does not fail the backup.
func (m *Manager) tarAs(ctx context.Context, account host.User, part, domain string, spec CreateSpec, dir string, excludes []string, file string) (commit func() error, incremental bool, warn string, err error) {
	f, err := os.OpenFile(file, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o600)
	if err != nil {
		return nil, false, "", err
	}
	defer f.Close()
	args := []string{"-czf", "-", "--one-file-system"}
	var work, snar, state string
	if part != "" {
		work, err = m.workDir(account)
		if err != nil {
			return nil, false, "", err
		}
		defer m.removeWork(context.WithoutCancel(ctx), account, work)
		snar = work + "/" + part + ".snar"
		state = stateDir + "/" + domain + "." + part + ".snar"
		if spec.Incremental {
			if prev, err := os.ReadFile(m.h.Path(state)); err == nil {
				if err := os.WriteFile(m.h.Path(snar), prev, 0o600); err != nil {
					return nil, false, "", err
				}
				if err := m.h.Chown(m.h.Path(snar), account.UID, account.GID); err != nil {
					return nil, false, "", err
				}
				incremental = true
			}
		}
		if err := m.h.Chown(m.h.Path(work), account.UID, account.GID); err != nil {
			return nil, false, "", err
		}
		args = append(args, "--listed-incremental="+snar)
	}
	args = append(append(args, excludes...), "-C", dir, ".")
	c := m.slow(host.AsUser(account.Name, host.Command{Name: "tar", Args: args, Stdout: f}))
	out, err := m.h.Run(ctx, c)
	if err != nil {
		if host.ExitCode(err) != 1 {
			return nil, false, "", fmt.Errorf("tar failed: %s", firstLine(out))
		}
		warn = i18n.T(ctx, "bazı dosyalar okunurken değişti: %s", firstLine(out))
	}
	if err := f.Close(); err != nil {
		return nil, false, "", err
	}
	if part == "" || !spec.Chain {
		return nil, incremental, warn, nil
	}
	data, err := readOwned(m.h.Path(snar), account.UID, 256<<20)
	if err != nil {
		return nil, incremental, warn, err
	}
	return func() error {
		tmp := m.h.Path(state + ".tmp")
		if err := os.WriteFile(tmp, data, 0o600); err != nil {
			return err
		}
		return os.Rename(tmp, m.h.Path(state))
	}, incremental, warn, nil
}

// workDir makes a private scratch directory for account (owned by root
// until handed over with Chown).
func (m *Manager) workDir(account host.User) (string, error) {
	if err := m.dirs(); err != nil {
		return "", err
	}
	dir := workRoot + "/" + randomHex(12)
	if err := os.Mkdir(m.h.Path(dir), 0o700); err != nil {
		return "", err
	}
	return dir, nil
}

// removeWork removes a scratch directory as its account.
func (m *Manager) removeWork(ctx context.Context, account host.User, dir string) {
	_, _ = m.h.Run(ctx, host.AsUser(account.Name, host.Command{Name: "rm", Args: []string{"-rf", "--one-file-system", dir}}))
	_ = os.Remove(m.h.Path(dir))
}

// readOwned reads a file an account wrote, refusing symlinks, hard links
// and files of anyone else.
func readOwned(path string, uid, limit int) ([]byte, error) {
	f, err := os.OpenFile(path, os.O_RDONLY|syscall.O_NOFOLLOW|syscall.O_NONBLOCK, 0)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	fi, err := f.Stat()
	if err != nil {
		return nil, err
	}
	st, ok := fi.Sys().(*syscall.Stat_t)
	if !fi.Mode().IsRegular() || (ok && (st.Nlink != 1 || (int(st.Uid) != uid && st.Uid != uint32(os.Getuid())))) {
		return nil, errors.New("the snapshot file was replaced")
	}
	if fi.Size() > int64(limit) {
		return nil, errors.New("the snapshot file is too big")
	}
	return io.ReadAll(io.LimitReader(f, int64(limit)))
}

// packDir writes the files in dir (one level of subdirectories) into an
// uncompressed tar.
func packDir(out, dir string) error {
	var files []string
	err := filepath.WalkDir(dir, func(p string, e os.DirEntry, err error) error {
		if err != nil {
			return err
		}
		if !e.Type().IsRegular() {
			return nil
		}
		rel, _ := filepath.Rel(dir, p)
		files = append(files, filepath.ToSlash(rel))
		return nil
	})
	if err != nil {
		return err
	}
	// manifest.json first, so a reader finds it at once.
	sort.Slice(files, func(i, j int) bool {
		if (files[i] == "manifest.json") != (files[j] == "manifest.json") {
			return files[i] == "manifest.json"
		}
		return files[i] < files[j]
	})
	f, err := os.OpenFile(out, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o600)
	if err != nil {
		return err
	}
	defer f.Close()
	tw := tar.NewWriter(f)
	for _, name := range files {
		src, err := os.Open(filepath.Join(dir, filepath.FromSlash(name)))
		if err != nil {
			return err
		}
		fi, err := src.Stat()
		if err != nil {
			src.Close()
			return err
		}
		if err := tw.WriteHeader(&tar.Header{Name: name, Mode: 0o600, Size: fi.Size(), ModTime: fi.ModTime(), Typeflag: tar.TypeReg}); err != nil {
			src.Close()
			return err
		}
		_, err = io.Copy(tw, src)
		src.Close()
		if err != nil {
			return err
		}
	}
	if err := tw.Close(); err != nil {
		return err
	}
	return f.Close()
}

// upload sends the set's archives (encrypted when a passphrase is given)
// and backup.json to the target.
func (m *Manager) upload(ctx context.Context, spec CreateSpec, desc Descriptor, log io.Writer) error {
	t, err := m.target(*spec.Target)
	if err != nil {
		return err
	}
	fmt.Fprintf(log, "▸ %s\n", i18n.T(ctx, "Uzak hedefe gönderiliyor: %s%s", spec.Target.address(), map[bool]string{true: " " + i18n.T(ctx, "(AES-256 ile şifreli)"), false: ""}[spec.Passphrase != ""]))
	for _, it := range desc.Items {
		if it.Error != "" {
			continue
		}
		local := m.h.Path(archivePath(spec.Key, it.Name))
		name := spec.Key + "/" + it.Name + ".tar"
		send := local
		if spec.Passphrase != "" {
			send = local + ".enc"
			if err := encryptFile(local, send, spec.Passphrase); err != nil {
				return err
			}
			name += ".enc"
		}
		err := t.Put(ctx, name, send)
		if send != local {
			os.Remove(send)
		}
		if err != nil {
			return fmt.Errorf("%s: %w", it.Name, err)
		}
		fmt.Fprintf(log, "  %s\n", i18n.T(ctx, "%s gönderildi", it.Name))
	}
	descFile := m.h.Path(setDir(spec.Key) + "/backup.json.upload")
	if err := writeJSON(descFile, desc); err != nil {
		return err
	}
	defer os.Remove(descFile)
	return t.Put(ctx, spec.Key+"/backup.json", descFile)
}

func encryptFile(src, dst, passphrase string) error {
	in, err := os.Open(src)
	if err != nil {
		return err
	}
	defer in.Close()
	out, err := os.OpenFile(dst, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o600)
	if err != nil {
		return err
	}
	defer out.Close()
	w, err := newEncryptWriter(out, passphrase)
	if err != nil {
		return err
	}
	if _, err := io.Copy(w, in); err != nil {
		return err
	}
	if err := w.Close(); err != nil {
		return err
	}
	return out.Close()
}
