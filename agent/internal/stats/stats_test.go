package stats

import (
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
	"time"
)

const registry = `2|ripencc|1729036799|123|19830705|20261015|+0200
ripencc|*|ipv4|*|80000|summary
ripencc|TR|ipv4|88.224.0.0|1048576|20050419|allocated|abc
ripencc|DE|ipv4|5.1.0.0|65536|20120101|assigned|def
ripencc||ipv4|5.2.0.0|65536||available|
ripencc|NL|ipv6|2a02:a400::|26|20100101|allocated|ghi
`

const arin = `arin|US|ipv4|3.0.0.0|16777216|19880101|allocated|x
arin|ZZ|ipv4|4.0.0.0|256||reserved|
`

func TestGeoLookupAndFile(t *testing.T) {
	g, err := BuildGeo(strings.NewReader(registry), strings.NewReader(arin))
	if err != nil {
		t.Fatal(err)
	}
	cases := map[string]string{
		"88.224.0.1": "TR", "88.239.255.255": "TR", "88.240.0.0": "",
		"5.1.2.3": "DE", "5.2.0.1": "", "3.120.0.9": "US", "4.0.0.1": "",
		"2a02:a400::1": "NL", "2a02:a43f:ffff::1": "NL", "2a02:a440::1": "",
		"::ffff:5.1.0.9": "DE", "127.0.0.1": "", "bad": "",
	}
	for ip, want := range cases {
		if got := g.Country(ip); got != want {
			t.Errorf("Country(%s) = %q, want %q", ip, got, want)
		}
	}
	data, _ := g.MarshalBinary()
	file := filepath.Join(t.TempDir(), "countries.bin")
	if err := writeFile(file, data); err != nil {
		t.Fatal(err)
	}
	loaded, err := LoadGeo(file)
	if err != nil {
		t.Fatal(err)
	}
	if loaded.Size() != g.Size() || loaded.Country("88.230.1.1") != "TR" || loaded.Country("2a02:a400::5") != "NL" {
		t.Fatalf("loaded table differs: %d blocks", loaded.Size())
	}
	var none *Geo
	if none.Country("88.230.1.1") != "" {
		t.Fatal("nil table should know nothing")
	}
}

func TestParseRequest(t *testing.T) {
	line := `88.230.1.2 - - [20/Oct/2026:09:12:01 +0300] "GET /urunler/kahve?renk=kirmizi HTTP/2.0" 200 5120 "https://www.google.com/search?q=kahve" "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/141.0 Safari/537.36"`
	r, ok := ParseRequest(line)
	if !ok {
		t.Fatal("not parsed")
	}
	if r.IP != "88.230.1.2" || r.Method != "GET" || r.Path != "/urunler/kahve" || r.Status != 200 || r.Bytes != 5120 {
		t.Fatalf("parsed %+v", r)
	}
	if r.Referrer != "https://www.google.com/search?q=kahve" || !strings.HasPrefix(r.Agent, "Mozilla/5.0 (Windows") {
		t.Fatalf("referrer/agent %q %q", r.Referrer, r.Agent)
	}
	if !r.IsPage() || r.Bot() != "" || r.ReferrerHost([]string{"kahveatolyesi.com.tr"}) != "google.com" {
		t.Fatalf("page %v bot %q ref %q", r.IsPage(), r.Bot(), r.ReferrerHost(nil))
	}
	// A request line Nginx could not read still counts.
	if r, ok := ParseRequest(`1.2.3.4 - - [20/Oct/2026:09:12:01 +0300] "\x16\x03\x01" 400 157 "-" "-"`); !ok || r.Status != 400 || r.Bot() != NoAgent {
		t.Fatalf("bad request line: %+v %v", r, ok)
	}
	if _, ok := ParseRequest("garbage"); ok {
		t.Fatal("garbage parsed")
	}
}

func TestBotsPagesAndReferrers(t *testing.T) {
	agents := map[string]string{
		"Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)": "Googlebot",
		"Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)":       "AhrefsBot",
		"curl/8.5.0": "curl",
		"Mozilla/5.0 (compatible; Foo/1.0; +http://foo.example/bot)": OtherBot,
		"SomeLib/2.0": OtherTool,
		"Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1": "",
	}
	for a, want := range agents {
		if got := (Request{Agent: a}).Bot(); got != want {
			t.Errorf("Bot(%q) = %q, want %q", a, got, want)
		}
	}
	for path, page := range map[string]bool{"/": true, "/blog/yazi": true, "/build/app.js": false, "/logo.PNG": false, "/feed.xml": false} {
		if got := (Request{Method: "GET", Status: 200, Path: path}).IsPage(); got != page {
			t.Errorf("IsPage(%s) = %v", path, got)
		}
	}
	if (Request{Method: "POST", Status: 200, Path: "/"}).IsPage() || (Request{Method: "GET", Status: 404, Path: "/"}).IsPage() {
		t.Error("POSTs and errors are not page views")
	}
	names := []string{"kahveatolyesi.com.tr", "www.kahveatolyesi.com.tr"}
	for ref, want := range map[string]string{
		"https://kahveatolyesi.com.tr/sepet": "", "https://blog.kahveatolyesi.com.tr/": "", "https://WWW.Instagram.com/p/1": "instagram.com",
		"-": "", "android-app://com.google.android.gm/": "com.google.android.gm",
	} {
		if got := (Request{Referrer: ref}).ReferrerHost(names); got != want {
			t.Errorf("ReferrerHost(%s) = %q, want %q", ref, got, want)
		}
	}
}

func accessLine(ip, path string, status int, bytes int, ref, agent string) string {
	return ip + ` - - [20/Oct/2026:09:12:01 +0300] "GET ` + path + ` HTTP/1.1" ` + itoa(status) + " " + itoa(bytes) + ` "` + ref + `" "` + agent + `"`
}

const browser = "Mozilla/5.0 (X11; Linux x86_64) Firefox/140.0"

func TestAggregateAndCollect(t *testing.T) {
	g, _ := BuildGeo(strings.NewReader(registry))
	a := New()
	a.SetGeo(g)
	at := time.Date(2026, 10, 20, 9, 30, 0, 0, time.UTC)
	a.now = func() time.Time { return at }
	site := "kahveatolyesi.com.tr"
	names := []string{site, "www." + site}
	a.AddAccess(site, names, []string{
		accessLine("88.230.1.2", "/", 200, 1000, "https://www.google.com/", browser),
		accessLine("88.230.1.2", "/style.css", 200, 300, "https://kahveatolyesi.com.tr/", browser),
		accessLine("88.230.1.2", "/urunler", 200, 2000, "https://kahveatolyesi.com.tr/", browser),
		accessLine("5.1.0.7", "/urunler", 200, 2000, "https://instagram.com/", browser),
		accessLine("9.9.9.9", "/yok", 404, 100, "-", browser),
		accessLine("66.249.66.1", "/", 200, 1000, "-", "Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)"),
		accessLine("1.1.1.1", "/", 502, 50, "-", "curl/8.5.0"),
	}, at)
	// Next hour: the same visitor is not counted again today.
	a.AddAccess(site, names, []string{accessLine("88.230.1.2", "/", 200, 1000, "-", browser)}, at.Add(time.Hour))

	b := a.Collect(CollectSpec{})
	if b.ID == "" || !b.Countries || len(b.Sites) != 1 || len(b.Sites[0].Hours) != 2 {
		t.Fatalf("batch %+v", b)
	}
	h := b.Sites[0].Hours[0]
	want := Counts{Requests: 7, Bytes: 6450, Visitors: 3, Pages: 3, BotRequests: 2, BotBytes: 1050, S2: 5, S4: 1, S5: 1}
	if h.Counts != want {
		t.Fatalf("counts %+v, want %+v", h.Counts, want)
	}
	if !h.Hour.Equal(at.Truncate(time.Hour)) {
		t.Fatalf("hour %v", h.Hour)
	}
	if got := h.Items[KindPage]["/urunler"]; got.Requests != 2 || got.Bytes != 4000 {
		t.Fatalf("page %+v", got)
	}
	if h.Items[KindReferrer]["google.com"].Requests != 1 || h.Items[KindReferrer]["instagram.com"].Requests != 1 || len(h.Items[KindReferrer]) != 2 {
		t.Fatalf("referrers %+v", h.Items[KindReferrer])
	}
	if tr := h.Items[KindCountry]["TR"]; tr.Requests != 3 || tr.Visitors != 1 {
		t.Fatalf("TR %+v", tr)
	}
	if h.Items[KindCountry]["-"].Visitors != 1 || h.Items[KindCountry]["DE"].Visitors != 1 {
		t.Fatalf("countries %+v", h.Items[KindCountry])
	}
	if h.Items[KindBot]["Googlebot"].Requests != 1 || h.Items[KindBot]["curl"].Bytes != 50 {
		t.Fatalf("bots %+v", h.Items[KindBot])
	}
	if next := b.Sites[0].Hours[1]; next.Visitors != 0 || next.Requests != 1 {
		t.Fatalf("second hour %+v", next.Counts)
	}

	// Not acknowledged: the same batch again, new counts wait.
	a.AddAccess(site, names, []string{accessLine("5.1.0.8", "/", 200, 10, "-", browser)}, at)
	again := a.Collect(CollectSpec{Ack: "something-else"})
	if again.ID != b.ID || again.Sites[0].Hours[0].Requests != 7 {
		t.Fatalf("pending batch not handed out again: %s", again.ID)
	}
	third := a.Collect(CollectSpec{Ack: b.ID})
	if third.ID == b.ID || third.Sites[0].Hours[0].Requests != 1 {
		t.Fatalf("after ack %+v", third)
	}
	empty := a.Collect(CollectSpec{Ack: third.ID})
	if empty.ID != "" || len(empty.Sites) != 0 {
		t.Fatalf("expected nothing, got %+v", empty)
	}
}

func TestItemCap(t *testing.T) {
	a := New()
	at := time.Now()
	var lines []string
	for i := 0; i < maxItems+50; i++ {
		lines = append(lines, accessLine("88.230.1.2", "/p"+itoa(i), 200, 1, "-", browser))
	}
	a.AddAccess("ornek.com", nil, lines, at)
	h := a.Collect(CollectSpec{}).Sites[0].Hours[0]
	if len(h.Items[KindPage]) != maxItems+1 || h.Items[KindPage][""].Requests != 50 {
		t.Fatalf("%d pages, other %+v", len(h.Items[KindPage]), h.Items[KindPage][""])
	}
}

func TestMail(t *testing.T) {
	a := New()
	a.MailDomains = func() map[string]bool { return map[string]bool{"kahveatolyesi.com.tr": true} }
	at := time.Date(2026, 10, 20, 9, 30, 0, 0, time.UTC)
	// The lines are dated; a week later the real clock would age them out.
	a.SetClock(func() time.Time { return at })
	a.AddMail([]string{
		// Outgoing: sent and bounced.
		"2026-10-20T09:30:01 mail postfix/qmgr[2210]: 4QAB1: from=<siparis@kahveatolyesi.com.tr>, size=4210, nrcpt=2 (queue active)",
		"2026-10-20T09:30:02 mail postfix/smtp[2301]: 4QAB1: to=<ali@gmail.com>, relay=gmail-smtp-in.l.google.com[142.250.1.27]:25, delay=1, dsn=2.0.0, status=sent (250 2.0.0 OK)",
		"2026-10-20T09:30:02 mail postfix/smtp[2301]: 4QAB1: to=<yok@ornek.net>, relay=mx.ornek.net[198.51.100.4]:25, delay=1, dsn=5.1.1, status=bounced (host said: 550 5.1.1 User unknown)",
		// Incoming: delivered to a mailbox, and a mail for another domain.
		"2026-10-20T09:30:03 mail postfix/qmgr[2210]: 4QAB2: from=<ayse@gmail.com>, size=8000, nrcpt=1 (queue active)",
		"2026-10-20T09:30:03 mail postfix/lmtp[2302]: 4QAB2: to=<bilgi@kahveatolyesi.com.tr>, relay=mail.servik.test[private/dovecot-lmtp], delay=0.2, dsn=2.0.0, status=sent (250 2.0.0 <bilgi@kahveatolyesi.com.tr> Saved)",
		"2026-10-20T09:30:03 mail postfix/smtp[2301]: 4QAB2: to=<x@baska.com>, relay=mx.baska.com[198.51.100.5]:25, status=sent (250 ok)",
		// Refused on the way in.
		"2026-10-20T09:30:04 mail postfix/smtpd[2400]: NOQUEUE: reject: RCPT from unknown[203.0.113.9]: 550 5.1.1 <yok@kahveatolyesi.com.tr>: Recipient address rejected: User unknown in virtual mailbox table; from=<spam@kotu.example> to=<yok@kahveatolyesi.com.tr> proto=ESMTP helo=<x>",
		"2026-10-20T09:30:05 mail postfix/cleanup[2401]: 4QAB3: milter-reject: END-OF-MESSAGE from unknown[203.0.113.9]: 5.7.1 Spam message rejected; from=<spam@kotu.example> to=<bilgi@kahveatolyesi.com.tr> proto=ESMTP helo=<x>",
		"2026-10-20T09:30:06 mail postfix/smtpd[2400]: NOQUEUE: reject: RCPT from unknown[203.0.113.9]: 554 5.7.1 <a@baska.org>: Relay access denied; from=<spam@kotu.example> to=<a@baska.org> proto=ESMTP helo=<x>",
	}, at)
	b := a.Collect(CollectSpec{})
	if len(b.Mail) != 1 || b.Mail[0].Domain != "kahveatolyesi.com.tr" {
		t.Fatalf("mail %+v", b.Mail)
	}
	if got, want := b.Mail[0].Hours[0].MailCounts, (MailCounts{Sent: 1, Received: 1, Rejected: 2, Bounced: 1}); got != want {
		t.Fatalf("counts %+v, want %+v", got, want)
	}
}

func TestSaveAndLoad(t *testing.T) {
	file := filepath.Join(t.TempDir(), "state.gob")
	at := time.Now()
	a := New()
	a.AddAccess("ornek.com", nil, []string{accessLine("88.230.1.2", "/", 200, 10, "-", browser)}, at)
	pending := a.Collect(CollectSpec{})
	a.AddAccess("ornek.com", nil, []string{accessLine("88.230.1.3", "/", 200, 10, "-", browser)}, at)
	if err := a.Save(file); err != nil {
		t.Fatal(err)
	}
	b := New()
	if err := b.Load(file); err != nil {
		t.Fatal(err)
	}
	if again := b.Collect(CollectSpec{}); again.ID != pending.ID {
		t.Fatalf("pending batch lost over a restart: %q vs %q", again.ID, pending.ID)
	}
	next := b.Collect(CollectSpec{Ack: pending.ID})
	if len(next.Sites) != 1 || next.Sites[0].Hours[0].Visitors != 1 {
		t.Fatalf("uncollected counts lost: %+v", next)
	}
	// Today's visitors are remembered: the first one does not count again.
	b.AddAccess("ornek.com", nil, []string{accessLine("88.230.1.2", "/", 200, 10, "-", browser)}, at)
	if v := b.Collect(CollectSpec{Ack: next.ID}).Sites[0].Hours[0].Visitors; v != 0 {
		t.Fatalf("visitor counted twice: %d", v)
	}
	if err := New().Load(file); err != nil {
		t.Fatalf("state file should be gone after loading: %v", err)
	}
}

func itoa(n int) string { return strconv.Itoa(n) }

func writeFile(file string, data []byte) error { return os.WriteFile(file, data, 0o644) }
