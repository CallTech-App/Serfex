package app

import (
	"encoding/json"
	"fmt"
	"net/http"
	"net/url"
	"sort"
	"strconv"
	"strings"

	. "github.com/winchester37/servik/cli/internal/i18n"
)

// --- E-posta -----------------------------------------------------------

func mailCommands() *Command {
	passwordFlag := func(f *Flags) {
		f.Bool("password-stdin", "", T("Parolayı standart girdiden okur (verilmezse panel güçlü bir parola oluşturur ve bir kez gösterir)"))
	}
	return &Command{
		Name: "mail", Group: T("Barındırma"),
		Short: T("E-posta alan adları, posta kutuları ve yönlendiriciler"),
		Subs: []*Command{
			{Name: "domains", Short: T("E-postası açık alan adlarını listeler"), Run: mailDomains},
			{Name: "enable", Args: T("<alan-adı>"), MinArgs: 1, MaxArgs: 1, Short: T("Bir alan adında e-postayı açar (DNS kayıtları kendiliğinden yazılır)"), Complete: completeSites, Run: mailEnable},
			{Name: "list", Aliases: []string{"ls"}, Args: T("<alan-adı>"), MinArgs: 1, MaxArgs: 1, Short: T("Posta kutularını ve yönlendiricileri listeler"), Complete: completeMailDomains, Run: mailList},
			{
				Name: "create", Aliases: []string{"add"}, Args: T("<adres>"), MinArgs: 1, MaxArgs: 1,
				Short: T("Posta kutusu oluşturur"),
				Flags: func(f *Flags) {
					passwordFlag(f)
					f.String("quota", "", "MB", T("Kota, MB (varsayılan: sunucunun varsayılanı; 0 sınırsız)"))
				},
				Examples: []string{"servik mail create siparis@ornek.com.tr", "printf '%s' \"$PAROLA\" | servik mail create info@ornek.com.tr --password-stdin"},
				Run:      mailCreate,
			},
			{
				Name: "password", Args: T("<adres>"), MinArgs: 1, MaxArgs: 1,
				Short: T("Posta kutusunun parolasını değiştirir"),
				Flags: passwordFlag,
				Run:   mailPassword,
			},
			{Name: "quota", Args: T("<adres> <MB>"), MinArgs: 2, MaxArgs: 2, Short: T("Posta kutusunun kotasını değiştirir (0 sınırsız)"), Run: mailQuota},
			{Name: "delete", Aliases: []string{"rm"}, Args: T("<adres>"), MinArgs: 1, MaxArgs: 1, Short: T("Posta kutusunu siler"), Run: mailDelete},
			{
				Name: "forward", Args: T("<adres> <hedef>…"), MinArgs: 2, MaxArgs: -1,
				Short:    T("Bir adresi başka adreslere yönlendirir (*@alan-adı: tümünü yakala)"),
				Examples: []string{"servik mail forward satis@ornek.com.tr ali@ornek.com.tr ayse@gmail.com", "servik mail forward '*@ornek.com.tr' info@ornek.com.tr"},
				Run:      mailForward,
			},
			{Name: "unforward", Args: T("<adres>"), MinArgs: 1, MaxArgs: 1, Short: T("Yönlendiriciyi siler"), Run: mailUnforward},
		},
	}
}

func completeMailDomains(c *Ctx, n int) []string {
	if n > 0 {
		return nil
	}
	resp, err := c.get("/mail/domains", nil)
	if err != nil {
		return nil
	}
	var list []struct {
		Name string `json:"name"`
	}
	_ = resp.Decode(&list)
	var out []string
	for _, d := range list {
		out = append(out, d.Name)
	}
	return out
}

// address splits info@ornek.com.tr.
func address(s string) (string, string, error) {
	local, domain, ok := strings.Cut(strings.ToLower(strings.TrimSpace(s)), "@")
	if !ok || local == "" || domain == "" {
		return "", "", usageErr(T("%s bir e-posta adresi değil.", s))
	}
	return local, domain, nil
}

func mailDomains(c *Ctx) error {
	resp, err := c.get("/mail/domains", nil)
	if err != nil {
		return err
	}
	return show(c, resp, func(list []struct {
		Name       string `json:"name"`
		Status     string `json:"status"`
		Mailboxes  int    `json:"mailboxes"`
		Forwarders int    `json:"forwarders"`
		LastError  string `json:"last_error"`
	}) error {
		if len(list) == 0 {
			c.empty(T("Hiçbir alan adında e-posta açık değil. Açmak için: servik mail enable <alan-adı>"))
			return nil
		}
		var rows [][]string
		for _, d := range list {
			rows = append(rows, []string{d.Name, c.status(d.Status), strconv.Itoa(d.Mailboxes), strconv.Itoa(d.Forwarders)})
		}
		c.table([]string{T("Alan adı"), T("Durum"), T("Posta kutusu"), T("Yönlendirici")}, rows)
		return nil
	})
}

func mailEnable(c *Ctx) error {
	resp, err := c.send(http.MethodPost, "/mail/domains", map[string]string{"name": c.Args[0]})
	if err != nil {
		return err
	}
	return c.done(resp, T("%s için e-posta açılıyor; MX, SPF, DKIM ve DMARC kayıtları yazılıyor.", c.Args[0]))
}

type mailbox struct {
	Address   string   `json:"address"`
	Name      string   `json:"name"`
	QuotaMB   int      `json:"quota_mb"`
	Usage     float64  `json:"usage_bytes"`
	ForwardTo []string `json:"forward_to"`
	Password  string   `json:"password"`
}

func mailList(c *Ctx) error {
	resp, err := c.get("/mail/domains/"+esc(c.Args[0]), nil)
	if err != nil {
		return err
	}
	return show(c, resp, func(d struct {
		Name       string    `json:"name"`
		Status     string    `json:"status"`
		Mailboxes  []mailbox `json:"mailboxes"`
		Forwarders []struct {
			Address string   `json:"address"`
			To      []string `json:"to"`
		} `json:"forwarders"`
	}) error {
		if len(d.Mailboxes)+len(d.Forwarders) == 0 {
			c.empty(T("%s için posta kutusu ya da yönlendirici yok.", d.Name))
			return nil
		}
		var rows [][]string
		for _, m := range d.Mailboxes {
			quota := T("sınırsız")
			if m.QuotaMB > 0 {
				quota = bytesText(float64(m.QuotaMB) * 1024 * 1024)
			}
			rows = append(rows, []string{m.Address, T("Posta kutusu"), bytesText(m.Usage) + " / " + quota})
		}
		for _, f := range d.Forwarders {
			rows = append(rows, []string{f.Address, T("Yönlendirici"), "→ " + strings.Join(f.To, ", ")})
		}
		c.table([]string{T("Adres"), T("Tür"), T("Ayrıntı")}, rows)
		return nil
	})
}

// shownOnce prints a password the panel made: it is not kept anywhere.
func (c *Ctx) shownOnce(label, secret string) {
	if secret == "" {
		return
	}
	c.println()
	c.printf("  %s %s\n", c.dim(label), c.bold(secret))
	c.println("  " + c.dim(T("Bu parola yalnızca şimdi gösteriliyor; panel saklamaz.")))
}

func mailCreate(c *Ctx) error {
	local, domain, err := address(c.Args[0])
	if err != nil {
		return err
	}
	body := map[string]any{"name": local}
	if c.Bool("password-stdin") {
		pw, err := readSecret(c.env.Stdin)
		if err != nil {
			return err
		}
		body["password"] = pw
	}
	if c.Has("quota") {
		q, err := c.Int("quota", 0)
		if err != nil {
			return err
		}
		body["quota_mb"] = q
	}
	resp, err := c.send(http.MethodPost, "/mail/domains/"+esc(domain)+"/mailboxes", body)
	if err != nil {
		return err
	}
	if c.json {
		return c.printJSON(resp.Data())
	}
	var m mailbox
	_ = resp.Decode(&m)
	_ = c.done(nil, T("%s oluşturuldu.", m.Address))
	c.shownOnce(T("Parola:"), m.Password)
	return nil
}

func mailPassword(c *Ctx) error {
	local, domain, err := address(c.Args[0])
	if err != nil {
		return err
	}
	body := map[string]any{"generate": true}
	if c.Bool("password-stdin") {
		pw, err := readSecret(c.env.Stdin)
		if err != nil {
			return err
		}
		body = map[string]any{"password": pw}
	}
	resp, err := c.send(http.MethodPatch, "/mail/domains/"+esc(domain)+"/mailboxes/"+esc(local), body)
	if err != nil {
		return err
	}
	if c.json {
		return c.printJSON(resp.Data())
	}
	var m mailbox
	_ = resp.Decode(&m)
	_ = c.done(nil, T("%s parolası değişti; yeni parola hemen geçerli.", m.Address))
	c.shownOnce(T("Yeni parola:"), m.Password)
	return nil
}

func mailQuota(c *Ctx) error {
	local, domain, err := address(c.Args[0])
	if err != nil {
		return err
	}
	q, err := strconv.Atoi(c.Args[1])
	if err != nil || q < 0 {
		return usageErr(T("Kotayı MB olarak yazın, örneğin 2048."))
	}
	resp, err := c.send(http.MethodPatch, "/mail/domains/"+esc(domain)+"/mailboxes/"+esc(local), map[string]int{"quota_mb": q})
	if err != nil {
		return err
	}
	return c.done(resp, T("%s kotası güncellendi.", c.Args[0]))
}

func mailDelete(c *Ctx) error {
	local, domain, err := address(c.Args[0])
	if err != nil {
		return err
	}
	if err := c.confirm(T("%s ve içindeki tüm postalar silinsin mi?", c.Args[0])); err != nil {
		return err
	}
	resp, err := c.send(http.MethodDelete, "/mail/domains/"+esc(domain)+"/mailboxes/"+esc(local), nil)
	if err != nil {
		return err
	}
	return c.done(resp, T("%s silindi; postaları 10 dakika sonra silinir.", c.Args[0]))
}

func mailForward(c *Ctx) error {
	local, domain, err := address(c.Args[0])
	if err != nil {
		return err
	}
	resp, err := c.send(http.MethodPut, "/mail/domains/"+esc(domain)+"/forwarders/"+esc(local), map[string]any{"to": c.Args[1:]})
	if err != nil {
		return err
	}
	return c.done(resp, T("%s → %s", c.Args[0], strings.Join(c.Args[1:], ", ")))
}

func mailUnforward(c *Ctx) error {
	local, domain, err := address(c.Args[0])
	if err != nil {
		return err
	}
	resp, err := c.send(http.MethodDelete, "/mail/domains/"+esc(domain)+"/forwarders/"+esc(local), nil)
	if err != nil {
		return err
	}
	return c.done(resp, T("%s yönlendiricisi silindi.", c.Args[0]))
}

// --- Veritabanları -----------------------------------------------------

func dbCommands() *Command {
	return &Command{
		Name: "db", Aliases: []string{"databases", "database"}, Group: T("Barındırma"),
		Short: T("Sitelerin veritabanları"),
		Subs: []*Command{
			{
				Name: "list", Aliases: []string{"ls"}, Short: T("Veritabanlarını listeler"),
				Flags: func(f *Flags) { f.String("site", "", T("site"), T("Yalnızca bu sitenin veritabanları")) },
				Run:   dbList,
			},
			{
				Name: "create", Aliases: []string{"add"}, Args: T("<site> <ad>"), MinArgs: 2, MaxArgs: 2,
				Short: T("Veritabanı ve kullanıcısını oluşturur"),
				Long:  T("Veritabanı ve aynı adlı bir kullanıcı oluşturur. Adlara sitenin ön eki (kahve_) kendiliğinden eklenir; parola verilmezse panel güçlü bir parola oluşturur ve bağlantı bilgileriyle bir kez gösterir."),
				Flags: func(f *Flags) {
					f.String("user", "", T("ad"), T("Kullanıcının adı (varsayılan: veritabanınınki)"))
					f.Bool("no-user", "", T("Kullanıcı oluşturmaz"))
					f.Bool("password-stdin", "", T("Kullanıcının parolasını standart girdiden okur"))
					f.String("access", "", "all|read", T("Kullanıcının yetkisi (varsayılan: all)"))
					f.String("server", "", "mysql|postgresql", T("Veritabanı sunucusu (varsayılan: kurulu olan ilki)"))
				},
				Examples: []string{"servik db create kahveatolyesi.com.tr dukkan"},
				Complete: completeSites,
				Run:      dbCreate,
			},
			{
				Name: "delete", Aliases: []string{"rm"}, Args: T("<ad>"), MinArgs: 1, MaxArgs: 1,
				Short: T("Veritabanını siler (önce yedeğini alır)"),
				Flags: func(f *Flags) { f.Bool("no-backup", "", T("Silmeden önce yedek almaz")) },
				Run:   dbDelete,
			},
		},
	}
}

func dbList(c *Ctx) error {
	q := url.Values{}
	if s := c.String("site"); s != "" {
		q.Set("site", s)
	}
	resp, err := c.get("/databases", q)
	if err != nil {
		return err
	}
	return show(c, resp, func(list []struct {
		Name   string  `json:"name"`
		Server string  `json:"server"`
		Site   string  `json:"site"`
		Size   float64 `json:"size_bytes"`
		Tables *int    `json:"tables"`
		Users  []struct {
			Name string `json:"name"`
		} `json:"users"`
	}) error {
		if len(list) == 0 {
			c.empty(T("Veritabanı yok. Oluşturmak için: servik db create <site> <ad>"))
			return nil
		}
		var rows [][]string
		for _, d := range list {
			var users []string
			for _, u := range d.Users {
				users = append(users, u.Name)
			}
			rows = append(rows, []string{d.Name, d.Server, orDash(d.Site), bytesText(d.Size), orDash(strings.Join(users, ", "))})
		}
		c.table([]string{T("Veritabanı"), T("Sunucu"), T("Site"), T("Boyut"), T("Kullanıcılar")}, rows)
		return nil
	})
}

func dbCreate(c *Ctx) error {
	body := map[string]any{"site": c.Args[0], "name": c.Args[1]}
	if c.Bool("no-user") {
		body["user"] = ""
	} else if u := c.String("user"); u != "" {
		body["user"] = u
	}
	if c.Bool("password-stdin") {
		pw, err := readSecret(c.env.Stdin)
		if err != nil {
			return err
		}
		body["password"] = pw
	}
	for _, k := range []string{"access", "server"} {
		if v := c.String(k); v != "" {
			body[k] = v
		}
	}
	resp, err := c.send(http.MethodPost, "/databases", body)
	if err != nil {
		return err
	}
	if c.json {
		return c.printJSON(resp.Data())
	}
	var d struct {
		Name       string            `json:"name"`
		Connection map[string]string `json:"connection"`
	}
	_ = resp.Decode(&d)
	_ = c.done(nil, T("%s veritabanı oluşturuldu.", d.Name))
	if len(d.Connection) > 0 {
		c.println()
		order := []string{"driver", "host", "port", "database", "username", "password"}
		env := map[string]string{"driver": "DB_CONNECTION", "host": "DB_HOST", "port": "DB_PORT", "database": "DB_DATABASE", "username": "DB_USERNAME", "password": "DB_PASSWORD"}
		for _, k := range order {
			c.printf("  %s=%s\n", env[k], d.Connection[k])
		}
		c.println("  " + c.dim(T("Bu parola yalnızca şimdi gösteriliyor; panel saklamaz.")))
	}
	return nil
}

func dbDelete(c *Ctx) error {
	if err := c.confirm(T("%s veritabanı silinsin mi?", c.Args[0])); err != nil {
		return err
	}
	path := "/databases/" + esc(c.Args[0])
	if c.Bool("no-backup") {
		path += "?backup=0"
	}
	resp, err := c.send(http.MethodDelete, path, nil)
	if err != nil {
		return err
	}
	if c.Bool("no-backup") {
		return c.done(resp, T("%s silindi.", c.Args[0]))
	}
	return c.done(resp, T("%s silindi; bir kopyası yedeklerde.", c.Args[0]))
}

// --- Yedekler ----------------------------------------------------------

type backup struct {
	ID          int     `json:"id"`
	Scope       string  `json:"scope"`
	Type        string  `json:"type"`
	Trigger     string  `json:"trigger"`
	Status      string  `json:"status"`
	Destination string  `json:"destination"`
	Encrypted   bool    `json:"encrypted"`
	Size        float64 `json:"size"`
	Error       string  `json:"error"`
	CreatedAt   string  `json:"created_at"`
	FinishedAt  string  `json:"finished_at"`
	Done        bool    `json:"done"`
	Items       []struct {
		Name       string   `json:"name"`
		Status     string   `json:"status"`
		Size       float64  `json:"size"`
		Parts      string   `json:"parts"`
		Restorable []string `json:"restorable"`
		Error      string   `json:"error"`
	} `json:"items"`
}

func backupCommands() *Command {
	return &Command{
		Name: "backups", Aliases: []string{"backup"}, Group: T("Barındırma"),
		Short: T("Yedekler ve geri yükleme"),
		Subs: []*Command{
			{
				Name: "list", Aliases: []string{"ls"}, Short: T("Yedekleri listeler, en yenisi önce"),
				Flags: func(f *Flags) {
					f.String("site", "", T("site"), T("Yalnızca bu sitenin bulunduğu yedekler"))
					f.String("limit", "n", T("sayı"), T("En fazla kaç yedek (varsayılan: 20)"))
				},
				Run: backupsList,
			},
			{Name: "show", Args: T("<no>"), MinArgs: 1, MaxArgs: 1, Short: T("Bir yedeğin içindekileri gösterir"), Run: backupsShow},
			{
				Name: "create", Args: T("[site]"), MaxArgs: 1,
				Short:    T("Şimdi yedek alır: bir siteyi ya da (yöneticiler) tüm sunucuyu"),
				Flags:    func(f *Flags) { f.Bool("wait", "", T("Yedek bitene kadar bekler")) },
				Complete: completeSites,
				Run:      backupsCreate,
			},
			{
				Name: "restore", Args: T("<no> <site>"), MinArgs: 2, MaxArgs: 2,
				Short: T("Bir siteyi yedekten geri yükler"),
				Long:  T("Bir siteyi yedekten geri yükler. Önce sitenin şimdiki hâli yedeklenir, böylece geri yükleme de geri alınabilir."),
				Flags: func(f *Flags) {
					f.Strings("only", "", T("parça"), T("Yalnızca bunlar: files, databases, mail, dns, certificates (virgülle)"))
					f.Bool("no-safety", "", T("Önce güvenlik yedeği almaz"))
					f.Bool("wait", "", T("Geri yükleme bitene kadar bekler"))
				},
				Examples: []string{"servik backups restore 42 kahveatolyesi.com.tr --only files,databases --wait"},
				Run:      backupsRestore,
			},
		},
	}
}

func backupsList(c *Ctx) error {
	q := url.Values{}
	if s := c.String("site"); s != "" {
		q.Set("site", s)
	}
	n, err := c.Int("limit", 20)
	if err != nil {
		return err
	}
	q.Set("limit", strconv.Itoa(n))
	resp, err := c.get("/backups", q)
	if err != nil {
		return err
	}
	return show(c, resp, func(list []backup) error {
		if len(list) == 0 {
			c.empty(T("Yedek yok. Almak için: servik backups create <site>"))
			return nil
		}
		var rows [][]string
		for _, b := range list {
			var names []string
			for _, i := range b.Items {
				names = append(names, i.Name)
			}
			what := strings.Join(names, ", ")
			if b.Scope == "server" {
				what = T("Tüm sunucu (%d)", len(b.Items))
			}
			rows = append(rows, []string{strconv.Itoa(b.ID), when(b.CreatedAt), what, c.status(b.Status), bytesText(b.Size), b.Destination})
		}
		c.table([]string{T("No"), T("Tarih"), T("İçerik"), T("Durum"), T("Boyut"), T("Hedef")}, rows)
		return nil
	})
}

func (c *Ctx) printBackup(b backup) {
	c.pairs([][2]string{
		{T("Yedek"), strconv.Itoa(b.ID)},
		{T("Durum"), c.status(b.Status)},
		{T("Tarih"), when(b.CreatedAt)},
		{T("Hedef"), b.Destination},
		{T("Boyut"), bytesText(b.Size)},
		{T("Hata"), c.red(b.Error)},
	})
	if len(b.Items) == 0 {
		return
	}
	c.println()
	var rows [][]string
	for _, i := range b.Items {
		rows = append(rows, []string{i.Name, c.status(i.Status), bytesText(i.Size), orDash(strings.Join(i.Restorable, ", "))})
	}
	c.table([]string{T("Ad"), T("Durum"), T("Boyut"), T("Geri yüklenebilir")}, rows)
}

func backupsShow(c *Ctx) error {
	if _, err := strconv.Atoi(c.Args[0]); err != nil {
		return usageErr(T("Yedeğin numarasını yazın; numaralar: servik backups list"))
	}
	resp, err := c.get("/backups/"+c.Args[0], nil)
	if err != nil {
		return err
	}
	return show(c, resp, func(b backup) error {
		c.printBackup(b)
		return nil
	})
}

func backupsCreate(c *Ctx) error {
	body := map[string]any{}
	if len(c.Args) > 0 {
		body["site"] = c.Args[0]
	}
	resp, err := c.send(http.MethodPost, "/backups", body)
	if err != nil {
		return err
	}
	var b backup
	_ = resp.Decode(&b)
	if !c.Bool("wait") {
		return c.done(resp, T("Yedek %d alınıyor; durumu: servik backups show %d", b.ID, b.ID))
	}
	if err := c.waitUntil(3*c.env.Interval, T("Yedek %d alınıyor…", b.ID), func() (bool, error) {
		if resp, err = c.get("/backups/"+strconv.Itoa(b.ID), nil); err != nil {
			return false, err
		}
		_ = resp.Decode(&b)
		return b.Done, nil
	}); err != nil {
		return err
	}
	if b.Status == "failed" {
		c.errorf("%s", T("Yedek %d alınamadı: %s", b.ID, orDash(b.Error)))
		return exitCode(1)
	}
	return c.done(resp, T("Yedek %d hazır (%s).", b.ID, bytesText(b.Size)))
}

func backupsRestore(c *Ctx) error {
	if _, err := strconv.Atoi(c.Args[0]); err != nil {
		return usageErr(T("Yedeğin numarasını yazın; numaralar: servik backups list"))
	}
	body := map[string]any{"site": c.Args[1]}
	if only := c.Strings("only"); len(only) > 0 {
		body["parts"] = only
	}
	if c.Bool("no-safety") {
		body["safety"] = false
	}
	if err := c.confirm(T("%s, %s numaralı yedekten geri yüklensin mi? Sitedeki şimdiki dosyaların yerini yedektekiler alır.", c.Args[1], c.Args[0])); err != nil {
		return err
	}
	resp, err := c.send(http.MethodPost, "/backups/"+c.Args[0]+"/restore", body)
	if err != nil {
		return err
	}
	var r struct {
		ID     int    `json:"id"`
		Status string `json:"status"`
		Error  string `json:"error"`
		Done   bool   `json:"done"`
	}
	_ = resp.Decode(&r)
	if !c.Bool("wait") {
		return c.done(resp, T("%s geri yükleniyor.", c.Args[1]))
	}
	if err := c.waitUntil(3*c.env.Interval, T("%s geri yükleniyor…", c.Args[1]), func() (bool, error) {
		if resp, err = c.get("/restores/"+strconv.Itoa(r.ID), nil); err != nil {
			return false, err
		}
		_ = resp.Decode(&r)
		return r.Done, nil
	}); err != nil {
		return err
	}
	if r.Status == "failed" {
		c.errorf("%s", T("Geri yükleme başarısız: %s", orDash(r.Error)))
		return exitCode(1)
	}
	return c.done(resp, T("%s geri yüklendi.", c.Args[1]))
}

// --- Hizmetler ---------------------------------------------------------

type service struct {
	Unit      string   `json:"unit"`
	Name      string   `json:"name"`
	Group     string   `json:"group"`
	State     string   `json:"state"`
	SubState  string   `json:"sub_state"`
	Enabled   string   `json:"enabled"`
	Since     string   `json:"since"`
	PID       int      `json:"pid"`
	Restarts  int      `json:"restarts"`
	Memory    float64  `json:"memory"`
	Protected string   `json:"protected"`
	Affects   []string `json:"affects"`
}

func serviceCommands() *Command {
	act := func(action string) func(c *Ctx) error {
		return func(c *Ctx) error { return servicesAct(c, action) }
	}
	return &Command{
		Name: "services", Aliases: []string{"service"}, Group: T("Sunucu"),
		Short: T("Sunucunun hizmetleri (yöneticiler)"),
		Subs: []*Command{
			{Name: "list", Aliases: []string{"ls"}, Short: T("Hizmetleri listeler"), Run: servicesList},
			{Name: "status", Aliases: []string{"show"}, Args: T("<hizmet>"), MinArgs: 1, MaxArgs: 1, Short: T("Bir hizmetin durumunu gösterir"), Complete: completeServices, Run: servicesShow},
			{Name: "start", Args: T("<hizmet>"), MinArgs: 1, MaxArgs: 1, Short: T("Hizmeti başlatır"), Complete: completeServices, Run: act("start")},
			{Name: "stop", Args: T("<hizmet>"), MinArgs: 1, MaxArgs: 1, Short: T("Hizmeti durdurur"), Complete: completeServices, Run: act("stop")},
			{Name: "restart", Args: T("<hizmet>"), MinArgs: 1, MaxArgs: 1, Short: T("Ayarını denetleyip hizmeti yeniden başlatır"), Complete: completeServices, Run: act("restart")},
			{
				Name: "logs", Args: T("<hizmet>"), MinArgs: 1, MaxArgs: 1, Short: T("Hizmetin günlüğünün son satırları"),
				Flags:    func(f *Flags) { f.String("lines", "n", T("sayı"), T("Kaç satır (varsayılan: 50)")) },
				Complete: completeServices,
				Run:      servicesLogs,
			},
		},
	}
}

func completeServices(c *Ctx, n int) []string {
	if n > 0 {
		return nil
	}
	resp, err := c.get("/services", nil)
	if err != nil {
		return nil
	}
	var list []service
	_ = resp.Decode(&list)
	var out []string
	for _, s := range list {
		out = append(out, s.Unit)
	}
	return out
}

func servicesList(c *Ctx) error {
	resp, err := c.get("/services", nil)
	if err != nil {
		return err
	}
	return show(c, resp, func(list []service) error {
		sort.SliceStable(list, func(i, j int) bool { return list[i].Group < list[j].Group })
		var rows [][]string
		for _, s := range list {
			rows = append(rows, []string{s.Unit, s.Name, c.toneOf(s.State, s.State+" ("+s.SubState+")"), bytesText(s.Memory), when(s.Since)})
		}
		c.table([]string{T("Hizmet"), T("Ad"), T("Durum"), T("Bellek"), T("Başlangıç")}, rows)
		return nil
	})
}

func servicesShow(c *Ctx) error {
	resp, err := c.get("/services/"+esc(c.Args[0]), nil)
	if err != nil {
		return err
	}
	return show(c, resp, func(s service) error {
		c.pairs([][2]string{
			{T("Hizmet"), c.bold(s.Name) + " (" + s.Unit + ")"},
			{T("Durum"), c.toneOf(s.State, s.State+" ("+s.SubState+")")},
			{T("Açılışta"), s.Enabled},
			{T("Başlangıç"), when(s.Since)},
			{"PID", strconv.Itoa(s.PID)},
			{T("Yeniden başlama"), strconv.Itoa(s.Restarts)},
			{T("Bellek"), bytesText(s.Memory)},
			{T("Etkiler"), strings.Join(s.Affects, ", ")},
			{T("Korumalı"), s.Protected},
		})
		return nil
	})
}

func servicesAct(c *Ctx, action string) error {
	if action == "stop" {
		if err := c.confirm(T("%s durdurulsun mu? Ona bağlı siteler ve hizmetler çalışmaz.", c.Args[0])); err != nil {
			return err
		}
	}
	resp, err := c.send(http.MethodPost, "/services/"+esc(c.Args[0])+"/"+action, nil)
	if err != nil {
		return err
	}
	var out struct {
		Warning string `json:"warning"`
	}
	_ = json.Unmarshal(resp.Body, &out)
	var s service
	_ = resp.Decode(&s)
	if c.json {
		return c.printJSON(resp.Body)
	}
	if out.Warning != "" {
		c.errorf("%s", out.Warning)
		return exitCode(1)
	}
	msg := map[string]string{"start": T("%s başlatıldı.", s.Name), "stop": T("%s durduruldu.", s.Name), "restart": T("%s yeniden başlatıldı.", s.Name)}[action]
	return c.done(nil, msg)
}

func servicesLogs(c *Ctx) error {
	n, err := c.Int("lines", 50)
	if err != nil {
		return err
	}
	resp, err := c.get("/services/"+esc(c.Args[0])+"/logs", url.Values{"lines": {strconv.Itoa(n)}})
	if err != nil {
		return err
	}
	return show(c, resp, func(l struct {
		Lines []string `json:"lines"`
	}) error {
		for _, line := range l.Lines {
			c.println(line)
		}
		return nil
	})
}

// --- Müşteriler --------------------------------------------------------

type customer struct {
	ID       int    `json:"id"`
	Name     string `json:"name"`
	Company  string `json:"company"`
	Email    string `json:"email"`
	Username string `json:"username"`
	Package  *struct {
		Name string `json:"name"`
	} `json:"package"`
	Suspended bool     `json:"suspended"`
	Reason    string   `json:"suspend_reason"`
	Sites     []string `json:"sites"`
	Usage     map[string]struct {
		Text string `json:"text"`
	} `json:"usage"`
}

func customerCommands() *Command {
	return &Command{
		Name: "customers", Aliases: []string{"customer"}, Group: T("Sunucu"),
		Short: T("Müşteriler ve paketleri (yöneticiler)"),
		Subs: []*Command{
			{Name: "list", Aliases: []string{"ls"}, Short: T("Müşterileri listeler"), Run: customersList},
			{Name: "show", Args: T("<no>"), MinArgs: 1, MaxArgs: 1, Short: T("Müşterinin siteleri ve paket kullanımı"), Run: customersShow},
			{
				Name: "suspend", Args: T("<no>"), MinArgs: 1, MaxArgs: 1, Short: T("Müşteriyi askıya alır (siteleri 503, postaları bekletilir)"),
				Flags: func(f *Flags) { f.String("reason", "", T("metin"), T("Müşterinin göreceği neden")) },
				Run:   func(c *Ctx) error { return customersSuspend(c, true) },
			},
			{Name: "unsuspend", Args: T("<no>"), MinArgs: 1, MaxArgs: 1, Short: T("Askıyı kaldırır"), Run: func(c *Ctx) error { return customersSuspend(c, false) }},
			{Name: "packages", Short: T("Paketleri listeler"), Run: packagesList},
		},
	}
}

func customersList(c *Ctx) error {
	resp, err := c.get("/customers", nil)
	if err != nil {
		return err
	}
	return show(c, resp, func(list []customer) error {
		if len(list) == 0 {
			c.empty(T("Henüz müşteri yok."))
			return nil
		}
		var rows [][]string
		for _, m := range list {
			pkg := ""
			if m.Package != nil {
				pkg = m.Package.Name
			}
			state := c.green(T("Etkin"))
			if m.Suspended {
				state = c.red(T("Askıda"))
			}
			name := m.Name
			if m.Company != "" {
				name = m.Company + " · " + m.Name
			}
			rows = append(rows, []string{strconv.Itoa(m.ID), name, m.Email, pkg, state})
		}
		c.table([]string{T("No"), T("Müşteri"), T("E-posta"), T("Paket"), T("Durum")}, rows)
		return nil
	})
}

func customersShow(c *Ctx) error {
	resp, err := c.get("/customers/"+esc(c.Args[0]), nil)
	if err != nil {
		return err
	}
	return show(c, resp, func(m customer) error {
		pkg := ""
		if m.Package != nil {
			pkg = m.Package.Name
		}
		rows := [][2]string{{T("Müşteri"), c.bold(m.Name)}, {T("Firma"), m.Company}, {T("E-posta"), m.Email}, {T("Kullanıcı adı"), m.Username}, {T("Paket"), pkg}, {T("Siteler"), strings.Join(m.Sites, ", ")}}
		if m.Suspended {
			rows = append(rows, [2]string{T("Durum"), c.red(T("Askıda")) + " " + m.Reason})
		}
		keys := make([]string, 0, len(m.Usage))
		for k := range m.Usage {
			keys = append(keys, k)
		}
		sort.Strings(keys)
		for _, k := range keys {
			rows = append(rows, [2]string{k, m.Usage[k].Text})
		}
		c.pairs(rows)
		return nil
	})
}

func customersSuspend(c *Ctx, suspend bool) error {
	action := "unsuspend"
	var body any
	if suspend {
		if err := c.confirm(T("%s numaralı müşteri askıya alınsın mı? Siteleri ziyaretçilere kapanır.", c.Args[0])); err != nil {
			return err
		}
		action = "suspend"
		body = map[string]string{"reason": c.String("reason")}
	}
	resp, err := c.send(http.MethodPost, "/customers/"+esc(c.Args[0])+"/"+action, body)
	if err != nil {
		return err
	}
	var m customer
	_ = resp.Decode(&m)
	if suspend {
		return c.done(resp, T("%s askıya alındı.", m.Name))
	}
	return c.done(resp, T("%s yeniden etkin.", m.Name))
}

func packagesList(c *Ctx) error {
	resp, err := c.get("/packages", nil)
	if err != nil {
		return err
	}
	return show(c, resp, func(list []struct {
		ID        int    `json:"id"`
		Name      string `json:"name"`
		Default   bool   `json:"default"`
		Customers int    `json:"customers"`
	}) error {
		var rows [][]string
		for _, p := range list {
			name := p.Name
			if p.Default {
				name += " " + c.dim(T("(varsayılan)"))
			}
			rows = append(rows, []string{strconv.Itoa(p.ID), name, fmt.Sprint(p.Customers)})
		}
		c.table([]string{T("No"), T("Paket"), T("Müşteri")}, rows)
		return nil
	})
}
