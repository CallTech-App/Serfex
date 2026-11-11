package component

import (
	"context"

	"github.com/calltech-app/serfex/agent/internal/database"
)

// webTool is phpMyAdmin or Adminer, the web database manager's tools.
// They come and go with the database servers that need them
// (database.Manager.SetupManager); here they move from Ubuntu's package
// (servers set up before Serfex served the releases) or an older release
// to the release Serfex pins and, for a week after that, back
// (database.Manager.UpgradeTool).
func webTool(id string) *component {
	c := &component{
		id: id,
		installed: func(m *Manager) bool {
			return m.db.Tool(id).Served
		},
		// The release served, else Ubuntu's package (named as the tool).
		version: func(ctx context.Context, m *Manager) string {
			if t := m.db.Tool(id); t.Release != "" {
				return t.Release
			}
			return m.versions(ctx, []string{id})[id]
		},
		install: func(*op) error {
			return invalid("%s is installed with its database server", id)
		},
		update: func(*op) error {
			return invalid("%s moves to a newer release with component.upgrade", id)
		},
		series: &series{
			offer: func(_ context.Context, _ *Manager, version string) *database.UpgradeOffer {
				return database.ToolOffer(id, version)
			},
			kept: func(m *Manager) *database.Kept { return m.db.ToolKept(id) },
			upgrade: func(o *op) error {
				return o.m.db.UpgradeTool(o.ctx, database.ToolSpec{Tool: id, From: o.c.version(o.ctx, o.m), Log: o.w, Fail: o.failpoint})
			},
			revert: func(o *op) error {
				return o.m.db.RevertTool(o.ctx, database.ToolSpec{Tool: id, Log: o.w, Fail: o.failpoint})
			},
			removeOld: func(o *op) error { return o.m.db.RemoveOldTool(o.ctx, id, o.w) },
			// database.Manager.PruneKept lets the kept ones go, also
			// after their database server was removed.
			prune: func(context.Context, *Manager) {},
		},
	}
	if id == database.ToolAdminer {
		c.required = "the web manager of PostgreSQL; it comes and goes with the database server"
		c.release, c.from = database.AdminerRelease.Version, "github.com/vrana/adminer"
	} else {
		c.required = "the web manager of MySQL and MariaDB; it comes and goes with the database server"
		c.release, c.from = database.PhpMyAdminRelease.Version, "files.phpmyadmin.net"
	}
	return c
}
