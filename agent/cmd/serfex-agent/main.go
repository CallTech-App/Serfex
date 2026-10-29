// Command serfex-agent is the privileged half of Serfex.
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
	"os/user"
	"path/filepath"
	"runtime"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"

	"github.com/calltech-app/serfex/agent/internal/backup"
	"github.com/calltech-app/serfex/agent/internal/cert"
	"github.com/calltech-app/serfex/agent/internal/component"
	"github.com/calltech-app/serfex/agent/internal/cron"
	"github.com/calltech-app/serfex/agent/internal/database"
	"github.com/calltech-app/serfex/agent/internal/dns"
	"github.com/calltech-app/serfex/agent/internal/files"
	"github.com/calltech-app/serfex/agent/internal/firewall"
	"github.com/calltech-app/serfex/agent/internal/ftp"
	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/laravel"
	"github.com/calltech-app/serfex/agent/internal/logrotate"
	"github.com/calltech-app/serfex/agent/internal/mail"
	"github.com/calltech-app/serfex/agent/internal/monitor"
	"github.com/calltech-app/serfex/agent/internal/php"
	"github.com/calltech-app/serfex/agent/internal/quota"
	"github.com/calltech-app/serfex/agent/internal/rpc"
	"github.com/calltech-app/serfex/agent/internal/security"
	"github.com/calltech-app/serfex/agent/internal/service"
	"github.com/calltech-app/serfex/agent/internal/site"
	"github.com/calltech-app/serfex/agent/internal/stats"
	"github.com/calltech-app/serfex/agent/internal/system"
	"github.com/calltech-app/serfex/agent/internal/update"
	"github.com/calltech-app/serfex/agent/internal/version"
)

const defaultSocket = "/run/serfex-agent/agent.sock"

func main() {
	socket := flag.String("socket", defaultSocket, "path of the Unix socket to listen on")
	showVersion := flag.Bool("version", false, "print version and exit")
	dryRun := flag.Bool("dry-run", false, "simulate server changes: keep files under -root and only log commands")
	root := flag.String("root", "", "with -dry-run, the directory that stands in for / (default: a new temporary directory)")
	webUser := flag.String("web-user", "www-data", "account the web server runs as")
	acmeDir := flag.String("acme-directory", cert.LetsEncrypt, "ACME directory URL certificates are requested from")
	acmeCA := flag.String("acme-ca", "", "PEM file with extra root certificates to trust for the ACME server (for test servers such as Pebble)")
	socketGroup := flag.String("socket-group", "", "group that may connect to the socket (the panel's account, serfex, on installed servers)")
	updateRun := flag.String("update-run", "", "perform a started update or rollback (the agent starts this itself)")
	flag.Usage = func() {
		fmt.Fprintf(flag.CommandLine.Output(), "Usage:\n  serfex-agent [flags]                  run the agent\n  serfex-agent [-socket S] call METHOD [JSON]   call a method of the running agent\n  serfex-agent cron-exec [--manual] DOMAIN ID   run a site's scheduled task (cron starts it)\n\nFlags:\n")
		flag.PrintDefaults()
	}
	flag.Parse()

	if *showVersion {
		fmt.Println(version.Version)
		return
	}
	if flag.Arg(0) == "files-helper" {
		// Started by the agent itself, as a site's user (files package).
		os.Exit(files.Serve(os.Stdin, os.Stdout))
	}
	if flag.Arg(0) == "cron-exec" {
		// Started by cron, or by the agent for a manual run, as a site's
		// user (cron package).
		os.Exit(cron.Exec(flag.Args()[1:]))
	}
	if flag.Arg(0) == "call" {
		os.Exit(call(*socket, flag.Args()[1:]))
	}

	log := slog.New(slog.NewJSONHandler(os.Stderr, nil))
	if *updateRun != "" {
		os.Exit(runUpdate(*socket, *updateRun, log))
	}
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
	svc.mail.Addresses = ownAddresses
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
	svc.stats = stats.New()
	svc.stats.MailDomains = mailDomains(h)
	svc.statsFile = h.Path(statsState)
	if err := svc.stats.Load(svc.statsFile); err != nil {
		log.Error("loading uncollected statistics", "err", err)
	}
	if *dryRun {
		svc.stats.SetGeo(stats.DemoGeo())
	} else {
		svc.geoFile = h.Path(stats.GeoFile)
	}
	svc.monitor.Stats = svc.stats
	svc.certs.PanelChanged = svc.databases.PanelCertificateChanged
	svc.php = php.NewManager(h)
	svc.components = component.NewManager(h, svc.sites, svc.databases, svc.laravel)
	svc.components.PHP = svc.php
	svc.services = service.NewManager(h)
	svc.services.Usage = func() map[string]service.Usage {
		out := map[string]service.Usage{}
		for _, u := range svc.monitor.Snapshot(monitor.SnapshotSpec{Window: 10}).Services {
			out[strings.TrimSuffix(u.Unit, ".service")] = service.Usage{CPU: u.CPU, Memory: u.Memory}
		}
		return out
	}
	if !*dryRun {
		svc.services.SelfRestart = func(ctx context.Context) error {
			// The answer goes out before systemd restarts the agent.
			_, err := h.Run(ctx, host.Command{Name: "systemd-run", Args: []string{"--on-active=2", "--collect", "--unit=serfex-agent-restart-" + strconv.FormatInt(time.Now().Unix(), 10), "systemctl", "restart", "serfex-agent.service"}})
			return err
		}
	}
	if !*dryRun {
		svc.components.Addresses = ownAddressStrings
	}
	svc.backups = backup.NewManager(h, svc.databases, svc.laravel)
	svc.backups.InstallCert = func(ctx context.Context, spec cert.InstallSpec) error {
		_, err := svc.certs.Install(ctx, spec)
		return err
	}
	exe, err := os.Executable()
	if err != nil {
		log.Error("agent stopped", "err", err)
		os.Exit(2)
	}
	svc.files = files.NewManager(h, exe)
	svc.ftp = ftp.NewManager(h)
	svc.cron = cron.NewManager(h)
	svc.cron.Demo = *dryRun
	svc.system = system.NewManager(h)
	svc.logs = logrotate.NewManager(h)
	svc.quotas = quota.NewManager(h)
	svc.log = log
	svc.security = security.NewManager(h, svc.files)
	svc.security.WebUser = *webUser
	svc.laravel.Snippet = svc.security.WriteSnippet
	svc.firewall = firewall.NewManager(h)
	if !*dryRun {
		svc.firewall.Protected = ownAddresses
	}
	svc.updates = update.NewManager(h, version.Version, runtime.GOARCH)
	svc.updates.WebPort = svc.databases.WebPort
	svc.updates.Web = func(ctx context.Context) (any, error) { return svc.databases.SetupPanelWeb(ctx) }
	svc.updates.DefaultSite = svc.sites.EnsureDefaultSite
	svc.updates.Mail = svc.mail.RefreshPostfix
	if *dryRun {
		// The run stays in this process, which is not restarted.
		svc.updates.ApplyPanel = func(ctx context.Context) error {
			_, err := svc.updates.Setup(ctx)
			return err
		}
	} else {
		svc.updates.Spawn = spawnUpdate(h, *socket)
	}
	if err := run(*socket, *socketGroup, svc, log); err != nil {
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
		dir, err := os.MkdirTemp("", "serfex-dry-run-")
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
	if err := php.SeedSimulator(sim, "8.3", "8.4", "8.5"); err != nil {
		return nil, err
	}
	log.Warn("dry run: no real server changes are made", "root", root)
	return sim, nil
}

func run(socket, group string, svc services, log *slog.Logger) error {
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
	if group != "" {
		g, err := user.LookupGroup(group)
		if err != nil {
			ln.Close()
			return err
		}
		gid, _ := strconv.Atoi(g.Gid)
		if err := os.Chown(socket, 0, gid); err != nil {
			ln.Close()
			return err
		}
	}

	srv := rpc.NewServer(log)
	registerMethods(srv, svc)

	// Blocks and the port filter come back after a reboot; monitoring runs
	// while the agent does.
	if err := svc.firewall.Restore(ctx); err != nil {
		log.Error("restoring IP blocks", "err", err)
	}
	if err := svc.firewall.RestoreRules(ctx); err != nil {
		log.Error("restoring the port filter", "err", err)
	}
	// Crontab lines name the agent's path, which an update changes.
	if err := svc.cron.Reconcile(ctx); err != nil {
		log.Error("restoring scheduled tasks", "err", err)
	}
	// Versions set up by older agents have their defaults for PHP-FPM only.
	if err := svc.php.SyncCLI(ctx); err != nil {
		log.Error("copying PHP defaults to the command line", "err", err)
	}
	// Rotation rules are rebuilt at once: sites set up by older agents
	// had none.
	go svc.logs.Run(ctx, log)
	go svc.monitor.Run(ctx)
	go svc.databases.Run(ctx)
	if svc.geoFile != "" {
		go stats.KeepGeo(ctx, svc.stats, svc.geoFile, stats.RegistryFiles, log)
	}

	log.Info("agent listening", "socket", socket, "version", version.Version)
	err = srv.Serve(ctx, ln)
	// Counts the panel has not collected yet survive the restart.
	if serr := svc.stats.Save(svc.statsFile); serr != nil {
		log.Error("saving uncollected statistics", "err", serr)
	}
	return err
}

// statsState holds the statistics not collected yet while the agent is
// stopped.
const statsState = "/var/lib/serfex/stats/state.gob"

// mailDomains lists the server's mail domains (package mail keeps one
// state file each), read again at most every 30 seconds.
func mailDomains(h host.Host) func() map[string]bool {
	var (
		mu   sync.Mutex
		at   time.Time
		last map[string]bool
	)
	return func() map[string]bool {
		mu.Lock()
		defer mu.Unlock()
		if last != nil && time.Since(at) < 30*time.Second {
			return last
		}
		last, at = map[string]bool{}, time.Now()
		files, _ := filepath.Glob(h.Path("/etc/serfex/mail/domains/*.json"))
		for _, f := range files {
			last[strings.TrimSuffix(filepath.Base(f), ".json")] = true
		}
		return last
	}
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
