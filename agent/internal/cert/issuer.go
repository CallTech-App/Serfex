package cert

import (
	"context"
	"crypto"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/json"
	"encoding/pem"
	"errors"
	"fmt"
	"math/big"
	"net"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"time"

	"golang.org/x/crypto/acme"

	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/version"
)

// LetsEncrypt is the production ACME directory.
const LetsEncrypt = "https://acme-v02.api.letsencrypt.org/directory"

// Challenge types.
const (
	HTTP01 = "http-01"
	DNS01  = "dns-01"
)

// Request is what a certificate should cover and how to prove control.
type Request struct {
	Names     []string
	Challenge string
	Email     string
}

// Solver publishes and removes challenge answers.
type Solver interface {
	// PresentHTTP makes http://<name>/.well-known/acme-challenge/<token>
	// answer keyAuth.
	PresentHTTP(ctx context.Context, name, token, keyAuth string) error
	CleanupHTTP(token string)
	// PresentDNS publishes TXT values at name (_acme-challenge.<domain>).
	PresentDNS(ctx context.Context, name string, values []string) error
	CleanupDNS(ctx context.Context, name string)
}

// Issuer obtains certificates.
type Issuer interface {
	// Obtain returns the certificate chain (leaf first) and its new key.
	Obtain(ctx context.Context, req Request, s Solver) ([]*x509.Certificate, crypto.Signer, error)
}

// ACME obtains certificates from an ACME server such as Let's Encrypt,
// with golang.org/x/crypto/acme. Accounts are created on first use and
// kept under AccountsDir, one per ACME server.
type ACME struct {
	H            host.Host
	DirectoryURL string
	HTTPClient   *http.Client
	// RetryDelays are the pauses between attempts to reach the ACME
	// server; nil means reachDelays.
	RetryDelays []time.Duration
}

// reachDelays cover a short network or DNS outage, such as the seconds in
// which this server's resolver moves away from 127.0.0.53 while PowerDNS
// is set up (Go programs re-read resolv.conf at most every five seconds).
var reachDelays = []time.Duration{3 * time.Second, 6 * time.Second, 12 * time.Second}

// UnreachableError means the ACME server could not be reached: its name
// did not resolve, the connection failed, or it answered with a server
// error. Nothing was asked of it, so trying again soon is safe.
type UnreachableError struct {
	URL string
	Err error
}

func (e *UnreachableError) Error() string {
	host := e.URL
	if u, err := url.Parse(e.URL); err == nil && u.Host != "" {
		host = u.Host
	}
	return fmt.Sprintf("could not reach the certificate authority (%s): %v", host, e.Err)
}

func (e *UnreachableError) Unwrap() error { return e.Err }

// reach fetches the ACME directory, trying again after a network failure
// or a server error.
func (a *ACME) reach(ctx context.Context, c *acme.Client) error {
	delays := a.RetryDelays
	if delays == nil {
		delays = reachDelays
	}
	for i := 0; ; i++ {
		_, err := c.Discover(ctx)
		if err == nil {
			return nil
		}
		if !transient(err) || ctx.Err() != nil {
			return err
		}
		if i == len(delays) {
			return &UnreachableError{URL: a.DirectoryURL, Err: err}
		}
		select {
		case <-ctx.Done():
			return &UnreachableError{URL: a.DirectoryURL, Err: err}
		case <-time.After(delays[i]):
		}
	}
}

// transient reports whether err is a failure to reach the server rather
// than an answer from it: a lookup or connection error, or a 5xx.
func transient(err error) bool {
	var ae *acme.Error
	if errors.As(err, &ae) {
		return ae.StatusCode >= 500
	}
	var dnsErr *net.DNSError
	var opErr *net.OpError
	var urlErr *url.Error
	return errors.As(err, &dnsErr) || errors.As(err, &opErr) || errors.As(err, &urlErr)
}

type account struct {
	URI   string `json:"uri"`
	Email string `json:"email"`
}

// accountDir is the account directory for the configured ACME server.
func (a *ACME) accountDir() string {
	u, err := url.Parse(a.DirectoryURL)
	name := "default"
	if err == nil && u.Host != "" {
		name = strings.NewReplacer(":", "_", "/", "_").Replace(u.Host)
	}
	return AccountsDir + "/" + name
}

// client returns an ACME client with a registered account.
func (a *ACME) client(ctx context.Context, email string) (*acme.Client, error) {
	dir := a.H.Path(a.accountDir())
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return nil, err
	}
	key, err := loadOrCreateKey(filepath.Join(dir, "account.key"))
	if err != nil {
		return nil, err
	}
	c := &acme.Client{Key: key, DirectoryURL: a.DirectoryURL, HTTPClient: a.HTTPClient, UserAgent: "servik-agent/" + version.Version}
	if err := a.reach(ctx, c); err != nil {
		return nil, err
	}

	var acct account
	if data, err := os.ReadFile(filepath.Join(dir, "account.json")); err == nil {
		_ = json.Unmarshal(data, &acct)
	}
	contact := []string{}
	if email != "" {
		contact = []string{"mailto:" + email}
	}
	switch {
	case acct.URI == "":
		reg, err := c.Register(ctx, &acme.Account{Contact: contact}, acme.AcceptTOS)
		if errors.Is(err, acme.ErrAccountAlreadyExists) {
			reg, err = c.GetReg(ctx, "")
		}
		if err != nil {
			return nil, fmt.Errorf("registering with the certificate authority: %w", explain(err))
		}
		acct = account{URI: reg.URI, Email: email}
	case email != "" && acct.Email != email:
		c.KID = acme.KeyID(acct.URI)
		if _, err := c.UpdateReg(ctx, &acme.Account{URI: acct.URI, Contact: contact}); err != nil {
			return nil, fmt.Errorf("updating the account e-mail: %w", explain(err))
		}
		acct.Email = email
	default:
		c.KID = acme.KeyID(acct.URI)
		return c, nil
	}
	c.KID = acme.KeyID(acct.URI)
	data, _ := json.Marshal(acct)
	return c, os.WriteFile(filepath.Join(dir, "account.json"), data, 0o600)
}

// Obtain implements Issuer.
func (a *ACME) Obtain(ctx context.Context, req Request, s Solver) ([]*x509.Certificate, crypto.Signer, error) {
	c, err := a.client(ctx, req.Email)
	if err != nil {
		return nil, nil, err
	}
	order, err := c.AuthorizeOrder(ctx, acme.DomainIDs(req.Names...))
	if err != nil {
		return nil, nil, explain(err)
	}

	// Collect what has to be proven first: DNS-01 answers for a name and
	// its wildcard share one TXT record set and must be published together.
	type pending struct {
		authz *acme.Authorization
		chal  *acme.Challenge
	}
	var todo []pending
	txt := map[string][]string{}
	var tokens []string
	var txtNames []string
	defer func() {
		bg := context.WithoutCancel(ctx)
		for _, t := range tokens {
			s.CleanupHTTP(t)
		}
		for _, n := range txtNames {
			s.CleanupDNS(bg, n)
		}
	}()
	for _, u := range order.AuthzURLs {
		z, err := c.GetAuthorization(ctx, u)
		if err != nil {
			return nil, nil, explain(err)
		}
		if z.Status == acme.StatusValid {
			continue
		}
		var chal *acme.Challenge
		for _, ch := range z.Challenges {
			if ch.Type == req.Challenge {
				chal = ch
			}
		}
		if chal == nil {
			return nil, nil, fmt.Errorf("the certificate authority does not offer %s validation for %s", req.Challenge, z.Identifier.Value)
		}
		switch req.Challenge {
		case HTTP01:
			ka, err := c.HTTP01ChallengeResponse(chal.Token)
			if err != nil {
				return nil, nil, err
			}
			if err := s.PresentHTTP(ctx, z.Identifier.Value, chal.Token, ka); err != nil {
				return nil, nil, err
			}
			tokens = append(tokens, chal.Token)
		case DNS01:
			v, err := c.DNS01ChallengeRecord(chal.Token)
			if err != nil {
				return nil, nil, err
			}
			name := "_acme-challenge." + z.Identifier.Value
			if txt[name] == nil {
				txtNames = append(txtNames, name)
			}
			txt[name] = append(txt[name], v)
		}
		todo = append(todo, pending{z, chal})
	}
	for _, n := range txtNames {
		if err := s.PresentDNS(ctx, n, txt[n]); err != nil {
			return nil, nil, err
		}
	}
	for _, p := range todo {
		if _, err := c.Accept(ctx, p.chal); err != nil {
			return nil, nil, explain(err)
		}
	}
	for _, p := range todo {
		if _, err := c.WaitAuthorization(ctx, p.authz.URI); err != nil {
			return nil, nil, explain(err)
		}
	}

	// Polling answers do not repeat the order URL; keep the original.
	orderURL := order.URI
	order, err = c.WaitOrder(ctx, orderURL)
	if err != nil {
		return nil, nil, explain(err)
	}
	order.URI = orderURL
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return nil, nil, err
	}
	csr, err := x509.CreateCertificateRequest(rand.Reader, &x509.CertificateRequest{
		Subject:  pkix.Name{CommonName: commonName(req.Names)},
		DNSNames: req.Names,
	}, key)
	if err != nil {
		return nil, nil, err
	}
	ders, err := finalize(ctx, c, order, csr)
	if err != nil {
		return nil, nil, explain(err)
	}
	var chain []*x509.Certificate
	for _, d := range ders {
		cert, err := x509.ParseCertificate(d)
		if err != nil {
			return nil, nil, fmt.Errorf("the certificate authority sent an unreadable certificate: %w", err)
		}
		chain = append(chain, cert)
	}
	if len(chain) == 0 {
		return nil, nil, errors.New("the certificate authority sent no certificate")
	}
	return chain, key, nil
}

// finalize submits the CSR and downloads the certificate. A server that
// finalizes asynchronously need not repeat the order URL in its answer
// (Pebble does not, and Let's Encrypt finalizes asynchronously), and
// CreateOrderCert then polls an empty URL; in that case the order is
// polled at its known URL instead.
func finalize(ctx context.Context, c *acme.Client, order *acme.Order, csr []byte) ([][]byte, error) {
	ders, _, err := c.CreateOrderCert(ctx, order.FinalizeURL, csr, true)
	if err == nil {
		return ders, nil
	}
	var ae *acme.Error
	var oe *acme.OrderError
	if errors.As(err, &ae) || errors.As(err, &oe) || ctx.Err() != nil {
		return nil, err
	}
	o, werr := c.WaitOrder(ctx, order.URI)
	if werr != nil {
		return nil, errors.Join(err, werr)
	}
	if o.Status != acme.StatusValid || o.CertURL == "" {
		return nil, err
	}
	return c.FetchCert(ctx, o.CertURL, true)
}

// commonName picks the subject name: the first name that is not a
// wildcard (64 bytes at most), else none.
func commonName(names []string) string {
	for _, n := range names {
		if !strings.HasPrefix(n, "*.") && len(n) <= 64 {
			return n
		}
	}
	return ""
}

// explain shortens ACME problem documents to their human-readable part.
func explain(err error) error {
	var ae *acme.Error
	if errors.As(err, &ae) && ae.Detail != "" {
		msg := ae.Detail
		for _, sp := range ae.Subproblems {
			msg += "; " + sp.Detail
		}
		return fmt.Errorf("%s (%s)", msg, strings.TrimPrefix(ae.ProblemType, "urn:ietf:params:acme:error:"))
	}
	var ze *acme.AuthorizationError
	if errors.As(err, &ze) {
		var parts []string
		for _, e := range ze.Errors {
			if errors.As(e, &ae) && ae.Detail != "" {
				parts = append(parts, ae.Detail)
			} else {
				parts = append(parts, e.Error())
			}
		}
		return fmt.Errorf("validation of %s failed: %s", ze.Identifier, strings.Join(parts, "; "))
	}
	return err
}

// loadOrCreateKey reads an ECDSA key, creating it when missing.
func loadOrCreateKey(path string) (crypto.Signer, error) {
	if data, err := os.ReadFile(path); err == nil {
		return parseKey(data)
	} else if !errors.Is(err, os.ErrNotExist) {
		return nil, err
	}
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return nil, err
	}
	data, err := encodeKey(key)
	if err != nil {
		return nil, err
	}
	return key, os.WriteFile(path, data, 0o600)
}

// Simulated issues certificates from a throwaway local CA, for dry runs
// and tests. It still publishes and removes every challenge answer, so the
// HTTP and DNS plumbing is exercised.
type Simulated struct {
	// Fail, when set, is consulted before issuing.
	Fail func(Request) error
	// Validity of issued certificates; 90 days when zero.
	Validity time.Duration

	ca    *x509.Certificate
	caKey crypto.Signer
}

// Issuer name used by Simulated.
const SimulatedIssuer = "Servik Dry Run CA"

// Obtain implements Issuer.
func (sim *Simulated) Obtain(ctx context.Context, req Request, s Solver) ([]*x509.Certificate, crypto.Signer, error) {
	for i, n := range req.Names {
		token := fmt.Sprintf("simulated-%d", i)
		switch req.Challenge {
		case HTTP01:
			if err := s.PresentHTTP(ctx, n, token, token+".key"); err != nil {
				return nil, nil, err
			}
			s.CleanupHTTP(token)
		case DNS01:
			name := "_acme-challenge." + strings.TrimPrefix(n, "*.")
			if err := s.PresentDNS(ctx, name, []string{"simulated-" + token}); err != nil {
				return nil, nil, err
			}
			s.CleanupDNS(ctx, name)
		}
	}
	if sim.Fail != nil {
		if err := sim.Fail(req); err != nil {
			return nil, nil, err
		}
	}
	if sim.ca == nil {
		key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
		if err != nil {
			return nil, nil, err
		}
		tmpl := &x509.Certificate{
			SerialNumber:          big.NewInt(1),
			Subject:               pkix.Name{CommonName: SimulatedIssuer, Organization: []string{SimulatedIssuer}},
			NotBefore:             time.Now().Add(-time.Hour),
			NotAfter:              time.Now().AddDate(10, 0, 0),
			IsCA:                  true,
			BasicConstraintsValid: true,
			KeyUsage:              x509.KeyUsageCertSign,
		}
		der, err := x509.CreateCertificate(rand.Reader, tmpl, tmpl, key.Public(), key)
		if err != nil {
			return nil, nil, err
		}
		sim.ca, _ = x509.ParseCertificate(der)
		sim.caKey = key
	}
	validity := sim.Validity
	if validity == 0 {
		validity = 90 * 24 * time.Hour
	}
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return nil, nil, err
	}
	serial, _ := rand.Int(rand.Reader, big.NewInt(1<<62))
	tmpl := &x509.Certificate{
		SerialNumber: serial,
		Subject:      pkix.Name{CommonName: commonName(req.Names)},
		DNSNames:     req.Names,
		NotBefore:    time.Now().Add(-time.Minute),
		NotAfter:     time.Now().Add(validity),
		KeyUsage:     x509.KeyUsageDigitalSignature,
		ExtKeyUsage:  []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
	}
	der, err := x509.CreateCertificate(rand.Reader, tmpl, sim.ca, key.Public(), sim.caKey)
	if err != nil {
		return nil, nil, err
	}
	leaf, _ := x509.ParseCertificate(der)
	return []*x509.Certificate{leaf, sim.ca}, key, nil
}

// pemCert renders one certificate (used by tests).
func pemCert(c *x509.Certificate) string {
	return string(pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: c.Raw}))
}
