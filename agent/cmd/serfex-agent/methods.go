package main

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"log/slog"
	"net"
	"net/netip"
	"runtime"
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

// CodeDNSNotSetUp tells the panel to offer DNS server setup.
const CodeDNSNotSetUp = -32001

// CodeMailNotSetUp tells the panel to offer mail server setup.
const CodeMailNotSetUp = -32002

// CodeDatabaseNotInstalled tells the panel to offer installing the
// database server.
const CodeDatabaseNotInstalled = -32003

// CodeComponentInUse refuses to remove software something still uses;
// the message lists what.
const CodeComponentInUse = -32004

// CodeComponentBusy says another software operation is running.
const CodeComponentBusy = -32005

// CodeBackupBusy says another backup or restore is running.
const CodeBackupBusy = -32006

// CodeUpdateBusy says an update or rollback is running.
const CodeUpdateBusy = -32007

// CodeFileError is a refused or failed file manager operation; the
// message starts with a stable code ("exists: ...").
const CodeFileError = -32008

// CodeWAFNotInstalled tells the panel to offer installing ModSecurity.
const CodeWAFNotInstalled = -32009

// CodeConfigRejected means Nginx or Apache refused the configuration when
// it was tested; nothing was changed. The message carries the test output.
const CodeConfigRejected = -32010

// CodeCAUnreachable means the certificate authority could not be reached
// (lookup, connection or server error); trying again soon is safe.
const CodeCAUnreachable = -32011

// CodeDatabaseUpgrading says the database server is being upgraded to a
// new major version; its databases cannot be used until it is done.
const CodeDatabaseUpgrading = -32012

// services are the managers the methods act on.
type services struct {
	sites      *site.Manager
	dns        *dns.Manager
	certs      *cert.Manager
	mail       *mail.Manager
	laravel    *laravel.Manager
	monitor    *monitor.Monitor
	firewall   *firewall.Manager
	databases  *database.Manager
	components *component.Manager
	backups    *backup.Manager
	updates    *update.Manager
	files      *files.Manager
	ftp        *ftp.Manager
	security   *security.Manager
	services   *service.Manager
	php        *php.Manager
	cron       *cron.Manager
	system     *system.Manager
	logs       *logrotate.Manager
	quotas     *quota.Manager
	log        *slog.Logger
	stats      *stats.Aggregator
	statsFile  string
	geoFile    string
	dryRun     bool
}

func registerMethods(srv *rpc.Server, svc services) {
	srv.Handle("system.ping", func(context.Context, json.RawMessage) (any, error) {
		return "pong", nil
	})
	srv.Handle("agent.info", func(context.Context, json.RawMessage) (any, error) {
		return map[string]any{
			"version": version.Version,
			"go":      runtime.Version(),
			"os":      runtime.GOOS,
			"arch":    runtime.GOARCH,
			"dry_run": svc.dryRun,
		}, nil
	})
	srv.Handle("system.addresses", func(context.Context, json.RawMessage) (any, error) {
		return addresses(svc.dryRun)
	})
	srv.Handle("php.versions", func(context.Context, json.RawMessage) (any, error) {
		v, err := svc.sites.PHPVersions()
		return v, mapError(err)
	})
	srv.Handle("site.apply", func(ctx context.Context, p json.RawMessage) (any, error) {
		var spec site.Spec
		if err := decode(p, &spec); err != nil {
			return nil, err
		}
		res, err := svc.sites.Apply(ctx, spec)
		if err != nil {
			return nil, mapError(err)
		}
		syncLogRotation(ctx, svc)
		return res, nil
	})
	srv.Handle("site.delete", func(ctx context.Context, p json.RawMessage) (any, error) {
		var spec site.DeleteSpec
		if err := decode(p, &spec); err != nil {
			return nil, err
		}
		res, err := svc.sites.Delete(ctx, spec)
		if err != nil {
			return nil, mapError(err)
		}
		syncLogRotation(ctx, svc)
		return res, nil
	})
	handle(srv, "site.logs", func(_ context.Context, p site.LogsSpec) (any, error) { return svc.sites.Logs(p) })
	handle(srv, "usage.disk", func(ctx context.Context, p site.DiskSpec) (any, error) { return svc.sites.DiskUsage(ctx, p) })
	srv.Handle("dns.status", func(ctx context.Context, _ json.RawMessage) (any, error) {
		return svc.dns.Status(ctx), nil
	})
	srv.Handle("dns.setup", func(ctx context.Context, _ json.RawMessage) (any, error) {
		res, err := svc.dns.Setup(ctx)
		if err != nil {
			return nil, mapError(err)
		}
		return res, nil
	})
	srv.Handle("dns.zone.apply", func(ctx context.Context, p json.RawMessage) (any, error) {
		var spec dns.ZoneSpec
		if err := decode(p, &spec); err != nil {
			return nil, err
		}
		res, err := svc.dns.ApplyZone(ctx, spec)
		if err != nil {
			return nil, mapError(err)
		}
		return res, nil
	})
	srv.Handle("dns.zone.get", func(ctx context.Context, p json.RawMessage) (any, error) {
		var q struct {
			Zone string `json:"zone"`
		}
		if err := decode(p, &q); err != nil {
			return nil, err
		}
		res, err := svc.dns.GetZone(ctx, q.Zone)
		if err != nil {
			return nil, mapError(err)
		}
		return res, nil
	})
	srv.Handle("dns.zone.delete", func(ctx context.Context, p json.RawMessage) (any, error) {
		var q struct {
			Zone string `json:"zone"`
		}
		if err := decode(p, &q); err != nil {
			return nil, err
		}
		res, err := svc.dns.DeleteZone(ctx, q.Zone)
		if err != nil {
			return nil, mapError(err)
		}
		return res, nil
	})
	handle(srv, "dns.secondaries.get", func(ctx context.Context, _ struct{}) (any, error) { return svc.dns.GetSecondaries(ctx) })
	handle(srv, "dns.secondaries.set", func(ctx context.Context, p dns.SecondariesSpec) (any, error) { return svc.dns.SetSecondaries(ctx, p) })
	srv.Handle("ssl.issue", func(ctx context.Context, p json.RawMessage) (any, error) {
		var spec cert.IssueSpec
		if err := decode(p, &spec); err != nil {
			return nil, err
		}
		ctx, cancel := context.WithTimeout(ctx, issueTimeout)
		defer cancel()
		res, err := svc.certs.Issue(ctx, spec)
		if err != nil {
			return nil, mapError(err)
		}
		return res, nil
	})
	srv.Handle("ssl.install", func(ctx context.Context, p json.RawMessage) (any, error) {
		var spec cert.InstallSpec
		if err := decode(p, &spec); err != nil {
			return nil, err
		}
		res, err := svc.certs.Install(ctx, spec)
		if err != nil {
			return nil, mapError(err)
		}
		return res, nil
	})
	srv.Handle("ssl.get", func(_ context.Context, p json.RawMessage) (any, error) {
		var q struct {
			Name string `json:"name"`
		}
		if err := decode(p, &q); err != nil {
			return nil, err
		}
		res, err := svc.certs.Get(q.Name)
		if err != nil {
			return nil, mapError(err)
		}
		return res, nil
	})
	srv.Handle("ssl.delete", func(_ context.Context, p json.RawMessage) (any, error) {
		var q struct {
			Name string `json:"name"`
		}
		if err := decode(p, &q); err != nil {
			return nil, err
		}
		res, err := svc.certs.Delete(q.Name)
		if err != nil {
			return nil, mapError(err)
		}
		return res, nil
	})
	srv.Handle("ssl.panel.issue", func(ctx context.Context, p json.RawMessage) (any, error) {
		var spec cert.PanelSpec
		if err := decode(p, &spec); err != nil {
			return nil, err
		}
		ctx, cancel := context.WithTimeout(ctx, issueTimeout)
		defer cancel()
		res, err := svc.certs.IssuePanel(ctx, spec)
		if err != nil {
			return nil, mapError(err)
		}
		return res, nil
	})
	registerMail(srv, svc)
	registerLaravel(srv, svc)
	registerMonitor(srv, svc)
	registerDatabases(srv, svc)
	registerComponents(srv, svc)
	registerBackups(srv, svc)
	registerUpdates(srv, svc)
	registerFiles(srv, svc)
	registerSecurity(srv, svc)
	registerServices(srv, svc)
	registerCron(srv, svc)
	registerSettings(srv, svc)
	srv.Handle("ssl.panel.get", func(context.Context, json.RawMessage) (any, error) {
		res, err := svc.certs.GetPanel()
		if err != nil {
			return nil, mapError(err)
		}
		return res, nil
	})
}

func registerMail(srv *rpc.Server, svc services) {
	srv.Handle("mail.status", func(ctx context.Context, _ json.RawMessage) (any, error) {
		return svc.mail.Status(ctx), nil
	})
	srv.Handle("mail.setup", func(ctx context.Context, p json.RawMessage) (any, error) {
		var spec mail.SetupSpec
		if err := decode(p, &spec); err != nil {
			return nil, err
		}
		res, err := svc.mail.Setup(ctx, spec)
		if err != nil {
			return nil, mapError(err)
		}
		// Fail2ban starts watching the mail logins. When it cannot now
		// (another software operation runs), the panel's minute check
		// catches up.
		if svc.components != nil {
			_, _ = svc.components.Fail2banSync(context.WithoutCancel(ctx))
		}
		return res, nil
	})
	srv.Handle("mail.domain.apply", func(ctx context.Context, p json.RawMessage) (any, error) {
		var spec mail.DomainSpec
		if err := decode(p, &spec); err != nil {
			return nil, err
		}
		res, err := svc.mail.ApplyDomain(ctx, spec)
		if err != nil {
			return nil, mapError(err)
		}
		return res, nil
	})
	srv.Handle("mail.domain.delete", func(ctx context.Context, p json.RawMessage) (any, error) {
		var q struct {
			Domain      string `json:"domain"`
			RemoveFiles bool   `json:"remove_files"`
		}
		if err := decode(p, &q); err != nil {
			return nil, err
		}
		res, err := svc.mail.DeleteDomain(ctx, q.Domain, q.RemoveFiles)
		if err != nil {
			return nil, mapError(err)
		}
		return res, nil
	})
	srv.Handle("mail.mailbox.purge", func(ctx context.Context, p json.RawMessage) (any, error) {
		var q struct {
			Domain string `json:"domain"`
			Name   string `json:"name"`
		}
		if err := decode(p, &q); err != nil {
			return nil, err
		}
		res, err := svc.mail.PurgeMailbox(ctx, q.Domain, q.Name)
		if err != nil {
			return nil, mapError(err)
		}
		return res, nil
	})
	srv.Handle("mail.usage", func(_ context.Context, p json.RawMessage) (any, error) {
		var q struct {
			Domain string `json:"domain"`
		}
		if err := decode(p, &q); err != nil {
			return nil, err
		}
		res, err := svc.mail.Usage(q.Domain)
		if err != nil {
			return nil, mapError(err)
		}
		return res, nil
	})
	srv.Handle("mail.webmail.login", func(_ context.Context, p json.RawMessage) (any, error) {
		var q struct {
			Address string `json:"address"`
		}
		if err := decode(p, &q); err != nil {
			return nil, err
		}
		res, err := svc.mail.WebmailLogin(q.Address)
		if err != nil {
			return nil, mapError(err)
		}
		return res, nil
	})
}

// issueTimeout bounds one certificate request, validation included.
const issueTimeout = 4 * time.Minute

// addresses lists the server's global IP addresses, public ones before
// private ones (a cloud server behind NAT may only see a private one). The
// panel uses the first of each family for the A and AAAA records of new
// zones. Dry runs return documentation addresses so tests are
// deterministic.
func addresses(dryRun bool) (map[string][]string, error) {
	out := map[string][]string{"ipv4": {}, "ipv6": {}}
	if dryRun {
		out["ipv4"] = []string{"203.0.113.10"}
		out["ipv6"] = []string{"2001:db8::10"}
		return out, nil
	}
	addrs, err := net.InterfaceAddrs()
	if err != nil {
		return nil, err
	}
	var private []netip.Addr
	add := func(ip netip.Addr) {
		if ip.Is4() {
			out["ipv4"] = append(out["ipv4"], ip.String())
		} else {
			out["ipv6"] = append(out["ipv6"], ip.String())
		}
	}
	for _, a := range addrs {
		n, ok := a.(*net.IPNet)
		if !ok {
			continue
		}
		ip, ok := netip.AddrFromSlice(n.IP)
		if !ok {
			continue
		}
		ip = ip.Unmap()
		if !ip.IsGlobalUnicast() || ip.IsLinkLocalUnicast() {
			continue
		}
		if ip.IsPrivate() {
			private = append(private, ip)
			continue
		}
		add(ip)
	}
	for _, ip := range private {
		add(ip)
	}
	return out, nil
}

// decode strictly unmarshals params; unknown fields are rejected so typos
// in the panel fail loudly. Missing params mean none.
func decode(p json.RawMessage, v any) error {
	if len(bytes.TrimSpace(p)) == 0 || string(p) == "null" {
		p = json.RawMessage("{}")
	}
	dec := json.NewDecoder(bytes.NewReader(p))
	dec.DisallowUnknownFields()
	if err := dec.Decode(v); err != nil {
		return &rpc.Error{Code: rpc.CodeInvalidParams, Message: "invalid params: " + err.Error()}
	}
	return nil
}

// mapError turns manager errors into JSON-RPC errors the panel can show.
func mapError(err error) error {
	if err == nil {
		return nil
	}
	if errors.Is(err, dns.ErrNotSetUp) {
		return &rpc.Error{Code: CodeDNSNotSetUp, Message: err.Error()}
	}
	if errors.Is(err, mail.ErrNotSetUp) {
		return &rpc.Error{Code: CodeMailNotSetUp, Message: err.Error()}
	}
	if errors.Is(err, database.ErrNotInstalled) {
		return &rpc.Error{Code: CodeDatabaseNotInstalled, Message: err.Error()}
	}
	if errors.Is(err, database.ErrUpgrading) {
		return &rpc.Error{Code: CodeDatabaseUpgrading, Message: err.Error()}
	}
	var inUse *component.InUseError
	if errors.As(err, &inUse) {
		return &rpc.Error{Code: CodeComponentInUse, Message: err.Error()}
	}
	if errors.Is(err, component.ErrBusy) {
		return &rpc.Error{Code: CodeComponentBusy, Message: err.Error()}
	}
	if errors.Is(err, backup.ErrBusy) {
		return &rpc.Error{Code: CodeBackupBusy, Message: err.Error()}
	}
	var fe *files.Error
	if errors.As(err, &fe) {
		return &rpc.Error{Code: CodeFileError, Message: fe.Error()}
	}
	if errors.Is(err, security.ErrWAFNotInstalled) {
		return &rpc.Error{Code: CodeWAFNotInstalled, Message: err.Error()}
	}
	if errors.Is(err, update.ErrBusy) {
		return &rpc.Error{Code: CodeUpdateBusy, Message: err.Error()}
	}
	var ue *cert.UnreachableError
	if errors.As(err, &ue) {
		return &rpc.Error{Code: CodeCAUnreachable, Message: err.Error()}
	}
	var ce *site.ConfigError
	if errors.As(err, &ce) {
		return &rpc.Error{Code: CodeConfigRejected, Message: ce.Error()}
	}
	var v *site.ValidationError
	var dv *dns.ValidationError
	var cv *cert.ValidationError
	var mv *mail.ValidationError
	var lv *laravel.ValidationError
	var fv *firewall.ValidationError
	var monv *monitor.ValidationError
	var dbv *database.ValidationError
	var compv *component.ValidationError
	var bv *backup.ValidationError
	var uv *update.ValidationError
	var ftpv *ftp.ValidationError
	var secv *security.ValidationError
	var svcv *service.ValidationError
	var phpv *php.ValidationError
	var cronv *cron.ValidationError
	var sysv *system.ValidationError
	var logv *logrotate.ValidationError
	var qv *quota.ValidationError
	if errors.As(err, &qv) || errors.As(err, &logv) || errors.As(err, &cronv) || errors.As(err, &sysv) || errors.As(err, &svcv) || errors.As(err, &phpv) || errors.As(err, &secv) || errors.As(err, &ftpv) || errors.As(err, &uv) || errors.As(err, &v) || errors.As(err, &dv) || errors.As(err, &cv) || errors.As(err, &mv) || errors.As(err, &lv) || errors.As(err, &fv) || errors.As(err, &monv) || errors.As(err, &dbv) || errors.As(err, &compv) || errors.As(err, &bv) {
		return &rpc.Error{Code: rpc.CodeInvalidParams, Message: err.Error()}
	}
	var op *site.OperationError
	var dop *dns.OperationError
	var cop *cert.OperationError
	var mop *mail.OperationError
	var lop *laravel.OperationError
	var fop *firewall.OperationError
	var monop *monitor.OperationError
	var dbop *database.OperationError
	var compop *component.OperationError
	var bop *backup.OperationError
	var uop *update.OperationError
	var ftpop *ftp.OperationError
	var secop *security.OperationError
	var svcop *service.OperationError
	var phpop *php.OperationError
	var cronop *cron.OperationError
	var sysop *system.OperationError
	var logop *logrotate.OperationError
	var qop *quota.OperationError
	if errors.As(err, &qop) || errors.As(err, &logop) || errors.As(err, &cronop) || errors.As(err, &sysop) || errors.As(err, &svcop) || errors.As(err, &phpop) || errors.As(err, &secop) || errors.As(err, &ftpop) || errors.As(err, &uop) || errors.As(err, &op) || errors.As(err, &dop) || errors.As(err, &cop) || errors.As(err, &mop) || errors.As(err, &lop) || errors.As(err, &fop) || errors.As(err, &monop) || errors.As(err, &dbop) || errors.As(err, &compop) || errors.As(err, &bop) {
		return &rpc.Error{Code: rpc.CodeOperationFailed, Message: err.Error()}
	}
	return err
}

// syncLogRotation adds a new site's logs to the rotation rules, or takes a
// removed site's out. The site's own change stands even if this fails.
func syncLogRotation(ctx context.Context, svc services) {
	if err := svc.logs.Sync(context.WithoutCancel(ctx)); err != nil && svc.log != nil {
		svc.log.Error("writing log rotation rules", "err", err)
	}
}

// handle registers a method whose params decode into P.
func handle[P any](srv *rpc.Server, method string, f func(context.Context, P) (any, error)) {
	srv.Handle(method, func(ctx context.Context, raw json.RawMessage) (any, error) {
		var p P
		if err := decode(raw, &p); err != nil {
			return nil, err
		}
		res, err := f(ctx, p)
		if err != nil {
			return nil, mapError(err)
		}
		return res, nil
	})
}

func registerLaravel(srv *rpc.Server, svc services) {
	l := svc.laravel
	handle(srv, "laravel.tools", func(ctx context.Context, q struct {
		PHPVersion string `json:"php_version"`
	}) (any, error) {
		return l.Tools(ctx, q.PHPVersion)
	})
	handle(srv, "laravel.deploy", func(ctx context.Context, p laravel.DeploySpec) (any, error) { return l.Deploy(ctx, p) })
	handle(srv, "laravel.rollback", func(ctx context.Context, p laravel.RollbackSpec) (any, error) { return l.Rollback(ctx, p) })
	handle(srv, "laravel.run", func(ctx context.Context, p laravel.RunSpec) (any, error) { return l.Run(ctx, p) })
	handle(srv, "laravel.run.read", func(_ context.Context, q struct {
		Domain string `json:"domain"`
		Run    string `json:"run"`
		Offset int64  `json:"offset"`
	}) (any, error) {
		return l.ReadRun(q.Domain, q.Run, q.Offset)
	})
	handle(srv, "laravel.status", func(ctx context.Context, p laravel.App) (any, error) { return l.Status(ctx, p) })
	handle(srv, "laravel.env.get", func(ctx context.Context, p laravel.App) (any, error) { return l.GetEnv(ctx, p) })
	handle(srv, "laravel.env.set", func(ctx context.Context, p laravel.EnvSpec) (any, error) { return l.SetEnv(ctx, p) })
	handle(srv, "laravel.queue.apply", func(ctx context.Context, p laravel.QueueSpec) (any, error) { return l.ApplyQueues(ctx, p) })
	handle(srv, "laravel.queue.restart", func(ctx context.Context, p laravel.Target) (any, error) { return l.RestartQueues(ctx, p) })
	handle(srv, "laravel.schedule.apply", func(ctx context.Context, p laravel.ScheduleSpec) (any, error) { return l.ApplySchedule(ctx, p) })
	handle(srv, "laravel.maintenance", func(ctx context.Context, p laravel.MaintenanceSpec) (any, error) { return l.Maintenance(ctx, p) })
	handle(srv, "laravel.logs", func(ctx context.Context, p laravel.LogsSpec) (any, error) { return l.Logs(ctx, p) })
	handle(srv, "laravel.probe", func(ctx context.Context, p laravel.ProbeSpec) (any, error) { return l.Probe(ctx, p) })
	handle(srv, "laravel.scan", func(ctx context.Context, q struct {
		Sites []laravel.ScanSite `json:"sites"`
	}) (any, error) {
		return l.Scan(ctx, q.Sites)
	})
	handle(srv, "laravel.key", func(ctx context.Context, p laravel.Target) (any, error) {
		key, err := l.DeployKey(ctx, p)
		return map[string]string{"public_key": key}, err
	})
	handle(srv, "laravel.health", func(ctx context.Context, q struct {
		Domain string `json:"domain"`
		Path   string `json:"path"`
	}) (any, error) {
		return l.CheckHealth(ctx, q.Domain, q.Path)
	})
	handle(srv, "laravel.remove", func(ctx context.Context, p laravel.RemoveSpec) (any, error) { return l.Remove(ctx, p) })

	// Git deploys of plain sites share the Laravel toolkit's helpers.
	handle(srv, "site.git.deploy", func(ctx context.Context, p laravel.GitSpec) (any, error) { return l.GitDeploy(ctx, p) })
	handle(srv, "site.git.key", func(ctx context.Context, p laravel.Target) (any, error) {
		key, err := l.GitKey(ctx, p)
		return map[string]string{"public_key": key}, err
	})
	handle(srv, "site.git.remove", func(ctx context.Context, p laravel.Target) (any, error) { return l.GitRemove(ctx, p) })
	handle(srv, "site.git.read", func(_ context.Context, q struct {
		Domain string `json:"domain"`
		Run    string `json:"run"`
		Offset int64  `json:"offset"`
	}) (any, error) {
		return l.ReadGitRun(q.Domain, q.Run, q.Offset)
	})

	handle(srv, "laravel.process.apply", func(ctx context.Context, p laravel.ProcessSpec) (any, error) { return l.ApplyProcesses(ctx, p) })
	handle(srv, "laravel.process.restart", func(ctx context.Context, p laravel.ProcessAction) (any, error) { return l.RestartProcess(ctx, p) })
	handle(srv, "laravel.process.log", func(_ context.Context, q struct {
		Domain string `json:"domain"`
		Name   string `json:"name"`
		Offset int64  `json:"offset"`
	}) (any, error) {
		return l.ReadProcessLog(laravel.ProcessAction{Domain: q.Domain, Name: q.Name}, q.Offset)
	})

	// Tinker sessions outlive the request that starts them.
	handle(srv, "laravel.tinker.start", func(ctx context.Context, p laravel.TinkerSpec) (any, error) {
		return l.StartTinker(context.WithoutCancel(ctx), p)
	})
	handle(srv, "laravel.tinker.send", func(_ context.Context, p laravel.TinkerInput) (any, error) { return l.TinkerSend(p) })
	handle(srv, "laravel.tinker.stop", func(_ context.Context, p laravel.TinkerInput) (any, error) { return l.StopTinker(p) })
	handle(srv, "laravel.tinker.status", func(_ context.Context, q struct {
		Domain  string `json:"domain"`
		Session string `json:"session"`
	}) (any, error) {
		return laravel.TinkerState{Running: l.TinkerRunning(q.Domain, q.Session)}, nil
	})

	handle(srv, "laravel.backup.create", func(ctx context.Context, p laravel.BackupSpec) (any, error) { return l.Backup(ctx, p) })
	handle(srv, "laravel.backup.restore", func(ctx context.Context, p laravel.RestoreSpec) (any, error) { return l.Restore(ctx, p) })
	handle(srv, "laravel.backup.delete", func(_ context.Context, p laravel.BackupRef) (any, error) { return l.DeleteBackup(p) })
	handle(srv, "laravel.backup.read", func(_ context.Context, q struct {
		Domain string `json:"domain"`
		ID     string `json:"id"`
		Offset int64  `json:"offset"`
	}) (any, error) {
		return l.ReadBackup(laravel.BackupRef{Domain: q.Domain, ID: q.ID}, q.Offset)
	})
}

type domainParam struct {
	Domain string `json:"domain"`
}

func registerMonitor(srv *rpc.Server, svc services) {
	mon, fw := svc.monitor, svc.firewall
	handle(srv, "monitor.live", func(_ context.Context, p monitor.SnapshotSpec) (any, error) { return mon.Snapshot(p), nil })
	handle(srv, "monitor.limit.apply", func(ctx context.Context, p monitor.LimitSpec) (any, error) { return mon.ApplyLimit(ctx, p) })
	handle(srv, "monitor.limit.remove", func(ctx context.Context, p domainParam) (any, error) {
		removed, err := mon.RemoveLimit(ctx, p.Domain)
		return map[string]bool{"removed": removed}, err
	})
	handle(srv, "monitor.limits", func(context.Context, struct{}) (any, error) { return mon.Limits(), nil })
	handle(srv, "stats.collect", func(_ context.Context, p stats.CollectSpec) (any, error) { return svc.stats.Collect(p), nil })
	handle(srv, "php.restart", func(ctx context.Context, p domainParam) (any, error) {
		n, err := mon.RestartPool(ctx, p.Domain)
		return map[string]int{"stopped": n}, err
	})

	handle(srv, "firewall.block", func(ctx context.Context, p firewall.Spec) (any, error) { return fw.Block(ctx, p) })
	handle(srv, "firewall.unblock", func(ctx context.Context, p struct {
		Address string `json:"address"`
	}) (any, error) {
		removed, err := fw.Unblock(ctx, p.Address)
		return map[string]bool{"removed": removed}, err
	})
	handle(srv, "firewall.list", func(context.Context, struct{}) (any, error) { return fw.List() })

	handle(srv, "mail.queue", func(ctx context.Context, _ struct{}) (any, error) { return svc.mail.Queue(ctx) })
	handle(srv, "mail.queue.flush", func(ctx context.Context, _ struct{}) (any, error) {
		return map[string]bool{"flushed": true}, svc.mail.FlushQueue(ctx)
	})
	handle(srv, "mail.queue.hold", func(ctx context.Context, p domainParam) (any, error) {
		n, err := svc.mail.HoldDomain(ctx, p.Domain)
		return map[string]int{"messages": n}, err
	})
	handle(srv, "mail.queue.release", func(ctx context.Context, p domainParam) (any, error) {
		n, err := svc.mail.ReleaseDomain(ctx, p.Domain)
		return map[string]int{"messages": n}, err
	})
}

func registerDatabases(srv *rpc.Server, svc services) {
	db := svc.databases
	handle(srv, "db.status", func(ctx context.Context, _ struct{}) (any, error) { return db.Status(ctx), nil })
	// Installing packages outlives a panel request that gives up.
	handle(srv, "db.server.install", func(ctx context.Context, p database.InstallSpec) (any, error) {
		return db.Install(context.WithoutCancel(ctx), p)
	})
	handle(srv, "db.manager.setup", func(ctx context.Context, p database.ManagerSpec) (any, error) {
		return db.SetupManager(context.WithoutCancel(ctx), p)
	})
	handle(srv, "db.manager.login", func(ctx context.Context, p database.LoginSpec) (any, error) { return db.Login(ctx, p) })
	handle(srv, "db.database.create", func(ctx context.Context, p database.DatabaseSpec) (any, error) { return db.CreateDatabase(ctx, p) })
	handle(srv, "db.database.delete", func(ctx context.Context, p database.DeleteSpec) (any, error) { return db.DeleteDatabase(ctx, p) })
	handle(srv, "db.user.apply", func(ctx context.Context, p database.UserSpec) (any, error) { return db.ApplyUser(ctx, p) })
	handle(srv, "db.user.delete", func(ctx context.Context, p database.UserRef) (any, error) { return db.DeleteUser(ctx, p) })
	handle(srv, "db.list", func(ctx context.Context, p struct {
		Server string `json:"server"`
	}) (any, error) {
		return db.List(ctx, p.Server)
	})
}

func registerComponents(srv *rpc.Server, svc services) {
	c := svc.components
	handle(srv, "component.list", func(ctx context.Context, _ struct{}) (any, error) { return c.List(ctx), nil })
	// Package operations outlive a panel request that gives up.
	handle(srv, "component.check", func(ctx context.Context, _ struct{}) (any, error) {
		return c.Check(context.WithoutCancel(ctx))
	})
	handle(srv, "component.install", func(ctx context.Context, p component.OpSpec) (any, error) {
		return c.Install(context.WithoutCancel(ctx), p)
	})
	handle(srv, "component.remove", func(ctx context.Context, p component.OpSpec) (any, error) {
		return c.Remove(context.WithoutCancel(ctx), p)
	})
	handle(srv, "component.update", func(ctx context.Context, p component.OpSpec) (any, error) {
		return c.Update(context.WithoutCancel(ctx), p)
	})
	handle(srv, "component.upgrade", func(ctx context.Context, p component.OpSpec) (any, error) {
		return c.Upgrade(context.WithoutCancel(ctx), p)
	})
	handle(srv, "component.remove-old", func(ctx context.Context, p component.OpSpec) (any, error) {
		return c.RemoveOld(context.WithoutCancel(ctx), p)
	})
	handle(srv, "component.configure", func(ctx context.Context, p component.ConfigureSpec) (any, error) {
		return c.Configure(context.WithoutCancel(ctx), p)
	})
	handle(srv, "component.run.read", func(_ context.Context, q struct {
		Run    string `json:"run"`
		Offset int64  `json:"offset"`
	}) (any, error) {
		return c.ReadRun(q.Run, q.Offset)
	})
	handle(srv, "redis.apply", func(ctx context.Context, p component.RedisSpec) (any, error) { return c.ApplyRedis(ctx, p) })
	handle(srv, "redis.remove", func(ctx context.Context, p domainParam) (any, error) {
		removed, err := c.RemoveRedis(ctx, p.Domain)
		return map[string]bool{"removed": removed}, err
	})
}

func registerBackups(srv *rpc.Server, svc services) {
	b := svc.backups
	handle(srv, "backup.status", func(context.Context, struct{}) (any, error) { return b.Status() })
	// Backups, restores and transfers outlive a panel request that gives up.
	handle(srv, "backup.create", func(ctx context.Context, p backup.CreateSpec) (any, error) {
		return b.Create(context.WithoutCancel(ctx), p)
	})
	handle(srv, "backup.restore", func(ctx context.Context, p backup.RestoreSpec) (any, error) {
		return b.Restore(context.WithoutCancel(ctx), p)
	})
	handle(srv, "backup.fetch", func(ctx context.Context, p backup.FetchSpec) (any, error) {
		return b.Fetch(context.WithoutCancel(ctx), p)
	})
	handle(srv, "backup.import", func(ctx context.Context, p backup.ImportSpec) (any, error) {
		return b.Import(context.WithoutCancel(ctx), p)
	})
	handle(srv, "backup.delete", func(ctx context.Context, p backup.DeleteSpec) (any, error) {
		return b.Delete(context.WithoutCancel(ctx), p)
	})
	handle(srv, "backup.target.test", func(ctx context.Context, p backup.TargetSpec) (any, error) { return b.TestTarget(ctx, p) })
	handle(srv, "backup.scan", func(ctx context.Context, q struct {
		Target *backup.TargetSpec `json:"target"`
	}) (any, error) {
		return b.Scan(ctx, q.Target)
	})
	handle(srv, "backup.inspect", func(_ context.Context, q struct {
		Key  string `json:"key"`
		Name string `json:"name"`
	}) (any, error) {
		return b.Inspect(q.Key, q.Name)
	})
	handle(srv, "backup.read", func(_ context.Context, q struct {
		Key    string `json:"key"`
		Name   string `json:"name"`
		Offset int64  `json:"offset"`
	}) (any, error) {
		return b.Read(q.Key, q.Name, q.Offset)
	})
	handle(srv, "backup.member.read", func(_ context.Context, q struct {
		Key    string `json:"key"`
		Name   string `json:"name"`
		Member string `json:"member"`
		Offset int64  `json:"offset"`
	}) (any, error) {
		return b.ReadMember(q.Key, q.Name, q.Member, q.Offset)
	})
	handle(srv, "backup.upload.write", func(_ context.Context, q struct {
		Upload string `json:"upload"`
		Offset int64  `json:"offset"`
		Data   []byte `json:"data"`
	}) (any, error) {
		return b.UploadWrite(q.Upload, q.Offset, q.Data)
	})
	// Accounts moved from other panels (cPanel).
	handle(srv, "migrate.scan", func(context.Context, struct{}) (any, error) { return b.MigrateScan() })
	handle(srv, "migrate.inspect", func(ctx context.Context, p backup.MigrateSource) (any, error) {
		return b.MigrateInspect(context.WithoutCancel(ctx), p)
	})
	handle(srv, "migrate.convert", func(ctx context.Context, p backup.MigrateSpec) (any, error) {
		return b.MigrateConvert(context.WithoutCancel(ctx), p)
	})
	handle(srv, "migrate.discard", func(_ context.Context, p backup.MigrateSource) (any, error) {
		return map[string]bool{"discarded": true}, b.MigrateDiscard(p)
	})
	handle(srv, "backup.run.read", func(_ context.Context, q struct {
		Run    string `json:"run"`
		Offset int64  `json:"offset"`
	}) (any, error) {
		return b.ReadRun(q.Run, q.Offset)
	})
}

func registerUpdates(srv *rpc.Server, svc services) {
	u := svc.updates
	handle(srv, "update.status", func(context.Context, struct{}) (any, error) { return u.Status() })
	handle(srv, "update.check", func(ctx context.Context, p update.CheckSpec) (any, error) { return u.Check(ctx, p) })
	handle(srv, "update.start", func(ctx context.Context, p update.StartSpec) (any, error) {
		return u.Start(context.WithoutCancel(ctx), p)
	})
	handle(srv, "update.rollback", func(ctx context.Context, p update.RollbackSpec) (any, error) {
		return u.Rollback(context.WithoutCancel(ctx), p)
	})
	handle(srv, "update.run.read", func(_ context.Context, q struct {
		Run    string `json:"run"`
		Offset int64  `json:"offset"`
	}) (any, error) {
		return u.ReadRun(q.Run, q.Offset)
	})
	// Installing packages for serfex-php outlives a request that gives up.
	handle(srv, "panel.setup", func(ctx context.Context, _ struct{}) (any, error) {
		return u.Setup(context.WithoutCancel(ctx))
	})
}

func registerFiles(srv *rpc.Server, svc services) {
	for _, op := range files.Ops {
		handle(srv, "files."+op, func(ctx context.Context, p files.Spec) (any, error) {
			p.Op = op
			return svc.files.Do(ctx, p)
		})
	}
	f := svc.ftp
	srv.Handle("ftp.status", func(ctx context.Context, _ json.RawMessage) (any, error) { return f.Status(ctx), nil })
	handle(srv, "ftp.account.apply", func(ctx context.Context, p ftp.AccountSpec) (any, error) { return f.Apply(ctx, p) })
	handle(srv, "ftp.account.delete", func(ctx context.Context, q struct {
		Name string `json:"name"`
	}) (any, error) {
		return map[string]bool{"deleted": true}, f.Delete(ctx, q.Name)
	})
	srv.Handle("ftp.account.list", func(context.Context, json.RawMessage) (any, error) {
		l, err := f.List()
		return l, mapError(err)
	})
	handle(srv, "ssh.user.apply", func(ctx context.Context, p ftp.UserSpec) (any, error) { return f.ApplyUser(ctx, p) })
}

func registerSecurity(srv *rpc.Server, svc services) {
	sec, fw, c := svc.security, svc.firewall, svc.components
	handle(srv, "waf.site.apply", func(ctx context.Context, p security.WAFSpec) (any, error) { return sec.ApplyWAF(ctx, p) })
	handle(srv, "waf.events", func(_ context.Context, p security.EventsSpec) (any, error) { return sec.WAFEvents(p) })
	handle(srv, "web.protect.apply", func(ctx context.Context, p security.ProtectSpec) (any, error) { return sec.ApplyProtect(ctx, p) })
	handle(srv, "web.deny.apply", func(ctx context.Context, p security.DenySpec) (any, error) { return sec.ApplyDeny(ctx, p) })
	// A whole-server scan outlives a panel request that gives up.
	handle(srv, "scan.run", func(ctx context.Context, p security.ScanSpec) (any, error) {
		return sec.Scan(context.WithoutCancel(ctx), p)
	})
	handle(srv, "scan.files", func(ctx context.Context, p security.FileSpec) (any, error) { return sec.ScanFiles(ctx, p) })
	handle(srv, "scan.fix", func(ctx context.Context, p security.FixSpec) (any, error) { return sec.Fix(ctx, p) })
	handle(srv, "ssh.status", func(ctx context.Context, _ struct{}) (any, error) { return sec.SSH(ctx) })
	handle(srv, "ssh.apply", func(ctx context.Context, p security.SSHSettings) (any, error) { return sec.ApplySSH(ctx, p) })

	handle(srv, "firewall.rules", func(ctx context.Context, _ struct{}) (any, error) { return fw.Rules(ctx) })
	handle(srv, "firewall.rules.apply", func(ctx context.Context, p firewall.RulesSpec) (any, error) { return fw.ApplyRules(ctx, p) })

	handle(srv, "fail2ban.status", func(ctx context.Context, _ struct{}) (any, error) { return c.Fail2ban(ctx) })
	handle(srv, "fail2ban.refresh", func(ctx context.Context, _ struct{}) (any, error) {
		changed, err := c.Fail2banRefresh(ctx)
		return map[string]bool{"changed": changed}, err
	})
	handle(srv, "fail2ban.unban", func(ctx context.Context, p component.BanSpec) (any, error) {
		return map[string]bool{"unbanned": true}, c.Fail2banUnban(ctx, p)
	})
	handle(srv, "fail2ban.ban", func(ctx context.Context, p component.BanSpec) (any, error) {
		return map[string]bool{"banned": true}, c.Fail2banBan(ctx, p)
	})
}

func registerServices(srv *rpc.Server, svc services) {
	s, p, c := svc.services, svc.php, svc.components
	handle(srv, "service.list", func(ctx context.Context, _ struct{}) (any, error) {
		list, err := s.List(ctx)
		return map[string]any{"services": list}, err
	})
	handle(srv, "service.action", func(ctx context.Context, q service.ActionSpec) (any, error) { return s.Do(ctx, q) })
	handle(srv, "service.logs", func(ctx context.Context, q service.LogsSpec) (any, error) { return s.Logs(ctx, q) })
	handle(srv, "service.settings", func(ctx context.Context, q struct {
		Unit string `json:"unit"`
	}) (any, error) {
		return s.Settings(ctx, q.Unit)
	})
	// A database server restarts with its new settings, which can take
	// longer than a panel request waits.
	handle(srv, "service.settings.apply", func(ctx context.Context, q service.SettingsSpec) (any, error) {
		return s.ApplySettings(context.WithoutCancel(ctx), q)
	})

	handle(srv, "php.info", func(_ context.Context, q struct {
		Version string `json:"version"`
	}) (any, error) {
		return p.Info(q.Version)
	})
	handle(srv, "php.settings.apply", func(ctx context.Context, q php.SettingsSpec) (any, error) { return p.Apply(ctx, q) })
	handle(srv, "php.extension.set", func(ctx context.Context, q php.ExtensionSpec) (any, error) { return p.SetExtension(ctx, q) })
	// Package installs outlive a panel request that gives up.
	handle(srv, "component.extension", func(ctx context.Context, q component.ExtensionSpec) (any, error) {
		return c.Extension(context.WithoutCancel(ctx), q)
	})
}

func registerCron(srv *rpc.Server, svc services) {
	c := svc.cron
	handle(srv, "cron.apply", func(ctx context.Context, p cron.Spec) (any, error) { return c.Apply(ctx, p) })
	handle(srv, "cron.remove", func(ctx context.Context, p domainParam) (any, error) { return c.Remove(ctx, p.Domain) })
	// The run goes on in the background; starting it must not be cut short.
	handle(srv, "cron.run", func(ctx context.Context, p cron.RunSpec) (any, error) {
		return c.Run(context.WithoutCancel(ctx), p)
	})
	handle(srv, "cron.runs", func(ctx context.Context, p cron.RunsSpec) (any, error) { return c.Runs(ctx, p) })
}

// systemInfo is system.info's answer: the machine and its addresses.
type systemInfo struct {
	system.Info
	Addresses map[string][]string `json:"addresses"`
}

func registerSettings(srv *rpc.Server, svc services) {
	s, db := svc.system, svc.databases
	handle(srv, "system.info", func(context.Context, struct{}) (any, error) {
		addrs, err := addresses(svc.dryRun)
		if err != nil {
			return nil, err
		}
		return systemInfo{Info: s.Info(), Addresses: addrs}, nil
	})
	handle(srv, "system.hostname.set", func(ctx context.Context, p system.HostnameSpec) (any, error) { return s.SetHostname(ctx, p) })
	handle(srv, "system.timezone.set", func(ctx context.Context, p system.TimezoneSpec) (any, error) { return s.SetTimezone(ctx, p) })
	handle(srv, "system.timezones", func(context.Context, struct{}) (any, error) { return s.Timezones() })
	handle(srv, "logs.rotation.get", func(context.Context, struct{}) (any, error) { return svc.logs.Settings(), nil })
	handle(srv, "logs.rotation.set", func(ctx context.Context, p logrotate.Settings) (any, error) { return svc.logs.Set(ctx, p) })
	handle(srv, "quota.status", func(ctx context.Context, _ struct{}) (any, error) { return svc.quotas.Status(ctx) })
	// Installing packages and counting every file outlive a panel request
	// that gives up.
	handle(srv, "quota.enable", func(ctx context.Context, _ struct{}) (any, error) {
		return svc.quotas.Enable(context.WithoutCancel(ctx))
	})
	handle(srv, "quota.report", func(ctx context.Context, p quota.UsersSpec) (any, error) { return svc.quotas.Report(ctx, p) })
	handle(srv, "quota.set", func(ctx context.Context, p quota.LimitsSpec) (any, error) { return svc.quotas.Set(ctx, p) })

	handle(srv, "panel.web.status", func(context.Context, struct{}) (any, error) { return db.WebPortsStatus() })
	// Reconfiguring serfex-web (maybe installing packages) outlives a
	// panel request that gives up.
	handle(srv, "panel.web.ports", func(ctx context.Context, p database.WebPortsSpec) (any, error) {
		return db.SetWebPorts(context.WithoutCancel(ctx), p)
	})
	handle(srv, "ssl.panel.install", func(ctx context.Context, p cert.PanelInstallSpec) (any, error) { return svc.certs.InstallPanel(ctx, p) })
}
