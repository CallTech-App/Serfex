package laravel

import (
	"context"
	"crypto/rand"
	"errors"
	"fmt"
	"net/mail"
	"net/url"
	"os"
	"regexp"
	"strings"
	"time"

	"github.com/calltech-app/serfex/agent/internal/i18n"
	"github.com/calltech-app/serfex/agent/internal/site"
)

// WPInstallSpec installs WordPress into an empty folder of a site, its
// web root. The database and its user exist already (the panel creates
// them); the passwords reach WP-CLI on its standard input only.
type WPInstallSpec struct {
	App
	Run string `json:"run"`
	// URL is the site's address, https://example.com.
	URL           string `json:"url"`
	Title         string `json:"title"`
	AdminUser     string `json:"admin_user"`
	AdminEmail    string `json:"admin_email"`
	AdminPassword string `json:"admin_password"`
	Locale        string `json:"locale"`
	// Version pins the WordPress release; empty installs the newest.
	Version string     `json:"version"`
	DB      WPDatabase `json:"db"`
	// Harden applies WPRecommended after the install.
	Harden bool `json:"harden"`
	// Apache is true when Apache serves the site: WordPress then gets the
	// .htaccess its permalinks need.
	Apache bool `json:"apache"`
}

// WPDatabase is the MySQL database WordPress uses.
type WPDatabase struct {
	Name     string `json:"name"`
	User     string `json:"user"`
	Password string `json:"password"`
	Host     string `json:"host"`
}

// WPInstallResult describes a finished install.
type WPInstallResult struct {
	Version string `json:"version"`
	Millis  int64  `json:"ms"`
}

func (s WPInstallSpec) validate() error {
	if err := s.App.validate(); err != nil {
		return err
	}
	if err := validateID("run", s.Run); err != nil {
		return err
	}
	u, err := url.Parse(s.URL)
	if err != nil || (u.Scheme != "http" && u.Scheme != "https") || u.User != nil || u.RawQuery != "" || u.Fragment != "" ||
		(u.Path != "" && u.Path != "/") || site.ValidateDomain(u.Hostname()) != nil || u.Port() != "" {
		return invalid("url %q must look like https://example.com", s.URL)
	}
	if t := strings.TrimSpace(s.Title); t == "" || len(t) > 200 || strings.ContainsAny(t, "\r\n") {
		return invalid("title must be one line of at most 200 bytes")
	}
	if !wpLoginRe.MatchString(s.AdminUser) {
		return invalid("admin_user %q must be 1-60 letters, digits, spaces or _.@-", s.AdminUser)
	}
	if a, err := mail.ParseAddress(s.AdminEmail); err != nil || a.Address != s.AdminEmail || len(s.AdminEmail) > 100 {
		return invalid("admin_email %q is not an e-mail address", s.AdminEmail)
	}
	if len(s.AdminPassword) < 10 || len(s.AdminPassword) > 200 || strings.ContainsAny(s.AdminPassword, "\r\n\x00") {
		return invalid("admin_password must be 10-200 characters on one line")
	}
	if !wpLocaleRe.MatchString(s.Locale) {
		return invalid("locale %q must look like tr_TR or en_US", s.Locale)
	}
	if s.Version != "" && !wpReleaseRe.MatchString(s.Version) {
		return invalid("version %q must look like 6.8.3", s.Version)
	}
	for k, v := range map[string]string{"db.name": s.DB.Name, "db.user": s.DB.User, "db.host": s.DB.Host} {
		if !dbWordRe.MatchString(v) || len(v) > 64 {
			return invalid("%s %q is not valid", k, v)
		}
	}
	if s.DB.Password == "" || len(s.DB.Password) > 200 || strings.ContainsAny(s.DB.Password, "\r\n\x00") {
		return invalid("db.password must be one line")
	}
	return nil
}

var wpReleaseRe = regexp.MustCompile(`^[0-9]+\.[0-9]+(\.[0-9]+)?$`)

// wpRun holds one running WordPress operation.
type wpRun struct {
	*deploy
	app App
}

// wp runs a WP-CLI step with its output in the log.
func (r *wpRun) wp(shown string, stdin string, args ...string) error {
	fmt.Fprintf(r.log, "$ wp %s\n", shown)
	c := r.m.wpCmd(r.app, r.log, false, args...)
	if stdin != "" {
		c.Stdin = []byte(stdin)
	}
	if _, err := r.m.h.Run(r.ctx, c); err != nil {
		if r.ctx.Err() != nil {
			return fmt.Errorf("wp %s stopped: it took longer than %d minutes", shown, int(wpTimeout.Minutes()))
		}
		return fmt.Errorf("wp %s failed with exit code %d", shown, host0(err))
	}
	return nil
}

// InstallWordPress downloads WordPress into the folder, writes
// wp-config.php, creates the administrator and, when asked, applies the
// recommended hardening. The folder must be empty but for Serfex's
// default page; a failed install leaves it as it was.
func (m *Manager) InstallWordPress(ctx context.Context, spec WPInstallSpec) (res WPInstallResult, err error) {
	if err := spec.validate(); err != nil {
		return res, err
	}
	if err := m.requirePHP(spec.PHPVersion); err != nil {
		return res, err
	}
	if !m.isDir(siteDir(spec.Target)) {
		return res, invalid("site %s does not exist", spec.Domain)
	}
	unlock := m.lock(spec.Domain)
	defer unlock()
	log, err := m.openLog(wpRunDir(spec.Domain), spec.Run)
	if err != nil {
		return res, &OperationError{err}
	}
	defer log.Close()
	ctx, cancel := context.WithTimeout(ctx, wpTimeout)
	defer cancel()
	start := time.Now()
	r := &wpRun{deploy: &deploy{m: m, spec: DeploySpec{Target: spec.Target}, log: log, ctx: ctx, base: spec.dir(), dir: spec.dir()}, app: spec.App}
	defer func() {
		if err != nil {
			fmt.Fprintf(log, "✗ %s\n", err)
			var v *ValidationError
			if !errors.As(err, &v) {
				err = &OperationError{err}
			}
		}
	}()

	r.step("WordPress kurulumu başladı · %s → %s", spec.URL, spec.Path)
	placeholder, err := m.wpEmptyFolder(ctx, spec.App)
	if err != nil {
		return res, err
	}
	if err := m.ensureWPCLI(ctx, log); err != nil {
		return res, fmt.Errorf("WP-CLI could not be downloaded: %w", err)
	}
	if err := r.user("mkdir", "-p", spec.dir()); err != nil {
		return res, err
	}
	if placeholder != nil {
		if err := r.user("rm", "-f", spec.dir()+"/index.html"); err != nil {
			return res, err
		}
	}
	// From here on a failure takes the files out again.
	defer func() {
		if err != nil {
			fmt.Fprintln(log, "↺ "+i18n.T(ctx, "Kurulan dosyalar kaldırılıyor"))
			m.wpClear(context.WithoutCancel(ctx), spec.App)
			if placeholder != nil {
				_ = m.writeFile(context.WithoutCancel(ctx), spec.Target, spec.dir()+"/index.html", placeholder, "0644")
			}
		}
	}()

	r.step("WordPress indiriliyor (wordpress.org, %s)", spec.Locale)
	download := []string{"core", "download", "--locale=" + spec.Locale}
	if spec.Version != "" {
		download = append(download, "--version="+spec.Version)
	}
	if err := r.wp(strings.Join(download, " "), "", download...); err != nil {
		return res, err
	}
	r.step("wp-config.php yazılıyor (veritabanı %s)", spec.DB.Name)
	prefix := "wp_" + randomLower(4) + "_"
	if err := r.wp("config create --dbname="+spec.DB.Name+" --dbuser="+spec.DB.User+" --dbhost="+spec.DB.Host+" --dbprefix="+prefix, spec.DB.Password+"\n",
		"config", "create", "--dbname="+spec.DB.Name, "--dbuser="+spec.DB.User, "--dbhost="+spec.DB.Host, "--dbprefix="+prefix,
		"--locale="+spec.Locale, "--prompt=dbpass"); err != nil {
		return res, err
	}
	r.step("WordPress kuruluyor; yönetici: %s", spec.AdminUser)
	if err := r.wp("core install --url="+spec.URL+" --admin_user="+spec.AdminUser+" --skip-email", spec.AdminPassword+"\n",
		"core", "install", "--url="+spec.URL, "--title="+strings.TrimSpace(spec.Title), "--admin_user="+spec.AdminUser,
		"--admin_email="+spec.AdminEmail, "--skip-email", "--prompt=admin_password"); err != nil {
		return res, err
	}
	r.step("Kalıcı bağlantılar açılıyor (/%%postname%%/)")
	if err := r.wp("rewrite structure /%postname%/", "", "rewrite", "structure", "/%postname%/"); err != nil {
		return res, err
	}
	if spec.Apache {
		if err := m.writeFile(ctx, spec.Target, spec.dir()+"/.htaccess", []byte(wpHtaccess), "0644"); err != nil {
			return res, err
		}
	}
	if err := m.writeLoginPlugin(ctx, spec.App); err != nil {
		return res, err
	}
	if spec.Harden {
		r.step("Önerilen güvenlik ayarları uygulanıyor")
		rules := map[string]bool{}
		for _, k := range WPRecommended {
			rules[k] = true
		}
		if err := m.harden(ctx, WPHardenSpec{App: spec.App, WebPath: "/", Rules: rules}); err != nil {
			return res, err
		}
	}
	if b, err := m.readFile(ctx, spec.Target, spec.dir()+"/wp-includes/version.php", 64<<10); err == nil {
		if mm := wpVersionRe.FindSubmatch(b); mm != nil {
			res.Version = string(mm[1])
		}
	}
	h := m.Health(ctx, spec.Domain, "/")
	if h.Status != 0 {
		r.step("Site yanıt veriyor: HTTP %d", h.Status)
	}
	res.Millis = time.Since(start).Milliseconds()
	fmt.Fprintf(log, "✓ %s\n", i18n.T(ctx, "WordPress %s kuruldu. Toplam %s.", res.Version, humanDuration(ctx, time.Since(start))))
	return res, nil
}

// wpEmptyFolder refuses a folder with files in it. Serfex's default page
// does not count; placeholder is its content when it is there.
func (m *Manager) wpEmptyFolder(ctx context.Context, a App) (placeholder []byte, err error) {
	entries, err := os.ReadDir(m.h.Path(a.dir()))
	if errors.Is(err, os.ErrNotExist) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	var others []string
	for _, e := range entries {
		switch e.Name() {
		case ".well-known":
			continue
		case "index.html":
			if b, err := m.readFile(ctx, a.Target, a.dir()+"/index.html", 256<<10); err == nil && strings.Contains(string(b), placeholderMark) {
				placeholder = b
				continue
			}
		}
		others = append(others, e.Name())
	}
	if len(others) > 0 {
		more := ""
		if len(others) > 3 {
			more = fmt.Sprintf(" (+%d)", len(others)-3)
			others = others[:3]
		}
		return nil, invalid("%s is not empty: %s%s", a.Path, strings.Join(others, ", "), more)
	}
	return placeholder, nil
}

// wpClear empties the folder after a failed install. It was empty but
// for the default page before.
func (m *Manager) wpClear(ctx context.Context, a App) {
	entries, err := os.ReadDir(m.h.Path(a.dir()))
	if err != nil {
		return
	}
	for _, e := range entries {
		if e.Name() == ".well-known" {
			continue
		}
		_, _ = m.userRun(ctx, a.Target, "/", "rm", "-rf", "--one-file-system", a.dir()+"/"+e.Name())
	}
}

// wpHtaccess is WordPress's own rewrite block for Apache.
const wpHtaccess = `# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
`

// randomLower is n random lowercase letters and digits.
func randomLower(n int) string {
	const alphabet = "abcdefghijklmnopqrstuvwxyz0123456789"
	b := make([]byte, n)
	_, _ = rand.Read(b)
	for i := range b {
		b[i] = alphabet[int(b[i])%len(alphabet)]
	}
	return string(b)
}
