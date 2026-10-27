package security

import (
	"context"
	"crypto/tls"
	"fmt"
	"net"
	"net/http"
	"regexp"
	"sort"
	"strings"
	"time"

	"github.com/calltech-app/serfex/agent/internal/site"
)

// MaxProtected is how many protected folders a site may have; MaxDenied
// how many blocked paths.
const (
	MaxProtected = 20
	MaxDenied    = 200
	MaxLogins    = 50
)

// ProtectSpec is the whole list of a site's password-protected folders.
type ProtectSpec struct {
	Domain string         `json:"domain"`
	Dirs   []ProtectedDir `json:"dirs"`
}

// ProtectedDir asks for a user name and password under Path.
type ProtectedDir struct {
	// Path is the URL path, such as /admin/ (a folder, with the slash).
	Path string `json:"path"`
	// Realm is shown by the browser's login window.
	Realm string `json:"realm"`
	// Users are names with their crypt(3) hashes ($6$ or $2y$).
	Users []Login `json:"users"`
}

// Login is one user of a protected folder.
type Login struct {
	Name string `json:"name"`
	Hash string `json:"hash"`
}

var (
	loginRe = regexp.MustCompile(`^[A-Za-z0-9._@-]{1,64}$`)
	hashRe  = regexp.MustCompile(`^\$(?:6|5|2[aby])\$[A-Za-z0-9./$=]{20,200}$`)
	realmRe = regexp.MustCompile(`^[\p{L}\p{N} ._,:()-]{0,60}$`)
)

func (s ProtectSpec) validate() error {
	if len(s.Dirs) > MaxProtected {
		return invalid("a site may have at most %d protected folders", MaxProtected)
	}
	seen := map[string]bool{}
	for _, d := range s.Dirs {
		if !validPath(d.Path) || !strings.HasSuffix(d.Path, "/") {
			return invalid("folder %q is not valid; write it as /folder/", d.Path)
		}
		if seen[d.Path] {
			return invalid("folder %s is listed twice", d.Path)
		}
		seen[d.Path] = true
		if !realmRe.MatchString(d.Realm) {
			return invalid("the title of %s may hold letters, digits, spaces and ._,:()- (at most 60)", d.Path)
		}
		if len(d.Users) == 0 || len(d.Users) > MaxLogins {
			return invalid("folder %s needs 1 to %d users", d.Path, MaxLogins)
		}
		names := map[string]bool{}
		for _, u := range d.Users {
			if !loginRe.MatchString(u.Name) || names[u.Name] {
				return invalid("user name %q of %s is not valid or listed twice", u.Name, d.Path)
			}
			names[u.Name] = true
			if !hashRe.MatchString(u.Hash) {
				return invalid("the password of %s is not a crypt hash", u.Name)
			}
		}
	}
	return nil
}

// ApplyProtect writes a site's protected folders. Nginx asks for a login
// before anything under the folder, whatever serves it (PHP, Apache,
// static files), using a variable so the site's other locations stay as
// they are: the longest matching folder wins.
func (m *Manager) ApplyProtect(ctx context.Context, spec ProtectSpec) (WAFResult, error) {
	if err := spec.validate(); err != nil {
		return WAFResult{}, err
	}
	if err := m.checkSite(spec.Domain); err != nil {
		return WAFResult{}, err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	dirs := append([]ProtectedDir{}, spec.Dirs...)
	// Shorter paths first: a later match (the longer, more specific
	// folder) overrides.
	sort.SliceStable(dirs, func(i, j int) bool { return len(dirs[i].Path) < len(dirs[j].Path) })
	extra := site.ExtraDir(spec.Domain)
	snippets := []snippet{}
	var conf strings.Builder
	if len(dirs) > 0 {
		conf.WriteString(header("Password-protected folders of " + spec.Domain + "."))
		conf.WriteString("set $serfex_auth off;\nset $serfex_auth_file /dev/null;\n")
	}
	for i, d := range dirs {
		file := fmt.Sprintf("protect-%d.htpasswd", i+1)
		realm := d.Realm
		if realm == "" {
			realm = "Korumalı alan"
		}
		fmt.Fprintf(&conf, "if ($uri ~ \"^%s\") {\n    set $serfex_auth \"%s\";\n    set $serfex_auth_file %s/%s;\n}\n", regexp.QuoteMeta(d.Path), realm, extra, file)
		var pw strings.Builder
		pw.WriteString(header("Users of " + d.Path + " on " + spec.Domain + "."))
		for _, u := range d.Users {
			pw.WriteString(u.Name + ":" + u.Hash + "\n")
		}
		snippets = append(snippets, snippet{Name: file, Data: []byte(pw.String()), Secret: true})
	}
	var data []byte
	if len(dirs) > 0 {
		// Let's Encrypt must reach the site whatever is protected.
		conf.WriteString("if ($uri ~ \"^/\\.well-known/acme-challenge/\") {\n    set $serfex_auth off;\n}\n")
		conf.WriteString("auth_basic $serfex_auth;\nauth_basic_user_file $serfex_auth_file;\n")
		data = []byte(conf.String())
	}
	snippets = append(snippets, snippet{Name: "protect.conf", Data: data})
	changed, err := m.writeSnippets(ctx, spec.Domain, snippets, func(n string) bool {
		return strings.HasPrefix(n, "protect-") && strings.HasSuffix(n, ".htpasswd")
	})
	return WAFResult{Domain: spec.Domain, Changed: changed}, err
}

// DenySpec is the whole list of paths of a site nobody may fetch.
type DenySpec struct {
	Domain string   `json:"domain"`
	Paths  []string `json:"paths"`
}

// ApplyDeny writes a site's blocked paths: a file (/backup.sql) or a
// folder with a trailing slash (/old/). Nginx answers 403.
func (m *Manager) ApplyDeny(ctx context.Context, spec DenySpec) (WAFResult, error) {
	if len(spec.Paths) > MaxDenied {
		return WAFResult{}, invalid("a site may block at most %d paths", MaxDenied)
	}
	paths := append([]string{}, spec.Paths...)
	sort.Strings(paths)
	for i, p := range paths {
		if !validPath(p) || p == "/" {
			return WAFResult{}, invalid("path %q is not valid", p)
		}
		if i > 0 && paths[i-1] == p {
			return WAFResult{}, invalid("path %s is listed twice", p)
		}
	}
	if err := m.checkSite(spec.Domain); err != nil {
		return WAFResult{}, err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	var data []byte
	if len(paths) > 0 {
		var b strings.Builder
		b.WriteString(header("Paths of " + spec.Domain + " nobody may fetch (Güvenlik taraması)."))
		for _, p := range paths {
			if strings.HasSuffix(p, "/") {
				fmt.Fprintf(&b, "location ^~ %s {\n    deny all;\n}\n", p)
			} else {
				fmt.Fprintf(&b, "location = %s {\n    deny all;\n}\n", p)
			}
		}
		data = []byte(b.String())
	}
	changed, err := m.writeSnippets(ctx, spec.Domain, []snippet{{Name: "deny.conf", Data: data}}, nil)
	return WAFResult{Domain: spec.Domain, Changed: changed}, err
}

// fetchLocal asks this server's Nginx for a site's path, over HTTP and,
// when HTTP redirects, HTTPS; only the status code is read.
func fetchLocal(ctx context.Context, domain, path string) int {
	dial := func(ctx context.Context, network, addr string) (net.Conn, error) {
		_, port, _ := net.SplitHostPort(addr)
		var d net.Dialer
		return d.DialContext(ctx, network, net.JoinHostPort("127.0.0.1", port))
	}
	client := &http.Client{
		Timeout: 5 * time.Second,
		Transport: &http.Transport{
			DialContext:     dial,
			TLSClientConfig: &tls.Config{ServerName: domain, InsecureSkipVerify: true}, // our own server, by name
		},
		CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
	}
	code := 0
	for _, scheme := range []string{"http", "https"} {
		req, err := http.NewRequestWithContext(ctx, http.MethodGet, scheme+"://"+domain+path, nil)
		if err != nil {
			return 0
		}
		req.Header.Set("User-Agent", "Serfex security scan")
		req.Header.Set("Range", "bytes=0-0")
		resp, err := client.Do(req)
		if err != nil {
			return code
		}
		resp.Body.Close()
		code = resp.StatusCode
		if code != http.StatusMovedPermanently && code != http.StatusFound && code != http.StatusPermanentRedirect {
			return code
		}
	}
	return code
}
