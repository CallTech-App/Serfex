package laravel

import (
	"context"
	"net"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestSameSite(t *testing.T) {
	for host, want := range map[string]bool{"roifex.com": true, "www.roifex.com": true, "WWW.Roifex.com": true, "shop.roifex.com": false, "evil.com": false} {
		if got := sameSite("roifex.com", host); got != want {
			t.Errorf("sameSite(roifex.com, %s) = %v", host, got)
		}
	}
	if !sameSite("www.roifex.com", "roifex.com") {
		t.Error("www site redirecting to bare")
	}
}

// A site whose preferred address is www answers /up after a redirect;
// a redirect to another site is reported, not followed.
func TestCheckHealthFollowsThePreferredAddress(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case r.Host == "roifex.com":
			http.Redirect(w, r, "http://www.roifex.com"+r.URL.Path, http.StatusMovedPermanently)
		case r.Host == "www.roifex.com" && r.URL.Path == "/up":
			w.WriteHeader(http.StatusOK)
		case r.Host == "old.example":
			http.Redirect(w, r, "https://elsewhere.example/up", http.StatusMovedPermanently)
		default:
			w.WriteHeader(http.StatusNotFound)
		}
	}))
	defer srv.Close()
	saved := healthDial
	healthDial = func(ctx context.Context, network, _ string) (net.Conn, error) {
		return (&net.Dialer{}).DialContext(ctx, network, strings.TrimPrefix(srv.URL, "http://"))
	}
	defer func() { healthDial = saved }()

	res := checkHealth(context.Background(), "roifex.com", "/up")
	if !res.OK || res.Status != 200 || res.URL != "http://www.roifex.com/up" {
		t.Errorf("www redirect: %+v", res)
	}
	res = healthOnce(context.Background(), &http.Client{Transport: &http.Transport{DialContext: healthDial},
		CheckRedirect: func(req *http.Request, _ []*http.Request) error {
			if !sameSite("old.example", req.URL.Hostname()) {
				return http.ErrUseLastResponse
			}
			return nil
		}}, "http://old.example/up")
	if res.OK || res.Status != 301 || res.Location != "https://elsewhere.example/up" {
		t.Errorf("other site: %+v", res)
	}
}
