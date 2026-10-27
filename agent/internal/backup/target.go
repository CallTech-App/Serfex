package backup

import (
	"context"
	"errors"
	"fmt"
	"io"
	"net"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"

	"github.com/calltech-app/serfex/agent/internal/hostname"
)

// Remote storage a backup copy is sent to. The panel keeps the targets
// and their credentials (encrypted) and sends the one to use with every
// call, so the agent stores none of them.

// TargetSpec is a remote storage.
type TargetSpec struct {
	// Type is "s3" (any S3-compatible storage) or "sftp".
	Type string `json:"type"`
	// Prefix is the folder backups go under, for example
	// "servik/srv-ist-01".
	Prefix string `json:"prefix"`

	// S3: Endpoint is a host name (or URL) such as s3.eu-central-1.amazonaws.com;
	// empty means AWS in Region.
	Endpoint  string `json:"endpoint,omitempty"`
	Region    string `json:"region,omitempty"`
	Bucket    string `json:"bucket,omitempty"`
	AccessKey string `json:"access_key,omitempty"`
	SecretKey string `json:"secret_key,omitempty"`
	// PathStyle puts the bucket in the path (MinIO and most self-hosted
	// storage) instead of the host name.
	PathStyle bool `json:"path_style,omitempty"`

	// SFTP: the server signs in with the agent's own SSH key.
	Host string `json:"host,omitempty"`
	Port int    `json:"port,omitempty"`
	User string `json:"user,omitempty"`
	// HostKey is the server's key ("ssh-ed25519 AAAA..."), learned on the
	// first test and checked on every connection after.
	HostKey string `json:"host_key,omitempty"`
}

// Object is a file on a target.
type Object struct {
	Name string `json:"name"`
	Size int64  `json:"size"`
}

// Target stores files under names relative to its prefix.
type Target interface {
	// Put uploads the local file as name.
	Put(ctx context.Context, name, file string) error
	// Get downloads name into w.
	Get(ctx context.Context, name string, w io.Writer) error
	// Delete removes name; a missing file is not an error.
	Delete(ctx context.Context, name string) error
	// List returns the files whose name starts with dir + "/" (all files
	// for an empty dir), recursively.
	List(ctx context.Context, dir string) ([]Object, error)
}

var (
	prefixRe  = regexp.MustCompile(`^[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)*$`)
	bucketRe  = regexp.MustCompile(`^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$`)
	regionRe  = regexp.MustCompile(`^[a-z0-9-]{1,32}$`)
	sshUserRe = regexp.MustCompile(`^[A-Za-z0-9._][A-Za-z0-9._-]{0,63}$`)
	keyIDRe   = regexp.MustCompile(`^[\x21-\x7e]{1,256}$`)
)

func (t TargetSpec) validate() error {
	if t.Prefix != "" && (!prefixRe.MatchString(t.Prefix) || len(t.Prefix) > 200 || strings.Contains("/"+t.Prefix+"/", "/../") || strings.Contains("/"+t.Prefix+"/", "/./")) {
		return invalid("the folder may only contain letters, digits, '.', '_', '-' and '/'")
	}
	switch t.Type {
	case "s3":
		if !bucketRe.MatchString(t.Bucket) {
			return invalid("bucket %q is not a valid bucket name", t.Bucket)
		}
		if t.Region != "" && !regionRe.MatchString(t.Region) {
			return invalid("region %q is not valid", t.Region)
		}
		if !keyIDRe.MatchString(t.AccessKey) || !keyIDRe.MatchString(t.SecretKey) {
			return invalid("an access key and a secret key are required")
		}
		if t.Endpoint != "" {
			if _, err := t.endpointURL(); err != nil {
				return err
			}
		}
	case "sftp":
		if err := validHost(t.Host); err != nil {
			return err
		}
		if t.Port < 0 || t.Port > 65535 {
			return invalid("port must be between 1 and 65535")
		}
		if !sshUserRe.MatchString(t.User) {
			return invalid("user name %q is not valid", t.User)
		}
		if t.HostKey != "" {
			if _, err := parseHostKey(t.HostKey); err != nil {
				return invalid("the server's host key is not valid: %s", err)
			}
		}
	default:
		return invalid("target type must be s3 or sftp")
	}
	return nil
}

// validHost accepts a host name or an IP address.
func validHost(h string) error {
	if net.ParseIP(h) != nil {
		return nil
	}
	if err := hostname.Validate(h); err != nil && h != "localhost" {
		return invalid("server %q is not a host name or IP address", h)
	}
	return nil
}

// endpointURL is the S3 endpoint as a URL: https unless written as http://.
func (t TargetSpec) endpointURL() (*url.URL, error) {
	ep := t.Endpoint
	if ep == "" {
		region := t.Region
		if region == "" {
			region = "us-east-1"
		}
		ep = "s3." + region + ".amazonaws.com"
	}
	if !strings.Contains(ep, "://") {
		ep = "https://" + ep
	}
	u, err := url.Parse(ep)
	if err != nil || (u.Scheme != "https" && u.Scheme != "http") || u.Host == "" || (u.Path != "" && u.Path != "/") || u.RawQuery != "" || u.User != nil {
		return nil, invalid("endpoint %q must be a host name such as s3.eu-central-1.amazonaws.com", t.Endpoint)
	}
	if err := validHost(u.Hostname()); err != nil {
		return nil, err
	}
	u.Path = ""
	return u, nil
}

// address describes the target for logs.
func (t TargetSpec) address() string {
	if t.Type == "sftp" {
		return t.User + "@" + t.Host + ":" + t.Prefix
	}
	return t.Bucket + "/" + t.Prefix
}

// join puts a name under the target's prefix.
func (t TargetSpec) join(name string) string {
	if t.Prefix == "" {
		return name
	}
	return t.Prefix + "/" + name
}

// validName checks a name relative to a prefix: the same characters, no
// "." or ".." segments.
func validName(name string) error {
	if !prefixRe.MatchString(name) || strings.Contains("/"+name+"/", "/../") || strings.Contains("/"+name+"/", "/./") {
		return fmt.Errorf("invalid name %q", name)
	}
	return nil
}

// dirTarget keeps files in a local directory. Dry runs use it for every
// remote target, so the panel can be tried without storage.
type dirTarget struct {
	dir string
}

func (d dirTarget) path(name string) (string, error) {
	if err := validName(name); err != nil {
		return "", err
	}
	return filepath.Join(d.dir, filepath.FromSlash(name)), nil
}

func (d dirTarget) Put(_ context.Context, name, file string) error {
	p, err := d.path(name)
	if err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(p), 0o700); err != nil {
		return err
	}
	src, err := os.Open(file)
	if err != nil {
		return err
	}
	defer src.Close()
	dst, err := os.OpenFile(p+".part", os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o600)
	if err != nil {
		return err
	}
	if _, err := io.Copy(dst, src); err != nil {
		dst.Close()
		return err
	}
	if err := dst.Close(); err != nil {
		return err
	}
	return os.Rename(p+".part", p)
}

func (d dirTarget) Get(_ context.Context, name string, w io.Writer) error {
	p, err := d.path(name)
	if err != nil {
		return err
	}
	f, err := os.Open(p)
	if errors.Is(err, os.ErrNotExist) {
		return fmt.Errorf("%s is not on the target", name)
	}
	if err != nil {
		return err
	}
	defer f.Close()
	_, err = io.Copy(w, f)
	return err
}

func (d dirTarget) Delete(_ context.Context, name string) error {
	p, err := d.path(name)
	if err != nil {
		return err
	}
	if err := os.Remove(p); err != nil && !errors.Is(err, os.ErrNotExist) {
		return err
	}
	// Leave no empty backup folders behind.
	for dir := filepath.Dir(p); dir != d.dir && strings.HasPrefix(dir, d.dir); dir = filepath.Dir(dir) {
		if os.Remove(dir) != nil {
			break
		}
	}
	return nil
}

func (d dirTarget) List(_ context.Context, dir string) ([]Object, error) {
	root := d.dir
	if dir != "" {
		p, err := d.path(dir)
		if err != nil {
			return nil, err
		}
		root = p
	}
	var out []Object
	err := filepath.WalkDir(root, func(p string, e os.DirEntry, err error) error {
		if err != nil {
			if errors.Is(err, os.ErrNotExist) {
				return nil
			}
			return err
		}
		if e.IsDir() || strings.HasSuffix(p, ".part") {
			return nil
		}
		fi, err := e.Info()
		if err != nil {
			return err
		}
		rel, _ := filepath.Rel(d.dir, p)
		out = append(out, Object{Name: filepath.ToSlash(rel), Size: fi.Size()})
		return nil
	})
	sort.Slice(out, func(i, j int) bool { return out[i].Name < out[j].Name })
	return out, err
}

// prefixed lists and names files relative to a TargetSpec prefix.
type prefixed struct {
	t      Target
	prefix string
}

func (p prefixed) name(n string) string {
	if p.prefix == "" {
		return n
	}
	return p.prefix + "/" + n
}

func (p prefixed) Put(ctx context.Context, name, file string) error {
	return p.t.Put(ctx, p.name(name), file)
}

func (p prefixed) Get(ctx context.Context, name string, w io.Writer) error {
	return p.t.Get(ctx, p.name(name), w)
}

func (p prefixed) Delete(ctx context.Context, name string) error {
	return p.t.Delete(ctx, p.name(name))
}

func (p prefixed) List(ctx context.Context, dir string) ([]Object, error) {
	full := p.prefix
	if dir != "" {
		full = p.name(dir)
	}
	objs, err := p.t.List(ctx, full)
	if err != nil {
		return nil, err
	}
	out := objs[:0]
	for _, o := range objs {
		if p.prefix != "" {
			rest, ok := strings.CutPrefix(o.Name, p.prefix+"/")
			if !ok {
				continue
			}
			o.Name = rest
		}
		out = append(out, o)
	}
	return out, nil
}
