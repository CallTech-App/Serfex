package security

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/calltech-app/serfex/agent/internal/component"
	"github.com/calltech-app/serfex/agent/internal/site"
)

// WAF modes of a site.
const (
	ModeServer = ""       // follows the server's mode (Yazılım Yönetimi / Güvenlik > WAF)
	ModeOff    = "off"    // ModSecurity does not look at the site
	ModeDetect = "detect" // attacks are logged, nothing is refused
	ModeBlock  = "block"  // attacks are refused with 403
)

// MaxExclusions is how many rule exclusions a site may have.
const MaxExclusions = 100

// WAFSpec is a site's whole WAF setting.
type WAFSpec struct {
	Domain     string      `json:"domain"`
	Mode       string      `json:"mode"`
	Exclusions []Exclusion `json:"exclusions"`
}

// Exclusion turns one rule off for a site, everywhere or under a path.
type Exclusion struct {
	RuleID int    `json:"rule_id"`
	Path   string `json:"path"`
}

// WAFResult says whether the server changed.
type WAFResult struct {
	Domain  string `json:"domain"`
	Changed bool   `json:"changed"`
}

var pathRe = regexp.MustCompile(`^/[A-Za-z0-9._~/%@+-]{0,200}$`)

func validPath(p string) bool {
	return pathRe.MatchString(p) && !strings.Contains(p, "/../") && !strings.HasSuffix(p, "/..") && !strings.Contains(p, "//")
}

func (s WAFSpec) validate() error {
	switch s.Mode {
	case ModeServer, ModeOff, ModeDetect, ModeBlock:
	default:
		return invalid("mode must be empty, off, detect or block")
	}
	if len(s.Exclusions) > MaxExclusions {
		return invalid("a site may have at most %d rule exclusions", MaxExclusions)
	}
	for _, e := range s.Exclusions {
		if e.RuleID < 1 || e.RuleID > 99999999 {
			return invalid("rule id %d is not valid", e.RuleID)
		}
		if e.Path != "" && !validPath(e.Path) {
			return invalid("path %q is not valid; it starts with / and holds letters, digits and ._~/%%@+-", e.Path)
		}
	}
	return nil
}

// ApplyWAF writes a site's WAF setting. A site that follows the server
// and excludes nothing has no files.
func (m *Manager) ApplyWAF(ctx context.Context, spec WAFSpec) (WAFResult, error) {
	if err := spec.validate(); err != nil {
		return WAFResult{}, err
	}
	if err := m.checkSite(spec.Domain); err != nil {
		return WAFResult{}, err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	conf, rules := renderWAF(spec)
	if conf != nil && !m.wafInstalled() {
		return WAFResult{}, ErrWAFNotInstalled
	}
	changed, err := m.writeSnippets(ctx, spec.Domain, []snippet{{Name: "waf.conf", Data: conf}, {Name: "waf.rules", Data: rules}}, nil)
	return WAFResult{Domain: spec.Domain, Changed: changed}, err
}

var modeNames = map[string]string{ModeOff: "Kapalı", ModeDetect: "Yalnızca izle", ModeBlock: "Engelle", ModeServer: "sunucu ayarı"}

func renderWAF(s WAFSpec) (conf, rules []byte) {
	if s.Mode == ModeOff {
		return []byte(header("WAF of "+s.Domain+": Kapalı") + "modsecurity off;\n"), nil
	}
	if s.Mode == ModeServer && len(s.Exclusions) == 0 {
		return nil, nil
	}
	dir := site.ExtraDir(s.Domain)
	conf = []byte(header("WAF of "+s.Domain+": "+modeNames[s.Mode]) + "modsecurity on;\nmodsecurity_rules_file " + dir + "/waf.rules;\n")
	var b strings.Builder
	b.WriteString(header("ModSecurity rules of " + s.Domain + ", merged with the server's."))
	switch s.Mode {
	case ModeDetect:
		b.WriteString("SecRuleEngine DetectionOnly\n")
	case ModeBlock:
		b.WriteString("SecRuleEngine On\n")
	}
	ex := append([]Exclusion{}, s.Exclusions...)
	sort.SliceStable(ex, func(i, j int) bool {
		if ex[i].RuleID != ex[j].RuleID {
			return ex[i].RuleID < ex[j].RuleID
		}
		return ex[i].Path < ex[j].Path
	})
	// Phase 1 runs before the Core Rule Set's request rules, so the
	// excluded rules never see the request.
	for i, e := range ex {
		id := 10001 + i
		if e.Path == "" {
			fmt.Fprintf(&b, "SecAction \"id:%d,phase:1,pass,nolog,ctl:ruleRemoveById=%d\"\n", id, e.RuleID)
		} else {
			fmt.Fprintf(&b, "SecRule REQUEST_FILENAME \"@beginsWith %s\" \"id:%d,phase:1,pass,nolog,ctl:ruleRemoveById=%d\"\n", e.Path, id, e.RuleID)
		}
	}
	return conf, []byte(b.String())
}

// --- The blocked-request log -----------------------------------------

// EventsSpec filters the log.
type EventsSpec struct {
	// Hosts keeps requests to these host names (a site and its aliases);
	// empty keeps all.
	Hosts []string  `json:"hosts"`
	Since time.Time `json:"since"`
	IP    string    `json:"ip"`
	Limit int       `json:"limit"`
}

// Event is one request a rule matched.
type Event struct {
	ID      string    `json:"id"`
	Time    time.Time `json:"time"`
	IP      string    `json:"ip"`
	Host    string    `json:"host"`
	Method  string    `json:"method"`
	URI     string    `json:"uri"`
	Status  int       `json:"status"`
	Blocked bool      `json:"blocked"`
	// Score is the CRS inbound anomaly score, when it was exceeded.
	Score int       `json:"score"`
	Rules []RuleHit `json:"rules"`
	Agent string    `json:"user_agent,omitempty"`
}

// RuleHit is one rule that matched.
type RuleHit struct {
	ID       int    `json:"id"`
	Message  string `json:"message"`
	Severity int    `json:"severity"`
	Data     string `json:"data,omitempty"`
}

// Events is the WAF state and the newest matching events.
type Events struct {
	Installed bool    `json:"installed"`
	Events    []Event `json:"events"`
}

// The CRS rules that only add up scores: shown as the score, not as hits.
var scoringRules = map[int]bool{949110: true, 959100: true, 980130: true, 980140: true, 980170: true}

// maxRead is how much of the end of the log is read.
const maxRead = 16 << 20

// WAFEvents returns the newest requests the WAF logged, newest first.
func (m *Manager) WAFEvents(spec EventsSpec) (Events, error) {
	res := Events{Installed: m.wafInstalled(), Events: []Event{}}
	limit := spec.Limit
	if limit <= 0 || limit > 500 {
		limit = 100
	}
	hosts := map[string]bool{}
	for _, h := range spec.Hosts {
		hosts[strings.ToLower(h)] = true
	}
	var data []byte
	for _, name := range []string{component.ModSecurityLog + ".1", component.ModSecurityLog} {
		b, err := tail(m.h.Path(name), maxRead)
		if err != nil {
			continue
		}
		data = append(data, b...)
	}
	sc := bufio.NewScanner(bytes.NewReader(data))
	sc.Buffer(make([]byte, 64<<10), 4<<20)
	var all []Event
	for sc.Scan() {
		e, ok := parseEvent(sc.Bytes())
		if !ok {
			continue
		}
		if len(hosts) > 0 && !hosts[e.Host] {
			continue
		}
		if !spec.Since.IsZero() && !e.Time.After(spec.Since) {
			continue
		}
		if spec.IP != "" && e.IP != spec.IP {
			continue
		}
		all = append(all, e)
	}
	for i := len(all) - 1; i >= 0 && len(res.Events) < limit; i-- {
		res.Events = append(res.Events, all[i])
	}
	return res, nil
}

func tail(path string, n int64) ([]byte, error) {
	f, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	st, err := f.Stat()
	if err != nil {
		return nil, err
	}
	start := int64(0)
	if st.Size() > n {
		start = st.Size() - n
	}
	if _, err := f.Seek(start, io.SeekStart); err != nil {
		return nil, err
	}
	b, err := io.ReadAll(io.LimitReader(f, n))
	if err != nil {
		return nil, err
	}
	if start > 0 {
		// Drop the partial first line.
		if i := bytes.IndexByte(b, '\n'); i >= 0 {
			b = b[i+1:]
		}
	}
	if len(b) > 0 && b[len(b)-1] != '\n' {
		b = append(b, '\n')
	}
	return b, nil
}

type auditLine struct {
	Transaction struct {
		ClientIP  string `json:"client_ip"`
		TimeStamp string `json:"time_stamp"`
		UniqueID  string `json:"unique_id"`
		Request   struct {
			Method  string            `json:"method"`
			URI     string            `json:"uri"`
			Headers map[string]string `json:"headers"`
		} `json:"request"`
		Response struct {
			HTTPCode int `json:"http_code"`
		} `json:"response"`
		Producer struct {
			Engine string `json:"secrules_engine"`
		} `json:"producer"`
		Messages []struct {
			Message string `json:"message"`
			Details struct {
				RuleID   string `json:"ruleId"`
				Severity string `json:"severity"`
				Data     string `json:"data"`
			} `json:"details"`
		} `json:"messages"`
	} `json:"transaction"`
}

var scoreRe = regexp.MustCompile(`Total Score: (\d+)`)

func parseEvent(line []byte) (Event, bool) {
	var a auditLine
	if len(line) == 0 || line[0] != '{' || json.Unmarshal(line, &a) != nil {
		return Event{}, false
	}
	tx := a.Transaction
	if len(tx.Messages) == 0 {
		return Event{}, false // a 4xx/5xx answer no rule was involved in
	}
	t, err := time.ParseInLocation("Mon Jan _2 15:04:05 2006", tx.TimeStamp, time.Local)
	if err != nil {
		return Event{}, false
	}
	host := ""
	for k, v := range tx.Request.Headers {
		if strings.EqualFold(k, "host") {
			host = strings.ToLower(v)
		}
	}
	if h, _, ok := strings.Cut(host, ":"); ok && !strings.HasPrefix(host, "[") {
		host = h
	}
	e := Event{
		ID: tx.UniqueID, Time: t.UTC(), IP: tx.ClientIP, Host: host,
		Method: clip(tx.Request.Method, 16), URI: clip(tx.Request.URI, 500),
		Status: tx.Response.HTTPCode, Rules: []RuleHit{},
	}
	for k, v := range tx.Request.Headers {
		if strings.EqualFold(k, "user-agent") {
			e.Agent = clip(v, 200)
		}
	}
	anomaly := false
	for _, msg := range tx.Messages {
		id, _ := strconv.Atoi(msg.Details.RuleID)
		if scoringRules[id] {
			anomaly = anomaly || id == 949110 || id == 959100
			if m := scoreRe.FindStringSubmatch(msg.Message); m != nil {
				if n, _ := strconv.Atoi(m[1]); n > e.Score {
					e.Score = n
				}
			}
			continue
		}
		sev, _ := strconv.Atoi(msg.Details.Severity)
		e.Rules = append(e.Rules, RuleHit{ID: id, Message: clip(msg.Message, 200), Severity: sev, Data: clip(msg.Details.Data, 200)})
	}
	e.Blocked = tx.Producer.Engine == "Enabled" && (anomaly || e.Status == 403)
	return e, true
}

func clip(s string, n int) string {
	s = strings.ToValidUTF8(s, "?")
	if len(s) <= n {
		return s
	}
	cut := n
	for cut > 0 && (s[cut]&0xC0) == 0x80 {
		cut--
	}
	return s[:cut] + "…"
}
