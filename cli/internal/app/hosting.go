package app

import (
	"fmt"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"

	"github.com/calltech-app/serfex/cli/internal/api"
	. "github.com/calltech-app/serfex/cli/internal/i18n"
)

// get and send are the panel calls of the hosting commands.
func (c *Ctx) get(path string, query url.Values) (*api.Response, error) {
	client, err := c.Client()
	if err != nil {
		return nil, err
	}
	return client.Get(c, path, query)
}

func (c *Ctx) send(method, path string, body any) (*api.Response, error) {
	client, err := c.Client()
	if err != nil {
		return nil, err
	}
	return client.Do(c, method, path, nil, body)
}

func esc(s string) string { return url.PathEscape(s) }

// waitUntil asks every interval until ready says so; Ctrl+C stops
// waiting, not the work on the server.
func (c *Ctx) waitUntil(every time.Duration, what string, ready func() (bool, error)) error {
	if !c.json {
		c.note("%s", c.dim(what))
	}
	for {
		ok, err := ready()
		if err != nil || ok {
			return err
		}
		if err := c.sleep(every); err != nil {
			c.note("%s", T("Beklemeyi bıraktınız; iş sunucuda sürüyor."))
			return err
		}
	}
}

// --- Siteler -------------------------------------------------------------

type site struct {
	Domain   string   `json:"domain"`
	Aliases  []string `json:"aliases"`
	Status   string   `json:"status"`
	PHP      string   `json:"php_version"`
	Web      string   `json:"web_server"`
	Account  string   `json:"account"`
	Customer *struct {
		Name string `json:"name"`
	} `json:"customer"`
	HTTPS         bool    `json:"https"`
	HTTPSRedirect bool    `json:"https_redirect"`
	CertExpires   string  `json:"certificate_expires_at"`
	Disk          float64 `json:"disk_bytes"`
	LastError     string  `json:"last_error"`
	CreatedAt     string  `json:"created_at"`
}

func siteCommands() *Command {
	return &Command{
		Name: "sites", Aliases: []string{"site"}, Group: T("Barındırma"),
		Short: T("Siteleri listeler, oluşturur ve siler"),
		Subs: []*Command{
			{Name: "list", Aliases: []string{"ls"}, Short: T("Siteleri listeler"), Run: sitesList},
			{Name: "show", Args: T("<site>"), MinArgs: 1, MaxArgs: 1, Short: T("Bir sitenin ayrıntılarını gösterir"), Complete: completeSites, Run: sitesShow},
			{
				Name: "create", Aliases: []string{"add"}, Args: T("<alan-adı>"), MinArgs: 1, MaxArgs: 1,
				Short: T("Yeni bir site oluşturur"),
				Long:  T("Yeni bir site oluşturur: sistem kullanıcısı, web klasörü, web sunucusu ayarı ve DNS bölgesi, paneldeki gibi."),
				Flags: func(f *Flags) {
					f.String("php", "", T("sürüm"), T("PHP sürümü (varsayılan: sunucunun varsayılanı)"))
					f.Bool("www", "", T("www. adı da açılır (varsayılan: evet; kapatmak için --no-www)"))
					f.String("customer", "", "id", T("Sitenin ait olacağı müşteri (yöneticiler)"))
					f.String("account", "", T("ad"), T("Sistem kullanıcısının adı (varsayılan: alan adından)"))
					f.Bool("wait", "", T("Site hazır olana kadar bekler"))
				},
				Examples: []string{"serfex sites create kahveatolyesi.com.tr --php 8.4 --wait"},
				Run:      sitesCreate,
			},
			{
				Name: "delete", Aliases: []string{"rm"}, Args: T("<site>"), MinArgs: 1, MaxArgs: 1,
				Short:    T("Siteyi dosyaları, veritabanları ve e-postasıyla siler"),
				Flags:    func(f *Flags) { f.Bool("keep-dns", "", T("DNS bölgesini bırakır")) },
				Complete: completeSites,
				Run:      sitesDelete,
			},
		},
	}
}

func completeSites(c *Ctx, n int) []string {
	if n > 0 {
		return nil
	}
	resp, err := c.get("/sites", nil)
	if err != nil {
		return nil
	}
	var sites []site
	if resp.Decode(&sites) != nil {
		return nil
	}
	var out []string
	for _, s := range sites {
		out = append(out, s.Domain)
	}
	return out
}

func sitesList(c *Ctx) error {
	resp, err := c.get("/sites", nil)
	if err != nil {
		return err
	}
	return show(c, resp, func(sites []site) error {
		if len(sites) == 0 {
			c.empty(T("Henüz site yok. Oluşturmak için: serfex sites create <alan-adı>"))
			return nil
		}
		var rows [][]string
		for _, s := range sites {
			https := c.dim("—")
			if s.HTTPS {
				https = c.green("HTTPS")
			}
			owner := ""
			if s.Customer != nil {
				owner = s.Customer.Name
			}
			rows = append(rows, []string{s.Domain, c.status(s.Status), "PHP " + s.PHP, https, orDash(owner), bytesText(s.Disk)})
		}
		c.table([]string{T("Site"), T("Durum"), "PHP", "SSL", T("Müşteri"), T("Disk")}, rows)
		return nil
	})
}

func sitesShow(c *Ctx) error {
	resp, err := c.get("/sites/"+esc(c.Args[0]), nil)
	if err != nil {
		return err
	}
	return show(c, resp, func(s site) error {
		owner := ""
		if s.Customer != nil {
			owner = s.Customer.Name
		}
		https := T("yok")
		if s.HTTPS {
			https = T("var, %s tarihine kadar", when(s.CertExpires))
			if s.HTTPSRedirect {
				https += " · " + T("HTTP'den yönlendiriliyor")
			}
		}
		c.pairs([][2]string{
			{T("Site"), c.bold(s.Domain)},
			{T("Durum"), c.status(s.Status)},
			{T("Ek alan adları"), strings.Join(s.Aliases, ", ")},
			{"PHP", s.PHP},
			{T("Web sunucusu"), s.Web},
			{T("Sistem kullanıcısı"), s.Account},
			{T("Müşteri"), owner},
			{T("SSL"), https},
			{T("Disk"), bytesText(s.Disk)},
			{T("Oluşturuldu"), when(s.CreatedAt)},
			{T("Son hata"), c.red(s.LastError)},
		})
		return nil
	})
}

func sitesCreate(c *Ctx) error {
	body := map[string]any{"domain": c.Args[0]}
	if v := c.String("php"); v != "" {
		body["php_version"] = v
	}
	if c.Has("www") {
		body["www"] = c.Bool("www")
	}
	if v := c.String("customer"); v != "" {
		id, err := strconv.Atoi(v)
		if err != nil {
			return usageErr(T("--customer müşterinin numarasını bekliyor (serfex customers list)."))
		}
		body["customer_id"] = id
	}
	if v := c.String("account"); v != "" {
		body["account"] = v
	}
	resp, err := c.send(http.MethodPost, "/sites", body)
	if err != nil {
		return err
	}
	var s site
	_ = resp.Decode(&s)
	if !c.Bool("wait") {
		return c.done(resp, T("%s oluşturuluyor; durumu: serfex sites show %s", s.Domain, s.Domain))
	}
	if err := c.waitUntil(2*c.env.Interval, T("%s hazırlanıyor…", s.Domain), func() (bool, error) {
		if resp, err = c.get("/sites/"+esc(s.Domain), nil); err != nil {
			return false, err
		}
		_ = resp.Decode(&s)
		return s.Status != "provisioning", nil
	}); err != nil {
		return err
	}
	if s.Status != "active" {
		c.errorf("%s", T("%s oluşturulamadı: %s", s.Domain, orDash(s.LastError)))
		if c.json {
			_ = c.printJSON(resp.Data())
		}
		return exitCode(1)
	}
	return c.done(resp, T("%s yayında.", s.Domain))
}

func sitesDelete(c *Ctx) error {
	domain := c.Args[0]
	if err := c.confirmTyped(T("%s sitesi dosyaları, veritabanları, e-postası ve DNS bölgesiyle birlikte silinecek.", domain), domain); err != nil {
		return err
	}
	path := "/sites/" + esc(domain)
	if c.Bool("keep-dns") {
		path += "?keep_dns=1"
	}
	resp, err := c.send(http.MethodDelete, path, nil)
	if err != nil {
		return err
	}
	return c.done(resp, T("%s siliniyor.", domain))
}

// --- Alan adları -------------------------------------------------------

func domainCommands() *Command {
	return &Command{
		Name: "domains", Aliases: []string{"domain"}, Group: T("Barındırma"),
		Short: T("Alt alan adları, ek alan adları ve yönlendirmeler"),
		Subs: []*Command{
			{Name: "list", Aliases: []string{"ls"}, Args: T("<site>"), MinArgs: 1, MaxArgs: 1, Short: T("Bir sitenin diğer adlarını listeler"), Complete: completeSites, Run: domainsList},
			{
				Name: "add", Args: T("<site> <ad>"), MinArgs: 2, MaxArgs: 2,
				Short: T("Alt alan adı (blog) ya da ek alan adı (ornek.net) ekler"),
				Long:  T("Bir siteye ad ekler. Noktasız ya da sitenin altındaki bir ad (blog, blog.ornek.com.tr) kendi klasörü olan bir alt alan adıdır; başka bir alan adı (ornek.net) aynı siteyi açan ek alan adıdır. DNS ve Let's Encrypt kendiliğinden ayarlanır."),
				Flags: func(f *Flags) {
					f.String("root", "", T("klasör"), T("Alt alan adının klasörü, sitenin klasörüne göre (varsayılan: subdomains/<ad>)"))
					f.Bool("dns", "", T("Ek alan adı için DNS bölgesi oluşturur (varsayılan: evet)"))
				},
				Examples: []string{"serfex domains add ornek.com.tr blog", "serfex domains add ornek.com.tr ornek.net --no-dns"},
				Complete: completeSites,
				Run:      domainsAdd,
			},
			{
				Name: "redirect", Args: T("<site> <kaynak> <hedef>"), MinArgs: 3, MaxArgs: 3,
				Short: T("Bir yolu (/eski) ya da alan adını başka bir adrese yönlendirir"),
				Flags: func(f *Flags) {
					f.String("code", "", "301|302", T("Yönlendirme kodu (varsayılan: 301)"))
					f.Bool("keep-path", "", T("Alan adı yönlendirmesinde yolu korur"))
					f.Bool("www", "", T("Alan adı yönlendirmesine www. adını da ekler"))
					f.Bool("dns", "", T("Yönlendirilen alan adı için DNS bölgesi oluşturur (varsayılan: evet)"))
				},
				Examples: []string{"serfex domains redirect ornek.com.tr /eski https://ornek.com.tr/yeni", "serfex domains redirect ornek.com.tr eskiad.com https://ornek.com.tr --keep-path"},
				Complete: completeSites,
				Run:      domainsRedirect,
			},
			{Name: "remove", Aliases: []string{"rm"}, Args: T("<site> <ad>"), MinArgs: 2, MaxArgs: 2, Short: T("Alt alan adını, ek alan adını ya da yönlendirmeyi kaldırır"), Complete: completeSites, Run: domainsRemove},
		},
	}
}

type siteNames struct {
	Site       string `json:"site"`
	Subdomains []struct {
		Name  string    `json:"name"`
		Root  string    `json:"root"`
		State nameState `json:"state"`
	} `json:"subdomains"`
	Aliases []struct {
		Name  string    `json:"name"`
		State nameState `json:"state"`
	} `json:"aliases"`
	Redirects []struct {
		Kind     string `json:"kind"`
		Source   string `json:"source"`
		WWW      bool   `json:"www"`
		Target   string `json:"target"`
		Code     int    `json:"code"`
		KeepPath bool   `json:"keep_path"`
	} `json:"redirects"`
}

type nameState struct {
	Text string `json:"text"`
	Tone string `json:"tone"`
	Hint string `json:"hint"`
}

func (c *Ctx) tone(t nameState) string {
	switch t.Tone {
	case "success", "ok":
		return c.green(t.Text)
	case "danger":
		return c.red(t.Text)
	case "warning":
		return c.yellow(t.Text)
	}
	return t.Text
}

func domainsList(c *Ctx) error {
	resp, err := c.get("/sites/"+esc(c.Args[0])+"/domains", nil)
	if err != nil {
		return err
	}
	return show(c, resp, func(n siteNames) error {
		if len(n.Subdomains)+len(n.Aliases)+len(n.Redirects) == 0 {
			c.empty(T("%s için alt alan adı, ek alan adı ya da yönlendirme yok.", n.Site))
			return nil
		}
		var rows [][]string
		for _, s := range n.Subdomains {
			rows = append(rows, []string{s.Name, T("Alt alan adı"), s.Root, c.tone(s.State)})
		}
		for _, a := range n.Aliases {
			rows = append(rows, []string{a.Name, T("Ek alan adı"), "→ " + n.Site, c.tone(a.State)})
		}
		for _, r := range n.Redirects {
			rows = append(rows, []string{r.Source, T("Yönlendirme"), fmt.Sprintf("→ %s (%d)", r.Target, r.Code), ""})
		}
		c.table([]string{T("Ad"), T("Tür"), T("Hedef"), T("Durum")}, rows)
		return nil
	})
}

func domainsAdd(c *Ctx) error {
	site, name := c.Args[0], strings.ToLower(strings.TrimSpace(c.Args[1]))
	var resp *api.Response
	var err error
	if !strings.Contains(name, ".") || strings.HasSuffix(name, "."+site) {
		body := map[string]any{"name": name}
		if r := c.String("root"); r != "" {
			body["root"] = r
		}
		resp, err = c.send(http.MethodPost, "/sites/"+esc(site)+"/subdomains", body)
	} else {
		body := map[string]any{"name": name}
		if c.Has("dns") {
			body["dns"] = c.Bool("dns")
		}
		resp, err = c.send(http.MethodPost, "/sites/"+esc(site)+"/aliases", body)
	}
	if err != nil {
		return err
	}
	if !strings.Contains(name, ".") {
		name += "." + site
	}
	return c.done(resp, T("%s eklendi; DNS ve SSL kendiliğinden ayarlanıyor.", name))
}

func domainsRedirect(c *Ctx) error {
	body := map[string]any{"source": c.Args[1], "target": c.Args[2]}
	if v := c.String("code"); v != "" {
		code, err := strconv.Atoi(v)
		if err != nil || (code != 301 && code != 302) {
			return usageErr(T("--code için 301 ya da 302 yazın."))
		}
		body["code"] = code
	}
	for _, k := range []string{"keep-path", "www", "dns"} {
		if c.Has(k) {
			body[strings.ReplaceAll(k, "-", "_")] = c.Bool(k)
		}
	}
	resp, err := c.send(http.MethodPost, "/sites/"+esc(c.Args[0])+"/redirects", body)
	if err != nil {
		return err
	}
	return c.done(resp, T("%s → %s yönlendirmesi eklendi.", c.Args[1], c.Args[2]))
}

func domainsRemove(c *Ctx) error {
	if err := c.confirm(T("%s kaldırılsın mı?", c.Args[1])); err != nil {
		return err
	}
	resp, err := c.send(http.MethodDelete, "/sites/"+esc(c.Args[0])+"/domains?name="+url.QueryEscape(c.Args[1]), nil)
	if err != nil {
		return err
	}
	return c.done(resp, T("%s kaldırıldı.", c.Args[1]))
}

// --- DNS ---------------------------------------------------------------

func dnsCommands() *Command {
	return &Command{
		Name: "dns", Group: T("Barındırma"),
		Short: T("DNS bölgeleri ve kayıtları"),
		Subs: []*Command{
			{Name: "zones", Aliases: []string{"list", "ls"}, Short: T("DNS bölgelerini listeler"), Run: dnsZones},
			{Name: "records", Aliases: []string{"show"}, Args: T("<bölge>"), MinArgs: 1, MaxArgs: 1, Short: T("Bir bölgenin kayıtlarını listeler"), Complete: completeZones, Run: dnsRecords},
			{
				Name: "add", Args: T("<bölge> <tür> <ad> <değer>"), MinArgs: 4, MaxArgs: 4,
				Short: T("Kayıt ekler (ad: @ bölgenin kendisi)"),
				Flags: func(f *Flags) {
					f.String("ttl", "", T("saniye"), T("Kaydın TTL'i (varsayılan: bölgeninki)"))
					f.String("priority", "", T("sayı"), T("MX ve SRV önceliği (varsayılan: 10)"))
				},
				Examples: []string{"serfex dns add ornek.com.tr A www 203.0.113.10", "serfex dns add ornek.com.tr MX @ mail.ornek.com.tr --priority 10", "serfex dns add ornek.com.tr TXT @ \"v=spf1 mx -all\""},
				Complete: completeZones,
				Run:      dnsAdd,
			},
			{Name: "delete", Aliases: []string{"rm"}, Args: T("<bölge> <kayıt-no>"), MinArgs: 2, MaxArgs: 2, Short: T("Kaydı siler (numarası: serfex dns records)"), Complete: completeZones, Run: dnsDelete},
		},
	}
}

func completeZones(c *Ctx, n int) []string {
	if n > 0 {
		return nil
	}
	resp, err := c.get("/dns/zones", nil)
	if err != nil {
		return nil
	}
	var zones []struct {
		Name string `json:"name"`
	}
	_ = resp.Decode(&zones)
	var out []string
	for _, z := range zones {
		out = append(out, z.Name)
	}
	return out
}

func dnsZones(c *Ctx) error {
	resp, err := c.get("/dns/zones", nil)
	if err != nil {
		return err
	}
	return show(c, resp, func(zones []struct {
		Name    string `json:"name"`
		Status  string `json:"status"`
		Site    string `json:"site"`
		Records int    `json:"records"`
	}) error {
		if len(zones) == 0 {
			c.empty(T("DNS bölgesi yok."))
			return nil
		}
		var rows [][]string
		for _, z := range zones {
			rows = append(rows, []string{z.Name, c.status(z.Status), orDash(z.Site), strconv.Itoa(z.Records)})
		}
		c.table([]string{T("Bölge"), T("Durum"), T("Site"), T("Kayıt")}, rows)
		return nil
	})
}

type record struct {
	ID       int    `json:"id"`
	Name     string `json:"name"`
	Type     string `json:"type"`
	Content  string `json:"content"`
	Priority *int   `json:"priority"`
	TTL      int    `json:"ttl"`
}

func dnsRecords(c *Ctx) error {
	resp, err := c.get("/dns/zones/"+esc(c.Args[0]), nil)
	if err != nil {
		return err
	}
	return show(c, resp, func(z struct {
		Name        string   `json:"name"`
		Status      string   `json:"status"`
		Nameservers []string `json:"nameservers"`
		LastError   string   `json:"last_error"`
		Records     []record `json:"records"`
	}) error {
		var rows [][]string
		for _, r := range z.Records {
			value := r.Content
			if r.Priority != nil {
				value = strconv.Itoa(*r.Priority) + " " + value
			}
			rows = append(rows, []string{strconv.Itoa(r.ID), r.Name, r.Type, value, strconv.Itoa(r.TTL)})
		}
		c.table([]string{T("No"), T("Ad"), T("Tür"), T("Değer"), "TTL"}, rows)
		if len(z.Nameservers) > 0 {
			c.println()
			c.println(c.dim(T("Ad sunucuları: %s", strings.Join(z.Nameservers, ", "))))
		}
		if z.LastError != "" {
			c.println(c.red(z.LastError))
		}
		return nil
	})
}

func dnsAdd(c *Ctx) error {
	body := map[string]any{"type": strings.ToUpper(c.Args[1]), "name": c.Args[2], "content": c.Args[3]}
	for _, k := range []string{"ttl", "priority"} {
		if c.Has(k) {
			n, err := c.Int(k, 0)
			if err != nil {
				return err
			}
			body[k] = n
		}
	}
	resp, err := c.send(http.MethodPost, "/dns/zones/"+esc(c.Args[0])+"/records", body)
	if err != nil {
		return err
	}
	var r record
	_ = resp.Decode(&r)
	return c.done(resp, T("%s kaydı eklendi (no %d).", r.Type, r.ID))
}

func dnsDelete(c *Ctx) error {
	if _, err := strconv.Atoi(c.Args[1]); err != nil {
		return usageErr(T("Kaydın numarasını yazın; numaralar: serfex dns records %s", c.Args[0]))
	}
	if err := c.confirm(T("%s bölgesinden %s numaralı kayıt silinsin mi?", c.Args[0], c.Args[1])); err != nil {
		return err
	}
	resp, err := c.send(http.MethodDelete, "/dns/zones/"+esc(c.Args[0])+"/records/"+c.Args[1], nil)
	if err != nil {
		return err
	}
	return c.done(resp, T("Kayıt silindi."))
}

// --- SSL ---------------------------------------------------------------

type sslState struct {
	Site  string `json:"site"`
	State struct {
		Text string `json:"text"`
		Tone string `json:"tone"`
		Busy bool   `json:"busy"`
	} `json:"state"`
	HTTPSRedirect bool  `json:"https_redirect"`
	Certificate   *cert `json:"certificate"`
	Pending       *cert `json:"pending"`
}

type cert struct {
	Source    string   `json:"source"`
	Issuer    string   `json:"issuer"`
	Names     []string `json:"names"`
	Status    string   `json:"status"`
	NotAfter  string   `json:"not_after"`
	DaysLeft  *int     `json:"days_left"`
	AutoRenew bool     `json:"auto_renew"`
	Error     string   `json:"error"`
}

func sslCommands() *Command {
	return &Command{
		Name: "ssl", Group: T("Barındırma"),
		Short: T("Sitelerin sertifikaları ve HTTPS"),
		Subs: []*Command{
			{Name: "list", Aliases: []string{"ls"}, Short: T("Sitelerin sertifikalarını listeler"), Run: sslList},
			{Name: "show", Args: T("<site>"), MinArgs: 1, MaxArgs: 1, Short: T("Bir sitenin sertifikasını gösterir"), Complete: completeSites, Run: sslShow},
			{
				Name: "issue", Args: T("<site>"), MinArgs: 1, MaxArgs: 1,
				Short: T("Let's Encrypt sertifikası alır"),
				Flags: func(f *Flags) {
					f.Bool("wildcard", "", T("*.alan-adı için de (DNS bu sunucudaysa)"))
					f.Bool("only-domain", "", T("www. ve ek alan adları olmadan"))
					f.Bool("wait", "", T("Sertifika alınana kadar bekler"))
				},
				Complete: completeSites,
				Run:      func(c *Ctx) error { return sslIssue(c, "") },
			},
			{
				Name: "renew", Args: T("<site>"), MinArgs: 1, MaxArgs: 1,
				Short:    T("Sertifikayı şimdi yeniler"),
				Flags:    func(f *Flags) { f.Bool("wait", "", T("Sertifika yenilenene kadar bekler")) },
				Complete: completeSites,
				Run:      func(c *Ctx) error { return sslIssue(c, "/renew") },
			},
			{
				Name: "https", Args: T("<site> on|off"), MinArgs: 2, MaxArgs: 2,
				Short:    T("HTTP isteklerini HTTPS'e yönlendirmeyi açar ya da kapatır"),
				Complete: completeSites,
				Run:      sslHTTPS,
			},
		},
	}
}

func (c *Ctx) days(cr *cert) string {
	if cr == nil || cr.DaysLeft == nil {
		return "—"
	}
	text := T("%d gün", *cr.DaysLeft)
	switch {
	case *cr.DaysLeft < 7:
		return c.red(text)
	case *cr.DaysLeft < 30:
		return c.yellow(text)
	}
	return text
}

func (c *Ctx) sslTone(s sslState) string {
	return c.tone(nameState{Text: s.State.Text, Tone: s.State.Tone})
}

func sslList(c *Ctx) error {
	resp, err := c.get("/certificates", nil)
	if err != nil {
		return err
	}
	return show(c, resp, func(list []sslState) error {
		if len(list) == 0 {
			c.empty(T("Henüz site yok."))
			return nil
		}
		var rows [][]string
		for _, s := range list {
			issuer, until := "—", "—"
			if s.Certificate != nil {
				issuer, until = s.Certificate.Issuer, when(s.Certificate.NotAfter)
			}
			rows = append(rows, []string{s.Site, c.sslTone(s), issuer, until, c.days(s.Certificate)})
		}
		c.table([]string{T("Site"), T("Durum"), T("Veren"), T("Bitiş"), T("Kalan")}, rows)
		return nil
	})
}

func sslShow(c *Ctx) error {
	resp, err := c.get("/sites/"+esc(c.Args[0])+"/ssl", nil)
	if err != nil {
		return err
	}
	return show(c, resp, func(s sslState) error {
		rows := [][2]string{{T("Site"), c.bold(s.Site)}, {T("Durum"), c.sslTone(s)}, {T("HTTPS yönlendirmesi"), yesNo(s.HTTPSRedirect)}}
		if cr := s.Certificate; cr != nil {
			rows = append(rows,
				[2]string{T("Veren"), cr.Issuer},
				[2]string{T("Adlar"), strings.Join(cr.Names, ", ")},
				[2]string{T("Bitiş"), when(cr.NotAfter) + " (" + c.days(cr) + ")"},
				[2]string{T("Kendiliğinden yenilenir"), yesNo(cr.AutoRenew)},
				[2]string{T("Son hata"), c.red(cr.Error)},
			)
		}
		if p := s.Pending; p != nil {
			rows = append(rows, [2]string{T("Bekleyen sertifika"), strings.Join(p.Names, ", ") + " · " + c.status(p.Status)}, [2]string{T("Hata"), c.red(p.Error)})
		}
		c.pairs(rows)
		return nil
	})
}

func sslIssue(c *Ctx, renew string) error {
	body := map[string]any{}
	if renew == "" {
		body["wildcard"] = c.Bool("wildcard")
		body["only_domain"] = c.Bool("only-domain")
	}
	path := "/sites/" + esc(c.Args[0]) + "/ssl"
	resp, err := c.send(http.MethodPost, path+renew, body)
	if err != nil {
		return err
	}
	if !c.Bool("wait") {
		return c.done(resp, T("%s için sertifika isteniyor. İzlemek için: serfex ssl show %s", c.Args[0], c.Args[0]))
	}
	var s sslState
	if err := c.waitUntil(3*c.env.Interval, T("Sertifika alınıyor…"), func() (bool, error) {
		if resp, err = c.get(path, nil); err != nil {
			return false, err
		}
		_ = resp.Decode(&s)
		return !s.State.Busy, nil
	}); err != nil {
		return err
	}
	if s.Pending != nil && s.Pending.Error != "" {
		c.errorf("%s", s.Pending.Error)
		return exitCode(1)
	}
	if s.Certificate == nil || s.Certificate.Status != "active" {
		c.errorf("%s", s.State.Text)
		return exitCode(1)
	}
	return c.done(resp, T("%s için sertifika hazır: %s tarihine kadar geçerli.", s.Site, when(s.Certificate.NotAfter)))
}

func sslHTTPS(c *Ctx) error {
	on, err := onOff(c.Args[1])
	if err != nil {
		return err
	}
	resp, err := c.send(http.MethodPut, "/sites/"+esc(c.Args[0])+"/ssl/https", map[string]bool{"redirect": on})
	if err != nil {
		return err
	}
	if on {
		return c.done(resp, T("%s: HTTP istekleri HTTPS'e yönlendiriliyor.", c.Args[0]))
	}
	return c.done(resp, T("%s: HTTP yönlendirmesi kapatıldı.", c.Args[0]))
}

func onOff(s string) (bool, error) {
	switch strings.ToLower(s) {
	case "on", "true", "1", "yes":
		return true, nil
	case "off", "false", "0", "no":
		return false, nil
	}
	return false, usageErr(T("on ya da off yazın."))
}
