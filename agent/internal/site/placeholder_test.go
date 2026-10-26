package site

import (
	"context"
	"errors"
	"strings"
	"testing"
)

func TestNewSiteGetsTheDefaultPage(t *testing.T) {
	m, sim := newTest(t)
	s := spec
	s.PanelURL = "https://server.kahveatolyesi.com.tr:8443/"
	if _, err := m.Apply(context.Background(), s); err != nil {
		t.Fatal(err)
	}
	page := read(t, sim, "/home/kahveatolyesi/web/kahveatolyesi.com.tr/public_html/index.html")
	for _, want := range []string{
		"<title>kahveatolyesi.com.tr</title>",
		`<span class="domain">kahveatolyesi.com.tr</span>`,
		"PHP " + s.PHPVersion,
		`href="https://server.kahveatolyesi.com.tr:8443/"`,
		`<p class="panel-url">server.kahveatolyesi.com.tr:8443</p>`,
	} {
		if !strings.Contains(page, want) {
			t.Errorf("default page lacks %q", want)
		}
	}
	if strings.Contains(page, "{{") || strings.Contains(page, "example.com") {
		t.Error("default page has template leftovers")
	}
}

func TestDefaultPageWithoutPanelAddress(t *testing.T) {
	page := string(placeholderPage("klinikmavi.com", "8.4", ""))
	if strings.Contains(page, `class="btn btn-primary"`) || strings.Contains(page, `<p class="panel-url">`) {
		t.Errorf("sign-in button shown without a panel address")
	}
	if !strings.Contains(page, `href="#servik"`) {
		t.Error("the page lost its other button")
	}
}

func TestPanelURLIsChecked(t *testing.T) {
	for _, ok := range []string{"https://server.example.com:8443/", "https://server.example.com:8443", "https://185.98.142.21:8443/", "https://[2a0e:1c80:4:2a::21]:8443/"} {
		if err := validatePanelURL(ok); err != nil {
			t.Errorf("%s refused: %v", ok, err)
		}
	}
	for _, bad := range []string{"http://server.example.com:8443/", "javascript:alert(1)", "https://user@server.example.com/", "https://server.example.com/x", "https://server.example.com/?a", "https://server.example.com:99999/", `https://ser"ver.example.com/`, "https:///"} {
		if err := validatePanelURL(bad); err == nil {
			t.Errorf("%s accepted", bad)
		}
	}
	m, _ := newTest(t)
	s := spec
	s.PanelURL = "javascript:alert(1)"
	var v *ValidationError
	if _, err := m.Apply(context.Background(), s); !errors.As(err, &v) {
		t.Errorf("Apply with a bad panel_url: %v", err)
	}
}
