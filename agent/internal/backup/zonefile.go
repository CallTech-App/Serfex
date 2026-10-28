package backup

import (
	"bufio"
	"io"
	"strconv"
	"strings"
)

// zoneRR is one resource record of a BIND zone file.
type zoneRR struct {
	// Name is absolute, lower case, without the trailing dot.
	Name string
	TTL  int
	Type string
	// Data are the record's fields; quoted strings are unquoted.
	Data []string
}

// parseZone reads a BIND zone file (the form cPanel keeps its zones in):
// $ORIGIN and $TTL, owners left out to repeat the previous one, "@",
// names relative to the origin, records split over lines in parentheses,
// quoted strings and ; comments. Lines it cannot read are left out.
func parseZone(r io.Reader, origin string) []zoneRR {
	origin = strings.TrimSuffix(strings.ToLower(origin), ".")
	ttl := 14400
	var out []zoneRR
	prev := origin
	sc := bufio.NewScanner(r)
	sc.Buffer(make([]byte, 64<<10), 1<<20)
	var pending []string
	depth := 0
	startsBlank := false
	for sc.Scan() {
		line := sc.Text()
		toks, opens, closes := zoneTokens(line)
		if depth == 0 {
			startsBlank = line != "" && (line[0] == ' ' || line[0] == '\t')
			pending = toks
		} else {
			pending = append(pending, toks...)
		}
		depth += opens - closes
		if depth > 0 {
			continue
		}
		depth = 0
		toks = pending
		pending = nil
		if len(toks) == 0 {
			continue
		}
		switch strings.ToUpper(toks[0]) {
		case "$TTL":
			if len(toks) > 1 {
				if n, ok := zoneTTL(toks[1]); ok {
					ttl = n
				}
			}
			continue
		case "$ORIGIN":
			if len(toks) > 1 {
				origin = absName(toks[1], origin)
			}
			continue
		}
		if strings.HasPrefix(toks[0], "$") {
			continue
		}
		name := prev
		if !startsBlank {
			name = absName(toks[0], origin)
			toks = toks[1:]
		}
		prev = name
		rr := zoneRR{Name: name, TTL: ttl}
		// [ttl] [class] type, ttl and class in either order.
		for len(toks) > 0 {
			t := strings.ToUpper(toks[0])
			if n, ok := zoneTTL(t); ok {
				rr.TTL = n
			} else if t == "IN" || t == "CH" || t == "HS" {
				// class
			} else {
				break
			}
			toks = toks[1:]
		}
		if len(toks) < 2 {
			continue
		}
		rr.Type = strings.ToUpper(toks[0])
		rr.Data = toks[1:]
		out = append(out, rr)
	}
	return out
}

// zoneTokens splits a line into fields, unquoting strings, dropping the
// comment, and counts its parentheses.
func zoneTokens(line string) (toks []string, opens, closes int) {
	var b strings.Builder
	inQuote, quoted := false, false
	flush := func() {
		if b.Len() > 0 || quoted {
			toks = append(toks, b.String())
		}
		b.Reset()
		quoted = false
	}
	for i := 0; i < len(line); i++ {
		c := line[i]
		switch {
		case inQuote && c == '\\' && i+1 < len(line):
			i++
			b.WriteByte(line[i])
		case c == '"':
			inQuote = !inQuote
			quoted = true
		case inQuote:
			b.WriteByte(c)
		case c == ';':
			flush()
			return toks, opens, closes
		case c == '(':
			flush()
			opens++
		case c == ')':
			flush()
			closes++
		case c == ' ' || c == '\t' || c == '\r':
			flush()
		default:
			b.WriteByte(c)
		}
	}
	flush()
	return toks, opens, closes
}

// zoneTTL reads a TTL: seconds, or BIND units (1h, 2d, 1w30m).
func zoneTTL(s string) (int, bool) {
	if s == "" || s[0] < '0' || s[0] > '9' {
		return 0, false
	}
	if n, err := strconv.Atoi(s); err == nil {
		return n, true
	}
	total, num := 0, 0
	for _, c := range strings.ToLower(s) {
		switch {
		case c >= '0' && c <= '9':
			num = num*10 + int(c-'0')
		case c == 's':
			total, num = total+num, 0
		case c == 'm':
			total, num = total+num*60, 0
		case c == 'h':
			total, num = total+num*3600, 0
		case c == 'd':
			total, num = total+num*86400, 0
		case c == 'w':
			total, num = total+num*604800, 0
		default:
			return 0, false
		}
	}
	return total + num, true
}

// absName makes a zone file name absolute, without the trailing dot.
func absName(n, origin string) string {
	n = strings.ToLower(n)
	switch {
	case n == "@":
		return origin
	case strings.HasSuffix(n, "."):
		return strings.TrimSuffix(n, ".")
	case origin == "":
		return n
	}
	return n + "." + origin
}
