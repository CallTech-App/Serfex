package app

import (
	"strings"
	"time"

	. "github.com/winchester37/servik/cli/internal/i18n"
)

// tree is every command, built after the language is known.
func tree() *Command {
	root := &Command{
		Name: "servik",
		Long: T("Servik komut satırı: siteler, alan adları, DNS, SSL, e-posta, veritabanları, yedekler, hizmetler ve Laravel uygulamaları.\nÖnce bir panele giriş yapın: servik login panel.ornek.com.tr:8443"),
	}
	root.Subs = append(root.Subs, accountCommands()...)
	root.Subs = append(root.Subs, siteCommands(), domainCommands(), dnsCommands(), sslCommands(), mailCommands(), dbCommands(), backupCommands())
	root.Subs = append(root.Subs, serviceCommands(), customerCommands())
	root.Subs = append(root.Subs, laravelCommands()...)
	root.Subs = append(root.Subs, completionCommand(), versionCommand(), completeCommand())
	return root
}

func completionCommand() *Command {
	return &Command{
		Name: "completion", Group: T("Diğer"), Args: "bash|zsh|fish", MinArgs: 1, MaxArgs: 1,
		Short: T("Kabuk tamamlama betiğini yazar"),
		Long:  T("Kabuk tamamlama betiğini yazar. Komutlar, seçenekler ve siteler, uygulamalar gibi adlar Tab ile tamamlanır."),
		Examples: []string{
			"source <(servik completion bash)",
			"servik completion zsh > \"${fpath[1]}/_servik\"",
			"servik completion fish > ~/.config/fish/completions/servik.fish",
		},
		Complete: func(c *Ctx, n int) []string {
			if n > 0 {
				return nil
			}
			return []string{"bash", "zsh", "fish"}
		},
		Run: func(c *Ctx) error {
			script, ok := completionScripts[c.Args[0]]
			if !ok {
				return usageErr(T("bash, zsh ya da fish yazın."))
			}
			c.printf("%s", script)
			return nil
		},
	}
}

var completionScripts = map[string]string{
	"bash": `# servik bash completion
_servik() {
	local IFS=$'\n'
	COMPREPLY=($(compgen -W "$(servik __complete -- "${COMP_WORDS[@]:1:COMP_CWORD}" 2>/dev/null)" -- "${COMP_WORDS[COMP_CWORD]}"))
}
complete -o default -F _servik servik
`,
	"zsh": `#compdef servik
# servik zsh completion
_servik() {
	local -a items
	items=("${(@f)$(servik __complete -- "${(@)words[2,CURRENT]}" 2>/dev/null)}")
	compadd -a items
}
if [ "$funcstack[1]" = "_servik" ]; then
	_servik "$@"
else
	compdef _servik servik
fi
`,
	"fish": `# servik fish completion
function __servik_complete
	set -l tokens (commandline -opc) (commandline -ct)
	servik __complete -- $tokens[2..-1] 2>/dev/null
end
complete -c servik -f -a '(__servik_complete)'
`,
}

// completeCommand answers the completion scripts: given the words so far
// (the last one being typed), it lists what may come next.
func completeCommand() *Command {
	return &Command{
		Name: "__complete", Hidden: true, Args: "…", MaxArgs: -1, Passthrough: true,
		Run: func(c *Ctx) error {
			for _, s := range candidates(c, c.Args) {
				c.println(s)
			}
			return nil
		},
	}
}

func candidates(c *Ctx, words []string) []string {
	if len(words) == 0 {
		words = []string{""}
	}
	current := words[len(words)-1]
	cmd := tree()
	cmd.link()
	global := globalFlags()
	var positional []string
	for i := 0; i < len(words)-1; i++ {
		w := words[i]
		if strings.HasPrefix(w, "-") {
			name, short := strings.TrimLeft(w, "-"), !strings.HasPrefix(w, "--")
			fl := cmd.ownFlags().lookup(name, short)
			if fl == nil {
				fl = global.lookup(name, short)
			}
			if fl != nil && !fl.isBool && !strings.Contains(w, "=") {
				i++
			}
			continue
		}
		if sub := cmd.find(w); sub != nil && len(positional) == 0 && !cmd.Passthrough {
			cmd = sub
			continue
		}
		positional = append(positional, w)
	}
	// After a flag that takes a value: its values, when known.
	if len(words) >= 2 {
		prev := words[len(words)-2]
		if prev == "--app" || prev == "-a" {
			return withTimeout(c, completeApps)
		}
		if prev == "--profile" {
			return completeProfiles(c, 0)
		}
		if prev == "--lang" {
			return []string{"tr", "en"}
		}
	}
	var out []string
	if strings.HasPrefix(current, "-") {
		for _, set := range []*Flags{cmd.ownFlags(), global} {
			for _, fl := range set.list {
				out = append(out, "--"+fl.name)
			}
		}
		return out
	}
	if cmd.Passthrough {
		return nil
	}
	if len(cmd.Subs) > 0 && len(positional) == 0 {
		out = cmd.names()
	}
	if cmd.Complete != nil {
		c.cmd = cmd
		out = append(out, withTimeout(c, func(c *Ctx) []string { return cmd.Complete(c, len(positional)) })...)
	}
	return out
}

// withTimeout keeps a Tab press from hanging on a slow panel.
func withTimeout(c *Ctx, f func(c *Ctx) []string) []string {
	done := make(chan []string, 1)
	go func() {
		defer func() {
			if recover() != nil {
				done <- nil
			}
		}()
		done <- f(c)
	}()
	select {
	case r := <-done:
		return r
	case <-time.After(3 * time.Second):
		return nil
	}
}
