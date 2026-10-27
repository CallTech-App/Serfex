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
	"strings"
	"time"

	"github.com/calltech-app/serfex/agent/internal/cert"
	"github.com/calltech-app/serfex/agent/internal/database"
	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/i18n"
	"github.com/calltech-app/serfex/agent/internal/laravel"
)

// ChainRef names one archive of a restore chain: where it is kept, and
// the target to fetch it from when it is not on the server.
type ChainRef struct {
	Key    string      `json:"key"`
	Name   string      `json:"name"`
	Target *TargetSpec `json:"target,omitempty"`
}

func (c ChainRef) validate() error {
	if err := validateKey("backup", c.Key); err != nil {
		return err
	}
	if err := validateName(c.Name); err != nil {
		return err
	}
	if c.Target != nil {
		return c.Target.validate()
	}
	return nil
}

// Parts a site restore can put back.
var restoreParts = []string{"files", "home", "databases", "mail", "certificates", "laravel"}

// RestoreSpec puts parts of a site archive back.
type RestoreSpec struct {
	Run string `json:"run"`
	// Chain is the archives to read, oldest first: the full backup and
	// the incrementals up to the one restored (just that one for a full).
	Chain      []ChainRef `json:"chain"`
	Passphrase string     `json:"passphrase,omitempty"`
	Parts      []string   `json:"parts"`
	Site       SiteRef    `json:"site"`
	// Databases are the dumps to load (each replaces the database).
	Databases []DatabaseRef `json:"databases,omitempty"`
	// DatabaseUsers are applied after the databases; a user that does
	// not exist is created with the password stored in the backup.
	DatabaseUsers []database.UserSpec `json:"database_users,omitempty"`
	Laravel       []LaravelSpec       `json:"laravel,omitempty"`
}

// RestoreResult says what was put back.
type RestoreResult struct {
	Restored []string          `json:"restored"`
	Skipped  map[string]string `json:"skipped"`
}

func (s *RestoreSpec) validate() error {
	if s.Run != "" {
		if err := validateKey("run", s.Run); err != nil {
			return err
		}
	}
	if len(s.Chain) == 0 || len(s.Chain) > 400 {
		return invalid("the backup to restore is missing")
	}
	for _, c := range s.Chain {
		if err := c.validate(); err != nil {
			return err
		}
		if c.Name != s.Site.Domain {
			return invalid("every archive of the chain must be the site's")
		}
	}
	if err := s.Site.validate(); err != nil {
		return err
	}
	if len(s.Parts) == 0 {
		return invalid("choose at least one part to restore")
	}
	for _, p := range s.Parts {
		if !slices.Contains(restoreParts, p) {
			return invalid("part %q cannot be restored", p)
		}
	}
	for _, d := range s.Databases {
		if err := (database.DatabaseSpec{Server: d.Server, Name: d.Name}).Validate(); err != nil {
			return err
		}
	}
	for _, l := range s.Laravel {
		if l.Domain != s.Site.Domain || l.User != s.Site.User {
			return invalid("the Laravel application must be the site's")
		}
	}
	return nil
}

// Restore puts the chosen parts of a site archive back. Files and mail
// are unpacked next to the live ones and swapped in, so a failure leaves
// the site as it was.
func (m *Manager) Restore(ctx context.Context, spec RestoreSpec) (res RestoreResult, err error) {
	if err := spec.validate(); err != nil {
		return RestoreResult{}, err
	}
	release, err := m.acquire(ctx)
	if err != nil {
		return RestoreResult{}, err
	}
	defer release()
	ctx, cancel := context.WithTimeout(ctx, opTimeout)
	defer cancel()
	log, closeLog := m.logger(spec.Run)
	defer closeLog()
	defer func() {
		if err != nil {
			fmt.Fprintf(log, "✗ %s\n", i18n.T(ctx, "Geri yükleme durdu: %s", err))
			err = opError(err)
		}
	}()
	if err := m.dirs(); err != nil {
		return RestoreResult{}, err
	}
	last := spec.Chain[len(spec.Chain)-1]
	fmt.Fprintf(log, "▸ %s\n", i18n.T(ctx, "Geri yükleme başladı: %s · yedek %s", spec.Site.Domain, last.Key))
	for _, c := range spec.Chain {
		if err := m.ensureLocal(ctx, c, spec.Passphrase, log); err != nil {
			return RestoreResult{}, err
		}
	}
	tmp := m.h.Path(workRoot + "/restore-" + randomHex(8))
	if err := os.Mkdir(tmp, 0o700); err != nil {
		return RestoreResult{}, err
	}
	defer os.RemoveAll(tmp)
	// The chain's archives, unpacked one directory each.
	dirs := make([]string, len(spec.Chain))
	mans := make([]Manifest, len(spec.Chain))
	for i, c := range spec.Chain {
		dirs[i] = filepath.Join(tmp, fmt.Sprint(i))
		if err := os.Mkdir(dirs[i], 0o700); err != nil {
			return RestoreResult{}, err
		}
		want := func(n string) bool { return n == "manifest.json" || n == "files.tar.gz" || n == "mail.tar.gz" }
		if i == len(spec.Chain)-1 {
			want = knownMember
		}
		man, err := m.unpack(m.h.Path(archivePath(c.Key, c.Name)), dirs[i], want)
		if err != nil {
			return RestoreResult{}, err
		}
		if man.Kind != "site" || man.Name != spec.Site.Domain {
			return RestoreResult{}, invalid("archive %s/%s is not a backup of %s", c.Key, c.Name, spec.Site.Domain)
		}
		mans[i] = man
	}
	top := dirs[len(dirs)-1]
	res.Skipped = map[string]string{}
	has := func(name string) bool { _, err := os.Stat(filepath.Join(top, name)); return err == nil }

	for _, part := range restoreParts {
		if !slices.Contains(spec.Parts, part) {
			continue
		}
		var err error
		switch part {
		case "files":
			err = m.restoreTree(ctx, spec.Site.User, "files", dirs, mans, spec.Site.webDir(), log)
		case "home":
			if !has("home.tar.gz") {
				res.Skipped[part] = "yedekte yok"
				continue
			}
			err = m.untarAs(ctx, spec.Site.User, filepath.Join(top, "home.tar.gz"), spec.Site.home(), false)
			fmt.Fprintln(log, "▸ "+i18n.T(ctx, "Hesabın diğer dosyaları geri yüklendi"))
		case "databases":
			err = m.restoreDatabases(ctx, spec, top, &res, log)
		case "mail":
			if _, lerr := m.h.LookupUser(vmailUser); lerr != nil {
				res.Skipped[part] = i18n.T(ctx, "posta sunucusu kurulu değil")
				continue
			}
			if !has("mail.tar.gz") {
				res.Skipped[part] = "yedekte posta yok"
				continue
			}
			err = m.restoreTree(ctx, vmailUser, "mail", dirs, mans, vmailDir+"/"+spec.Site.Domain, log)
		case "certificates":
			err = m.restoreCertificates(ctx, top, &res, log)
		case "laravel":
			err = m.restoreLaravel(ctx, spec, mans[len(mans)-1], top, &res, log)
		}
		if err != nil {
			return RestoreResult{}, fmt.Errorf("%s: %w", part, err)
		}
		if _, skipped := res.Skipped[part]; !skipped {
			res.Restored = append(res.Restored, part)
		}
	}
	fmt.Fprintln(log, "✓ "+i18n.T(ctx, "Geri yükleme bitti."))
	return res, nil
}

// unpack extracts the wanted members of an archive into dir.
func (m *Manager) unpack(file, dir string, want func(string) bool) (Manifest, error) {
	var man Manifest
	f, err := os.Open(file)
	if err != nil {
		return man, err
	}
	defer f.Close()
	tr := tar.NewReader(f)
	for {
		hdr, err := tr.Next()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			return man, fmt.Errorf("the backup is damaged: %w", err)
		}
		if hdr.Typeflag != tar.TypeReg || !knownMember(hdr.Name) || !want(hdr.Name) {
			continue
		}
		p := filepath.Join(dir, filepath.FromSlash(hdr.Name))
		if err := os.MkdirAll(filepath.Dir(p), 0o700); err != nil {
			return man, err
		}
		out, err := os.OpenFile(p, os.O_CREATE|os.O_WRONLY|os.O_TRUNC|os.O_EXCL, 0o600)
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

// restoreTree unpacks a part (files or mail) of the chain's archives in
// order into a new directory as user, then swaps it with live.
func (m *Manager) restoreTree(ctx context.Context, user, part string, dirs []string, mans []Manifest, live string, log io.Writer) error {
	member := part + ".tar.gz"
	// The newest full archive of the part, and the incrementals after it.
	start := -1
	for i := range dirs {
		if _, err := os.Stat(filepath.Join(dirs[i], member)); err != nil {
			continue
		}
		if !slices.Contains(mans[i].Incremental, part) {
			start = i
		}
	}
	if start < 0 {
		return errors.New("the chain has no full backup of this part")
	}
	parent, base := filepath.Dir(live), filepath.Base(live)
	next, old := parent+"/."+base+".serfex-restore", parent+"/."+base+".serfex-old"
	if !m.isDir(parent) {
		return fmt.Errorf("%s does not exist", parent)
	}
	for _, p := range []string{next, old} {
		if m.exists(p) {
			if _, err := m.h.Run(ctx, host.AsUser(user, host.Command{Name: "rm", Args: []string{"-rf", "--one-file-system", p}})); err != nil {
				return fmt.Errorf("removing %s failed", p)
			}
		}
	}
	if _, err := m.h.Run(ctx, host.AsUser(user, host.Command{Name: "mkdir", Args: []string{"-p", next}})); err != nil {
		return fmt.Errorf("creating %s failed", next)
	}
	cleanup := func() {
		_, _ = m.h.Run(context.WithoutCancel(ctx), host.AsUser(user, host.Command{Name: "rm", Args: []string{"-rf", "--one-file-system", next}}))
	}
	n := 0
	for i := start; i < len(dirs); i++ {
		archive := filepath.Join(dirs[i], member)
		if _, err := os.Stat(archive); err != nil {
			continue
		}
		if err := m.untarAs(ctx, user, archive, next, true); err != nil {
			cleanup()
			return err
		}
		n++
	}
	if m.exists(live) {
		if _, err := m.h.Run(ctx, host.AsUser(user, host.Command{Name: "mv", Args: []string{"-T", live, old}})); err != nil {
			cleanup()
			return errors.New("moving the live files aside failed")
		}
	}
	if _, err := m.h.Run(ctx, host.AsUser(user, host.Command{Name: "mv", Args: []string{"-T", next, live}})); err != nil {
		_, _ = m.h.Run(context.WithoutCancel(ctx), host.AsUser(user, host.Command{Name: "mv", Args: []string{"-T", old, live}}))
		cleanup()
		return errors.New("putting the restored files in place failed")
	}
	if m.exists(old) {
		_, _ = m.h.Run(ctx, host.AsUser(user, host.Command{Name: "rm", Args: []string{"-rf", "--one-file-system", old}}))
	}
	label := i18n.T(ctx, map[string]string{"files": "Dosyalar", "mail": "Posta kutuları"}[part])
	if n > 1 {
		fmt.Fprintf(log, "▸ %s\n", i18n.T(ctx, "%s geri yüklendi (%d arşiv: tam + %d artımlı)", label, n, n-1))
	} else {
		fmt.Fprintf(log, "▸ %s\n", i18n.T(ctx, "%s geri yüklendi", label))
	}
	return nil
}

// untarAs unpacks a .tar.gz into dir as user. Incremental archives apply
// the deletions they record too.
func (m *Manager) untarAs(ctx context.Context, user, archive, dir string, incremental bool) error {
	f, err := os.Open(archive)
	if err != nil {
		return err
	}
	defer f.Close()
	args := []string{"-xzf", "-", "--no-same-owner", "--no-same-permissions", "--delay-directory-restore"}
	if incremental {
		args = append(args, "--listed-incremental=/dev/null")
	}
	args = append(args, "-C", dir)
	c := m.slow(host.AsUser(user, host.Command{Name: "tar", Args: args, Input: f}))
	if out, err := m.h.Run(ctx, c); err != nil {
		return fmt.Errorf("unpacking failed: %s", firstLine(out))
	}
	return nil
}

func (m *Manager) restoreDatabases(ctx context.Context, spec RestoreSpec, top string, res *RestoreResult, log io.Writer) error {
	loaded := 0
	for _, d := range spec.Databases {
		f, err := os.Open(filepath.Join(top, dumpMember(d.Server, d.Name)))
		if err != nil {
			res.Skipped["database:"+d.Name] = i18n.T(ctx, "yedekte bu veritabanı yok")
			continue
		}
		gz, err := gzip.NewReader(f)
		if err != nil {
			f.Close()
			return fmt.Errorf("the dump of %s is damaged", d.Name)
		}
		fmt.Fprintf(log, "▸ %s\n", i18n.T(ctx, "Veritabanı yükleniyor: %s", d.Name))
		err = m.db.Load(ctx, d.Server, d.Name, spec.Site.Domain, gz)
		f.Close()
		if err != nil {
			return err
		}
		loaded++
	}
	var creds []database.UserExport
	if b, err := os.ReadFile(filepath.Join(top, "database-users.json")); err == nil {
		_ = json.Unmarshal(b, &creds)
	}
	for _, u := range spec.DatabaseUsers {
		i := slices.IndexFunc(creds, func(c database.UserExport) bool { return c.Server == u.Server && c.Name == u.Name })
		if i < 0 {
			// Not in the backup: the user keeps its password, if it exists.
			if _, err := m.db.ApplyUser(ctx, database.UserSpec{Server: u.Server, Name: u.Name, Hosts: u.Hosts, Grants: u.Grants}); err != nil {
				res.Skipped["user:"+u.Name] = i18n.T(ctx, "parolası yedekte yok")
			}
			continue
		}
		if err := m.db.RestoreUser(ctx, u, creds[i].Credential); err != nil {
			return fmt.Errorf("user %s: %w", u.Name, err)
		}
		fmt.Fprintf(log, "▸ %s\n", i18n.T(ctx, "Veritabanı kullanıcısı: %s", u.Name))
	}
	if loaded == 0 && len(spec.Databases) > 0 {
		res.Skipped["databases"] = i18n.T(ctx, "yedekte veritabanı yok")
	}
	return nil
}

func (m *Manager) restoreCertificates(ctx context.Context, top string, res *RestoreResult, log io.Writer) error {
	b, err := os.ReadFile(filepath.Join(top, "certificates.json"))
	if err != nil {
		res.Skipped["certificates"] = "yedekte sertifika yok"
		return nil
	}
	var certs []certFile
	if err := json.Unmarshal(b, &certs); err != nil {
		return errors.New("certificates.json is damaged")
	}
	if m.InstallCert == nil {
		res.Skipped["certificates"] = i18n.T(ctx, "sertifikalar geri yüklenemiyor")
		return nil
	}
	n := 0
	for _, c := range certs {
		if err := cert.ValidateName(c.Name); err != nil {
			continue
		}
		if err := m.InstallCert(ctx, cert.InstallSpec{Name: c.Name, Certificate: c.Certificate, PrivateKey: c.PrivateKey}); err != nil {
			res.Skipped["certificate:"+c.Name] = err.Error()
			fmt.Fprintf(log, "  ⚠ %s\n", i18n.T(ctx, "Sertifika %s geri yüklenmedi: %s", c.Name, err))
			continue
		}
		n++
	}
	if n == 0 {
		res.Skipped["certificates"] = i18n.T(ctx, "yedekteki sertifikalar kullanılamıyor (süresi dolmuş olabilir)")
		return nil
	}
	fmt.Fprintf(log, "▸ %s\n", i18n.T(ctx, "%d sertifika geri yüklendi", n))
	return nil
}

func (m *Manager) restoreLaravel(ctx context.Context, spec RestoreSpec, man Manifest, top string, res *RestoreResult, log io.Writer) error {
	n := 0
	for _, app := range spec.Laravel {
		i := slices.Index(man.Laravel, app.Path)
		if i < 0 {
			continue
		}
		member := filepath.Join(top, fmt.Sprintf("laravel-%d.tar", i))
		id := "restore-" + time.Now().UTC().Format("20060102150405")
		dst := m.h.Path(laravel.BackupFile(app.Domain, id))
		if err := os.MkdirAll(filepath.Dir(dst), 0o700); err != nil {
			return err
		}
		if err := os.Rename(member, dst); err != nil {
			return err
		}
		_, err := m.laravel.Restore(ctx, laravel.RestoreSpec{App: app.App, ID: id, Parts: []string{"database"}})
		os.Remove(dst)
		if err != nil {
			return err
		}
		fmt.Fprintf(log, "▸ %s\n", i18n.T(ctx, "Laravel veritabanı geri yüklendi (%s)", strings.TrimSuffix(app.Path, "/current")))
		n++
	}
	if n == 0 {
		res.Skipped["laravel"] = i18n.T(ctx, "yedekte Laravel veritabanı yok")
	}
	return nil
}

// ensureLocal fetches an archive from its target when it is not on the
// server, decrypting it.
func (m *Manager) ensureLocal(ctx context.Context, c ChainRef, passphrase string, log io.Writer) error {
	local := m.h.Path(archivePath(c.Key, c.Name))
	if _, err := os.Stat(local); err == nil {
		return nil
	}
	if c.Target == nil {
		return invalid("backup %s/%s is no longer on the server", c.Key, c.Name)
	}
	t, err := m.target(*c.Target)
	if err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(local), 0o700); err != nil {
		return err
	}
	fmt.Fprintln(log, "▸ "+i18n.T(ctx, "%s uzak hedeften indiriliyor", c.Name))
	dl := local + ".download"
	f, err := os.OpenFile(dl, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o600)
	if err != nil {
		return err
	}
	encrypted := true
	err = t.Get(ctx, c.Key+"/"+c.Name+".tar.enc", f)
	if err != nil && strings.Contains(err.Error(), "is not on the target") {
		encrypted = false
		if _, serr := f.Seek(0, io.SeekStart); serr == nil {
			_ = f.Truncate(0)
		}
		err = t.Get(ctx, c.Key+"/"+c.Name+".tar", f)
	}
	f.Close()
	if err != nil {
		os.Remove(dl)
		return fmt.Errorf("downloading %s failed: %w", c.Name, err)
	}
	if encrypted {
		if passphrase == "" {
			os.Remove(dl)
			return invalid("the backup is encrypted; enter its passphrase")
		}
		if err := decryptFile(dl, local+".part", passphrase); err != nil {
			os.Remove(dl)
			os.Remove(local + ".part")
			return err
		}
		os.Remove(dl)
		return os.Rename(local+".part", local)
	}
	return os.Rename(dl, local)
}

func decryptFile(src, dst, passphrase string) error {
	in, err := os.Open(src)
	if err != nil {
		return err
	}
	defer in.Close()
	r, err := newDecryptReader(in, passphrase)
	if err != nil {
		return err
	}
	out, err := os.OpenFile(dst, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o600)
	if err != nil {
		return err
	}
	defer out.Close()
	if _, err := io.Copy(out, r); err != nil {
		return err
	}
	return out.Close()
}
