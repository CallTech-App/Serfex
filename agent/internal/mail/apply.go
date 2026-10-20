package mail

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

	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/txn"
)

// DKIM describes a domain's signing key.
type DKIM struct {
	Selector string `json:"selector"`
	// Name is the TXT record's name inside the zone.
	Name string `json:"name"`
	// Record is the TXT record content.
	Record string `json:"record"`
}

// DomainResult describes an applied domain.
type DomainResult struct {
	Domain  string `json:"domain"`
	Changed bool   `json:"changed"`
	DKIM    DKIM   `json:"dkim"`
}

// ApplyDomain stores a mail domain as given and makes the services follow:
// new mailboxes can sign in and receive mail, removed ones stop (their
// mail stays until mail.mailbox.purge), forwarders and autoreplies change.
// A DKIM key is created the first time.
func (m *Manager) ApplyDomain(ctx context.Context, spec DomainSpec) (res DomainResult, err error) {
	if err := spec.normalize(); err != nil {
		return DomainResult{}, err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	if _, err := m.loadServer(); err != nil {
		return DomainResult{}, err
	}

	t := txn.New(m.h)
	defer m.finish(ctx, t, &err)

	rspamd, err := m.h.LookupUser("_rspamd")
	if err != nil {
		return DomainResult{}, opError(fmt.Errorf("_rspamd account: %w", err))
	}
	key, err := os.ReadFile(m.h.Path(dkimKey(spec.Domain)))
	if errors.Is(err, os.ErrNotExist) {
		if key, err = newDKIMKey(); err == nil {
			err = t.WriteOwned(dkimKey(spec.Domain), key, 0o640, 0, rspamd.GID)
		}
	}
	if err != nil {
		return DomainResult{}, err
	}
	record, err := dkimRecord(key)
	if err != nil {
		return DomainResult{}, err
	}
	res = DomainResult{Domain: spec.Domain, DKIM: DKIM{Selector: DKIMSelector, Name: DKIMSelector + "._domainkey", Record: record}}

	data, err := json.MarshalIndent(spec, "", "  ")
	if err != nil {
		return DomainResult{}, err
	}
	if err := t.WriteOwned(domainFile(spec.Domain), data, 0o600, 0, 0); err != nil {
		return DomainResult{}, err
	}
	if err := m.rebuild(ctx, t); err != nil {
		return DomainResult{}, err
	}
	res.Changed = t.Changed
	return res, nil
}

// DeleteResult describes a removed domain or mailbox.
type DeleteResult struct {
	Deleted bool `json:"deleted"`
	// FilesRemoved is true when stored mail was deleted.
	FilesRemoved bool `json:"files_removed"`
}

// DeleteDomain stops serving a mail domain. Its mail and DKIM key stay
// unless removeFiles is set, so turning mail back on keeps both.
// Deleting a missing domain succeeds, so the panel can retry.
func (m *Manager) DeleteDomain(ctx context.Context, domain string, removeFiles bool) (res DeleteResult, err error) {
	if err := validateDomain(domain); err != nil {
		return DeleteResult{}, err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	if _, err := m.loadServer(); err != nil {
		return DeleteResult{}, err
	}

	t := txn.New(m.h)
	defer m.finish(ctx, t, &err)

	if m.exists(domainFile(domain)) {
		p := m.h.Path(domainFile(domain))
		old, err := os.ReadFile(p)
		if err != nil {
			return DeleteResult{}, err
		}
		if err := os.Remove(p); err != nil {
			return DeleteResult{}, err
		}
		t.Changed = true
		t.OnUndo(func(context.Context) error { return os.WriteFile(p, old, 0o600) })
		res.Deleted = true
		if err := m.rebuild(ctx, t); err != nil {
			return DeleteResult{}, err
		}
	}
	if removeFiles {
		// Nothing below can be undone, so it runs after the services
		// stopped using the domain.
		if err := m.removeMail(ctx, VmailDir+"/"+domain); err != nil {
			return DeleteResult{}, err
		}
		if err := os.Remove(m.h.Path(dkimKey(domain))); err != nil && !errors.Is(err, os.ErrNotExist) {
			return DeleteResult{}, err
		}
		res.FilesRemoved = true
	}
	return res, nil
}

// PurgeMailbox deletes the stored mail of a mailbox that the domain no
// longer has. A mailbox that still exists is left alone.
func (m *Manager) PurgeMailbox(ctx context.Context, domain, name string) (DeleteResult, error) {
	if err := validateDomain(domain); err != nil {
		return DeleteResult{}, err
	}
	if err := validateLocal(name); err != nil {
		return DeleteResult{}, err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	d, err := m.loadDomain(domain)
	if err != nil {
		return DeleteResult{}, opError(err)
	}
	if d != nil && d.mailbox(name) != nil {
		return DeleteResult{}, nil
	}
	dir := VmailDir + "/" + domain + "/" + name
	if !m.exists(dir) {
		return DeleteResult{}, nil
	}
	if err := m.removeMail(ctx, dir); err != nil {
		return DeleteResult{}, opError(err)
	}
	return DeleteResult{Deleted: true, FilesRemoved: true}, nil
}

// removeMail deletes a directory under VmailDir as the vmail account.
func (m *Manager) removeMail(ctx context.Context, dir string) error {
	if !strings.HasPrefix(dir, VmailDir+"/") || strings.Contains(dir, "..") {
		return fmt.Errorf("refusing to remove %s", dir)
	}
	if !m.exists(dir) {
		return nil
	}
	_, err := m.h.Run(ctx, host.AsUser(VmailUser, host.Command{Name: "rm", Args: []string{"-rf", "--one-file-system", dir}}))
	return err
}

// Usage is the space each mailbox of a domain takes, in bytes.
type Usage struct {
	Domain    string           `json:"domain"`
	Mailboxes map[string]int64 `json:"mailboxes"`
}

// Usage adds up the stored mail of each mailbox of domain.
func (m *Manager) Usage(domain string) (Usage, error) {
	if err := validateDomain(domain); err != nil {
		return Usage{}, err
	}
	d, err := m.loadDomain(domain)
	if err != nil {
		return Usage{}, opError(err)
	}
	out := Usage{Domain: domain, Mailboxes: map[string]int64{}}
	if d == nil {
		return out, nil
	}
	for _, b := range d.Mailboxes {
		var size int64
		root := m.h.Path(VmailDir + "/" + domain + "/" + b.Name + "/Maildir")
		_ = filepath.WalkDir(root, func(_ string, e fs.DirEntry, err error) error {
			if err != nil || e.IsDir() {
				return nil
			}
			// Dovecot's own index and cache files are not mail.
			if strings.HasPrefix(e.Name(), "dovecot") {
				return nil
			}
			if fi, err := e.Info(); err == nil && fi.Mode().IsRegular() {
				size += fi.Size()
			}
			return nil
		})
		out.Mailboxes[b.Name] = size
	}
	return out, nil
}

// finish rolls the transaction back on error and brings the services back
// to the old files.
func (m *Manager) finish(ctx context.Context, t *txn.Tx, err *error) {
	if *err == nil {
		return
	}
	bg := context.WithoutCancel(ctx)
	if rbErr := t.Rollback(bg); rbErr != nil {
		*err = fmt.Errorf("%w (rollback also failed: %v)", *err, rbErr)
	}
	for _, u := range []string{"postfix", "rspamd", "dovecot"} {
		_, _ = m.h.Run(bg, host.Command{Name: "systemctl", Args: []string{"reload", u}})
	}
	*err = opError(*err)
}

// rebuild rewrites the maps from the stored domains and reloads what
// changed.
func (m *Manager) rebuild(ctx context.Context, t *txn.Tx) error {
	dovecot, err := m.h.LookupUser("dovecot")
	if err != nil {
		return fmt.Errorf("dovecot account: %w", err)
	}
	domains, err := m.loadDomains()
	if err != nil {
		return err
	}
	built := buildMaps(domains)
	changed, err := m.writeMaps(t, built, dovecot.GID)
	if err != nil {
		return err
	}
	if changed["sieve"] {
		paths := make([]string, 0, len(built.sieve))
		for p := range built.sieve {
			paths = append(paths, p)
		}
		sort.Strings(paths)
		for _, p := range paths {
			if err := m.compileSieve(ctx, p); err != nil {
				return err
			}
		}
	}
	if changed["postfix"] {
		if _, err := m.h.Run(ctx, host.Command{Name: "postfix", Args: []string{"check"}}); err != nil {
			return fmt.Errorf("postfix rejected the maps: %w", err)
		}
		if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"reload", "postfix"}}); err != nil {
			return err
		}
	}
	if changed["rspamd"] {
		if _, err := m.h.Run(ctx, host.Command{Name: "rspamadm", Args: []string{"configtest"}}); err != nil {
			return fmt.Errorf("rspamd rejected the DKIM settings: %w", err)
		}
		if err := m.reloadRspamd(ctx); err != nil {
			return err
		}
	}
	if changed["dovecot"] {
		// Dovecot would notice the users file by itself, but not within the
		// same second, and its cache keeps old passwords for an hour. A
		// reload keeps open connections.
		if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"reload", "dovecot"}}); err != nil {
			return err
		}
	}
	return nil
}

// changes runs write with t.Changed cleared and reports whether it
// changed anything.
func changes(t *txn.Tx, write func() error) (bool, error) {
	before := t.Changed
	t.Changed = false
	err := write()
	changed := t.Changed
	t.Changed = changed || before
	return changed, err
}

// writeMaps writes the maps and reports which services' files changed:
// "postfix", "dovecot", "rspamd" and "sieve".
func (m *Manager) writeMaps(t *txn.Tx, built maps, dovecotGID int) (map[string]bool, error) {
	out := map[string]bool{}
	var err error
	if out["postfix"], err = changes(t, func() error {
		for _, name := range []string{"domains", "mailboxes", "aliases", "senders", "suspended"} {
			if err := t.WriteFile(postfixMaps+"/"+name, built.postfix[name]); err != nil {
				return err
			}
		}
		return nil
	}); err != nil {
		return nil, err
	}
	if out["dovecot"], err = changes(t, func() error {
		return t.WriteFileMode(dovecotUsers, built.users, 0o640, 0, dovecotGID)
	}); err != nil {
		return nil, err
	}
	if out["rspamd"], err = changes(t, func() error {
		return t.WriteFile(rspamdLocal+"/dkim_signing.conf", built.dkim)
	}); err != nil {
		return nil, err
	}
	if out["sieve"], err = changes(t, func() error { return m.writeSieve(t, built.sieve) }); err != nil {
		return nil, err
	}
	out["dovecot"] = out["dovecot"] || out["sieve"]
	return out, nil
}

// writeSieve writes the autoreply scripts and removes the ones no mailbox
// uses any more, with their compiled copies.
func (m *Manager) writeSieve(t *txn.Tx, scripts map[string][]byte) error {
	for path, script := range scripts {
		if err := t.RootDir(filepath.Dir(path), 0o755, 0, 0); err != nil {
			return err
		}
		if err := t.WriteFile(path, script); err != nil {
			return err
		}
	}
	root := m.h.Path(sieveDir)
	return filepath.WalkDir(root, func(p string, e fs.DirEntry, err error) error {
		if err != nil || e.IsDir() {
			return nil
		}
		abs := sieveDir + strings.TrimPrefix(p, root)
		script := abs
		if base, ok := strings.CutSuffix(abs, ".svbin"); ok {
			script = base + ".sieve"
		}
		if script == spamSieve || scripts[script] != nil {
			return nil
		}
		if strings.HasSuffix(abs, ".svbin") {
			return os.Remove(p)
		}
		if strings.HasSuffix(abs, ".sieve") {
			return t.RemoveFile(abs)
		}
		return nil
	})
}
