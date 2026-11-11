// Package pinned downloads the releases Serfex takes from their makers
// rather than from Ubuntu (ModSecurity's rule set, the web database
// tools) and unpacks their archives. Each release is pinned to a version
// and the SHA-256 of its file in Serfex's source, so a download is only
// used when it is exactly the file Serfex was released with.
package pinned

import (
	"archive/tar"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"path"
	"path/filepath"
	"strings"
	"time"
)

// Release is a pinned file: its version, where it is downloaded from and
// its SHA-256.
type Release struct {
	Version, URL, SHA256 string
}

// Fetch downloads r's file with get and checks its SHA-256.
func (r Release) Fetch(ctx context.Context, get func(ctx context.Context, url string) ([]byte, error)) ([]byte, error) {
	data, err := get(ctx, r.URL)
	if err != nil {
		return nil, err
	}
	if sum := sha256.Sum256(data); hex.EncodeToString(sum[:]) != r.SHA256 {
		return nil, fmt.Errorf("%s has SHA-256 %x, not %s", r.URL, sum, r.SHA256)
	}
	return data, nil
}

// MaxDownload limits the size of a downloaded file.
const MaxDownload = 32 << 20

// Download fetches a release file over HTTPS, following redirects (GitHub
// serves release files from its storage).
func Download(ctx context.Context, url string) ([]byte, error) {
	ctx, cancel := context.WithTimeout(ctx, 2*time.Minute)
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
	data, err := io.ReadAll(io.LimitReader(res.Body, MaxDownload+1))
	if err == nil && len(data) > MaxDownload {
		err = fmt.Errorf("%s is larger than %d MB", url, MaxDownload>>20)
	}
	return data, err
}

// UnpackTar writes the entries of a tar stream into dir, which must
// exist: every entry must be under the archive's one top directory
// ("coreruleset-4.25.0/rules/x.conf" becomes dir/rules/x.conf), nothing
// may point outside it, links are left out and the files may hold at
// most max bytes together. Files are 0644 and directories 0755, owned
// by the agent (root).
func UnpackTar(r io.Reader, dir string, max int64) error {
	tr := tar.NewReader(r)
	var total int64
	top := ""
	for {
		hdr, err := tr.Next()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			return err
		}
		name := path.Clean(strings.TrimPrefix(hdr.Name, "./"))
		if name == ".." || strings.HasPrefix(name, "../") || path.IsAbs(name) {
			return fmt.Errorf("%q in the archive points outside it", hdr.Name)
		}
		first, rel, _ := strings.Cut(name, "/")
		if top == "" {
			top = first
		} else if first != top {
			return fmt.Errorf("%q in the archive is outside its directory %s", hdr.Name, top)
		}
		if rel == "" {
			continue
		}
		dst := filepath.Join(dir, filepath.FromSlash(rel))
		if !strings.HasPrefix(dst, filepath.Clean(dir)+string(os.PathSeparator)) {
			return fmt.Errorf("%q in the archive points outside it", hdr.Name)
		}
		switch hdr.Typeflag {
		case tar.TypeDir:
			if err := os.MkdirAll(dst, 0o755); err != nil {
				return err
			}
		case tar.TypeReg:
			if total += hdr.Size; total > max {
				return fmt.Errorf("the archive unpacks to more than %d MB", max>>20)
			}
			if err := os.MkdirAll(filepath.Dir(dst), 0o755); err != nil {
				return err
			}
			f, err := os.OpenFile(dst, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o644)
			if err != nil {
				return err
			}
			_, err = io.Copy(f, io.LimitReader(tr, hdr.Size))
			if cerr := f.Close(); err == nil {
				err = cerr
			}
			if err != nil {
				return err
			}
		}
	}
	if top == "" {
		return errors.New("the archive is empty")
	}
	return nil
}
