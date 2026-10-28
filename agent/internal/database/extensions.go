package database

import (
	"context"
	"errors"
	"fmt"
	"io"
	"os"
	"regexp"
	"strings"

	"github.com/calltech-app/serfex/agent/internal/i18n"
)

// PostgreSQL extensions customers may turn on in their databases.
//
// The ones listed here are not "trusted": only a superuser may create
// them, so a database's owner cannot run CREATE EXTENSION itself. The
// agent creates them as postgres when the panel asks; afterwards the
// database's users use them like any other type (CREATE EXTENSION IF NOT
// EXISTS, as Laravel's ensureVectorExtensionExists runs it, only notes
// that it is there). The packages come with the server, so a major-version
// upgrade finds them in the new version too.

// pgExtension is an extension Serfex offers and the package that ships it.
type pgExtension struct {
	name string
	// pkg is the package for PostgreSQL major version v.
	pkg func(v string) string
}

var pgExtensions = []pgExtension{
	{name: "vector", pkg: func(v string) string { return "postgresql-" + v + "-pgvector" }},
}

func findExtension(name string) (pgExtension, bool) {
	for _, x := range pgExtensions {
		if x.name == name {
			return x, true
		}
	}
	return pgExtension{}, false
}

// controlFile is the extension's control file in PostgreSQL v, which its
// package installs.
func controlFile(v, name string) string {
	return "/usr/share/postgresql/" + v + "/extension/" + name + ".control"
}

var defaultVersionRe = regexp.MustCompile(`(?m)^\s*default_version\s*=\s*'([^']+)'`)

// packagedVersion is the version of the extension's installed package,
// "" when it is not installed.
func (m *Manager) packagedVersion(v, name string) string {
	b, err := os.ReadFile(m.h.Path(controlFile(v, name)))
	if err != nil {
		return ""
	}
	if s := defaultVersionRe.FindSubmatch(b); s != nil {
		return string(s[1])
	}
	return "?"
}

// ensureExtensionPackages installs the packages of the offered extensions
// missing for PostgreSQL v. A failure is only logged: the server works
// without them, and turning one on installs it again.
func (m *Manager) ensureExtensionPackages(ctx context.Context, v string, log io.Writer) {
	var pkgs []string
	for _, x := range pgExtensions {
		if !m.exists(controlFile(v, x.name)) {
			pkgs = append(pkgs, x.pkg(v))
		}
	}
	if len(pkgs) == 0 {
		return
	}
	if err := installPackages(ctx, m.h, pkgs, "", log); err != nil {
		fmt.Fprintln(log, "! "+i18n.T(ctx, "Eklenti paketleri kurulamadı (%s); eklentiyi açınca yeniden denenir: %s", strings.Join(pkgs, " "), lastLine(err.Error())))
	}
}

// errInUse is dropExtension's refusal while objects depend on it.
var errInUse = errors.New("objects depend on the extension")

// pgExtensionEngine is the part of an engine that manages extensions
// (PostgreSQL only).
type pgExtensionEngine interface {
	// extensions maps the extensions created in db to their version.
	extensions(ctx context.Context, db string) (map[string]string, error)
	createExtension(ctx context.Context, db, name string) error
	// dropExtension refuses (errInUse) while objects depend on it.
	dropExtension(ctx context.Context, db, name string) error
	hasDatabase(ctx context.Context, db string) (bool, error)
}

// ExtensionRef names a customer database's extensions.
type ExtensionRef struct {
	Server   string `json:"server"`
	Database string `json:"database"`
}

// ExtensionSpec turns an extension on or off in a database.
type ExtensionSpec struct {
	Server   string `json:"server"`
	Database string `json:"database"`
	Name     string `json:"name"`
	Enabled  bool   `json:"enabled"`
}

// Extension is an offered extension's state in one database.
type Extension struct {
	Name    string `json:"name"`
	Enabled bool   `json:"enabled"`
	// Version is the version created in the database, when enabled.
	Version string `json:"version,omitempty"`
	// Available is the installed package's version, "" without the
	// package (turning it on installs it).
	Available string `json:"available,omitempty"`
}

func (r ExtensionRef) validate() error {
	if err := validateServer(r.Server); err != nil {
		return err
	}
	if r.Server != PostgreSQL {
		return invalid("extensions are offered on PostgreSQL only")
	}
	return ValidateDatabaseName(r.Database)
}

// extensionEngine checks the request and returns PostgreSQL's engine
// with its major version; the caller holds m.mu.
func (m *Manager) extensionEngine(ctx context.Context, ref ExtensionRef) (pgExtensionEngine, string, error) {
	if err := ref.validate(); err != nil {
		return nil, "", err
	}
	e, err := m.engineFor(ref.Server)
	if err != nil {
		return nil, "", err
	}
	x, ok := e.(pgExtensionEngine)
	if !ok {
		return nil, "", invalid("extensions are offered on PostgreSQL only")
	}
	found, err := x.hasDatabase(ctx, ref.Database)
	if err != nil {
		return nil, "", opError(err)
	}
	if !found {
		return nil, "", invalid("database %q does not exist", ref.Database)
	}
	return x, m.pgMainVersion(), nil
}

// Extensions lists the offered extensions and their state in a database.
func (m *Manager) Extensions(ctx context.Context, ref ExtensionRef) ([]Extension, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	e, v, err := m.extensionEngine(ctx, ref)
	if err != nil {
		return nil, err
	}
	created, err := e.extensions(ctx, ref.Database)
	if err != nil {
		return nil, opError(err)
	}
	out := make([]Extension, 0, len(pgExtensions))
	for _, x := range pgExtensions {
		ver, on := created[x.name]
		out = append(out, Extension{Name: x.name, Enabled: on, Version: ver, Available: m.packagedVersion(v, x.name)})
	}
	return out, nil
}

// SetExtension creates an offered extension in a database (installing
// its package when missing) or drops it. Dropping one that tables still
// use is refused; nothing is dropped with it.
func (m *Manager) SetExtension(ctx context.Context, spec ExtensionSpec) (Extension, error) {
	x, ok := findExtension(spec.Name)
	if !ok {
		names := make([]string, len(pgExtensions))
		for i, x := range pgExtensions {
			names[i] = x.name
		}
		return Extension{}, invalid("extension %q is not offered (offered: %s)", spec.Name, strings.Join(names, ", "))
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	ref := ExtensionRef{Server: spec.Server, Database: spec.Database}
	e, v, err := m.extensionEngine(ctx, ref)
	if err != nil {
		return Extension{}, err
	}
	if spec.Enabled {
		if !m.exists(controlFile(v, x.name)) {
			if err := installPackages(ctx, m.h, []string{x.pkg(v)}, "", nil); err != nil {
				return Extension{}, opError(err)
			}
		}
		err = e.createExtension(ctx, spec.Database, x.name)
	} else {
		err = e.dropExtension(ctx, spec.Database, x.name)
	}
	if err != nil {
		if errors.Is(err, errInUse) {
			return Extension{}, invalid("%s is used by columns or indexes in %s; remove them first", x.name, spec.Database)
		}
		return Extension{}, opError(err)
	}
	created, err := e.extensions(ctx, spec.Database)
	if err != nil {
		return Extension{}, opError(err)
	}
	ver, on := created[x.name]
	return Extension{Name: x.name, Enabled: on, Version: ver, Available: m.packagedVersion(v, x.name)}, nil
}

// ExtensionPackages lists the installed packages of the offered
// extensions for the customers' PostgreSQL, which its updates upgrade too.
func (m *Manager) ExtensionPackages() []string {
	v := m.pgMainVersion()
	var pkgs []string
	for _, x := range pgExtensions {
		if v != "" && m.exists(controlFile(v, x.name)) {
			pkgs = append(pkgs, x.pkg(v))
		}
	}
	return pkgs
}
