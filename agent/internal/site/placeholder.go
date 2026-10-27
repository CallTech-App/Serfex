package site

import (
	"bytes"
	_ "embed"
	"html/template"
	"net"
	"net/url"
	"strconv"
)

// placeholderHTML is the page a new document root starts with: the
// site's name, how to replace it and what Serfex is. It is one file with
// no outside assets; Turkish by default, English at #en.
//
//go:embed placeholder.html
var placeholderHTML string

var placeholderTmpl = template.Must(template.New("placeholder").Parse(placeholderHTML))

type placeholderData struct {
	Domain     string
	PHPVersion string
	// PanelURL is the panel's address for the sign-in button; empty
	// leaves the button out. PanelHost is the same without the scheme.
	PanelURL  string
	PanelHost string
}

// placeholderPage renders the default page for a domain.
func placeholderPage(domain, php, panelURL string) []byte {
	d := placeholderData{Domain: domain, PHPVersion: php}
	if u, err := url.Parse(panelURL); err == nil && panelURL != "" {
		d.PanelURL = u.String()
		d.PanelHost = u.Host
	}
	var b bytes.Buffer
	if err := placeholderTmpl.Execute(&b, d); err != nil {
		// The template is static and data is validated; this is a bug.
		panic(err)
	}
	return b.Bytes()
}

// validatePanelURL accepts https://name[:port][/] and nothing more.
func validatePanelURL(s string) error {
	u, err := url.Parse(s)
	if err != nil || u.Scheme != "https" || u.User != nil || u.Opaque != "" ||
		(u.Path != "" && u.Path != "/") || u.RawQuery != "" || u.Fragment != "" || u.ForceQuery {
		return invalid("panel_url %q must look like https://server.example.com:8443/", s)
	}
	host := u.Hostname()
	if net.ParseIP(host) == nil && ValidateDomain(host) != nil {
		return invalid("panel_url %q: %q is not a host name or IP address", s, host)
	}
	if p := u.Port(); p != "" {
		if n, err := strconv.Atoi(p); err != nil || n < 1 || n > 65535 {
			return invalid("panel_url %q: bad port", s)
		}
	}
	return nil
}
