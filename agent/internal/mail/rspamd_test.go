package mail

import (
	"os"
	"path/filepath"
	"testing"
)

func TestRspamdWorkersLeavesOutTheMainProcess(t *testing.T) {
	m, sim := newTest(t)
	for pid, p := range map[string][2]string{
		"101": {"rspamd\n", "rspamd: main process\x00"},
		"102": {"rspamd\n", "rspamd: rspamd_proxy process (127.0.0.1:11332)\x00"},
		"103": {"rspamd\n", "rspamd: normal process\x00"},
		"104": {"nginx\n", "nginx: worker process\x00"},
	} {
		dir := sim.Path(filepath.Join("/proc", pid))
		if err := os.MkdirAll(dir, 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(dir+"/comm", []byte(p[0]), 0o644); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(dir+"/cmdline", []byte(p[1]), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	got := m.rspamdWorkers()
	if len(got) != 2 || !got["102"] || !got["103"] {
		t.Fatalf("workers = %v", got)
	}
}
