package main

import (
	"bufio"
	"context"
	"encoding/json"
	"fmt"
	"net"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/calltech-app/serfex/agent/internal/cert"
	"github.com/calltech-app/serfex/agent/internal/component"
	"github.com/calltech-app/serfex/agent/internal/cron"
	"github.com/calltech-app/serfex/agent/internal/database"
	"github.com/calltech-app/serfex/agent/internal/dns"
	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/laravel"
	"github.com/calltech-app/serfex/agent/internal/mail"
	"github.com/calltech-app/serfex/agent/internal/rpc"
	"github.com/calltech-app/serfex/agent/internal/site"
	"github.com/calltech-app/serfex/agent/internal/system"
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
	// A configuration test that refuses comes back as such, with its
	// output, so the panel can show it next to the directives.
	resp := call(`{"jsonrpc":"2.0","id":6,"method":"site.apply","params":{"domain":"b.com","user":"b","php_version":"8.4"}}`)
	if resp.Error == nil || resp.Error.Code != CodeConfigRejected || !strings.Contains(resp.Error.Message, "deadline") {
		t.Fatalf("error = %+v, want config rejected", resp.Error)
	}
	sim.Fail = func(c host.Command) error {
		if c.Name == "php-fpm8.4" {
			return context.DeadlineExceeded
		}
		return nil
	}
	resp = call(`{"jsonrpc":"2.0","id":7,"method":"site.apply","params":{"domain":"b.com","user":"b","php_version":"8.4"}}`)
	if resp.Error == nil || resp.Error.Code != rpc.CodeOperationFailed {
		t.Fatalf("error = %+v, want operation failed", resp.Error)
	}
}

func TestDecodeTreatsMissingParamsAsNone(t *testing.T) {
	var v struct {
		Domain string `json:"domain"`
	}
	for _, p := range []string{"", "null", "{}"} {
		if err := decode(json.RawMessage(p), &v); err != nil {
			t.Errorf("decode(%q) = %v", p, err)
		}
	}
	if err := decode(json.RawMessage(`{"typo":1}`), &v); err == nil {
		t.Error("unknown field accepted")
	}
}

func TestSettingsAndCronMethods(t *testing.T) {
	sim, err := host.NewSimulator(t.TempDir(), "8.4")
	if err != nil {
		t.Fatal(err)
	}
	srv := rpc.NewServer(nil)
	dnsm := dns.NewSimulatedManager(sim, dns.NewMemory())
	c := cron.NewManager(sim)
	c.Exe = "/opt/serfex/current/bin/serfex-agent"
	registerMethods(srv, services{
		sites: site.NewManager(sim, "www-data"), dns: dnsm, certs: cert.NewManager(sim, dnsm, &cert.Simulated{}),
		databases: database.NewSimulated(sim), cron: c, system: system.NewManager(sim), dryRun: true,
	})
	sock := filepath.Join(t.TempDir(), "agent.sock")
	ln, err := net.Listen("unix", sock)
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() { defer close(done); _ = srv.Serve(ctx, ln) }()
	t.Cleanup(func() { cancel(); <-done })

	id := 0
	call := func(method, params string, code int) map[string]any {
		t.Helper()
		id++
		conn, err := net.Dial("unix", sock)
		if err != nil {
			t.Fatal(err)
		}
		defer conn.Close()
		fmt.Fprintf(conn, `{"jsonrpc":"2.0","id":%d,"method":%q,"params":%s}`+"\n", id, method, params)
		line, err := bufio.NewReader(conn).ReadBytes('\n')
		if err != nil {
			t.Fatal(err)
		}
		var resp struct {
			Result json.RawMessage `json:"result"`
			Error  *rpc.Error      `json:"error"`
		}
		if err := json.Unmarshal(line, &resp); err != nil {
			t.Fatal(err)
		}
		if code == 0 && resp.Error != nil {
			t.Fatalf("%s: unexpected error %+v", method, resp.Error)
		}
		if code != 0 && (resp.Error == nil || resp.Error.Code != code) {
			t.Fatalf("%s %s: error = %+v, want %d", method, params, resp.Error, code)
		}
		out := map[string]any{}
		if len(resp.Result) > 0 && resp.Result[0] == '[' {
			var list []any
			_ = json.Unmarshal(resp.Result, &list)
			out["list"] = list
		} else {
			_ = json.Unmarshal(resp.Result, &out)
		}
		return out
	}
	want := func(what string, got, wanted any) {
		t.Helper()
		if fmt.Sprint(got) != fmt.Sprint(wanted) {
			t.Errorf("%s = %v, want %v", what, got, wanted)
		}
	}

	// Scheduled tasks.
	call("site.apply", `{"domain":"a.com","user":"a","php_version":"8.4"}`, 0)
	tasks := `{"domain":"a.com","user":"a","php":"8.4","tasks":[{"id":1,"schedule":"*/5 * * * *","command":"php cron.php","enabled":true,"timeout":60},{"id":2,"schedule":"0 3 * * *","command":"echo hi","enabled":false}]}`
	res := call("cron.apply", tasks, 0)
	want("cron.apply", res, map[string]any{"tasks": 2, "enabled": 1})
	call("cron.apply", `{"domain":"a.com","user":"a","tasks":[{"id":1,"schedule":"* * *","command":"x"}]}`, rpc.CodeInvalidParams)
	call("cron.apply", `{"domain":"a.com","user":"a","taks":[]}`, rpc.CodeInvalidParams)
	call("cron.run", `{"domain":"a.com","id":9}`, rpc.CodeInvalidParams)
	want("cron.run", call("cron.run", `{"domain":"a.com","id":2}`, 0), map[string]any{"started": true})
	runs := call("cron.runs", `{"domain":"a.com","id":2,"limit":5}`, 0)
	if list, _ := runs["runs"].([]any); len(list) != 1 || runs["running"] != false {
		t.Fatalf("cron.runs = %v", runs)
	} else if r := list[0].(map[string]any); r["trigger"] != "manual" || r["exit_code"] != float64(0) || r["output"] != cron.SimulatedOutput {
		t.Fatalf("run = %v", r)
	}
	want("cron.remove", call("cron.remove", `{"domain":"a.com"}`, 0), map[string]any{"removed": true})
	call("cron.runs", `{"domain":"a.com","id":2}`, rpc.CodeInvalidParams)

	// The server's settings.
	info := call("system.info", `{}`, 0)
	want("hostname", info["hostname"], "srv-ist-01.serfex.test")
	want("timezone", info["timezone"], "Europe/Istanbul")
	want("cpus", info["cpus"], 8)
	want("addresses", info["addresses"], map[string]any{"ipv4": []any{"203.0.113.10"}, "ipv6": []any{"2001:db8::10"}})
	want("hostname.set", call("system.hostname.set", `{"hostname":"panel.example.com"}`, 0), map[string]any{"hostname": "panel.example.com"})
	call("system.hostname.set", `{"hostname":"bad_name"}`, rpc.CodeInvalidParams)
	want("timezone.set", call("system.timezone.set", `{"timezone":"Europe/Berlin"}`, 0), map[string]any{"timezone": "Europe/Berlin"})
	call("system.timezone.set", `{"timezone":"../../etc/passwd"}`, rpc.CodeInvalidParams)
	info = call("system.info", `{}`, 0)
	want("hostname after set", info["hostname"], "panel.example.com")
	want("timezone after set", info["timezone"], "Europe/Berlin")
	if zones := call("system.timezones", `{}`, 0)["list"].([]any); len(zones) < 6 {
		t.Fatalf("zones %v", zones)
	}

	// The panel's ports and certificate.
	want("panel.web.status", call("panel.web.status", `{}`, 0), map[string]any{"ports": []any{8443}, "port": 8443})
	want("panel.web.ports", call("panel.web.ports", `{"ports":[8443,9443]}`, 0), map[string]any{"ports": []any{8443, 9443}, "port": 8443})
	call("panel.web.ports", `{"ports":[80]}`, rpc.CodeInvalidParams)
	want("panel.web.ports", call("panel.web.ports", `{"ports":[9443]}`, 0), map[string]any{"ports": []any{9443}, "port": 9443})
	call("ssl.panel.install", `{"certificate":"x","private_key":"y"}`, rpc.CodeInvalidParams)
}

// Setting the mail server up adds the mail jails to an installed Fail2ban.
func TestMailSetupRefreshesFail2ban(t *testing.T) {
	sim, err := host.NewSimulator(t.TempDir(), "8.4")
	if err != nil {
		t.Fatal(err)
	}
	ctx := context.Background()
	sites := site.NewManager(sim, "www-data")
	dbs := database.NewSimulated(sim)
	comps := component.NewManager(sim, sites, dbs, laravel.NewManager(sim))
	if _, err := comps.Install(ctx, component.OpSpec{ID: "fail2ban", Run: "f2b"}); err != nil {
		t.Fatal(err)
	}
	srv := rpc.NewServer(nil)
	dnsm := dns.NewSimulatedManager(sim, dns.NewMemory())
	registerMethods(srv, services{
		sites: sites, dns: dnsm, certs: cert.NewManager(sim, dnsm, &cert.Simulated{}),
		mail: mail.NewManager(sim, "www-data"), databases: dbs, components: comps, dryRun: true,
	})
	sock := filepath.Join(t.TempDir(), "agent.sock")
	ln, err := net.Listen("unix", sock)
	if err != nil {
		t.Fatal(err)
	}
	sctx, cancel := context.WithCancel(ctx)
	done := make(chan struct{})
	go func() { defer close(done); _ = srv.Serve(sctx, ln) }()
	t.Cleanup(func() { cancel(); <-done })

	conn, err := net.Dial("unix", sock)
	if err != nil {
		t.Fatal(err)
	}
	defer conn.Close()
	fmt.Fprintln(conn, `{"jsonrpc":"2.0","id":1,"method":"mail.setup","params":{"hostname":"mail.example.net","hourly_limit":500}}`)
	line, err := bufio.NewReader(conn).ReadBytes('\n')
	if err != nil {
		t.Fatal(err)
	}
	var resp rpc.Response
	if err := json.Unmarshal(line, &resp); err != nil || resp.Error != nil {
		t.Fatalf("mail.setup: %s %v", line, err)
	}
	jails, err := os.ReadFile(sim.Path("/etc/fail2ban/jail.d/serfex.conf"))
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(string(jails), "[dovecot]") || !strings.Contains(string(jails), "[postfix-sasl]") {
		t.Fatalf("jails after mail setup:\n%s", jails)
	}
	if !sim.Ran("systemctl try-reload-or-restart fail2ban") {
		t.Fatal("Fail2ban not reloaded")
	}
}

func TestMapErrorMarksUnreachableCA(t *testing.T) {
	err := &cert.OperationError{Err: &cert.UnreachableError{URL: cert.LetsEncrypt, Err: &net.DNSError{Err: "server misbehaving", Name: "acme-v02.api.letsencrypt.org"}}}
	rpcErr, ok := mapError(err).(*rpc.Error)
	if !ok || rpcErr.Code != CodeCAUnreachable {
		t.Fatalf("mapError = %#v", mapError(err))
	}
}
