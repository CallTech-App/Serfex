package site

import (
	"fmt"
	"regexp"
	"strings"

	"github.com/winchester37/servik/agent/internal/hostname"
)

// ValidationError reports a request that can never succeed as given.
type ValidationError struct{ msg string }

func (e *ValidationError) Error() string { return e.msg }

func invalid(format string, a ...any) error { return &ValidationError{fmt.Sprintf(format, a...)} }

var (
	userRe    = regexp.MustCompile(`^[a-z][a-z0-9_-]{0,31}$`)
	phpVerRe  = regexp.MustCompile(`^[5-9]\.[0-9]{1,2}$`)
	maxAlias  = 20
	reserved  = map[string]bool{}
	reservedL = "root daemon bin sys sync games man lp mail news uucp proxy www-data backup list irc gnats nobody " +
		"systemd-network systemd-resolve systemd-timesync messagebus syslog ubuntu admin administrator " +
		"nginx apache httpd postgres mysql redis servik servik-agent ftp sshd dovecot postfix pdns named " +
		"bind clamav debian-exim _apt tss uuidd tcpdump landscape fwupd-refresh polkitd"
)

func init() {
	for _, n := range strings.Fields(reservedL) {
		reserved[n] = true
	}
}

// ValidateDomain checks a lowercase ASCII (punycode) host name with at
// least two labels.
func ValidateDomain(d string) error {
	if err := hostname.Validate(d); err != nil {
		return invalid("%s", err.Error())
	}
	return nil
}

// ValidateUser checks a system account name Servik may create.
func ValidateUser(u string) error {
	if !userRe.MatchString(u) {
		return invalid("user %q must start with a letter and contain only a-z, 0-9, - or _ (max 32)", u)
	}
	if reserved[u] {
		return invalid("user %q is reserved for the system", u)
	}
	return nil
}

var relPathRe = regexp.MustCompile(`^[A-Za-z0-9_][A-Za-z0-9._-]*(/[A-Za-z0-9_][A-Za-z0-9._-]*)*$`)

// ValidateRelPath checks a path inside a site directory, such as
// "laravel/current/public": relative, no "..", no hidden first letters.
func ValidateRelPath(p string) error {
	if len(p) > 200 || !relPathRe.MatchString(p) {
		return invalid("%q must be a relative path such as laravel/current/public", p)
	}
	for _, part := range strings.Split(p, "/") {
		if part == "." || part == ".." {
			return invalid("%q must not contain . or ..", p)
		}
	}
	return nil
}

// ValidatePHPVersion checks the shape of a PHP version such as "8.4".
func ValidatePHPVersion(v string) error {
	if !phpVerRe.MatchString(v) {
		return invalid("php_version %q must look like 8.4", v)
	}
	return nil
}

func (s Spec) validate() error {
	if err := ValidateDomain(s.Domain); err != nil {
		return err
	}
	if err := ValidateUser(s.User); err != nil {
		return err
	}
	if err := ValidatePHPVersion(s.PHPVersion); err != nil {
		return err
	}
	if s.WebRoot != "" {
		if err := ValidateRelPath(s.WebRoot); err != nil {
			return invalid("web_root: %s", err.Error())
		}
	}
	if s.TLS != nil && s.TLS.Certificate == "" {
		return invalid("tls.certificate is required")
	}
	if err := s.validateWeb(); err != nil {
		return err
	}
	if len(s.Aliases) > maxAlias {
		return invalid("at most %d aliases are allowed", maxAlias)
	}
	seen := map[string]bool{s.Domain: true}
	for _, a := range s.Aliases {
		if err := ValidateDomain(a); err != nil {
			return err
		}
		if seen[a] {
			return invalid("alias %q is listed twice or equals the domain", a)
		}
		seen[a] = true
	}
	return nil
}
