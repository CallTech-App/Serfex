package cert

import (
	"bytes"
	"context"
	"crypto"
	"crypto/x509"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"sync"
	"text/template"
	"time"

	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/hostname"
	"github.com/winchester37/servik/agent/internal/txn"
)

// MaxNames bounds the names on one certificate (Let's Encrypt allows 100).
const MaxNames = 100

// PanelUnit is the systemd unit of the panel's own web server. It is
// reloaded after the panel certificate changes, when it exists.
const PanelUnit = "servik-web"

const (
	panelVhost        = "/etc/nginx/sites-available/servik-panel.conf"
	panelVhostEnabled = "/etc/nginx/sites-enabled/servik-panel.conf"
)

// ChallengeDNS publishes DNS-01 answers in a zone this server serves
// (dns.Manager).
type ChallengeDNS interface {
	SetChallenge(ctx context.Context, zone, name string, values []string) error
	ClearChallenge(ctx context.Context, zone, name string) error
}

// Consumer is a service other than Nginx that reads certificates from
// the store (the mail server).
type Consumer interface {
	UsesCertificate(name string) bool
	// ReloadCertificate makes the service read renewed files.
	ReloadCertificate(ctx context.Context) error
}

// Manager obtains and installs certificates. Operations are serialised.
type Manager struct {
	h         host.Host
	dns       ChallengeDNS
	issuer    Issuer
	consumers []Consumer
	// PanelChanged, when set, runs after the panel's certificate files
	// changed and before the panel's web server reloads (servik-web
	// switches from its self-signed certificate to the panel's).
	PanelChanged func(context.Context) error
	// localWeb is where this server's Nginx answers on port 80. Before
	// asking the certificate authority to validate, the agent waits until
	// Nginx serves the answer there: a reload that has just been requested
	// takes a moment to reach the workers. Empty skips the check.
	localWeb string
	mu       sync.Mutex
}

// NewManager returns a Manager. dnsm publishes DNS-01 answers; issuer
// talks to the certificate authority.
func NewManager(h host.Host, dnsm ChallengeDNS, issuer Issuer) *Manager {
	m := &Manager{h: h, dns: dnsm, issuer: issuer}
	if _, sim := h.(*host.Simulator); !sim {
		m.localWeb = "http://127.0.0.1"
	}
	return m
}

// IssueSpec asks for a certificate from the certificate authority.
type IssueSpec struct {
	// Name is the directory the certificate is stored in; sites refer to it.
	Name string `json:"name"`
	// Names are the host names to cover; "*.example.com" needs dns-01.
	Names []string `json:"names"`
	// Challenge is http-01 (default) or dns-01.
	Challenge string `json:"challenge"`
	// DNSZone is the zone on this server's DNS that holds every name, for
	// dns-01.
	DNSZone string `json:"dns_zone"`
	// Email receives expiry notices from the certificate authority.
	Email string `json:"email"`
}

// InstallSpec installs a certificate the user brings.
type InstallSpec struct {
	Name        string `json:"name"`
	Certificate string `json:"certificate"`
	PrivateKey  string `json:"private_key"`
	// Chain holds intermediate certificates, if not already appended to
	// Certificate.
	Chain string `json:"chain"`
	// Domain, when set, must be covered by the certificate.
	Domain string `json:"domain"`
}

// PanelSpec asks for the panel's own certificate.
type PanelSpec struct {
	Hostname string `json:"hostname"`
	Email    string `json:"email"`
}

// Result describes an installed certificate.
type Result struct {
	Info
	// Changed is false when the same certificate was installed already.
	Changed bool `json:"changed"`
	// Reloaded is true when a web server picked up the new files.
	Reloaded bool `json:"reloaded"`
}

// validateHost checks a host name with an optional leading "*." label.
func validateHost(n string, wildcard bool) error {
	h := n
	if wildcard {
		h = strings.TrimPrefix(n, "*.")
	}
	if err := hostname.Validate(h); err != nil {
		return invalid("%s", err.Error())
	}
	return nil
}

func (s *IssueSpec) validate() error {
	if err := ValidateName(s.Name); err != nil {
		return err
	}
	if s.Challenge == "" {
		s.Challenge = HTTP01
	}
	if s.Challenge != HTTP01 && s.Challenge != DNS01 {
		return invalid("challenge must be %s or %s", HTTP01, DNS01)
	}
	if len(s.Names) == 0 || len(s.Names) > MaxNames {
		return invalid("a certificate covers 1 to %d names", MaxNames)
	}
	seen := map[string]bool{}
	for _, n := range s.Names {
		if err := validateHost(n, s.Challenge == DNS01); err != nil {
			return err
		}
		if strings.HasPrefix(n, "*.") && s.Challenge != DNS01 {
			return invalid("wildcard name %s needs dns-01 validation", n)
		}
		if seen[n] {
			return invalid("%s is listed twice", n)
		}
		seen[n] = true
		if s.Challenge == DNS01 {
			base := strings.TrimPrefix(n, "*.")
			if base != s.DNSZone && !strings.HasSuffix(base, "."+s.DNSZone) {
				return invalid("%s is not in DNS zone %q", n, s.DNSZone)
			}
		}
	}
	if s.Challenge == DNS01 {
		if err := validateHost(s.DNSZone, false); err != nil {
			return err
		}
	}
	if s.Email != "" && (!strings.Contains(s.Email, "@") || strings.ContainsAny(s.Email, " \t\r\n<>")) {
		return invalid("email %q is not valid", s.Email)
	}
	return nil
}

// Issue obtains a certificate from the certificate authority and installs
// it under Name. When sites already use that name, Nginx is reloaded; if
// Nginx rejects the new files, the previous ones are restored.
func (m *Manager) Issue(ctx context.Context, spec IssueSpec) (Result, error) {
	if err := spec.validate(); err != nil {
		return Result{}, err
	}
	m.mu.Lock()
	defer m.mu.Unlock()

	if spec.Challenge == HTTP01 {
		if err := m.ensureWebroot(); err != nil {
			return Result{}, &OperationError{err}
		}
	}
	chain, key, err := m.issuer.Obtain(ctx, Request{Names: spec.Names, Challenge: spec.Challenge, Email: spec.Email}, &solver{m: m, zone: spec.DNSZone})
	if err != nil {
		return Result{}, &OperationError{err}
	}
	full, keyFile := Files(spec.Name)
	return m.store(ctx, spec.Name, full, keyFile, chain, key, m.reloadNginxIfUsed)
}

// Install stores a certificate the user uploaded, after checking that the
// key matches, the certificate is still valid and (if asked) that it
// covers Domain.
func (m *Manager) Install(ctx context.Context, spec InstallSpec) (Result, error) {
	if err := ValidateName(spec.Name); err != nil {
		return Result{}, err
	}
	chain, key, err := parseUpload(spec)
	if err != nil {
		return Result{}, err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	full, keyFile := Files(spec.Name)
	return m.store(ctx, spec.Name, full, keyFile, chain, key, m.reloadNginxIfUsed)
}

// parseUpload validates an uploaded certificate and orders its chain leaf
// first.
func parseUpload(spec InstallSpec) ([]*x509.Certificate, crypto.Signer, error) {
	certs, err := parseChain([]byte(spec.Certificate + "\n" + spec.Chain))
	if err != nil {
		return nil, nil, invalid("certificate: %s", err.Error())
	}
	key, err := parseKey([]byte(spec.PrivateKey))
	if err != nil {
		return nil, nil, invalid("private key: %s", err.Error())
	}
	var leaf *x509.Certificate
	for _, c := range certs {
		if publicKeyEqual(c, key) {
			leaf = c
			break
		}
	}
	if leaf == nil {
		return nil, nil, invalid("the private key does not belong to this certificate")
	}
	if time.Now().After(leaf.NotAfter) {
		return nil, nil, invalid("the certificate expired on %s", leaf.NotAfter.UTC().Format("2006-01-02"))
	}
	if spec.Domain != "" {
		if err := leaf.VerifyHostname(spec.Domain); err != nil {
			return nil, nil, invalid("the certificate does not cover %s (it covers %s)", spec.Domain, strings.Join(leaf.DNSNames, ", "))
		}
	}
	chain := []*x509.Certificate{leaf}
	seen := map[string]bool{string(leaf.Raw): true}
	for _, c := range certs {
		if !seen[string(c.Raw)] {
			seen[string(c.Raw)] = true
			chain = append(chain, c)
		}
	}
	return chain, key, nil
}

// Get returns an installed certificate, or nil.
func (m *Manager) Get(name string) (*Info, error) {
	if err := ValidateName(name); err != nil {
		return nil, err
	}
	full, _ := Files(name)
	return m.read(full, name)
}

func (m *Manager) read(path, name string) (*Info, error) {
	info, err := ReadInfo(m.h.Path(path), name)
	if errors.Is(err, os.ErrNotExist) {
		return nil, nil
	}
	if err != nil {
		return nil, &OperationError{err}
	}
	return &info, nil
}

// DeleteResult describes a removed certificate.
type DeleteResult struct {
	Name    string `json:"name"`
	Deleted bool   `json:"deleted"`
}

// Delete removes an installed certificate. A certificate that an enabled
// site still uses is refused; deleting a missing one succeeds.
func (m *Manager) Delete(name string) (DeleteResult, error) {
	if err := ValidateName(name); err != nil {
		return DeleteResult{}, err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	if users, err := m.usedBy(name); err != nil {
		return DeleteResult{}, &OperationError{err}
	} else if m.consumerUses(name) {
		return DeleteResult{}, invalid("certificate %s is used by the mail server", name)
	} else if len(users) > 0 {
		return DeleteResult{}, invalid("certificate %s is still used by %s", name, strings.Join(users, ", "))
	}
	dir := m.h.Path(Dir + "/" + name)
	if _, err := os.Lstat(dir); errors.Is(err, os.ErrNotExist) {
		return DeleteResult{Name: name}, nil
	}
	if err := os.RemoveAll(dir); err != nil {
		return DeleteResult{}, &OperationError{err}
	}
	return DeleteResult{Name: name, Deleted: true}, nil
}

// AddConsumer registers a service that reloads when a certificate it
// uses changes, and keeps that certificate from being deleted.
func (m *Manager) AddConsumer(c Consumer) { m.consumers = append(m.consumers, c) }

// IssuePanel obtains the certificate for the panel's own address. Nginx
// (which owns port 80) answers the HTTP-01 validation through a small
// vhost that serves nothing else; the panel's own web server reads the
// files from PanelDir.
func (m *Manager) IssuePanel(ctx context.Context, spec PanelSpec) (Result, error) {
	is := IssueSpec{Name: "panel", Names: []string{spec.Hostname}, Email: spec.Email}
	if err := is.validate(); err != nil {
		return Result{}, err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	if err := m.ensureWebroot(); err != nil {
		return Result{}, &OperationError{err}
	}
	if err := m.panelVhost(ctx, spec.Hostname); err != nil {
		return Result{}, &OperationError{err}
	}
	chain, key, err := m.issuer.Obtain(ctx, Request{Names: is.Names, Challenge: HTTP01, Email: spec.Email}, &solver{m: m})
	if err != nil {
		return Result{}, &OperationError{err}
	}
	full, keyFile := PanelFiles()
	return m.store(ctx, "panel", full, keyFile, chain, key, m.reloadPanel)
}

// PanelInstallSpec installs a certificate the user brings for the panel's
// own address.
type PanelInstallSpec struct {
	Certificate string `json:"certificate"`
	PrivateKey  string `json:"private_key"`
}

// InstallPanel stores an uploaded certificate as the panel's own, with
// the same checks as Install (the key matches, the certificate is still
// valid), and reloads the panel's web server.
func (m *Manager) InstallPanel(ctx context.Context, spec PanelInstallSpec) (Result, error) {
	chain, key, err := parseUpload(InstallSpec{Name: "panel", Certificate: spec.Certificate, PrivateKey: spec.PrivateKey})
	if err != nil {
		return Result{}, err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	full, keyFile := PanelFiles()
	return m.store(ctx, "panel", full, keyFile, chain, key, m.reloadPanel)
}

// GetPanel returns the panel's certificate, or nil.
func (m *Manager) GetPanel() (*Info, error) {
	full, _ := PanelFiles()
	return m.read(full, "panel")
}

var panelVhostTmpl = template.Must(template.New("panel").Parse(`# {{.Marker}}. Manual changes are overwritten.
# Answers Let's Encrypt validation for the panel's own address. The panel
# is served by its own web server, never by this one.
server {
    listen 80;
{{- if .IPv6}}
    listen [::]:80;
{{- end}}
    server_name {{.Hostname}};

    location ^~ /.well-known/acme-challenge/ {
        root {{.Webroot}};
        default_type "text/plain";
        try_files $uri =404;
    }

    location / {
        return 404;
    }
}
`))

// panelVhost points port 80 of the panel's address at the ACME webroot.
func (m *Manager) panelVhost(ctx context.Context, hostname string) (err error) {
	var b bytes.Buffer
	_, ipv6 := os.Stat(m.h.Path("/proc/net/if_inet6"))
	if err := panelVhostTmpl.Execute(&b, map[string]any{"Marker": txn.Marker, "Hostname": hostname, "Webroot": Webroot, "IPv6": ipv6 == nil}); err != nil {
		return err
	}
	t := txn.New(m.h)
	defer func() {
		if err != nil {
			if rbErr := t.Rollback(context.WithoutCancel(ctx)); rbErr != nil {
				err = fmt.Errorf("%w (rollback also failed: %v)", err, rbErr)
			}
		}
	}()
	if err := t.WriteFile(panelVhost, b.Bytes()); err != nil {
		return err
	}
	if err := t.Symlink(panelVhost, panelVhostEnabled); err != nil {
		return err
	}
	if !t.Changed {
		return nil
	}
	return m.reloadNginx(ctx, t)
}

// store writes a certificate and key, then lets reload pick them up. If
// reload fails the previous files come back.
func (m *Manager) store(ctx context.Context, name, full, keyFile string, chain []*x509.Certificate, key crypto.Signer, reload func(context.Context, *txn.Tx, string) (bool, error)) (res Result, err error) {
	keyPEM, err := encodeKey(key)
	if err != nil {
		return Result{}, &OperationError{err}
	}
	t := txn.New(m.h)
	defer func() {
		if err != nil {
			if rbErr := t.Rollback(context.WithoutCancel(ctx)); rbErr != nil {
				err = fmt.Errorf("%w (rollback also failed: %v)", err, rbErr)
			}
			var v *ValidationError
			if !errors.As(err, &v) {
				err = &OperationError{err}
			}
		}
	}()
	dir := filepath.Dir(full)
	for _, d := range parents(dir) {
		mode := os.FileMode(0o755)
		if d == dir {
			mode = 0o750
		}
		if err := t.RootDir(d, mode, 0, 0); err != nil {
			return Result{}, err
		}
	}
	// Key first: Nginx reads both only on reload.
	if err := t.WriteOwned(keyFile, keyPEM, 0o600, 0, 0); err != nil {
		return Result{}, err
	}
	if err := t.WriteOwned(full, encodeChain(chain), 0o644, 0, 0); err != nil {
		return Result{}, err
	}
	res = Result{Info: describe(name, chain[0]), Changed: t.Changed}
	if !t.Changed {
		return res, nil
	}
	res.Reloaded, err = reload(ctx, t, name)
	if err != nil {
		return Result{}, err
	}
	return res, nil
}

// parents lists dir and its ancestors below /etc, outermost first.
func parents(dir string) []string {
	var out []string
	for d := dir; d != "/etc" && d != "/" && d != "."; d = filepath.Dir(d) {
		out = append([]string{d}, out...)
	}
	return out
}

// reloadNginxIfUsed reloads Nginx when an enabled vhost uses the
// certificate, and the other services that use it. If one of them fails,
// the previous files come back and everything is reloaded with them.
func (m *Manager) reloadNginxIfUsed(ctx context.Context, t *txn.Tx, name string) (bool, error) {
	users, err := m.usedBy(name)
	if err != nil {
		return false, err
	}
	reloaded := false
	if len(users) > 0 {
		if err := m.reloadNginx(ctx, t); err != nil {
			return false, err
		}
		reloaded = true
	}
	for _, c := range m.consumers {
		if !c.UsesCertificate(name) {
			continue
		}
		if err := c.ReloadCertificate(ctx); err != nil {
			bg := context.WithoutCancel(ctx)
			rbErr := t.Rollback(bg)
			_ = c.ReloadCertificate(bg)
			if reloaded {
				_, _ = m.h.Run(bg, host.Command{Name: "systemctl", Args: []string{"reload", "nginx"}})
			}
			return false, errors.Join(fmt.Errorf("reloading the mail server failed: %w", err), rbErr)
		}
		reloaded = true
	}
	return reloaded, nil
}

func (m *Manager) consumerUses(name string) bool {
	for _, c := range m.consumers {
		if c.UsesCertificate(name) {
			return true
		}
	}
	return false
}

// reloadPanel reloads the panel's web server when it is installed.
func (m *Manager) reloadPanel(ctx context.Context, t *txn.Tx, _ string) (bool, error) {
	found := false
	for _, d := range []string{"/etc/systemd/system", "/usr/lib/systemd/system", "/lib/systemd/system"} {
		if _, err := os.Stat(m.h.Path(d + "/" + PanelUnit + ".service")); err == nil {
			found = true
		}
	}
	if !found {
		return false, nil
	}
	if m.PanelChanged != nil {
		if err := m.PanelChanged(ctx); err != nil {
			return false, errors.Join(fmt.Errorf("pointing %s at the new certificate failed: %w", PanelUnit, err), t.Rollback(context.WithoutCancel(ctx)))
		}
	}
	if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"reload-or-restart", PanelUnit}}); err != nil {
		rbErr := t.Rollback(context.WithoutCancel(ctx))
		_, _ = m.h.Run(context.WithoutCancel(ctx), host.Command{Name: "systemctl", Args: []string{"reload-or-restart", PanelUnit}})
		return false, errors.Join(fmt.Errorf("reloading %s failed: %w", PanelUnit, err), rbErr)
	}
	return true, nil
}

// reloadNginx checks the configuration and reloads Nginx. On failure the
// transaction is rolled back and Nginx reloaded with the old files.
func (m *Manager) reloadNginx(ctx context.Context, t *txn.Tx) error {
	if _, err := m.h.Run(ctx, host.Command{Name: "nginx", Args: []string{"-t"}}); err != nil {
		return fmt.Errorf("nginx rejected the configuration: %w", err)
	}
	if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"reload", "nginx"}}); err != nil {
		rbErr := t.Rollback(context.WithoutCancel(ctx))
		_, _ = m.h.Run(context.WithoutCancel(ctx), host.Command{Name: "systemctl", Args: []string{"reload", "nginx"}})
		return errors.Join(fmt.Errorf("reloading nginx failed: %w", err), rbErr)
	}
	return nil
}

// usedBy lists the enabled Nginx configurations that use certificate name.
func (m *Manager) usedBy(name string) ([]string, error) {
	dir := m.h.Path("/etc/nginx/sites-enabled")
	entries, err := os.ReadDir(dir)
	if errors.Is(err, os.ErrNotExist) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	needle := []byte(Dir + "/" + name + "/")
	var out []string
	for _, e := range entries {
		path := filepath.Join(dir, e.Name())
		// Enabled sites are symlinks to sites-available.
		if target, err := os.Readlink(path); err == nil && filepath.IsAbs(target) {
			path = m.h.Path(target)
		}
		data, err := os.ReadFile(path)
		if err != nil {
			continue
		}
		if bytes.Contains(data, needle) {
			out = append(out, strings.TrimSuffix(e.Name(), ".conf"))
		}
	}
	return out, nil
}

// ensureWebroot creates the directory HTTP-01 answers are written to.
func (m *Manager) ensureWebroot() error {
	for _, d := range []string{"/var/lib/servik", Webroot, Webroot + "/.well-known", ChallengeDir} {
		if err := os.MkdirAll(m.h.Path(d), 0o755); err != nil {
			return err
		}
		if err := os.Chmod(m.h.Path(d), 0o755); err != nil {
			return err
		}
	}
	return nil
}

var tokenRe = regexp.MustCompile(`^[A-Za-z0-9_-]{1,255}$`)

// solver publishes challenge answers on this server.
type solver struct {
	m    *Manager
	zone string
}

func (s *solver) PresentHTTP(ctx context.Context, name, token, keyAuth string) error {
	if !tokenRe.MatchString(token) {
		return fmt.Errorf("unexpected challenge token %q", token)
	}
	if err := os.WriteFile(s.m.h.Path(ChallengeDir+"/"+token), []byte(keyAuth), 0o644); err != nil {
		return err
	}
	if s.m.localWeb != "" {
		s.m.waitServed(ctx, name, "/.well-known/acme-challenge/"+token, keyAuth)
	}
	return nil
}

// waitServed polls the local Nginx for up to ten seconds until it answers
// path for host with want several times in a row, each on a new
// connection: right after a reload, workers still running the old
// configuration keep accepting connections for a moment. It does not
// fail: the certificate authority's own request decides, and reports a
// clearer error.
func (m *Manager) waitServed(ctx context.Context, host, path, want string) {
	const inARow = 3
	client := &http.Client{
		Timeout:       2 * time.Second,
		Transport:     &http.Transport{DisableKeepAlives: true},
		CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
	}
	good := 0
	deadline := time.Now().Add(10 * time.Second)
	for time.Now().Before(deadline) {
		req, err := http.NewRequestWithContext(ctx, http.MethodGet, m.localWeb+path, nil)
		if err != nil {
			return
		}
		req.Host = host
		if resp, err := client.Do(req); err == nil {
			body, _ := io.ReadAll(io.LimitReader(resp.Body, 4096))
			resp.Body.Close()
			if resp.StatusCode == http.StatusOK && string(body) == want {
				good++
			} else {
				good = 0
			}
		} else {
			good = 0
		}
		if good == inARow {
			return
		}
		select {
		case <-ctx.Done():
			return
		case <-time.After(200 * time.Millisecond):
		}
	}
}

func (s *solver) CleanupHTTP(token string) {
	if tokenRe.MatchString(token) {
		_ = os.Remove(s.m.h.Path(ChallengeDir + "/" + token))
	}
}

func (s *solver) PresentDNS(ctx context.Context, name string, values []string) error {
	if s.zone == "" {
		return errors.New("dns-01 validation needs a DNS zone on this server")
	}
	if err := s.m.dns.SetChallenge(ctx, s.zone, name, values); err != nil {
		return fmt.Errorf("publishing %s: %w", name, err)
	}
	return nil
}

func (s *solver) CleanupDNS(ctx context.Context, name string) {
	_ = s.m.dns.ClearChallenge(ctx, s.zone, name)
}
