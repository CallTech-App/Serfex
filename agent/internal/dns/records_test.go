package dns

import (
	"strings"
	"testing"
)

var soa = SOA{Primary: "ns1.servik.net.tr", Hostmaster: "hostmaster@kahveatolyesi.com.tr", Refresh: 10800, Retry: 3600, Expire: 604800, Minimum: 3600, TTL: 3600}

func zoneSpec(records ...Record) ZoneSpec {
	base := []Record{
		{Name: "@", Type: "NS", TTL: 3600, Content: "ns1.servik.net.tr."},
		{Name: "@", Type: "NS", TTL: 3600, Content: "ns2.servik.net.tr"},
	}
	return ZoneSpec{Zone: "kahveatolyesi.com.tr", SOA: soa, Records: append(base, records...)}
}

func find(sets []RRSet, name, typ string) *RRSet {
	for i := range sets {
		if sets[i].Name == name && sets[i].Type == typ {
			return &sets[i]
		}
	}
	return nil
}

func TestNormalizeBuildsRecordSets(t *testing.T) {
	sets, err := zoneSpec(
		Record{Name: "@", Type: "A", TTL: 3600, Content: "185.98.142.21"},
		Record{Name: "@", Type: "AAAA", TTL: 3600, Content: "2a0e:1c80:0004:002a:0000:0000:0000:0021"},
		Record{Name: "www", Type: "cname", TTL: 300, Content: "Kahveatolyesi.com.tr."},
		Record{Name: "@", Type: "MX", TTL: 3600, Content: "10 mail.kahveatolyesi.com.tr"},
		Record{Name: "_dmarc", Type: "TXT", TTL: 3600, Content: `v=DMARC1; p=none; rua="mailto:x@y.z"`},
		Record{Name: "_sip._tcp", Type: "SRV", TTL: 3600, Content: "10 5 5060 sip.kahveatolyesi.com.tr"},
		Record{Name: "@", Type: "CAA", TTL: 3600, Content: "0 issue letsencrypt.org"},
		Record{Name: "*", Type: "A", TTL: 3600, Content: "185.98.142.21"},
		Record{Name: "shop.kahveatolyesi.com.tr.", Type: "A", TTL: 3600, Content: "185.98.142.22"},
	).normalize()
	if err != nil {
		t.Fatal(err)
	}
	if sets[0].Type != "SOA" || sets[0].Records[0] != "ns1.servik.net.tr. hostmaster.kahveatolyesi.com.tr. 0 10800 3600 604800 3600" {
		t.Fatalf("SOA = %+v", sets[0])
	}
	want := map[string]string{
		"kahveatolyesi.com.tr. NS":            "ns1.servik.net.tr.|ns2.servik.net.tr.",
		"kahveatolyesi.com.tr. AAAA":          "2a0e:1c80:4:2a::21",
		"www.kahveatolyesi.com.tr. CNAME":     "kahveatolyesi.com.tr.",
		"kahveatolyesi.com.tr. MX":            "10 mail.kahveatolyesi.com.tr.",
		"_dmarc.kahveatolyesi.com.tr. TXT":    `"v=DMARC1; p=none; rua=\"mailto:x@y.z\""`,
		"_sip._tcp.kahveatolyesi.com.tr. SRV": "10 5 5060 sip.kahveatolyesi.com.tr.",
		"kahveatolyesi.com.tr. CAA":           `0 issue "letsencrypt.org"`,
		"*.kahveatolyesi.com.tr. A":           "185.98.142.21",
		"shop.kahveatolyesi.com.tr. A":        "185.98.142.22",
	}
	for k, v := range want {
		name, typ, _ := strings.Cut(k, " ")
		s := find(sets, name, typ)
		if s == nil {
			t.Errorf("missing %s", k)
			continue
		}
		if got := strings.Join(s.Records, "|"); got != v {
			t.Errorf("%s = %q, want %q", k, got, v)
		}
	}
}

func TestNormalizeRejects(t *testing.T) {
	cases := map[string]ZoneSpec{
		"apex CNAME":       zoneSpec(Record{Name: "@", Type: "CNAME", TTL: 3600, Content: "x.example.com"}),
		"CNAME and A":      zoneSpec(Record{Name: "www", Type: "CNAME", TTL: 3600, Content: "x.example.com"}, Record{Name: "www", Type: "A", TTL: 3600, Content: "1.2.3.4"}),
		"bad IPv4":         zoneSpec(Record{Name: "@", Type: "A", TTL: 3600, Content: "1.2.3"}),
		"IPv6 in A":        zoneSpec(Record{Name: "@", Type: "A", TTL: 3600, Content: "::1"}),
		"IPv4 in AAAA":     zoneSpec(Record{Name: "@", Type: "AAAA", TTL: 3600, Content: "1.2.3.4"}),
		"MX without prio":  zoneSpec(Record{Name: "@", Type: "MX", TTL: 3600, Content: "mail.example.com"}),
		"SOA record":       zoneSpec(Record{Name: "@", Type: "SOA", TTL: 3600, Content: "x"}),
		"unknown type":     zoneSpec(Record{Name: "@", Type: "PTR", TTL: 3600, Content: "x.example.com"}),
		"low TTL":          zoneSpec(Record{Name: "@", Type: "A", TTL: 10, Content: "1.2.3.4"}),
		"mixed TTL":        zoneSpec(Record{Name: "@", Type: "A", TTL: 3600, Content: "1.2.3.4"}, Record{Name: "@", Type: "A", TTL: 300, Content: "1.2.3.5"}),
		"duplicate":        zoneSpec(Record{Name: "@", Type: "A", TTL: 3600, Content: "1.2.3.4"}, Record{Name: "@", Type: "A", TTL: 3600, Content: "1.2.3.4"}),
		"bad name":         zoneSpec(Record{Name: "a b", Type: "A", TTL: 3600, Content: "1.2.3.4"}),
		"inner wildcard":   zoneSpec(Record{Name: "a.*", Type: "A", TTL: 3600, Content: "1.2.3.4"}),
		"bare CNAME label": zoneSpec(Record{Name: "www", Type: "CNAME", TTL: 3600, Content: "localhost"}),
		"bad CAA tag":      zoneSpec(Record{Name: "@", Type: "CAA", TTL: 3600, Content: `0 policy "x"`}),
		"no NS":            {Zone: "kahveatolyesi.com.tr", SOA: soa},
		"bad zone":         {Zone: "localhost", SOA: soa},
		"bad hostmaster":   {Zone: "kahveatolyesi.com.tr", SOA: SOA{Primary: "ns1.servik.net.tr", Hostmaster: "a b@c", Refresh: 10800, Retry: 3600, Expire: 604800, Minimum: 3600, TTL: 3600}},
		"bad SOA timer":    {Zone: "kahveatolyesi.com.tr", SOA: SOA{Primary: "ns1.servik.net.tr", Hostmaster: "h@kahveatolyesi.com.tr", Refresh: 1, Retry: 3600, Expire: 604800, Minimum: 3600, TTL: 3600}},
	}
	for name, spec := range cases {
		if _, err := spec.normalize(); err == nil {
			t.Errorf("%s: accepted", name)
		} else if _, ok := err.(*ValidationError); !ok {
			t.Errorf("%s: %T is not a ValidationError", name, err)
		}
	}
}

func TestTXTQuoting(t *testing.T) {
	long := strings.Repeat("k", 300)
	for _, text := range []string{"v=spf1 a mx ~all", `say "hi" \ bye`, "Kahve Atölyesi", long} {
		q := quoteTXT(text)
		if !strings.HasPrefix(q, `"`) {
			t.Errorf("%q not quoted: %s", text, q)
		}
		if got := unquoteTXT(q); got != text {
			t.Errorf("round trip of %q gave %q (via %s)", text, got, q)
		}
	}
	if q := quoteTXT(long); strings.Count(q, `" "`) != 1 {
		t.Errorf("300 characters should be two strings: %s", q)
	}
	if q := quoteTXT("Ö"); q != `"\195\150"` {
		t.Errorf("non-ASCII not escaped: %s", q)
	}
}

func TestMailbox(t *testing.T) {
	for in, want := range map[string]string{
		"hostmaster@example.com":  "hostmaster.example.com.",
		"first.last@example.com":  `first\.last.example.com.`,
		"hostmaster.example.com.": "hostmaster.example.com.",
	} {
		got, err := mailbox(in)
		if err != nil || got != want {
			t.Errorf("mailbox(%q) = %q, %v; want %q", in, got, err, want)
		}
	}
}
