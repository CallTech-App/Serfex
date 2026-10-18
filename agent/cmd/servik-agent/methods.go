package main

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"runtime"

	"github.com/winchester37/servik/agent/internal/rpc"
	"github.com/winchester37/servik/agent/internal/site"
	"github.com/winchester37/servik/agent/internal/version"
)

func registerMethods(srv *rpc.Server, sites *site.Manager, dryRun bool) {
	srv.Handle("system.ping", func(context.Context, json.RawMessage) (any, error) {
		return "pong", nil
	})
	srv.Handle("agent.info", func(context.Context, json.RawMessage) (any, error) {
		return map[string]any{
			"version": version.Version,
			"go":      runtime.Version(),
			"os":      runtime.GOOS,
			"arch":    runtime.GOARCH,
			"dry_run": dryRun,
		}, nil
	})
	srv.Handle("php.versions", func(context.Context, json.RawMessage) (any, error) {
		v, err := sites.PHPVersions()
		return v, mapError(err)
	})
	srv.Handle("site.apply", func(ctx context.Context, p json.RawMessage) (any, error) {
		var spec site.Spec
		if err := decode(p, &spec); err != nil {
			return nil, err
		}
		res, err := sites.Apply(ctx, spec)
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
		res, err := sites.Delete(ctx, spec)
		if err != nil {
			return nil, mapError(err)
		}
		return res, nil
	})
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

// mapError turns site errors into JSON-RPC errors the panel can show.
func mapError(err error) error {
	if err == nil {
		return nil
	}
	var v *site.ValidationError
	if errors.As(err, &v) {
		return &rpc.Error{Code: rpc.CodeInvalidParams, Message: v.Error()}
	}
	var op *site.OperationError
	if errors.As(err, &op) {
		return &rpc.Error{Code: rpc.CodeOperationFailed, Message: op.Error()}
	}
	return err
}
