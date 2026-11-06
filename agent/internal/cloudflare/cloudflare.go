// Package cloudflare publishes DNS-01 answers in a zone whose DNS is at
// Cloudflare, and knows Cloudflare's proxy address ranges.
//
// The panel keeps the Cloudflare API tokens; it passes one with each
// certificate request and the agent never stores it. Only the calls a
// certificate needs are here: adding and removing a TXT record.
package cloudflare

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"net/http"
	"slices"
	"strings"
	"time"
)

// API is Cloudflare's v4 API.
const API = "https://api.cloudflare.com/client/v4"

// Zone says where to publish: a zone at Cloudflare and a token allowed to
// edit its DNS records.
type Zone struct {
	// ZoneID is Cloudflare's id of the zone.
	ZoneID string `json:"zone_id"`
	// Token is an API token with Zone → DNS → Edit on the zone.
	Token string `json:"token"`
	// Nameservers are the zone's Cloudflare name servers. Answers are
	// awaited there before the certificate authority is asked to look.
	Nameservers []string `json:"nameservers"`
}

// Valid reports whether z has what publishing needs.
func (z *Zone) Valid() error {
	if z == nil {
		return fmt.Errorf("no Cloudflare zone")
	}
	if !idRe(z.ZoneID) {
		return fmt.Errorf("Cloudflare zone id %q is not valid", z.ZoneID)
	}
	if z.Token == "" || len(z.Token) > 200 || strings.ContainsAny(z.Token, " \t\r\n") {
		return fmt.Errorf("the Cloudflare API token is missing or not valid")
	}
	for _, ns := range z.Nameservers {
		if ns == "" || len(ns) > 253 || strings.ContainsAny(ns, " /:") {
			return fmt.Errorf("Cloudflare name server %q is not valid", ns)
		}
	}
	return nil
}

func idRe(s string) bool {
	if len(s) != 32 {
		return false
	}
	for _, c := range s {
		if !(c >= '0' && c <= '9' || c >= 'a' && c <= 'f') {
			return false
		}
	}
	return true
}

// Client talks to the API with one token.
type Client struct {
	Token string
	// Base is the API address; tests point it at a fake.
	Base string
	HTTP *http.Client
}

// NewClient returns a client for token.
func NewClient(token string) *Client {
	return &Client{Token: token, Base: API, HTTP: &http.Client{Timeout: 30 * time.Second}}
}

// Error is an error Cloudflare returned.
type Error struct {
	Status  int
	Code    int
	Message string
}

func (e *Error) Error() string {
	if e.Code != 0 {
		return fmt.Sprintf("Cloudflare: %s (%d)", e.Message, e.Code)
	}
	return fmt.Sprintf("Cloudflare: HTTP %d", e.Status)
}

type envelope struct {
	Success bool            `json:"success"`
	Errors  []apiError      `json:"errors"`
	Result  json.RawMessage `json:"result"`
}

type apiError struct {
	Code    int    `json:"code"`
	Message string `json:"message"`
}

func (c *Client) do(ctx context.Context, method, path string, body any, out any) error {
	var r io.Reader
	if body != nil {
		b, err := json.Marshal(body)
		if err != nil {
			return err
		}
		r = bytes.NewReader(b)
	}
	req, err := http.NewRequestWithContext(ctx, method, c.Base+path, r)
	if err != nil {
		return err
	}
	req.Header.Set("Authorization", "Bearer "+c.Token)
	req.Header.Set("Content-Type", "application/json")
	resp, err := c.HTTP.Do(req)
	if err != nil {
		return fmt.Errorf("Cloudflare could not be reached: %w", err)
	}
	defer resp.Body.Close()
	data, _ := io.ReadAll(io.LimitReader(resp.Body, 4<<20))
	var env envelope
	if err := json.Unmarshal(data, &env); err != nil || !env.Success {
		e := &Error{Status: resp.StatusCode}
		if len(env.Errors) > 0 {
			e.Code, e.Message = env.Errors[0].Code, env.Errors[0].Message
		}
		return e
	}
	if out != nil {
		return json.Unmarshal(env.Result, out)
	}
	return nil
}

// AddTXT adds a TXT record and returns its id.
func (c *Client) AddTXT(ctx context.Context, zoneID, name, value string) (string, error) {
	var rec struct {
		ID string `json:"id"`
	}
	err := c.do(ctx, http.MethodPost, "/zones/"+zoneID+"/dns_records", map[string]any{
		"type": "TXT", "name": name, "content": value, "ttl": 60,
		"comment": "Serfex: Let's Encrypt validation, removed after use",
	}, &rec)
	return rec.ID, err
}

// Delete removes a record.
func (c *Client) Delete(ctx context.Context, zoneID, id string) error {
	return c.do(ctx, http.MethodDelete, "/zones/"+zoneID+"/dns_records/"+id, nil, nil)
}

// Lookup asks a name server for TXT values; tests replace it.
type Lookup func(ctx context.Context, server, name string) ([]string, error)

// LookupTXT asks server directly (not through a resolver's cache).
func LookupTXT(ctx context.Context, server, name string) ([]string, error) {
	r := &net.Resolver{PreferGo: true, Dial: func(ctx context.Context, network, _ string) (net.Conn, error) {
		d := net.Dialer{Timeout: 5 * time.Second}
		return d.DialContext(ctx, network, net.JoinHostPort(server, "53"))
	}}
	return r.LookupTXT(ctx, name)
}

// Await waits until every name server answers name with all of values,
// for at most limit. It does not fail: the certificate authority's own
// lookup decides.
func Await(ctx context.Context, lookup Lookup, servers []string, name string, values []string, limit time.Duration) {
	if len(servers) == 0 {
		return
	}
	deadline := time.Now().Add(limit)
	for time.Now().Before(deadline) {
		all := true
		for _, s := range servers {
			got, err := lookup(ctx, s, name)
			if err != nil || !containsAll(got, values) {
				all = false
				break
			}
		}
		if all {
			return
		}
		select {
		case <-ctx.Done():
			return
		case <-time.After(2 * time.Second):
		}
	}
}

func containsAll(got, want []string) bool {
	for _, w := range want {
		if !slices.Contains(got, w) {
			return false
		}
	}
	return true
}
