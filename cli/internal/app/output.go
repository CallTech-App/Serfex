package app

import (
	"bufio"
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"math"
	"regexp"
	"strconv"
	"strings"
	"time"
	"unicode"
	"unicode/utf8"

	"github.com/calltech-app/serfex/cli/internal/api"
	. "github.com/calltech-app/serfex/cli/internal/i18n"
)

func (c *Ctx) paint(code, s string) string {
	if !c.color || s == "" {
		return s
	}
	return "\033[" + code + "m" + s + "\033[0m"
}

func (c *Ctx) bold(s string) string   { return c.paint("1", s) }
func (c *Ctx) dim(s string) string    { return c.paint("2", s) }
func (c *Ctx) green(s string) string  { return c.paint("32", s) }
func (c *Ctx) red(s string) string    { return c.paint("31", s) }
func (c *Ctx) yellow(s string) string { return c.paint("33", s) }

// printf writes to stdout.
func (c *Ctx) printf(format string, a ...any) { fmt.Fprintf(c.env.Stdout, format, a...) }

// println writes a line to stdout.
func (c *Ctx) println(a ...any) { fmt.Fprintln(c.env.Stdout, a...) }

// note writes a line for people to stderr, so stdout stays the result.
func (c *Ctx) note(format string, a ...any) {
	fmt.Fprintf(c.env.Stderr, format+"\n", a...)
}

func (c *Ctx) errorf(format string, a ...any) {
	fmt.Fprintf(c.env.Stderr, "%s %s\n", c.red("serfex:"), fmt.Sprintf(format, a...))
}

// done says a change was made: "✓ message", or the answer as JSON.
func (c *Ctx) done(resp *api.Response, message string) error {
	if c.json {
		if resp == nil || len(bytes.TrimSpace(resp.Body)) == 0 {
			return c.printJSON(json.RawMessage(`{"ok":true}`))
		}
		return c.printJSON(resp.Data())
	}
	if message == "" && resp != nil {
		message = resp.Message()
	}
	if message != "" {
		c.println(c.green("✓") + " " + message)
	}
	return nil
}

// printJSON writes raw JSON indented.
func (c *Ctx) printJSON(raw json.RawMessage) error {
	var buf bytes.Buffer
	if err := json.Indent(&buf, raw, "", "  "); err != nil {
		_, err := c.env.Stdout.Write(raw)
		return err
	}
	buf.WriteByte('\n')
	_, err := buf.WriteTo(c.env.Stdout)
	return err
}

// show prints the answer as JSON with --json, else calls render with
// the decoded value.
func show[T any](c *Ctx, resp *api.Response, render func(v T) error) error {
	if c.json {
		return c.printJSON(resp.Data())
	}
	var v T
	if err := resp.Decode(&v); err != nil {
		return err
	}
	return render(v)
}

// table writes rows under headings, aligned. Headings are uppercased by
// the language's rules (SİTE); a word written in capitals already, like a
// loanword (COMMIT), is left as it is.
func (c *Ctx) table(headings []string, rows [][]string) {
	if len(rows) == 0 {
		return
	}
	h := make([]string, len(headings))
	for i, s := range headings {
		if Locale() == "tr" {
			h[i] = strings.ToUpperSpecial(unicode.TurkishCase, s)
		} else {
			h[i] = strings.ToUpper(s)
		}
	}
	// Widths are counted without colors (tabwriter would count them).
	widths := make([]int, len(h))
	for _, r := range append([][]string{h}, rows...) {
		for i, cell := range r {
			if i < len(widths) {
				widths[i] = max(widths[i], textWidth(cell))
			}
		}
	}
	line := func(cells []string, paint func(string) string) {
		var b strings.Builder
		for i, cell := range cells {
			if i == len(cells)-1 {
				b.WriteString(paint(cell))
				break
			}
			b.WriteString(paint(cell) + strings.Repeat(" ", widths[i]-textWidth(cell)+2))
		}
		c.println(b.String())
	}
	line(h, c.dim)
	for _, r := range rows {
		line(r, func(s string) string { return s })
	}
}

// textWidth is how many columns s takes on a terminal, colors left out.
func textWidth(s string) int {
	return utf8.RuneCountInString(ansi.ReplaceAllString(s, ""))
}

var ansi = regexp.MustCompile("\x1b\\[[0-9;]*m")

// pairs writes label: value lines, aligned.
func (c *Ctx) pairs(rows [][2]string) {
	width := 0
	for _, r := range rows {
		width = max(width, utf8.RuneCountInString(r[0]))
	}
	for _, r := range rows {
		if r[1] == "" {
			continue
		}
		pad := strings.Repeat(" ", width-utf8.RuneCountInString(r[0]))
		c.printf("%s%s  %s\n", c.dim(r[0]+":"), pad, r[1])
	}
}

// empty says a list has nothing in it (stderr, so `| wc -l` is 0).
func (c *Ctx) empty(message string) { c.note("%s", c.dim(message)) }

// toneOf colors a state by what it means: working, waiting or failing.
func (c *Ctx) toneOf(state, text string) string {
	switch state {
	case "active", "running", "succeeded", "issued", "approved", "done", "ok", "success", "enabled", "valid":
		return c.green(text)
	case "failed", "error", "danger", "unhealthy", "rolled_back", "suspended", "expired", "inactive", "dead":
		return c.red(text)
	case "provisioning", "queued", "pending", "syncing", "issuing", "waiting_dns", "deleting", "installing", "removing", "warning", "activating", "reloading", "deactivating":
		return c.yellow(text)
	}
	return text
}

// status is a state's name for people, colored.
func (c *Ctx) status(state string) string {
	labels := map[string]string{
		"active":       T("Etkin"),
		"provisioning": T("Hazırlanıyor"),
		"failed":       T("Hata"),
		"deleting":     T("Siliniyor"),
		"syncing":      T("Uygulanıyor"),
		"waiting_dns":  T("DNS bekleniyor"),
		"issuing":      T("Alınıyor"),
		"installing":   T("Kuruluyor"),
		"removing":     T("Kaldırılıyor"),
		"queued":       T("Sırada"),
		"running":      T("Sürüyor"),
		"succeeded":    T("Başarılı"),
		"rolled_back":  T("Geri dönüldü"),
		"unhealthy":    T("Sağlıksız"),
		"inactive":     T("Durmuş"),
		"finished":     T("Bitti"),
		"done":         T("Bitti"),
		"deleted":      T("Silindi"),
		"partial":      T("Kısmen"),
	}
	text, ok := labels[state]
	if !ok {
		text = state
	}
	return c.toneOf(state, text)
}

// yesNo is "evet" or "hayır".
func yesNo(b bool) string {
	if b {
		return T("evet")
	}
	return T("hayır")
}

// orDash is s, or "—" when empty.
func orDash(s string) string {
	if strings.TrimSpace(s) == "" {
		return "—"
	}
	return s
}

// when writes an ISO time in the computer's zone.
func when(iso string) string {
	if iso == "" {
		return "—"
	}
	t, err := time.Parse(time.RFC3339, iso)
	if err != nil {
		return iso
	}
	return t.Local().Format("2006-01-02 15:04")
}

// bytesText writes a size: 1,2 GB in Turkish, 1.2 GB in English.
func bytesText(n float64) string {
	if n <= 0 {
		return "—"
	}
	units := []string{"B", "KB", "MB", "GB", "TB"}
	i := 0
	for n >= 1024 && i < len(units)-1 {
		n /= 1024
		i++
	}
	s := strconv.FormatFloat(math.Round(n*10)/10, 'f', -1, 64)
	if i == 0 {
		s = strconv.FormatFloat(n, 'f', 0, 64)
	}
	if Locale() == "tr" {
		s = strings.Replace(s, ".", ",", 1)
	}
	return s + " " + units[i]
}

// confirm asks a yes/no question; --yes answers it. Without a terminal
// the question cannot be asked, so the command stops.
func (c *Ctx) confirm(question string) error {
	if c.yes {
		return nil
	}
	if !c.env.Interactive {
		return usageErr(T("%s Onay sorulamıyor; emin olduğunuzda --yes ekleyin.", question))
	}
	fmt.Fprintf(c.env.Stderr, "%s %s ", question, T("[e/H]"))
	answer, _ := bufio.NewReader(c.env.Stdin).ReadString('\n')
	answer = strings.ToLower(strings.TrimSpace(answer))
	if answer == "e" || answer == "evet" || answer == "y" || answer == "yes" {
		return nil
	}
	return exitCode(1)
}

// confirmTyped asks the person to type word (a domain before deleting
// its site), as the panel does.
func (c *Ctx) confirmTyped(question, word string) error {
	if c.yes {
		return nil
	}
	if !c.env.Interactive {
		return usageErr(T("%s Onay sorulamıyor; emin olduğunuzda --yes ekleyin.", question))
	}
	fmt.Fprintf(c.env.Stderr, "%s\n%s ", question, T("Onaylamak için %s yazın:", c.bold(word)))
	answer, _ := bufio.NewReader(c.env.Stdin).ReadString('\n')
	if strings.TrimSpace(answer) != word {
		c.note("%s", T("Vazgeçildi."))
		return exitCode(1)
	}
	return nil
}

// readSecret reads a password from stdin (--password-stdin).
func readSecret(r io.Reader) (string, error) {
	data, err := io.ReadAll(io.LimitReader(r, 4096))
	if err != nil {
		return "", err
	}
	s := strings.TrimRight(string(data), "\r\n")
	if s == "" {
		return "", usageErr(T("Standart girdiden parola okunamadı."))
	}
	return s, nil
}
