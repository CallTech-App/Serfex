// Command servik-agent is the privileged half of Servik.
//
// It runs as root under systemd and performs server operations (web server,
// PHP-FPM, DNS, mail, certificates, system users) on behalf of the panel.
// The panel never runs as root; it talks to this agent over a local Unix
// socket using JSON-RPC 2.0.
package main

import (
	"context"
	"crypto/tls"
	"crypto/x509"
	"errors"
	"flag"
	"fmt"
	"log/slog"
	"net"
	"net/http"
	"os"
	"os/signal"
	"path/filepath"
	"syscall"
	"time"

	"github.com/winchester37/servik/agent/internal/cert"
	"github.com/winchester37/servik/agent/internal/dns"
	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/laravel"
	"github.com/winchester37/servik/agent/internal/mail"
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
	acmeDir := flag.String("acme-directory", cert.LetsEncrypt, "ACME directory URL certificates are requested from")
	acmeCA := flag.String("acme-ca", "", "PEM file with extra root certificates to trust for the ACME server (for test servers such as Pebble)")
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
	var issuer cert.Issuer = &cert.Simulated{}
	if *dryRun {
		svc.dns = dns.NewSimulatedManager(h, dns.NewMemory())
	} else {
		client, err := acmeHTTPClient(*acmeCA)
		if err != nil {
			log.Error("agent stopped", "err", err)
			os.Exit(2)
		}
		issuer = &cert.ACME{H: h, DirectoryURL: *acmeDir, HTTPClient: client}
	}
	svc.certs = cert.NewManager(h, svc.dns, issuer)
	svc.mail = mail.NewManager(h, *webUser)
	svc.certs.AddConsumer(svc.mail)
	svc.laravel = laravel.NewManager(h)
	if err := run(*socket, svc, log); err != nil {
		log.Error("agent stopped", "err", err)
		os.Exit(1)
	}
}

// acmeHTTPClient returns the HTTP client for the ACME server, trusting
// the roots in caFile on top of the system's.
func acmeHTTPClient(caFile string) (*http.Client, error) {
	client := &http.Client{Timeout: 30 * time.Second}
	if caFile == "" {
		return client, nil
	}
	pool, err := x509.SystemCertPool()
	if err != nil {
		pool = x509.NewCertPool()
	}
	data, err := os.ReadFile(caFile)
	if err != nil {
		return nil, err
	}
	if !pool.AppendCertsFromPEM(data) {
		return nil, fmt.Errorf("%s holds no PEM certificate", caFile)
	}
	tr := http.DefaultTransport.(*http.Transport).Clone()
	tr.TLSClientConfig = &tls.Config{RootCAs: pool, MinVersion: tls.VersionTLS12}
	client.Transport = tr
	return client, nil
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
