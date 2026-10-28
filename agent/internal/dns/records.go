package dns

import (
	"fmt"
	"net/netip"
	"regexp"
	"sort"
	"strconv"
	"strings"

	hostnames "github.com/calltech-app/serfex/agent/internal/hostname"
)

// Limits on what a zone may hold.
const (
	MinTTL     = 60
	MaxTTL     = 604800
	MaxRecords = 1000
	maxTXT     = 4000
)

// Types lists the record types a zone may hold. SOA is built from
// ZoneSpec.SOA; DNSSEC types are left to PowerDNS.
var Types = []string{"A", "AAAA", "CNAME", "MX", "TXT", "NS", "SRV", "CAA"}

// Record is one resource record in the panel's form: Name is relative to
// the zone ("@" for the apex), Content is the presentation form without
// quoting for TXT ("v=spf1 a mx ~all") and with priority for MX and SRV
// ("10 mail.example.com.").
type Record struct {
	Name    string `json:"name"`
	Type    string `json:"type"`
	TTL     int    `json:"ttl"`
	Content string `json:"content"`
}

// SOA holds the start-of-authority fields Serfex lets the user set. The
// serial is managed by PowerDNS.
type SOA struct {
	Primary    string `json:"primary"`
	Hostmaster string `json:"hostmaster"`
	Refresh    int    `json:"refresh"`
	Retry      int    `json:"retry"`
	Expire     int    `json:"expire"`
	Minimum    int    `json:"minimum"`
	TTL        int    `json:"ttl"`
}

// ZoneSpec is the desired state of a zone: every record it should serve.
type ZoneSpec struct {
	Zone    string   `json:"zone"`
	SOA     SOA      `json:"soa"`
	Records []Record `json:"records"`
	// DNSSEC is the signing wanted; nil leaves it as it is.
	DNSSEC *DNSSECSpec `json:"dnssec,omitempty"`
}

// RRSet is all records of one name and type, in PowerDNS's terms: Name is
// absolute with a trailing dot and Records are presentation-form contents.
type RRSet struct {
	Name    string
	Type    string
	TTL     int
	Records []string
}

func (r RRSet) key() string { return r.Name + " " + r.Type }

// equal reports whether two sets serve the same data.
func (r RRSet) equal(o RRSet) bool {
	if r.TTL != o.TTL || len(r.Records) != len(o.Records) {
		return false
	}
	a, b := sorted(r.Records), sorted(o.Records)
	for i := range a {
		if a[i] != b[i] {
			return false
		}
	}
	return true
}

func sorted(s []string) []string {
	c := append([]string(nil), s...)
	sort.Strings(c)
	return c
}

var (
	ownerLabelRe = regexp.MustCompile(`^[a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?$`)
	caaTagRe     = regexp.MustCompile(`^(issue|issuewild|iodef)$`)
)

// fqdn returns the zone name in absolute form.
func fqdn(zone string) string { return zone + "." }

// normalize validates spec and turns it into the record sets to serve.
// The SOA set comes first.
func (s ZoneSpec) normalize() ([]RRSet, error) {
	if _, err := s.normalizeName(); err != nil {
		return nil, err
	}
	soa, err := s.SOA.content(s.Zone)
	if err != nil {
		return nil, err
	}
	if len(s.Records) > MaxRecords {
		return nil, invalid("a zone may hold at most %d records", MaxRecords)
	}

	sets := map[string]*RRSet{}
	var order []string
	for i, r := range s.Records {
		name, err := ownerName(r.Name, s.Zone)
		if err != nil {
			return nil, err
		}
		typ := strings.ToUpper(strings.TrimSpace(r.Type))
		if !contains(Types, typ) {
			return nil, invalid("record %d: type %q is not supported (supported: %s)", i+1, r.Type, strings.Join(Types, ", "))
		}
		if r.TTL < MinTTL || r.TTL > MaxTTL {
			return nil, invalid("record %d: ttl must be between %d and %d", i+1, MinTTL, MaxTTL)
		}
		content, err := normalizeContent(typ, r.Content)
		if err != nil {
			return nil, invalid("record %d (%s %s): %s", i+1, displayName(name), typ, err.Error())
		}

		set := sets[name+" "+typ]
		if set == nil {
			set = &RRSet{Name: name, Type: typ, TTL: r.TTL}
			sets[set.key()] = set
			order = append(order, set.key())
		}
		if set.TTL != r.TTL {
			return nil, invalid("%s %s records must all have the same ttl", displayName(name), typ)
		}
		if contains(set.Records, content) {
			return nil, invalid("%s %s %s is listed twice", displayName(name), typ, r.Content)
		}
		set.Records = append(set.Records, content)
	}

	apex := fqdn(s.Zone)
	if sets[apex+" NS"] == nil {
		return nil, invalid("the zone needs at least one NS record at its apex")
	}
	if sets[apex+" CNAME"] != nil {
		return nil, invalid("a CNAME record cannot be at the zone apex; use A/AAAA records instead")
	}
	for _, set := range sets {
		if set.Type != "CNAME" {
			continue
		}
		if len(set.Records) > 1 {
			return nil, invalid("%s can have only one CNAME record", displayName(set.Name))
		}
		for _, t := range Types {
			if t != "CNAME" && sets[set.Name+" "+t] != nil {
				return nil, invalid("%s has a CNAME record, so it cannot have other records (%s)", displayName(set.Name), t)
			}
		}
	}

	ttl := s.SOA.TTL
	out := []RRSet{{Name: apex, Type: "SOA", TTL: ttl, Records: []string{soa}}}
	for _, k := range order {
		out = append(out, *sets[k])
	}
	return out, nil
}

// normalizeName validates the zone name and returns it absolute.
func (s ZoneSpec) normalizeName() (string, error) {
	if err := hostnames.Validate(s.Zone); err != nil {
		return "", invalid("zone: %s", err.Error())
	}
	return fqdn(s.Zone), nil
}

// content renders the SOA record with serial 0; PowerDNS fills it in.
func (s SOA) content(zone string) (string, error) {
	primary, err := hostname(s.Primary)
	if err != nil {
		return "", invalid("soa primary: %s", err.Error())
	}
	mbox, err := mailbox(s.Hostmaster)
	if err != nil {
		return "", invalid("soa hostmaster: %s", err.Error())
	}
	for _, f := range []struct {
		name     string
		v        int
		min, max int
	}{
		{"refresh", s.Refresh, 1200, 2419200},
		{"retry", s.Retry, 180, 2419200},
		{"expire", s.Expire, 3600, 4838400},
		{"minimum", s.Minimum, MinTTL, 86400},
		{"ttl", s.TTL, MinTTL, MaxTTL},
	} {
		if f.v < f.min || f.v > f.max {
			return "", invalid("soa %s must be between %d and %d", f.name, f.min, f.max)
		}
	}
	return fmt.Sprintf("%s %s 0 %d %d %d %d", primary, mbox, s.Refresh, s.Retry, s.Expire, s.Minimum), nil
}

// soaWithoutSerial drops the serial so two SOA contents can be compared.
func soaWithoutSerial(content string) string {
	f := strings.Fields(content)
	if len(f) != 7 {
		return content
	}
	return strings.Join(append(f[:2:2], f[3:]...), " ")
}

// soaWithSerial replaces the serial of an SOA content.
func soaWithSerial(content string, serial int64) string {
	f := strings.Fields(content)
	if len(f) != 7 {
		return content
	}
	f[2] = strconv.FormatInt(serial, 10)
	return strings.Join(f, " ")
}

// ownerName turns a relative record name into an absolute one.
func ownerName(name, zone string) (string, error) {
	name = strings.ToLower(strings.TrimSpace(name))
	name = strings.TrimSuffix(name, ".")
	if name == "" || name == "@" || name == zone {
		return fqdn(zone), nil
	}
	name = strings.TrimSuffix(name, "."+zone)
	labels := strings.Split(name, ".")
	for i, l := range labels {
		if l == "*" && i == 0 {
			continue
		}
		if !ownerLabelRe.MatchString(l) {
			return "", invalid("record name %q is not valid", name)
		}
	}
	abs := name + "." + fqdn(zone)
	if len(abs) > 254 {
		return "", invalid("record name %q is too long", name)
	}
	return abs, nil
}

// relativeName is the inverse of ownerName.
func relativeName(abs, zone string) string {
	if abs == fqdn(zone) {
		return "@"
	}
	return strings.TrimSuffix(abs, "."+fqdn(zone))
}

func displayName(abs string) string { return strings.TrimSuffix(abs, ".") }

// normalizeContent validates a record's content and returns it in the
// form PowerDNS stores.
func normalizeContent(typ, content string) (string, error) {
	content = strings.TrimSpace(content)
	if content == "" {
		return "", fmt.Errorf("value is required")
	}
	switch typ {
	case "A":
		ip, err := netip.ParseAddr(content)
		if err != nil || !ip.Is4() {
			return "", fmt.Errorf("%q is not an IPv4 address", content)
		}
		return ip.String(), nil
	case "AAAA":
		ip, err := netip.ParseAddr(content)
		if err != nil || !ip.Is6() || ip.Is4In6() || ip.Zone() != "" {
			return "", fmt.Errorf("%q is not an IPv6 address", content)
		}
		return ip.String(), nil
	case "CNAME", "NS":
		return hostname(content)
	case "MX":
		f := strings.Fields(content)
		if len(f) != 2 {
			return "", fmt.Errorf("MX needs a priority and a mail server, like \"10 mail.example.com.\"")
		}
		prio, err := uint16Field("priority", f[0])
		if err != nil {
			return "", err
		}
		target := "."
		if f[1] != "." {
			if target, err = hostname(f[1]); err != nil {
				return "", err
			}
		}
		return prio + " " + target, nil
	case "SRV":
		f := strings.Fields(content)
		if len(f) != 4 {
			return "", fmt.Errorf("SRV needs priority, weight, port and target, like \"10 5 5060 sip.example.com.\"")
		}
		var parts []string
		for i, n := range []string{"priority", "weight", "port"} {
			v, err := uint16Field(n, f[i])
			if err != nil {
				return "", err
			}
			parts = append(parts, v)
		}
		target := "."
		if f[3] != "." {
			var err error
			if target, err = hostname(f[3]); err != nil {
				return "", err
			}
		}
		return strings.Join(append(parts, target), " "), nil
	case "TXT":
		if len(content) > maxTXT {
			return "", fmt.Errorf("TXT value is longer than %d characters", maxTXT)
		}
		return quoteTXT(content), nil
	case "CAA":
		f := strings.SplitN(content, " ", 3)
		if len(f) != 3 {
			return "", fmt.Errorf("CAA needs flags, tag and value, like 0 issue \"letsencrypt.org\"")
		}
		flags, err := strconv.Atoi(f[0])
		if err != nil || flags < 0 || flags > 255 {
			return "", fmt.Errorf("CAA flags must be 0-255")
		}
		tag := strings.ToLower(f[1])
		if !caaTagRe.MatchString(tag) {
			return "", fmt.Errorf("CAA tag must be issue, issuewild or iodef")
		}
		value := strings.TrimSpace(f[2])
		if len(value) >= 2 && value[0] == '"' && value[len(value)-1] == '"' {
			value = value[1 : len(value)-1]
		}
		if strings.ContainsAny(value, "\"\\") || !printable(value) {
			return "", fmt.Errorf("CAA value contains characters that are not allowed")
		}
		return fmt.Sprintf("%d %s \"%s\"", flags, tag, value), nil
	}
	return "", fmt.Errorf("unsupported type %s", typ)
}

// displayContent is the inverse of normalizeContent for TXT; other types
// are returned as stored.
func displayContent(typ, content string) string {
	if typ == "TXT" {
		return unquoteTXT(content)
	}
	return content
}

// hostname validates a target host name and returns it lowercased and
// absolute. Labels may contain underscores (_dmarc, _domainkey).
func hostname(h string) (string, error) {
	h = strings.ToLower(strings.TrimSpace(h))
	h = strings.TrimSuffix(h, ".")
	if h == "" || len(h) > 253 {
		return "", fmt.Errorf("%q is not a valid host name", h)
	}
	labels := strings.Split(h, ".")
	if len(labels) < 2 {
		return "", fmt.Errorf("%q must be a full host name, like mail.example.com", h)
	}
	for _, l := range labels {
		if !ownerLabelRe.MatchString(l) {
			return "", fmt.Errorf("%q is not a valid host name", h)
		}
	}
	return h + ".", nil
}

// mailbox converts hostmaster@example.com to SOA form
// (hostmaster.example.com.). A name already in SOA form is accepted.
func mailbox(m string) (string, error) {
	m = strings.ToLower(strings.TrimSpace(m))
	local, domain, ok := strings.Cut(m, "@")
	if !ok {
		return hostname(m)
	}
	if local == "" || !printable(local) || strings.ContainsAny(local, " \\") {
		return "", fmt.Errorf("%q is not a valid e-mail address", m)
	}
	d, err := hostname(domain)
	if err != nil {
		return "", fmt.Errorf("%q is not a valid e-mail address", m)
	}
	return strings.ReplaceAll(local, ".", "\\.") + "." + d, nil
}

func uint16Field(name, v string) (string, error) {
	n, err := strconv.Atoi(v)
	if err != nil || n < 0 || n > 65535 {
		return "", fmt.Errorf("%s must be a number from 0 to 65535", name)
	}
	return strconv.Itoa(n), nil
}

// quoteTXT renders text as one or more quoted character strings of at most
// 255 bytes each, escaping quotes, backslashes and non-printable bytes.
func quoteTXT(s string) string {
	var parts []string
	for len(s) > 0 {
		n := min(len(s), 255)
		parts = append(parts, quoteChunk(s[:n]))
		s = s[n:]
	}
	return strings.Join(parts, " ")
}

func quoteChunk(s string) string {
	var b strings.Builder
	b.WriteByte('"')
	for i := 0; i < len(s); i++ {
		c := s[i]
		switch {
		case c == '"' || c == '\\':
			b.WriteByte('\\')
			b.WriteByte(c)
		case c < 0x20 || c > 0x7e:
			fmt.Fprintf(&b, "\\%03d", c)
		default:
			b.WriteByte(c)
		}
	}
	b.WriteByte('"')
	return b.String()
}

// unquoteTXT joins the character strings of a TXT content back into text.
func unquoteTXT(s string) string {
	var b strings.Builder
	in := false
	for i := 0; i < len(s); i++ {
		c := s[i]
		switch {
		case c == '"':
			in = !in
		case !in:
			// whitespace between strings
		case c == '\\' && i+3 < len(s) && isDigit(s[i+1]) && isDigit(s[i+2]) && isDigit(s[i+3]):
			n, _ := strconv.Atoi(s[i+1 : i+4])
			b.WriteByte(byte(n))
			i += 3
		case c == '\\' && i+1 < len(s):
			b.WriteByte(s[i+1])
			i++
		default:
			b.WriteByte(c)
		}
	}
	return b.String()
}

func isDigit(c byte) bool { return c >= '0' && c <= '9' }

func printable(s string) bool {
	for i := 0; i < len(s); i++ {
		if s[i] < 0x20 || s[i] > 0x7e {
			return false
		}
	}
	return true
}

func contains(list []string, s string) bool {
	for _, v := range list {
		if v == s {
			return true
		}
	}
	return false
}
