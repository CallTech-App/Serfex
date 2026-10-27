// Package files is the file manager of a site: browsing, uploads,
// downloads, the editor, permissions, archives, search and a trash.
//
// Every operation runs in a helper process with the site's own user
// (runuser ... serfex-agent files-helper), never as root or as the
// panel's user, so the kernel's permission checks apply exactly as they
// do to the site's PHP. The helper also keeps every path inside the site
// folder: ".." is refused and symlinks are only followed to places inside
// it.
//
// Layout (for user kahve and site kahve.com):
//
//	/home/kahve/web/kahve.com/            what the file manager shows
//	/home/kahve/.serfex/trash/kahve.com/  deleted and overwritten entries, TrashDays
package files

import (
	"bytes"
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"time"

	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/site"
)

// Spec is a request from the panel: the site (User, Domain) and the
// operation's own fields.
type Spec struct {
	User   string `json:"user"`
	Domain string `json:"domain"`
	Request
}

// Manager runs file operations for sites.
type Manager struct {
	h host.Host
	// Exe is the agent binary started as the helper.
	Exe string
}

// NewManager returns a manager. exe is the agent's own binary.
func NewManager(h host.Host, exe string) *Manager {
	return &Manager{h: h, Exe: exe}
}

// SiteDir is the folder the file manager shows for a site.
func SiteDir(user, domain string) string { return "/home/" + user + "/web/" + domain }

// TrashDir is where a site's deleted entries wait.
func TrashDir(user, domain string) string { return "/home/" + user + "/.serfex/trash/" + domain }

// Do runs one operation (Spec.Op is one of Ops).
func (m *Manager) Do(ctx context.Context, spec Spec) (any, error) {
	if err := site.ValidateUser(spec.User); err != nil {
		return nil, err
	}
	if err := site.ValidateDomain(spec.Domain); err != nil {
		return nil, err
	}
	known := false
	for _, op := range Ops {
		known = known || op == spec.Op
	}
	if !known {
		return nil, fail(CodeInvalid, "", "unknown operation %q", spec.Op)
	}
	u, err := m.h.LookupUser(spec.User)
	if err != nil {
		return nil, fail(CodeNotFound, "", "account %s: %v", spec.User, err)
	}
	if u.UID == 0 || filepath.Clean(u.Home) != "/home/"+spec.User {
		return nil, fail(CodeDenied, "", "account %s is not a site account", spec.User)
	}
	req := spec.Request
	req.Root = SiteDir(spec.User, spec.Domain)
	req.Trash = TrashDir(spec.User, spec.Domain)
	req.Now = time.Now().Unix()
	if _, err := os.Stat(m.h.Path(req.Root)); err != nil {
		return nil, fail(CodeNotFound, "", "the site folder %s is missing", req.Root)
	}
	return m.exec(ctx, spec.User, req)
}

// exec runs the helper as user. The simulator runs it in this process,
// under its root.
func (m *Manager) exec(ctx context.Context, user string, req Request) (any, error) {
	if sim, ok := m.h.(*host.Simulator); ok {
		return Execute(req, sim.Root)
	}
	in, err := json.Marshal(req)
	if err != nil {
		return nil, err
	}
	var out bytes.Buffer
	_, runErr := m.h.Run(ctx, host.AsUser(user, host.Command{Name: m.Exe, Args: []string{"files-helper"}, Stdin: in, Stdout: &out}))
	var res struct {
		Result json.RawMessage `json:"result"`
		Error  *Error          `json:"error"`
	}
	if err := json.Unmarshal(out.Bytes(), &res); err != nil {
		if runErr != nil {
			return nil, fail(CodeOperation, "", "the file helper failed: %v", runErr)
		}
		return nil, fail(CodeOperation, "", "the file helper answered %q", out.String())
	}
	if res.Error != nil {
		return nil, res.Error
	}
	if runErr != nil {
		return nil, fail(CodeOperation, "", "the file helper failed: %v", runErr)
	}
	return res.Result, nil
}
