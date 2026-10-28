package backup

import (
	"bufio"
	"io"
	"strings"
)

// cpYAML is a cPanel userdata file: the small part of YAML cPanel writes
// there (top-level keys holding a scalar, a list of scalars or a map of
// scalars). Lists are []string, maps map[string]string.
type cpYAML map[string]any

func parseCPYAML(r io.Reader) cpYAML {
	out := cpYAML{}
	sc := bufio.NewScanner(r)
	sc.Buffer(make([]byte, 64<<10), 1<<20)
	key := ""
	for sc.Scan() {
		line := strings.TrimRight(sc.Text(), " \r")
		trimmed := strings.TrimSpace(line)
		if trimmed == "" || trimmed == "---" || strings.HasPrefix(trimmed, "#") {
			continue
		}
		if line[0] != ' ' && line[0] != '-' {
			k, v, ok := strings.Cut(line, ":")
			if !ok {
				key = ""
				continue
			}
			key = yamlScalar(k)
			v = strings.TrimSpace(v)
			switch v {
			case "", "[]", "{}", "~":
				out[key] = nil
			default:
				out[key] = yamlScalar(v)
			}
			continue
		}
		if key == "" {
			continue
		}
		if item, ok := strings.CutPrefix(trimmed, "- "); ok || trimmed == "-" {
			list, _ := out[key].([]string)
			out[key] = append(list, yamlScalar(item))
			continue
		}
		if k, v, ok := strings.Cut(trimmed, ":"); ok {
			m, _ := out[key].(map[string]string)
			if m == nil {
				m = map[string]string{}
			}
			m[yamlScalar(k)] = yamlScalar(strings.TrimSpace(v))
			out[key] = m
		}
	}
	return out
}

func yamlScalar(s string) string {
	s = strings.TrimSpace(s)
	if len(s) >= 2 && s[0] == '\'' && s[len(s)-1] == '\'' {
		return strings.ReplaceAll(s[1:len(s)-1], "''", "'")
	}
	if len(s) >= 2 && s[0] == '"' && s[len(s)-1] == '"' {
		return strings.ReplaceAll(strings.ReplaceAll(s[1:len(s)-1], `\"`, `"`), `\\`, `\`)
	}
	return s
}

func (y cpYAML) str(k string) string {
	s, _ := y[k].(string)
	return s
}

func (y cpYAML) list(k string) []string {
	switch v := y[k].(type) {
	case []string:
		return v
	case string:
		if v != "" {
			return []string{v}
		}
	}
	return nil
}

func (y cpYAML) dict(k string) map[string]string {
	m, _ := y[k].(map[string]string)
	return m
}
