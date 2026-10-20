// Package app is the servik command tree: parsing, help, output and the
// commands themselves.
package app

import (
	"context"
	"errors"
	"fmt"
	"io"
	"net"
	"net/url"
	"os"
	"os/exec"
	"os/signal"
	"runtime"
	"strconv"
	"strings"
	"syscall"
	"time"

	"github.com/winchester37/servik/cli/internal/api"
	"github.com/winchester37/servik/cli/internal/config"
	. "github.com/winchester37/servik/cli/internal/i18n"
)

// Env is everything the command line takes from the computer, so tests
// can give their own.
type Env struct {
	Stdin          io.Reader
	Stdout, Stderr io.Writer
	Getenv         func(string) string
	// Interactive is true when a person is at the terminal (stdin and
	// stderr are terminals): only then does servik ask questions.
	Interactive bool
	// Color is true when stdout is a terminal that shows colors.
	Color       bool
	OpenBrowser func(url string) error
	// Command runs a program and returns its standard output (servik
	// login --local).
	Command  func(ctx context.Context, name string, args ...string) ([]byte, error)
	Geteuid  func() int
	Hostname func() (string, error)
	ReadFile func(string) ([]byte, error)
	// Interval scales the waits between polls (tests make it short).
	Interval time.Duration
}

// System is the real computer.
func System() *Env {
	return &Env{
		Stdin:       os.Stdin,
		Stdout:      os.Stdout,
		Stderr:      os.Stderr,
		Getenv:      os.Getenv,
		Interactive: isTerminal(os.Stdin) && isTerminal(os.Stderr),
		Color:       isTerminal(os.Stdout) && os.Getenv("NO_COLOR") == "" && os.Getenv("TERM") != "dumb",
		OpenBrowser: openBrowser,
		Command: func(ctx context.Context, name string, args ...string) ([]byte, error) {
			cmd := exec.CommandContext(ctx, name, args...)
			cmd.Stderr = os.Stderr
			return cmd.Output()
		},
		Geteuid:  os.Geteuid,
		Hostname: os.Hostname,
		ReadFile: os.ReadFile,
		Interval: time.Second,
	}
}

// isTerminal is a character device other than /dev/null (CI often
// gives commands /dev/null as their input).
func isTerminal(f *os.File) bool {
	st, err := f.Stat()
	if err != nil || st.Mode()&os.ModeCharDevice == 0 {
		return false
	}
	null, err := os.Stat(os.DevNull)
	return err != nil || !os.SameFile(st, null)
}

func openBrowser(u string) error {
	switch runtime.GOOS {
	case "darwin":
		return exec.Command("open", u).Start()
	case "linux":
		if os.Getenv("DISPLAY") == "" && os.Getenv("WAYLAND_DISPLAY") == "" {
			return errors.New("no display")
		}
		return exec.Command("xdg-open", u).Start()
	}
	return errors.New("unsupported")
}

// usageError is a mistake in the command itself (exit 2).
type usageError struct{ msg string }

func (e *usageError) Error() string { return e.msg }

func usageErr(msg string) error { return &usageError{msg} }

// exitCode ends the run with a code and no message (the command already
// said why, or passes on a remote command's code).
type exitCode int

func (e exitCode) Error() string { return "exit " + strconv.Itoa(int(e)) }

func globalFlags() *Flags {
	f := newFlags()
	f.String("profile", "", T("ad"), T("Kullanılacak profil (varsayılan: son giriş yapılan; SERVIK_PROFILE)"))
	f.Bool("json", "", T("Çıktıyı JSON olarak yazar"))
	f.String("lang", "", "tr|en", T("Dil (varsayılan: sistem dili; SERVIK_LANG)"))
	f.String("url", "", T("adres"), T("Panel adresi (SERVIK_URL)"))
	f.String("token", "", T("anahtar"), T("API anahtarı ya da uygulama belirteci (SERVIK_TOKEN)"))
	f.Bool("yes", "y", T("Onay sormadan yapar"))
	f.Bool("no-color", "", T("Renksiz çıktı (NO_COLOR)"))
	f.Bool("help", "h", T("Yardımı gösterir"))
	return f
}

// Main runs the command line and returns its exit code.
func Main(args []string, env *Env) int {
	// "servik help deploy" is "servik deploy --help".
	if len(args) > 0 && args[0] == "help" {
		args = append(append([]string{}, args[1:]...), "--help")
	}
	SetLocale(Detect(preScan(args, "lang"), env.Getenv))
	root := tree()
	root.link()

	cmd, rest := root, []string{}
	global := globalFlags()
	for i := 0; i < len(args); i++ {
		a := args[i]
		if strings.HasPrefix(a, "-") && a != "-" && a != "--" {
			rest = append(rest, a)
			name, short := strings.TrimLeft(a, "-"), !strings.HasPrefix(a, "--")
			fl := cmd.ownFlags().lookup(name, short)
			if fl == nil {
				fl = global.lookup(name, short)
			}
			if fl != nil && !fl.isBool && !strings.Contains(a, "=") && i+1 < len(args) {
				i++
				rest = append(rest, args[i])
			}
			continue
		}
		if sub := cmd.find(a); sub != nil && !cmd.Passthrough {
			cmd = sub
			continue
		}
		rest = append(rest, args[i:]...)
		break
	}

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	c := &Ctx{Context: ctx, env: env, cmd: cmd, global: global, own: cmd.ownFlags()}
	positional, err := parse(rest, []*Flags{c.own, global}, cmd.Passthrough)
	c.Args = positional
	c.json = c.Bool("json")
	c.yes = c.Bool("yes")
	c.color = env.Color && !c.Bool("no-color") && env.Getenv("NO_COLOR") == ""
	if err == nil {
		err = c.check()
	}
	if err == nil {
		switch {
		case c.Bool("help"):
			fmt.Fprint(env.Stdout, cmd.help(c))
			return 0
		case cmd.Run == nil:
			if len(c.Args) > 0 {
				err = usageErr(T("Bilinmeyen komut: %s", c.Args[0]))
				break
			}
			fmt.Fprint(env.Stdout, cmd.help(c))
			return 0
		default:
			err = cmd.Run(c)
		}
	}
	return c.fail(err)
}

// preScan finds --name value or --name=value before parsing.
func preScan(args []string, name string) string {
	for i, a := range args {
		if a == "--" {
			break
		}
		if a == "--"+name && i+1 < len(args) {
			return args[i+1]
		}
		if v, ok := strings.CutPrefix(a, "--"+name+"="); ok {
			return v
		}
	}
	return ""
}

func (c *Ctx) check() error {
	if l := c.String("lang"); l != "" && l != "tr" && l != "en" {
		return usageErr(T("--lang için tr ya da en yazın."))
	}
	if c.Bool("help") || c.cmd.Run == nil {
		return nil
	}
	if len(c.Args) < c.cmd.MinArgs || (c.cmd.MaxArgs >= 0 && len(c.Args) > c.cmd.MaxArgs) {
		if c.cmd.Args == "" {
			return usageErr(T("%s argüman almaz.", c.cmd.path()))
		}
		return usageErr(T("Kullanım: %s %s", c.cmd.path(), c.cmd.Args))
	}
	return nil
}

// fail writes err for people and returns the exit code.
func (c *Ctx) fail(err error) int {
	if err == nil {
		return 0
	}
	var code exitCode
	if errors.As(err, &code) {
		return int(code)
	}
	var usage *usageError
	if errors.As(err, &usage) {
		c.errorf("%s", usage.msg)
		fmt.Fprintln(c.env.Stderr, T("Yardım için: %s --help", c.cmd.path()))
		return 2
	}
	if errors.Is(err, context.Canceled) {
		c.errorf("%s", T("Durduruldu."))
		return 130
	}
	var apiErr *api.Error
	if errors.As(err, &apiErr) {
		c.errorf("%s", apiErr.Error())
		if apiErr.Status == 401 {
			fmt.Fprintln(c.env.Stderr, T("Yeniden giriş yapın: servik login %s", c.urlHint()))
		}
		return 1
	}
	var pin *api.PinError
	if errors.As(err, &pin) {
		c.errorf("%s", T("Panelin sertifikası değişti ve tanıdığınız sertifika değil (parmak izi %s). Bu sizin yaptığınız bir değişiklikse servik login ile yeniden giriş yapın; değilse bağlanmayın.", api.Format(pin.Got)))
		return 1
	}
	if api.IsCertificateProblem(err) {
		c.errorf("%s", T("Panelin sertifikası doğrulanamadı: %s", err))
		fmt.Fprintln(c.env.Stderr, T("Panele ilk kez bağlanıyorsanız servik login ile giriş yapın; parmak izini orada onaylarsınız."))
		return 1
	}
	var urlErr *url.Error
	var netErr net.Error
	if errors.As(err, &urlErr) || errors.As(err, &netErr) {
		c.errorf("%s", T("Panele ulaşılamadı: %s", err))
		return 1
	}
	c.errorf("%s", err.Error())
	return 1
}

func (c *Ctx) urlHint() string {
	if c.profile != nil {
		return c.profile.URL
	}
	return T("<panel-adresi>")
}

// Ctx is one run of a command.
type Ctx struct {
	context.Context
	env    *Env
	cmd    *Command
	Args   []string
	own    *Flags
	global *Flags
	json   bool
	yes    bool
	color  bool

	cfg         *config.Config
	profileName string
	profile     *config.Profile
	client      *api.Client
	who         *me
}

func (c *Ctx) value(name string) ([]string, bool) {
	if v, ok := c.own.values[name]; ok {
		return v, true
	}
	v, ok := c.global.values[name]
	return v, ok
}

// Has is true when the flag was given.
func (c *Ctx) Has(name string) bool {
	_, ok := c.value(name)
	return ok
}

// Bool is a flag's value; false when not given.
func (c *Ctx) Bool(name string) bool {
	v, ok := c.value(name)
	if !ok {
		return false
	}
	b, _ := strconv.ParseBool(v[len(v)-1])
	return b
}

// String is a flag's value, "" when not given.
func (c *Ctx) String(name string) string {
	v, ok := c.value(name)
	if !ok {
		return ""
	}
	return v[len(v)-1]
}

// Strings are every value of a repeatable flag; commas separate values too.
func (c *Ctx) Strings(name string) []string {
	v, _ := c.value(name)
	var out []string
	for _, s := range v {
		for _, p := range strings.Split(s, ",") {
			if p = strings.TrimSpace(p); p != "" {
				out = append(out, p)
			}
		}
	}
	return out
}

// Int is a flag's number, def when not given.
func (c *Ctx) Int(name string, def int) (int, error) {
	if !c.Has(name) {
		return def, nil
	}
	n, err := strconv.Atoi(c.String(name))
	if err != nil {
		return 0, usageErr(T("--%s bir sayı bekliyor.", name))
	}
	return n, nil
}

// Getenv reads the environment.
func (c *Ctx) Getenv(name string) string { return c.env.Getenv(name) }

// config loads the profiles once.
func (c *Ctx) config() (*config.Config, error) {
	if c.cfg != nil {
		return c.cfg, nil
	}
	path, err := config.Path(c.env.Getenv)
	if err != nil {
		return nil, err
	}
	cfg, err := config.Load(path)
	if err != nil {
		return nil, err
	}
	c.cfg = cfg
	return cfg, nil
}

func (c *Ctx) first(flag, env string) string {
	if v := c.String(flag); v != "" {
		return v
	}
	return c.env.Getenv(env)
}

// Client is the panel this run talks to: --url and --token (or
// SERVIK_URL and SERVIK_TOKEN, for CI), else the profile.
func (c *Ctx) Client() (*api.Client, error) {
	if c.client != nil {
		return c.client, nil
	}
	opts := api.Options{Lang: Locale()}
	token, rawURL := c.first("token", "SERVIK_TOKEN"), c.first("url", "SERVIK_URL")
	profileName := c.first("profile", "SERVIK_PROFILE")
	if token == "" || rawURL == "" || profileName != "" {
		cfg, err := c.config()
		if err != nil {
			return nil, err
		}
		name, p := cfg.Get(profileName)
		if p == nil && token == "" {
			if profileName != "" {
				return nil, fmt.Errorf("%s", T("%s adında bir profil yok; profiller: servik profile list", profileName))
			}
			return nil, fmt.Errorf("%s", T("Henüz giriş yapılmadı. Önce: servik login <panel-adresi>"))
		}
		if p != nil {
			c.profileName, c.profile = name, p
			opts.URL, opts.Token, opts.Fingerprint, opts.Connect = p.URL, p.Token, p.Fingerprint, p.Connect
		}
	}
	if rawURL != "" {
		opts.URL = rawURL
		if c.profile == nil || c.profile.URL != rawURL {
			opts.Fingerprint, opts.Connect = "", ""
		}
	}
	if token != "" {
		opts.Token = token
	}
	if fp := c.env.Getenv("SERVIK_FINGERPRINT"); fp != "" {
		opts.Fingerprint = fp
	}
	if opts.URL == "" {
		return nil, fmt.Errorf("%s", T("Panel adresi yok: --url ya da SERVIK_URL ile verin."))
	}
	client, err := api.New(opts)
	if err != nil {
		return nil, err
	}
	c.client = client
	return client, nil
}
