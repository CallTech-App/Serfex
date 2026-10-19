package component

import (
	"context"
	"strings"
	"testing"
	"time"
)

func TestParseFail2ban(t *testing.T) {
	names := parseJailList("Status\n|- Number of jail:\t3\n`- Jail list:\tsshd, servik-panel, recidive\n")
	if strings.Join(names, ",") != "recidive,servik-panel,sshd" {
		t.Fatalf("jails = %v", names)
	}
	bans := parseBans("203.0.113.5 \t2026-10-19 13:00:00 + 3600 = 2026-10-19 14:00:00\n2001:db8::1 \t2026-10-19 13:00:00 + -1 = 9999-12-31 23:59:59\nnot an ip\n", time.UTC)
	if len(bans) != 2 || bans[0].Address != "203.0.113.5" || bans[0].Until == nil || bans[0].Until.Hour() != 14 || bans[1].Until != nil {
		t.Fatalf("bans = %+v", bans)
	}
}

func TestFail2banJailsAndBans(t *testing.T) {
	m, sim, _ := newTest(t)
	ctx := context.Background()
	if st, _ := m.Fail2ban(ctx); st.Installed {
		t.Fatal("installed before installing")
	}
	if _, err := m.Install(ctx, OpSpec{ID: "fail2ban", Run: "f2b"}); err != nil {
		t.Fatal(err)
	}
	jails := read(t, sim, jailConf)
	if !strings.Contains(jails, "[servik-panel]") || !strings.Contains(jails, "port = 8443") || !strings.Contains(read(t, sim, panelFilter), "SYSLOG_IDENTIFIER=servik-panel") {
		t.Fatalf("jails:\n%s", jails)
	}
	st, err := m.Fail2ban(ctx)
	if err != nil || !st.Installed || !st.Current || len(st.Jails) == 0 {
		t.Fatalf("status = %+v %v", st, err)
	}
	if err := m.Fail2banUnban(ctx, BanSpec{Jail: "sshd", Address: "92.118.39.73"}); err != nil {
		t.Fatal(err)
	}
	st, _ = m.Fail2ban(ctx)
	for _, j := range st.Jails {
		for _, b := range j.Banned {
			if b.Address == "92.118.39.73" {
				t.Fatal("still banned")
			}
		}
	}
	if err := m.Fail2banBan(ctx, BanSpec{Jail: "sshd; rm", Address: "1.2.3.4"}); err == nil {
		t.Fatal("bad jail accepted")
	}
	if changed, err := m.Fail2banRefresh(ctx); err != nil || changed {
		t.Fatalf("refresh of current jails: %v %v", changed, err)
	}
}
