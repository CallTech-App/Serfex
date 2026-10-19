//go:build realhost

// This test provisions a real site with real Nginx and PHP-FPM. It needs
// root and changes the machine, so it only builds with -tags realhost:
//
//	sudo go test -tags realhost -run TestRealHost ./internal/site
//
// CI runs it on a throwaway runner with nginx and php-fpm installed.
package site

import (
	"context"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"os/exec"
	"strings"
	"testing"
	"time"

	"github.com/winchester37/servik/agent/internal/host"
)

// serviceHost is the real system, except that "systemctl reload" is done
// with signals so the test also runs where systemd is not PID 1.
type serviceHost struct{ host.System }

func (h serviceHost) Run(ctx context.Context, c host.Command) (string, error) {
	if c.Name == "systemctl" && len(c.Args) == 2 && c.Args[0] == "reload" {
		unit := c.Args[1]
		if unit == "nginx" {
			return h.System.Run(ctx, host.Command{Name: "nginx", Args: []string{"-s", "reload"}})
		}
		ver := strings.TrimSuffix(strings.TrimPrefix(unit, "php"), "-fpm")
		return h.System.Run(ctx, host.Command{Name: "sh", Args: []string{"-c", fmt.Sprintf("kill -USR2 $(cat /run/php/php%s-fpm.pid)", ver)}})
	}
	return h.System.Run(ctx, c)
}

func TestRealHost(t *testing.T) {
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
	spec := Spec{Domain: "servik-test.example", User: "servikt", PHPVersion: php, Aliases: []string{"www.servik-test.example"}}
	t.Cleanup(func() {
		_, _ = m.Delete(ctx, DeleteSpec{Domain: spec.Domain, User: spec.User, RemoveFiles: true, RemoveUser: true})
	})

	res, err := m.Apply(ctx, spec)
	if err != nil {
		t.Fatal(err)
	}
	if !res.UserCreated {
		t.Fatal("user was not created")
	}

	// Static placeholder is served by Nginx.
	if body := get(t, "www.servik-test.example", "/", "servik-test.example"); !strings.Contains(body, "servik-test.example") {
		t.Fatalf("placeholder not served: %q", body)
	}

	// PHP runs as the site's own user, confined to its home.
	script := host.AsUser("servikt", host.Command{Name: "tee", Args: []string{res.DocumentRoot + "/whoami.php"},
		Stdin: []byte(`<?php echo get_current_user(), ':', trim(shell_exec('id -un') ?? posix_getpwuid(posix_geteuid())['name']), ':', ini_get('open_basedir');`)})
	if _, err := h.Run(ctx, script); err != nil {
		t.Fatal(err)
	}
	waitFor(t, func() bool { return fileExists(res.PHPSocket) })
	body := get(t, "servik-test.example", "/whoami.php", "servikt")
	if !strings.HasPrefix(body, "servikt:servikt:/home/servikt/") {
		t.Fatalf("PHP did not run as the site user: %q", body)
	}

	// The account's home is measured, a 3 MB file included.
	blob := host.AsUser("servikt", host.Command{Name: "dd", Args: []string{"if=/dev/zero", "of=" + res.DocumentRoot + "/blob.bin", "bs=1M", "count=3"}})
	if _, err := h.Run(ctx, blob); err != nil {
		t.Fatal(err)
	}
	disk, err := m.DiskUsage(ctx, DiskSpec{Users: []string{"servikt"}})
	if err != nil || disk["servikt"].Bytes < 3<<20 || disk["servikt"].Files < 3 {
		t.Fatalf("disk usage = %+v, %v", disk, err)
	}

	// A suspended site answers 503 with the suspension page, PHP included,
	// and serves again once the suspension is lifted.
	suspended := spec
	suspended.Suspended = true
	if _, err := m.Apply(ctx, suspended); err != nil {
		t.Fatal(err)
	}
	if body := getStatus(t, "servik-test.example", "/whoami.php", 503, "hizmet dışı"); strings.Contains(body, "servikt:") {
		t.Fatalf("PHP ran on a suspended site: %q", body)
	}
	if _, err := m.Apply(ctx, spec); err != nil {
		t.Fatal(err)
	}
	get(t, "servik-test.example", "/whoami.php", "servikt")

	// Applying again changes nothing.
	again, err := m.Apply(ctx, spec)
	if err != nil || again.Changed {
		t.Fatalf("second apply: changed=%v err=%v", again.Changed, err)
	}

	// Other customers cannot read the site's files.
	if fi, err := os.Stat("/home/servikt"); err != nil || fi.Mode().Perm() != 0o710 {
		t.Fatalf("home mode: %v %v", fi.Mode(), err)
	}

	del, err := m.Delete(ctx, DeleteSpec{Domain: spec.Domain, User: spec.User, RemoveFiles: true, RemoveUser: true})
	if err != nil {
		t.Fatal(err)
	}
	if !del.UserRemoved || fileExists("/home/servikt") {
		t.Fatalf("site not fully removed: %+v", del)
	}
	if _, err := h.LookupUser("servikt"); !errors.Is(err, host.ErrUserNotFound) {
		t.Fatal("account still exists")
	}
}

func startIfStopped(t *testing.T, pidFile, bin string) {
	t.Helper()
	if fileExists(pidFile) {
		return
	}
	if out, err := exec.Command(bin).CombinedOutput(); err != nil {
		t.Fatalf("start %s: %v: %s", bin, err, out)
	}
	waitFor(t, func() bool { return fileExists(pidFile) })
}

// get fetches path from the local Nginx, retrying until the response
// contains want (reloads take effect asynchronously).
func get(t *testing.T, hostname, path, want string) string {
	t.Helper()
	var body string
	waitFor(t, func() bool {
		req, _ := http.NewRequest("GET", "http://127.0.0.1"+path, nil)
		req.Host = hostname
		resp, err := http.DefaultClient.Do(req)
		if err != nil {
			return false
		}
		defer resp.Body.Close()
		b, _ := io.ReadAll(resp.Body)
		body = string(b)
		return resp.StatusCode == 200 && strings.Contains(body, want)
	})
	return body
}

// getStatus is get for answers other than 200.
func getStatus(t *testing.T, hostname, path string, status int, want string) string {
	t.Helper()
	var body string
	waitFor(t, func() bool {
		req, _ := http.NewRequest("GET", "http://127.0.0.1"+path, nil)
		req.Host = hostname
		resp, err := http.DefaultClient.Do(req)
		if err != nil {
			return false
		}
		defer resp.Body.Close()
		b, _ := io.ReadAll(resp.Body)
		body = string(b)
		return resp.StatusCode == status && strings.Contains(body, want)
	})
	return body
}

func waitFor(t *testing.T, ok func() bool) {
	t.Helper()
	for i := 0; i < 50; i++ {
		if ok() {
			return
		}
		time.Sleep(100 * time.Millisecond)
	}
	t.Helper()
	t.Fatal("timed out")
}

func fileExists(p string) bool {
	_, err := os.Stat(p)
	return err == nil
}
