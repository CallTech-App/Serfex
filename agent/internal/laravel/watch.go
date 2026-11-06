package laravel

import (
	"context"
	"fmt"
	"regexp"
	"sort"
	"strings"
	"time"

	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/site"
)

// WatchSpec names the applications the panel checks every minute.
type WatchSpec struct {
	Domains []string `json:"domains"`
}

// Watch is how an application's background work is doing: its enabled
// queue workers and processes, with how often systemd restarted them, and
// the scheduler's tasks with their failures in a row.
type Watch struct {
	Domain    string      `json:"domain"`
	Units     []UnitWatch `json:"units"`
	Scheduler bool        `json:"scheduler"`
	Tasks     []TaskRun   `json:"tasks"`
}

// UnitWatch is one systemd unit of an application.
type UnitWatch struct {
	Unit string `json:"unit"`
	// Kind is "worker" or "process".
	Kind string `json:"kind"`
	// Name is the worker's or process's name in the panel.
	Name string `json:"name"`
	// State is systemd's ActiveState: active, activating (waiting to be
	// restarted), failed, inactive...
	State string `json:"state"`
	// Crashes counts the times the unit's main process failed (exited
	// with an error or was killed) in the last WatchWindow; a worker that
	// stops cleanly after --max-jobs or --max-time is not counted.
	Crashes int `json:"crashes"`
}

const maxWatch = 500

// WatchWindow is how far back crashes are counted.
const WatchWindow = 10 * time.Minute

// failedLine is systemd's journal line for a unit whose process failed:
// "serfex-queue-a.test_default@1.service: Failed with result 'exit-code'."
var failedLine = regexp.MustCompile(`^(\S+\.service): Failed with result '`)

// Watch reports the units and scheduled tasks of each application. It only
// reads: one systemctl and one journalctl call for all units, and the
// schedulers' logs.
func (m *Manager) Watch(ctx context.Context, spec WatchSpec) ([]Watch, error) {
	if len(spec.Domains) > maxWatch {
		return nil, invalid("at most %d domains", maxWatch)
	}
	for _, d := range spec.Domains {
		if err := site.ValidateDomain(d); err != nil {
			return nil, err
		}
	}
	out := make([]Watch, 0, len(spec.Domains))
	var units []string
	for _, d := range spec.Domains {
		w := Watch{Domain: d, Units: []UnitWatch{}, Tasks: []TaskRun{}}
		for _, u := range m.enabledWorkers(d) {
			w.Units = append(w.Units, UnitWatch{Unit: u, Kind: "worker", Name: workerName(d, u), State: "unknown"})
		}
		for _, u := range m.enabledProcesses(d) {
			w.Units = append(w.Units, UnitWatch{Unit: u, Kind: "process", Name: strings.TrimSuffix(strings.TrimPrefix(u, procPrefix(d)), ".service"), State: "unknown"})
		}
		for _, u := range w.Units {
			units = append(units, u.Unit)
		}
		st := m.scheduleState(d)
		w.Scheduler = st.Enabled
		if st.Enabled {
			w.Tasks = st.Tasks
		}
		out = append(out, w)
	}
	if len(units) == 0 {
		return out, nil
	}
	sort.Strings(units)
	res, _ := m.h.Run(ctx, host.Command{Name: "systemctl", Args: append([]string{"show", "--property=Id,ActiveState", "--"}, units...)})
	shown := parseUnitShow(res)
	args := []string{"--no-pager", "--quiet", "-o", "cat", "--since", fmt.Sprintf("%d min ago", int(WatchWindow.Minutes()))}
	for _, u := range units {
		args = append(args, "-u", u)
	}
	journal, _ := m.h.Run(ctx, host.Command{Name: "journalctl", Args: args})
	crashes := countFailures(journal)
	for i := range out {
		for j, u := range out[i].Units {
			if s, ok := shown[u.Unit]; ok {
				out[i].Units[j].State = s.State
			}
			out[i].Units[j].Crashes = crashes[u.Unit]
		}
	}
	return out, nil
}

// countFailures counts systemd's "Failed with result" lines per unit.
func countFailures(journal string) map[string]int {
	out := map[string]int{}
	for _, line := range strings.Split(journal, "\n") {
		if g := failedLine.FindStringSubmatch(strings.TrimSpace(line)); g != nil {
			out[g[1]]++
		}
	}
	return out
}

// parseUnitShow reads systemctl show's blocks (Key=Value lines, a blank
// line between units) into unit => state.
func parseUnitShow(out string) map[string]UnitWatch {
	res := map[string]UnitWatch{}
	var cur UnitWatch
	flush := func() {
		if cur.Unit != "" {
			res[cur.Unit] = cur
		}
		cur = UnitWatch{}
	}
	for _, line := range strings.Split(out, "\n") {
		line = strings.TrimSpace(line)
		if line == "" {
			flush()
			continue
		}
		k, v, ok := strings.Cut(line, "=")
		if !ok {
			continue
		}
		switch k {
		case "Id":
			cur.Unit = v
		case "ActiveState":
			cur.State = v
		}
	}
	flush()
	return res
}
