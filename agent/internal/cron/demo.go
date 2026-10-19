package cron

import (
	"os"
	"time"
)

// seed gives tasks without any run record a made-up history of three
// scheduled runs, one of them failing, so a dry run's pages show what a
// real server would. Only dry runs seed (Manager.Demo).
func (m *Manager) seed(spec Spec) {
	now := m.now().UTC().Truncate(time.Minute)
	for _, task := range spec.Tasks {
		runs := m.h.Path(TaskDir(spec.User, spec.Domain, task.ID) + "/runs")
		if entries, err := os.ReadDir(runs); err == nil && len(entries) > 0 {
			continue
		}
		for i, r := range []struct {
			ago    time.Duration
			ms     int64
			code   int
			output string
		}{
			{3 * time.Hour, 1240, 0, "Done.\n"},
			{2 * time.Hour, 380, 1, "PHP Fatal error:  Uncaught PDOException: SQLSTATE[HY000] [2002] Connection refused in /home/" + spec.User + "/web/" + spec.Domain + "/public_html/cron.php:14\n" +
				"Stack trace:\n#0 /home/" + spec.User + "/web/" + spec.Domain + "/public_html/cron.php(14): PDO->__construct()\n#1 {main}\n  thrown in /home/" + spec.User + "/web/" + spec.Domain + "/public_html/cron.php on line 14\n"},
			{time.Hour, 1105, 0, "Done.\n"},
		} {
			start := now.Add(-r.ago).Add(time.Duration(i) * time.Second)
			_ = m.writeSimRecord(spec.User, spec.Domain, task.ID, Record{
				StartedAt: start, FinishedAt: start.Add(time.Duration(r.ms) * time.Millisecond), DurationMS: r.ms,
				ExitCode: r.code, Output: r.output, Trigger: TriggerSchedule,
			})
		}
	}
}
