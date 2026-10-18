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

// checkHealth requests http://<domain><path> from this server's own
// Nginx, following a redirect to HTTPS, and passes on a 2xx answer. It
// tries three times over a few seconds: PHP-FPM may still be reloading.
func checkHealth(ctx context.Context, domain, path string) HealthResult {
	dialer := &net.Dialer{Timeout: 5 * time.Second}
	client := &http.Client{
		Timeout: 15 * time.Second,
		Transport: &http.Transport{
			// Whatever the DNS says, ask this server.
			DialContext: func(ctx context.Context, network, addr string) (net.Conn, error) {
				_, port, err := net.SplitHostPort(addr)
				if err != nil {
					return nil, err
				}
				return dialer.DialContext(ctx, "tcp", net.JoinHostPort("127.0.0.1", port))
			},
			// The certificate is this server's own; a just-issued or
			// self-signed one must not fail the deploy.
			TLSClientConfig:   &tls.Config{InsecureSkipVerify: true, ServerName: domain}, //nolint:gosec
			DisableKeepAlives: true,
		},
		CheckRedirect: func(req *http.Request, via []*http.Request) error {
			if len(via) >= 3 || req.URL.Hostname() != domain {
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
	req.Header.Set("User-Agent", "Servik health check")
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
	return HealthResult{
		OK:     resp.StatusCode >= 200 && resp.StatusCode < 300,
		Status: resp.StatusCode,
		Millis: time.Since(start).Milliseconds(),
	}
}
