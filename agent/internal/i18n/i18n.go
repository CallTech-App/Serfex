// Package i18n puts the agent's messages for people (progress logs and
// the few explanations shown as they are) in the panel user's language.
//
// Turkish is the source language: messages are written in Turkish in the
// code, and catalog.go holds their English. The panel sends its user's
// language with each request ("locale", next to "method"); rpc puts it in
// the context. Errors meant for the panel's own code stay in English.
package i18n

import (
	"context"
	"fmt"
)

type localeKey struct{}

// Default is the language of messages when the request names none.
const Default = "tr"

// WithLocale returns ctx carrying locale ("tr" or "en"). Unknown or empty
// values keep Turkish.
func WithLocale(ctx context.Context, locale string) context.Context {
	if _, ok := catalogs[locale]; !ok {
		locale = Default
	}
	return context.WithValue(ctx, localeKey{}, locale)
}

// Locale is the language ctx carries.
func Locale(ctx context.Context) string {
	if ctx != nil {
		if l, ok := ctx.Value(localeKey{}).(string); ok {
			return l
		}
	}
	return Default
}

// Translate returns the message (a Turkish fmt format) in ctx's language.
func Translate(ctx context.Context, message string) string {
	return In(Locale(ctx), message)
}

// In returns the message in locale, or the message itself when the
// catalog has no translation.
func In(locale, message string) string {
	if c, ok := catalogs[locale]; ok && c != nil {
		if t, ok := c[message]; ok {
			return t
		}
	}
	return message
}

// T formats a Turkish fmt format in ctx's language. Without arguments it
// only translates, so the result may still be used as a format.
func T(ctx context.Context, format string, a ...any) string {
	if len(a) == 0 {
		return Translate(ctx, format)
	}
	return fmt.Sprintf(Translate(ctx, format), a...)
}

// catalogs holds each language's translations; Turkish needs none.
var catalogs = map[string]map[string]string{
	"tr": nil,
	"en": english,
}
