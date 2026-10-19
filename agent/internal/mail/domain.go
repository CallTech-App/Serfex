package mail

import (
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"sort"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/winchester37/servik/agent/internal/hostname"
)

// MaxMailboxes bounds the mailboxes and aliases of one domain.
const MaxMailboxes = 5000

// DomainSpec is everything about one mail domain.
type DomainSpec struct {
	Domain string `json:"domain"`
	// SpamFilter files messages Rspamd marks as spam into Junk. Clear
	// spam (score 15 and up) is rejected for every domain.
	SpamFilter bool      `json:"spam_filter"`
	Mailboxes  []Mailbox `json:"mailboxes"`
	Aliases    []Alias   `json:"aliases"`
	// CatchAll receives mail for addresses that do not exist; empty
	// rejects them.
	CatchAll []string `json:"catch_all"`
	// Suspended keeps the domain's mail but stops the service: incoming
	// mail is deferred (senders retry for days) and mailboxes cannot sign
	// in, so they can neither read nor send.
	Suspended bool `json:"suspended"`
}

// Mailbox is one mailbox of a domain.
type Mailbox struct {
	// Name is the part before the @.
	Name string `json:"name"`
	// PasswordHash is a Dovecot password hash such as
	// {SHA512-CRYPT}$6$...; the agent never sees the password.
	PasswordHash string `json:"password_hash"`
	// QuotaMB limits the mailbox size; 0 means no limit.
	QuotaMB int `json:"quota_mb"`
	// ForwardTo sends a copy of every message to these addresses.
	ForwardTo []string `json:"forward_to"`
	// KeepCopy keeps forwarded messages in the mailbox too.
	KeepCopy  bool       `json:"keep_copy"`
	Autoreply *Autoreply `json:"autoreply"`
}

// Autoreply answers incoming mail, at most once a day per sender.
type Autoreply struct {
	Subject string `json:"subject"`
	Body    string `json:"body"`
	// Until is the last day (YYYY-MM-DD, server time) to answer; empty
	// answers until it is turned off.
	Until string `json:"until"`
}

// Alias forwards an address of the domain to other addresses.
type Alias struct {
	Name string   `json:"name"`
	To   []string `json:"to"`
}

var (
	localRe = regexp.MustCompile(`^[a-z0-9](?:[a-z0-9._-]{0,62}[a-z0-9])?$`)
	hashRe  = regexp.MustCompile(`^\{(SHA512-CRYPT|BLF-CRYPT|ARGON2ID)\}\$[^\s:]{10,200}$`)
	dateRe  = regexp.MustCompile(`^\d{4}-\d{2}-\d{2}$`)
)

func validateLocal(n string) error {
	if !localRe.MatchString(n) || strings.Contains(n, "..") {
		return invalid("%q is not a valid mailbox name (lowercase letters, digits, dot, hyphen and underscore)", n)
	}
	return nil
}

// validateAddress checks a full address used as a destination.
func validateAddress(a string) error {
	local, domain, ok := strings.Cut(a, "@")
	if !ok || local == "" || len(local) > 64 || strings.ContainsAny(local, " \t\r\n,:<>()[]\\\"") {
		return invalid("%q is not a valid e-mail address", a)
	}
	if err := hostname.Validate(domain); err != nil {
		return invalid("%q is not a valid e-mail address", a)
	}
	return nil
}

func validateDestinations(what string, to []string) error {
	if len(to) > 50 {
		return invalid("%s has more than 50 destinations", what)
	}
	seen := map[string]bool{}
	for _, a := range to {
		if err := validateAddress(a); err != nil {
			return err
		}
		if seen[a] {
			return invalid("%s lists %s twice", what, a)
		}
		seen[a] = true
	}
	return nil
}

func validateDomain(d string) error {
	if err := hostname.Validate(d); err != nil {
		return invalid("%s", err.Error())
	}
	return nil
}

// normalize checks the spec and sorts it, so equal domains are stored
// byte for byte the same.
func (s *DomainSpec) normalize() error {
	// Sorting below must not reorder the caller's slices.
	s.Mailboxes, s.Aliases = slices.Clone(s.Mailboxes), slices.Clone(s.Aliases)
	if err := validateDomain(s.Domain); err != nil {
		return err
	}
	if len(s.Mailboxes)+len(s.Aliases) > MaxMailboxes {
		return invalid("a domain has at most %d mailboxes and aliases", MaxMailboxes)
	}
	names := map[string]string{}
	for i := range s.Mailboxes {
		b := &s.Mailboxes[i]
		if err := validateLocal(b.Name); err != nil {
			return err
		}
		if names[b.Name] != "" {
			return invalid("%s@%s is listed twice", b.Name, s.Domain)
		}
		names[b.Name] = "mailbox"
		if !hashRe.MatchString(b.PasswordHash) {
			return invalid("the password of %s@%s must be a Dovecot hash such as {SHA512-CRYPT}$6$...", b.Name, s.Domain)
		}
		if b.QuotaMB < 0 || b.QuotaMB > 10<<20 {
			return invalid("the quota of %s@%s is out of range", b.Name, s.Domain)
		}
		if err := validateDestinations(b.Name+"@"+s.Domain, b.ForwardTo); err != nil {
			return err
		}
		if b.ForwardTo == nil {
			b.ForwardTo = []string{}
		}
		if len(b.ForwardTo) == 0 {
			b.KeepCopy = false
		}
		if a := b.Autoreply; a != nil {
			if strings.TrimSpace(a.Body) == "" {
				return invalid("the autoreply of %s@%s has no text", b.Name, s.Domain)
			}
			if utf8.RuneCountInString(a.Subject) > 200 || utf8.RuneCountInString(a.Body) > 5000 {
				return invalid("the autoreply of %s@%s is too long", b.Name, s.Domain)
			}
			if !utf8.ValidString(a.Subject+a.Body) || strings.ContainsAny(a.Subject, "\r\n") {
				return invalid("the autoreply of %s@%s has invalid characters", b.Name, s.Domain)
			}
			if a.Until != "" {
				if _, err := time.Parse("2006-01-02", a.Until); err != nil || !dateRe.MatchString(a.Until) {
					return invalid("the autoreply end date %q is not a date (YYYY-MM-DD)", a.Until)
				}
			}
		}
	}
	for i := range s.Aliases {
		a := &s.Aliases[i]
		if err := validateLocal(a.Name); err != nil {
			return err
		}
		if names[a.Name] != "" {
			return invalid("%s@%s is already a %s", a.Name, s.Domain, names[a.Name])
		}
		names[a.Name] = "forwarder"
		if len(a.To) == 0 {
			return invalid("forwarder %s@%s has no destination", a.Name, s.Domain)
		}
		if err := validateDestinations(a.Name+"@"+s.Domain, a.To); err != nil {
			return err
		}
		for _, to := range a.To {
			if to == a.Name+"@"+s.Domain {
				return invalid("forwarder %s@%s points at itself", a.Name, s.Domain)
			}
		}
	}
	if err := validateDestinations("the catch-all address", s.CatchAll); err != nil {
		return err
	}
	if s.CatchAll == nil {
		s.CatchAll = []string{}
	}
	if s.Mailboxes == nil {
		s.Mailboxes = []Mailbox{}
	}
	if s.Aliases == nil {
		s.Aliases = []Alias{}
	}
	sort.Slice(s.Mailboxes, func(i, j int) bool { return s.Mailboxes[i].Name < s.Mailboxes[j].Name })
	sort.Slice(s.Aliases, func(i, j int) bool { return s.Aliases[i].Name < s.Aliases[j].Name })
	return nil
}

// address returns name@domain.
func (s *DomainSpec) address(name string) string { return name + "@" + s.Domain }

func (s *DomainSpec) mailbox(name string) *Mailbox {
	for i := range s.Mailboxes {
		if s.Mailboxes[i].Name == name {
			return &s.Mailboxes[i]
		}
	}
	return nil
}

func domainFile(domain string) string { return domainsDir + "/" + domain + ".json" }

// loadDomains reads every stored domain, sorted by name.
func (m *Manager) loadDomains() ([]DomainSpec, error) {
	entries, err := os.ReadDir(m.h.Path(domainsDir))
	if errors.Is(err, os.ErrNotExist) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	var out []DomainSpec
	for _, e := range entries {
		name, ok := strings.CutSuffix(e.Name(), ".json")
		if !ok || validateDomain(name) != nil {
			continue
		}
		data, err := os.ReadFile(filepath.Join(m.h.Path(domainsDir), e.Name()))
		if err != nil {
			return nil, err
		}
		var d DomainSpec
		if err := json.Unmarshal(data, &d); err != nil {
			return nil, err
		}
		if d.Domain != name {
			continue
		}
		out = append(out, d)
	}
	sort.Slice(out, func(i, j int) bool { return out[i].Domain < out[j].Domain })
	return out, nil
}

// loadDomain reads one stored domain, or nil.
func (m *Manager) loadDomain(domain string) (*DomainSpec, error) {
	data, err := os.ReadFile(m.h.Path(domainFile(domain)))
	if errors.Is(err, os.ErrNotExist) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	var d DomainSpec
	if err := json.Unmarshal(data, &d); err != nil {
		return nil, err
	}
	return &d, nil
}
