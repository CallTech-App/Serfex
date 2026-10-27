package app

import (
	"fmt"
	"sort"
	"strconv"
	"strings"

	. "github.com/calltech-app/serfex/cli/internal/i18n"
)

// Command is one word of the command tree: a group (Subs) or something
// that runs (Run).
type Command struct {
	Name    string
	Aliases []string
	// Args is the synopsis of the positional arguments: "<site> <name>".
	Args string
	// Short is the one line shown in lists, Long the help text.
	Short, Long string
	// Examples are shown under the help.
	Examples []string
	// Group puts the root's commands under headings.
	Group  string
	Hidden bool
	// MinArgs and MaxArgs bound the positional arguments (-1: any).
	MinArgs, MaxArgs int
	// Passthrough commands (artisan, composer, npm) give every argument
	// from the first positional one on to Run as it is.
	Passthrough bool
	Flags       func(f *Flags)
	Run         func(c *Ctx) error
	// Complete lists candidates for positional argument n (from 0).
	Complete func(c *Ctx, n int) []string
	Subs     []*Command

	parent *Command
	flags  *Flags
}

func (cmd *Command) path() string {
	if cmd.parent == nil {
		return cmd.Name
	}
	return cmd.parent.path() + " " + cmd.Name
}

func (cmd *Command) find(word string) *Command {
	for _, s := range cmd.Subs {
		if s.Name == word {
			return s
		}
		for _, a := range s.Aliases {
			if a == word {
				return s
			}
		}
	}
	return nil
}

func (cmd *Command) link() {
	for _, s := range cmd.Subs {
		s.parent = cmd
		if s.MaxArgs == 0 && s.Args != "" {
			s.MaxArgs = -1
		}
		s.link()
	}
}

// ownFlags are the flags the command defines (not the global ones).
func (cmd *Command) ownFlags() *Flags {
	if cmd.flags == nil {
		cmd.flags = newFlags()
		if cmd.Flags != nil {
			cmd.Flags(cmd.flags)
		}
	}
	return cmd.flags
}

// flag is one option: --name, -s.
type flag struct {
	name, short, value, usage string
	isBool, multi             bool
	def                       string
}

// Flags is a command's options and, once parsed, their values.
type Flags struct {
	list   []*flag
	values map[string][]string
}

func newFlags() *Flags { return &Flags{values: map[string][]string{}} }

// Bool adds --name (and -short) that takes no value.
func (f *Flags) Bool(name, short, usage string) {
	f.list = append(f.list, &flag{name: name, short: short, usage: usage, isBool: true})
}

// String adds --name <value>.
func (f *Flags) String(name, short, value, usage string) {
	f.list = append(f.list, &flag{name: name, short: short, value: value, usage: usage})
}

// Strings adds --name <value> that may be given more than once.
func (f *Flags) Strings(name, short, value, usage string) {
	f.list = append(f.list, &flag{name: name, short: short, value: value, usage: usage, multi: true})
}

func (f *Flags) lookup(name string, short bool) *flag {
	for _, fl := range f.list {
		if (!short && fl.name == name) || (short && fl.short != "" && fl.short == name) {
			return fl
		}
	}
	return nil
}

// parse reads args with the command's and the global flags mixed with
// positional arguments. A passthrough command stops reading flags at its
// first positional argument.
func parse(args []string, sets []*Flags, passthrough bool) ([]string, error) {
	var positional []string
	find := func(name string, short bool) (*flag, *Flags) {
		for _, s := range sets {
			if fl := s.lookup(name, short); fl != nil {
				return fl, s
			}
		}
		return nil, nil
	}
	for i := 0; i < len(args); i++ {
		a := args[i]
		if passthrough && len(positional) > 0 {
			positional = append(positional, args[i:]...)
			break
		}
		if a == "--" {
			positional = append(positional, args[i+1:]...)
			break
		}
		if len(a) < 2 || a[0] != '-' || a == "-" {
			positional = append(positional, a)
			continue
		}
		name, value, hasValue := a, "", false
		short := !strings.HasPrefix(a, "--")
		name = strings.TrimLeft(name, "-")
		if k, v, ok := strings.Cut(name, "="); ok {
			name, value, hasValue = k, v, true
		}
		fl, set := find(name, short)
		if fl == nil && !short && strings.HasPrefix(name, "no-") {
			if b, s := find(strings.TrimPrefix(name, "no-"), false); b != nil && b.isBool {
				s.values[b.name] = []string{"false"}
				continue
			}
		}
		if fl == nil {
			return nil, usageErr(T("Bilinmeyen seçenek: %s", a))
		}
		if fl.isBool {
			if hasValue {
				if _, err := strconv.ParseBool(value); err != nil {
					return nil, usageErr(T("%s için true ya da false yazın.", a))
				}
			} else {
				value = "true"
			}
			set.values[fl.name] = []string{value}
			continue
		}
		if !hasValue {
			if i+1 >= len(args) {
				return nil, usageErr(T("%s bir değer bekliyor.", a))
			}
			i++
			value = args[i]
		}
		if fl.multi {
			set.values[fl.name] = append(set.values[fl.name], value)
		} else {
			set.values[fl.name] = []string{value}
		}
	}
	return positional, nil
}

// synopsis is the usage line: serfex sites create <domain> [options].
func (cmd *Command) synopsis() string {
	synopsis := "serfex"
	if cmd.parent != nil {
		synopsis = "serfex " + strings.TrimPrefix(cmd.path(), "serfex ")
	}
	if len(cmd.Subs) > 0 && cmd.Run == nil {
		synopsis += " " + T("<komut>")
	}
	if cmd.Args != "" {
		synopsis += " " + cmd.Args
	}
	if len(cmd.ownFlags().list) > 0 || cmd.parent == nil {
		synopsis += " " + T("[seçenekler]")
	}
	return synopsis
}

// help writes a command's help.
func (cmd *Command) help(c *Ctx) string {
	var b strings.Builder
	fmt.Fprintf(&b, "%s %s\n", c.bold(T("Kullanım:")), cmd.synopsis())
	text := cmd.Long
	if text == "" {
		text = cmd.Short
	}
	if text != "" {
		fmt.Fprintf(&b, "\n%s\n", text)
	}
	if len(cmd.Subs) > 0 {
		groups := map[string][]*Command{}
		var order []string
		for _, s := range cmd.Subs {
			if s.Hidden {
				continue
			}
			if _, ok := groups[s.Group]; !ok {
				order = append(order, s.Group)
			}
			groups[s.Group] = append(groups[s.Group], s)
		}
		width := 0
		for _, s := range cmd.Subs {
			width = max(width, len(s.Name))
		}
		for _, g := range order {
			heading := T("Komutlar:")
			if g != "" {
				heading = g + ":"
			}
			fmt.Fprintf(&b, "\n%s\n", c.bold(heading))
			for _, s := range groups[g] {
				fmt.Fprintf(&b, "  %-*s  %s\n", width, s.Name, s.Short)
			}
		}
	}
	if own := cmd.ownFlags().list; len(own) > 0 {
		fmt.Fprintf(&b, "\n%s\n", c.bold(T("Seçenekler:")))
		writeFlags(&b, own)
	}
	if cmd.parent == nil {
		fmt.Fprintf(&b, "\n%s\n", c.bold(T("Genel seçenekler:")))
		writeFlags(&b, globalFlags().list)
	}
	if len(cmd.Examples) > 0 {
		fmt.Fprintf(&b, "\n%s\n", c.bold(T("Örnekler:")))
		for _, e := range cmd.Examples {
			fmt.Fprintf(&b, "  %s\n", e)
		}
	}
	if len(cmd.Subs) > 0 {
		fmt.Fprintf(&b, "\n%s\n", T("Bir komutun ayrıntıları için: %s <komut> --help", strings.TrimSpace("serfex "+strings.TrimPrefix(cmd.path(), "serfex"))))
	} else if cmd.parent != nil {
		fmt.Fprintf(&b, "\n%s\n", T("Genel seçenekler (--json, --profile, --lang …) için: serfex --help"))
	}
	return b.String()
}

func writeFlags(b *strings.Builder, list []*flag) {
	type row struct{ left, usage string }
	var rows []row
	width := 0
	for _, fl := range list {
		left := "--" + fl.name
		if fl.short != "" {
			left = "-" + fl.short + ", " + left
		} else {
			left = "    " + left
		}
		if !fl.isBool {
			left += " <" + fl.value + ">"
		}
		rows = append(rows, row{left, fl.usage})
		width = max(width, len(left))
	}
	for _, r := range rows {
		fmt.Fprintf(b, "  %-*s  %s\n", width, r.left, r.usage)
	}
}

// names lists the visible subcommands, for completion.
func (cmd *Command) names() []string {
	var out []string
	for _, s := range cmd.Subs {
		if !s.Hidden {
			out = append(out, s.Name)
		}
	}
	sort.Strings(out)
	return out
}
