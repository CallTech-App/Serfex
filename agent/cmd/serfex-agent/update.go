package main

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"os"
	"runtime"
	"time"

	"github.com/calltech-app/serfex/agent/internal/database"
	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/rpc"
	"github.com/calltech-app/serfex/agent/internal/update"
	"github.com/calltech-app/serfex/agent/internal/version"
)

// spawnUpdate starts an update in a transient systemd unit, from this
// binary: the update restarts the agent, which must not take it down.
func spawnUpdate(h host.Host, socket string) func(context.Context, string) error {
	return func(ctx context.Context, run string) error {
		exe, err := os.Executable()
		if err != nil {
			return err
		}
		_, err = h.Run(ctx, host.Command{Name: "systemd-run", Args: []string{
			"--unit", "serfex-update-" + run, "--collect", "--quiet",
			"--description", "Serfex update " + run,
			"--property", "TimeoutStopSec=30min",
			exe, "-socket", socket, "-update-run", run,
		}})
		return err
	}
}

// runUpdate is the update process: it performs the run and exits.
func runUpdate(socket, run string, log *slog.Logger) int {
	h := host.System{}
	m := update.NewManager(h, version.Version, runtime.GOARCH)
	m.WebPort = database.NewManager(h).WebPort
	m.RestartAgent = func(ctx context.Context, want string) error {
		if _, err := h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"restart", update.AgentUnit}}); err != nil {
			return err
		}
		return waitForAgent(ctx, socket, want, time.Minute)
	}
	m.ApplyPanel = func(ctx context.Context) error {
		cctx, cancel := context.WithTimeout(ctx, 10*time.Minute)
		defer cancel()
		return rpc.Call(cctx, socket, "panel.setup", nil, nil)
	}
	if err := m.Execute(context.Background(), run); err != nil {
		log.Error("update failed", "run", run, "err", err)
		return 1
	}
	return 0
}

// waitForAgent waits until the agent on socket answers with version want.
func waitForAgent(ctx context.Context, socket, want string, limit time.Duration) error {
	deadline := time.Now().Add(limit)
	var last error
	for time.Now().Before(deadline) {
		var info struct {
			Version string `json:"version"`
		}
		cctx, cancel := context.WithTimeout(ctx, 5*time.Second)
		err := rpc.Call(cctx, socket, "agent.info", nil, &info)
		cancel()
		if err == nil && info.Version == want {
			return nil
		}
		if err == nil {
			err = fmt.Errorf("the agent answers as %s", info.Version)
		}
		last = err
		time.Sleep(time.Second)
	}
	return fmt.Errorf("no answer from the agent as %s within %s: %w", want, limit, last)
}

// call is "serfex-agent call METHOD [JSON]": one request to the running
// agent, the result printed as JSON (install.sh and administrators).
func call(socket string, args []string) int {
	if len(args) == 0 || len(args) > 2 {
		fmt.Fprintln(os.Stderr, "usage: serfex-agent [-socket S] call METHOD [JSON]")
		return 2
	}
	var params any = struct{}{}
	if len(args) == 2 {
		params = json.RawMessage(args[1])
	}
	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Hour)
	defer cancel()
	var out json.RawMessage
	if err := rpc.Call(ctx, socket, args[0], params, &out); err != nil {
		var re *rpc.Error
		if errors.As(err, &re) {
			fmt.Fprintf(os.Stderr, "%s (%d)\n", re.Message, re.Code)
		} else {
			fmt.Fprintln(os.Stderr, err)
		}
		return 1
	}
	if len(out) == 0 {
		out = json.RawMessage("null")
	}
	fmt.Println(string(out))
	return 0
}
