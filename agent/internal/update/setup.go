package update

import (
	"bytes"
	"context"
	"fmt"
	"text/template"

	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/panel"
	"github.com/winchester37/servik/agent/internal/txn"
)

// Setup writes the panel's own units (queue workers and scheduler) and
// serves the panel on servik-web. install.sh runs it once, and every
// update runs the new agent's version of it, so a release brings its
// own layout. Units that changed and run are restarted; starting them is
// left to the caller (they need migrated tables).
func (m *Manager) Setup(ctx context.Context) (SetupResult, error) {
	cfg, ok := panel.Load(m.h)
	if !ok || !panel.Installed(m.h) {
		return SetupResult{}, invalid("the panel is not installed from a release here (%s, %s)", panel.ConfigFile, panel.CurrentLink)
	}
	if _, err := m.h.LookupUser(panel.User); err != nil {
		return SetupResult{}, invalid("the panel's account %s does not exist", panel.User)
	}
	t := txn.New(m.h)
	var changed []string
	for _, u := range panelUnits {
		var b bytes.Buffer
		if err := unitTmpl.Execute(&b, map[string]string{
			"Marker": txn.Marker, "Description": u.description, "PHP": "/usr/bin/php" + cfg.PHP,
			"Artisan": panel.CurrentLink + "/panel/artisan", "Args": u.args, "Dir": panel.CurrentLink + "/panel",
			"User": panel.User, "Stop": u.stop,
		}); err != nil {
			return SetupResult{}, err
		}
		before := t.Changed
		t.Changed = false
		if err := t.WriteFile("/etc/systemd/system/"+u.name+".service", b.Bytes()); err != nil {
			_ = t.Rollback(context.WithoutCancel(ctx))
			return SetupResult{}, opError(err)
		}
		if t.Changed {
			changed = append(changed, u.name)
		}
		t.Changed = t.Changed || before
	}
	if len(changed) > 0 {
		if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"daemon-reload"}}); err != nil {
			_ = t.Rollback(context.WithoutCancel(ctx))
			return SetupResult{}, opError(err)
		}
	}
	if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: append([]string{"enable"}, panel.Units...)}); err != nil {
		return SetupResult{}, opError(err)
	}
	if len(changed) > 0 {
		if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: append([]string{"try-restart"}, changed...)}); err != nil {
			return SetupResult{}, opError(err)
		}
	}
	res := SetupResult{Units: panel.Units, Changed: changed}
	if m.Web != nil {
		web, err := m.Web(ctx)
		if err != nil {
			return SetupResult{}, fmt.Errorf("serving the panel on servik-web: %w", err)
		}
		res.Web = web
	}
	return res, nil
}

// SetupResult says what Setup wrote.
type SetupResult struct {
	Units   []string `json:"units"`
	Changed []string `json:"changed"`
	Web     any      `json:"web,omitempty"`
}

type unitSpec struct{ name, description, args, stop string }

// Long jobs (deploys, installs) get a minute to finish when a worker
// stops; backups have a worker of their own, and updates wait for them.
var panelUnits = []unitSpec{
	{panel.Units[0], "Servik panel queue worker", "queue:work --sleep=1 --tries=1 --max-time=3600", "60"},
	{panel.Units[1], "Servik panel backup worker", "queue:work backups --queue=backups --sleep=3 --tries=1 --max-time=86400", "60"},
	{panel.Units[2], "Servik panel scheduler", "schedule:work", "15"},
}

var unitTmpl = template.Must(template.New("unit").Parse(`# {{.Marker}}. Manual changes are overwritten.
[Unit]
Description={{.Description}}
After=network-online.target postgresql.service servik-agent.service
Wants=network-online.target

[Service]
User={{.User}}
Group={{.User}}
WorkingDirectory={{.Dir}}
ExecStart={{.PHP}} {{.Artisan}} {{.Args}}
Restart=always
RestartSec=3
TimeoutStopSec={{.Stop}}
NoNewPrivileges=true

[Install]
WantedBy=multi-user.target
`))
