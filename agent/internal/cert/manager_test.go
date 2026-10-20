package cert

import (
	"context"
	"crypto"
	"crypto/x509"
	"errors"
	"os"
	"strings"
	"testing"
	"time"

	"github.com/winchester37/servik/agent/internal/dns"
	"github.com/winchester37/servik/agent/internal/host"
)

// probe wraps an issuer and checks, while challenges are presented, that
// the answers really are where the certificate authority would look.
type probe struct {
	t      *testing.T
	sim    *host.Simulator
	dns    *dns.Manager
	zone   string
	inner  Simulated
	seenTX []string
	seenHT []string
}

type probeSolver struct {
	p *probe
	Solver
}

func (s probeSolver) PresentHTTP(ctx context.Context, name, token, keyAuth string) error {
	if err := s.Solver.PresentHTTP(ctx, name, token, keyAuth); err != nil {
		return err
	}
	got, err := os.ReadFile(s.p.sim.Path(ChallengeDir + "/" + token))
	if err != nil || string(got) != keyAuth {
		s.p.t.Errorf("token %s not served: %q, %v", token, got, err)
	}
	s.p.seenHT = append(s.p.seenHT, token)
	return nil
}

func (s probeSolver) PresentDNS(ctx context.Context, name string, values []string) error {
	if err := s.Solver.PresentDNS(ctx, name, values); err != nil {
		return err
	}
	z, err := s.p.dns.GetZone(ctx, s.p.zone)
	if err != nil {
		s.p.t.Fatal(err)
	}
	found := false
	for _, r := range z.Records {
		if r.Type == "TXT" && r.Name+"."+s.p.zone == name && r.Content == values[0] {
			found = true
		}
	}
	if !found {
		s.p.t.Errorf("TXT %s %v not published: %+v", name, values, z.Records)
	}
	s.p.seenTX = append(s.p.seenTX, name)
	return nil
}

func (p *probe) Obtain(ctx context.Context, req Request, s Solver) ([]*x509.Certificate, crypto.Signer, error) {
	return p.inner.Obtain(ctx, req, probeSolver{p, s})
}

const zone = "kahve.com.tr"

func newTest(t *testing.T) (*Manager, *host.Simulator, *probe) {
	t.Helper()
	sim, err := host.NewSimulator(t.TempDir(), "8.4")
	if err != nil {
		t.Fatal(err)
	}
	d := dns.NewSimulatedManager(sim, dns.NewMemory())
	ctx := context.Background()
	if _, err := d.Setup(ctx); err != nil {
		t.Fatal(err)
	}
	_, err = d.ApplyZone(ctx, dns.ZoneSpec{
		Zone: zone,
		SOA:  dns.SOA{Primary: "ns1." + zone, Hostmaster: "hostmaster@" + zone, Refresh: 10800, Retry: 3600, Expire: 604800, Minimum: 3600, TTL: 3600},
		Records: []dns.Record{
			{Name: "@", Type: "NS", TTL: 3600, Content: "ns1." + zone + "."},
			{Name: "@", Type: "A", TTL: 3600, Content: "203.0.113.10"},
		},
	})
	if err != nil {
		t.Fatal(err)
	}
	p := &probe{t: t, sim: sim, dns: d, zone: zone}
	return NewManager(sim, d, p), sim, p
}

func TestIssueHTTP(t *testing.T) {
	m, sim, p := newTest(t)
	res, err := m.Issue(context.Background(), IssueSpec{Name: zone, Names: []string{zone, "www." + zone}, Email: "admin@" + zone})
	if err != nil {
		t.Fatal(err)
	}
	if !res.Changed || res.Reloaded || res.Issuer != SimulatedIssuer || strings.Join(res.Names, ",") != zone+",www."+zone {
		t.Fatalf("unexpected result %+v", res)
	}
	if time.Until(res.NotAfter) < 80*24*time.Hour {
		t.Fatalf("not after %s", res.NotAfter)
	}
	if len(p.seenHT) != 2 {
		t.Fatalf("HTTP challenges presented: %v", p.seenHT)
	}
	if left, _ := os.ReadDir(sim.Path(ChallengeDir)); len(left) != 0 {
		t.Fatalf("challenge files left behind: %v", left)
	}
	full, key := Files(zone)
	if fi, err := os.Stat(sim.Path(key)); err != nil || fi.Mode().Perm() != 0o600 {
		t.Fatalf("key mode: %v %v", fi, err)
	}
	info, err := m.Get(zone)
	if err != nil || info == nil || info.Fingerprint != res.Fingerprint {
		t.Fatalf("get = %+v, %v", info, err)
	}
	chain, _ := os.ReadFile(sim.Path(full))
	if strings.Count(string(chain), "BEGIN CERTIFICATE") != 2 {
		t.Fatalf("fullchain should hold leaf and issuer:\n%s", chain)
	}
}

func TestIssueWildcardWithDNS(t *testing.T) {
	m, _, p := newTest(t)
	ctx := context.Background()
	res, err := m.Issue(ctx, IssueSpec{Name: zone, Names: []string{zone, "*." + zone}, Challenge: DNS01, DNSZone: zone})
	if err != nil {
		t.Fatal(err)
	}
	if len(res.Names) != 2 || len(p.seenTX) != 2 {
		t.Fatalf("names %v, TXT presented %v", res.Names, p.seenTX)
	}
	z, err := p.dns.GetZone(ctx, zone)
	if err != nil {
		t.Fatal(err)
	}
	for _, r := range z.Records {
		if r.Type == "TXT" {
			t.Fatalf("challenge record left behind: %+v", r)
		}
	}
}

func TestIssueValidation(t *testing.T) {
	m, _, _ := newTest(t)
	for _, spec := range []IssueSpec{
		{Name: "../etc", Names: []string{zone}},
		{Name: zone, Names: []string{"*." + zone}},
		{Name: zone, Names: []string{"other.com"}, Challenge: DNS01, DNSZone: zone},
		{Name: zone, Names: []string{zone, zone}},
		{Name: zone, Names: []string{zone}, Challenge: "tls-alpn-01"},
		{Name: zone, Names: []string{"localhost"}},
	} {
		_, err := m.Issue(context.Background(), spec)
		var v *ValidationError
		if !errors.As(err, &v) {
			t.Errorf("%+v: err = %v, want validation error", spec, err)
		}
	}
}

func TestIssueFailureKeepsOldCertificate(t *testing.T) {
	m, sim, p := newTest(t)
	ctx := context.Background()
	first, err := m.Issue(ctx, IssueSpec{Name: zone, Names: []string{zone}})
	if err != nil {
		t.Fatal(err)
	}
	p.inner.Fail = func(Request) error { return errors.New("urn:ietf:params:acme:error:unauthorized") }
	if _, err := m.Issue(ctx, IssueSpec{Name: zone, Names: []string{zone}}); err == nil {
		t.Fatal("expected error")
	}
	info, _ := m.Get(zone)
	if info == nil || info.Fingerprint != first.Fingerprint {
		t.Fatal("the previous certificate was not kept")
	}
	_ = sim
}

// enableSite writes an enabled vhost that uses certificate name.
func enableSite(t *testing.T, sim *host.Simulator, name string) {
	t.Helper()
	full, _ := Files(name)
	if err := os.WriteFile(sim.Path("/etc/nginx/sites-enabled/"+name+".conf"), []byte("# Managed by Servik\nssl_certificate "+full+";\n"), 0o644); err != nil {
		t.Fatal(err)
	}
}

func TestRenewReloadsNginxAndRollsBack(t *testing.T) {
	m, sim, _ := newTest(t)
	ctx := context.Background()
	first, err := m.Issue(ctx, IssueSpec{Name: zone, Names: []string{zone}})
	if err != nil {
		t.Fatal(err)
	}
	enableSite(t, sim, zone)

	second, err := m.Issue(ctx, IssueSpec{Name: zone, Names: []string{zone}})
	if err != nil {
		t.Fatal(err)
	}
	if !second.Reloaded || !sim.Ran("systemctl reload nginx") || second.Fingerprint == first.Fingerprint {
		t.Fatalf("renewal not picked up: %+v", second)
	}

	sim.Fail = func(c host.Command) error {
		if c.Name == "nginx" {
			return errors.New("SSL_CTX_use_PrivateKey failed")
		}
		return nil
	}
	if _, err := m.Issue(ctx, IssueSpec{Name: zone, Names: []string{zone}}); err == nil || !strings.Contains(err.Error(), "nginx rejected") {
		t.Fatalf("err = %v", err)
	}
	info, _ := m.Get(zone)
	if info.Fingerprint != second.Fingerprint {
		t.Fatal("files were not restored after nginx rejected them")
	}
}

func TestInstallUpload(t *testing.T) {
	m, _, _ := newTest(t)
	ctx := context.Background()
	var sim Simulated
	chain, key, err := sim.Obtain(ctx, Request{Names: []string{zone, "www." + zone}}, nopSolver{})
	if err != nil {
		t.Fatal(err)
	}
	keyPEM, _ := encodeKey(key)
	_, otherKey, _ := sim.Obtain(ctx, Request{Names: []string{zone}}, nopSolver{})
	otherPEM, _ := encodeKey(otherKey)
	expired := Simulated{Validity: -time.Hour}
	oldChain, oldKey, _ := expired.Obtain(ctx, Request{Names: []string{zone}}, nopSolver{})
	oldKeyPEM, _ := encodeKey(oldKey)

	// The chain may come in the wrong order or in its own field.
	res, err := m.Install(ctx, InstallSpec{Name: "upload", Certificate: pemCert(chain[1]) + pemCert(chain[0]), PrivateKey: string(keyPEM), Domain: "www." + zone})
	if err != nil {
		t.Fatal(err)
	}
	if res.Names[0] != zone || res.Issuer != SimulatedIssuer {
		t.Fatalf("unexpected %+v", res)
	}

	for name, spec := range map[string]InstallSpec{
		"wrong key":   {Name: "u", Certificate: pemCert(chain[0]), PrivateKey: string(otherPEM)},
		"expired":     {Name: "u", Certificate: pemCert(oldChain[0]), PrivateKey: string(oldKeyPEM)},
		"not covered": {Name: "u", Certificate: pemCert(chain[0]), PrivateKey: string(keyPEM), Domain: "baska.com"},
		"no cert":     {Name: "u", Certificate: "hello", PrivateKey: string(keyPEM)},
		"no key":      {Name: "u", Certificate: pemCert(chain[0]), PrivateKey: ""},
	} {
		_, err := m.Install(ctx, spec)
		var v *ValidationError
		if !errors.As(err, &v) {
			t.Errorf("%s: err = %v, want validation error", name, err)
		}
	}
}

func TestDeleteRefusesCertificateInUse(t *testing.T) {
	m, sim, _ := newTest(t)
	ctx := context.Background()
	if _, err := m.Issue(ctx, IssueSpec{Name: zone, Names: []string{zone}}); err != nil {
		t.Fatal(err)
	}
	enableSite(t, sim, zone)
	var v *ValidationError
	if _, err := m.Delete(zone); !errors.As(err, &v) {
		t.Fatalf("err = %v, want refusal", err)
	}
	os.Remove(sim.Path("/etc/nginx/sites-enabled/" + zone + ".conf"))
	res, err := m.Delete(zone)
	if err != nil || !res.Deleted {
		t.Fatalf("delete = %+v, %v", res, err)
	}
	if res, err := m.Delete(zone); err != nil || res.Deleted {
		t.Fatalf("second delete = %+v, %v", res, err)
	}
	if info, _ := m.Get(zone); info != nil {
		t.Fatal("certificate still there")
	}
}

func TestIssuePanel(t *testing.T) {
	m, sim, _ := newTest(t)
	res, err := m.IssuePanel(context.Background(), PanelSpec{Hostname: "sunucu1." + zone, Email: "admin@" + zone})
	if err != nil {
		t.Fatal(err)
	}
	if res.Names[0] != "sunucu1."+zone || res.Reloaded {
		t.Fatalf("unexpected %+v", res)
	}
	vhost, err := os.ReadFile(sim.Path(panelVhost))
	if err != nil || !strings.Contains(string(vhost), "server_name sunucu1."+zone+";") {
		t.Fatalf("panel vhost: %s %v", vhost, err)
	}
	if !sim.Ran("nginx -t") {
		t.Fatal("nginx was not checked")
	}
	if info, _ := m.GetPanel(); info == nil || info.Fingerprint != res.Fingerprint {
		t.Fatal("panel certificate not stored")
	}
	if info, _ := m.Get("panel"); info != nil {
		t.Fatal("the panel certificate must not be stored with site certificates")
	}

	// With the panel's web server installed, it is reloaded.
	os.MkdirAll(sim.Path("/etc/systemd/system"), 0o755)
	os.WriteFile(sim.Path("/etc/systemd/system/"+PanelUnit+".service"), nil, 0o644)
	res, err = m.IssuePanel(context.Background(), PanelSpec{Hostname: "sunucu1." + zone})
	if err != nil || !res.Reloaded || !sim.Ran("systemctl reload-or-restart "+PanelUnit) {
		t.Fatalf("panel web server not reloaded: %+v %v", res, err)
	}
}

func TestInstallPanel(t *testing.T) {
	m, sim, _ := newTest(t)
	ctx := context.Background()
	var issuer Simulated
	chain, key, err := issuer.Obtain(ctx, Request{Names: []string{"panel." + zone}}, nopSolver{})
	if err != nil {
		t.Fatal(err)
	}
	keyPEM, _ := encodeKey(key)
	_, otherKey, _ := issuer.Obtain(ctx, Request{Names: []string{zone}}, nopSolver{})
	otherPEM, _ := encodeKey(otherKey)
	expired := Simulated{Validity: -time.Hour}
	oldChain, oldKey, _ := expired.Obtain(ctx, Request{Names: []string{zone}}, nopSolver{})
	oldKeyPEM, _ := encodeKey(oldKey)

	for name, spec := range map[string]PanelInstallSpec{
		"wrong key": {Certificate: pemCert(chain[0]), PrivateKey: string(otherPEM)},
		"expired":   {Certificate: pemCert(oldChain[0]), PrivateKey: string(oldKeyPEM)},
		"no cert":   {Certificate: "hello", PrivateKey: string(keyPEM)},
		"no key":    {Certificate: pemCert(chain[0])},
	} {
		var v *ValidationError
		if _, err := m.InstallPanel(ctx, spec); !errors.As(err, &v) {
			t.Errorf("%s: err = %v, want validation error", name, err)
		}
	}
	if info, _ := m.GetPanel(); info != nil {
		t.Fatal("a refused upload was stored")
	}

	// The panel's web server picks up the uploaded certificate.
	os.MkdirAll(sim.Path("/etc/systemd/system"), 0o755)
	os.WriteFile(sim.Path("/etc/systemd/system/"+PanelUnit+".service"), nil, 0o644)
	changed := false
	m.PanelChanged = func(context.Context) error { changed = true; return nil }
	res, err := m.InstallPanel(ctx, PanelInstallSpec{Certificate: pemCert(chain[1]) + pemCert(chain[0]), PrivateKey: string(keyPEM)})
	if err != nil {
		t.Fatal(err)
	}
	if res.Name != "panel" || res.Names[0] != "panel."+zone || !res.Changed || !res.Reloaded || !changed {
		t.Fatalf("unexpected %+v (panel changed %v)", res, changed)
	}
	if !sim.Ran("systemctl reload-or-restart " + PanelUnit) {
		t.Fatal("panel web server not reloaded")
	}
	if info, _ := m.GetPanel(); info == nil || info.Fingerprint != res.Fingerprint {
		t.Fatal("panel certificate not stored")
	}
	full, keyFile := PanelFiles()
	if fi, err := os.Stat(sim.Path(keyFile)); err != nil || fi.Mode().Perm() != 0o600 {
		t.Fatalf("key file %v %v", fi, err)
	}
	if data, _ := os.ReadFile(sim.Path(full)); !strings.HasPrefix(string(data), pemCert(chain[0])) {
		t.Fatal("the leaf must come first")
	}
	// The same upload again changes nothing.
	if res, err := m.InstallPanel(ctx, PanelInstallSpec{Certificate: pemCert(chain[0]) + pemCert(chain[1]), PrivateKey: string(keyPEM)}); err != nil || res.Changed {
		t.Fatalf("second install %+v %v", res, err)
	}
}

type nopSolver struct{}

func (nopSolver) PresentHTTP(context.Context, string, string, string) error { return nil }
func (nopSolver) CleanupHTTP(string)                                        {}
func (nopSolver) PresentDNS(context.Context, string, []string) error        { return nil }
func (nopSolver) CleanupDNS(context.Context, string)                        {}

// mailServer stands in for the mail server, which reads one certificate.
type mailServer struct {
	name    string
	reloads int
	fail    error
}

func (s *mailServer) UsesCertificate(name string) bool { return name == s.name }

func (s *mailServer) ReloadCertificate(context.Context) error {
	s.reloads++
	return s.fail
}

func TestConsumersReloadAndKeepTheirCertificate(t *testing.T) {
	m, _, _ := newTest(t)
	ctx := context.Background()
	mail := &mailServer{name: "mail"}
	m.AddConsumer(mail)

	first, err := m.Issue(ctx, IssueSpec{Name: "mail", Names: []string{"mail." + zone}})
	if err != nil {
		t.Fatal(err)
	}
	if !first.Reloaded || mail.reloads != 1 {
		t.Fatalf("mail server was not reloaded: %+v, %d reloads", first, mail.reloads)
	}
	if _, err := m.Issue(ctx, IssueSpec{Name: zone, Names: []string{zone}}); err != nil || mail.reloads != 1 {
		t.Fatalf("another certificate reloaded the mail server: %v, %d reloads", err, mail.reloads)
	}

	// A renewal the mail server cannot load is rolled back.
	mail.fail = errors.New("dovecot: bad key")
	if _, err := m.Issue(ctx, IssueSpec{Name: "mail", Names: []string{"mail." + zone}}); err == nil || !strings.Contains(err.Error(), "mail server") {
		t.Fatalf("err = %v", err)
	}
	if info, _ := m.Get("mail"); info.Fingerprint != first.Fingerprint {
		t.Fatal("files were not restored after the mail server rejected them")
	}

	var v *ValidationError
	if _, err := m.Delete("mail"); !errors.As(err, &v) {
		t.Fatalf("deleting the mail certificate: %v", err)
	}
}
