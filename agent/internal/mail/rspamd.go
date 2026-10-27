package mail

import (
	"bytes"
	"context"
	"net"
	"os"
	"time"

	"github.com/calltech-app/serfex/agent/internal/host"
)

// Postfix passes mail on unsigned while Rspamd's milter does not answer
// (milter_default_action = accept), and systemctl returns before Rspamd
// has loaded its settings: a first start takes seconds, and after a
// reload the old workers keep the old settings until they exit. So the
// agent waits for both, with these limits, before mail is sent.
const (
	rspamdOldWorkers = 20 * time.Second
	rspamdListening  = 30 * time.Second
)

// reloadRspamd reloads Rspamd and waits until the workers that ran the
// old settings have exited and the new ones answer.
func (m *Manager) reloadRspamd(ctx context.Context) error {
	old := m.rspamdWorkers()
	if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"reload", "rspamd"}}); err != nil {
		return err
	}
	for end := time.Now().Add(rspamdOldWorkers); len(old) > 0 && time.Now().Before(end); {
		if err := pause(ctx); err != nil {
			return err
		}
		now := m.rspamdWorkers()
		for pid := range old {
			if !now[pid] {
				delete(old, pid)
			}
		}
	}
	return m.waitRspamd(ctx)
}

// waitRspamd waits until Rspamd's milter accepts connections. Past the
// limit it gives up quietly: mail still flows, only unsigned.
func (m *Manager) waitRspamd(ctx context.Context) error {
	if _, sim := m.h.(*host.Simulator); sim {
		return nil
	}
	for end := time.Now().Add(rspamdListening); time.Now().Before(end); {
		if c, err := net.DialTimeout("tcp", milterAddr, time.Second); err == nil {
			c.Close()
			return nil
		}
		if err := pause(ctx); err != nil {
			return err
		}
	}
	return nil
}

func pause(ctx context.Context) error {
	select {
	case <-ctx.Done():
		return ctx.Err()
	case <-time.After(100 * time.Millisecond):
		return nil
	}
}

// rspamdWorkers returns the PIDs of Rspamd's worker processes (all of
// its processes but the main one).
func (m *Manager) rspamdWorkers() map[string]bool {
	out := map[string]bool{}
	entries, err := os.ReadDir(m.h.Path("/proc"))
	if err != nil {
		return out
	}
	for _, e := range entries {
		pid := e.Name()
		if pid[0] < '0' || pid[0] > '9' {
			continue
		}
		comm, err := os.ReadFile(m.h.Path("/proc/" + pid + "/comm"))
		if err != nil || string(bytes.TrimSpace(comm)) != "rspamd" {
			continue
		}
		cmd, err := os.ReadFile(m.h.Path("/proc/" + pid + "/cmdline"))
		if err != nil || bytes.Contains(cmd, []byte("main process")) {
			continue
		}
		out[pid] = true
	}
	return out
}
