// Package cert obtains, installs and renews TLS certificates: from Let's
// Encrypt (or any ACME server) with HTTP-01 validation through Nginx or
// DNS-01 validation through Serfex's own DNS server, or uploaded by the
// user.
//
// Layout on the server:
//
//	/etc/serfex/ssl/certs/<name>/fullchain.pem   certificate and chain, 0644
//	/etc/serfex/ssl/certs/<name>/privkey.pem     private key, root 0600
//	/etc/serfex/ssl/accounts/<acme host>/        ACME account key and URL, root 0700
//	/etc/serfex/panel/ssl/                       the panel's own certificate
//	/var/lib/serfex/acme/.well-known/acme-challenge/  HTTP-01 answers, served by every site
//	/etc/serfex/nginx/tls.conf                   TLS defaults shared by HTTPS sites
//
// Sites refer to a certificate by name (site.apply's tls.certificate), so
// a renewal only replaces the files and reloads Nginx.
package cert

import (
	"crypto"
	"crypto/ecdsa"
	"crypto/ed25519"
	"crypto/rsa"
	"crypto/sha256"
	"crypto/x509"
	"encoding/hex"
	"encoding/pem"
	"errors"
	"fmt"
	"os"
	"regexp"
	"strings"
	"time"
)

const (
	// Dir holds the certificates sites use, one directory per name.
	Dir = "/etc/serfex/ssl/certs"
	// PanelDir holds the certificate of the panel's own web server. It is
	// kept apart from customer certificates like the panel's services.
	PanelDir = "/etc/serfex/panel/ssl"
	// AccountsDir holds one ACME account per ACME server.
	AccountsDir = "/etc/serfex/ssl/accounts"
	// Webroot is where HTTP-01 answers are written; every Serfex vhost
	// serves /.well-known/acme-challenge/ from here, so validation works
	// whatever the site's own files or framework do.
	Webroot = "/var/lib/serfex/acme"
	// ChallengeDir is the directory inside Webroot that holds the tokens.
	ChallengeDir = Webroot + "/.well-known/acme-challenge"
	// TLSConf holds the TLS settings every HTTPS vhost includes.
	TLSConf = "/etc/serfex/nginx/tls.conf"
)

// ValidationError reports a request that can never succeed as given.
type ValidationError struct{ msg string }

func (e *ValidationError) Error() string { return e.msg }

func invalid(format string, a ...any) error { return &ValidationError{fmt.Sprintf(format, a...)} }

// OperationError reports a valid request that failed on the server or at
// the certificate authority.
type OperationError struct{ Err error }

func (e *OperationError) Error() string { return e.Err.Error() }
func (e *OperationError) Unwrap() error { return e.Err }

var nameRe = regexp.MustCompile(`^[a-z0-9]([a-z0-9.-]{0,200}[a-z0-9])?$`)

// ValidateName checks a certificate name, which becomes a directory name.
func ValidateName(name string) error {
	if !nameRe.MatchString(name) || strings.Contains(name, "..") {
		return invalid("certificate name %q may contain only a-z, 0-9, . and -", name)
	}
	return nil
}

// Files returns the certificate and key paths of a site certificate.
func Files(name string) (fullchain, key string) {
	return Dir + "/" + name + "/fullchain.pem", Dir + "/" + name + "/privkey.pem"
}

// PanelFiles returns the paths of the panel's own certificate.
func PanelFiles() (fullchain, key string) {
	return PanelDir + "/fullchain.pem", PanelDir + "/privkey.pem"
}

// Info describes an installed certificate.
type Info struct {
	Name        string    `json:"name"`
	Names       []string  `json:"names"`
	Issuer      string    `json:"issuer"`
	NotBefore   time.Time `json:"not_before"`
	NotAfter    time.Time `json:"not_after"`
	Serial      string    `json:"serial"`
	Fingerprint string    `json:"fingerprint"`
	// OCSP is true when the certificate names an OCSP responder, so Nginx
	// can staple its answers. Let's Encrypt stopped running OCSP in 2025.
	OCSP       bool `json:"ocsp"`
	SelfSigned bool `json:"self_signed"`
}

// describe summarises a leaf certificate.
func describe(name string, leaf *x509.Certificate) Info {
	issuer := leaf.Issuer.Organization
	in := Info{
		Name:       name,
		Names:      leaf.DNSNames,
		Issuer:     leaf.Issuer.CommonName,
		NotBefore:  leaf.NotBefore.UTC(),
		NotAfter:   leaf.NotAfter.UTC(),
		Serial:     strings.ToUpper(leaf.SerialNumber.Text(16)),
		OCSP:       len(leaf.OCSPServer) > 0,
		SelfSigned: leaf.CheckSignatureFrom(leaf) == nil,
	}
	if len(issuer) > 0 {
		in.Issuer = issuer[0]
	}
	if in.Names == nil {
		in.Names = []string{}
	}
	sum := sha256.Sum256(leaf.Raw)
	in.Fingerprint = strings.ToUpper(hex.EncodeToString(sum[:]))
	return in
}

// ReadInfo parses an installed certificate file.
func ReadInfo(path, name string) (Info, error) {
	data, err := os.ReadFile(path)
	if err != nil {
		return Info{}, err
	}
	chain, err := parseChain(data)
	if err != nil {
		return Info{}, fmt.Errorf("%s: %w", path, err)
	}
	return describe(name, chain[0]), nil
}

// parseChain decodes every CERTIFICATE block in data, in order.
func parseChain(data []byte) ([]*x509.Certificate, error) {
	var out []*x509.Certificate
	for {
		var b *pem.Block
		b, data = pem.Decode(data)
		if b == nil {
			break
		}
		if b.Type != "CERTIFICATE" {
			continue
		}
		c, err := x509.ParseCertificate(b.Bytes)
		if err != nil {
			return nil, fmt.Errorf("the certificate cannot be read: %v", err)
		}
		out = append(out, c)
	}
	if len(out) == 0 {
		return nil, errors.New("no PEM certificate found")
	}
	return out, nil
}

// parseKey decodes a PEM private key in PKCS#8, PKCS#1 or SEC 1 form.
func parseKey(data []byte) (crypto.Signer, error) {
	for {
		var b *pem.Block
		b, data = pem.Decode(data)
		if b == nil {
			return nil, errors.New("no PEM private key found")
		}
		if !strings.HasSuffix(b.Type, "PRIVATE KEY") {
			continue
		}
		if strings.Contains(b.Type, "ENCRYPTED") || b.Headers["Proc-Type"] != "" {
			return nil, errors.New("the private key is protected with a password; upload it without one")
		}
		var k any
		var err error
		switch b.Type {
		case "RSA PRIVATE KEY":
			k, err = x509.ParsePKCS1PrivateKey(b.Bytes)
		case "EC PRIVATE KEY":
			k, err = x509.ParseECPrivateKey(b.Bytes)
		default:
			k, err = x509.ParsePKCS8PrivateKey(b.Bytes)
		}
		if err != nil {
			return nil, fmt.Errorf("the private key cannot be read: %v", err)
		}
		s, ok := k.(crypto.Signer)
		if !ok {
			return nil, errors.New("unsupported private key type")
		}
		switch s.(type) {
		case *rsa.PrivateKey, *ecdsa.PrivateKey, ed25519.PrivateKey:
			return s, nil
		}
		return nil, errors.New("unsupported private key type")
	}
}

// publicKeyEqual reports whether a certificate belongs to key.
func publicKeyEqual(c *x509.Certificate, key crypto.Signer) bool {
	type equaler interface{ Equal(crypto.PublicKey) bool }
	pub, ok := c.PublicKey.(equaler)
	return ok && pub.Equal(key.Public())
}

// encodeChain renders certificates as PEM.
func encodeChain(chain []*x509.Certificate) []byte {
	var b strings.Builder
	for _, c := range chain {
		b.Write(pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: c.Raw}))
	}
	return []byte(b.String())
}

// encodeKey renders a private key as PKCS#8 PEM.
func encodeKey(key crypto.Signer) ([]byte, error) {
	der, err := x509.MarshalPKCS8PrivateKey(key)
	if err != nil {
		return nil, err
	}
	return pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: der}), nil
}
