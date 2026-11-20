package geoblock

import (
	"context"
	"errors"
	"os"
	"strings"
	"testing"
	"time"

	"github.com/calltech-app/serfex/agent/internal/cloudflare"
	"github.com/calltech-app/serfex/agent/internal/firewall"
	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/site"
	"github.com/calltech-app/serfex/agent/internal/stats"
)

const registry = `demo|CN|ipv4|1.0.1.0|256|20110414|allocated
demo|CN|ipv4|1.0.2.0|512|20110414|allocated
demo|CN|ipv6|2400:da00::|32|20110414|allocated
demo|RU|ipv4|5.8.0.0|65536|20110414|allocated
demo|US|ipv4|3.0.0.0|16777216|19880101|allocated
`

type bed struct {
	m     *Manager
	fw    *firewall.Manager
	sites *site.Manager
	sim   *host.Simulator
	geo   *stats.Geo
}

func table(t *testing.T, lines string) *stats.Geo {
	t.Helper()
	g, err := stats.BuildGeo(strings.NewReader(lines))
	if err != nil {
		t.Fatal(err)
	}
	return g
}

func newTest(t *testing.T, domains ...string) *bed {
	t.Helper()
	sim, err := host.NewSimulator(t.TempDir(), "8.4")
	if err != nil {
		t.Fatal(err)
	}
	b := &bed{sim: sim, fw: firewall.NewManager(sim), sites: site.NewManager(sim, "www-data"), geo: table(t, registry)}
	b.m = NewManager(sim, b.fw)
	b.m.Geo = func() *stats.Geo { return b.geo }
	b.m.Own = func() []string { return []string{"203.0.113.10", "2001:db8:ffff::10"} }
	b.m.Updated = func() time.Time { return time.Date(2026, 11, 2, 4, 0, 0, 0, time.UTC) }
	for _, d := range domains {
		b.addSite(t, d)
	}
	return b
}

func (b *bed) addSite(t *testing.T, domain string) {
	t.Helper()
	user := strings.NewReplacer(".", "", "-", "").Replace(domain)
	if len(user) > 16 {
		user = user[:16]
	}
	if _, err := b.sites.Apply(context.Background(), site.Spec{Domain: domain, User: user, PHPVersion: "8.4"}); err != nil {
		t.Fatalf("site %s: %v", domain, err)
	}
}

func (b *bed) read(t *testing.T, abs string) string {
	t.Helper()
	data, err := os.ReadFile(b.sim.Path(abs))
	if err != nil {
		t.Fatal(err)
	}
	return string(data)
}

func (b *bed) exists(abs string) bool {
	_, err := os.Lstat(b.sim.Path(abs))
	return err == nil
}

func (b *bed) ran(name string, args ...string) int {
	n := 0
	for _, c := range b.sim.Commands() {
		if c.Name == name && strings.Join(c.Args, " ") == strings.Join(args, " ") {
			n++
		}
	}
	return n
}

func (b *bed) reloads() int { return b.ran("systemctl", "reload", "nginx.service") }

func (b *bed) nft() []string {
	var out []string
	for _, c := range b.sim.Commands() {
		if c.Name == "nft" && len(c.Args) == 2 && c.Args[0] == "-f" {
			out = append(out, string(c.Stdin))
		}
	}
	return out
}

func snippet(domain string) string { return site.ExtraDir(domain) + "/" + Snippet }

// noTable makes the simulator a server whose firewall has no Serfex
// table yet, as before the first block; also is consulted first.
func (b *bed) noTable(also func(c host.Command) error) {
	b.sim.Fail = func(c host.Command) error {
		if c.Name == "nft" && len(c.Args) > 0 && c.Args[0] == "list" {
			return errors.New("Error: No such file or directory")
		}
		if also != nil {
			return also(c)
		}
		return nil
	}
}

func TestApplyWritesTheGeoFileAndASnippetForEverySite(t *testing.T) {
	b := newTest(t, "kahve.example", "atolye.example")
	b.noTable(nil)
	reloads := b.reloads()
	st, err := b.m.Apply(context.Background(), Spec{Countries: []string{"ru", "CN", "cn"}})
	if err != nil {
		t.Fatal(err)
	}
	if strings.Join(st.Countries, " ") != "CN RU" || st.Scope != ScopeWeb || !st.Loaded || !st.Nginx || st.Sites != 2 {
		t.Fatalf("status %+v", st)
	}
	if st.Ranges["CN"] != 3 || st.Ranges["RU"] != 1 || st.Updated == nil || !st.Updated.Equal(time.Date(2026, 11, 2, 4, 0, 0, 0, time.UTC)) {
		t.Fatalf("status %+v", st)
	}
	conf := b.read(t, Conf)
	if !strings.HasPrefix(conf, "# Managed by Serfex") {
		t.Errorf("no marker:\n%s", conf)
	}
	for _, want := range []string{
		"geo $serfex_country_listed {\n    default 0;\n",
		"    1.0.1.0/24 1;\n", "    1.0.2.0/23 1;\n", "    5.8.0.0/16 1;\n", "    2400:da00::/32 1;\n",
	} {
		if !strings.Contains(conf, want) {
			t.Errorf("conf lacks %q:\n%s", want, conf)
		}
	}
	if strings.Contains(conf, "3.0.0.0/8") {
		t.Errorf("a country that is not blocked is listed:\n%s", conf)
	}
	for _, d := range []string{"kahve.example", "atolye.example"} {
		if got := b.read(t, snippet(d)); !strings.Contains(got, "if ($serfex_country_refused) {\n    return 403;\n}\n") || !strings.HasPrefix(got, "# Managed by Serfex") {
			t.Errorf("%s snippet:\n%s", d, got)
		}
	}
	if b.reloads() != reloads+1 || b.ran("nginx", "-t") == 0 {
		t.Fatalf("Nginx was checked %d times and reloaded %d times", b.ran("nginx", "-t"), b.reloads()-reloads)
	}
	// Web sites only: the firewall is not asked to hold anything.
	if s := b.nft(); len(s) != 0 {
		t.Fatalf("scope web ran nft: %q", s)
	}
	if got := b.read(t, StateFile); !strings.Contains(got, `"CN"`) || !strings.Contains(got, `"scope": "web"`) {
		t.Fatalf("state file:\n%s", got)
	}
}

func TestLetsEncryptAllowedOwnAndCloudflareAddressesPass(t *testing.T) {
	b := newTest(t, "kahve.example")
	ctx := context.Background()
	if _, err := b.fw.SetAllowed(ctx, []string{"1.0.1.7", "198.51.100.0/24", "2001:db8:1::/48"}); err != nil {
		t.Fatal(err)
	}
	if _, err := b.m.Apply(ctx, Spec{Countries: []string{"CN"}}); err != nil {
		t.Fatal(err)
	}
	conf := b.read(t, Conf)
	exempt := conf[strings.Index(conf, "geo $serfex_country_exempt {"):]
	exempt = exempt[:strings.Index(exempt, "}")]
	for _, want := range []string{
		"    default 0;\n", "    127.0.0.0/8 1;\n", "    ::1 1;\n",
		"    1.0.1.7 1;\n", "    198.51.100.0/24 1;\n", "    2001:db8:1::/48 1;\n",
		"    203.0.113.10 1;\n", "    2001:db8:ffff::10 1;\n",
		"    " + cloudflare.Builtin[0] + " 1;\n",
	} {
		if !strings.Contains(exempt, want) {
			t.Errorf("exempt lacks %q:\n%s", want, exempt)
		}
	}
	// A visitor is refused when listed and not exempt ("01"), except for
	// Let's Encrypt's validation path; the path rule comes first.
	for _, want := range []string{
		`map "$serfex_country_exempt$serfex_country_listed:$uri" $serfex_country_refused {` + "\n    default 0;\n",
		`    "~^01:/\.well-known/acme-challenge/" 0;` + "\n" + `    "~^01:" 1;` + "\n}\n",
	} {
		if !strings.Contains(conf, want) {
			t.Errorf("conf lacks %q:\n%s", want, conf)
		}
	}
}

func TestExemptAddressesAreListedOnce(t *testing.T) {
	b := newTest(t, "kahve.example")
	ctx := context.Background()
	// The server's own address is also on the allowed list.
	if _, err := b.fw.SetAllowed(ctx, []string{"198.51.100.7"}); err != nil {
		t.Fatal(err)
	}
	b.m.Own = func() []string { return []string{"198.51.100.7", "not an address", ""} }
	if _, err := b.m.Apply(ctx, Spec{Countries: []string{"CN"}}); err != nil {
		t.Fatal(err)
	}
	conf := b.read(t, Conf)
	if n := strings.Count(conf, "    198.51.100.7 1;\n"); n != 1 {
		t.Fatalf("198.51.100.7 is listed %d times (Nginx warns about a duplicate):\n%s", n, conf)
	}
	if strings.Contains(conf, "not an address") {
		t.Fatalf("something that is not an address reached Nginx:\n%s", conf)
	}
}

func TestScopeServerFillsTheFirewallSetsAndScopeWebEmptiesThem(t *testing.T) {
	b := newTest(t, "kahve.example")
	ctx := context.Background()
	st, err := b.m.Apply(ctx, Spec{Countries: []string{"CN"}, Scope: ScopeServer})
	if err != nil {
		t.Fatal(err)
	}
	if st.Scope != ScopeServer {
		t.Fatalf("status %+v", st)
	}
	s := b.nft()
	if len(s) == 0 {
		t.Fatal("scope server did not reach the firewall")
	}
	last := s[len(s)-1]
	for _, want := range []string{
		"add element inet serfex country4 { 1.0.1.0/24, 1.0.2.0/23 }\n",
		"add element inet serfex country6 { 2400:da00::/32 }\n",
	} {
		if !strings.Contains(last, want) {
			t.Errorf("nft lacks %q:\n%s", want, last)
		}
	}
	// The sites still refuse the country themselves.
	if !b.exists(Conf) || !b.exists(snippet("kahve.example")) {
		t.Fatal("scope server left Nginx out")
	}
	if _, err := b.m.Apply(ctx, Spec{Countries: []string{"CN"}, Scope: ScopeWeb}); err != nil {
		t.Fatal(err)
	}
	s = b.nft()
	last = s[len(s)-1]
	if !strings.Contains(last, "flush set inet serfex country4\n") || strings.Contains(last, "add element") {
		t.Fatalf("back to web sites only:\n%s", last)
	}
}

func TestAnEmptyListRemovesEveryFileAndReloadsOnce(t *testing.T) {
	b := newTest(t, "kahve.example", "atolye.example")
	ctx := context.Background()
	if _, err := b.m.Apply(ctx, Spec{Countries: []string{"CN", "RU"}, Scope: ScopeServer}); err != nil {
		t.Fatal(err)
	}
	reloads, runs := b.reloads(), len(b.nft())
	st, err := b.m.Apply(ctx, Spec{Scope: ScopeServer})
	if err != nil {
		t.Fatal(err)
	}
	if len(st.Countries) != 0 || st.Sites != 0 || st.Scope != ScopeServer {
		t.Fatalf("status %+v", st)
	}
	for _, f := range []string{Conf, snippet("kahve.example"), snippet("atolye.example")} {
		if b.exists(f) {
			t.Errorf("%s is still there", f)
		}
	}
	if b.reloads() != reloads+1 {
		t.Fatalf("%d reloads", b.reloads()-reloads)
	}
	s := b.nft()
	if len(s) != runs+1 || !strings.Contains(s[len(s)-1], "flush set inet serfex country4\n") || strings.Contains(s[len(s)-1], "add element") {
		t.Fatalf("firewall after the last country left: %q", s[runs:])
	}
}

func TestNothingIsKeptWhenNginxRefuses(t *testing.T) {
	b := newTest(t, "kahve.example")
	b.noTable(nil)
	ctx := context.Background()
	if _, err := b.m.Apply(ctx, Spec{Countries: []string{"CN"}}); err != nil {
		t.Fatal(err)
	}
	before := b.read(t, Conf)
	b.noTable(func(c host.Command) error {
		if c.Name == "nginx" && len(c.Args) == 1 && c.Args[0] == "-t" {
			return errors.New("nginx: [emerg] unknown directive")
		}
		return nil
	})
	_, err := b.m.Apply(ctx, Spec{Countries: []string{"CN", "RU"}, Scope: ScopeServer})
	var v *ValidationError
	if !errors.As(err, &v) || !strings.Contains(err.Error(), "Nginx refused") {
		t.Fatalf("err = %v, want Nginx's refusal as a validation error", err)
	}
	if got := b.read(t, Conf); got != before {
		t.Fatalf("the refused file stayed:\n%s", got)
	}
	b.sim.Fail = nil
	st, err := b.m.Status()
	if err != nil || strings.Join(st.Countries, " ") != "CN" || st.Scope != ScopeWeb {
		t.Fatalf("after a refusal the list is %+v, %v; want what was in force before", st, err)
	}
	if s := b.nft(); len(s) != 0 {
		t.Fatalf("a refused change reached the firewall: %q", s)
	}
}

func TestTheFirewallRefusingPutsNginxBack(t *testing.T) {
	b := newTest(t, "kahve.example")
	ctx := context.Background()
	b.sim.Fail = func(c host.Command) error {
		if c.Name == "nft" && len(c.Args) == 2 && c.Args[0] == "-f" && strings.Contains(string(c.Stdin), "country4 {") && strings.Contains(string(c.Stdin), "add element inet serfex country4") {
			return errors.New("Error: Could not process rule: No buffer space available")
		}
		return nil
	}
	_, err := b.m.Apply(ctx, Spec{Countries: []string{"CN"}, Scope: ScopeServer})
	var op *OperationError
	if !errors.As(err, &op) {
		t.Fatalf("err = %v, want an operation error", err)
	}
	if b.exists(Conf) || b.exists(snippet("kahve.example")) {
		t.Fatal("the sites refuse a country the firewall never took: half a block is in force")
	}
	if st, _ := b.m.Status(); len(st.Countries) != 0 {
		t.Fatalf("status %+v", st)
	}
}

func TestBlockingNeedsTheCountryTable(t *testing.T) {
	b := newTest(t, "kahve.example")
	ctx := context.Background()
	b.geo = nil
	_, err := b.m.Apply(ctx, Spec{Countries: []string{"CN"}})
	var v *ValidationError
	if !errors.As(err, &v) || !strings.Contains(err.Error(), "country table") {
		t.Fatalf("err = %v", err)
	}
	if b.exists(Conf) || b.exists(StateFile) {
		t.Fatal("something was written without a table")
	}
	st, err := b.m.Status()
	if err != nil || st.Loaded || len(st.Countries) != 0 {
		t.Fatalf("status %+v, %v", st, err)
	}
	// Turning the block off never needs the table.
	if _, err := b.m.Apply(ctx, Spec{}); err != nil {
		t.Fatalf("empty list without a table: %v", err)
	}
}

func TestSyncCoversASiteAddedLater(t *testing.T) {
	b := newTest(t, "kahve.example")
	ctx := context.Background()
	if _, err := b.m.Apply(ctx, Spec{Countries: []string{"CN"}, Scope: ScopeServer}); err != nil {
		t.Fatal(err)
	}
	b.addSite(t, "yeni.example")
	if b.exists(snippet("yeni.example")) {
		t.Fatal("the test expects the site manager not to know about countries")
	}
	reloads, runs := b.reloads(), len(b.nft())
	if err := b.m.Sync(ctx); err != nil {
		t.Fatal(err)
	}
	if !b.exists(snippet("yeni.example")) || b.reloads() != reloads+1 {
		t.Fatalf("new site covered: %v, reloads: %d", b.exists(snippet("yeni.example")), b.reloads()-reloads)
	}
	// Nothing changed since: Nginx and the firewall are left alone.
	if err := b.m.Sync(ctx); err != nil {
		t.Fatal(err)
	}
	if b.reloads() != reloads+1 || len(b.nft()) != runs {
		t.Fatalf("an idle sync reloaded Nginx %d times and ran nft %d times", b.reloads()-reloads-1, len(b.nft())-runs)
	}
	if st, _ := b.m.Status(); st.Sites != 2 {
		t.Fatalf("status %+v", st)
	}
}

func TestSyncAfterANewTableRewritesTheRanges(t *testing.T) {
	b := newTest(t, "kahve.example")
	ctx := context.Background()
	if _, err := b.m.Apply(ctx, Spec{Countries: []string{"CN"}, Scope: ScopeServer}); err != nil {
		t.Fatal(err)
	}
	b.geo = table(t, "demo|CN|ipv4|36.0.0.0|65536|20110414|allocated\n")
	if err := b.m.Sync(ctx); err != nil {
		t.Fatal(err)
	}
	conf := b.read(t, Conf)
	if !strings.Contains(conf, "    36.0.0.0/16 1;\n") || strings.Contains(conf, "1.0.1.0/24") {
		t.Fatalf("conf after the new table:\n%s", conf)
	}
	s := b.nft()
	if last := s[len(s)-1]; !strings.Contains(last, "add element inet serfex country4 { 36.0.0.0/16 }\n") || strings.Contains(last, "1.0.1.0/24") {
		t.Fatalf("firewall after the new table:\n%s", last)
	}
}

func TestSyncWaitsForTheTableAfterAStart(t *testing.T) {
	b := newTest(t, "kahve.example")
	ctx := context.Background()
	if _, err := b.m.Apply(ctx, Spec{Countries: []string{"CN"}, Scope: ScopeServer}); err != nil {
		t.Fatal(err)
	}
	conf := b.read(t, Conf)
	// The agent starts again: a new manager, the table not read yet.
	again := NewManager(b.sim, b.fw)
	again.Geo = func() *stats.Geo { return nil }
	if err := again.Sync(ctx); !errors.Is(err, ErrNoTable) {
		t.Fatalf("err = %v, want ErrNoTable", err)
	}
	if got := b.read(t, Conf); got != conf {
		t.Fatal("the ranges were emptied because the table was not read yet")
	}
	// Once it is, the firewall gets its sets back.
	again.Geo = func() *stats.Geo { return b.geo }
	runs := len(b.nft())
	if err := again.Sync(ctx); err != nil {
		t.Fatal(err)
	}
	s := b.nft()
	if len(s) != runs+1 || !strings.Contains(s[len(s)-1], "add element inet serfex country4 { 1.0.1.0/24, 1.0.2.0/23 }\n") {
		t.Fatalf("firewall after the start: %q", s[runs:])
	}
}

func TestCodesAndScopeAreValidated(t *testing.T) {
	b := newTest(t)
	ctx := context.Background()
	many := make([]string, 0, MaxCountries+1)
	for i := 0; len(many) <= MaxCountries; i++ {
		many = append(many, string([]byte{byte('A' + i/26), byte('A' + i%26)}))
	}
	for name, spec := range map[string]Spec{
		"three letters": {Countries: []string{"CHN"}},
		"a digit":       {Countries: []string{"C1"}},
		"empty code":    {Countries: []string{""}},
		"too many":      {Countries: many},
		"unknown scope": {Countries: []string{"CN"}, Scope: "all"},
	} {
		_, err := b.m.Apply(ctx, spec)
		var v *ValidationError
		if !errors.As(err, &v) {
			t.Errorf("%s: err = %v, want a validation error", name, err)
		}
	}
	if b.exists(StateFile) {
		t.Fatal("a refused request was stored")
	}
}

func TestSyncWithNothingBlockedTouchesNothing(t *testing.T) {
	b := newTest(t, "kahve.example")
	before := len(b.sim.Commands())
	if err := b.m.Sync(context.Background()); err != nil {
		t.Fatal(err)
	}
	if n := len(b.sim.Commands()) - before; n != 0 {
		t.Fatalf("a server that blocks no country ran %d commands: %+v", n, b.sim.Commands()[before:])
	}
	for _, f := range []string{Conf, StateFile, snippet("kahve.example")} {
		if b.exists(f) {
			t.Errorf("%s was written", f)
		}
	}
	st, err := b.m.Status()
	if err != nil || len(st.Countries) != 0 || st.Scope != ScopeWeb || !st.Loaded || st.Countries == nil || st.Ranges == nil {
		t.Fatalf("status %+v, %v", st, err)
	}
}

func TestWithoutNginxOnlyTheFirewallIsSet(t *testing.T) {
	b := newTest(t)
	if err := os.RemoveAll(b.sim.Path("/etc/nginx")); err != nil {
		t.Fatal(err)
	}
	ctx := context.Background()
	st, err := b.m.Apply(ctx, Spec{Countries: []string{"RU"}, Scope: ScopeServer})
	if err != nil {
		t.Fatal(err)
	}
	if st.Nginx || st.Sites != 0 || b.exists(Conf) {
		t.Fatalf("status %+v", st)
	}
	if s := b.nft(); len(s) == 0 || !strings.Contains(s[len(s)-1], "country4 { 5.8.0.0/16 }") {
		t.Fatalf("firewall: %q", s)
	}
}
