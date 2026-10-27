package laravel

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"runtime"
	"sort"
	"strconv"
	"strings"

	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/txn"
)

// Node.js release lines and Composer as installable components
// (Yazılım Yönetimi): several Node.js lines can sit side by side under
// /opt/serfex/node/<version>; builds use the one /opt/serfex/node/current
// points at.

var nodeMajorRe = regexp.MustCompile(`^[1-9][0-9]?$`)

// simNode are the versions a dry run "installs".
var simNode = map[string]string{"22": "v22.21.1", "24": "v24.11.0", "26": "v26.1.0"}

// NodeLTS are the long-term support lines, which builds prefer.
var NodeLTS = []string{"22", "24"}

// NodeVersions maps each installed Node.js line to its version ("24" →
// "v24.11.0").
func (m *Manager) NodeVersions() map[string]string {
	out := map[string]string{}
	entries, err := os.ReadDir(m.h.Path(toolsDir + "/node"))
	if err != nil {
		return out
	}
	for _, e := range entries {
		major, ok := nodeMajor(e.Name())
		if !ok || !e.IsDir() {
			continue
		}
		if have, ok := out[major]; !ok || versionLess(have, e.Name()) {
			out[major] = e.Name()
		}
	}
	return out
}

// DefaultNode is the line builds use, "" when there is none.
func (m *Manager) DefaultNode() string {
	target, err := os.Readlink(m.h.Path(toolsDir + "/node/current"))
	if err != nil {
		return ""
	}
	if _, err := os.Stat(m.h.Path(toolsDir + "/node/" + target)); err != nil {
		return ""
	}
	major, _ := nodeMajor(filepath.Base(target))
	return major
}

// nodeMajor reads "24" from "v24.11.0".
func nodeMajor(dir string) (string, bool) {
	rest, ok := strings.CutPrefix(dir, "v")
	if !ok {
		return "", false
	}
	major, _, ok := strings.Cut(rest, ".")
	return major, ok && nodeMajorRe.MatchString(major)
}

// versionLess compares "v24.9.0" and "v24.11.0" numerically.
func versionLess(a, b string) bool {
	pa, pb := strings.Split(strings.TrimPrefix(a, "v"), "."), strings.Split(strings.TrimPrefix(b, "v"), ".")
	for i := 0; i < len(pa) && i < len(pb); i++ {
		x, _ := strconv.Atoi(pa[i])
		y, _ := strconv.Atoi(pb[i])
		if x != y {
			return x < y
		}
	}
	return len(pa) < len(pb)
}

// finishNode removes older versions of the line just installed and keeps
// current pointing at an installed line.
func (m *Manager) finishNode(t *txn.Tx, major, version string) error {
	entries, _ := os.ReadDir(m.h.Path(toolsDir + "/node"))
	current, _ := os.Readlink(m.h.Path(toolsDir + "/node/current"))
	for _, e := range entries {
		if mj, ok := nodeMajor(e.Name()); ok && mj == major && e.Name() != version {
			if current == e.Name() {
				// The line moves on to its new version.
				if err := t.ReplaceSymlink(version, toolsDir+"/node/current"); err != nil {
					return err
				}
				current = version
			}
			_ = os.RemoveAll(m.h.Path(toolsDir + "/node/" + e.Name()))
		}
	}
	return m.pickNode(t)
}

// pickNode leaves current alone while it points at an installed line, so
// installing a newer line never changes what builds use. Otherwise it
// picks the newest LTS line installed, or the newest line.
func (m *Manager) pickNode(t *txn.Tx) error {
	link := toolsDir + "/node/current"
	if m.DefaultNode() != "" {
		return nil
	}
	versions := m.NodeVersions()
	if len(versions) == 0 {
		if _, err := os.Lstat(m.h.Path(link)); err == nil {
			return os.Remove(m.h.Path(link))
		}
		return nil
	}
	majors := make([]string, 0, len(versions))
	for k := range versions {
		majors = append(majors, k)
	}
	sort.Slice(majors, func(i, j int) bool { return versionLess(majors[i], majors[j]) })
	pick := majors[len(majors)-1]
	for _, mj := range majors {
		for _, lts := range NodeLTS {
			if mj == lts {
				pick = mj
			}
		}
	}
	return t.ReplaceSymlink(versions[pick], link)
}

// RemoveNode deletes a Node.js line. Builds move to another installed
// line when they used this one.
func (m *Manager) RemoveNode(major string) error {
	if !nodeMajorRe.MatchString(major) {
		return invalid("node line %q must be a major version such as 24", major)
	}
	v, ok := m.NodeVersions()[major]
	if !ok {
		return nil
	}
	if err := os.RemoveAll(m.h.Path(toolsDir + "/node/" + v)); err != nil {
		return err
	}
	return m.pickNode(txn.New(m.h))
}

// LatestNode asks nodejs.org for the newest version of a line.
func (m *Manager) LatestNode(ctx context.Context, major string) (string, error) {
	if !nodeMajorRe.MatchString(major) {
		return "", invalid("node line %q must be a major version such as 24", major)
	}
	if m.sim {
		if v := simNode[major]; v != "" {
			return strings.Replace(v, ".1", ".2", 1), nil
		}
		return "v" + major + ".0.0", nil
	}
	arch := map[string]string{"amd64": "x64", "arm64": "arm64"}[runtime.GOARCH]
	sums, err := m.Download(ctx, nodeDist+"latest-v"+major+".x/SHASUMS256.txt")
	if err != nil {
		return "", err
	}
	for _, line := range strings.Split(string(sums), "\n") {
		f := strings.Fields(line)
		if len(f) == 2 && strings.HasPrefix(f[1], "node-v") && strings.HasSuffix(f[1], "-linux-"+arch+".tar.gz") {
			return strings.TrimSuffix(strings.TrimPrefix(f[1], "node-"), "-linux-"+arch+".tar.gz"), nil
		}
	}
	return "", fmt.Errorf("no linux-%s build of Node.js %s listed", arch, major)
}

// ComposerVersion is the installed Composer's version, "" when there is
// none.
func (m *Manager) ComposerVersion(ctx context.Context) string {
	if !m.exists(composerPhar()) {
		return ""
	}
	if m.sim {
		return "2.8.12"
	}
	php := ""
	entries, _ := os.ReadDir(m.h.Path("/usr/bin"))
	for _, e := range entries {
		if strings.HasPrefix(e.Name(), "php") && phpCLIRe.MatchString(e.Name()) {
			php = "/usr/bin/" + e.Name()
		}
	}
	if php == "" {
		return "?"
	}
	out, err := m.h.Run(ctx, host.Command{Name: php, Args: []string{composerPhar(), "--version", "--no-ansi", "--no-interaction"}, Env: []string{"COMPOSER_ALLOW_SUPERUSER=1", "COMPOSER_HOME=/nonexistent"}})
	if err != nil {
		return "?"
	}
	// "Composer version 2.8.12 2025-09-19 13:41:59"
	f := strings.Fields(out)
	for i, w := range f {
		if w == "version" && i+1 < len(f) {
			return f[i+1]
		}
	}
	return "?"
}

var phpCLIRe = regexp.MustCompile(`^php[0-9]\.[0-9]+$`)

// LatestComposer asks getcomposer.org for the newest stable version.
func (m *Manager) LatestComposer(ctx context.Context) (string, error) {
	if m.sim {
		return "2.8.12", nil
	}
	data, err := m.Download(ctx, "https://getcomposer.org/versions")
	if err != nil {
		return "", err
	}
	var v struct {
		Stable []struct {
			Version string `json:"version"`
		} `json:"stable"`
	}
	if err := json.Unmarshal(data, &v); err != nil || len(v.Stable) == 0 {
		return "", errors.New("getcomposer.org/versions did not list a stable version")
	}
	return v.Stable[0].Version, nil
}

// RemoveComposer deletes Serfex's Composer.
func (m *Manager) RemoveComposer() error {
	return os.RemoveAll(m.h.Path(toolsDir + "/composer"))
}
