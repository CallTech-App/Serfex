package site

import (
	"bytes"
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"crypto/x509/pkix"
	_ "embed"
	"encoding/pem"
	"errors"
	"fmt"
	"math/big"
	"os"
	"text/template"
	"time"

	"github.com/calltech-app/serfex/agent/internal/cert"
	"github.com/calltech-app/serfex/agent/internal/txn"
)

// The server's default site. Nginx sends a request to the default server
// of its port when no server_name matches: a name whose site has no
// certificate yet (only its port 80 block exists), a name pointed here by
// mistake, the bare IP address. Without one of its own Nginx picks the
// first server it read, which is another customer's site. Serfex's
// default server answers every such request with a 404 page of its own,
// on port 80 and on 443 with a self-signed certificate.
const (
	DefaultSiteDir      = "/var/lib/serfex/default-site"
	defaultSiteAvail    = "/etc/nginx/sites-available/serfex-default.conf"
	defaultSiteEnabled  = "/etc/nginx/sites-enabled/serfex-default.conf"
	defaultSiteTLSDir   = "/etc/serfex/nginx/default"
	defaultSiteCert     = defaultSiteTLSDir + "/cert.pem"
	defaultSiteKey      = defaultSiteTLSDir + "/key.pem"
	distroDefaultLink   = "/etc/nginx/sites-enabled/default"
	distroDefaultTarget = "/etc/nginx/sites-available/default"
)

//go:embed default.html
var defaultSiteHTML string

var defaultSitePage = template.Must(template.New("default-page").Parse(defaultSiteHTML))

var defaultSiteTmpl = template.Must(template.New("default").Parse(`# {{.Marker}}. Manual changes are overwritten.
# The default server: requests for a name no site here serves (a site
# without its certificate yet, a name pointed here by mistake, the IP
# address) get Serfex's "no site" page, never another site.
server {
    listen 80 default_server;
{{- if .IPv6}}
    listen [::]:80 default_server;
{{- end}}
    listen 443 ssl http2 default_server;
{{- if .IPv6}}
    listen [::]:443 ssl http2 default_server;
{{- end}}
    ssl_certificate {{.Cert}};
    ssl_certificate_key {{.Key}};
    include {{.TLSConf}};
    server_name _;

    root {{.Root}};

    # Let's Encrypt validation, here too: a proxy in front (Cloudflare)
    # may send it over HTTPS before the site has a certificate.
    location ^~ /.well-known/acme-challenge/ {
        root {{.ACMERoot}};
        default_type "text/plain";
        try_files $uri =404;
    }

    error_page 404 /index.html;
    location = /index.html {
        internal;
        ssi on;
        add_header Cache-Control "no-store" always;
    }

    location / {
        return 404;
    }
}
`))

// writeDefaultSite writes the default server, its page and certificate,
// and takes the distribution's default site (which claims port 80) out
// of sites-enabled. Its file stays in sites-available.
func (m *Manager) writeDefaultSite(t *txn.Tx) error {
	for _, d := range []string{"/var/lib/serfex", DefaultSiteDir, "/etc/serfex", "/etc/serfex/nginx"} {
		if err := t.RootDir(d, 0o755, 0, 0); err != nil {
			return err
		}
	}
	if err := t.RootDir(defaultSiteTLSDir, 0o700, 0, 0); err != nil {
		return err
	}
	if !m.exists(defaultSiteCert) || !m.exists(defaultSiteKey) {
		certPEM, keyPEM, err := selfSigned("default.serfex.invalid")
		if err != nil {
			return err
		}
		if err := t.WriteOwned(defaultSiteKey, keyPEM, 0o600, 0, 0); err != nil {
			return err
		}
		if err := t.WriteOwned(defaultSiteCert, certPEM, 0o644, 0, 0); err != nil {
			return err
		}
	}
	if err := t.WriteFile(cert.TLSConf, tlsConf); err != nil {
		return err
	}
	var page bytes.Buffer
	if err := defaultSitePage.Execute(&page, map[string]string{"Marker": txn.Marker}); err != nil {
		return err
	}
	if err := t.WriteFile(DefaultSiteDir+"/index.html", page.Bytes()); err != nil {
		return err
	}
	var conf bytes.Buffer
	if err := defaultSiteTmpl.Execute(&conf, map[string]any{
		"Marker": txn.Marker, "IPv6": m.hasIPv6(), "Cert": defaultSiteCert, "Key": defaultSiteKey,
		"TLSConf": cert.TLSConf, "Root": DefaultSiteDir, "ACMERoot": cert.Webroot,
	}); err != nil {
		return err
	}
	if err := t.WriteFile(defaultSiteAvail, conf.Bytes()); err != nil {
		return err
	}
	if err := t.Symlink(defaultSiteAvail, defaultSiteEnabled); err != nil {
		return err
	}
	// Ubuntu's own default site; a link the administrator changed is
	// left alone (and Nginx reports the clash).
	if target, err := os.Readlink(m.h.Path(distroDefaultLink)); err == nil &&
		(target == distroDefaultTarget || target == "../sites-available/default") {
		return t.Unlink(distroDefaultLink)
	}
	return nil
}

// EnsureDefaultSite writes the default server where Nginx is installed and
// reloads Nginx when something changed. Every update runs it, so servers
// set up before it existed get it without a site change.
func (m *Manager) EnsureDefaultSite(ctx context.Context) (changed bool, err error) {
	if !m.exists("/usr/sbin/nginx") || !m.exists("/etc/nginx/sites-enabled") {
		return false, nil
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	t := txn.New(m.h)
	defer func() {
		if err != nil {
			if rbErr := t.Rollback(context.WithoutCancel(ctx)); rbErr != nil {
				err = errors.Join(err, rbErr)
			}
		}
	}()
	if err := m.writeDefaultSite(t); err != nil {
		return false, &OperationError{fmt.Errorf("default site: %w", err)}
	}
	if !t.Changed {
		return false, nil
	}
	if err := m.checkAndReload(ctx, t, nil, false); err != nil {
		return false, err
	}
	return true, nil
}

// selfSigned returns a certificate and key for hostname, valid for ten
// years. The default server shows it to a browser that asks for a name no
// site serves; nobody is meant to trust it.
func selfSigned(hostname string) (certPEM, keyPEM []byte, err error) {
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return nil, nil, err
	}
	serial, err := rand.Int(rand.Reader, new(big.Int).Lsh(big.NewInt(1), 127))
	if err != nil {
		return nil, nil, err
	}
	tmpl := &x509.Certificate{
		SerialNumber:          serial,
		Subject:               pkix.Name{CommonName: hostname, Organization: []string{"Serfex (self-signed)"}},
		DNSNames:              []string{hostname},
		NotBefore:             time.Now().Add(-time.Hour),
		NotAfter:              time.Now().AddDate(10, 0, 0),
		KeyUsage:              x509.KeyUsageDigitalSignature,
		ExtKeyUsage:           []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
		BasicConstraintsValid: true,
	}
	der, err := x509.CreateCertificate(rand.Reader, tmpl, tmpl, &key.PublicKey, key)
	if err != nil {
		return nil, nil, err
	}
	keyDER, err := x509.MarshalPKCS8PrivateKey(key)
	if err != nil {
		return nil, nil, err
	}
	return pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}),
		pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: keyDER}), nil
}
