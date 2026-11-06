package laravel

import (
	"context"
	"crypto/tls"
	"errors"
	"io"
	"net"
	"net/http"
	"strings"
	"time"
)

// CheckHealth requests path of a site from this server, as a deploy does.
func (m *Manager) CheckHealth(ctx context.Context, domain, path string) (HealthResult, error) {
	if err := (Target{Domain: domain, User: "x"}).validate(); err != nil {
		return HealthResult{}, err
	}
	if !strings.HasPrefix(path, "/") || len(path) > 200 || strings.ContainsAny(path, " \r\n#") {
		return HealthResult{}, invalid("path must start with / (for example /up)")
	}
	return m.Health(ctx, domain, path), nil
}

// healthDial connects a health check to this server's own Nginx,
// whatever the DNS says (tests point it elsewhere).
var healthDial = func(ctx context.Context, network, addr string) (net.Conn, error) {
	_, port, err := net.SplitHostPort(addr)
	if err != nil {
		return nil, err
	}
	dialer := &net.Dialer{Timeout: 5 * time.Second}
	return dialer.DialContext(ctx, "tcp", net.JoinHostPort("127.0.0.1", port))
}

// sameSite reports whether a redirect stays on the site: its own name or
// the www/bare twin a site's preferred address redirects to.
func sameSite(domain, host string) bool {
	domain, host = strings.ToLower(domain), strings.ToLower(host)
	return host == domain || host == "www."+domain || "www."+host == domain
}

// checkHealth requests http://<domain><path> from this server's own
// Nginx, following redirects to HTTPS and to the site's preferred
// address (www or bare), and passes on a 2xx answer. It tries three
// times over a few seconds: PHP-FPM may still be reloading.
func checkHealth(ctx context.Context, domain, path string) HealthResult {
	client := &http.Client{
		Timeout: 15 * time.Second,
		Transport: &http.Transport{
			DialContext: healthDial,
			// The certificate is this server's own; a just-issued or
			// self-signed one must not fail the deploy. The name sent is
			// the one asked for, so that Nginx picks the site.
			TLSClientConfig:   &tls.Config{InsecureSkipVerify: true}, //nolint:gosec
			DisableKeepAlives: true,
		},
		CheckRedirect: func(req *http.Request, via []*http.Request) error {
			if len(via) >= 5 || !sameSite(domain, req.URL.Hostname()) {
				return http.ErrUseLastResponse
			}
			return nil
		},
	}
	var res HealthResult
	for attempt := 0; attempt < 3; attempt++ {
		if attempt > 0 {
			select {
			case <-ctx.Done():
				return res
			case <-time.After(2 * time.Second):
			}
		}
		res = healthOnce(ctx, client, "http://"+domain+path)
		if res.OK {
			return res
		}
	}
	return res
}

func healthOnce(ctx context.Context, client *http.Client, url string) HealthResult {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return HealthResult{Error: err.Error()}
	}
	req.Header.Set("User-Agent", "Serfex health check")
	start := time.Now()
	resp, err := client.Do(req)
	if err != nil {
		var ne net.Error
		if errors.As(err, &ne) && ne.Timeout() {
			return HealthResult{Error: "no answer within 15 seconds"}
		}
		return HealthResult{Error: "connection failed"}
	}
	defer resp.Body.Close()
	_, _ = io.Copy(io.Discard, io.LimitReader(resp.Body, 1<<20))
	res := HealthResult{
		OK:     resp.StatusCode >= 200 && resp.StatusCode < 300,
		Status: resp.StatusCode,
		Millis: time.Since(start).Milliseconds(),
		URL:    resp.Request.URL.String(),
	}
	if loc := resp.Header.Get("Location"); resp.StatusCode >= 300 && resp.StatusCode < 400 && loc != "" {
		res.Location = loc
	}
	return res
}
