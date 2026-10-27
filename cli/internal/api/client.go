// Package api talks to a Serfex panel's /api/v1.
//
// Every request carries the key or token, the user's language (the panel
// answers in it) and the command line's version. A panel whose
// certificate the computer does not trust yet (its own first certificate,
// before Let's Encrypt) is reached by pinning that certificate's
// SHA-256 fingerprint at sign-in.
package api

import (
	"bytes"
	"context"
	"crypto/sha256"
	"crypto/tls"
	"crypto/x509"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"runtime"
	"sort"
	"strings"
	"time"

	"github.com/calltech-app/serfex/cli/internal/version"
)

// Options say how to reach a panel.
type Options struct {
	URL         string
	Token       string
	Fingerprint string
	Connect     string
	Lang        string
	Timeout     time.Duration
}

// Client is one panel.
type Client struct {
	base  *url.URL
	token string
	lang  string
	http  *http.Client
}

// Error is the panel's answer to a request it refused.
type Error struct {
	Status  int
	Message string
	Fields  map[string][]string
}

func (e *Error) Error() string {
	if len(e.Fields) == 0 || e.Message == "" {
		if e.Message == "" {
			return http.StatusText(e.Status)
		}
		return e.Message
	}
	keys := make([]string, 0, len(e.Fields))
	for k := range e.Fields {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	var lines []string
	for _, k := range keys {
		lines = append(lines, e.Fields[k]...)
	}
	// Laravel's message repeats the first field error; list them all instead.
	return strings.Join(lines, "\n")
}

// PinError means the panel showed a certificate other than the pinned one.
type PinError struct {
	Got, Want string
}

func (e *PinError) Error() string {
	return "certificate fingerprint " + Format(e.Got) + " is not the pinned " + Format(e.Want)
}

// NormalizeURL turns "panel.example.com:8443" into https://panel.example.com:8443.
func NormalizeURL(raw string) (*url.URL, error) {
	raw = strings.TrimSpace(raw)
	if !strings.Contains(raw, "://") {
		raw = "https://" + raw
	}
	u, err := url.Parse(raw)
	if err != nil {
		return nil, err
	}
	if u.Host == "" || (u.Scheme != "https" && u.Scheme != "http") {
		return nil, fmt.Errorf("not a panel address: %s", raw)
	}
	u.Path, u.RawQuery, u.Fragment = "", "", ""
	return u, nil
}

// New makes a client.
func New(o Options) (*Client, error) {
	base, err := NormalizeURL(o.URL)
	if err != nil {
		return nil, err
	}
	timeout := o.Timeout
	if timeout == 0 {
		timeout = 60 * time.Second
	}
	return &Client{
		base:  base,
		token: o.Token,
		lang:  o.Lang,
		http:  &http.Client{Transport: Transport(base.Hostname(), o.Fingerprint, o.Connect), Timeout: timeout},
	}, nil
}

// URL is the panel's address.
func (c *Client) URL() *url.URL { return c.base }

// Transport trusts what the computer trusts and, with a fingerprint, also
// the certificate that has it; connect replaces the address dialed.
func Transport(host, fingerprint, connect string) *http.Transport {
	tr := http.DefaultTransport.(*http.Transport).Clone()
	if fingerprint != "" {
		want := Normalize(fingerprint)
		tr.TLSClientConfig = &tls.Config{
			ServerName:         host,
			InsecureSkipVerify: true, // checked below: the system's roots, or the pinned certificate
			VerifyConnection: func(cs tls.ConnectionState) error {
				if len(cs.PeerCertificates) == 0 {
					return errors.New("no certificate")
				}
				if Verify(cs.PeerCertificates, cs.ServerName) == nil {
					return nil
				}
				if got := Fingerprint(cs.PeerCertificates[0]); got != want {
					return &PinError{Got: got, Want: want}
				}
				return nil
			},
		}
	}
	if connect != "" {
		dialer := &net.Dialer{Timeout: 15 * time.Second, KeepAlive: 30 * time.Second}
		tr.Proxy = nil
		tr.DialContext = func(ctx context.Context, network, _ string) (net.Conn, error) {
			return dialer.DialContext(ctx, network, connect)
		}
	}
	return tr
}

// Verify checks a certificate chain against the system's roots.
func Verify(chain []*x509.Certificate, host string) error {
	inter := x509.NewCertPool()
	for _, c := range chain[1:] {
		inter.AddCert(c)
	}
	_, err := chain[0].Verify(x509.VerifyOptions{DNSName: host, Intermediates: inter})
	return err
}

// Fingerprint is a certificate's SHA-256, lowercase hex.
func Fingerprint(c *x509.Certificate) string {
	sum := sha256.Sum256(c.Raw)
	return hex.EncodeToString(sum[:])
}

// Normalize accepts a fingerprint written as AB:CD:… or abcd…, with or
// without "SHA256:".
func Normalize(fp string) string {
	fp = strings.TrimPrefix(strings.ToLower(strings.TrimSpace(fp)), "sha256:")
	return strings.NewReplacer(":", "", " ", "").Replace(fp)
}

// Format writes a fingerprint as OpenSSL does: AB:CD:….
func Format(fp string) string {
	fp = strings.ToUpper(Normalize(fp))
	var parts []string
	for i := 0; i+2 <= len(fp); i += 2 {
		parts = append(parts, fp[i:i+2])
	}
	return strings.Join(parts, ":")
}

// Certificate fetches the certificate a panel shows, without trusting it.
func Certificate(ctx context.Context, u *url.URL, connect string) ([]*x509.Certificate, error) {
	addr := connect
	if addr == "" {
		addr = HostPort(u)
	}
	dialer := &tls.Dialer{
		NetDialer: &net.Dialer{Timeout: 15 * time.Second},
		Config:    &tls.Config{ServerName: u.Hostname(), InsecureSkipVerify: true}, // only to read it
	}
	conn, err := dialer.DialContext(ctx, "tcp", addr)
	if err != nil {
		return nil, err
	}
	defer conn.Close()
	return conn.(*tls.Conn).ConnectionState().PeerCertificates, nil
}

// HostPort is the URL's host with its port, 443 by default.
func HostPort(u *url.URL) string {
	if u.Port() != "" {
		return u.Host
	}
	if u.Scheme == "http" {
		return net.JoinHostPort(u.Hostname(), "80")
	}
	return net.JoinHostPort(u.Hostname(), "443")
}

// IsCertificateProblem tells a certificate the computer does not trust
// from other failures.
func IsCertificateProblem(err error) bool {
	var unknown x509.UnknownAuthorityError
	var host x509.HostnameError
	var invalid x509.CertificateInvalidError
	var verify *tls.CertificateVerificationError
	var pin *PinError
	return errors.As(err, &unknown) || errors.As(err, &host) || errors.As(err, &invalid) || errors.As(err, &verify) || errors.As(err, &pin)
}

// Response is an answer the panel accepted (2xx).
type Response struct {
	Status int
	Body   []byte
}

// Data is the answer's "data", or the whole answer when it has none.
func (r *Response) Data() json.RawMessage {
	var env struct {
		Data json.RawMessage `json:"data"`
	}
	if json.Unmarshal(r.Body, &env) == nil && len(env.Data) > 0 {
		return env.Data
	}
	return r.Body
}

// Decode reads Data into v.
func (r *Response) Decode(v any) error {
	return json.Unmarshal(r.Data(), v)
}

// Message is the answer's "message", if any.
func (r *Response) Message() string {
	var env struct {
		Message string `json:"message"`
	}
	_ = json.Unmarshal(r.Body, &env)
	return env.Message
}

// UserAgent names the command line to the panel (shown under Erişim ve API).
func UserAgent() string {
	return "serfex/" + version.Version + " (" + runtime.GOOS + "/" + runtime.GOARCH + ")"
}

// Do sends a request to /api/v1<path>. body, when not nil, is sent as JSON.
// An answer outside 2xx is an *Error.
func (c *Client) Do(ctx context.Context, method, path string, query url.Values, body any) (*Response, error) {
	u := *c.base
	path, raw, _ := strings.Cut(path, "?")
	u.Path = strings.TrimSuffix(c.base.Path, "/") + "/api/v1" + path
	u.RawQuery = raw
	if len(query) > 0 {
		u.RawQuery = query.Encode()
	}
	var reader io.Reader
	if body != nil {
		data, err := json.Marshal(body)
		if err != nil {
			return nil, err
		}
		reader = bytes.NewReader(data)
	}
	req, err := http.NewRequestWithContext(ctx, method, u.String(), reader)
	if err != nil {
		return nil, err
	}
	req.Header.Set("Accept", "application/json")
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	if c.token != "" {
		req.Header.Set("Authorization", "Bearer "+c.token)
	}
	if c.lang != "" {
		req.Header.Set("Accept-Language", c.lang)
	}
	req.Header.Set("User-Agent", UserAgent())
	resp, err := c.http.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	data, err := io.ReadAll(io.LimitReader(resp.Body, 64<<20))
	if err != nil {
		return nil, err
	}
	if resp.StatusCode < 200 || resp.StatusCode > 299 {
		e := &Error{Status: resp.StatusCode}
		var env struct {
			Message string              `json:"message"`
			Errors  map[string][]string `json:"errors"`
		}
		if json.Unmarshal(data, &env) == nil {
			e.Message, e.Fields = env.Message, env.Errors
		}
		if e.Message == "" && len(e.Fields) == 0 {
			e.Message = fmt.Sprintf("%d %s", resp.StatusCode, http.StatusText(resp.StatusCode))
		}
		return nil, e
	}
	return &Response{Status: resp.StatusCode, Body: data}, nil
}

// Get is Do with GET.
func (c *Client) Get(ctx context.Context, path string, query url.Values) (*Response, error) {
	return c.Do(ctx, http.MethodGet, path, query, nil)
}
