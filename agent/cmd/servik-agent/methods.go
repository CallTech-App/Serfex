package main

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"net"
	"net/netip"
	"runtime"

	"github.com/winchester37/servik/agent/internal/dns"
	"github.com/winchester37/servik/agent/internal/rpc"
	"github.com/winchester37/servik/agent/internal/site"
	"github.com/winchester37/servik/agent/internal/version"
)

// CodeDNSNotSetUp tells the panel to offer DNS server setup.
const CodeDNSNotSetUp = -32001

// services are the managers the methods act on.
type services struct {
	sites  *site.Manager
	dns    *dns.Manager
	dryRun bool
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
}

// addresses lists the server's public IP addresses, which the panel uses
// for the A and AAAA records of new zones. Dry runs return documentation
// addresses so tests are deterministic.
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
		if ip.Is4() {
			out["ipv4"] = append(out["ipv4"], ip.String())
		} else {
			out["ipv6"] = append(out["ipv6"], ip.String())
		}
	}
	return out, nil
}

// decode strictly unmarshals params; unknown fields are rejected so typos
// in the panel fail loudly.
func decode(p json.RawMessage, v any) error {
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
	var v *site.ValidationError
	var dv *dns.ValidationError
	if errors.As(err, &v) || errors.As(err, &dv) {
		return &rpc.Error{Code: rpc.CodeInvalidParams, Message: err.Error()}
	}
	var op *site.OperationError
	var dop *dns.OperationError
	if errors.As(err, &op) || errors.As(err, &dop) {
		return &rpc.Error{Code: rpc.CodeOperationFailed, Message: err.Error()}
	}
	return err
}
