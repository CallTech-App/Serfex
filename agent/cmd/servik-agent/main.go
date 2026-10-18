// Command servik-agent is the privileged half of Servik.
//
// It runs as root under systemd and performs server operations (web server,
// PHP-FPM, DNS, mail, certificates, system users) on behalf of the panel.
// The panel never runs as root; it talks to this agent over a local Unix
// socket using JSON-RPC 2.0.
package main

import (
	"context"
	"errors"
	"flag"
	"fmt"
	"log/slog"
	"net"
	"os"
	"os/signal"
	"path/filepath"
	"syscall"

	"github.com/winchester37/servik/agent/internal/dns"
	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/rpc"
	"github.com/winchester37/servik/agent/internal/site"
	"github.com/winchester37/servik/agent/internal/version"
)

const defaultSocket = "/run/servik-agent/agent.sock"

func main() {
	socket := flag.String("socket", defaultSocket, "path of the Unix socket to listen on")
	showVersion := flag.Bool("version", false, "print version and exit")
	dryRun := flag.Bool("dry-run", false, "simulate server changes: keep files under -root and only log commands")
	root := flag.String("root", "", "with -dry-run, the directory that stands in for / (default: a new temporary directory)")
	webUser := flag.String("web-user", "www-data", "account the web server runs as")
	flag.Parse()

	if *showVersion {
		fmt.Println(version.Version)
		return
	}

	log := slog.New(slog.NewJSONHandler(os.Stderr, nil))
	h, err := newHost(*dryRun, *root, log)
	if err != nil {
		log.Error("agent stopped", "err", err)
		os.Exit(2)
	}
	svc := services{sites: site.NewManager(h, *webUser), dns: dns.NewManager(h), dryRun: *dryRun}
	if *dryRun {
		svc.dns = dns.NewSimulatedManager(h, dns.NewMemory())
	}
	if err := run(*socket, svc, log); err != nil {
		log.Error("agent stopped", "err", err)
		os.Exit(1)
	}
}

// newHost returns the real system, or a simulator for dry runs.
func newHost(dryRun bool, root string, log *slog.Logger) (host.Host, error) {
	if !dryRun {
		if root != "" {
			return nil, errors.New("-root only works together with -dry-run")
		}
		return host.System{}, nil
	}
	if root == "" {
		dir, err := os.MkdirTemp("", "servik-dry-run-")
		if err != nil {
			return nil, err
		}
		root = dir
	}
	sim, err := host.NewSimulator(root, "8.3", "8.4", "8.5")
	if err != nil {
		return nil, err
	}
	log.Warn("dry run: no real server changes are made", "root", root)
	return sim, nil
}

func run(socket string, svc services, log *slog.Logger) error {
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
	registerMethods(srv, svc)

	log.Info("agent listening", "socket", socket, "version", version.Version)
	return srv.Serve(ctx, ln)
}
