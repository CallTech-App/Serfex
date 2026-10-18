package main

import (
	"bufio"
	"context"
	"encoding/json"
	"net"
	"path/filepath"
	"testing"

	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/rpc"
	"github.com/winchester37/servik/agent/internal/site"
)

func TestSiteMethods(t *testing.T) {
	sim, err := host.NewSimulator(t.TempDir(), "8.4")
	if err != nil {
		t.Fatal(err)
	}
	srv := rpc.NewServer(nil)
	registerMethods(srv, site.NewManager(sim, "www-data"), true)

	sock := filepath.Join(t.TempDir(), "agent.sock")
	ln, err := net.Listen("unix", sock)
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() { defer close(done); _ = srv.Serve(ctx, ln) }()
	t.Cleanup(func() { cancel(); <-done })

	call := func(req string) rpc.Response {
		t.Helper()
		conn, err := net.Dial("unix", sock)
		if err != nil {
			t.Fatal(err)
		}
		defer conn.Close()
		conn.Write([]byte(req + "\n"))
		line, err := bufio.NewReader(conn).ReadBytes('\n')
		if err != nil {
			t.Fatal(err)
		}
		var resp rpc.Response
		if err := json.Unmarshal(line, &resp); err != nil {
			t.Fatal(err)
		}
		return resp
	}

	tests := []struct {
		name, req string
		code      int
	}{
		{"apply", `{"jsonrpc":"2.0","id":1,"method":"site.apply","params":{"domain":"a.com","user":"a","php_version":"8.4","aliases":["www.a.com"]}}`, 0},
		{"versions", `{"jsonrpc":"2.0","id":2,"method":"php.versions"}`, 0},
		{"unknown field", `{"jsonrpc":"2.0","id":3,"method":"site.apply","params":{"domian":"a.com"}}`, rpc.CodeInvalidParams},
		{"invalid domain", `{"jsonrpc":"2.0","id":4,"method":"site.apply","params":{"domain":"a","user":"a","php_version":"8.4"}}`, rpc.CodeInvalidParams},
		{"delete", `{"jsonrpc":"2.0","id":5,"method":"site.delete","params":{"domain":"a.com","user":"a","remove_files":true,"remove_user":true}}`, 0},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			resp := call(tt.req)
			if tt.code == 0 && resp.Error != nil {
				t.Fatalf("unexpected error %+v", resp.Error)
			}
			if tt.code != 0 && (resp.Error == nil || resp.Error.Code != tt.code) {
				t.Fatalf("error = %+v, want %d", resp.Error, tt.code)
			}
		})
	}

	sim.Fail = func(c host.Command) error {
		if c.Name == "nginx" {
			return context.DeadlineExceeded
		}
		return nil
	}
	resp := call(`{"jsonrpc":"2.0","id":6,"method":"site.apply","params":{"domain":"b.com","user":"b","php_version":"8.4"}}`)
	if resp.Error == nil || resp.Error.Code != rpc.CodeOperationFailed {
		t.Fatalf("error = %+v, want operation failed", resp.Error)
	}
}
