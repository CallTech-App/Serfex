//go:build realhost

package site

import (
	"context"
	"crypto/tls"
	"io"
	"net"
	"net/http"
	"os"
	"strings"
	"testing"

	"github.com/calltech-app/serfex/agent/internal/cert"
)

// TestRealHostDefaultServer asks real Nginx for names no site serves,
// over HTTP and HTTPS (SNI), next to a site that has a certificate. They
// get Serfex's default page, never another site:
//
//	sudo go test -tags realhost -run TestRealHostDefaultServer ./internal/site
func TestRealHostDefaultServer(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("needs root")
	}
	h := serviceHost{}
	m := NewManager(h, "www-data")
	versions, err := m.PHPVersions()
	if err != nil || len(versions) == 0 {
		t.Fatalf("no PHP-FPM installed: %v", err)
	}
	php := versions[len(versions)-1]
	startIfStopped(t, "/run/nginx.pid", "nginx")
	startIfStopped(t, "/run/php/php"+php+"-fpm.pid", "php-fpm"+php)

	ctx := context.Background()
	// "aaa" sorts first: Nginx used to fall back to the first HTTPS site.
	secure := Spec{Domain: "serfex-aaa.example", User: "serfexaa", PHPVersion: php}
	plain := Spec{Domain: "serfex-zzz.example", User: "serfexzz", PHPVersion: php}
	t.Cleanup(func() {
		for _, s := range []Spec{secure, plain} {
			_, _ = m.Delete(ctx, DeleteSpec{Domain: s.Domain, User: s.User, RemoveFiles: true, RemoveUser: true})
		}
	})
	if _, err := m.Apply(ctx, secure); err != nil {
		t.Fatal(err)
	}
	certs := cert.NewManager(h, nil, &cert.Simulated{})
	if _, err := certs.Issue(ctx, cert.IssueSpec{Name: secure.Domain, Names: []string{secure.Domain}}); err != nil {
		t.Fatal(err)
	}
	secure.TLS = &TLSSpec{Certificate: secure.Domain}
	if _, err := m.Apply(ctx, secure); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Apply(ctx, plain); err != nil {
		t.Fatal(err)
	}

	// The sites answer for their own names.
	fetchTLS(t, secure.Domain, 200, secure.Domain)
	get(t, plain.Domain, "/", plain.Domain)

	// A site without a certificate yet, a name no site has, the bare IP
	// address: the default page, never the other site.
	for _, name := range []string{plain.Domain, "serfex-unknown.example"} {
		if body := fetchTLS(t, name, 404, "No site"); strings.Contains(body, secure.Domain) {
			t.Fatalf("https://%s served %s:\n%s", name, secure.Domain, body)
		}
	}
	if body := getStatus(t, "serfex-unknown.example", "/", 404, "serfex-unknown.example"); strings.Contains(body, secure.Domain) {
		t.Fatalf("http://serfex-unknown.example served %s", secure.Domain)
	}
	getStatus(t, "127.0.0.1", "/", 404, "No site")
}

// fetchTLS requests / over HTTPS with name as SNI and Host, the way a
// proxy that does not check certificates (Cloudflare "Full") does.
func fetchTLS(t *testing.T, name string, status int, want string) string {
	t.Helper()
	client := &http.Client{Transport: &http.Transport{
		TLSClientConfig: &tls.Config{ServerName: name, InsecureSkipVerify: true},
		DialContext: func(ctx context.Context, network, _ string) (net.Conn, error) {
			return (&net.Dialer{}).DialContext(ctx, network, "127.0.0.1:443")
		},
	}}
	var body string
	waitFor(t, func() bool {
		resp, err := client.Get("https://" + name + "/")
		if err != nil {
			body = err.Error()
			return false
		}
		defer resp.Body.Close()
		b, _ := io.ReadAll(resp.Body)
		body = string(b)
		return resp.StatusCode == status && strings.Contains(body, want)
	})
	return body
}
