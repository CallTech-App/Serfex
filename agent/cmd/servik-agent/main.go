// Command servik-agent is the privileged half of Servik.
//
// It runs as root under systemd and performs server operations (web server,
// PHP-FPM, DNS, mail, certificates, system users) on behalf of the panel.
// The panel never runs as root; it talks to this agent over a local Unix
// socket using JSON-RPC 2.0.
package main

import (
	"context"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"log/slog"
	"net"
	"os"
	"os/signal"
	"path/filepath"
	"runtime"
	"syscall"

	"github.com/winchester37/servik/agent/internal/rpc"
	"github.com/winchester37/servik/agent/internal/version"
)

const defaultSocket = "/run/servik-agent/agent.sock"

func main() {
	socket := flag.String("socket", defaultSocket, "path of the Unix socket to listen on")
	showVersion := flag.Bool("version", false, "print version and exit")
	flag.Parse()

	if *showVersion {
		fmt.Println(version.Version)
		return
	}

	log := slog.New(slog.NewJSONHandler(os.Stderr, nil))
	if err := run(*socket, log); err != nil {
		log.Error("agent stopped", "err", err)
		os.Exit(1)
	}
}

func run(socket string, log *slog.Logger) error {
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	if err := os.MkdirAll(filepath.Dir(socket), 0o750); err != nil {
		return err
	}
	if err := os.Remove(socket); err != nil && !errors.Is(err, os.ErrNotExist) {
		return err
	}
	ln, err := net.Listen("unix", socket)
	if err != nil {
		return err
	}
	// Only the owner and the panel's group may connect.
	if err := os.Chmod(socket, 0o660); err != nil {
		ln.Close()
		return err
	}

	srv := rpc.NewServer(log)
	registerMethods(srv)

	log.Info("agent listening", "socket", socket, "version", version.Version)
	return srv.Serve(ctx, ln)
}

func registerMethods(srv *rpc.Server) {
	srv.Handle("system.ping", func(context.Context, json.RawMessage) (any, error) {
		return "pong", nil
	})
	srv.Handle("agent.info", func(context.Context, json.RawMessage) (any, error) {
		return map[string]string{
			"version": version.Version,
			"go":      runtime.Version(),
			"os":      runtime.GOOS,
			"arch":    runtime.GOARCH,
		}, nil
	})
}
