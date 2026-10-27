package site

import (
	"fmt"
	"regexp"
	"sort"
	"strconv"
	"strings"
)

// PHPSpec holds a site's own PHP settings (the panel's PHP ve Web Sunucusu
// page). The zero value is the server's defaults: php.ini of the site's
// PHP version and an on-demand pool of DefaultMaxChildren workers.
type PHPSpec struct {
	// Values are php.ini settings the site's code may still change with
	// ini_set (php_value).
	Values map[string]string `json:"values,omitempty"`
	// AdminValues are php.ini settings the code cannot change
	// (php_admin_value), such as a hosting package's memory limit.
	AdminValues map[string]string `json:"admin_values,omitempty"`
	// DisableFunctions are switched off for the site, on top of php.ini's
	// disable_functions (PHP-FPM only adds to that list).
	DisableFunctions []string `json:"disable_functions,omitempty"`

	// PM is the process manager: "ondemand" (the default: workers start
	// with requests and stop when idle), "dynamic" or "static".
	PM          string `json:"pm,omitempty"`
	MaxChildren int    `json:"max_children,omitempty"`
	// MaxRequests restarts a worker after this many requests (0: 500).
	MaxRequests int `json:"max_requests,omitempty"`
	// RequestTimeout ends a request that runs longer, in seconds (0: no
	// limit beyond PHP's max_execution_time).
	RequestTimeout int `json:"request_timeout,omitempty"`
	// BodyMB, when set, lets Nginx accept request bodies this large for
	// the site (its post_max_size); others use the server's limit.
	BodyMB int `json:"body_mb,omitempty"`
}

// Process managers.
const (
	PMOndemand = "ondemand"
	PMDynamic  = "dynamic"
	PMStatic   = "static"
)

// reservedINI are settings Serfex sets for every pool to keep sites apart;
// a site cannot change them.
var reservedINI = map[string]bool{
	"open_basedir": true, "upload_tmp_dir": true, "sys_temp_dir": true, "session.save_path": true,
	"error_log": true, "log_errors": true, "disable_functions": true, "disable_classes": true,
	"extension": true, "zend_extension": true, "extension_dir": true, "doc_root": true, "user_dir": true,
}

var (
	iniKeyRe   = regexp.MustCompile(`^[a-z][a-z0-9_]*(\.[a-z0-9_]+)*$`)
	iniValueRe = regexp.MustCompile(`^[A-Za-z0-9_.,:/ +*@~=%-]*$`)
	funcRe     = regexp.MustCompile(`^[a-z_][a-z0-9_]*$`)
)

const maxPHPValues = 50

func (p *PHPSpec) validate() error {
	if p == nil {
		return nil
	}
	if len(p.Values)+len(p.AdminValues) > maxPHPValues {
		return invalid("at most %d PHP settings are allowed", maxPHPValues)
	}
	for _, values := range []map[string]string{p.Values, p.AdminValues} {
		for k, v := range values {
			if len(k) > 64 || !iniKeyRe.MatchString(k) {
				return invalid("PHP setting %q is not a valid php.ini name", k)
			}
			if reservedINI[k] {
				return invalid("PHP setting %s is set by Serfex and cannot be changed", k)
			}
			if len(v) > 200 || !iniValueRe.MatchString(v) {
				return invalid("value of %s may contain letters, digits, spaces and . , : / + * @ ~ = %% _ - only", k)
			}
		}
	}
	for k := range p.Values {
		if _, ok := p.AdminValues[k]; ok {
			return invalid("PHP setting %s is given twice", k)
		}
	}
	if len(p.DisableFunctions) > 100 {
		return invalid("at most 100 disabled functions are allowed")
	}
	for _, f := range p.DisableFunctions {
		if len(f) > 64 || !funcRe.MatchString(f) {
			return invalid("%q is not a PHP function name", f)
		}
	}
	switch p.PM {
	case "", PMOndemand, PMDynamic, PMStatic:
	default:
		return invalid("pm must be ondemand, dynamic or static")
	}
	switch {
	case p.MaxChildren < 0 || p.MaxChildren > 1000:
		return invalid("max_children must be between 1 and 1000")
	case p.MaxRequests < 0 || p.MaxRequests > 1000000:
		return invalid("max_requests must be between 0 and 1000000")
	case p.RequestTimeout < 0 || p.RequestTimeout > 86400:
		return invalid("request_timeout must be between 0 and 86400 seconds")
	case p.BodyMB < 0 || p.BodyMB > 102400:
		return invalid("body_mb must be between 0 and 102400")
	}
	return nil
}

// pool is what the PHP-FPM pool template needs of a site's PHP settings.
type pool struct {
	PM             string
	Base           int // max_children without a temporary limit
	StartServers   int
	MinSpare       int
	MaxSpare       int
	MaxRequests    int
	RequestTimeout int
	Values         []iniLine
	AdminValues    []iniLine
	Disable        string
	BodyMB         int
}

type iniLine struct{ Key, Value string }

func sortedINI(m map[string]string) []iniLine {
	out := make([]iniLine, 0, len(m))
	for k, v := range m {
		out = append(out, iniLine{k, v})
	}
	sort.Slice(out, func(i, j int) bool { return out[i].Key < out[j].Key })
	return out
}

// poolData fills the pool part of the template. limit is a temporary
// worker limit (0: none).
func poolData(d *tmplData, p *PHPSpec, limit int) {
	if p == nil {
		p = &PHPSpec{}
	}
	pl := pool{PM: p.PM, Base: p.MaxChildren, MaxRequests: p.MaxRequests, RequestTimeout: p.RequestTimeout, BodyMB: p.BodyMB}
	if pl.PM == "" {
		pl.PM = PMOndemand
	}
	if pl.Base == 0 {
		pl.Base = DefaultMaxChildren
	}
	if pl.MaxRequests == 0 {
		pl.MaxRequests = 500
	}
	d.MaxChildren = pl.Base
	if limit > 0 {
		d.MaxChildren = limit
	}
	pl.StartServers, pl.MinSpare, pl.MaxSpare = spares(d.MaxChildren)
	pl.Values = sortedINI(p.Values)
	pl.AdminValues = sortedINI(p.AdminValues)
	pl.Disable = strings.Join(p.DisableFunctions, ",")
	d.Pool = pl
}

// spares are the dynamic process manager's start, minimum and maximum
// idle workers for n workers at most.
func spares(n int) (start, lo, hi int) {
	lo = max(1, n/4)
	hi = max(lo, n/2)
	return lo, lo, hi
}

var (
	baseRe     = regexp.MustCompile(`(?m)^; serfex-max-children = (\d+)$`)
	pmRe       = regexp.MustCompile(`(?m)^pm = (\w+)$`)
	startRe    = regexp.MustCompile(`(?m)^pm\.start_servers = \d+$`)
	minSpareRe = regexp.MustCompile(`(?m)^pm\.min_spare_servers = \d+$`)
	maxSpareRe = regexp.MustCompile(`(?m)^pm\.max_spare_servers = \d+$`)
)

// setWorkers rewrites a pool's worker counts for n workers; n = 0 goes
// back to the site's own number.
func setWorkers(conf []byte, n int) ([]byte, error) {
	if !maxChildrenRe.Match(conf) {
		return nil, fmt.Errorf("no pm.max_children line")
	}
	if n == 0 {
		n = DefaultMaxChildren
		if m := baseRe.FindSubmatch(conf); m != nil {
			n, _ = strconv.Atoi(string(m[1]))
		}
	}
	conf = maxChildrenRe.ReplaceAll(conf, fmt.Appendf(nil, "pm.max_children = %d", n))
	if m := pmRe.FindSubmatch(conf); m != nil && string(m[1]) == PMDynamic {
		start, lo, hi := spares(n)
		conf = startRe.ReplaceAll(conf, fmt.Appendf(nil, "pm.start_servers = %d", start))
		conf = minSpareRe.ReplaceAll(conf, fmt.Appendf(nil, "pm.min_spare_servers = %d", lo))
		conf = maxSpareRe.ReplaceAll(conf, fmt.Appendf(nil, "pm.max_spare_servers = %d", hi))
	}
	return conf, nil
}
