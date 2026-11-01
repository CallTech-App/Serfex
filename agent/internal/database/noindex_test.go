package database

import (
	"bytes"
	"strings"
	"testing"
)

// Search engines must never list the panel, its sign-in page or the
// database tools: every response carries X-Robots-Tag, and robots.txt
// lets crawlers in so they see it (a page they may not fetch stays listed).
func TestSerfexWebIsNeverIndexed(t *testing.T) {
	var buf bytes.Buffer
	if err := webConfTmpl.Execute(&buf, webData{
		Marker: "m", Ports: []int{8443}, Cert: "c", Key: "k", TLSConf: "tls",
		PanelNames: []string{"server.ornek.com.tr"}, PanelCert: "pc", PanelKey: "pk",
		Socket: "s", WWW: "w", Logs: "l", Temp: "t", WebUser: "www-data",
		PhpMyAdmin: true, Adminer: true,
		Panel: true, PanelRoot: "/opt/serfex/current/panel/public", PanelSocket: "p", PanelUser: "serfex",
		NoIndex: NoIndex,
	}); err != nil {
		t.Fatal(err)
	}
	conf := buf.String()
	header := `add_header X-Robots-Tag "noindex, nofollow, noarchive, nosnippet" always;`
	robots := "location = /robots.txt {\n            default_type text/plain;\n            return 200 \"# Every response here is noindex (X-Robots-Tag).\\nUser-agent: *\\nDisallow:\\n\";\n        }"

	// Both servers: the IP address's and the panel's name's.
	if n := strings.Count(conf, "\n    server {"); n != 2 {
		t.Fatalf("%d servers:\n%s", n, conf)
	}
	for i, srv := range strings.Split(conf, "\n    server {")[1:] {
		if !strings.Contains(srv, "\n        "+header) || !strings.Contains(srv, robots) {
			t.Errorf("server %d is not kept out of search engines:\n%s", i, srv)
		}
	}

	// A block with add_header of its own drops the server's headers in
	// Nginx, so each one must repeat X-Robots-Tag.
	for _, b := range blocks(conf) {
		if strings.Contains(b, "add_header ") && !strings.Contains(b, header) {
			t.Errorf("headers without X-Robots-Tag:\n%s", b)
		}
	}
	// The panel's own header is hidden so the response carries one.
	if !strings.Contains(conf, "fastcgi_hide_header X-Robots-Tag;") {
		t.Error("the panel's X-Robots-Tag comes twice")
	}
}

// blocks returns the directives written directly inside each { } block.
func blocks(conf string) []string {
	var out []string
	stack := []*strings.Builder{{}}
	for _, line := range strings.Split(conf, "\n") {
		l := strings.TrimSpace(line)
		switch {
		case strings.HasPrefix(l, "#"):
		case strings.HasSuffix(l, "{"):
			stack = append(stack, &strings.Builder{})
		case l == "}":
			out = append(out, stack[len(stack)-1].String())
			stack = stack[:len(stack)-1]
		default:
			stack[len(stack)-1].WriteString(l + "\n")
		}
	}
	return out
}
