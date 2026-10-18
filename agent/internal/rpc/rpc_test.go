package rpc

import (
	"bufio"
	"context"
	"encoding/json"
	"net"
	"path/filepath"
	"testing"
)

func startServer(t *testing.T) string {
	t.Helper()
	sock := filepath.Join(t.TempDir(), "agent.sock")
	ln, err := net.Listen("unix", sock)
	if err != nil {
		t.Fatal(err)
	}
	s := NewServer(nil)
	s.Handle("echo", func(_ context.Context, p json.RawMessage) (any, error) {
		return p, nil
	})
	s.Handle("fail", func(context.Context, json.RawMessage) (any, error) {
		return nil, &Error{Code: CodeInvalidParams, Message: "bad params"}
	})
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() {
		defer close(done)
		_ = s.Serve(ctx, ln)
	}()
	t.Cleanup(func() {
		cancel()
		<-done
	})
	return sock
}

func call(t *testing.T, sock, line string) Response {
	t.Helper()
	conn, err := net.Dial("unix", sock)
	if err != nil {
		t.Fatal(err)
	}
	defer conn.Close()
	if _, err := conn.Write([]byte(line + "\n")); err != nil {
		t.Fatal(err)
	}
	var resp Response
	r := bufio.NewReader(conn)
	b, err := r.ReadBytes('\n')
	if err != nil {
		t.Fatal(err)
	}
	if err := json.Unmarshal(b, &resp); err != nil {
		t.Fatal(err)
	}
	return resp
}

func TestDispatch(t *testing.T) {
	sock := startServer(t)

	tests := []struct {
		name     string
		req      string
		wantCode int
	}{
		{"ok", `{"jsonrpc":"2.0","id":1,"method":"echo","params":{"a":1}}`, 0},
		{"parse error", `{not json`, CodeParseError},
		{"wrong version", `{"jsonrpc":"1.0","id":1,"method":"echo"}`, CodeInvalidRequest},
		{"unknown method", `{"jsonrpc":"2.0","id":1,"method":"nope"}`, CodeMethodNotFound},
		{"handler error", `{"jsonrpc":"2.0","id":1,"method":"fail"}`, CodeInvalidParams},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			resp := call(t, sock, tt.req)
			if resp.JSONRPC != "2.0" {
				t.Fatalf("jsonrpc = %q", resp.JSONRPC)
			}
			if tt.wantCode == 0 {
				if resp.Error != nil {
					t.Fatalf("unexpected error: %+v", resp.Error)
				}
				return
			}
			if resp.Error == nil || resp.Error.Code != tt.wantCode {
				t.Fatalf("error = %+v, want code %d", resp.Error, tt.wantCode)
			}
		})
	}
}
