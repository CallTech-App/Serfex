package app

import (
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"net/http"
	"net/url"
	"runtime"
	"strings"
	"time"

	"github.com/winchester37/servik/cli/internal/api"
	"github.com/winchester37/servik/cli/internal/config"
	. "github.com/winchester37/servik/cli/internal/i18n"
	"github.com/winchester37/servik/cli/internal/version"
)

// me is GET /me: who the key or token acts as.
type me struct {
	Kind     string `json:"kind"`
	Name     string `json:"name"`
	Email    string `json:"email"`
	Account  string `json:"account"`
	Role     string `json:"role"`
	RoleName string `json:"role_name"`
	Customer string `json:"customer"`
	App      string `json:"app"`
	Key      *struct {
		Name      string `json:"name"`
		Kind      string `json:"kind"`
		Scope     string `json:"scope"`
		ExpiresAt string `json:"expires_at"`
	} `json:"key"`
	Server struct {
		Hostname string `json:"hostname"`
		Version  string `json:"version"`
	} `json:"server"`
}

// me asks the panel once per run.
func (c *Ctx) me() (*me, error) {
	if c.who != nil {
		return c.who, nil
	}
	client, err := c.Client()
	if err != nil {
		return nil, err
	}
	resp, err := client.Get(c, "/me", nil)
	if err != nil {
		return nil, err
	}
	var m me
	if err := resp.Decode(&m); err != nil {
		return nil, err
	}
	c.who = &m
	return &m, nil
}

const (
	panelConfig = "/etc/servik/panel.json"
	panelDir    = "/opt/servik/current/panel"
	defaultPort = "8443"
)

func accountCommands() []*Command {
	return []*Command{
		{
			Name: "login", Group: T("Hesap"), Args: T("[panel-adresi]"), MaxArgs: 1,
			Short: T("Bir panele giriş yapar"),
			Long: T("Bir Servik paneline giriş yapar. Tarayıcıda bir onay sayfası açılır; onayladığınızda bu bilgisayar için bir anahtar oluşur ve Hesap güvenliği altında listelenir.\n\n" +
				"CI ya da betikler için --token ile bir API anahtarı ya da bir uygulamanın Erişim ve API bölümündeki belirteci verin. Sunucunun kendisinde root olarak --local tarayıcısız giriş yapar."),
			Examples: []string{
				"servik login panel.ornek.com.tr:8443",
				"servik login https://panel.ornek.com.tr:8443 --token svk_…",
				"sudo servik login --local",
			},
			Flags: func(f *Flags) {
				f.Bool("local", "", T("Sunucuda root olarak, tarayıcısız giriş"))
				f.String("email", "", T("e-posta"), T("--local: anahtarın ait olacağı yönetici (varsayılan: ilk tam yetkili yönetici)"))
				f.Bool("read-only", "", T("--local: yalnızca okuyabilen anahtar"))
				f.String("fingerprint", "", "sha256", T("Panelin sertifikasının beklenen SHA-256 parmak izi"))
				f.Bool("no-browser", "", T("Tarayıcıyı açmaz; bağlantıyı yalnızca yazar"))
			},
			Run: login,
		},
		{
			Name: "logout", Group: T("Hesap"),
			Short: T("Profilden çıkar ve anahtarını panelde kapatır"),
			Run:   logout,
		},
		{
			Name: "profile", Aliases: []string{"profiles"}, Group: T("Hesap"),
			Short: T("Giriş yapılan panelleri listeler ve aralarında geçer"),
			Subs: []*Command{
				{Name: "list", Aliases: []string{"ls"}, Short: T("Profilleri listeler"), Run: profileList},
				{Name: "use", Args: T("<ad>"), MinArgs: 1, MaxArgs: 1, Short: T("Varsayılan profili değiştirir"), Run: profileUse, Complete: completeProfiles},
				{Name: "remove", Aliases: []string{"rm"}, Args: T("<ad>"), MinArgs: 1, MaxArgs: 1, Short: T("Profili siler ve anahtarını panelde kapatır"), Complete: completeProfiles, Run: func(c *Ctx) error {
					c.global.values["profile"] = []string{c.Args[0]}
					return logout(c)
				}},
			},
		},
		{Name: "whoami", Group: T("Hesap"), Short: T("Kim olarak, hangi panele bağlı olduğunuzu gösterir"), Run: whoami},
		{Name: "status", Group: T("Hesap"), Short: T("Sunucunun durumunu ve dikkat isteyen sorunları gösterir"), Run: status},
		{
			Name: "fingerprint", Group: T("Hesap"), Args: T("[panel-adresi]"), MaxArgs: 1,
			Short: T("Panelin sertifikasının parmak izini yazar"),
			Long:  T("Panelin gösterdiği sertifikanın SHA-256 parmak izini yazar. Sunucuda adres vermeden çalıştırın ve çıkan değeri ilk girişte bilgisayarınızda görünenle karşılaştırın."),
			Run:   fingerprint,
		},
	}
}

// clientName is how the approval page and Hesap güvenliği name this computer.
func (c *Ctx) clientName() string {
	host, _ := c.env.Hostname()
	if host == "" {
		host = "servik"
	}
	osName := map[string]string{"darwin": "macOS", "linux": "Linux"}[runtime.GOOS]
	if osName == "" {
		osName = runtime.GOOS
	}
	return host + " · " + osName
}

func login(c *Ctx) error {
	if c.Bool("local") {
		return loginLocal(c)
	}
	raw := c.first("url", "SERVIK_URL")
	if len(c.Args) > 0 {
		raw = c.Args[0]
	}
	if raw == "" {
		return usageErr(T("Panelin adresini yazın: servik login panel.ornek.com.tr:8443"))
	}
	u, err := api.NormalizeURL(raw)
	if err != nil {
		return usageErr(T("%s bir panel adresi değil.", raw))
	}
	u, fp, err := c.trust(u, "", c.String("fingerprint"))
	if err != nil {
		return err
	}
	token := c.first("token", "SERVIK_TOKEN")
	if token == "" {
		if token, err = c.approveInBrowser(u, fp); err != nil {
			return err
		}
	}
	return c.signIn(&config.Profile{URL: u.String(), Token: token, Fingerprint: fp})
}

// trust checks the panel's certificate. One the computer trusts needs
// nothing; any other (the panel's own, before Let's Encrypt) is pinned by
// its fingerprint once the person accepts it. Without a port, the
// panel's own 8443 is tried after 443.
func (c *Ctx) trust(u *url.URL, connect, want string) (*url.URL, string, error) {
	if u.Scheme == "http" {
		return u, "", nil
	}
	chain, err := api.Certificate(c, u, connect)
	if err != nil && u.Port() == "" && connect == "" {
		alt := *u
		alt.Host = net.JoinHostPort(u.Hostname(), defaultPort)
		if chain2, err2 := api.Certificate(c, &alt, ""); err2 == nil {
			u, chain, err = &alt, chain2, nil
		}
	}
	if err != nil {
		return nil, "", fmt.Errorf("%s", T("%s adresine bağlanılamadı: %s", api.HostPort(u), err))
	}
	if api.Verify(chain, u.Hostname()) == nil {
		return u, "", nil
	}
	fp := api.Fingerprint(chain[0])
	if want != "" {
		if api.Normalize(want) != fp {
			return nil, "", fmt.Errorf("%s", T("Panelin sertifikasının parmak izi beklenen değil.\n  Beklenen: %s\n  Gelen:    %s\nBağlanılmadı.", api.Format(want), api.Format(fp)))
		}
		return u, fp, nil
	}
	cert := chain[0]
	c.note("%s", T("Bu panelin sertifikasını bilgisayarınız tanımıyor; büyük olasılıkla panelin Let's Encrypt'ten önceki kendi sertifikası."))
	c.note("  %s %s", c.dim(T("Ad:")), cert.Subject.CommonName)
	c.note("  %s %s", c.dim(T("Geçerlilik:")), cert.NotAfter.Local().Format("2006-01-02"))
	c.note("  %s %s", c.dim("SHA-256:"), api.Format(fp))
	c.note("%s", T("Sunucuda servik fingerprint ile aynı değeri görmelisiniz."))
	if !c.env.Interactive {
		return nil, "", usageErr(T("Doğruladıktan sonra parmak izini verin: --fingerprint %s", fp))
	}
	if err := c.confirm(T("Bu sertifikaya güvenilsin mi?")); err != nil {
		return nil, "", err
	}
	return u, fp, nil
}

// approveInBrowser is the browser sign-in: the panel shows the code, the
// person approves it, the key is collected.
func (c *Ctx) approveInBrowser(u *url.URL, fp string) (string, error) {
	client, err := api.New(api.Options{URL: u.String(), Fingerprint: fp, Lang: Locale()})
	if err != nil {
		return "", err
	}
	resp, err := client.Do(c, http.MethodPost, "/cli/login", nil, map[string]string{"client": c.clientName()})
	if err != nil {
		return "", err
	}
	var start struct {
		Code      string `json:"code"`
		Device    string `json:"device"`
		URL       string `json:"url"`
		Interval  int    `json:"interval"`
		ExpiresIn int    `json:"expires_in"`
	}
	if err := json.Unmarshal(resp.Body, &start); err != nil {
		return "", err
	}
	c.note("%s\n\n  %s\n\n  %s %s\n", T("Tarayıcıda şu sayfayı açıp kodu onaylayın:"), c.bold(start.URL), c.dim(T("Kod:")), c.bold(start.Code))
	if !c.Bool("no-browser") && c.env.OpenBrowser != nil {
		_ = c.env.OpenBrowser(start.URL)
	}
	c.note("%s", c.dim(T("Onay bekleniyor… (vazgeçmek için Ctrl+C)")))
	interval := time.Duration(max(start.Interval, 1)) * c.env.Interval
	for {
		if err := c.sleep(interval); err != nil {
			return "", err
		}
		resp, err := client.Do(c, http.MethodPost, "/cli/login/token", nil, map[string]string{"device": start.Device})
		var apiErr *api.Error
		if errors.As(err, &apiErr) && apiErr.Status == http.StatusTooManyRequests {
			interval += c.env.Interval
			continue
		}
		if err != nil {
			return "", err
		}
		if resp.Status == http.StatusAccepted {
			continue
		}
		var got struct {
			Key string `json:"key"`
		}
		if err := json.Unmarshal(resp.Body, &got); err != nil || got.Key == "" {
			return "", fmt.Errorf("%s", T("Panelin yanıtı anlaşılamadı."))
		}
		return got.Key, nil
	}
}

// signIn checks the key with /me and keeps it as a profile.
func (c *Ctx) signIn(p *config.Profile) error {
	client, err := api.New(api.Options{URL: p.URL, Token: p.Token, Fingerprint: p.Fingerprint, Connect: p.Connect, Lang: Locale()})
	if err != nil {
		return err
	}
	c.client = client
	who, err := c.me()
	if err != nil {
		return err
	}
	p.Kind, p.Name, p.Email, p.Account, p.App = who.Kind, who.Name, who.Email, who.Account, who.App
	cfg, err := c.config()
	if err != nil {
		return err
	}
	name := c.String("profile")
	if name == "" {
		name = client.URL().Hostname()
		if who.Kind == "token" && who.App != "" {
			name = who.App
		}
	}
	cfg.Put(name, p)
	if err := cfg.Save(); err != nil {
		return err
	}
	if c.json {
		return c.printJSON(mustJSON(map[string]any{"profile": name, "url": p.URL, "kind": who.Kind, "name": who.Name, "email": who.Email, "account": who.Account, "app": who.App}))
	}
	host := client.URL().Host
	if who.Kind == "token" {
		c.println(c.green("✓") + " " + T("%s paneline %s uygulamasının belirteciyle bağlanıldı (%s).", host, who.App, who.RoleName))
	} else {
		c.println(c.green("✓") + " " + T("%s paneline %s olarak giriş yapıldı (%s).", host, who.Name, who.RoleName))
	}
	if len(cfg.Profiles) > 1 {
		c.println(c.dim(T("Profil: %s · diğerleri için servik profile list", name)))
	}
	return nil
}

func mustJSON(v any) json.RawMessage {
	data, _ := json.Marshal(v)
	return data
}

// loginLocal signs root in on the server: the panel makes the key itself
// (servik:cli-key), and the panel is reached on the loopback address.
func loginLocal(c *Ctx) error {
	if c.env.Geteuid() != 0 {
		return fmt.Errorf("%s", T("servik login --local sunucunun kendisinde root olarak çalışır: sudo servik login --local"))
	}
	raw, err := c.env.ReadFile(panelConfig)
	if err != nil {
		return fmt.Errorf("%s", T("%s okunamadı; bu sunucuda Servik kurulu değil gibi görünüyor.", panelConfig))
	}
	var panel struct {
		PHP string `json:"php"`
	}
	if err := json.Unmarshal(raw, &panel); err != nil || panel.PHP == "" {
		return fmt.Errorf("%s", T("%s anlaşılamadı.", panelConfig))
	}
	host, _ := c.env.Hostname()
	args := []string{"-u", "servik", "--", "env", "-C", panelDir, "php" + panel.PHP, "artisan", "servik:cli-key", "--client", host + " (root)"}
	if e := c.String("email"); e != "" {
		args = append(args, "--email", e)
	}
	if c.Bool("read-only") {
		args = append(args, "--read-only")
	}
	out, err := c.env.Command(c, "runuser", args...)
	if err != nil {
		return fmt.Errorf("%s", T("Panel anahtar oluşturamadı: %s", err))
	}
	var key struct {
		Key     string `json:"key"`
		URL     string `json:"url"`
		Connect string `json:"connect"`
	}
	if err := json.Unmarshal(lastLine(out), &key); err != nil || key.Key == "" {
		return fmt.Errorf("%s", T("Panelin yanıtı anlaşılamadı."))
	}
	u, err := api.NormalizeURL(key.URL)
	if err != nil {
		return err
	}
	// This is the server itself: whatever certificate the panel shows on
	// the loopback address is its own.
	chain, err := api.Certificate(c, u, key.Connect)
	if err != nil {
		return fmt.Errorf("%s", T("%s adresine bağlanılamadı: %s", key.Connect, err))
	}
	fp := ""
	if api.Verify(chain, u.Hostname()) != nil {
		fp = api.Fingerprint(chain[0])
	}
	return c.signIn(&config.Profile{URL: u.String(), Token: key.Key, Fingerprint: fp, Connect: key.Connect})
}

func lastLine(out []byte) []byte {
	lines := strings.Split(strings.TrimSpace(string(out)), "\n")
	return []byte(lines[len(lines)-1])
}

func logout(c *Ctx) error {
	cfg, err := c.config()
	if err != nil {
		return err
	}
	name, p := cfg.Get(c.first("profile", "SERVIK_PROFILE"))
	if p == nil {
		return fmt.Errorf("%s", T("Çıkış yapılacak bir profil yok."))
	}
	closed := false
	if client, err := api.New(api.Options{URL: p.URL, Token: p.Token, Fingerprint: p.Fingerprint, Connect: p.Connect, Lang: Locale(), Timeout: 15 * time.Second}); err == nil {
		if resp, err := client.Do(c, http.MethodDelete, "/me", nil, nil); err == nil {
			closed = resp.Status == http.StatusNoContent
		}
	}
	cfg.Remove(name)
	if err := cfg.Save(); err != nil {
		return err
	}
	if closed {
		return c.done(nil, T("%s profilinden çıkıldı; anahtar panelde kapatıldı.", name))
	}
	return c.done(nil, T("%s profilinden çıkıldı.", name))
}

func profileList(c *Ctx) error {
	cfg, err := c.config()
	if err != nil {
		return err
	}
	current, _ := cfg.Get("")
	if c.json {
		var out []map[string]any
		for _, n := range cfg.Names() {
			p := cfg.Profiles[n]
			out = append(out, map[string]any{"name": n, "current": n == current, "url": p.URL, "kind": p.Kind, "account": p.Account, "user": p.Name, "email": p.Email, "app": p.App})
		}
		if out == nil {
			out = []map[string]any{}
		}
		return c.printJSON(mustJSON(out))
	}
	if len(cfg.Profiles) == 0 {
		c.empty(T("Henüz giriş yapılmadı. Önce: servik login <panel-adresi>"))
		return nil
	}
	var rows [][]string
	for _, n := range cfg.Names() {
		p := cfg.Profiles[n]
		mark := " "
		if n == current {
			mark = c.green("*")
		}
		who := p.Name
		if p.Email != "" {
			who += " <" + p.Email + ">"
		}
		if p.Kind == "token" {
			who = T("%s belirteci", p.App)
		}
		rows = append(rows, []string{mark + " " + n, p.URL, who})
	}
	c.table([]string{T("Profil"), T("Panel"), T("Hesap")}, rows)
	return nil
}

func profileUse(c *Ctx) error {
	cfg, err := c.config()
	if err != nil {
		return err
	}
	if cfg.Profiles[c.Args[0]] == nil {
		return fmt.Errorf("%s", T("%s adında bir profil yok; profiller: servik profile list", c.Args[0]))
	}
	cfg.Current = c.Args[0]
	if err := cfg.Save(); err != nil {
		return err
	}
	return c.done(nil, T("Varsayılan profil artık %s.", c.Args[0]))
}

func completeProfiles(c *Ctx, n int) []string {
	cfg, err := c.config()
	if err != nil || n > 0 {
		return nil
	}
	return cfg.Names()
}

func whoami(c *Ctx) error {
	who, err := c.me()
	if err != nil {
		return err
	}
	if c.json {
		resp, _ := c.client.Get(c, "/me", nil)
		return c.printJSON(resp.Data())
	}
	rows := [][2]string{}
	if who.Kind == "token" {
		rows = append(rows, [2]string{T("Belirteç"), who.Name}, [2]string{T("Uygulama"), who.App}, [2]string{T("Yetki"), who.RoleName})
	} else {
		person := who.Name
		if who.Email != "" {
			person += " <" + who.Email + ">"
		}
		rows = append(rows, [2]string{T("Hesap"), person}, [2]string{T("Rol"), who.RoleName}, [2]string{T("Müşteri"), who.Customer})
		if who.Key != nil {
			scope := T("okuma ve yazma")
			if who.Key.Scope == "read" {
				scope = T("yalnızca okuma")
			}
			rows = append(rows, [2]string{T("Anahtar"), who.Key.Name + " · " + scope}, [2]string{T("Son geçerlilik"), when(who.Key.ExpiresAt)})
		}
	}
	server := c.client.URL().Host
	if who.Server.Version != "" {
		server += " · Servik " + who.Server.Version
	}
	rows = append(rows, [2]string{T("Panel"), server}, [2]string{T("Profil"), c.profileName})
	c.pairs(rows)
	return nil
}

func status(c *Ctx) error {
	who, err := c.me()
	if err != nil {
		return err
	}
	if who.Account != "admin" {
		return whoami(c)
	}
	resp, err := c.client.Get(c, "/server", nil)
	if err != nil {
		return err
	}
	return show(c, resp, func(s struct {
		Hostname string `json:"hostname"`
		Panel    string `json:"panel_hostname"`
		Port     int    `json:"panel_port"`
		Version  string `json:"version"`
		Timezone string `json:"timezone"`
		Sites    int    `json:"sites"`
		Clients  int    `json:"customers"`
		Problems []struct {
			Kind   string `json:"kind"`
			Title  string `json:"title"`
			Detail string `json:"detail"`
		} `json:"problems"`
	}) error {
		c.pairs([][2]string{
			{T("Sunucu"), s.Hostname},
			{T("Panel"), panelAddress(s.Panel, s.Port)},
			{T("Sürüm"), "Servik " + s.Version},
			{T("Saat dilimi"), s.Timezone},
			{T("Siteler"), fmt.Sprint(s.Sites)},
			{T("Müşteriler"), fmt.Sprint(s.Clients)},
		})
		c.println()
		if len(s.Problems) == 0 {
			c.println(c.green("✓") + " " + T("Dikkat isteyen bir sorun yok."))
			return nil
		}
		for _, p := range s.Problems {
			mark := c.yellow("!")
			if p.Kind == "danger" {
				mark = c.red("✗")
			}
			c.println(mark + " " + p.Title)
			if p.Detail != "" {
				c.println("  " + c.dim(p.Detail))
			}
		}
		return nil
	})
}

func fingerprint(c *Ctx) error {
	var u *url.URL
	connect := ""
	switch {
	case len(c.Args) > 0:
		var err error
		if u, err = api.NormalizeURL(c.Args[0]); err != nil {
			return usageErr(T("%s bir panel adresi değil.", c.Args[0]))
		}
	default:
		// On the server: the panel on the loopback address, by its name.
		host := "localhost"
		if raw, err := c.env.ReadFile(panelConfig); err == nil {
			var panel struct {
				Hostname string `json:"hostname"`
			}
			if json.Unmarshal(raw, &panel) == nil && panel.Hostname != "" {
				host = panel.Hostname
			}
		}
		u = &url.URL{Scheme: "https", Host: net.JoinHostPort(host, defaultPort)}
		connect = net.JoinHostPort("127.0.0.1", defaultPort)
	}
	chain, err := api.Certificate(c, u, connect)
	if err != nil {
		return fmt.Errorf("%s", T("%s adresine bağlanılamadı: %s", api.HostPort(u), err))
	}
	fp := api.Fingerprint(chain[0])
	if c.json {
		return c.printJSON(mustJSON(map[string]any{"host": u.Host, "sha256": fp, "subject": chain[0].Subject.CommonName, "not_after": chain[0].NotAfter.Format(time.RFC3339), "trusted": api.Verify(chain, u.Hostname()) == nil}))
	}
	c.println(api.Format(fp))
	return nil
}

func versionCommand() *Command {
	return &Command{
		Name: "version", Group: T("Diğer"), Short: T("Sürümü yazar"),
		Run: func(c *Ctx) error {
			if c.json {
				return c.printJSON(mustJSON(map[string]string{"version": version.Version, "os": runtime.GOOS, "arch": runtime.GOARCH}))
			}
			c.printf("servik %s (%s/%s)\n", version.Version, runtime.GOOS, runtime.GOARCH)
			return nil
		},
	}
}

// sleep waits d, or stops on Ctrl+C.
func (c *Ctx) sleep(d time.Duration) error {
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-c.Done():
		return c.Err()
	case <-t.C:
		return nil
	}
}

// panelAddress is host:port, or "—" before the panel's address is set.
func panelAddress(host string, port int) string {
	if strings.TrimSpace(host) == "" {
		return "—"
	}
	return fmt.Sprintf("%s:%d", host, port)
}
