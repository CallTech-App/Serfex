package site

import (
	"context"
	"errors"
	"os"
	"strings"
	"testing"

	"github.com/calltech-app/serfex/agent/internal/cert"
	"github.com/calltech-app/serfex/agent/internal/host"
)

func TestApplyServesSubdomainsFromTheirOwnRoots(t *testing.T) {
	m, sim := newTest(t)
	s := spec
	s.Subdomains = []SubdomainSpec{
		{Name: "blog.kahveatolyesi.com.tr", WebRoot: "subdomains/blog"},
		{Name: "magaza.kahveatolyesi.com.tr", WebRoot: "public_html/magaza"},
	}
	res, err := m.Apply(context.Background(), s)
	if err != nil {
		t.Fatal(err)
	}
	site := "/home/kahveatolyesi/web/kahveatolyesi.com.tr"
	for _, f := range []string{site + "/subdomains/blog/index.html", site + "/public_html/magaza/index.html"} {
		if !exists(sim, f) {
			t.Errorf("%s was not created", f)
		}
	}
	if page := read(t, sim, site+"/subdomains/blog/index.html"); !strings.Contains(page, `<span class="domain">blog.kahveatolyesi.com.tr</span>`) {
		t.Errorf("placeholder names the subdomain:\n%s", page)
	}
	vhost := read(t, sim, res.NginxConfig)
	for _, want := range []string{
		"server_name blog.kahveatolyesi.com.tr;\n\n    root " + site + "/subdomains/blog;",
		"server_name magaza.kahveatolyesi.com.tr;\n\n    root " + site + "/public_html/magaza;",
		"server_name kahveatolyesi.com.tr www.kahveatolyesi.com.tr;\n\n    root " + site + "/public_html;",
	} {
		if !strings.Contains(vhost, want) {
			t.Errorf("vhost lacks %q:\n%s", want, vhost)
		}
	}
	// Every server block uses the site's own PHP pool and logs.
	if n := strings.Count(vhost, "fastcgi_pass unix:/run/php/serfex-kahveatolyesi.com.tr.sock;"); n != 3 {
		t.Errorf("fastcgi_pass %d times, want 3:\n%s", n, vhost)
	}

	// A file the customer put there is kept on the next apply.
	if err := os.WriteFile(sim.Path(site+"/subdomains/blog/index.html"), []byte("mine"), 0o644); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Apply(context.Background(), s); err != nil {
		t.Fatal(err)
	}
	if got := read(t, sim, site+"/subdomains/blog/index.html"); got != "mine" {
		t.Errorf("index.html overwritten: %q", got)
	}

	// Removing a subdomain removes its server block, not its files.
	if _, err := m.Apply(context.Background(), spec); err != nil {
		t.Fatal(err)
	}
	if vhost = read(t, sim, res.NginxConfig); strings.Contains(vhost, "blog.") {
		t.Errorf("subdomain left in vhost:\n%s", vhost)
	}
	if !exists(sim, site+"/subdomains/blog/index.html") {
		t.Error("subdomain files removed")
	}
}

func TestApplyRefusesBadDomains(t *testing.T) {
	m, _ := newTest(t)
	for name, s := range map[string]Spec{
		"subdomain equals alias": {Subdomains: []SubdomainSpec{{Name: "www.kahveatolyesi.com.tr", WebRoot: "subdomains/www"}}},
		"escaping root":          {Subdomains: []SubdomainSpec{{Name: "a.kahveatolyesi.com.tr", WebRoot: "../other"}}},
		"logs root":              {Subdomains: []SubdomainSpec{{Name: "a.kahveatolyesi.com.tr", WebRoot: "logs"}}},
		"no source":              {Redirects: []RedirectSpec{{Target: "https://a.com", Code: 301}}},
		"both sources":           {Redirects: []RedirectSpec{{Hosts: []string{"a.com"}, Path: "/x", Target: "https://a.com", Code: 301}}},
		"bad code":               {Redirects: []RedirectSpec{{Path: "/x", Target: "/y", Code: 307}}},
		"variable in target":     {Redirects: []RedirectSpec{{Path: "/x", Target: "https://a.com/$host", Code: 301}}},
		"quote in target":        {Redirects: []RedirectSpec{{Path: "/x", Target: `https://a.com/"`, Code: 301}}},
		"javascript target":      {Redirects: []RedirectSpec{{Path: "/x", Target: "javascript:alert(1)", Code: 301}}},
		"relative host target":   {Redirects: []RedirectSpec{{Hosts: []string{"eski.com"}, Target: "/yeni", Code: 301}}},
		"root path":              {Redirects: []RedirectSpec{{Path: "/", Target: "https://a.com", Code: 301}}},
		"acme path":              {Redirects: []RedirectSpec{{Path: "/.well-known/acme-challenge", Target: "https://a.com", Code: 301}}},
		"query and keep path":    {Redirects: []RedirectSpec{{Path: "/x", Target: "https://a.com/?a=1", Code: 301, KeepPath: true}}},
		"redirect host taken":    {Redirects: []RedirectSpec{{Hosts: []string{"kahveatolyesi.com.tr"}, Target: "https://a.com", Code: 301}}},
		"unknown error page":     {ErrorPages: map[string]string{"401": "x"}},
		"open block":             {NginxDirectives: "location /a {\n  return 200;\n"},
		"closing block":          {NginxDirectives: "}\nserver {"},
		"apache open block":      {ApacheDirectives: "<Directory /x>\n{"},
		"preferred elsewhere":    {PreferredHost: "kahveatolyesi.com"},
		"preferred subdomain":    {PreferredHost: "blog.kahveatolyesi.com.tr"},
	} {
		t.Run(name, func(t *testing.T) {
			s.Domain, s.User, s.PHPVersion, s.Aliases = spec.Domain, spec.User, spec.PHPVersion, spec.Aliases
			_, err := m.Apply(context.Background(), s)
			var v *ValidationError
			if !errors.As(err, &v) {
				t.Fatalf("err = %v, want a validation error", err)
			}
		})
	}
	// The preferred host's pair must be one of the site's names.
	s := spec
	s.Aliases, s.PreferredHost = nil, spec.Domain
	if _, err := m.Apply(context.Background(), s); !errors.As(err, new(*ValidationError)) {
		t.Errorf("preferred host without www: err = %v, want a validation error", err)
	}
	// Braces inside quotes and comments do not count.
	s = spec
	s.NginxDirectives = "# a } comment\nadd_header X-Test \"a{b\";"
	if _, err := m.Apply(context.Background(), s); err != nil {
		t.Fatalf("directives with quoted braces: %v", err)
	}
}

func TestApplyWritesRedirects(t *testing.T) {
	m, sim := newTest(t)
	s := spec
	s.Redirects = []RedirectSpec{
		{Path: "/eski-menu", Target: "/menu", Code: 301},
		{Path: "/blog", Target: "https://blog.kahveatolyesi.com.tr/", Code: 302, KeepPath: true},
		{Hosts: []string{"eskikahve.com", "www.eskikahve.com"}, Target: "https://kahveatolyesi.com.tr/", Code: 301, KeepPath: true},
		{Hosts: []string{"kampanya.com"}, Target: "https://kahveatolyesi.com.tr/kampanya", Code: 302},
	}
	res, err := m.Apply(context.Background(), s)
	if err != nil {
		t.Fatal(err)
	}
	vhost := read(t, sim, res.NginxConfig)
	for _, want := range []string{
		"location = /eski-menu {\n        return 301 \"/menu\";",
		"location ~ \"^/eski-menu(/.*)$\" {\n        return 301 \"/menu\";",
		"location = /blog {\n        return 302 \"https://blog.kahveatolyesi.com.tr/$is_args$args\";",
		"location ~ \"^/blog(/.*)$\" {\n        return 302 \"https://blog.kahveatolyesi.com.tr$1$is_args$args\";",
		"server_name eskikahve.com www.eskikahve.com;",
		"return 301 \"https://kahveatolyesi.com.tr$request_uri\";",
		"server_name kampanya.com;",
		"return 302 \"https://kahveatolyesi.com.tr/kampanya\";",
	} {
		if !strings.Contains(vhost, want) {
			t.Errorf("vhost lacks %q:\n%s", want, vhost)
		}
	}
	// Path redirects come before the PHP location, so /blog/x.php
	// redirects too.
	if strings.Index(vhost, "^/blog(/.*)$") > strings.Index(vhost, "location ~ \\.php$") {
		t.Errorf("redirect after the PHP location:\n%s", vhost)
	}
	// A redirect-only host still answers Let's Encrypt.
	block := vhost[strings.Index(vhost, "server_name kampanya.com;"):]
	if !strings.Contains(block, "/.well-known/acme-challenge/") {
		t.Errorf("redirect host lacks ACME:\n%s", block)
	}
}

func TestApplyWritesErrorPagesAndDirectives(t *testing.T) {
	m, sim := newTest(t)
	s := spec
	s.ErrorPages = map[string]string{"404": "<h1>Bulunamadı</h1>", "50x": "<h1>Arıza</h1>"}
	s.NginxDirectives = "location /durum {\n    return 200 \"tamam\";\n}"
	res, err := m.Apply(context.Background(), s)
	if err != nil {
		t.Fatal(err)
	}
	vhost := read(t, sim, res.NginxConfig)
	for _, want := range []string{
		"error_page 404 /serfex-error-404.html;\n    location = /serfex-error-404.html {\n        root /var/lib/serfex/errors/kahveatolyesi.com.tr;\n        internal;",
		"error_page 500 502 504 /serfex-error-50x.html;",
		"    location /durum {\n        return 200 \"tamam\";\n    }",
	} {
		if !strings.Contains(vhost, want) {
			t.Errorf("vhost lacks %q:\n%s", want, vhost)
		}
	}
	if strings.Contains(vhost, "error_page 403") {
		t.Error("403 page written without being asked for")
	}
	page := read(t, sim, "/var/lib/serfex/errors/kahveatolyesi.com.tr/serfex-error-404.html")
	if !strings.HasSuffix(page, "<h1>Bulunamadı</h1>") || !strings.Contains(page, "Serfex") {
		t.Errorf("404 page:\n%s", page)
	}

	// Taking a page away removes its file.
	delete(s.ErrorPages, "50x")
	if _, err := m.Apply(context.Background(), s); err != nil {
		t.Fatal(err)
	}
	if exists(sim, "/var/lib/serfex/errors/kahveatolyesi.com.tr/serfex-error-50x.html") {
		t.Error("50x page left behind")
	}

	// Directives Nginx refuses change nothing; the test output comes back.
	sim.Fail = func(c host.Command) error {
		if c.Name == "nginx" && strings.Contains(read(t, sim, res.NginxConfig), "bilinmeyen_yonerge") {
			return errors.New(`nginx: [emerg] unknown directive "bilinmeyen_yonerge" in /etc/nginx/sites-enabled/kahveatolyesi.com.tr.conf:40`)
		}
		return nil
	}
	before := read(t, sim, res.NginxConfig)
	bad := s
	bad.NginxDirectives = "bilinmeyen_yonerge on;"
	_, err = m.Apply(context.Background(), bad)
	var ce *ConfigError
	if !errors.As(err, &ce) || ce.Server != "nginx" || !strings.Contains(ce.Output, `unknown directive "bilinmeyen_yonerge"`) {
		t.Fatalf("err = %v, want a config error", err)
	}
	if read(t, sim, res.NginxConfig) != before {
		t.Error("refused directives left in the vhost")
	}

	// Deleting the site removes its error pages.
	sim.Fail = nil
	if _, err := m.Delete(context.Background(), DeleteSpec{Domain: spec.Domain, User: spec.User}); err != nil {
		t.Fatal(err)
	}
	if exists(sim, "/var/lib/serfex/errors/kahveatolyesi.com.tr") {
		t.Error("error pages left after delete")
	}
}

func TestApplyKeepsNamesWithoutCertificateOnHTTP(t *testing.T) {
	m, sim := newTest(t)
	ctx := context.Background()
	s := spec
	s.Aliases = []string{"www.kahveatolyesi.com.tr", "kahveatolyesi.com"}
	s.Subdomains = []SubdomainSpec{
		{Name: "blog.kahveatolyesi.com.tr", WebRoot: "subdomains/blog"},
		{Name: "yeni.kahveatolyesi.com.tr", WebRoot: "subdomains/yeni"},
	}
	// The certificate has the domain, www and blog; the new alias and
	// the new subdomain do not point here yet.
	certs := cert.NewManager(sim, nil, &cert.Simulated{})
	if _, err := certs.Issue(ctx, cert.IssueSpec{Name: spec.Domain, Names: []string{spec.Domain, "www.kahveatolyesi.com.tr", "blog.kahveatolyesi.com.tr"}}); err != nil {
		t.Fatal(err)
	}
	s.TLS = &TLSSpec{Certificate: spec.Domain, Redirect: true}
	res, err := m.Apply(ctx, s)
	if err != nil {
		t.Fatal(err)
	}
	vhost := read(t, sim, res.NginxConfig)
	// Covered names redirect to HTTPS; the rest stay on HTTP.
	redirect := "server_name kahveatolyesi.com.tr www.kahveatolyesi.com.tr;\n\n    access_log"
	if !strings.Contains(vhost, redirect) {
		t.Errorf("redirect block for the covered names missing:\n%s", vhost)
	}
	plain := vhost[strings.Index(vhost, "server_name kahveatolyesi.com;"):]
	if strings.Contains(plain[:strings.Index(plain, "\n}")], "443") || strings.Contains(plain[:strings.Index(plain, "\n}")], "return 301 https") {
		t.Errorf("uncovered alias must stay on HTTP:\n%s", vhost)
	}
	blog := vhost[strings.Index(vhost, "# Subdomain blog."):]
	if !strings.Contains(blog, "listen 443 ssl http2;") || !strings.Contains(blog, "return 301 https://$host$request_uri;") {
		t.Errorf("covered subdomain should serve HTTPS:\n%s", blog)
	}
	yeni := vhost[strings.Index(vhost, "# Subdomain yeni."):]
	if strings.Contains(yeni, "443") || strings.Contains(yeni, "return 301") {
		t.Errorf("uncovered subdomain must stay on HTTP:\n%s", yeni)
	}
	if strings.Count(vhost, "server_name kahveatolyesi.com.tr ") != 2 {
		t.Errorf("covered names must be in the redirect and the HTTPS block only:\n%s", vhost)
	}
}

func TestApplyRedirectsToThePreferredHost(t *testing.T) {
	m, sim := newTest(t)
	ctx := context.Background()
	const www = "www.kahveatolyesi.com.tr"
	certs := cert.NewManager(sim, nil, &cert.Simulated{})
	issue := func(names ...string) {
		t.Helper()
		if _, err := certs.Issue(ctx, cert.IssueSpec{Name: spec.Domain, Names: names}); err != nil {
			t.Fatal(err)
		}
	}
	apply := func(s Spec) string {
		t.Helper()
		res, err := m.Apply(ctx, s)
		if err != nil {
			t.Fatal(err)
		}
		return read(t, sim, res.NginxConfig)
	}
	// block is the server block that serves name.
	block := func(vhost, name string) string {
		t.Helper()
		i := strings.LastIndex(vhost, "server_name "+name+";")
		if i < 0 {
			t.Fatalf("no server block for %s alone:\n%s", name, vhost)
		}
		start := strings.LastIndex(vhost[:i], "server {")
		return vhost[start : i+strings.Index(vhost[i:], "\n}")]
	}

	// Without a certificate the other name is sent to http://.
	s := spec
	s.PreferredHost = spec.Domain
	vhost := apply(s)
	if strings.Contains(vhost, "server_name kahveatolyesi.com.tr www.") {
		t.Errorf("www still served by the site:\n%s", vhost)
	}
	if b := block(vhost, www); !strings.Contains(b, `return 301 "http://kahveatolyesi.com.tr$request_uri";`) || strings.Contains(b, "443") {
		t.Errorf("www redirect without a certificate:\n%s", b)
	}

	// With a certificate for both: HTTPS on both, one hop to the preferred name.
	issue(spec.Domain, www)
	s.TLS = &TLSSpec{Certificate: spec.Domain, Redirect: true}
	vhost = apply(s)
	b := block(vhost, www)
	for _, want := range []string{"listen 80;", "listen 443 ssl http2;", `return 301 "https://kahveatolyesi.com.tr$request_uri";`, "/.well-known/acme-challenge/"} {
		if !strings.Contains(b, want) {
			t.Errorf("www redirect lacks %q:\n%s", want, b)
		}
	}
	if strings.Count(vhost, "server_name kahveatolyesi.com.tr;") != 2 {
		t.Errorf("the domain should have its HTTP redirect and HTTPS blocks:\n%s", vhost)
	}

	// The other way round.
	s.PreferredHost = www
	vhost = apply(s)
	if b := block(vhost, spec.Domain); !strings.Contains(b, `return 301 "https://www.kahveatolyesi.com.tr$request_uri";`) || !strings.Contains(b, "listen 443 ssl http2;") {
		t.Errorf("domain redirect:\n%s", b)
	}
	if !strings.Contains(vhost, "server_name "+www+";\n\n    root ") {
		t.Errorf("www should serve the site:\n%s", vhost)
	}

	if !strings.Contains(vhost, "}\n\n# Preferred address (Tercih edilen adres): kahveatolyesi.com.tr goes to www.kahveatolyesi.com.tr\nserver {") {
		t.Errorf("redirect block not explained:\n%s", vhost)
	}

	// A certificate without the preferred name: both stay as they are.
	issue(spec.Domain)
	vhost = apply(s)
	if strings.Contains(vhost, `return 301 "https://www.`) || strings.Contains(vhost, `return 301 "http://www.`) {
		t.Errorf("redirected to a name the certificate does not cover:\n%s", vhost)
	}
	if !strings.Contains(vhost, "server_name "+www+";\n\n    root ") || !strings.Contains(vhost, "server_name kahveatolyesi.com.tr;\n\n    root ") {
		t.Errorf("both names should be served:\n%s", vhost)
	}
}

func TestLogsReadsFromAnOffset(t *testing.T) {
	m, sim := newTest(t)
	if _, err := m.Apply(context.Background(), spec); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(sim.Path(AccessLog(spec.Domain)), []byte("0123456789"), 0o644); err != nil {
		t.Fatal(err)
	}
	off := int64(3)
	got, err := m.Logs(LogsSpec{Domain: spec.Domain, User: spec.User, Kind: "access", Bytes: 4, Offset: &off})
	if err != nil || got.Content != "3456" || got.Size != 10 || got.Next != 7 {
		t.Fatalf("got %+v, %v", got, err)
	}
	off = 20
	if got, err = m.Logs(LogsSpec{Domain: spec.Domain, User: spec.User, Kind: "access", Bytes: 4, Offset: &off}); err != nil || got.Content != "" {
		t.Fatalf("past the end: %+v, %v", got, err)
	}
	if _, err := m.Logs(LogsSpec{Domain: spec.Domain, User: spec.User, Kind: "apache"}); err != nil {
		t.Fatalf("apache log: %v", err)
	}
}
