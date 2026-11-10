package component

import (
	"context"

	"github.com/calltech-app/serfex/agent/internal/aptcache"
	"github.com/calltech-app/serfex/agent/internal/database"
	"github.com/calltech-app/serfex/agent/internal/dns"
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
	}
}

// dnsOffer and dnsKept are PowerDNS's upgrade and kept version in the
// shape the database servers report them.
func (m *Manager) dnsOffer(version string) *database.UpgradeOffer {
	if o := dns.UpgradeOffer(version); o != nil {
		return &database.UpgradeOffer{From: o.From, To: o.To}
	}
	return nil
}

func (m *Manager) dnsKept() *database.Kept {
	k := m.DNS.KeptVersion()
	if k == nil {
		return nil
	}
	return &database.Kept{Product: "powerdns", From: aptcache.Upstream(k.From), To: aptcache.Upstream(k.To), Old: k.Dir + "/lmdb", Dump: k.Dir + "/zones.json", Packages: k.Dir + "/packages", Upgraded: k.Upgraded, Until: k.Until}
}
