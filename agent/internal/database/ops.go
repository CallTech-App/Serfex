package database

import (
	"compress/gzip"
	"context"
	"errors"
	"fmt"
	"os"
	"slices"
	"strings"

	"github.com/calltech-app/serfex/agent/internal/hostname"
)

// DatabaseSpec names a database.
type DatabaseSpec struct {
	Server string `json:"server"`
	Name   string `json:"name"`
	// Site is the domain of the site that uses the database, for live
	// monitoring.
	Site string `json:"site"`
}

func (s DatabaseSpec) validate() error {
	if err := validateServer(s.Server); err != nil {
		return err
	}
	if s.Site != "" {
		if err := hostname.Validate(s.Site); err != nil {
			return invalid("%s", err.Error())
		}
	}
	return ValidateDatabaseName(s.Name)
}

// engineFor returns the engine of an installed server.
func (m *Manager) engineFor(server string) (engine, error) {
	if err := validateServer(server); err != nil {
		return nil, err
	}
	if _, ok := m.installedProduct(server); !ok {
		return nil, ErrNotInstalled
	}
	if m.Upgrading() == server {
		return nil, ErrUpgrading
	}
	return m.engines[server], nil
}

// CreateDatabase creates an empty database (UTF-8). Creating one that
// exists changes nothing.
func (m *Manager) CreateDatabase(ctx context.Context, spec DatabaseSpec) (map[string]bool, error) {
	if err := spec.validate(); err != nil {
		return nil, err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	e, err := m.engineFor(spec.Server)
	if err != nil {
		return nil, err
	}
	if err := e.createDatabase(ctx, spec.Name); err != nil {
		return nil, opError(err)
	}
	st, err := m.loadState()
	if err != nil {
		return nil, opError(err)
	}
	st.Databases[key(spec.Server, spec.Name)] = spec.Site
	return map[string]bool{"created": true}, opError(m.saveState(st))
}

// DeleteSpec deletes a database.
type DeleteSpec struct {
	Server string `json:"server"`
	Name   string `json:"name"`
	// Backup keeps a compressed dump in BackupDir first.
	Backup bool `json:"backup"`
}

// DeleteResult tells where the dump went.
type DeleteResult struct {
	Deleted bool   `json:"deleted"`
	Backup  string `json:"backup,omitempty"`
	Size    int64  `json:"backup_size,omitempty"`
}

// DeleteDatabase deletes a database, after dumping it when asked; if the
// dump fails nothing is deleted. Users keep their other grants.
func (m *Manager) DeleteDatabase(ctx context.Context, spec DeleteSpec) (DeleteResult, error) {
	if err := (DatabaseSpec{Server: spec.Server, Name: spec.Name}).validate(); err != nil {
		return DeleteResult{}, err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	e, err := m.engineFor(spec.Server)
	if err != nil {
		return DeleteResult{}, err
	}
	st, err := m.loadState()
	if err != nil {
		return DeleteResult{}, opError(err)
	}
	var res DeleteResult
	if spec.Backup {
		if res.Backup, res.Size, err = m.backup(ctx, e, spec.Server, spec.Name); err != nil {
			return DeleteResult{}, opError(fmt.Errorf("the backup before deleting failed, nothing was deleted: %w", err))
		}
	}
	// Manager sessions on it end first.
	st.Sessions = slices.DeleteFunc(st.Sessions, func(s session) bool {
		if s.Server == spec.Server && s.Database == spec.Name {
			_ = e.dropSession(ctx, s.User, s.Database)
			return true
		}
		return false
	})
	if err := e.dropDatabase(ctx, spec.Name); err != nil {
		return DeleteResult{}, opError(err)
	}
	delete(st.Databases, key(spec.Server, spec.Name))
	var errs []error
	for _, k := range sortedKeys(st.Users) {
		u := st.Users[k]
		server, name, _ := strings.Cut(k, "/")
		if server != spec.Server || !slices.Contains(grantsOn(u.Grants), spec.Name) {
			continue
		}
		prev := u
		u.Grants = slices.DeleteFunc(slices.Clone(u.Grants), func(g Grant) bool { return g.Database == spec.Name })
		if err := e.applyUser(ctx, name, "", u.Hosts, u.Grants, &prev); err != nil {
			errs = append(errs, err)
			continue
		}
		st.Users[k] = u
	}
	if err := m.saveState(st); err != nil {
		errs = append(errs, err)
	}
	res.Deleted = true
	return res, opError(errors.Join(errs...))
}

// backup dumps a database to a new gzip file in BackupDir.
func (m *Manager) backup(ctx context.Context, e engine, server, name string) (string, int64, error) {
	if err := os.MkdirAll(m.h.Path(BackupDir), 0o700); err != nil {
		return "", 0, err
	}
	path := fmt.Sprintf("%s/%s-%s-%s.sql.gz", BackupDir, server, name, m.now().UTC().Format("20060102-150405"))
	f, err := os.OpenFile(m.h.Path(path), os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0o600)
	if err != nil {
		return "", 0, err
	}
	zw := gzip.NewWriter(f)
	err = e.dump(ctx, name, zw, m.wrapped)
	if cerr := zw.Close(); err == nil {
		err = cerr
	}
	if cerr := f.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		_ = os.Remove(m.h.Path(path))
		return "", 0, err
	}
	fi, err := os.Stat(m.h.Path(path))
	if err != nil {
		return "", 0, err
	}
	return path, fi.Size(), nil
}

// UserSpec is a user as the panel keeps it.
type UserSpec struct {
	Server string `json:"server"`
	Name   string `json:"name"`
	// Password is set when not empty; a new user needs one.
	Password string `json:"password"`
	// Hosts are other machines the user may connect from (this server
	// always may).
	Hosts  []string `json:"hosts"`
	Grants []Grant  `json:"grants"`
}

func (s *UserSpec) validate() error {
	if err := validateServer(s.Server); err != nil {
		return err
	}
	if err := ValidateUserName(s.Name); err != nil {
		return err
	}
	if s.Password != "" {
		if err := validatePassword(s.Password); err != nil {
			return err
		}
	}
	if len(s.Hosts) > 20 {
		return invalid("at most 20 remote hosts per user")
	}
	var hosts []string
	for _, h := range s.Hosts {
		if err := ValidateHost(h); err != nil {
			return err
		}
		if !slices.Contains(hosts, h) {
			hosts = append(hosts, h)
		}
	}
	s.Hosts = hosts
	seen := map[string]bool{}
	for _, g := range s.Grants {
		if err := ValidateDatabaseName(g.Database); err != nil {
			return err
		}
		if _, ok := mysqlPrivileges[g.Access]; !ok {
			return invalid("access must be all, readwrite or readonly")
		}
		if seen[g.Database] {
			return invalid("database %s is granted twice", g.Database)
		}
		seen[g.Database] = true
	}
	if s.Grants == nil {
		s.Grants = []Grant{}
	}
	return nil
}

// ApplyUser creates or changes a user to match spec.
func (m *Manager) ApplyUser(ctx context.Context, spec UserSpec) (map[string]bool, error) {
	if err := spec.validate(); err != nil {
		return nil, err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	e, err := m.engineFor(spec.Server)
	if err != nil {
		return nil, err
	}
	st, err := m.loadState()
	if err != nil {
		return nil, opError(err)
	}
	k := key(spec.Server, spec.Name)
	var prev *userState
	if u, ok := st.Users[k]; ok {
		prev = &u
	}
	if err := e.applyUser(ctx, spec.Name, spec.Password, spec.Hosts, spec.Grants, prev); err != nil {
		return nil, opError(err)
	}
	hostsBefore := st.remoteHosts(spec.Server)
	st.Users[k] = userState{Hosts: spec.Hosts, Grants: spec.Grants}
	if err := m.saveState(st); err != nil {
		return nil, opError(err)
	}
	if !slices.Equal(hostsBefore, st.remoteHosts(spec.Server)) {
		if err := m.applyNetwork(ctx, spec.Server, st); err != nil {
			return nil, opError(err)
		}
	}
	return map[string]bool{"applied": true}, nil
}

// UserRef names a user.
type UserRef struct {
	Server string `json:"server"`
	Name   string `json:"name"`
}

// DeleteUser removes a user; a missing user is fine.
func (m *Manager) DeleteUser(ctx context.Context, ref UserRef) (map[string]bool, error) {
	if err := validateServer(ref.Server); err != nil {
		return nil, err
	}
	if err := ValidateUserName(ref.Name); err != nil {
		return nil, err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	e, err := m.engineFor(ref.Server)
	if err != nil {
		return nil, err
	}
	st, err := m.loadState()
	if err != nil {
		return nil, opError(err)
	}
	k := key(ref.Server, ref.Name)
	if err := e.dropUser(ctx, ref.Name, st.Users[k]); err != nil {
		return nil, opError(err)
	}
	hostsBefore := st.remoteHosts(ref.Server)
	delete(st.Users, k)
	if err := m.saveState(st); err != nil {
		return nil, opError(err)
	}
	if !slices.Equal(hostsBefore, st.remoteHosts(ref.Server)) {
		if err := m.applyNetwork(ctx, ref.Server, st); err != nil {
			return nil, opError(err)
		}
	}
	return map[string]bool{"deleted": true}, nil
}

// List returns the customer databases (with sizes) and users of server.
func (m *Manager) List(ctx context.Context, server string) (Listing, error) {
	e, err := m.engineFor(server)
	if err != nil {
		return Listing{}, err
	}
	l, err := e.list(ctx)
	return l, opError(err)
}

// Validate checks the database's server and name.
func (s DatabaseSpec) Validate() error { return s.validate() }
