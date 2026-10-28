package dns

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"os"
	"strings"

	"github.com/calltech-app/serfex/agent/internal/host"
	hostnames "github.com/calltech-app/serfex/agent/internal/hostname"
	"github.com/calltech-app/serfex/agent/internal/tuning"
	"github.com/calltech-app/serfex/agent/internal/txn"
)

// Zone kinds in PowerDNS's terms.
const (
	kindNative   = "Native"
	kindPrimary  = "Master"
	kindProducer = "Producer"
)

// Secondaries describes the secondary name servers.
type Secondaries struct {
	// Addresses may transfer zones: addresses and networks.
	Addresses []string `json:"addresses"`
	// Notify are the addresses told about every change.
	Notify []string `json:"notify"`
	// Catalog is the catalog zone (RFC 9432) that lists every zone, so
	// that a secondary that reads it picks up new zones on its own.
	// Empty while there are no secondaries.
	Catalog string `json:"catalog"`
	// Zones is how many zones the secondaries are given.
	Zones int `json:"zones"`
}

// SecondariesSpec sets the secondary name servers.
type SecondariesSpec struct {
	Addresses []string `json:"addresses"`
}

// GetSecondaries reports the secondary name servers.
func (m *Manager) GetSecondaries(ctx context.Context) (Secondaries, error) {
	b, err := m.backend()
	if err != nil {
		return Secondaries{}, err
	}
	return m.secondaries(ctx, b, tuning.Load(m.h).PowerDNS)
}

func (m *Manager) secondaries(ctx context.Context, b Backend, p tuning.PowerDNS) (Secondaries, error) {
	out := Secondaries{Addresses: p.AXFRAllow, Notify: tuning.NotifyAddresses(p)}
	if len(p.AXFRAllow) == 0 {
		return out, nil
	}
	out.Catalog = strings.TrimSuffix(p.Catalog, ".")
	zones, err := b.Zones(ctx)
	if err != nil {
		return Secondaries{}, &OperationError{err}
	}
	for _, z := range zones {
		if z.Kind != kindProducer {
			out.Zones++
		}
	}
	return out, nil
}

// SetSecondaries lets the given servers transfer every zone, tells them
// about each change and lists the zones for them in a catalog zone. An
// empty list stops all of it. PowerDNS restarts to read the change; if it
// does not come back, the previous configuration is put back.
func (m *Manager) SetSecondaries(ctx context.Context, spec SecondariesSpec) (res Secondaries, err error) {
	allow, err := tuning.ParseAXFRAllow(spec.Addresses)
	if err != nil {
		return Secondaries{}, invalid("%s", err)
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	b, err := m.backend()
	if err != nil {
		return Secondaries{}, err
	}

	s := tuning.Load(m.h)
	s.PowerDNS.AXFRAllow = allow
	if len(allow) > 0 && s.PowerDNS.Catalog == "" {
		s.PowerDNS.Catalog = catalogName(m.hostname())
	}
	p := s.PowerDNS

	conf, err := os.ReadFile(m.h.Path(configPath))
	if err != nil {
		return Secondaries{}, &OperationError{err}
	}
	updated := tuning.SetPowerDNS(conf, p)
	t := txn.New(m.h)
	restarted := false
	fail := func(err error) (Secondaries, error) {
		bg := context.WithoutCancel(ctx)
		if rbErr := t.Rollback(bg); rbErr != nil {
			err = fmt.Errorf("%w (rollback also failed: %v)", err, rbErr)
		}
		if restarted {
			_, _ = m.h.Run(bg, host.Command{Name: "systemctl", Args: []string{"restart", unit}})
		}
		var v *ValidationError
		if !errors.As(err, &v) {
			err = &OperationError{err}
		}
		return Secondaries{}, err
	}
	pdns, err := m.h.LookupUser("pdns")
	if err != nil {
		return fail(fmt.Errorf("pdns account: %w", err))
	}
	if err := tuning.Save(t, s); err != nil {
		return fail(err)
	}
	if !bytes.Equal(updated, conf) {
		if err := t.WriteFileMode(configPath, updated, 0o640, 0, pdns.GID); err != nil {
			return fail(err)
		}
		if out, err := m.h.Run(ctx, host.Command{Name: pdnsBinary, Args: []string{"--config-name=" + instance, "--config=check"}}); err != nil {
			return fail(invalid("PowerDNS rejected the configuration: %s", strings.TrimSpace(out)))
		}
		// PowerDNS cannot reload these settings; the restart takes a moment.
		restarted = true
		if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"restart", unit}}); err != nil {
			return fail(err)
		}
		if _, err := m.waitForAPI(ctx, b); err != nil {
			return fail(err)
		}
	}

	if err := m.syncSecondaries(ctx, b, p); err != nil {
		return Secondaries{}, err
	}
	return m.secondaries(ctx, b, p)
}

// syncSecondaries gives every zone the kind and catalog the secondaries
// call for, and tells the secondaries to fetch the zones that now have
// them. The catalog zone exists only while there are secondaries.
func (m *Manager) syncSecondaries(ctx context.Context, b Backend, p tuning.PowerDNS) error {
	zones, err := b.Zones(ctx)
	if err != nil {
		return &OperationError{err}
	}
	on := len(p.AXFRAllow) > 0
	if on {
		if err := m.ensureCatalog(ctx, b, p.Catalog); err != nil {
			return err
		}
	}
	for _, z := range zones {
		if z.Kind == kindProducer {
			continue
		}
		if _, err := m.applySecondaryOptions(ctx, b, &z, p); err != nil {
			return err
		}
		if on {
			_ = b.Notify(ctx, z.Name)
		}
	}
	for _, z := range zones {
		if z.Kind == kindProducer && (!on || z.Name != p.Catalog) {
			if err := b.DeleteZone(ctx, z.Name); err != nil {
				return &OperationError{err}
			}
		}
	}
	if on {
		_ = b.Notify(ctx, p.Catalog)
	}
	return nil
}

// applySecondaryOptions sets the zone's kind and catalog: a primary zone
// listed in the catalog while there are secondaries, a native zone (no
// NOTIFY) otherwise.
func (m *Manager) applySecondaryOptions(ctx context.Context, b Backend, z *Zone, p tuning.PowerDNS) (bool, error) {
	kind, catalog := kindNative, ""
	if len(p.AXFRAllow) > 0 {
		kind, catalog = kindPrimary, p.Catalog
	}
	var o ZoneOptions
	if z.Kind != kind {
		o.Kind = &kind
	}
	if z.Catalog != catalog {
		o.Catalog = &catalog
	}
	if o.Kind == nil && o.Catalog == nil {
		return false, nil
	}
	if err := b.SetZoneOptions(ctx, z.Name, o); err != nil {
		return false, &OperationError{err}
	}
	z.Kind, z.Catalog = kind, catalog
	return true, nil
}

// ensureCatalog creates the catalog zone if it is missing. PowerDNS fills
// it with the zones that name it.
func (m *Manager) ensureCatalog(ctx context.Context, b Backend, name string) error {
	if z, err := b.Zone(ctx, name); err != nil {
		return &OperationError{err}
	} else if z != nil {
		return nil
	}
	sets := []RRSet{
		{Name: name, Type: "SOA", TTL: 3600, Records: []string{"invalid. invalid. 1 3600 600 2419200 3600"}},
		{Name: name, Type: "NS", TTL: 3600, Records: []string{"invalid."}},
	}
	if err := b.CreateZone(ctx, name, sets); err != nil {
		return &OperationError{fmt.Errorf("creating the catalog zone: %w", err)}
	}
	kind := kindProducer
	if err := b.SetZoneOptions(ctx, name, ZoneOptions{Kind: &kind}); err != nil {
		return &OperationError{fmt.Errorf("creating the catalog zone: %w", err)}
	}
	return nil
}

// catalogName names this server's catalog zone after its host name, so
// that one secondary can serve several Serfex servers. Under .invalid it
// can never be looked up on the internet.
func catalogName(hostname string) string {
	hostname = strings.ToLower(strings.TrimSuffix(strings.TrimSpace(hostname), "."))
	if hostnames.Validate(hostname) != nil {
		hostname = "serfex"
	}
	return "catalog." + hostname + ".invalid."
}

func (m *Manager) hostname() string {
	if b, err := os.ReadFile(m.h.Path("/etc/hostname")); err == nil && strings.TrimSpace(string(b)) != "" {
		return string(b)
	}
	name, _ := os.Hostname()
	return name
}
