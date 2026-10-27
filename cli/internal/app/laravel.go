package app

import (
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/url"
	"os"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/calltech-app/serfex/cli/internal/api"
	. "github.com/calltech-app/serfex/cli/internal/i18n"
)

func appFlag(f *Flags) {
	f.String("app", "a", T("alan-adı"), T("Laravel uygulaması (SERVIK_APP; bir belirteçle girişte gerekmez)"))
}

// app is the application a Laravel command works on: -a, SERVIK_APP, the
// token's own application, or the only one the person can reach.
func (c *Ctx) app() (string, error) {
	if a := c.first("app", "SERVIK_APP"); a != "" {
		return strings.ToLower(a), nil
	}
	if _, err := c.Client(); err != nil {
		return "", err
	}
	if c.profile != nil && c.profile.App != "" && c.first("token", "SERVIK_TOKEN") == "" {
		return c.profile.App, nil
	}
	who, err := c.me()
	if err != nil {
		return "", err
	}
	if who.Kind == "token" {
		return who.App, nil
	}
	resp, err := c.get("/apps", nil)
	if err != nil {
		return "", err
	}
	var apps []struct {
		Domain string `json:"domain"`
	}
	_ = resp.Decode(&apps)
	switch len(apps) {
	case 0:
		return "", fmt.Errorf("%s", T("Erişebildiğiniz bir Laravel uygulaması yok."))
	case 1:
		return apps[0].Domain, nil
	}
	var names []string
	for _, a := range apps {
		names = append(names, a.Domain)
	}
	return "", usageErr(T("Hangi uygulama? -a ile birini seçin: %s", strings.Join(names, ", ")))
}

func (c *Ctx) appPath(rest string) (string, error) {
	a, err := c.app()
	if err != nil {
		return "", err
	}
	return "/apps/" + esc(a) + rest, nil
}

func completeApps(c *Ctx) []string {
	resp, err := c.get("/apps", nil)
	if err != nil {
		return nil
	}
	var apps []struct {
		Domain string `json:"domain"`
	}
	_ = resp.Decode(&apps)
	var out []string
	for _, a := range apps {
		out = append(out, a.Domain)
	}
	return out
}

func laravelCommands() []*Command {
	group := "Laravel"
	waitFlags := func(f *Flags) {
		f.Bool("wait", "", T("Bitene kadar çıktısını gösterir; başarısız olursa sıfırdan farklı kodla çıkar"))
		f.String("timeout", "", T("süre"), T("--wait için en uzun bekleme (varsayılan: 60m)"))
	}
	tool := func(name, short string) *Command {
		return &Command{
			Name: name, Group: group, Args: T("<komut>…"), MaxArgs: -1, Passthrough: true,
			Short: short,
			Long:  T("%s komutunu uygulamanın klasöründe, sitenin kullanıcısıyla çalıştırır ve çıktısını gösterir; çıkış kodu komutunki olur. servik seçenekleri (-a) komuttan önce gelir.", name),
			Flags: appFlag,
			Run:   func(c *Ctx) error { return runTool(c, name) },
		}
	}
	return []*Command{
		{Name: "apps", Group: group, Short: T("Erişebildiğiniz Laravel uygulamalarını listeler"), Run: appsList},
		{
			Name: "deploy", Group: group,
			Short: T("Uygulamayı dağıtır"),
			Long:  T("Uygulamayı deposundan dağıtır: yeni sürüm releases/ altına kurulur, sağlık kontrolünden geçince yayına girer, geçemezse önceki sürüm yayında kalır."),
			Flags: func(f *Flags) {
				appFlag(f)
				f.String("ref", "", T("dal|etiket|commit"), T("Dağıtılacak dal, etiket ya da commit (varsayılan: uygulamanın dalı)"))
				f.Bool("no-migrate", "", T("Bu dağıtımda migrate çalıştırmaz"))
				f.Bool("no-frontend", "", T("Bu dağıtımda ön yüzü (npm run build) derlemez"))
				waitFlags(f)
			},
			Examples: []string{
				"servik deploy -a api.ornek.com.tr --wait",
				"SERVIK_URL=https://panel.ornek.com.tr:8443 SERVIK_TOKEN=svk_… servik deploy --ref \"$GITHUB_SHA\" --wait",
			},
			Run: deploy,
		},
		{
			Name: "deployments", Group: group, Short: T("Son dağıtımları listeler"),
			Flags: func(f *Flags) {
				appFlag(f)
				f.String("limit", "n", T("sayı"), T("Kaç dağıtım (varsayılan: 10)"))
			},
			Run: deploymentsList,
		},
		{
			Name: "rollback", Group: group, Short: T("Önceki sürüme döner"),
			Flags: func(f *Flags) {
				appFlag(f)
				f.String("to", "", T("no"), T("Dönülecek dağıtımın numarası (varsayılan: bir önceki)"))
				waitFlags(f)
			},
			Run: rollback,
		},
		{
			Name: "preview", Aliases: []string{"previews"}, Group: group, Short: T("Önizleme ortamları (<dal>.alan-adı)"),
			Subs: []*Command{
				{Name: "create", Args: T("<dal>"), MinArgs: 1, MaxArgs: 1, Short: T("Bir dal için önizleme ortamı açar"), Flags: appFlag, Run: previewCreate},
				{Name: "delete", Aliases: []string{"rm"}, Args: T("<ad>"), MinArgs: 1, MaxArgs: 1, Short: T("Önizleme ortamını kaldırır"), Flags: appFlag, Run: previewDelete},
			},
		},
		{
			Name: "env", Group: group, Short: T("Uygulamanın .env dosyası"),
			Long: T("Uygulamanın .env dosyasını gösterir ve değiştirir. Kaydedilince config:cache yenilenir, işçiler yeniden başlar; panel her sürümü saklar."),
			Flags: func(f *Flags) {
				appFlag(f)
				f.Bool("reveal", "", T("Parola ve anahtarları gizlemeden gösterir"))
			},
			Run: envList,
			Subs: []*Command{
				{Name: "list", Aliases: []string{"ls"}, Short: T("Anahtarları ve değerlerini listeler (gizliler maskeli)"), Flags: func(f *Flags) { appFlag(f); f.Bool("reveal", "", T("Parola ve anahtarları gizlemeden gösterir")) }, Run: envList},
				{Name: "get", Args: T("<ANAHTAR>"), MinArgs: 1, MaxArgs: 1, Short: T("Bir değeri olduğu gibi yazar"), Flags: appFlag, Run: envGet},
				{Name: "set", Args: T("<ANAHTAR=değer>…"), MinArgs: 1, MaxArgs: -1, Short: T("Değerleri değiştirir ya da ekler"), Flags: appFlag, Examples: []string{"servik env set APP_DEBUG=false MAIL_FROM_NAME=\"Kargo\""}, Run: envSet},
				{Name: "unset", Args: T("<ANAHTAR>…"), MinArgs: 1, MaxArgs: -1, Short: T("Anahtarları siler"), Flags: appFlag, Run: envUnset},
				{
					Name: "pull", Short: T(".env dosyasını indirir"),
					Flags: func(f *Flags) {
						appFlag(f)
						f.String("output", "o", T("dosya"), T("Yazılacak dosya (varsayılan: standart çıktı)"))
					},
					Run: envPull,
				},
				{Name: "push", Args: T("<dosya>"), MinArgs: 1, MaxArgs: 1, Short: T(".env dosyasının tamamını değiştirir"), Flags: appFlag, Run: envPush},
			},
		},
		tool("artisan", T("Artisan komutu çalıştırır")),
		tool("composer", T("Composer komutu çalıştırır")),
		tool("npm", T("npm komutu çalıştırır")),
		{
			Name: "queues", Aliases: []string{"queue"}, Group: group, Short: T("Kuyruk işçileri ve bekleyen işler"),
			Flags: appFlag,
			Run:   queuesStatus,
			Subs: []*Command{
				{Name: "status", Short: T("İşçileri ve bekleyen işleri gösterir"), Flags: appFlag, Run: queuesStatus},
				{Name: "failed", Short: T("Başarısız işleri listeler"), Flags: appFlag, Run: queuesFailed},
				{Name: "retry", Args: T("[iş-no]"), MaxArgs: 1, Short: T("Başarısız bir işi ya da hepsini yeniden kuyruğa alır"), Flags: appFlag, Run: queuesRetry},
				{Name: "restart", Short: T("İşçileri yeniden başlatır"), Flags: appFlag, Run: queuesRestart},
			},
		},
		{
			Name: "maintenance", Aliases: []string{"down"}, Group: group, Short: T("Bakım modu"),
			Flags: appFlag,
			Run:   maintenanceStatus,
			Subs: []*Command{
				{Name: "status", Short: T("Bakım modunda olup olmadığını gösterir"), Flags: appFlag, Run: maintenanceStatus},
				{
					Name: "on", Short: T("Bakım modunu açar"),
					Flags: func(f *Flags) {
						appFlag(f)
						f.String("mode", "", "servik|custom|app", T("Servik sayfası (varsayılan), kendi HTML'iniz ya da php artisan down"))
						f.Strings("allow", "", "ip", T("Bakımdayken siteyi görebilecek IP ya da ağ (birden çok kez)"))
						f.String("secret", "", T("yol"), T("Bakımı atlatan gizli yol (varsayılan: rastgele)"))
						f.String("retry", "", T("saniye"), T("Retry-After başlığı"))
						f.String("html-file", "", T("dosya"), T("--mode custom için HTML dosyası"))
					},
					Run: maintenanceOn,
				},
				{Name: "off", Short: T("Bakım modunu kapatır"), Flags: appFlag, Run: maintenanceOff},
			},
		},
		{
			Name: "logs", Group: group, Short: T("Uygulamanın günlükleri (storage/logs)"),
			Flags: func(f *Flags) {
				appFlag(f)
				f.String("level", "", T("düzey"), T("Bu düzey ve üstü: debug, info, warning, error, critical"))
				f.String("file", "", T("dosya"), T("Günlük dosyası (varsayılan: en yenisi)"))
				f.String("search", "", T("metin"), T("İçinde bu metin geçenler"))
				f.String("lines", "n", T("sayı"), T("Kaç kayıt (varsayılan: 50)"))
				f.Bool("follow", "f", T("Yeni kayıtları geldikçe yazar"))
				f.Bool("detail", "", T("Hata ayrıntılarını (stack trace) da yazar"))
			},
			Examples: []string{"servik logs -a api.ornek.com.tr --level error -n 20", "servik logs -f"},
			Run:      logs,
		},
	}
}

func appsList(c *Ctx) error {
	resp, err := c.get("/apps", nil)
	if err != nil {
		return err
	}
	return show(c, resp, func(list []struct {
		Domain     string `json:"domain"`
		Status     string `json:"status"`
		Role       string `json:"role"`
		PreviewOf  string `json:"preview_of"`
		Repository string `json:"repository"`
		Branch     string `json:"branch"`
		Release    string `json:"release"`
		Last       *struct {
			Number     int    `json:"number"`
			Status     string `json:"status"`
			FinishedAt string `json:"finished_at"`
		} `json:"last_deploy"`
	}) error {
		if len(list) == 0 {
			c.empty(T("Erişebildiğiniz bir Laravel uygulaması yok."))
			return nil
		}
		var rows [][]string
		for _, a := range list {
			last := "—"
			if a.Last != nil {
				last = fmt.Sprintf("#%d %s %s", a.Last.Number, c.status(a.Last.Status), when(a.Last.FinishedAt))
			}
			name := a.Domain
			if a.PreviewOf != "" {
				name += " " + c.dim(T("(önizleme)"))
			}
			rows = append(rows, []string{name, c.status(a.Status), orDash(a.Repository), orDash(a.Branch), last})
		}
		c.table([]string{T("Uygulama"), T("Durum"), T("Depo"), T("Dal"), T("Son dağıtım")}, rows)
		return nil
	})
}

type deployment struct {
	Number      int    `json:"deployment"`
	Kind        string `json:"kind"`
	Status      string `json:"status"`
	Ref         string `json:"ref"`
	Commit      string `json:"commit"`
	Message     string `json:"message"`
	Error       string `json:"error"`
	StartedAt   string `json:"started_at"`
	FinishedAt  string `json:"finished_at"`
	TriggeredBy string `json:"triggered_by"`
	Run         string `json:"run"`
	Live        bool   `json:"live"`
	Done        bool   `json:"done"`
	URL         string `json:"url"`
}

func deploy(c *Ctx) error {
	path, err := c.appPath("")
	if err != nil {
		return err
	}
	body := map[string]any{}
	if r := c.String("ref"); r != "" {
		body["ref"] = r
	}
	if c.Bool("no-migrate") {
		body["migrate"] = false
	}
	if c.Bool("no-frontend") {
		body["frontend"] = false
	}
	resp, err := c.send(http.MethodPost, path+"/deploy", body)
	if err != nil {
		return err
	}
	return c.followDeployment(resp, path)
}

func (c *Ctx) followDeployment(resp *api.Response, path string) error {
	var d deployment
	_ = resp.Decode(&d)
	if !c.Bool("wait") {
		if c.json {
			return c.printJSON(resp.Body)
		}
		return c.done(nil, T("Dağıtım %d sıraya alındı. İzlemek için: servik deployments", d.Number))
	}
	if !c.json {
		c.note("%s", c.dim(T("Dağıtım %d başladı (iptal edilmez, yalnızca izlemeyi bırakmak için Ctrl+C).", d.Number)))
	}
	if err := c.followRun(path, d.Run, func() (bool, error) {
		r, err := c.get(path+"/deployments/"+strconv.Itoa(d.Number), nil)
		if err != nil {
			return false, err
		}
		resp = r
		_ = r.Decode(&d)
		return d.Done, nil
	}); err != nil {
		return err
	}
	if c.json {
		_ = c.printJSON(resp.Body)
	}
	switch d.Status {
	case "succeeded":
		if !c.json {
			commit := d.Commit
			if len(commit) > 7 {
				commit = commit[:7]
			}
			c.println(c.green("✓") + " " + T("Dağıtım %d yayında%s.", d.Number, map[bool]string{true: " · " + commit, false: ""}[commit != ""]))
		}
		return nil
	case "unhealthy":
		c.errorf("%s", T("Dağıtım %d yayında ama sağlık kontrolünden geçmedi: %s", d.Number, orDash(d.Error)))
	case "rolled_back":
		c.errorf("%s", T("Dağıtım %d sağlık kontrolünden geçmedi; önceki sürüm yayında: %s", d.Number, orDash(d.Error)))
	default:
		c.errorf("%s", T("Dağıtım %d başarısız: %s", d.Number, orDash(d.Error)))
	}
	return exitCode(1)
}

// followRun writes a deploy's or command's output as it comes, until
// finished says it ended. The output goes to stdout, or to stderr with
// --json so stdout stays one JSON document.
func (c *Ctx) followRun(path, run string, finished func() (bool, error)) error {
	timeout := 60 * time.Minute
	if t := c.String("timeout"); t != "" {
		d, err := time.ParseDuration(t)
		if err != nil {
			return usageErr(T("--timeout bir süre bekliyor, örneğin 30m."))
		}
		timeout = d
	}
	deadline := time.Now().Add(timeout)
	out := c.env.Stdout
	if c.json {
		out = c.env.Stderr
	}
	offset := 0
	// read writes what is new and says whether more is waiting.
	read := func() (bool, error) {
		if run == "" {
			return false, nil
		}
		resp, err := c.get(path+"/runs/"+esc(run), url.Values{"offset": {strconv.Itoa(offset)}})
		var apiErr *api.Error
		if errors.As(err, &apiErr) && apiErr.Status == http.StatusNotFound {
			return false, nil
		}
		if err != nil {
			return false, err
		}
		var chunk struct {
			Data   string `json:"data"`
			Offset int    `json:"offset"`
			More   bool   `json:"more"`
		}
		_ = resp.Decode(&chunk)
		fmt.Fprint(out, chunk.Data)
		if chunk.Offset <= offset {
			return false, nil
		}
		offset = chunk.Offset
		return chunk.More, nil
	}
	for {
		more, err := read()
		if err != nil {
			return err
		}
		if more {
			continue
		}
		ended, err := finished()
		if err != nil {
			return err
		}
		if ended {
			for more, err = read(); more && err == nil; more, err = read() {
			}
			return err
		}
		if time.Now().After(deadline) {
			return fmt.Errorf("%s", T("%s içinde bitmedi; iş sunucuda sürüyor.", timeout))
		}
		if err := c.sleep(2 * c.env.Interval); err != nil {
			c.note("%s", T("İzlemeyi bıraktınız; iş sunucuda sürüyor."))
			return err
		}
	}
}

func deploymentsList(c *Ctx) error {
	path, err := c.appPath("/deployments")
	if err != nil {
		return err
	}
	n, err := c.Int("limit", 10)
	if err != nil {
		return err
	}
	resp, err := c.get(path, url.Values{"limit": {strconv.Itoa(n)}})
	if err != nil {
		return err
	}
	return show(c, resp, func(list []deployment) error {
		if len(list) == 0 {
			c.empty(T("Henüz dağıtım yok."))
			return nil
		}
		var rows [][]string
		for _, d := range list {
			commit := d.Commit
			if len(commit) > 7 {
				commit = commit[:7]
			}
			number := "#" + strconv.Itoa(d.Number)
			if d.Live {
				number += " " + c.green("●")
			}
			rows = append(rows, []string{number, c.status(d.Status), orDash(d.Ref), orDash(commit), when(d.StartedAt), orDash(d.TriggeredBy)})
		}
		c.table([]string{T("No"), T("Durum"), T("Ref"), "COMMIT", T("Başlangıç"), T("Başlatan")}, rows)
		return nil
	})
}

func rollback(c *Ctx) error {
	path, err := c.appPath("")
	if err != nil {
		return err
	}
	body := map[string]any{}
	if c.Has("to") {
		n, err := c.Int("to", 0)
		if err != nil {
			return err
		}
		body["to"] = n
	}
	if err := c.confirm(T("Uygulama önceki sürüme dönsün mü?")); err != nil {
		return err
	}
	resp, err := c.send(http.MethodPost, path+"/rollback", body)
	if err != nil {
		return err
	}
	return c.followDeployment(resp, path)
}

func previewCreate(c *Ctx) error {
	path, err := c.appPath("/previews")
	if err != nil {
		return err
	}
	resp, err := c.send(http.MethodPost, path, map[string]string{"branch": c.Args[0]})
	if err != nil {
		return err
	}
	var p struct {
		URL string `json:"url"`
	}
	_ = json.Unmarshal(resp.Body, &p)
	return c.done(resp, T("Önizleme ortamı hazırlanıyor: %s", p.URL))
}

func previewDelete(c *Ctx) error {
	path, err := c.appPath("/previews/" + esc(c.Args[0]))
	if err != nil {
		return err
	}
	resp, err := c.send(http.MethodDelete, path, nil)
	if err != nil {
		return err
	}
	return c.done(resp, "")
}

// --- .env --------------------------------------------------------------

type envFile struct {
	Exists  bool              `json:"exists"`
	Values  map[string]string `json:"values"`
	Content string            `json:"content"`
	Missing []string          `json:"missing"`
}

func (c *Ctx) env_() (*envFile, *api.Response, error) {
	path, err := c.appPath("/env")
	if err != nil {
		return nil, nil, err
	}
	resp, err := c.get(path, nil)
	if err != nil {
		return nil, nil, err
	}
	var e envFile
	if err := resp.Decode(&e); err != nil {
		return nil, nil, err
	}
	return &e, resp, nil
}

func secret(key string) bool {
	for _, w := range []string{"PASSWORD", "SECRET", "KEY", "TOKEN", "PASS", "PRIVATE", "DSN"} {
		if strings.Contains(key, w) {
			return true
		}
	}
	return false
}

func envList(c *Ctx) error {
	e, resp, err := c.env_()
	if err != nil {
		return err
	}
	if c.json {
		return c.printJSON(resp.Data())
	}
	if !e.Exists {
		c.empty(T("Uygulamanın .env dosyası yok."))
		return nil
	}
	keys := make([]string, 0, len(e.Values))
	for k := range e.Values {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	for _, k := range keys {
		v := e.Values[k]
		if secret(k) && v != "" && !c.Bool("reveal") {
			v = c.dim("••••••")
		}
		c.printf("%s=%s\n", k, v)
	}
	if len(e.Missing) > 0 {
		c.note("%s", c.yellow(T(".env.example içinde olup burada olmayanlar: %s", strings.Join(e.Missing, ", "))))
	}
	return nil
}

func envGet(c *Ctx) error {
	e, _, err := c.env_()
	if err != nil {
		return err
	}
	v, ok := e.Values[strings.ToUpper(c.Args[0])]
	if !ok {
		c.errorf("%s", T("%s .env içinde yok.", strings.ToUpper(c.Args[0])))
		return exitCode(1)
	}
	if c.json {
		return c.printJSON(mustJSON(map[string]string{strings.ToUpper(c.Args[0]): v}))
	}
	c.println(v)
	return nil
}

func (c *Ctx) patchEnv(body map[string]any) error {
	path, err := c.appPath("/env")
	if err != nil {
		return err
	}
	resp, err := c.send(http.MethodPatch, path, body)
	if err != nil {
		return err
	}
	if c.json {
		return c.printJSON(resp.Body)
	}
	var d struct {
		Summary string `json:"summary"`
	}
	_ = resp.Decode(&d)
	msg := resp.Message()
	if d.Summary != "" {
		msg += " " + c.dim("("+d.Summary+")")
	}
	return c.done(nil, msg)
}

func envSet(c *Ctx) error {
	set := map[string]string{}
	for _, a := range c.Args {
		k, v, ok := strings.Cut(a, "=")
		if !ok || k == "" {
			return usageErr(T("%s ANAHTAR=değer biçiminde değil.", a))
		}
		set[strings.ToUpper(k)] = v
	}
	return c.patchEnv(map[string]any{"set": set})
}

func envUnset(c *Ctx) error {
	keys := make([]string, len(c.Args))
	for i, k := range c.Args {
		keys[i] = strings.ToUpper(k)
	}
	return c.patchEnv(map[string]any{"unset": keys})
}

func envPull(c *Ctx) error {
	e, _, err := c.env_()
	if err != nil {
		return err
	}
	if out := c.String("output"); out != "" {
		if err := os.WriteFile(out, []byte(e.Content), 0o600); err != nil {
			return err
		}
		return c.done(nil, T(".env %s dosyasına yazıldı.", out))
	}
	fmt.Fprint(c.env.Stdout, e.Content)
	return nil
}

func envPush(c *Ctx) error {
	content, err := c.env.ReadFile(c.Args[0])
	if err != nil {
		return err
	}
	path, err := c.appPath("/env")
	if err != nil {
		return err
	}
	if err := c.confirm(T("Uygulamanın .env dosyası %s ile değiştirilsin mi?", c.Args[0])); err != nil {
		return err
	}
	resp, err := c.send(http.MethodPut, path, map[string]string{"content": string(content)})
	if err != nil {
		return err
	}
	return c.done(resp, "")
}

// --- Komutlar ----------------------------------------------------------

// shellQuote joins arguments as the panel's command line reads them.
func shellQuote(args []string) string {
	out := make([]string, len(args))
	for i, a := range args {
		if a != "" && !strings.ContainsAny(a, " \t\n'\"\\$`;&|<>()*?[]{}~#!") {
			out[i] = a
			continue
		}
		out[i] = "'" + strings.ReplaceAll(a, "'", `'\''`) + "'"
	}
	return strings.Join(out, " ")
}

func runTool(c *Ctx, tool string) error {
	path, err := c.appPath("")
	if err != nil {
		return err
	}
	args := c.Args
	if len(args) == 0 {
		if tool != "artisan" {
			return usageErr(T("Çalıştırılacak komutu yazın, örneğin: servik %s install", tool))
		}
		args = []string{"list"}
	}
	resp, err := c.send(http.MethodPost, path+"/commands", map[string]string{"tool": tool, "command": shellQuote(args)})
	if err != nil {
		return err
	}
	var cmd struct {
		ID       int    `json:"id"`
		Run      string `json:"run"`
		Status   string `json:"status"`
		ExitCode *int   `json:"exit_code"`
		Error    string `json:"error"`
		Done     bool   `json:"done"`
	}
	_ = resp.Decode(&cmd)
	if err := c.followRun(path, cmd.Run, func() (bool, error) {
		r, err := c.get(path+"/commands/"+strconv.Itoa(cmd.ID), nil)
		if err != nil {
			return false, err
		}
		resp = r
		_ = r.Decode(&cmd)
		return cmd.Done, nil
	}); err != nil {
		return err
	}
	if c.json {
		_ = c.printJSON(resp.Data())
	}
	if cmd.Error != "" {
		c.errorf("%s", cmd.Error)
	}
	if cmd.ExitCode != nil && *cmd.ExitCode != 0 {
		return exitCode(*cmd.ExitCode)
	}
	if cmd.ExitCode == nil && cmd.Status == "failed" {
		return exitCode(1)
	}
	return nil
}

// --- Kuyruklar ---------------------------------------------------------

func queuesStatus(c *Ctx) error {
	path, err := c.appPath("/queues")
	if err != nil {
		return err
	}
	resp, err := c.get(path, nil)
	if err != nil {
		return err
	}
	return show(c, resp, func(q struct {
		Failed  *int `json:"failed"`
		Workers []struct {
			Name      string   `json:"name"`
			Queues    []string `json:"queues"`
			Processes int      `json:"processes"`
			Running   int      `json:"running"`
		} `json:"workers"`
		Pending []struct {
			Connection string `json:"connection"`
			Queue      string `json:"queue"`
			Size       int    `json:"size"`
		} `json:"pending"`
		Error string `json:"error"`
	}) error {
		if len(q.Workers) == 0 {
			c.empty(T("Bu uygulamada kuyruk işçisi yok; panelde Kuyruklar bölümünden ekleyin."))
			return nil
		}
		var rows [][]string
		for _, w := range q.Workers {
			running := fmt.Sprintf("%d/%d", w.Running, w.Processes)
			if w.Running < w.Processes {
				running = c.red(running)
			} else {
				running = c.green(running)
			}
			rows = append(rows, []string{w.Name, strings.Join(w.Queues, ", "), running})
		}
		c.table([]string{T("İşçi"), T("Kuyruklar"), T("Çalışan")}, rows)
		c.println()
		for _, p := range q.Pending {
			c.printf("%s %d\n", c.dim(T("Bekleyen iş (%s):", p.Queue)), p.Size)
		}
		if q.Failed != nil {
			failed := strconv.Itoa(*q.Failed)
			if *q.Failed > 0 {
				failed = c.red(failed) + c.dim("  servik queues failed")
			}
			c.printf("%s %s\n", c.dim(T("Başarısız:")), failed)
		}
		if q.Error != "" {
			c.println(c.red(q.Error))
		}
		return nil
	})
}

func queuesFailed(c *Ctx) error {
	path, err := c.appPath("/queues/failed")
	if err != nil {
		return err
	}
	resp, err := c.get(path, nil)
	if err != nil {
		return err
	}
	return show(c, resp, func(list []struct {
		ID       string `json:"id"`
		Queue    string `json:"queue"`
		Job      string `json:"job"`
		Error    string `json:"error"`
		FailedAt string `json:"failed_at"`
	}) error {
		if len(list) == 0 {
			c.empty(T("Başarısız iş yok."))
			return nil
		}
		var rows [][]string
		for _, j := range list {
			msg := j.Error
			if len(msg) > 80 {
				msg = msg[:77] + "…"
			}
			rows = append(rows, []string{j.ID, j.Queue, j.Job, j.FailedAt, msg})
		}
		c.table([]string{T("No"), T("Kuyruk"), T("İş"), T("Zaman"), T("Hata")}, rows)
		return nil
	})
}

func queuesRetry(c *Ctx) error {
	path, err := c.appPath("/queues/retry")
	if err != nil {
		return err
	}
	body := map[string]string{}
	if len(c.Args) > 0 {
		body["id"] = c.Args[0]
	}
	resp, err := c.send(http.MethodPost, path, body)
	if err != nil {
		return err
	}
	return c.done(resp, "")
}

func queuesRestart(c *Ctx) error {
	path, err := c.appPath("/queues/restart")
	if err != nil {
		return err
	}
	resp, err := c.send(http.MethodPost, path, nil)
	if err != nil {
		return err
	}
	return c.done(resp, "")
}

// --- Bakım modu --------------------------------------------------------

type maintenanceState struct {
	Down   bool     `json:"down"`
	Mode   string   `json:"mode"`
	Allow  []string `json:"allow"`
	Bypass string   `json:"bypass_url"`
}

func (c *Ctx) printMaintenance(m maintenanceState) {
	if !m.Down {
		c.println(c.green("●") + " " + T("Uygulama açık."))
		return
	}
	c.println(c.yellow("●") + " " + T("Uygulama bakım modunda (%s).", m.Mode))
	c.pairs([][2]string{{T("İzinli adresler"), strings.Join(m.Allow, ", ")}, {T("Atlatma bağlantısı"), m.Bypass}})
}

func maintenanceStatus(c *Ctx) error {
	path, err := c.appPath("/maintenance")
	if err != nil {
		return err
	}
	resp, err := c.get(path, nil)
	if err != nil {
		return err
	}
	return show(c, resp, func(m maintenanceState) error {
		c.printMaintenance(m)
		return nil
	})
}

func maintenanceOn(c *Ctx) error {
	path, err := c.appPath("/maintenance")
	if err != nil {
		return err
	}
	body := map[string]any{"down": true}
	if m := c.String("mode"); m != "" {
		body["mode"] = m
	}
	if a := c.Strings("allow"); len(a) > 0 {
		body["allow"] = a
	}
	if s := c.String("secret"); s != "" {
		body["secret"] = s
	}
	if c.Has("retry") {
		n, err := c.Int("retry", 0)
		if err != nil {
			return err
		}
		body["retry"] = n
	}
	if f := c.String("html-file"); f != "" {
		html, err := c.env.ReadFile(f)
		if err != nil {
			return err
		}
		body["html"] = string(html)
		if _, ok := body["mode"]; !ok {
			body["mode"] = "custom"
		}
	}
	resp, err := c.send(http.MethodPost, path, body)
	if err != nil {
		return err
	}
	if c.json {
		return c.printJSON(resp.Data())
	}
	var m maintenanceState
	_ = resp.Decode(&m)
	c.printMaintenance(m)
	return nil
}

func maintenanceOff(c *Ctx) error {
	path, err := c.appPath("/maintenance")
	if err != nil {
		return err
	}
	resp, err := c.send(http.MethodPost, path, map[string]bool{"down": false})
	if err != nil {
		return err
	}
	return c.done(resp, "")
}

// --- Günlükler ---------------------------------------------------------

type logEntry struct {
	At          string `json:"at"`
	Environment string `json:"environment"`
	Level       string `json:"level"`
	Message     string `json:"message"`
	Detail      string `json:"detail"`
}

func logs(c *Ctx) error {
	path, err := c.appPath("/logs")
	if err != nil {
		return err
	}
	n, err := c.Int("lines", 50)
	if err != nil {
		return err
	}
	q := url.Values{"limit": {strconv.Itoa(n)}}
	for _, k := range []string{"level", "file", "search"} {
		if v := c.String(k); v != "" {
			q.Set(k, v)
		}
	}
	seen := map[string]bool{}
	first := true
	for {
		resp, err := c.get(path, q)
		if err != nil {
			return err
		}
		var l struct {
			File    string     `json:"file"`
			Entries []logEntry `json:"entries"`
		}
		if err := resp.Decode(&l); err != nil {
			return err
		}
		if c.json && !c.Bool("follow") {
			return c.printJSON(resp.Data())
		}
		if first && len(l.Entries) == 0 && !c.Bool("follow") {
			c.empty(T("Günlükte kayıt yok."))
			return nil
		}
		// Newest first from the panel; oldest first on the screen.
		for i := len(l.Entries) - 1; i >= 0; i-- {
			e := l.Entries[i]
			id := e.At + "\x00" + e.Level + "\x00" + e.Message
			if seen[id] {
				continue
			}
			seen[id] = true
			if c.json {
				data, _ := json.Marshal(e)
				c.println(string(data))
				continue
			}
			c.printf("%s %s %s\n", c.dim(e.At), c.logLevel(e.Level), e.Message)
			if c.Bool("detail") && e.Detail != "" {
				c.println(c.dim(e.Detail))
			}
		}
		first = false
		if !c.Bool("follow") {
			return nil
		}
		if err := c.sleep(3 * c.env.Interval); err != nil {
			return nil
		}
	}
}

func (c *Ctx) logLevel(level string) string {
	text := strings.ToUpper(level)
	switch level {
	case "emergency", "alert", "critical", "error":
		return c.red(text)
	case "warning":
		return c.yellow(text)
	}
	return c.dim(text)
}
