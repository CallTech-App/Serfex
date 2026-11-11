package component

import (
	"context"

	"github.com/calltech-app/serfex/agent/internal/aptcache"
	"github.com/calltech-app/serfex/agent/internal/database"
	"github.com/calltech-app/serfex/agent/internal/dns"
	"github.com/calltech-app/serfex/agent/internal/mail"
)

// powerdns is the authoritative DNS server. The DNS setup installs it
// (dns.Manager.Setup); here it is updated within its series, upgraded to
// the series Serfex offers (dns.Series) and, for a week after that, taken
// back (revert).
func powerdns() *component {
	return &component{
		id: "powerdns", versionPkg: "pdns-server",
		required: "the domains' DNS server; it is set up on the Server DNS settings page",
		installed: func(m *Manager) bool {
			return m.DNS != nil && m.DNS.Configured() && m.DNS.PackageVersion(context.Background()) != ""
		},
		unitList: func(*Manager) []string { return []string{"pdns@serfex"} },
		install: func(*op) error {
			return invalid("PowerDNS is installed by the DNS server setup")
		},
		update: func(o *op) error {
			o.step("PowerDNS'in son sürümü kuruluyor")
			return o.m.DNS.Update(o.ctx, o.w)
		},
		series: &series{
			offer: func(_ context.Context, _ *Manager, version string) *database.UpgradeOffer {
				if o := dns.UpgradeOffer(version); o != nil {
					return &database.UpgradeOffer{From: o.From, To: o.To}
				}
				return nil
			},
			kept: func(m *Manager) *database.Kept {
				k := m.DNS.KeptVersion()
				if k == nil {
					return nil
				}
				return &database.Kept{Product: "powerdns", From: aptcache.Upstream(k.From), To: aptcache.Upstream(k.To), Old: k.Dir + "/lmdb", Dump: k.Dir + "/zones.json", Packages: k.Dir + "/packages", Upgraded: k.Upgraded, Until: k.Until}
			},
			upgrade: func(o *op) error {
				_, err := o.m.DNS.Upgrade(o.ctx, dns.UpgradeSpec{Log: o.w, Fail: o.failpoint})
				return err
			},
			revert: func(o *op) error {
				return o.m.DNS.Revert(o.ctx, dns.UpgradeSpec{Log: o.w, Fail: o.failpoint})
			},
			removeOld: func(o *op) error { return o.m.DNS.RemoveOld(o.ctx, o.w) },
			prune:     func(ctx context.Context, m *Manager) { m.DNS.PruneKept(ctx) },
		},
	}
}

// rspamd is the mail server's spam filter. The mail server setup installs
// it (mail.Manager.Setup); here it is updated, upgraded from Ubuntu's 3.8
// to the major version Serfex offers from Rspamd's archive
// (mail.RspamdMajor) and, for a week after that, taken back (revert).
func rspamd() *component {
	return &component{
		id: "rspamd", versionPkg: "rspamd",
		required: "the mail server's spam filter; it is set up on the Mail server page",
		installed: func(m *Manager) bool {
			return m.Mail != nil && m.Mail.Configured() && m.Mail.RspamdVersion(context.Background()) != ""
		},
		units: []string{"rspamd"},
		install: func(*op) error {
			return invalid("Rspamd is installed by the mail server setup")
		},
		update: func(o *op) error {
			o.step("Rspamd'in son sürümü kuruluyor")
			return o.m.Mail.UpdateRspamd(o.ctx, o.w)
		},
		series: &series{
			offer: func(_ context.Context, _ *Manager, version string) *database.UpgradeOffer {
				if o := mail.RspamdOffer(version); o != nil {
					return &database.UpgradeOffer{From: o.From, To: o.To}
				}
				return nil
			},
			kept: func(m *Manager) *database.Kept {
				k := m.Mail.RspamdKept()
				if k == nil {
					return nil
				}
				return &database.Kept{Product: "rspamd", From: aptcache.Upstream(k.From), To: aptcache.Upstream(k.To), Old: k.Dir + "/etc", Packages: k.Dir + "/packages", Upgraded: k.Upgraded, Until: k.Until}
			},
			upgrade: func(o *op) error {
				_, err := o.m.Mail.UpgradeRspamd(o.ctx, mail.UpgradeSpec{Log: o.w, Fail: o.failpoint})
				return err
			},
			revert: func(o *op) error {
				return o.m.Mail.RevertRspamd(o.ctx, mail.UpgradeSpec{Log: o.w, Fail: o.failpoint})
			},
			removeOld: func(o *op) error { return o.m.Mail.RemoveOldRspamd(o.ctx, o.w) },
			prune:     func(ctx context.Context, m *Manager) { m.Mail.PruneRspamd(ctx) },
		},
	}
}
