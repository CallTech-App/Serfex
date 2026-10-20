package stats

import (
	"net/url"
	"path"
	"strconv"
	"strings"
)

// Request is one access log line in Nginx's "combined" format:
//
//	1.2.3.4 - - [20/Oct/2026:09:12:01 +0300] "GET /a?b HTTP/1.1" 200 512 "https://ref/" "Mozilla/5.0 ..."
type Request struct {
	IP       string
	Method   string
	Path     string // without the query
	Status   int
	Bytes    uint64 // response body sent
	Referrer string
	Agent    string
}

// ParseRequest reads one combined-format line.
func ParseRequest(line string) (Request, bool) {
	ip, rest, ok := strings.Cut(line, " ")
	if !ok || ip == "" {
		return Request{}, false
	}
	req, rest, ok := quoted(rest)
	if !ok {
		return Request{}, false
	}
	// Status and size sit between the request and the referrer.
	q := strings.IndexByte(rest, '"')
	mid := rest
	if q >= 0 {
		mid = rest[:q]
	}
	f := strings.Fields(mid)
	if len(f) == 0 {
		return Request{}, false
	}
	status, err := strconv.Atoi(f[0])
	if err != nil {
		return Request{}, false
	}
	r := Request{IP: ip, Status: status}
	if len(f) > 1 {
		r.Bytes, _ = strconv.ParseUint(f[1], 10, 64)
	}
	if parts := strings.Fields(req); len(parts) >= 2 {
		r.Method = parts[0]
		r.Path, _, _ = strings.Cut(parts[1], "?")
	}
	if q >= 0 {
		var ref string
		if ref, rest, ok = quoted(rest[q:]); ok {
			r.Referrer = ref
			r.Agent, _, _ = quoted(rest)
		}
	}
	return r, true
}

// quoted returns the text between the first pair of double quotes in s
// (Nginx escapes quotes inside as \x22) and what follows.
func quoted(s string) (string, string, bool) {
	i := strings.IndexByte(s, '"')
	if i < 0 {
		return "", s, false
	}
	j := strings.IndexByte(s[i+1:], '"')
	if j < 0 {
		return "", s, false
	}
	return s[i+1 : i+1+j], s[i+j+2:], true
}

// staticExt are files that are part of a page, not pages.
var staticExt = map[string]bool{
	".css": true, ".js": true, ".mjs": true, ".map": true, ".json": true, ".xml": true, ".txt": true,
	".png": true, ".jpg": true, ".jpeg": true, ".gif": true, ".webp": true, ".avif": true, ".svg": true, ".ico": true, ".bmp": true,
	".woff": true, ".woff2": true, ".ttf": true, ".otf": true, ".eot": true,
	".mp4": true, ".webm": true, ".mp3": true, ".ogg": true, ".wav": true,
	".pdf": true, ".zip": true, ".gz": true, ".rar": true, ".7z": true,
}

// IsPage reports whether a request fetched a page a person looks at: a
// successful GET of something that is not an image, script, style or
// download.
func (r Request) IsPage() bool {
	if r.Method != "GET" || r.Status < 200 || r.Status >= 300 {
		return false
	}
	return !staticExt[strings.ToLower(path.Ext(r.Path))]
}

// bots are the crawlers worth naming, matched in this order against the
// lower-cased user agent.
var bots = []struct{ match, name string }{
	{"googlebot", "Googlebot"}, {"google-inspectiontool", "Googlebot"}, {"adsbot-google", "Google Ads"},
	{"bingbot", "Bingbot"}, {"yandex", "YandexBot"}, {"baiduspider", "Baiduspider"}, {"duckduckbot", "DuckDuckBot"},
	{"applebot", "Applebot"}, {"petalbot", "PetalBot"}, {"seznambot", "SeznamBot"},
	{"ahrefsbot", "AhrefsBot"}, {"semrushbot", "SemrushBot"}, {"mj12bot", "MJ12bot"}, {"dotbot", "DotBot"}, {"dataforseobot", "DataForSeoBot"},
	{"bytespider", "Bytespider"}, {"gptbot", "GPTBot"}, {"chatgpt-user", "ChatGPT"}, {"oai-searchbot", "OpenAI"}, {"claudebot", "ClaudeBot"},
	{"claude-user", "Claude"}, {"perplexitybot", "PerplexityBot"}, {"ccbot", "CCBot"}, {"amazonbot", "Amazonbot"}, {"meta-externalagent", "Meta"},
	{"facebookexternalhit", "Facebook"}, {"twitterbot", "Twitterbot"}, {"linkedinbot", "LinkedInBot"}, {"slackbot", "Slackbot"},
	{"telegrambot", "TelegramBot"}, {"whatsapp", "WhatsApp"}, {"discordbot", "Discordbot"},
	{"uptimerobot", "UptimeRobot"}, {"pingdom", "Pingdom"}, {"statuscake", "StatusCake"}, {"betteruptime", "Better Stack"},
	{"wordpress", "WordPress"}, {"curl/", "curl"}, {"wget/", "Wget"}, {"python-requests", "python-requests"},
	{"python-urllib", "Python"}, {"aiohttp", "Python"}, {"go-http-client", "Go"}, {"okhttp", "OkHttp"}, {"java/", "Java"},
	{"apache-httpclient", "Java"}, {"libwww-perl", "Perl"}, {"guzzlehttp", "Guzzle"}, {"node-fetch", "Node.js"}, {"axios/", "Node.js"},
	{"scrapy", "Scrapy"}, {"headlesschrome", "Headless Chrome"}, {"phantomjs", "PhantomJS"}, {"lighthouse", "Lighthouse"},
	{"zgrab", "zgrab"}, {"masscan", "masscan"}, {"nmap", "Nmap"}, {"censys", "Censys"}, {"expanse", "Expanse"},
}

// botWords mark a browser-looking user agent as automated when no name
// matched ("Mozilla/5.0 (compatible; Foobot/1.0; +http://foo.example)").
var botWords = []string{"bot", "crawl", "spider", "slurp", "+http", "archiver", "preview"}

// Names Bot returns for crawlers it does not know by name.
const (
	NoAgent   = "-"          // no user agent at all
	OtherTool = "other-tool" // a library or command-line tool
	OtherBot  = "other-bot"  // a crawler that names itself, but not one we list
)

// Bot returns the name of the crawler or tool behind a request, or ""
// for a browser. A missing user agent counts as a bot.
func (r Request) Bot() string {
	a := strings.ToLower(r.Agent)
	if a == "" || a == "-" {
		return NoAgent
	}
	for _, b := range bots {
		if strings.Contains(a, b.match) {
			return b.name
		}
	}
	if !strings.HasPrefix(a, "mozilla/") && !strings.HasPrefix(a, "opera") {
		return OtherTool
	}
	for _, w := range botWords {
		if strings.Contains(a, w) {
			return OtherBot
		}
	}
	return ""
}

// ReferrerHost returns the host a visitor came from, without "www.", or
// "" when there is none or it is the site itself (one of names, or a
// subdomain of the site).
func (r Request) ReferrerHost(names []string) string {
	if r.Referrer == "" || r.Referrer == "-" {
		return ""
	}
	u, err := url.Parse(r.Referrer)
	if err != nil || u.Host == "" {
		return ""
	}
	host := strings.ToLower(u.Hostname())
	host = strings.TrimSuffix(host, ".")
	for _, n := range names {
		n = strings.TrimPrefix(strings.ToLower(n), "www.")
		if host == n || strings.HasSuffix(host, "."+n) {
			return ""
		}
	}
	host = strings.TrimPrefix(host, "www.")
	if len(host) > 120 {
		host = host[:120]
	}
	return host
}
