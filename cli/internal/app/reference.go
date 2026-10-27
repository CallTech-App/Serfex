package app

import (
	"fmt"
	"strings"

	. "github.com/calltech-app/serfex/cli/internal/i18n"
)

// Reference writes the whole command tree as Markdown in the current
// language: docs/cli.md (English) and docs/tr/cli.md (Turkish) are this
// output, kept current by TestReferenceIsCurrent.
func Reference() string {
	root := tree()
	root.link()
	var b strings.Builder
	fmt.Fprintf(&b, "<!-- %s -->\n\n", T("Bu dosya üretilir: cd cli && go test ./internal/app -run TestReferenceIsCurrent -update"))
	fmt.Fprintf(&b, "# %s\n\n", T("Komut satırı başvurusu"))
	fmt.Fprintf(&b, "%s\n\n", paragraphs(root.Long))
	fmt.Fprintf(&b, "%s\n\n", T("Her komut `--help` ile kendi yardımını da gösterir: `servik sites create --help`."))

	fmt.Fprintf(&b, "## %s\n\n", T("Genel seçenekler"))
	fmt.Fprintf(&b, "%s\n\n", T("Bu seçenekler her komutta geçerlidir."))
	writeFlagTable(&b, globalFlags().list)

	var order []string
	groups := map[string][]*Command{}
	for _, s := range root.Subs {
		if s.Hidden {
			continue
		}
		if _, ok := groups[s.Group]; !ok {
			order = append(order, s.Group)
		}
		groups[s.Group] = append(groups[s.Group], s)
	}
	for _, g := range order {
		fmt.Fprintf(&b, "## %s\n\n", g)
		for _, s := range groups[g] {
			writeReference(&b, s)
		}
	}
	return strings.TrimRight(b.String(), "\n") + "\n"
}

func writeReference(b *strings.Builder, cmd *Command) {
	fmt.Fprintf(b, "### %s\n\n", cmd.path())
	fmt.Fprintf(b, "```\n%s\n```\n\n", cmd.synopsis())
	text := cmd.Long
	if text == "" {
		text = cmd.Short
	}
	if text != "" {
		fmt.Fprintf(b, "%s\n\n", paragraphs(text))
	}
	if len(cmd.Aliases) > 0 {
		fmt.Fprintf(b, "%s `%s`\n\n", T("Diğer adları:"), strings.Join(cmd.Aliases, "`, `"))
	}
	if subs := visible(cmd.Subs); len(subs) > 0 {
		fmt.Fprintf(b, "| %s | |\n| --- | --- |\n", T("Komut"))
		for _, s := range subs {
			fmt.Fprintf(b, "| [`%s`](#%s) | %s |\n", s.Name, anchor(s.path()), cell(s.Short))
		}
		b.WriteString("\n")
	}
	if own := cmd.ownFlags().list; len(own) > 0 {
		writeFlagTable(b, own)
	}
	if len(cmd.Examples) > 0 {
		fmt.Fprintf(b, "%s\n\n```sh\n%s\n```\n\n", T("Örnekler:"), strings.Join(cmd.Examples, "\n"))
	}
	for _, s := range visible(cmd.Subs) {
		writeReference(b, s)
	}
}

func writeFlagTable(b *strings.Builder, list []*flag) {
	fmt.Fprintf(b, "| %s | |\n| --- | --- |\n", T("Seçenek"))
	for _, fl := range list {
		left := "--" + fl.name
		if fl.short != "" {
			left = "-" + fl.short + ", " + left
		}
		if !fl.isBool {
			left += " <" + fl.value + ">"
		}
		fmt.Fprintf(b, "| `%s` | %s |\n", cell(left), cell(fl.usage))
	}
	b.WriteString("\n")
}

func visible(list []*Command) []*Command {
	var out []*Command
	for _, s := range list {
		if !s.Hidden {
			out = append(out, s)
		}
	}
	return out
}

// paragraphs turns help text, whose lines are sentences, into Markdown
// paragraphs.
func paragraphs(s string) string {
	var out []string
	for _, line := range strings.Split(s, "\n") {
		if line = strings.TrimSpace(line); line != "" {
			out = append(out, line)
		}
	}
	return strings.Join(out, "\n\n")
}

// cell escapes text for a Markdown table cell.
func cell(s string) string {
	return strings.ReplaceAll(strings.ReplaceAll(s, "|", `\|`), "\n", " ")
}

// anchor is GitHub's heading anchor for "servik sites list".
func anchor(heading string) string {
	return strings.ReplaceAll(strings.ToLower(heading), " ", "-")
}
