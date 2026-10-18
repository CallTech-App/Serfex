package main

import (
	"bufio"
	"context"
	"encoding/json"
	"net"
	"path/filepath"
	"testing"

	"github.com/winchester37/servik/agent/internal/cert"
	"github.com/winchester37/servik/agent/internal/dns"
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
	dnsm := dns.NewSimulatedManager(sim, dns.NewMemory())
	registerMethods(srv, services{sites: site.NewManager(sim, "www-data"), dns: dnsm, certs: cert.NewManager(sim, dnsm, &cert.Simulated{}), dryRun: true})

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

	const zone = `{"zone":"a.com","soa":{"primary":"ns1.a.com","hostmaster":"hostmaster@a.com","refresh":10800,"retry":3600,"expire":604800,"minimum":3600,"ttl":3600},` +
		`"records":[{"name":"@","type":"NS","ttl":3600,"content":"ns1.a.com."},{"name":"ns1","type":"A","ttl":3600,"content":"203.0.113.10"}]}`
	tests := []struct {
		name, req string
		code      int
	}{
		{"apply", `{"jsonrpc":"2.0","id":1,"method":"site.apply","params":{"domain":"a.com","user":"a","php_version":"8.4","aliases":["www.a.com"]}}`, 0},
		{"versions", `{"jsonrpc":"2.0","id":2,"method":"php.versions"}`, 0},
		{"unknown field", `{"jsonrpc":"2.0","id":3,"method":"site.apply","params":{"domian":"a.com"}}`, rpc.CodeInvalidParams},
		{"invalid domain", `{"jsonrpc":"2.0","id":4,"method":"site.apply","params":{"domain":"a","user":"a","php_version":"8.4"}}`, rpc.CodeInvalidParams},
		{"delete", `{"jsonrpc":"2.0","id":5,"method":"site.delete","params":{"domain":"a.com","user":"a","remove_files":true,"remove_user":true}}`, 0},
		{"addresses", `{"jsonrpc":"2.0","id":10,"method":"system.addresses"}`, 0},
		{"dns status", `{"jsonrpc":"2.0","id":11,"method":"dns.status"}`, 0},
		{"zone before setup", `{"jsonrpc":"2.0","id":12,"method":"dns.zone.apply","params":` + zone + `}`, CodeDNSNotSetUp},
		{"dns setup", `{"jsonrpc":"2.0","id":13,"method":"dns.setup"}`, 0},
		{"zone apply", `{"jsonrpc":"2.0","id":14,"method":"dns.zone.apply","params":` + zone + `}`, 0},
		{"zone get", `{"jsonrpc":"2.0","id":15,"method":"dns.zone.get","params":{"zone":"a.com"}}`, 0},
		{"zone invalid", `{"jsonrpc":"2.0","id":16,"method":"dns.zone.apply","params":{"zone":"a.com","soa":{},"records":[]}}`, rpc.CodeInvalidParams},
		{"ssl issue http", `{"jsonrpc":"2.0","id":20,"method":"ssl.issue","params":{"name":"a.com","names":["a.com","www.a.com"],"email":"admin@a.com"}}`, 0},
		{"ssl issue wildcard", `{"jsonrpc":"2.0","id":21,"method":"ssl.issue","params":{"name":"a.com-wild","names":["a.com","*.a.com"],"challenge":"dns-01","dns_zone":"a.com"}}`, 0},
		{"ssl wildcard over http", `{"jsonrpc":"2.0","id":22,"method":"ssl.issue","params":{"name":"x","names":["*.a.com"]}}`, rpc.CodeInvalidParams},
		{"ssl get", `{"jsonrpc":"2.0","id":23,"method":"ssl.get","params":{"name":"a.com"}}`, 0},
		{"site with tls", `{"jsonrpc":"2.0","id":24,"method":"site.apply","params":{"domain":"a.com","user":"a","php_version":"8.4","aliases":[],"tls":{"certificate":"a.com","redirect":true}}}`, 0},
		{"ssl delete in use", `{"jsonrpc":"2.0","id":25,"method":"ssl.delete","params":{"name":"a.com"}}`, rpc.CodeInvalidParams},
		{"ssl install bad", `{"jsonrpc":"2.0","id":26,"method":"ssl.install","params":{"name":"up","certificate":"x","private_key":"y"}}`, rpc.CodeInvalidParams},
		{"ssl panel", `{"jsonrpc":"2.0","id":27,"method":"ssl.panel.issue","params":{"hostname":"panel.a.com"}}`, 0},
		{"ssl panel get", `{"jsonrpc":"2.0","id":28,"method":"ssl.panel.get"}`, 0},
		{"site delete", `{"jsonrpc":"2.0","id":29,"method":"site.delete","params":{"domain":"a.com","user":"a","remove_files":true,"remove_user":true}}`, 0},
		{"ssl delete", `{"jsonrpc":"2.0","id":30,"method":"ssl.delete","params":{"name":"a.com"}}`, 0},
		{"zone delete", `{"jsonrpc":"2.0","id":17,"method":"dns.zone.delete","params":{"zone":"a.com"}}`, 0},
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
