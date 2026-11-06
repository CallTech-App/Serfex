package laravel

import (
	"context"
	"testing"
)

func TestParseUnitShow(t *testing.T) {
	out := "ActiveState=activating\nId=serfex-queue-a.test_default@1.service\n\nId=serfex-proc-a.test_horizon.service\nActiveState=active\n"
	got := parseUnitShow(out)
	if w := got["serfex-queue-a.test_default@1.service"]; w.State != "activating" {
		t.Fatalf("worker: %+v", w)
	}
	if w := got["serfex-proc-a.test_horizon.service"]; w.State != "active" {
		t.Fatalf("process: %+v", w)
	}
}

func TestCountFailures(t *testing.T) {
	journal := `serfex-queue-a.test_default@1.service: Main process exited, code=exited, status=255/EXCEPTION
serfex-queue-a.test_default@1.service: Failed with result 'exit-code'.
serfex-queue-a.test_default@1.service: Scheduled restart job, restart counter is at 1.
serfex-queue-a.test_default@1.service: Failed with result 'exit-code'.
serfex-proc-a.test_horizon.service: Deactivated successfully.
serfex-proc-a.test_reverb.service: Failed with result 'signal'.
`
	got := countFailures(journal)
	if got["serfex-queue-a.test_default@1.service"] != 2 || got["serfex-proc-a.test_reverb.service"] != 1 || got["serfex-proc-a.test_horizon.service"] != 0 {
		t.Fatalf("failures: %v", got)
	}
}

func TestTaskFailuresInARow(t *testing.T) {
	log := `  2026-11-06 10:00:00 Running ['artisan' report:send] 120ms DONE
  2026-11-06 10:01:00 Running ['artisan' report:send] 80ms FAIL
  2026-11-06 10:01:00 Running ['artisan' cache:prune] 10ms DONE
  2026-11-06 10:02:00 Running ['artisan' report:send] 81ms FAIL
  2026-11-06 10:03:00 Running ['artisan' report:send] 79ms FAIL
`
	runs := parseTaskRuns(log)
	if len(runs) != 2 || runs[0].Failures != 3 || runs[0].OK || runs[1].Failures != 0 {
		t.Fatalf("runs: %+v", runs)
	}
}

func TestWatchRejectsBadDomains(t *testing.T) {
	m, _ := newTest(t)
	if _, err := m.Watch(context.Background(), WatchSpec{Domains: []string{"../etc"}}); err == nil {
		t.Fatal("bad domain accepted")
	}
	got, err := m.Watch(context.Background(), WatchSpec{Domains: []string{"a.test"}})
	if err != nil || len(got) != 1 || got[0].Domain != "a.test" || len(got[0].Units) != 0 {
		t.Fatalf("watch: %+v %v", got, err)
	}
}
