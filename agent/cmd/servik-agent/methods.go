package main

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"net"
	"net/netip"
	"runtime"
	"time"

	"github.com/winchester37/servik/agent/internal/cert"
	"github.com/winchester37/servik/agent/internal/dns"
	"github.com/winchester37/servik/agent/internal/firewall"
	"github.com/winchester37/servik/agent/internal/laravel"
	"github.com/winchester37/servik/agent/internal/mail"
	"github.com/winchester37/servik/agent/internal/monitor"
	"github.com/winchester37/servik/agent/internal/rpc"
	"github.com/winchester37/servik/agent/internal/site"
	"github.com/winchester37/servik/agent/internal/version"
)

// CodeDNSNotSetUp tells the panel to offer DNS server setup.
const CodeDNSNotSetUp = -32001

// CodeMailNotSetUp tells the panel to offer mail server setup.
const CodeMailNotSetUp = -32002

// services are the managers the methods act on.
type services struct {
	sites    *site.Manager
	dns      *dns.Manager
	certs    *cert.Manager
	mail     *mail.Manager
	laravel  *laravel.Manager
	monitor  *monitor.Monitor
	firewall *firewall.Manager
	dryRun   bool
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
		return res, nil
	})
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
	var v *site.ValidationError
	var dv *dns.ValidationError
	var cv *cert.ValidationError
	var mv *mail.ValidationError
	var lv *laravel.ValidationError
	var fv *firewall.ValidationError
	var monv *monitor.ValidationError
	if errors.As(err, &v) || errors.As(err, &dv) || errors.As(err, &cv) || errors.As(err, &mv) || errors.As(err, &lv) || errors.As(err, &fv) || errors.As(err, &monv) {
		return &rpc.Error{Code: rpc.CodeInvalidParams, Message: err.Error()}
	}
	var op *site.OperationError
	var dop *dns.OperationError
	var cop *cert.OperationError
	var mop *mail.OperationError
	var lop *laravel.OperationError
	var fop *firewall.OperationError
	var monop *monitor.OperationError
	if errors.As(err, &op) || errors.As(err, &dop) || errors.As(err, &cop) || errors.As(err, &mop) || errors.As(err, &lop) || errors.As(err, &fop) || errors.As(err, &monop) {
		return &rpc.Error{Code: rpc.CodeOperationFailed, Message: err.Error()}
	}
	return err
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
