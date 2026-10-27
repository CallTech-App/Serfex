// Package i18n puts the command line's messages in the user's language.
//
// Turkish is the source language, as in the panel and the agent: messages
// are written in Turkish in the code and catalog.go holds their English.
// The language comes from --lang, SERFEX_LANG or the locale (LC_ALL,
// LC_MESSAGES, LANG): Turkish for tr_*, English otherwise.
package i18n

import (
	"fmt"
	"strings"
)

// locale is the language of this run: "tr" or "en".
var locale = "en"

// Detect picks the language from an explicit choice (--lang) and the
// environment.
func Detect(choice string, getenv func(string) string) string {
	for _, v := range []string{choice, getenv("SERFEX_LANG"), getenv("LC_ALL"), getenv("LC_MESSAGES"), getenv("LANG")} {
		v = strings.ToLower(strings.TrimSpace(v))
		if v == "" || v == "c" || v == "posix" || strings.HasPrefix(v, "c.") {
			continue
		}
		if strings.HasPrefix(v, "tr") {
			return "tr"
		}
		return "en"
	}
	return "en"
}

// SetLocale sets the language of the messages; anything but "tr" is English.
func SetLocale(l string) {
	if l != "tr" {
		l = "en"
	}
	locale = l
}

// Locale is the language of the messages.
func Locale() string { return locale }

// T formats a Turkish fmt format in the current language. Without
// arguments it only translates.
func T(format string, a ...any) string {
	if locale == "en" {
		if t, ok := english[format]; ok {
			format = t
		}
	}
	if len(a) == 0 {
		return format
	}
	return fmt.Sprintf(format, a...)
}
