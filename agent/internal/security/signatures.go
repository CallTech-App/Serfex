package security

import (
	"bytes"
	"regexp"
)

// signature is a pattern that marks a PHP file as a web shell or as
// suspicious. Strong patterns are what shells and backdoors do and real
// applications practically never do; weak ones also occur in odd but
// harmless code, so they are not checked in vendor/ and node_modules/.
type signature struct {
	code     string
	severity string
	strong   bool
	re       *regexp.Regexp
	// fn replaces re for checks a regular expression cannot express.
	fn func([]byte) bool
}

var signatures = []signature{
	{"eval-decoded", SeverityCritical, true, regexp.MustCompile(`(?i)\b(?:eval|assert)\s*\(\s*(?:@\s*)?(?:base64_decode|gzinflate|gzuncompress|gzdecode|str_rot13|strrev|hex2bin|convert_uudecode)\s*\(`), nil},
	{"exec-input", SeverityCritical, true, regexp.MustCompile(`(?i)\b(?:eval|assert|system|exec|shell_exec|passthru|popen|proc_open|pcntl_exec)\s*\(\s*(?:@\s*)?(?:\(\s*string\s*\)\s*)?\$_(?:GET|POST|REQUEST|COOKIE|SERVER|FILES)\b`), nil},
	{"call-input", SeverityCritical, true, regexp.MustCompile(`\$_(?:GET|POST|REQUEST|COOKIE)\s*\[[^\]]{1,40}\]\s*\(`), nil},
	{"known-shell", SeverityCritical, true, regexp.MustCompile(`(?i)(?:FilesMan|WSOsetcookie|wso_version|c99shell|r57shell|b374k|IndoXploit|Alfa_?Shell|marijuana shell|Uname:.{0,80}Safe mode)`), nil},
	{"preg-eval", SeverityHigh, true, regexp.MustCompile(`preg_replace\s*\(\s*['"][/#@~!|][^'"\n]{0,200}[/#@~!|][imsuxADSUXJ]*e[imsuxADSUXJ]*['"]\s*,`), nil},
	{"backtick-input", SeverityCritical, true, regexp.MustCompile("`[^`\n]{0,40}\\$_(?:GET|POST|REQUEST|COOKIE)\\b"), nil},
	{"encoded-blob", SeverityMedium, false, nil, longEncoded},
	{"uploader", SeverityHigh, false, regexp.MustCompile(`(?i)move_uploaded_file\s*\(\s*\$_FILES`), nil},
}

// longEncoded finds a run of 2000 base64 characters: payloads hidden in
// code are, code written by people is not.
func longEncoded(content []byte) bool {
	run := 0
	for _, c := range content {
		if c >= 'A' && c <= 'Z' || c >= 'a' && c <= 'z' || c >= '0' && c <= '9' || c == '+' || c == '/' {
			if run++; run >= 2000 {
				return true
			}
		} else {
			run = 0
		}
	}
	return false
}

// phpExtensions are run as PHP by the web server (or meant to be).
var phpExtensions = map[string]bool{
	".php": true, ".phtml": true, ".php3": true, ".php4": true, ".php5": true, ".php7": true,
	".php8": true, ".phar": true, ".inc": true, ".pht": true, ".phps": true, ".suspected": true,
}

// imageExtensions never hold PHP code legitimately.
var imageExtensions = map[string]bool{
	".jpg": true, ".jpeg": true, ".png": true, ".gif": true, ".ico": true, ".webp": true, ".bmp": true,
}

// match returns the strongest signature found in a PHP file's content.
// thirdParty is true for files under vendor/ or node_modules/.
func match(content []byte, thirdParty bool, size int64) (signature, bool) {
	best, found := signature{}, false
	for _, s := range signatures {
		if thirdParty && !s.strong {
			continue
		}
		// Upload handlers are everywhere; only a tiny file that does
		// little else is an uploader shell.
		if s.code == "uploader" && size > 2048 {
			continue
		}
		hit := s.fn != nil && s.fn(content) || s.re != nil && s.re.Match(content)
		if hit && (!found || rank(s.severity) > rank(best.severity)) {
			best, found = s, true
		}
	}
	return best, found
}

func rank(severity string) int {
	switch severity {
	case SeverityCritical:
		return 4
	case SeverityHigh:
		return 3
	case SeverityMedium:
		return 2
	}
	return 1
}

// hasPHP finds a PHP opening tag (in a file that should hold none).
func hasPHP(content []byte) bool {
	return bytes.Contains(bytes.ToLower(content), []byte("<?php"))
}
