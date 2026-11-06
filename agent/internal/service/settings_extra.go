package service

import (
	"bytes"
	"context"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"

	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/txn"
)

// PGSettings are the customers' PostgreSQL settings Serfex changes, in
// /etc/postgresql/<v>/main/conf.d/zz-serfex-tuning.conf (read after the
// cluster's postgresql.conf and Serfex's serfex.conf). 0 and "" leave
// PostgreSQL's own value.
type PGSettings struct {
	SharedBuffersMB        int    `json:"shared_buffers_mb"`
	EffectiveCacheSizeMB   int    `json:"effective_cache_size_mb"`
	WorkMemMB              int    `json:"work_mem_mb"`
	MaintenanceWorkMemMB   int    `json:"maintenance_work_mem_mb"`
	RandomPageCost         string `json:"random_page_cost"`
	EffectiveIOConcurrency int    `json:"effective_io_concurrency"`
}

var pageCostRe = regexp.MustCompile(`^(0|[1-9][0-9]?)(\.[0-9]{1,2})?$`)

func (p PGSettings) validate() error {
	between := func(v, lo, hi int) bool { return v == 0 || (v >= lo && v <= hi) }
	switch {
	case !between(p.SharedBuffersMB, 16, 1048576):
		return invalid("shared_buffers must be between 16 MB and 1 TB")
	case !between(p.EffectiveCacheSizeMB, 64, 4194304):
		return invalid("effective_cache_size must be between 64 MB and 4 TB")
	case !between(p.WorkMemMB, 1, 2048):
		return invalid("work_mem must be between 1 and 2048 MB")
	case !between(p.MaintenanceWorkMemMB, 16, 16384):
		return invalid("maintenance_work_mem must be between 16 MB and 16 GB")
	case p.RandomPageCost != "" && !pageCostRe.MatchString(p.RandomPageCost):
		return invalid("random_page_cost must be a number such as 1.1")
	case !between(p.EffectiveIOConcurrency, 1, 1000):
		return invalid("effective_io_concurrency must be between 1 and 1000")
	}
	if p.RandomPageCost != "" {
		if f, _ := strconv.ParseFloat(p.RandomPageCost, 64); f < 0.1 || f > 20 {
			return invalid("random_page_cost must be between 0.1 and 20")
		}
	}
	return nil
}

func pgTuning(v string) string { return "/etc/postgresql/" + v + "/main/conf.d/zz-serfex-tuning.conf" }

// PGContent is Serfex's tuning file for the customers' cluster.
func PGContent(p PGSettings) []byte {
	var b bytes.Buffer
	fmt.Fprintf(&b, "# %s. Change these on the panel's Hizmetler page.\n", txn.Marker)
	mb := func(name string, v int) {
		if v > 0 {
			fmt.Fprintf(&b, "%s = '%dMB'\n", name, v)
		}
	}
	mb("shared_buffers", p.SharedBuffersMB)
	mb("effective_cache_size", p.EffectiveCacheSizeMB)
	mb("work_mem", p.WorkMemMB)
	mb("maintenance_work_mem", p.MaintenanceWorkMemMB)
	if p.RandomPageCost != "" {
		fmt.Fprintf(&b, "random_page_cost = %s\n", p.RandomPageCost)
	}
	if p.EffectiveIOConcurrency > 0 {
		fmt.Fprintf(&b, "effective_io_concurrency = %d\n", p.EffectiveIOConcurrency)
	}
	return b.Bytes()
}

var pgLineRe = regexp.MustCompile(`(?m)^(\w+) = '?([0-9.]+)(MB)?'?$`)

// pgFileSettings reads Serfex's tuning file of version v.
func (m *Manager) pgFileSettings(v string) PGSettings {
	var p PGSettings
	b, err := os.ReadFile(m.h.Path(pgTuning(v)))
	if err != nil {
		return p
	}
	for _, mt := range pgLineRe.FindAllStringSubmatch(string(b), -1) {
		n, _ := strconv.Atoi(mt[2])
		switch mt[1] {
		case "shared_buffers":
			p.SharedBuffersMB = n
		case "effective_cache_size":
			p.EffectiveCacheSizeMB = n
		case "work_mem":
			p.WorkMemMB = n
		case "maintenance_work_mem":
			p.MaintenanceWorkMemMB = n
		case "random_page_cost":
			p.RandomPageCost = mt[2]
		case "effective_io_concurrency":
			p.EffectiveIOConcurrency = n
		}
	}
	return p
}

// pgSettings are the settings as the server runs them, where the tuning
// file leaves PostgreSQL's own value (when the server answers).
func (m *Manager) pgSettings(ctx context.Context, v string) PGSettings {
	p := m.pgFileSettings(v)
	if m.sim {
		def := PGSettings{SharedBuffersMB: 128, EffectiveCacheSizeMB: 4096, WorkMemMB: 4, MaintenanceWorkMemMB: 64, RandomPageCost: "4", EffectiveIOConcurrency: 1}
		return fillPG(p, def)
	}
	rows, err := m.psql(ctx, v, "SELECT name, setting, COALESCE(unit, '') FROM pg_settings WHERE name IN ("+quoted(pgMeasured)+");\n")
	if err != nil {
		return p
	}
	var run PGSettings
	for _, r := range rows {
		if len(r) != 3 {
			continue
		}
		mb, _ := pgMB(r[1], r[2])
		n, _ := strconv.Atoi(r[1])
		switch r[0] {
		case "shared_buffers":
			run.SharedBuffersMB = int(mb)
		case "effective_cache_size":
			run.EffectiveCacheSizeMB = int(mb)
		case "work_mem":
			run.WorkMemMB = int(mb)
		case "maintenance_work_mem":
			run.MaintenanceWorkMemMB = int(mb)
		case "random_page_cost":
			run.RandomPageCost = r[1]
		case "effective_io_concurrency":
			run.EffectiveIOConcurrency = n
		}
	}
	return fillPG(p, run)
}

func fillPG(p, run PGSettings) PGSettings {
	if p.SharedBuffersMB == 0 {
		p.SharedBuffersMB = run.SharedBuffersMB
	}
	if p.EffectiveCacheSizeMB == 0 {
		p.EffectiveCacheSizeMB = run.EffectiveCacheSizeMB
	}
	if p.WorkMemMB == 0 {
		p.WorkMemMB = run.WorkMemMB
	}
	if p.MaintenanceWorkMemMB == 0 {
		p.MaintenanceWorkMemMB = run.MaintenanceWorkMemMB
	}
	if p.RandomPageCost == "" {
		p.RandomPageCost = run.RandomPageCost
	}
	if p.EffectiveIOConcurrency == 0 {
		p.EffectiveIOConcurrency = run.EffectiveIOConcurrency
	}
	return p
}

// applyPostgres writes the tuning file, has PostgreSQL check it and
// reloads; a new shared_buffers needs a restart, which drops the open
// connections for a few seconds.
func (m *Manager) applyPostgres(ctx context.Context, t *txn.Tx, unit, v string, old, p PGSettings) error {
	if m.sim {
		_ = os.MkdirAll(m.h.Path(filepath.Dir(pgTuning(v))), 0o755)
	}
	if err := t.WriteFile(pgTuning(v), PGContent(p)); err != nil {
		return err
	}
	if !t.Changed || m.sim {
		return nil
	}
	// pg_file_settings reads the files as they are now.
	rows, err := m.psql(ctx, v, "SELECT sourcefile || ':' || sourceline || ' ' || name || ': ' || error FROM pg_file_settings WHERE error IS NOT NULL;\n")
	if err != nil {
		return err
	}
	if len(rows) > 0 {
		var msgs []string
		for _, r := range rows {
			msgs = append(msgs, strings.Join(r, " "))
		}
		return invalid("PostgreSQL refused the settings, nothing was changed: %s", strings.Join(msgs, "; "))
	}
	verb := "reload"
	if p.SharedBuffersMB != old.SharedBuffersMB {
		verb = "restart"
	}
	if out, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{verb, unit + ".service"}}); err != nil {
		return fmt.Errorf("PostgreSQL did not take the new settings: %s", tail(out, err))
	}
	return nil
}

// MemcachedSettings are Memcached's memory (-m) and connection (-c)
// limits.
type MemcachedSettings struct {
	MemoryMB       int `json:"memory_mb"`
	MaxConnections int `json:"max_connections"`
}

// SetMemcached puts the limits into /etc/memcached.conf, keeping the
// rest of the file.
func SetMemcached(conf []byte, mc MemcachedSettings) []byte {
	for _, o := range [][2]string{{"m", strconv.Itoa(mc.MemoryMB)}, {"c", strconv.Itoa(mc.MaxConnections)}} {
		re := regexp.MustCompile(`(?m)^[ \t]*#?[ \t]*-` + o[0] + `[ \t]+\S+.*$`)
		line := []byte("-" + o[0] + " " + o[1])
		if loc := re.FindIndex(conf); loc != nil {
			conf = append(conf[:loc[0]:loc[0]], append(line, conf[loc[1]:]...)...)
		} else {
			if len(conf) > 0 && conf[len(conf)-1] != '\n' {
				conf = append(conf, '\n')
			}
			conf = append(append(conf, line...), '\n')
		}
	}
	return conf
}

// applyMemcached changes the limits and restarts Memcached, which starts
// with an empty cache.
func (m *Manager) applyMemcached(ctx context.Context, t *txn.Tx, mc MemcachedSettings) error {
	conf, err := os.ReadFile(m.h.Path(memcachedConf))
	if err != nil && !m.sim {
		return err
	}
	if err := t.WriteOwned(memcachedConf, SetMemcached(conf, mc), 0o644, 0, 0); err != nil {
		return err
	}
	if !t.Changed || m.sim {
		return nil
	}
	if out, err := m.h.Run(ctx, host.Command{Name: "systemctl", Args: []string{"restart", "memcached.service"}}); err != nil {
		return fmt.Errorf("memcached did not start with the new settings: %s", tail(out, err))
	}
	return nil
}
