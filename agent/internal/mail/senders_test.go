package mail

import (
	"context"
	"errors"
	"net/netip"
	"os"
	"strings"
	"testing"
)

func TestSendersLimitSitesToTheirOwnDomains(t *testing.T) {
	m, sim := newTest(t)
	m.Addresses = func() []netip.Addr {
		return []netip.Addr{netip.MustParseAddr("127.0.0.1"), netip.MustParseAddr("203.0.113.7"), netip.MustParseAddr("2001:db8::7"), netip.MustParseAddr("fe80::1")}
	}
	ctx := context.Background()
	if _, err := m.ApplySenders(ctx, SendersSpec{Logins: map[string][]string{"alice": {"alice.test"}}}); !errors.Is(err, ErrNotSetUp) {
		t.Fatalf("err before setup = %v", err)
	}
	setUp(t, m)
	// The maps exist from setup on, so sendmail works before the panel
	// sends anything.
	contains(t, "main.cf", read(t, sim, postfixMain),
		"local_login_sender_maps = texthash:/etc/postfix/serfex/login-senders, static:*",
		"smtpd_restriction_classes = serfex_local_senders",
		"serfex_local_senders = check_sender_access texthash:/etc/postfix/serfex/local-senders",
		"smtpd_sender_restrictions = check_client_access cidr:/etc/postfix/serfex/local-clients")
	if _, err := os.Stat(sim.Path(postfixMaps + "/login-senders")); err != nil {
		t.Fatal(err)
	}
	if _, err := m.ApplyDomain(ctx, domainSpec()); err != nil {
		t.Fatal(err)
	}

	res, err := m.ApplySenders(ctx, SendersSpec{Logins: map[string][]string{
		"alice": {"Alice.test", "www.alice.test", "alice.test"},
		"bob":   {"bob.test"},
		"carol": nil,
	}})
	if err != nil {
		t.Fatal(err)
	}
	if !res.Changed || res.Logins != 3 {
		t.Fatalf("result = %+v", res)
	}
	login := read(t, sim, postfixMaps+"/login-senders")
	contains(t, "login-senders", login, "alice alice, @alice.test, @www.alice.test\n", "bob bob, @bob.test\n", "carol carol\n")
	info, err := os.Stat(sim.Path(postfixMaps + "/login-senders"))
	if err != nil || info.Mode().Perm() != 0o640 {
		t.Fatalf("login-senders mode = %v, %v", info.Mode(), err)
	}
	clients := read(t, sim, postfixMaps+"/local-clients")
	contains(t, "local-clients", clients, "127.0.0.0/8 serfex_local_senders\n", "::1/128 serfex_local_senders\n", "203.0.113.7/32 serfex_local_senders\n", "2001:db8::7/128 serfex_local_senders\n")
	if strings.Contains(clients, "fe80") {
		t.Errorf("link-local address listed:\n%s", clients)
	}
	// Mail domains count as hosted too.
	contains(t, "local-senders", read(t, sim, postfixMaps+"/local-senders"), "alice.test REJECT 5.7.1", "bob.test REJECT", "example.com REJECT", "www.alice.test REJECT")
	if !sim.Ran("systemctl reload postfix") {
		t.Error("postfix was not reloaded")
	}

	// The same request again changes nothing.
	if res, err := m.ApplySenders(ctx, SendersSpec{Logins: map[string][]string{"alice": {"alice.test", "www.alice.test"}, "bob": {"bob.test"}, "carol": {}}}); err != nil || res.Changed {
		t.Fatalf("repeat = %+v, %v", res, err)
	}

	for _, bad := range []map[string][]string{{"root": {"x.test"}}, {"Alice": nil}, {"alice": {"not a domain"}}} {
		if _, err := m.ApplySenders(ctx, SendersSpec{Logins: bad}); err == nil {
			t.Errorf("%v accepted", bad)
		} else if !errors.As(err, new(*ValidationError)) {
			t.Errorf("%v: err = %v, want a validation error", bad, err)
		}
	}
}

func TestRefreshPostfixBringsOldServersToTheNewSettings(t *testing.T) {
	m, sim := newTest(t)
	ctx := context.Background()
	if changed, err := m.RefreshPostfix(ctx); err != nil || changed {
		t.Fatalf("before setup = %v, %v", changed, err)
	}
	setUp(t, m)
	// A server set up by an older release.
	main := read(t, sim, postfixMain)
	old := strings.Replace(main, "local_login_sender_maps", "# local_login_sender_maps", 1)
	if err := os.WriteFile(sim.Path(postfixMain), []byte(old), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.Remove(sim.Path(postfixMaps + "/local-senders")); err != nil {
		t.Fatal(err)
	}
	changed, err := m.RefreshPostfix(ctx)
	if err != nil || !changed {
		t.Fatalf("refresh = %v, %v", changed, err)
	}
	if read(t, sim, postfixMain) != main {
		t.Error("main.cf was not rewritten")
	}
	if _, err := os.Stat(sim.Path(postfixMaps + "/local-senders")); err != nil {
		t.Error("local-senders was not written")
	}
	if changed, err := m.RefreshPostfix(ctx); err != nil || changed {
		t.Fatalf("second refresh = %v, %v", changed, err)
	}
}
