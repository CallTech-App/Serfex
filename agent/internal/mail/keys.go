package mail

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/rsa"
	"crypto/sha512"
	"crypto/subtle"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/base64"
	"encoding/hex"
	"encoding/pem"
	"errors"
	"fmt"
	"math/big"
	"strings"
	"time"
)

// randomHex returns n random bytes as hex.
func randomHex(n int) (string, error) {
	b := make([]byte, n)
	if _, err := rand.Read(b); err != nil {
		return "", err
	}
	return hex.EncodeToString(b), nil
}

// ssha512 hashes a random, high-entropy secret (the webmail master
// password) in Dovecot's {SSHA512} scheme. Mailbox passwords are hashed
// by the panel with SHA512-CRYPT instead.
func ssha512(secret string) (string, error) {
	salt := make([]byte, 16)
	if _, err := rand.Read(salt); err != nil {
		return "", err
	}
	sum := sha512.Sum512(append([]byte(secret), salt...))
	return "{SSHA512}" + base64.StdEncoding.EncodeToString(append(sum[:], salt...)), nil
}

// selfSigned returns a certificate and key for hostname, valid for ten
// years. Mail services use it until a certificate from the store is set.
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
		Subject:               pkix.Name{CommonName: hostname, Organization: []string{"Servik (self-signed)"}},
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

// certNames returns the DNS names of the first certificate in data.
func certNames(data []byte) ([]string, error) {
	block, _ := pem.Decode(data)
	if block == nil {
		return nil, errors.New("no PEM certificate")
	}
	c, err := x509.ParseCertificate(block.Bytes)
	if err != nil {
		return nil, err
	}
	return c.DNSNames, nil
}

// newDKIMKey returns a 2048-bit RSA key in PKCS#8 PEM, the size every
// receiver accepts and that fits a DNS TXT record.
func newDKIMKey() ([]byte, error) {
	key, err := rsa.GenerateKey(rand.Reader, 2048)
	if err != nil {
		return nil, err
	}
	der, err := x509.MarshalPKCS8PrivateKey(key)
	if err != nil {
		return nil, err
	}
	return pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: der}), nil
}

// dkimRecord returns the TXT record content that publishes the public
// half of a PEM key.
func dkimRecord(keyPEM []byte) (string, error) {
	block, _ := pem.Decode(keyPEM)
	if block == nil {
		return "", errors.New("DKIM key is not PEM")
	}
	key, err := x509.ParsePKCS8PrivateKey(block.Bytes)
	if err != nil {
		return "", err
	}
	rk, ok := key.(*rsa.PrivateKey)
	if !ok {
		return "", fmt.Errorf("DKIM key is %T, not RSA", key)
	}
	pub, err := x509.MarshalPKIXPublicKey(&rk.PublicKey)
	if err != nil {
		return "", err
	}
	return "v=DKIM1; k=rsa; p=" + base64.StdEncoding.EncodeToString(pub), nil
}

// checkSSHA512 reports whether hash is secret in the {SSHA512} scheme.
func checkSSHA512(hash, secret string) bool {
	raw, err := base64.StdEncoding.DecodeString(strings.TrimPrefix(hash, "{SSHA512}"))
	if !strings.HasPrefix(hash, "{SSHA512}") || err != nil || len(raw) <= sha512.Size {
		return false
	}
	sum := sha512.Sum512(append([]byte(secret), raw[sha512.Size:]...))
	return subtle.ConstantTimeCompare(sum[:], raw[:sha512.Size]) == 1
}
