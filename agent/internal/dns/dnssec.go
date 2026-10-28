package dns

import (
	"context"
	"errors"
	"strconv"
	"strings"
)

const (
	// nsec3Param is how a signed zone proves that a name does not exist:
	// NSEC3 with no extra iterations and no salt (RFC 9276). Narrow mode
	// would hide names a little better, but PowerDNS cannot transfer a
	// narrow zone to secondary servers.
	nsec3Param = "1 0 0 -"
	// signedSOAEdit raises a signed zone's served serial each time
	// PowerDNS renews its signatures (weekly), so secondaries fetch the
	// new signatures before the old ones expire.
	signedSOAEdit = "INCEPTION-INCREMENT"
)

// DNSSECSpec is the signing the panel wants for a zone.
type DNSSECSpec struct {
	Enabled bool `json:"enabled"`
	// Key is the zone's private key in PowerDNS's format, as an earlier
	// apply returned it. It keeps the DS record at the registrar valid
	// when the zone is turned off and on, deleted and restored, or moved
	// to another server. Empty lets the agent use or make one.
	Key string `json:"key,omitempty"`
}

// DNSSECInfo describes a zone's signing.
type DNSSECInfo struct {
	Enabled   bool `json:"enabled"`
	KeyTag    int  `json:"key_tag,omitempty"`
	Algorithm int  `json:"algorithm,omitempty"`
	// DS are the records to give the registrar, SHA-256 first.
	DS []string `json:"ds,omitempty"`
	// DNSKEY is the public key, for registrars that ask for it instead.
	DNSKEY string `json:"dnskey,omitempty"`
	// Key is the private key, sent back only when the panel did not
	// send it, so that the panel can keep it.
	Key string `json:"key,omitempty"`
}

// applyDNSSEC makes the zone's signing match spec. Keys are never
// deleted: turning signing off deactivates them, and turning it on again
// uses the same key, so the DS record at the registrar stays valid.
func (m *Manager) applyDNSSEC(ctx context.Context, b Backend, z *Zone, spec DNSSECSpec) (*DNSSECInfo, bool, error) {
	keys, err := b.Keys(ctx, z.Name)
	if err != nil {
		return nil, false, &OperationError{err}
	}
	changed := false
	setActive := func(k Key, on bool) error {
		if k.Active == on && k.Published == on {
			return nil
		}
		changed = true
		return b.SetKeyActive(ctx, z.Name, k.ID, on)
	}

	if !spec.Enabled {
		for _, k := range keys {
			if err := setActive(k, false); err != nil {
				return nil, false, &OperationError{err}
			}
		}
		if z.NSEC3Param != "" || z.SOAEdit == signedSOAEdit {
			none := ""
			if err := b.SetZoneOptions(ctx, z.Name, ZoneOptions{NSEC3Param: &none, SOAEdit: &none}); err != nil {
				return nil, false, &OperationError{err}
			}
			changed = true
		}
		return &DNSSECInfo{}, changed, nil
	}

	var use *Key
	sendBack := ""
	want := normalizeKey(spec.Key)
	switch {
	case want != "":
		for i, k := range keys {
			priv, err := b.PrivateKey(ctx, z.Name, k.ID)
			if err != nil {
				return nil, false, &OperationError{err}
			}
			if normalizeKey(priv) == want {
				use = &keys[i]
				break
			}
		}
		if use == nil {
			k, err := b.AddKey(ctx, z.Name, spec.Key)
			var ae *apiError
			if errors.As(err, &ae) && ae.Status == 422 {
				return nil, false, invalid("the zone's DNSSEC key could not be used: %s", ae.Message)
			}
			if err != nil {
				return nil, false, &OperationError{err}
			}
			keys, use, changed = append(keys, k), &k, true
		}
	case len(keys) > 0:
		// A key made earlier (or by hand): prefer the one in use.
		use = &keys[0]
		for i, k := range keys {
			if k.Active {
				use = &keys[i]
				break
			}
		}
	default:
		k, err := b.AddKey(ctx, z.Name, "")
		if err != nil {
			return nil, false, &OperationError{err}
		}
		keys, use, changed = append(keys, k), &k, true
	}
	if want == "" {
		priv, err := b.PrivateKey(ctx, z.Name, use.ID)
		if err != nil {
			return nil, false, &OperationError{err}
		}
		sendBack = priv
	}
	for _, k := range keys {
		if err := setActive(k, k.ID == use.ID); err != nil {
			return nil, false, &OperationError{err}
		}
	}
	if z.NSEC3Param != nsec3Param || z.SOAEdit != signedSOAEdit {
		p, e := nsec3Param, signedSOAEdit
		if err := b.SetZoneOptions(ctx, z.Name, ZoneOptions{NSEC3Param: &p, SOAEdit: &e}); err != nil {
			return nil, false, &OperationError{err}
		}
		changed = true
	}
	if changed {
		if err := b.Rectify(ctx, z.Name); err != nil {
			return nil, false, &OperationError{err}
		}
	}
	info := keyInfo(*use)
	info.Key = sendBack
	return &info, changed, nil
}

// dnssecInfo describes the signing of a zone as it is now.
func dnssecInfo(ctx context.Context, b Backend, zone string) (*DNSSECInfo, error) {
	keys, err := b.Keys(ctx, zone)
	if err != nil {
		return nil, err
	}
	for _, k := range keys {
		if k.Active {
			info := keyInfo(k)
			return &info, nil
		}
	}
	return &DNSSECInfo{}, nil
}

// keyInfo is what the panel shows for an active key: its DS records with
// SHA-256 and SHA-384 digests. SHA-1 is left out (RFC 8624).
func keyInfo(k Key) DNSSECInfo {
	info := DNSSECInfo{Enabled: true, DNSKEY: k.DNSKEY, DS: []string{}}
	for _, digest := range []string{"2", "4"} {
		for _, ds := range k.DS {
			f := strings.Fields(ds)
			if len(f) == 4 && f[2] == digest {
				info.DS = append(info.DS, ds)
				info.KeyTag, _ = strconv.Atoi(f[0])
				info.Algorithm, _ = strconv.Atoi(f[1])
			}
		}
	}
	return info
}

// normalizeKey makes two copies of a private key comparable.
func normalizeKey(k string) string {
	var lines []string
	for _, l := range strings.Split(k, "\n") {
		if l = strings.Join(strings.Fields(l), " "); l != "" {
			lines = append(lines, l)
		}
	}
	return strings.Join(lines, "\n")
}
