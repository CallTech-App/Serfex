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
	"net/netip"
	"os"
	"os/signal"
	"path/filepath"
	"syscall"
	"time"

	"github.com/winchester37/servik/agent/internal/backup"
	"github.com/winchester37/servik/agent/internal/cert"
	"github.com/winchester37/servik/agent/internal/component"
	"github.com/winchester37/servik/agent/internal/database"
	"github.com/winchester37/servik/agent/internal/dns"
	"github.com/winchester37/servik/agent/internal/firewall"
	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/laravel"
	"github.com/winchester37/servik/agent/internal/mail"
	"github.com/winchester37/servik/agent/internal/monitor"
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
	if *dryRun {
		svc.monitor = monitor.NewDemo(h, svc.sites, log)
	} else {
		svc.monitor = monitor.New(h, svc.sites, log)
	}
	svc.sites.MaxChildren = svc.monitor.PHPWorkers
	if *dryRun {
		svc.databases = database.NewSimulated(h)
	} else {
		svc.databases = database.NewManager(h)
	}
	svc.monitor.DatabaseSites = svc.databases.Sites
	svc.certs.PanelChanged = svc.databases.PanelCertificateChanged
	svc.components = component.NewManager(h, svc.sites, svc.databases, svc.laravel)
	if !*dryRun {
		svc.components.Addresses = ownAddressStrings
	}
	svc.backups = backup.NewManager(h, svc.databases, svc.laravel)
	svc.backups.InstallCert = func(ctx context.Context, spec cert.InstallSpec) error {
		_, err := svc.certs.Install(ctx, spec)
		return err
	}
	svc.firewall = firewall.NewManager(h)
	if !*dryRun {
		svc.firewall.Protected = ownAddresses
	}
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
	// Nginx and those PHP versions count as installed (Yazılım Yönetimi).
	bins := []string{"/usr/sbin/nginx"}
	for _, v := range []string{"8.3", "8.4", "8.5"} {
		bins = append(bins, "/usr/sbin/php-fpm"+v, "/usr/bin/php"+v)
	}
	for _, bin := range bins {
		if _, err := os.Stat(sim.Path(bin)); errors.Is(err, os.ErrNotExist) {
			if err := os.MkdirAll(filepath.Dir(sim.Path(bin)), 0o755); err != nil {
				return nil, err
			}
			if err := os.WriteFile(sim.Path(bin), nil, 0o755); err != nil {
				return nil, err
			}
		}
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

	// Blocks come back after a reboot; monitoring runs while the agent does.
	if err := svc.firewall.Restore(ctx); err != nil {
		log.Error("restoring IP blocks", "err", err)
	}
	go svc.monitor.Run(ctx)
	go svc.databases.Run(ctx)

	log.Info("agent listening", "socket", socket, "version", version.Version)
	return srv.Serve(ctx, ln)
}

// ownAddressStrings are the server's addresses as text, for Fail2ban.
func ownAddressStrings() []string {
	var out []string
	for _, a := range ownAddresses() {
		if !a.IsLoopback() && !a.IsLinkLocalUnicast() {
			out = append(out, a.String())
		}
	}
	return out
}

// ownAddresses are every address of this server, which must never be
// blocked.
func ownAddresses() []netip.Addr {
	addrs, err := net.InterfaceAddrs()
	if err != nil {
		return nil
	}
	var out []netip.Addr
	for _, a := range addrs {
		if n, ok := a.(*net.IPNet); ok {
			if ip, ok := netip.AddrFromSlice(n.IP); ok {
				out = append(out, ip.Unmap())
			}
		}
	}
	return out
}
