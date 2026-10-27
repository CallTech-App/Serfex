package monitor

import (
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"

	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/site"
	"github.com/calltech-app/serfex/agent/internal/txn"
)

// siteInfo is a site as its PHP-FPM pool and vhost describe it.
type siteInfo struct {
	Domain       string
	User         string
	PHP          string
	MaxChildren  int
	StatusSocket string
	Names        []string // the domain and its aliases
}

// discoverSites reads the PHP-FPM pools Serfex wrote: one per site.
func discoverSites(h host.Host) []siteInfo {
	files, _ := filepath.Glob(h.Path("/etc/php/*/fpm/pool.d/*.conf"))
	var sites []siteInfo
	for _, f := range files {
		b, err := os.ReadFile(f)
		if err != nil || !txn.HasMarker(b) {
			continue
		}
		si := siteInfo{PHP: filepath.Base(filepath.Dir(filepath.Dir(filepath.Dir(f)))), MaxChildren: site.DefaultMaxChildren}
		for _, line := range strings.Split(string(b), "\n") {
			line = strings.TrimSpace(line)
			if strings.HasPrefix(line, "[") && strings.HasSuffix(line, "]") {
				si.Domain = line[1 : len(line)-1]
				continue
			}
			k, v, ok := strings.Cut(line, "=")
			if !ok {
				continue
			}
			k, v = strings.TrimSpace(k), strings.TrimSpace(v)
			switch k {
			case "user":
				si.User = v
			case "pm.max_children":
				if n, err := strconv.Atoi(v); err == nil {
					si.MaxChildren = n
				}
			case "pm.status_listen":
				si.StatusSocket = v
			}
		}
		if si.Domain == "" || si.User == "" || site.ValidateDomain(si.Domain) != nil {
			continue
		}
		si.Names = serverNames(h, si.Domain)
		sites = append(sites, si)
	}
	sort.Slice(sites, func(i, j int) bool { return sites[i].Domain < sites[j].Domain })
	return sites
}

// serverNames reads the names the site's vhost answers to.
func serverNames(h host.Host, domain string) []string {
	names := []string{domain}
	b, err := os.ReadFile(h.Path("/etc/nginx/sites-available/" + domain + ".conf"))
	if err != nil {
		return names
	}
	for _, line := range strings.Split(string(b), "\n") {
		line = strings.TrimSpace(line)
		if rest, ok := strings.CutPrefix(line, "server_name "); ok {
			for _, n := range strings.Fields(strings.TrimSuffix(rest, ";")) {
				if n != domain {
					names = append(names, n)
				}
			}
			break
		}
	}
	return names
}
