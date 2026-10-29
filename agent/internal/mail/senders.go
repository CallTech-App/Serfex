package mail

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/netip"
	"os"
	"regexp"
	"slices"
	"sort"
	"strings"

	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/txn"
)

// Sites send mail with sendmail (PHP's mail()) as their system user, or
// over SMTP from this server without a login. Postfix would accept any
// sender from both, so a customer could send as another customer's
// domain and pass SPF and DKIM. The panel sends which domains each
// system user's sites use:
//
//   - login-senders (local_login_sender_maps) limits sendmail: a listed
//     user may send only as its own domains or its bare login; accounts
//     not listed (root, the panel) are not limited.
//   - local-senders, used for clients in local-clients, refuses a hosted
//     domain as the sender of unauthenticated SMTP from this server:
//     Postfix cannot tell whose site connected, so such mail must go
//     through sendmail or a mailbox login on port 587.
const sendersState = stateDir + "/senders.json"

// SendersSpec says which domains each site's system user may send as.
type SendersSpec struct {
	// Logins maps a system user to the domains its sites and mail
	// domains use. A user with no domains may send only as itself.
	Logins map[string][]string `json:"logins"`
}

// SendersResult describes applied sender limits.
type SendersResult struct {
	Changed bool `json:"changed"`
	Logins  int  `json:"logins"`
}

var loginRe = regexp.MustCompile(`^[a-z_][a-z0-9_-]{0,31}$`)

func (s *SendersSpec) normalize() error {
	if s.Logins == nil {
		s.Logins = map[string][]string{}
	}
	for login, domains := range s.Logins {
		if !loginRe.MatchString(login) || login == "root" {
			return invalid("%q is not a site's system user", login)
		}
		clean := make([]string, 0, len(domains))
		for _, d := range domains {
			d = strings.ToLower(strings.TrimSuffix(d, "."))
			if err := validateDomain(d); err != nil {
				return err
			}
			clean = append(clean, d)
		}
		sort.Strings(clean)
		s.Logins[login] = slices.Compact(clean)
	}
	return nil
}

// ApplySenders stores the sender limits and rewrites the maps.
func (m *Manager) ApplySenders(ctx context.Context, spec SendersSpec) (res SendersResult, err error) {
	if err := spec.normalize(); err != nil {
		return SendersResult{}, err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	if _, err := m.loadServer(); err != nil {
		return SendersResult{}, err
	}

	t := txn.New(m.h)
	defer m.finish(ctx, t, &err)

	data, err := json.MarshalIndent(spec, "", "  ")
	if err != nil {
		return SendersResult{}, err
	}
	if err := t.WriteOwned(sendersState, data, 0o600, 0, 0); err != nil {
		return SendersResult{}, err
	}
	if err := m.rebuild(ctx, t); err != nil {
		return SendersResult{}, err
	}
	return SendersResult{Changed: t.Changed, Logins: len(spec.Logins)}, nil
}

// loadSenders reads the stored sender limits; none are stored before the
// panel first sends them.
func (m *Manager) loadSenders() (SendersSpec, error) {
	var s SendersSpec
	data, err := os.ReadFile(m.h.Path(sendersState))
	if errors.Is(err, os.ErrNotExist) {
		return s, nil
	}
	if err != nil {
		return s, err
	}
	if err := json.Unmarshal(data, &s); err != nil {
		return s, err
	}
	return s, s.normalize()
}

// senderMaps are the files for the sender limits.
type senderMaps struct {
	login, clients, local []byte
}

// buildSenderMaps derives the sender maps. Every mail domain counts as
// hosted, even before the panel sent the sites' domains.
func buildSenderMaps(spec SendersSpec, domains []DomainSpec, addrs []netip.Addr) senderMaps {
	var login, clients, local strings.Builder
	login.WriteString(mapHeader("Which domains a site's system user may send as with sendmail (local_login_sender_maps)."))
	clients.WriteString(mapHeader("Clients on this server whose unauthenticated SMTP may not use a hosted domain as sender."))
	local.WriteString(mapHeader("Hosted domains, refused as sender of unauthenticated SMTP from this server."))

	logins := make([]string, 0, len(spec.Logins))
	for l := range spec.Logins {
		logins = append(logins, l)
	}
	sort.Strings(logins)
	hosted := map[string]bool{}
	for _, l := range logins {
		// The bare login allows sendmail's default sender, login@hostname.
		allowed := []string{l}
		for _, d := range spec.Logins[l] {
			allowed = append(allowed, "@"+d)
			hosted[d] = true
		}
		fmt.Fprintf(&login, "%s %s\n", l, strings.Join(allowed, ", "))
	}
	for _, d := range domains {
		hosted[d.Domain] = true
	}

	nets := []string{"127.0.0.0/8", "::1/128"}
	for _, a := range addrs {
		if a.IsLoopback() || a.IsLinkLocalUnicast() {
			continue
		}
		nets = append(nets, netip.PrefixFrom(a, a.BitLen()).String())
	}
	sort.Strings(nets)
	for _, n := range slices.Compact(nets) {
		fmt.Fprintf(&clients, "%s %s\n", n, localSendersClass)
	}

	names := make([]string, 0, len(hosted))
	for d := range hosted {
		names = append(names, d)
	}
	sort.Strings(names)
	for _, d := range names {
		// Postfix's access maps match subdomains too.
		fmt.Fprintf(&local, "%s REJECT 5.7.1 Mail from this server may use %s as sender only through sendmail or a mailbox login on port 587\n", d, d)
	}
	return senderMaps{login: []byte(login.String()), clients: []byte(clients.String()), local: []byte(local.String())}
}

// allMaps builds every map from the stored domains and sender limits.
func (m *Manager) allMaps(domains []DomainSpec) (maps, error) {
	spec, err := m.loadSenders()
	if err != nil {
		return maps{}, err
	}
	var addrs []netip.Addr
	if m.Addresses != nil {
		addrs = m.Addresses()
	}
	built := buildMaps(domains)
	built.senders = buildSenderMaps(spec, domains, addrs)
	return built, nil
}

// localSendersClass is the restriction class main.cf defines for local
// clients.
const localSendersClass = "serfex_local_senders"

// writeSenderMaps writes the sender maps. sendmail's postdrop reads
// login-senders as group postdrop.
func (m *Manager) writeSenderMaps(t *txn.Tx, built senderMaps) error {
	postdrop, err := host.LookupGroup(m.h, "postdrop")
	if err != nil {
		return fmt.Errorf("postdrop group: %w", err)
	}
	if err := t.WriteFileMode(postfixMaps+"/login-senders", built.login, 0o640, 0, postdrop); err != nil {
		return err
	}
	if err := t.WriteFile(postfixMaps+"/local-clients", built.clients); err != nil {
		return err
	}
	return t.WriteFile(postfixMaps+"/local-senders", built.local)
}
