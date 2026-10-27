package laravel

import (
	"bytes"
	"context"
	"os"
	"regexp"
	"strings"
	"text/template"
	"time"

	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/txn"
)

// The scheduler: a systemd timer runs php artisan schedule:run every
// minute as the site's account. Its output is appended to a root-owned
// log, from which the last run of each task is read.

var scheduleService = template.Must(template.New("service").Parse(`# {{.Marker}}. Manual changes are overwritten.
[Unit]
Description=Laravel scheduler of {{.Domain}}

[Service]
Type=oneshot
User={{.User}}
Group={{.User}}
WorkingDirectory={{.Dir}}
ExecStart={{.PHP}} artisan schedule:run
Environment=HOME={{.Home}}
StandardOutput=append:{{.Log}}
StandardError=inherit
Nice=5
NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=full
`))

var scheduleTimer = template.Must(template.New("timer").Parse(`# {{.Marker}}. Manual changes are overwritten.
[Unit]
Description=Run the Laravel scheduler of {{.Domain}} every minute

[Timer]
OnCalendar=*-*-* *:*:00
AccuracySec=1s
Unit={{.Unit}}.service

[Install]
WantedBy=timers.target
`))

func scheduleLog(domain string) string { return logDir + "/" + domain + "/schedule.log" }

// ScheduleSpec turns the scheduler of an application on or off.
type ScheduleSpec struct {
	App
	Enabled bool `json:"enabled"`
}

// ScheduleState is how the scheduler is doing.
type ScheduleState struct {
	Enabled bool `json:"enabled"`
	// LastRun is when schedule:run last wrote its log, nil if never.
	LastRun *time.Time `json:"last_run"`
	// Tasks are the last results per command, read from the log.
	Tasks []TaskRun `json:"tasks"`
}

// TaskRun is the last run of one scheduled command.
type TaskRun struct {
	Command  string `json:"command"`
	At       string `json:"at"`
	Duration string `json:"duration"`
	OK       bool   `json:"ok"`
}

// ApplySchedule writes the scheduler's units and starts or stops its timer.
func (m *Manager) ApplySchedule(ctx context.Context, spec ScheduleSpec) (st ScheduleState, err error) {
	if err := spec.App.validate(); err != nil {
		return ScheduleState{}, err
	}
	if spec.Enabled {
		if err := m.requireApp(spec.App); err != nil {
			return ScheduleState{}, err
		}
	}
	unlock := m.lock(spec.Domain)
	defer unlock()
	t := txn.New(m.h)
	defer func() {
		if err != nil {
			_ = t.Rollback(context.WithoutCancel(ctx))
			err = &OperationError{err}
		}
	}()
	unit := scheduleUnit(spec.Domain)
	if spec.Enabled {
		if err := m.logDirs(t, spec.Domain); err != nil {
			return ScheduleState{}, err
		}
		data := map[string]string{
			"Marker": txn.Marker, "Domain": spec.Domain, "User": spec.User, "Home": "/home/" + spec.User,
			"Dir": spec.dir(), "PHP": phpBin(spec.PHPVersion), "Log": scheduleLog(spec.Domain), "Unit": unit,
		}
		for file, tmpl := range map[string]*template.Template{unit + ".service": scheduleService, unit + ".timer": scheduleTimer} {
			var b bytes.Buffer
			if err := tmpl.Execute(&b, data); err != nil {
				return ScheduleState{}, err
			}
			if err := t.WriteFile(unitDir+"/"+file, b.Bytes()); err != nil {
				return ScheduleState{}, err
			}
		}
		if t.Changed {
			if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"daemon-reload"}}); err != nil {
				return ScheduleState{}, err
			}
		}
		if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"enable", "--now", unit + ".timer"}}); err != nil {
			return ScheduleState{}, err
		}
	} else if m.exists(unitDir + "/" + unit + ".timer") {
		if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"disable", "--now", unit + ".timer"}}); err != nil {
			return ScheduleState{}, err
		}
	}
	return m.scheduleState(spec.Domain), nil
}

// taskLine matches a task line of schedule:run, such as
// "  2026-10-18 21:00:00 Running ['artisan' inspire] ....... 4.21ms DONE".
var taskLine = regexp.MustCompile(`^\s*(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d) Running \[(.+?)\](?: in background)?[\s.]*?([0-9][0-9.,]*\s*(?:ms|s|m))?\s*(DONE|FAIL)\s*$`)

func (m *Manager) scheduleState(domain string) ScheduleState {
	st := ScheduleState{Tasks: []TaskRun{}}
	st.Enabled = m.exists(unitDir + "/timers.target.wants/" + scheduleUnit(domain) + ".timer")
	f, err := os.Open(m.h.Path(scheduleLog(domain)))
	if err != nil {
		return st
	}
	defer f.Close()
	if fi, err := f.Stat(); err == nil {
		t := fi.ModTime().UTC()
		st.LastRun = &t
		const tail = 256 << 10
		off := fi.Size() - tail
		if off < 0 {
			off = 0
		}
		buf := make([]byte, fi.Size()-off)
		n, _ := f.ReadAt(buf, off)
		st.Tasks = parseTaskRuns(string(buf[:n]))
	}
	return st
}

func parseTaskRuns(log string) []TaskRun {
	last := map[string]TaskRun{}
	var order []string
	for _, line := range strings.Split(log, "\n") {
		g := taskLine.FindStringSubmatch(line)
		if g == nil {
			continue
		}
		cmd := strings.TrimSpace(strings.ReplaceAll(strings.Replace(g[2], "'artisan'", "", 1), "'", ""))
		if _, ok := last[cmd]; !ok {
			order = append(order, cmd)
		}
		last[cmd] = TaskRun{Command: cmd, At: g[1], Duration: strings.TrimSpace(g[3]), OK: g[4] == "DONE"}
	}
	out := make([]TaskRun, 0, len(order))
	for _, c := range order {
		out = append(out, last[c])
	}
	return out
}
