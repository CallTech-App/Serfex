package component

import (
	"context"

	"github.com/calltech-app/serfex/agent/internal/database"
)

// series is how a component that is not a database server moves to the
// release series Serfex brings (component.upgrade), keeps the old one for
// a week, goes back to it (component.revert) or lets it go sooner
// (component.remove-old): PowerDNS, Rspamd and ModSecurity's rules. The
// packages that run them (dns, mail, this one for the rules) back up,
// verify and roll back; these hooks connect them to the Software page.
type series struct {
	// offer is the upgrade the installed version (as List reports it)
	// can have, nil for none.
	offer func(ctx context.Context, m *Manager, version string) *database.UpgradeOffer
	// kept is what the last upgrade keeps, nil when nothing is.
	kept      func(m *Manager) *database.Kept
	upgrade   func(o *op) error
	revert    func(o *op) error
	removeOld func(o *op) error
	// prune lets the kept version go once its week has passed.
	prune func(ctx context.Context, m *Manager)
}
