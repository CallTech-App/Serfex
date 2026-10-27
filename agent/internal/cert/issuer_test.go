package cert

import (
	"context"
	"errors"
	"net"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"golang.org/x/crypto/acme"
)

// flaky fails the first n round trips the way a lookup through a
// resolver that is not answering does.
type flaky struct {
	n     int
	calls int
}

func (f *flaky) RoundTrip(r *http.Request) (*http.Response, error) {
	f.calls++
	if f.calls <= f.n {
		return nil, &net.DNSError{Err: "server misbehaving", Name: r.URL.Hostname(), Server: "127.0.0.53:53"}
	}
	return http.DefaultTransport.RoundTrip(r)
}

func directoryServer(t *testing.T) *httptest.Server {
	t.Helper()
	var srv *httptest.Server
	srv = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"newNonce":"` + srv.URL + `/nonce","newAccount":"` + srv.URL + `/account","newOrder":"` + srv.URL + `/order"}`))
	}))
	t.Cleanup(srv.Close)
	return srv
}

func TestReachRetriesAfterLookupFailure(t *testing.T) {
	srv := directoryServer(t)
	rt := &flaky{n: 2}
	a := &ACME{DirectoryURL: srv.URL, HTTPClient: &http.Client{Transport: rt}, RetryDelays: []time.Duration{time.Millisecond, time.Millisecond, time.Millisecond}}
	c := &acme.Client{DirectoryURL: srv.URL, HTTPClient: a.HTTPClient}
	if err := a.reach(context.Background(), c); err != nil {
		t.Fatal(err)
	}
	if rt.calls != 3 {
		t.Errorf("calls = %d, want 3", rt.calls)
	}
}

func TestReachGivesUpWithUnreachableError(t *testing.T) {
	rt := &flaky{n: 100}
	a := &ACME{DirectoryURL: "https://acme-v02.api.letsencrypt.org/directory", HTTPClient: &http.Client{Transport: rt}, RetryDelays: []time.Duration{time.Millisecond, time.Millisecond}}
	c := &acme.Client{DirectoryURL: a.DirectoryURL, HTTPClient: a.HTTPClient}
	err := a.reach(context.Background(), c)
	var ue *UnreachableError
	if !errors.As(err, &ue) {
		t.Fatalf("err = %v, want UnreachableError", err)
	}
	if rt.calls != 3 {
		t.Errorf("calls = %d, want 3", rt.calls)
	}
	if !strings.Contains(err.Error(), "could not reach the certificate authority (acme-v02.api.letsencrypt.org)") || !strings.Contains(err.Error(), "server misbehaving") {
		t.Errorf("message = %q", err)
	}
	// The manager wraps it; the RPC layer still finds it.
	var op error = &OperationError{err}
	if !errors.As(op, &ue) {
		t.Error("UnreachableError lost behind OperationError")
	}
}

func TestReachDoesNotRetryAnAnswer(t *testing.T) {
	calls := 0
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		calls++
		http.Error(w, `{"type":"urn:ietf:params:acme:error:malformed","detail":"no"}`, http.StatusBadRequest)
	}))
	defer srv.Close()
	a := &ACME{DirectoryURL: srv.URL, HTTPClient: srv.Client(), RetryDelays: []time.Duration{time.Millisecond}}
	c := &acme.Client{DirectoryURL: srv.URL, HTTPClient: a.HTTPClient}
	err := a.reach(context.Background(), c)
	var ue *UnreachableError
	if err == nil || errors.As(err, &ue) {
		t.Fatalf("err = %v, want the server's answer", err)
	}
	if calls != 1 {
		t.Errorf("calls = %d, want 1", calls)
	}
}
