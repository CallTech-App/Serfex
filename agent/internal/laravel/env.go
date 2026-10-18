package laravel

import (
	"context"
	"regexp"
	"sort"
	"strings"
)

// The .env file. The panel edits it as text; the agent only fills in
// defaults on the first deploy and caches the configuration after a save.

var envKeyRe = regexp.MustCompile(`^[A-Za-z_][A-Za-z0-9_]*$`)

const maxEnv = 256 << 10

// parseEnv reads KEY=VALUE lines, without interpolation.
func parseEnv(content string) map[string]string {
	out := map[string]string{}
	for _, line := range strings.Split(content, "\n") {
		k, v, ok := envLine(line)
		if ok {
			out[k] = unquoteEnv(v)
		}
	}
	return out
}

func envLine(line string) (key, value string, ok bool) {
	line = strings.TrimSpace(line)
	line = strings.TrimPrefix(line, "export ")
	if line == "" || strings.HasPrefix(line, "#") {
		return "", "", false
	}
	k, v, ok := strings.Cut(line, "=")
	k = strings.TrimSpace(k)
	if !ok || !envKeyRe.MatchString(k) {
		return "", "", false
	}
	return k, strings.TrimSpace(v), true
}

func unquoteEnv(v string) string {
	if len(v) >= 2 && v[0] == '\'' {
		if i := strings.IndexByte(v[1:], '\''); i >= 0 {
			return v[1 : i+1]
		}
	}
	if len(v) >= 2 && v[0] == '"' {
		var b strings.Builder
		for i := 1; i < len(v); i++ {
			switch {
			case v[i] == '\\' && i+1 < len(v):
				i++
				if v[i] == 'n' {
					b.WriteByte('\n')
				} else {
					b.WriteByte(v[i])
				}
			case v[i] == '"':
				return b.String()
			default:
				b.WriteByte(v[i])
			}
		}
	}
	if i := strings.Index(v, " #"); i >= 0 {
		v = strings.TrimSpace(v[:i])
	}
	return v
}

// quoteEnv writes a value so that phpdotenv reads it back unchanged.
func quoteEnv(v string) string {
	if v == "" || regexp.MustCompile(`^[A-Za-z0-9_./:@+,=-]+$`).MatchString(v) {
		return v
	}
	if !strings.Contains(v, "'") && !strings.Contains(v, "\n") {
		return "'" + v + "'"
	}
	return `"` + strings.NewReplacer(`\`, `\\`, `"`, `\"`, "\n", `\n`, "$", `\$`).Replace(v) + `"`
}

// setEnv sets each key: an existing line is changed in place, a missing
// key is appended.
func setEnv(content string, vals map[string]string) string {
	lines := strings.Split(strings.TrimRight(content, "\n"), "\n")
	if content == "" {
		lines = nil
	}
	done := map[string]bool{}
	for i, line := range lines {
		k, _, ok := envLine(line)
		if v, want := vals[k]; ok && want {
			lines[i] = k + "=" + quoteEnv(v)
			done[k] = true
		}
		// A commented-out default such as "# DB_HOST=127.0.0.1" is switched on.
		if !ok {
			trimmed := strings.TrimSpace(strings.TrimPrefix(strings.TrimSpace(line), "#"))
			if k, _, ok := envLine(trimmed); ok && !done[k] {
				if v, want := vals[k]; want {
					lines[i] = k + "=" + quoteEnv(v)
					done[k] = true
				}
			}
		}
	}
	var missing []string
	for k := range vals {
		if !done[k] {
			missing = append(missing, k)
		}
	}
	sort.Strings(missing)
	if len(missing) > 0 && len(lines) > 0 {
		lines = append(lines, "")
	}
	for _, k := range missing {
		lines = append(lines, k+"="+quoteEnv(vals[k]))
	}
	return strings.Join(lines, "\n") + "\n"
}

// EnvFiles are an application's .env and .env.example; nil when missing.
type EnvFiles struct {
	Env     *string `json:"env"`
	Example *string `json:"example"`
}

// GetEnv returns the application's .env and .env.example.
func (m *Manager) GetEnv(ctx context.Context, a App) (EnvFiles, error) {
	if err := a.validate(); err != nil {
		return EnvFiles{}, err
	}
	var out EnvFiles
	for name, dst := range map[string]**string{".env": &out.Env, ".env.example": &out.Example} {
		p := a.dir() + "/" + name
		if !m.exists(p) {
			continue
		}
		b, err := m.readFile(ctx, a.Target, p, maxEnv)
		if err != nil {
			return EnvFiles{}, &OperationError{err}
		}
		s := string(b)
		*dst = &s
	}
	return out, nil
}

// EnvSpec replaces an application's .env.
type EnvSpec struct {
	App
	Content string `json:"content"`
}

// EnvResult says what happened after the .env was saved.
type EnvResult struct {
	// Cached is true when the configuration cache was rebuilt.
	Cached bool   `json:"cached"`
	Output string `json:"output"`
	// Restarted lists the queue workers restarted to read the new values.
	Restarted bool `json:"restarted"`
}

// SetEnv writes the .env as the site's account (mode 0600), rebuilds the
// configuration cache when the application uses one, and restarts its
// queue workers, which hold the configuration in memory.
func (m *Manager) SetEnv(ctx context.Context, spec EnvSpec) (EnvResult, error) {
	if err := spec.App.validate(); err != nil {
		return EnvResult{}, err
	}
	if len(spec.Content) > maxEnv {
		return EnvResult{}, invalid(".env must be smaller than 256 KB")
	}
	if err := m.requireApp(spec.App); err != nil {
		return EnvResult{}, err
	}
	content := spec.Content
	if content != "" && !strings.HasSuffix(content, "\n") {
		content += "\n"
	}
	if err := m.writeFile(ctx, spec.Target, spec.dir()+"/.env", []byte(content), "600"); err != nil {
		return EnvResult{}, &OperationError{err}
	}
	var res EnvResult
	if m.exists(spec.dir() + "/bootstrap/cache/config.php") {
		out, err := m.userRun(ctx, spec.Target, spec.dir(), phpBin(spec.PHPVersion), "artisan", "config:cache")
		res.Output = strings.TrimSpace(out)
		if err != nil {
			return res, failed("the .env was saved, but php artisan config:cache failed: %s", firstLine(out))
		}
		res.Cached = true
	}
	res.Restarted = m.restartWorkers(ctx, spec.Domain) == nil
	return res, nil
}
