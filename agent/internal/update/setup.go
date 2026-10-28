package update

import (
	"bytes"
	"context"
	"fmt"
	"os"
	"path/filepath"
	"text/template"

	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/panel"
	"github.com/calltech-app/serfex/agent/internal/txn"
)

// Setup writes the panel's own units (queue workers and scheduler) and
// serves the panel on serfex-web. install.sh runs it once, and every
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
	if err := writeNeedrestart(t); err != nil {
		_ = t.Rollback(context.WithoutCancel(ctx))
		return SetupResult{}, opError(err)
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
	if m.DefaultSite != nil {
		// Not fatal: the sites keep working as before, and the next site
		// change reports the same problem where it can be fixed.
		written, err := m.DefaultSite(ctx)
		res.DefaultSite = written
		if err != nil {
			res.DefaultSiteError = err.Error()
		}
	}
	if m.Web != nil {
		web, err := m.Web(ctx)
		if err != nil {
			return SetupResult{}, fmt.Errorf("serving the panel on serfex-web: %w", err)
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
	// DefaultSite says whether the Nginx default server was (re)written.
	DefaultSite      bool   `json:"default_site,omitempty"`
	DefaultSiteError string `json:"default_site_error,omitempty"`
}

// needrestartConf keeps Ubuntu's needrestart from restarting Serfex's
// own services after a package upgrade. A worker waits on the agent while
// it installs software; the PHP or library upgrade of that very install
// would otherwise get it killed mid-job, and the job's outcome is lost.
// The workers pick up new binaries on their hourly restart (--max-time).
const needrestartConf = "/etc/needrestart/conf.d/serfex.conf"

var needrestartTmpl = "# " + txn.Marker + `. Manual changes are overwritten.
# Serfex restarts its own services itself; a restart in the middle of a
# job (software install, deploy, update) would lose the job's outcome.
$nrconf{override_rc}->{qr(^serfex-agent\b)} = 0;
$nrconf{override_rc}->{qr(^serfex-panel-)} = 0;
$nrconf{override_rc}->{qr(^serfex-update-)} = 0;
`

// writeNeedrestart writes needrestartConf where needrestart is installed.
func writeNeedrestart(t *txn.Tx) error {
	if _, err := os.Stat(t.H.Path("/etc/needrestart")); err != nil {
		return nil
	}
	if err := os.MkdirAll(t.H.Path(filepath.Dir(needrestartConf)), 0o755); err != nil {
		return err
	}
	return t.WriteFile(needrestartConf, []byte(needrestartTmpl))
}

type unitSpec struct{ name, description, args, stop string }

// Long jobs (deploys, installs) get a minute to finish when a worker
// stops; backups have a worker of their own, and updates wait for them.
var panelUnits = []unitSpec{
	{panel.Units[0], "Serfex panel queue worker", "queue:work --sleep=1 --tries=1 --max-time=3600", "60"},
	{panel.Units[1], "Serfex panel backup worker", "queue:work backups --queue=backups --sleep=3 --tries=1 --max-time=86400", "60"},
	{panel.Units[2], "Serfex panel scheduler", "schedule:work", "15"},
}

var unitTmpl = template.Must(template.New("unit").Parse(`# {{.Marker}}. Manual changes are overwritten.
[Unit]
Description={{.Description}}
After=network-online.target postgresql.service serfex-agent.service
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
