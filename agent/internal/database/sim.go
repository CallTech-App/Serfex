package database

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"hash/fnv"
	"io"
	"os"
	"path/filepath"
	"slices"
	"sort"
	"time"

	"github.com/winchester37/servik/agent/internal/host"
)

// simEngine stands in for a database server in dry runs: databases and
// users live in a JSON file under the host's root, and sizes are made up
// (but stable) so the panel has something to show.
type simEngine struct {
	h      host.Host
	server string
}

type simData struct {
	Databases []string            `json:"databases"`
	Users     map[string][]string `json:"users"`
	Sessions  []string            `json:"sessions"`
}

func (e *simEngine) file() string {
	return e.h.Path("/var/lib/servik-sim/databases-" + e.server + ".json")
}

func (e *simEngine) load() (*simData, error) {
	d := &simData{Users: map[string][]string{}}
	data, err := os.ReadFile(e.file())
	if errors.Is(err, os.ErrNotExist) {
		return d, nil
	}
	if err != nil {
		return nil, err
	}
	err = json.Unmarshal(data, d)
	if d.Users == nil {
		d.Users = map[string][]string{}
	}
	return d, err
}

func (e *simEngine) save(d *simData) error {
	if err := os.MkdirAll(filepath.Dir(e.file()), 0o755); err != nil {
		return err
	}
	data, _ := json.MarshalIndent(d, "", "  ")
	return os.WriteFile(e.file(), data, 0o600)
}

func (e *simEngine) version(context.Context) (string, error) {
	if e.server == PostgreSQL {
		return "16.10", nil
	}
	return "8.0.46", nil
}

func (e *simEngine) createDatabase(_ context.Context, name string) error {
	d, err := e.load()
	if err != nil {
		return err
	}
	if !slices.Contains(d.Databases, name) {
		d.Databases = append(d.Databases, name)
	}
	return e.save(d)
}

func (e *simEngine) dropDatabase(_ context.Context, name string) error {
	d, err := e.load()
	if err != nil {
		return err
	}
	d.Databases = slices.DeleteFunc(d.Databases, func(n string) bool { return n == name })
	return e.save(d)
}

func (e *simEngine) dump(_ context.Context, name string, w io.Writer, _ func(host.Command) host.Command) error {
	_, err := fmt.Fprintf(w, "-- Servik dry run: dump of %s\n", name)
	return err
}

func (e *simEngine) applyUser(_ context.Context, name, password string, hosts []string, grants []Grant, _ *userState) error {
	d, err := e.load()
	if err != nil {
		return err
	}
	if _, ok := d.Users[name]; !ok && password == "" {
		return invalid("a password is required for the new user %s", name)
	}
	for _, g := range grants {
		if !slices.Contains(d.Databases, g.Database) {
			return invalid("database %s does not exist", g.Database)
		}
	}
	d.Users[name] = append([]string{}, hosts...)
	return e.save(d)
}

func (e *simEngine) dropUser(_ context.Context, name string, _ userState) error {
	d, err := e.load()
	if err != nil {
		return err
	}
	delete(d.Users, name)
	return e.save(d)
}

// simSize makes up a size between 1 and 3200 MB for a database.
func simSize(name string) int64 {
	f := fnv.New32a()
	_, _ = f.Write([]byte(name))
	return int64(1+f.Sum32()%3200) << 20
}

func (e *simEngine) list(context.Context) (Listing, error) {
	d, err := e.load()
	if err != nil {
		return Listing{}, err
	}
	l := Listing{Databases: []DatabaseInfo{}, Users: []UserInfo{}}
	for _, n := range d.Databases {
		l.Databases = append(l.Databases, DatabaseInfo{Name: n, Size: simSize(n), Tables: int(simSize(n)>>20) % 60})
	}
	for n, hosts := range d.Users {
		l.Users = append(l.Users, UserInfo{Name: n, Hosts: hosts})
	}
	sort.Slice(l.Users, func(i, j int) bool { return l.Users[i].Name < l.Users[j].Name })
	return l, nil
}

func (e *simEngine) createSession(_ context.Context, user, _, database string, _ time.Time) error {
	d, err := e.load()
	if err != nil {
		return err
	}
	if !slices.Contains(d.Databases, database) {
		return invalid("database %s does not exist", database)
	}
	d.Sessions = append(d.Sessions, user)
	return e.save(d)
}

func (e *simEngine) dropSession(_ context.Context, user, _ string) error {
	d, err := e.load()
	if err != nil {
		return err
	}
	d.Sessions = slices.DeleteFunc(d.Sessions, func(n string) bool { return n == user })
	return e.save(d)
}
