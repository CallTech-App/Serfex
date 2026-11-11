// Package aptrepo lists a software vendor's own apt archive next to
// Ubuntu's: its signing key is checked before apt trusts it, and a pin
// lets only the vendor's own packages come from it, even when Ubuntu has
// a higher version number. Serfex uses it where Ubuntu keeps a version
// its vendor no longer supports (PowerDNS, Rspamd).
package aptrepo

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"time"

	"github.com/calltech-app/serfex/agent/internal/aptcache"
	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/txn"
)

// Env is apt's environment for Serfex's commands.
var Env = []string{"DEBIAN_FRONTEND=noninteractive", "LC_ALL=C"}

// Archive is a vendor's apt archive.
type Archive struct {
	// Name names its files: /etc/apt/sources.list.d/<Name>.sources,
	// /etc/apt/preferences.d/<Name> and /etc/apt/keyrings/<Name>.asc.
	Name string
	// Title says in the sources file what the archive is for.
	Title string
	// URI is the archive's address, Host the origin apt reports for it
	// (the pin matches it) and Component the one Serfex uses.
	URI, Host, Component string
	// KeyURL is the signing key; Fingerprint is its full fingerprint
	// (40 hex digits), the only key the file may hold.
	KeyURL, Fingerprint string
	// Packages is the pin's package pattern ("pdns-*"). Versions, when
	// set, pins those versions ("4.*") instead of the archive's origin:
	// for an archive whose suite carries every new release of the vendor
	// (Rspamd's), so apt stays on the major version Serfex chose. Why
	// explains the pin.
	Packages, Versions, Why string
}

// Sources, Pin and Keyring are the archive's files.
func (a Archive) Sources() string { return "/etc/apt/sources.list.d/" + a.Name + ".sources" }
func (a Archive) Pin() string     { return "/etc/apt/preferences.d/" + a.Name }
func (a Archive) Keyring() string { return "/etc/apt/keyrings/" + a.Name + ".asc" }

// Files are the archive's files, sources first.
func (a Archive) Files() []string { return []string{a.Sources(), a.Pin(), a.Keyring()} }

// Listed reports whether apt reads the archive.
func (a Archive) Listed(h host.Host) bool {
	_, err := os.Stat(h.Path(a.Sources()))
	return err == nil
}

// SourcesFile is the deb822 sources file for suite.
func (a Archive) SourcesFile(suite string) []byte {
	return []byte(fmt.Sprintf("# %s. %s.\nTypes: deb\nURIs: %s\nSuites: %s\nComponents: %s\nSigned-By: %s\n",
		txn.Marker, a.Title, a.URI, suite, a.Component, a.Keyring()))
}

// PinFile makes apt take the pattern's packages from the archive only, or
// in Versions only, even when another archive has a higher version.
func (a Archive) PinFile() []byte {
	pin := "origin " + a.Host
	if a.Versions != "" {
		pin = "version " + a.Versions
	}
	return []byte("Explanation: " + txn.Marker + ".\nExplanation: " + a.Why + "\nPackage: " + a.Packages + "\nPin: " + pin + "\nPin-Priority: 600\n")
}

// Provides reports whether apt would install c from the archive.
func (a Archive) Provides(c aptcache.Candidate) bool {
	return strings.HasPrefix(c.From, a.Host)
}

// Add writes the archive's key, pin and sources (for suite) through t and
// refreshes apt's package lists. Undoing t takes them out again and
// refreshes the lists once more.
func (a Archive) Add(ctx context.Context, h host.Host, t *txn.Tx, suite string, key []byte, log io.Writer) error {
	update := host.Command{Name: "apt-get", Args: []string{"update", "-q"}, Env: Env, Stream: log}
	// Undone last: the lists are refreshed once the files are gone.
	t.OnUndo(func(ctx context.Context) error {
		_, err := h.Run(ctx, update)
		return err
	})
	for _, d := range []string{filepath.Dir(a.Keyring()), filepath.Dir(a.Sources()), filepath.Dir(a.Pin())} {
		if err := t.RootDir(d, 0o755, 0, 0); err != nil {
			return err
		}
	}
	if err := t.WriteOwned(a.Keyring(), key, 0o644, 0, 0); err != nil {
		return err
	}
	if err := t.WriteOwned(a.Pin(), a.PinFile(), 0o644, 0, 0); err != nil {
		return err
	}
	if err := t.WriteOwned(a.Sources(), a.SourcesFile(suite), 0o644, 0, 0); err != nil {
		return err
	}
	// apt only warns about a list it could not fetch; the caller checks
	// apt's candidate to know whether this one came.
	if _, err := h.Run(ctx, update); err != nil {
		return fmt.Errorf("apt-get update: %w", err)
	}
	return nil
}

// Drop takes the archive out of apt and refreshes the package lists.
func (a Archive) Drop(ctx context.Context, h host.Host, log io.Writer) error {
	for _, f := range a.Files() {
		if err := os.Remove(h.Path(f)); err != nil && !errors.Is(err, os.ErrNotExist) {
			return err
		}
	}
	_, err := h.Run(ctx, host.Command{Name: "apt-get", Args: []string{"update", "-q"}, Env: Env, Stream: log})
	return err
}

// FetchKey downloads the archive's signing key and checks that the file
// holds that key and no other. fetch nil downloads over HTTPS; a dry run
// (sim) gets a placeholder.
func (a Archive) FetchKey(ctx context.Context, h host.Host, fetch func(context.Context, string) ([]byte, error), sim bool) ([]byte, error) {
	if sim {
		return []byte("simulated key from " + a.KeyURL + "\n"), nil
	}
	if fetch == nil {
		fetch = Download
	}
	key, err := fetch(ctx, a.KeyURL)
	if err != nil {
		return nil, err
	}
	if !bytes.HasPrefix(bytes.TrimSpace(key), []byte("-----BEGIN PGP PUBLIC KEY BLOCK-----")) {
		return nil, fmt.Errorf("%s is not a PGP public key", a.KeyURL)
	}
	out, err := h.Run(ctx, host.Command{Name: "gpg", Args: []string{"--batch", "--with-colons", "--show-keys"}, Stdin: key})
	if err != nil {
		return nil, fmt.Errorf("reading %s: %w", a.KeyURL, err)
	}
	if err := checkKeys(out, a.Fingerprint); err != nil {
		return nil, fmt.Errorf("%s: %w", a.KeyURL, err)
	}
	return key, nil
}

// checkKeys reads "gpg --with-colons --show-keys" and requires exactly one
// primary key, with the fingerprint given: apt trusts every key in the
// file it is pointed at.
func checkKeys(out, fingerprint string) error {
	lines := strings.Split(out, "\n")
	var found []string
	for i, line := range lines {
		if !strings.HasPrefix(line, "pub:") {
			continue
		}
		fpr := ""
		if i+1 < len(lines) {
			if f := strings.Split(lines[i+1], ":"); len(f) > 9 && f[0] == "fpr" {
				fpr = strings.ToUpper(f[9])
			}
		}
		found = append(found, fpr)
	}
	switch {
	case len(found) == 0:
		return errors.New("no key in the file")
	case len(found) > 1:
		return fmt.Errorf("the file holds %d keys, not only %s", len(found), fingerprint)
	case found[0] != strings.ToUpper(fingerprint):
		return fmt.Errorf("the key is %s, not %s", found[0], fingerprint)
	}
	return nil
}

// Codename is the Ubuntu release's codename ("noble").
func Codename(h host.Host) (string, error) {
	b, err := os.ReadFile(h.Path("/etc/os-release"))
	if err != nil {
		return "", err
	}
	for _, line := range strings.Split(string(b), "\n") {
		if v, ok := strings.CutPrefix(line, "VERSION_CODENAME="); ok {
			if v = strings.Trim(strings.TrimSpace(v), `"'`); v != "" {
				return v, nil
			}
		}
	}
	return "", errors.New("the Ubuntu release's codename is unknown")
}

// Candidate is the version of pkg apt would install, and where from.
func Candidate(ctx context.Context, h host.Host, pkg string) aptcache.Candidate {
	out, _ := h.Run(ctx, host.Command{Name: "apt-cache", Args: []string{"policy", pkg}, Env: []string{"LC_ALL=C"}})
	return aptcache.Policy(out)[pkg]
}

// PackageVersion is the installed version of pkg ("" when it is not
// installed).
func PackageVersion(ctx context.Context, h host.Host, pkg string) string {
	out, err := h.Run(ctx, host.Command{Name: "dpkg-query", Args: []string{"-W", "-f=${db:Status-Status} ${Version}", pkg}})
	if status, v, ok := strings.Cut(strings.TrimSpace(out), " "); err == nil && ok && status == "installed" {
		return v
	}
	return ""
}

// SaveDebs downloads the .deb files of the packages in the versions given
// (package → version) into dir, so they can be installed again without
// their archive (InstallDebs).
func SaveDebs(ctx context.Context, h host.Host, dir string, versions map[string]string, log io.Writer) error {
	var args []string
	for p, v := range versions {
		if v == "" {
			return fmt.Errorf("%s is not installed", p)
		}
		args = append(args, p+"="+v)
	}
	sort.Strings(args)
	if err := os.MkdirAll(h.Path(dir), 0o700); err != nil {
		return err
	}
	if _, err := h.Run(ctx, host.Command{Name: "env", Args: append([]string{"-C", dir, "apt-get", "download", "-q"}, args...), Env: Env, Stream: log}); err != nil {
		return err
	}
	if _, sim := h.(*host.Simulator); sim {
		for p, v := range versions {
			_ = os.WriteFile(h.Path(dir+"/"+p+"_"+v+"_amd64.deb"), nil, 0o644)
		}
	}
	debs, _ := filepath.Glob(h.Path(dir + "/*.deb"))
	if len(debs) != len(versions) {
		return fmt.Errorf("apt-get download left %d package files, want %d", len(debs), len(versions))
	}
	return nil
}

// InstallDebs installs the .deb files SaveDebs kept in dir with dpkg,
// also over newer versions: exactly these files, even when apt could
// fetch the same versions again. Configuration files nobody changed get
// those versions' own; a dependency apt removed meanwhile is installed
// again from the archives apt lists.
func InstallDebs(ctx context.Context, h host.Host, dir string, log io.Writer) error {
	debs, _ := filepath.Glob(h.Path(dir + "/*.deb"))
	if len(debs) == 0 {
		return fmt.Errorf("no package files in %s", dir)
	}
	args := []string{"--force-confdef", "--force-confold", "-i"}
	for _, d := range debs {
		// dpkg takes a path; on a simulated host it is under its root.
		rel, _ := filepath.Rel(h.Path("/"), d)
		args = append(args, "/"+rel)
	}
	_, err := h.Run(ctx, host.Command{Name: "dpkg", Args: args, Env: Env, Stream: log})
	if err == nil {
		return nil
	}
	if _, ferr := h.Run(ctx, host.Command{Name: "apt-get", Args: []string{"install", "-f", "-y", "-q", "-o", "Dpkg::Options::=--force-confdef", "-o", "Dpkg::Options::=--force-confold"}, Env: Env, Stream: log}); ferr != nil {
		return fmt.Errorf("installing %s: %w", strings.Join(args[3:], " "), err)
	}
	return nil
}

// Download fetches a small file (a key) over HTTPS.
func Download(ctx context.Context, url string) ([]byte, error) {
	ctx, cancel := context.WithTimeout(ctx, 30*time.Second)
	defer cancel()
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return nil, err
	}
	req.Header.Set("User-Agent", "serfex-agent")
	res, err := http.DefaultClient.Do(req)
	if err != nil {
		return nil, err
	}
	defer res.Body.Close()
	if res.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("%s: %s", url, res.Status)
	}
	return io.ReadAll(io.LimitReader(res.Body, 1<<20))
}
