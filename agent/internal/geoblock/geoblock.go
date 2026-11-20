// Package geoblock blocks whole countries (Güvenlik > IP engelleri >
// Ülkeler).
//
// Which addresses belong to a country comes from the table the site
// statistics already keep (stats.Geo: the regional registries' lists,
// downloaded once a month). The blocked countries are enforced in two
// places:
//
//	/etc/nginx/conf.d/serfex-countries.conf        the countries' prefixes as a geo
//	                                               block, and who passes anyway
//	/etc/serfex/nginx/sites/<domain>/countries.conf   "return 403" in every site,
//	                                               for a visitor the geo block lists
//	table inet serfex, sets country4 and country6  only when the block covers the
//	                                               whole server: every port but
//	                                               25, 53, 80 and 443 is closed
//	/etc/serfex/firewall/countries.json            the countries and the scope, root 0600
//
// Web sites are refused by Nginx, not by the firewall, for two reasons.
// Behind Cloudflare's proxy the firewall sees Cloudflare, Nginx sees the
// visitor. And Let's Encrypt validates from several countries: its path
// (/.well-known/acme-challenge/) passes here, so certificates keep
// renewing whatever is blocked.
//
// Allowed addresses, the server's own addresses and Cloudflare's ranges
// always pass. With no country blocked nothing of the above exists: the
// files are removed and the sets are empty.
package geoblock

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/netip"
	"os"
	"regexp"
	"sort"
	"strings"
	"sync"
	"time"

	"github.com/calltech-app/serfex/agent/internal/cloudflare"
	"github.com/calltech-app/serfex/agent/internal/firewall"
	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/site"
	"github.com/calltech-app/serfex/agent/internal/stats"
	"github.com/calltech-app/serfex/agent/internal/txn"
)

const (
	// StateFile holds the blocked countries and the scope.
	StateFile = "/etc/serfex/firewall/countries.json"
	// Conf is the http-level Nginx file.
	Conf = "/etc/nginx/conf.d/serfex-countries.conf"
	// Snippet is the file in every site's site.ExtraDir.
	Snippet = "countries.conf"

	// ScopeWeb refuses the countries on the web sites only.
	ScopeWeb = "web"
	// ScopeServer also closes the server's other ports to them.
	ScopeServer = "server"

	// MaxCountries is how many countries can be blocked.
	MaxCountries = 250
)

// ErrNoTable is what Sync returns while countries are blocked but the
// country table has not been read yet (the agent just started). Nothing
// is changed then; Sync runs again when the table is there.
var ErrNoTable = errors.New("the country table is not loaded yet")

// ValidationError reports a request that can never succeed as given.
type ValidationError struct{ msg string }

func (e *ValidationError) Error() string { return e.msg }

// OperationError wraps a failure while changing the server.
type OperationError struct{ Err error }

func (e *OperationError) Error() string { return e.Err.Error() }
func (e *OperationError) Unwrap() error { return e.Err }

// Spec is the whole list the panel wants in force.
type Spec struct {
	// Countries are ISO 3166 two-letter codes; empty turns the block off.
	Countries []string `json:"countries"`
	// Scope is ScopeWeb (also when empty) or ScopeServer.
	Scope string `json:"scope"`
}

// Status says what is in force.
type Status struct {
	Countries []string `json:"countries"`
	Scope     string   `json:"scope"`
	// Ranges is how many address blocks the table lists for each blocked
	// country; 0 means the table does not know the country.
	Ranges map[string]int `json:"ranges"`
	// Loaded tells whether the country table is there.
	Loaded bool `json:"loaded"`
	// Updated is when the table was last downloaded.
	Updated *time.Time `json:"updated"`
	// Sites is how many sites refuse the countries.
	Sites int `json:"sites"`
	// Nginx tells whether the server has Nginx at all.
	Nginx bool `json:"nginx"`
}

type state struct {
	Countries []string `json:"countries"`
	Scope     string   `json:"scope"`
}

// Manager applies the block. Operations are serialised.
type Manager struct {
	h  host.Host
	fw *firewall.Manager
	mu sync.Mutex

	// Geo returns the country table, nil until it is loaded.
	Geo func() *stats.Geo
	// Updated returns when the table was downloaded; zero when unknown.
	Updated func() time.Time
	// Own returns the server's own addresses, which always pass.
	Own func() []string

	// applied is what the firewall was last given, so an idle Sync does
	// not run nft; empty until the first Sync after a start.
	applied string
}

// NewManager returns a Manager; fw holds the country sets and the allowed
// addresses.
func NewManager(h host.Host, fw *firewall.Manager) *Manager {
	return &Manager{h: h, fw: fw}
}

var codeRe = regexp.MustCompile(`^[A-Z]{2}$`)

func (s Spec) normalise() (state, error) {
	st := state{Countries: []string{}, Scope: s.Scope}
	if st.Scope == "" {
		st.Scope = ScopeWeb
	}
	if st.Scope != ScopeWeb && st.Scope != ScopeServer {
		return st, &ValidationError{fmt.Sprintf("scope %q is not known; it is %q or %q", s.Scope, ScopeWeb, ScopeServer)}
	}
	seen := map[string]bool{}
	for _, c := range s.Countries {
		code := strings.ToUpper(strings.TrimSpace(c))
		if !codeRe.MatchString(code) {
			return st, &ValidationError{fmt.Sprintf("%q is not a two-letter country code", c)}
		}
		if !seen[code] {
			seen[code] = true
			st.Countries = append(st.Countries, code)
		}
	}
	if len(st.Countries) > MaxCountries {
		return st, &ValidationError{fmt.Sprintf("at most %d countries can be blocked", MaxCountries)}
	}
	sort.Strings(st.Countries)
	return st, nil
}

// Apply puts spec in force: the files for Nginx, then the firewall's
// sets. When either refuses, what was in force before is put back and
// the error returned, so the panel never shows a block the server does
// not hold.
func (m *Manager) Apply(ctx context.Context, spec Spec) (Status, error) {
	next, err := spec.normalise()
	if err != nil {
		return Status{}, err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	if len(next.Countries) > 0 && m.geo().Size() == 0 {
		return Status{}, &ValidationError{"the country table has not been downloaded yet; try again in a few minutes"}
	}
	prev, err := m.load()
	if err != nil {
		return Status{}, &OperationError{err}
	}
	if err := m.save(next); err != nil {
		return Status{}, &OperationError{err}
	}
	if err := m.sync(ctx, next); err != nil {
		// Back to what was in force. The country table may be gone for
		// the previous list too; then its files simply stay.
		back := context.WithoutCancel(ctx)
		if serr := m.save(prev); serr != nil {
			return Status{}, &OperationError{errors.Join(err, serr)}
		}
		if rerr := m.sync(back, prev); rerr != nil && !errors.Is(rerr, ErrNoTable) {
			err = errors.Join(err, fmt.Errorf("putting the previous countries back: %w", rerr))
		}
		return Status{}, typed(err)
	}
	return m.status(next), nil
}

// Sync writes the files and the sets again from the stored list: after
// the country table was replaced, a site was added, or the allowed
// addresses or Cloudflare's ranges changed. A server that blocks no
// country is not touched.
func (m *Manager) Sync(ctx context.Context) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	st, err := m.load()
	if err != nil {
		return &OperationError{err}
	}
	if len(st.Countries) == 0 && !m.exists(Conf) && !m.leftover() {
		// Nothing is blocked and nothing was left behind.
		return nil
	}
	return typed(m.sync(ctx, st))
}

// leftover tells whether a site still holds a snippet: an agent that
// died while lifting the last country leaves some behind, and without
// the http-level file they keep Nginx from loading its configuration.
func (m *Manager) leftover() bool {
	dirs, _ := os.ReadDir(m.h.Path(site.ExtraRoot))
	for _, d := range dirs {
		if m.exists(site.ExtraRoot + "/" + d.Name() + "/" + Snippet) {
			return true
		}
	}
	return false
}

// Status says what is in force.
func (m *Manager) Status() (Status, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	st, err := m.load()
	if err != nil {
		return Status{}, &OperationError{err}
	}
	return m.status(st), nil
}

func (m *Manager) status(st state) Status {
	geo := m.geo()
	out := Status{Countries: st.Countries, Scope: st.Scope, Ranges: map[string]int{}, Loaded: geo.Size() > 0, Nginx: m.nginx()}
	for _, c := range st.Countries {
		out.Ranges[c] = geo.Blocks(c)
	}
	if m.Updated != nil {
		if at := m.Updated(); !at.IsZero() {
			at = at.UTC()
			out.Updated = &at
		}
	}
	if out.Nginx && len(st.Countries) > 0 {
		for _, d := range site.Domains(m.h) {
			if m.exists(site.ExtraDir(d) + "/" + Snippet) {
				out.Sites++
			}
		}
	}
	return out
}

func (m *Manager) geo() *stats.Geo {
	if m.Geo == nil {
		return nil
	}
	return m.Geo()
}

func (m *Manager) nginx() bool {
	fi, err := os.Stat(m.h.Path("/etc/nginx"))
	return err == nil && fi.IsDir()
}

func (m *Manager) exists(abs string) bool {
	_, err := os.Lstat(m.h.Path(abs))
	return err == nil
}

// sync enforces st. Nginx first, in a transaction that is rolled back
// when Nginx or, afterwards, the firewall refuses.
func (m *Manager) sync(ctx context.Context, st state) error {
	var v4, v6 []netip.Prefix
	if len(st.Countries) > 0 {
		geo := m.geo()
		if geo.Size() == 0 {
			return ErrNoTable
		}
		v4, v6 = geo.Prefixes(st.Countries)
	}
	t := txn.New(m.h)
	reloaded, err := m.writeNginx(ctx, t, st, v4, v6)
	if err != nil {
		_ = t.Rollback(context.WithoutCancel(ctx))
		return err
	}
	var pass []netip.Prefix
	if st.Scope != ScopeServer {
		v4, v6 = nil, nil
	} else if len(v4)+len(v6) > 0 {
		pass = m.passes()
	}
	mark := fingerprint(v4, v6, pass)
	if mark != m.applied {
		if err := m.fw.SetCountries(ctx, v4, v6, pass); err != nil {
			back := context.WithoutCancel(ctx)
			rerr := t.Rollback(back)
			if rerr == nil && reloaded {
				rerr = m.reload(back)
			}
			return errors.Join(&OperationError{err}, rerr)
		}
		m.applied = mark
	}
	return nil
}

// writeNginx writes or removes the http-level file and every site's
// snippet, and reloads Nginx when something changed. It reports whether
// it reloaded.
func (m *Manager) writeNginx(ctx context.Context, t *txn.Tx, st state, v4, v6 []netip.Prefix) (bool, error) {
	if !m.nginx() {
		return false, nil
	}
	if len(st.Countries) == 0 {
		if err := t.RemoveFile(Conf); err != nil {
			return false, &OperationError{err}
		}
		// Every directory, also one whose site is gone.
		dirs, _ := os.ReadDir(m.h.Path(site.ExtraRoot))
		for _, d := range dirs {
			if err := t.RemoveFile(site.ExtraRoot + "/" + d.Name() + "/" + Snippet); err != nil {
				return false, &OperationError{err}
			}
		}
	} else {
		if err := t.RootDir("/etc/nginx/conf.d", 0o755, 0, 0); err != nil {
			return false, &OperationError{err}
		}
		if err := t.WriteFile(Conf, Content(st.Countries, append(v4, v6...), m.exempt())); err != nil {
			return false, &OperationError{err}
		}
		for _, domain := range site.Domains(m.h) {
			dir := site.ExtraDir(domain)
			for _, d := range []string{"/etc/serfex", "/etc/serfex/nginx", site.ExtraRoot, dir} {
				if err := t.RootDir(d, 0o755, 0, 0); err != nil {
					return false, &OperationError{err}
				}
			}
			if err := t.WriteFile(dir+"/"+Snippet, SiteContent()); err != nil {
				return false, &OperationError{err}
			}
		}
	}
	if !t.Changed {
		return false, nil
	}
	if out, err := m.h.Run(ctx, host.Command{Name: "nginx", Args: []string{"-t"}}); err != nil {
		return false, &ValidationError{"Nginx refused the configuration, nothing was changed: " + strings.TrimSpace(out)}
	}
	if err := m.reload(ctx); err != nil {
		return false, err
	}
	return true, nil
}

func (m *Manager) reload(ctx context.Context) error {
	if _, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"reload", "nginx.service"}}); err != nil {
		return &OperationError{fmt.Errorf("reloading Nginx: %w", err)}
	}
	return nil
}

// exempt lists who passes whatever is blocked: loopback, the allowed
// addresses, the server's own and Cloudflare's ranges, each once.
func (m *Manager) exempt() []string {
	list := []string{"127.0.0.0/8", "::1"}
	if allowed, err := m.fw.Allowed(); err == nil {
		list = append(list, allowed...)
	}
	if m.Own != nil {
		list = append(list, m.Own()...)
	}
	for _, r := range cloudflare.Ranges(m.h) {
		list = append(list, r.String())
	}
	seen := map[netip.Prefix]bool{}
	out := make([]string, 0, len(list))
	for _, a := range list {
		p, err := firewall.Normalize(a)
		if err != nil || seen[p] {
			continue
		}
		seen[p] = true
		if p.IsSingleIP() {
			out = append(out, p.Addr().String())
		} else {
			out = append(out, p.String())
		}
	}
	return out
}

// passes lists who the firewall's country rules never stop: the server's
// own addresses and Cloudflare's ranges, each once. The allowed addresses
// are not among them: the firewall accepts those before any block.
func (m *Manager) passes() []netip.Prefix {
	var list []string
	if m.Own != nil {
		list = append(list, m.Own()...)
	}
	for _, r := range cloudflare.Ranges(m.h) {
		list = append(list, r.String())
	}
	seen := map[netip.Prefix]bool{}
	out := make([]netip.Prefix, 0, len(list))
	for _, a := range list {
		p, err := firewall.Normalize(a)
		if err != nil || seen[p] {
			continue
		}
		seen[p] = true
		out = append(out, p)
	}
	return out
}

// Content is the http-level file: who is listed, who is exempt, and the
// variable the sites test.
func Content(countries []string, listed []netip.Prefix, exempt []string) []byte {
	var b strings.Builder
	fmt.Fprintf(&b, "# %s. Manual changes are overwritten.\n", txn.Marker)
	fmt.Fprintf(&b, "# Blocked countries (Güvenlik > IP engelleri > Ülkeler): %s.\n", strings.Join(countries, ", "))
	b.WriteString("# A visitor whose address is registered in one of them gets 403 from every\n")
	b.WriteString("# site, also behind Cloudflare's proxy. Allowed addresses, the server's own\n")
	b.WriteString("# addresses and Cloudflare's ranges pass, and so does Let's Encrypt's\n")
	b.WriteString("# validation.\n")
	b.WriteString("geo $serfex_country_listed {\n    default 0;\n")
	for _, p := range listed {
		fmt.Fprintf(&b, "    %s 1;\n", p)
	}
	b.WriteString("}\ngeo $serfex_country_exempt {\n    default 0;\n")
	for _, a := range exempt {
		fmt.Fprintf(&b, "    %s 1;\n", a)
	}
	b.WriteString("}\n")
	// "01": not exempt, listed. The validation path is tried first.
	b.WriteString("map \"$serfex_country_exempt$serfex_country_listed:$uri\" $serfex_country_refused {\n")
	b.WriteString("    default 0;\n")
	b.WriteString("    \"~^01:/\\.well-known/acme-challenge/\" 0;\n")
	b.WriteString("    \"~^01:\" 1;\n")
	b.WriteString("}\n")
	return []byte(b.String())
}

// SiteContent is the snippet every site includes at server level.
func SiteContent() []byte {
	var b strings.Builder
	fmt.Fprintf(&b, "# %s. Manual changes are overwritten.\n", txn.Marker)
	b.WriteString("# Blocked countries (Güvenlik > IP engelleri > Ülkeler); the list is in\n")
	b.WriteString("# " + Conf + ".\n")
	b.WriteString("if ($serfex_country_refused) {\n    return 403;\n}\n")
	return []byte(b.String())
}

func fingerprint(v4, v6, pass []netip.Prefix) string {
	var b strings.Builder
	for _, part := range []struct {
		name string
		list []netip.Prefix
	}{{"v4", v4}, {"v6", v6}, {"pass", pass}} {
		b.WriteString(part.name + ":")
		for _, p := range part.list {
			b.WriteString(p.String())
			b.WriteByte(' ')
		}
	}
	return b.String()
}

func typed(err error) error {
	if err == nil || errors.Is(err, ErrNoTable) {
		return err
	}
	var v *ValidationError
	var o *OperationError
	if errors.As(err, &v) || errors.As(err, &o) {
		return err
	}
	return &OperationError{err}
}

func (m *Manager) load() (state, error) {
	st := state{Countries: []string{}, Scope: ScopeWeb}
	data, err := os.ReadFile(m.h.Path(StateFile))
	if errors.Is(err, os.ErrNotExist) {
		return st, nil
	}
	if err != nil {
		return st, err
	}
	if err := json.Unmarshal(data, &st); err != nil {
		return st, fmt.Errorf("%s: %w", StateFile, err)
	}
	// A file edited by hand is read as leniently as it can be.
	next, err := Spec{Countries: st.Countries, Scope: st.Scope}.normalise()
	if err != nil {
		return state{Countries: []string{}, Scope: ScopeWeb}, fmt.Errorf("%s: %w", StateFile, err)
	}
	return next, nil
}

func (m *Manager) save(st state) error {
	dir := m.h.Path("/etc/serfex/firewall")
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return err
	}
	data, err := json.MarshalIndent(st, "", "  ")
	if err != nil {
		return err
	}
	tmp := m.h.Path(StateFile + ".tmp")
	if err := os.WriteFile(tmp, append(data, '\n'), 0o600); err != nil {
		return err
	}
	return os.Rename(tmp, m.h.Path(StateFile))
}
