// Package backup takes server-wide and per-site backups (Yedekler), keeps
// them on local disk and on remote storage (S3-compatible or SFTP), and
// restores them in whole or in part.
//
// A backup is a set of archives taken at one time: one per site (its
// files, databases, mail, certificates, Laravel database and the panel's
// description of the site) and, for a whole-server backup, one for the
// panel itself (its database, its settings and the agent's state), so a
// server can be rebuilt from it.
//
// Layout on the server:
//
//	/var/lib/servik/backups/sets/<key>/<name>.tar   one archive per site ("example.com") or "panel", root 0600
//	/var/lib/servik/backups/sets/<key>/backup.json  what the set holds
//	/var/lib/servik/backups/state/<domain>.<part>.snar  GNU tar snapshot of the last scheduled backup, for incrementals
//	/var/lib/servik/backups/runs/<run>.log          progress of a backup or restore
//	/var/lib/servik/backups/uploads/<id>.part       archives being uploaded from the panel
//	/var/lib/servik/backups/import/                 archives copied to the server by hand, for importing
//	/var/lib/servik/backup-work/<random>/           scratch space of one account during a backup, 0711 above it
//	/etc/servik/backups/ssh/id_ed25519              the key the agent signs in to SFTP targets with
//
// On a target the same set is <prefix>/<key>/<name>.tar (".tar.enc" when
// encrypted) and <prefix>/<key>/backup.json.
//
// Inside a site archive (an uncompressed tar):
//
//	manifest.json                  what is in it
//	site.json                      the panel's description of the site (settings, DNS zone, mailboxes, databases)
//	files.tar.gz                   /home/<user>/web/<domain>, made by the site's account
//	home.tar.gz                    the account's other files (without web/ and tmp/), made by the account
//	databases/<server>-<name>.sql.gz  dumps
//	database-users.json            password hashes of the site's database users
//	mail.tar.gz                    /var/vmail/<domain>, made by vmail
//	certificates.json              the site's certificates and keys
//	laravel-<n>.tar                a Laravel application backup (M6 format) of a database Servik does not manage
//
// Files and mail can be incremental: GNU tar's listed-incremental archives
// that hold what changed since the previous backup of the chain. They are
// restored by unpacking the chain's archives in order.
//
// Customers' files are always read and written by their own account
// (tar through a pipe), never by root, so a symlink a customer plants
// cannot make root read or overwrite anything outside their home.
package backup

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"

	"github.com/winchester37/servik/agent/internal/cert"
	"github.com/winchester37/servik/agent/internal/database"
	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/laravel"
	"github.com/winchester37/servik/agent/internal/txn"
)

const (
	// Dir holds every backup Servik takes.
	Dir        = "/var/lib/servik/backups"
	setsDir    = Dir + "/sets"
	stateDir   = Dir + "/state"
	runsDir    = Dir + "/runs"
	uploadsDir = Dir + "/uploads"
	// ImportDir is where archives copied to the server are imported from.
	ImportDir = Dir + "/import"
	workRoot  = "/var/lib/servik/backup-work"
	keyDir    = "/etc/servik/backups/ssh"
	keyFile   = keyDir + "/id_ed25519"
	vmailDir  = "/var/vmail"
	vmailUser = "vmail"

	// opTimeout bounds one backup or restore.
	opTimeout = 12 * time.Hour
	chunkSize = 4 << 20
	runKeep   = 90 * 24 * time.Hour
)

// ValidationError reports a request that can never succeed as given.
type ValidationError struct{ msg string }

func (e *ValidationError) Error() string { return e.msg }

func invalid(format string, a ...any) error { return &ValidationError{fmt.Sprintf(format, a...)} }

// OperationError reports a valid request that failed on the server.
type OperationError struct{ Err error }

func (e *OperationError) Error() string { return e.Err.Error() }
func (e *OperationError) Unwrap() error { return e.Err }

// ErrBusy is returned while another backup or restore runs.
var ErrBusy = errors.New("another backup or restore is running")

func opError(err error) error {
	var v *ValidationError
	var o *OperationError
	if err == nil || errors.As(err, &v) || errors.As(err, &o) || errors.Is(err, ErrBusy) {
		return err
	}
	return &OperationError{err}
}

var (
	keyRe   = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$`)
	nameRe  = regexp.MustCompile(`^[a-z0-9][a-z0-9.-]{0,252}$`)
	excluRe = regexp.MustCompile(`^[^\x00-\x1f'"\\]{1,200}$`)
)

func validateKey(kind, k string) error {
	if !keyRe.MatchString(k) {
		return invalid("%s %q must be 1-64 letters, digits, - or _", kind, k)
	}
	return nil
}

// validateName checks an archive name: a domain or "panel".
func validateName(n string) error {
	if !nameRe.MatchString(n) || strings.Contains(n, "..") {
		return invalid("archive name %q is not valid", n)
	}
	return nil
}

// CertInstaller installs a certificate from a backup.
type CertInstaller func(ctx context.Context, spec cert.InstallSpec) error

// Manager takes and restores backups. One backup or restore runs at a
// time.
type Manager struct {
	h       host.Host
	db      *database.Manager
	laravel *laravel.Manager
	// InstallCert puts a certificate from a backup back.
	InstallCert CertInstaller
	sim         bool
	slot        chan struct{}
	// systemd tells whether transient scopes (resource weights) work.
	systemd bool
	// newTarget opens a remote target; dry runs keep them on local disk.
	newTarget func(TargetSpec) (Target, error)
	// now is time.Now, replaced in tests.
	now func() time.Time
}

// NewManager returns a Manager on h.
func NewManager(h host.Host, db *database.Manager, l *laravel.Manager) *Manager {
	_, sim := h.(*host.Simulator)
	m := &Manager{h: h, db: db, laravel: l, sim: sim, slot: make(chan struct{}, 1), now: time.Now}
	if !sim {
		_, err := os.Stat("/run/systemd/system")
		m.systemd = err == nil
	}
	m.newTarget = m.openTarget
	db.SetWrap(m.slow)
	return m
}

func (m *Manager) openTarget(t TargetSpec) (Target, error) {
	if err := t.validate(); err != nil {
		return nil, err
	}
	if m.sim {
		root := t.Bucket
		if t.Type == "sftp" {
			root = t.Host
		}
		return dirTarget{dir: m.h.Path("/var/lib/servik-sim/remote/" + t.Type + "/" + root)}, nil
	}
	switch t.Type {
	case "s3":
		return newS3(t, nil)
	default:
		if err := os.MkdirAll(m.h.Path(workRoot+"/sftp"), 0o700); err != nil {
			return nil, err
		}
		return &sftpTarget{h: m.h, spec: t, keyFile: m.h.Path(keyFile), tmpDir: m.h.Path(workRoot + "/sftp")}, nil
	}
}

// target opens t with its prefix applied.
func (m *Manager) target(t TargetSpec) (Target, error) {
	tg, err := m.newTarget(t)
	if err != nil {
		return nil, err
	}
	return prefixed{t: tg, prefix: t.Prefix}, nil
}

// acquire waits for the backup slot.
func (m *Manager) acquire(ctx context.Context) (func(), error) {
	select {
	case m.slot <- struct{}{}:
		return func() { <-m.slot }, nil
	case <-ctx.Done():
		return nil, ErrBusy
	}
}

// Busy reports whether a backup or restore runs now.
func (m *Manager) Busy() bool { return len(m.slot) > 0 }

// slow runs a command at the lowest CPU and disk priority, and in its own
// systemd scope with a small share of CPU and I/O, so a backup never
// starves the sites it reads.
func (m *Manager) slow(c host.Command) host.Command {
	args := []string{"-n", "19", "ionice", "-c", "3", c.Name}
	args = append(args, c.Args...)
	out := host.Command{Name: "nice", Args: args, Stdin: c.Stdin, Env: c.Env, Stream: c.Stream, Stdout: c.Stdout, Input: c.Input}
	if m.systemd {
		out.Args = append([]string{"--scope", "--quiet", "--collect", "-p", "CPUWeight=20", "-p", "IOWeight=20", "--", "nice"}, out.Args...)
		out.Name = "systemd-run"
	}
	return out
}

// dirs creates the backup directories, root only.
func (m *Manager) dirs() error {
	t := txn.New(m.h)
	for _, d := range []struct {
		path string
		mode os.FileMode
	}{
		{"/var/lib/servik", 0o755}, {Dir, 0o700}, {setsDir, 0o700}, {stateDir, 0o700}, {runsDir, 0o700}, {uploadsDir, 0o700}, {ImportDir, 0o700},
		{workRoot, 0o711},
	} {
		if err := t.RootDir(d.path, d.mode, 0, 0); err != nil {
			return err
		}
	}
	return nil
}

func (m *Manager) exists(abs string) bool {
	_, err := os.Lstat(m.h.Path(abs))
	return err == nil
}

func setDir(key string) string            { return setsDir + "/" + key }
func archivePath(key, name string) string { return setDir(key) + "/" + name + ".tar" }

// randomHex returns n random bytes as hex.
func randomHex(n int) string {
	b := make([]byte, n)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}

// logger appends progress to a run log, or discards it.
func (m *Manager) logger(run string) (io.Writer, func()) {
	if run == "" || validateKey("run", run) != nil {
		return io.Discard, func() {}
	}
	if err := m.dirs(); err != nil {
		return io.Discard, func() {}
	}
	m.pruneRuns()
	f, err := os.OpenFile(m.h.Path(runsDir+"/"+run+".log"), os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o600)
	if err != nil {
		return io.Discard, func() {}
	}
	return f, func() { f.Close() }
}

func (m *Manager) pruneRuns() {
	entries, err := os.ReadDir(m.h.Path(runsDir))
	if err != nil {
		return
	}
	for _, e := range entries {
		if fi, err := e.Info(); err == nil && m.now().Sub(fi.ModTime()) > runKeep {
			_ = os.Remove(filepath.Join(m.h.Path(runsDir), e.Name()))
		}
	}
}

// RunChunk is part of a run log.
type RunChunk struct {
	Data   string `json:"data"`
	Offset int64  `json:"offset"`
	Size   int64  `json:"size"`
}

// ReadRun returns up to 256 KB of a run log from offset on.
func (m *Manager) ReadRun(run string, offset int64) (RunChunk, error) {
	if err := validateKey("run", run); err != nil {
		return RunChunk{}, err
	}
	f, err := os.Open(m.h.Path(runsDir + "/" + run + ".log"))
	if errors.Is(err, os.ErrNotExist) {
		return RunChunk{}, nil
	}
	if err != nil {
		return RunChunk{}, opError(err)
	}
	defer f.Close()
	fi, err := f.Stat()
	if err != nil {
		return RunChunk{}, opError(err)
	}
	if offset < 0 || offset > fi.Size() {
		offset = 0
	}
	buf := make([]byte, 256<<10)
	n, err := f.ReadAt(buf, offset)
	if err != nil && !errors.Is(err, io.EOF) {
		return RunChunk{}, opError(err)
	}
	return RunChunk{Data: string(buf[:n]), Offset: offset + int64(n), Size: fi.Size()}, nil
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

func firstLine(s string) string {
	s = strings.TrimSpace(s)
	if i := strings.IndexByte(s, '\n'); i >= 0 {
		s = s[:i]
	}
	if len(s) > 300 {
		s = s[:300] + "…"
	}
	return s
}
