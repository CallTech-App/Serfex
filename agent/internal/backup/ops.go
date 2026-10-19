package backup

import (
	"archive/tar"
	"bytes"
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/json"
	"encoding/pem"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"syscall"
	"time"

	"golang.org/x/crypto/ssh"
)

// DeleteSpec removes a backup's archives.
type DeleteSpec struct {
	Key string `json:"key"`
	// Names limits the deletion to some archives; empty means all.
	Names []string `json:"names,omitempty"`
	// Local removes the copy on this server.
	Local bool `json:"local"`
	// Target, when set, removes the copy there too.
	Target *TargetSpec `json:"target,omitempty"`
}

// Delete removes a backup from the server and its target.
func (m *Manager) Delete(ctx context.Context, spec DeleteSpec) (map[string]bool, error) {
	if err := validateKey("backup", spec.Key); err != nil {
		return nil, err
	}
	for _, n := range spec.Names {
		if err := validateName(n); err != nil {
			return nil, err
		}
	}
	if spec.Target != nil {
		if err := spec.Target.validate(); err != nil {
			return nil, err
		}
	}
	dir := m.h.Path(setDir(spec.Key))
	if spec.Local {
		if len(spec.Names) == 0 {
			if err := os.RemoveAll(dir); err != nil {
				return nil, opError(err)
			}
		} else {
			for _, n := range spec.Names {
				if err := os.Remove(m.h.Path(archivePath(spec.Key, n))); err != nil && !errors.Is(err, os.ErrNotExist) {
					return nil, opError(err)
				}
			}
		}
	}
	if spec.Target != nil {
		t, err := m.target(*spec.Target)
		if err != nil {
			return nil, err
		}
		names := spec.Names
		all := len(names) == 0
		if all {
			objs, err := t.List(ctx, spec.Key)
			if err != nil {
				return nil, opError(err)
			}
			for _, o := range objs {
				if err := t.Delete(ctx, o.Name); err != nil {
					return nil, opError(err)
				}
			}
		} else {
			for _, n := range names {
				for _, suffix := range []string{".tar", ".tar.enc"} {
					if err := t.Delete(ctx, spec.Key+"/"+n+suffix); err != nil {
						return nil, opError(err)
					}
				}
			}
		}
	}
	return map[string]bool{"deleted": true}, nil
}

// Chunk is part of a file, for downloads.
type Chunk struct {
	Data   []byte `json:"data"`
	Offset int64  `json:"offset"`
	Size   int64  `json:"size"`
}

func readChunk(file string, offset int64) (Chunk, error) {
	f, err := os.Open(file)
	if errors.Is(err, os.ErrNotExist) {
		return Chunk{}, invalid("the backup is not on the server")
	}
	if err != nil {
		return Chunk{}, opError(err)
	}
	defer f.Close()
	fi, err := f.Stat()
	if err != nil {
		return Chunk{}, opError(err)
	}
	if offset < 0 || offset > fi.Size() {
		return Chunk{}, invalid("offset is outside the file")
	}
	buf := make([]byte, chunkSize)
	n, err := f.ReadAt(buf, offset)
	if err != nil && !errors.Is(err, io.EOF) {
		return Chunk{}, opError(err)
	}
	return Chunk{Data: buf[:n], Offset: offset + int64(n), Size: fi.Size()}, nil
}

// Read returns up to 4 MB of an archive on the server.
func (m *Manager) Read(key, name string, offset int64) (Chunk, error) {
	if err := validateKey("backup", key); err != nil {
		return Chunk{}, err
	}
	if err := validateName(name); err != nil {
		return Chunk{}, err
	}
	return readChunk(m.h.Path(archivePath(key, name)), offset)
}

// ReadMember returns up to 4 MB of one member of an archive (the panel
// reads its database dump and settings this way when rebuilding).
func (m *Manager) ReadMember(key, name, member string, offset int64) (Chunk, error) {
	if err := validateKey("backup", key); err != nil {
		return Chunk{}, err
	}
	if err := validateName(name); err != nil {
		return Chunk{}, err
	}
	if member != "panel.sql.gz" && member != "panel.env" && member != "site.json" {
		return Chunk{}, invalid("member %q cannot be read", member)
	}
	cache := m.h.Path(setDir(key) + "/.members/" + name)
	file := filepath.Join(cache, member)
	if _, err := os.Stat(file); err != nil {
		if err := os.MkdirAll(cache, 0o700); err != nil {
			return Chunk{}, opError(err)
		}
		if _, err := m.unpack(m.h.Path(archivePath(key, name)), cache, func(n string) bool { return n == member || n == "manifest.json" }); err != nil {
			return Chunk{}, opError(err)
		}
		if _, err := os.Stat(file); err != nil {
			return Chunk{}, invalid("the archive has no %s", member)
		}
	}
	return readChunk(file, offset)
}

// FetchSpec copies archives from a target to the server.
type FetchSpec struct {
	Key        string     `json:"key"`
	Names      []string   `json:"names"`
	Target     TargetSpec `json:"target"`
	Passphrase string     `json:"passphrase,omitempty"`
}

// Fetch downloads archives from the target (for a download, or before a
// restore), decrypting them.
func (m *Manager) Fetch(ctx context.Context, spec FetchSpec) (map[string]int64, error) {
	if len(spec.Names) == 0 {
		return nil, invalid("name the archives to fetch")
	}
	release, err := m.acquire(ctx)
	if err != nil {
		return nil, err
	}
	defer release()
	if err := m.dirs(); err != nil {
		return nil, opError(err)
	}
	out := map[string]int64{}
	for _, n := range spec.Names {
		t := spec.Target
		ref := ChainRef{Key: spec.Key, Name: n, Target: &t}
		if err := ref.validate(); err != nil {
			return nil, err
		}
		if err := m.ensureLocal(ctx, ref, spec.Passphrase, io.Discard); err != nil {
			return nil, opError(err)
		}
		out[n] = fileSize(m.h.Path(archivePath(spec.Key, n)))
	}
	return out, nil
}

// Inspection is what an archive says about itself.
type Inspection struct {
	Manifest Manifest        `json:"manifest"`
	Meta     json.RawMessage `json:"meta,omitempty"`
	Size     int64           `json:"size"`
}

// Inspect reads an archive's manifest and site description.
func (m *Manager) Inspect(key, name string) (Inspection, error) {
	if err := validateKey("backup", key); err != nil {
		return Inspection{}, err
	}
	if err := validateName(name); err != nil {
		return Inspection{}, err
	}
	return m.inspectFile(m.h.Path(archivePath(key, name)))
}

func (m *Manager) inspectFile(file string) (Inspection, error) {
	f, err := os.Open(file)
	if errors.Is(err, os.ErrNotExist) {
		return Inspection{}, invalid("the backup is not on the server")
	}
	if err != nil {
		return Inspection{}, opError(err)
	}
	defer f.Close()
	var in Inspection
	if fi, err := f.Stat(); err == nil {
		in.Size = fi.Size()
	}
	tr := tar.NewReader(f)
	found := false
	for {
		hdr, err := tr.Next()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			return Inspection{}, invalid("the file is not a Servik backup archive")
		}
		switch hdr.Name {
		case "manifest.json":
			if err := json.NewDecoder(io.LimitReader(tr, 1<<20)).Decode(&in.Manifest); err != nil {
				return Inspection{}, invalid("the backup's manifest.json is damaged")
			}
			found = true
		case "site.json":
			b, err := io.ReadAll(io.LimitReader(tr, 4<<20))
			if err == nil && json.Valid(b) {
				in.Meta = b
			}
		}
	}
	if !found || in.Manifest.Version != 1 || (in.Manifest.Kind != "site" && in.Manifest.Kind != "panel") {
		return Inspection{}, invalid("the file is not a Servik backup archive")
	}
	if validateName(in.Manifest.Name) != nil || validateKey("backup", in.Manifest.Key) != nil {
		return Inspection{}, invalid("the backup's manifest.json is damaged")
	}
	return in, nil
}

// Found is a backup found on a target or in the import folder.
type Found struct {
	// Path is the folder below the scanned one the backup is in (the
	// other server's name, for backups another server took).
	Path       string      `json:"path,omitempty"`
	File       string      `json:"file,omitempty"`
	Descriptor *Descriptor `json:"descriptor,omitempty"`
	Inspection *Inspection `json:"inspection,omitempty"`
	Encrypted  bool        `json:"encrypted,omitempty"`
}

// Scan lists the backups on a target (under its prefix, from any server),
// or with no target the archives copied to the import folder.
func (m *Manager) Scan(ctx context.Context, target *TargetSpec) ([]Found, error) {
	out := []Found{}
	if target == nil {
		if err := m.dirs(); err != nil {
			return nil, opError(err)
		}
		entries, err := os.ReadDir(m.h.Path(ImportDir))
		if err != nil {
			return nil, opError(err)
		}
		for _, e := range entries {
			if !e.Type().IsRegular() || !(strings.HasSuffix(e.Name(), ".tar") || strings.HasSuffix(e.Name(), ".tar.enc")) || !importNameRe.MatchString(e.Name()) {
				continue
			}
			f := Found{File: e.Name()}
			p := m.h.Path(ImportDir + "/" + e.Name())
			if encryptedFile(p) {
				f.Encrypted = true
			} else if in, err := m.inspectFile(p); err == nil {
				f.Inspection = &in
			} else {
				continue
			}
			out = append(out, f)
		}
		return out, nil
	}
	t, err := m.target(*target)
	if err != nil {
		return nil, err
	}
	objs, err := t.List(ctx, "")
	if err != nil {
		return nil, opError(err)
	}
	for _, o := range objs {
		dir, ok := strings.CutSuffix(o.Name, "/backup.json")
		if !ok || o.Size > 4<<20 {
			continue
		}
		var buf bytes.Buffer
		if err := t.Get(ctx, o.Name, &buf); err != nil {
			continue
		}
		var d Descriptor
		if json.Unmarshal(buf.Bytes(), &d) != nil || d.Version != 1 || validateKey("backup", d.Key) != nil {
			continue
		}
		path := strings.TrimSuffix(strings.TrimSuffix(dir, d.Key), "/")
		if path != "" && validName(path) != nil {
			continue
		}
		out = append(out, Found{Path: path, Descriptor: &d, Encrypted: d.Encrypted})
	}
	sort.Slice(out, func(i, j int) bool { return out[i].Descriptor.Created.After(out[j].Descriptor.Created) })
	return out, nil
}

var importNameRe = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._-]{0,200}\.tar(\.enc)?$`)

func encryptedFile(p string) bool {
	f, err := os.Open(p)
	if err != nil {
		return false
	}
	defer f.Close()
	head := make([]byte, len(encMagic))
	n, _ := io.ReadFull(f, head)
	return isEncrypted(head[:n])
}

// ImportSpec brings an archive into the backups: one copied to the import
// folder (File) or uploaded from the panel (Upload).
type ImportSpec struct {
	File       string `json:"file,omitempty"`
	Upload     string `json:"upload,omitempty"`
	Key        string `json:"key"`
	Passphrase string `json:"passphrase,omitempty"`
}

// Import moves an archive into a new backup set and returns what it holds.
func (m *Manager) Import(ctx context.Context, spec ImportSpec) (Inspection, error) {
	if err := validateKey("backup", spec.Key); err != nil {
		return Inspection{}, err
	}
	var src string
	switch {
	case spec.File != "" && spec.Upload == "":
		if !importNameRe.MatchString(spec.File) {
			return Inspection{}, invalid("file %q is not valid", spec.File)
		}
		src = m.h.Path(ImportDir + "/" + spec.File)
		fi, err := os.Lstat(src)
		if err != nil || !fi.Mode().IsRegular() {
			return Inspection{}, invalid("%s is not in the import folder", spec.File)
		}
	case spec.Upload != "" && spec.File == "":
		if err := validateKey("upload", spec.Upload); err != nil {
			return Inspection{}, err
		}
		src = m.h.Path(uploadsDir + "/" + spec.Upload + ".part")
	default:
		return Inspection{}, invalid("give a file or an upload")
	}
	release, err := m.acquire(ctx)
	if err != nil {
		return Inspection{}, err
	}
	defer release()
	if err := m.dirs(); err != nil {
		return Inspection{}, opError(err)
	}
	dir := m.h.Path(setDir(spec.Key))
	if err := os.Mkdir(dir, 0o700); err != nil {
		return Inspection{}, invalid("backup %s exists already", spec.Key)
	}
	staged := filepath.Join(dir, ".import.tar")
	if encryptedFile(src) {
		if spec.Passphrase == "" {
			os.RemoveAll(dir)
			return Inspection{}, invalid("the archive is encrypted; enter its passphrase")
		}
		if err := decryptFile(src, staged, spec.Passphrase); err != nil {
			os.RemoveAll(dir)
			return Inspection{}, opError(err)
		}
		os.Remove(src)
	} else if err := os.Rename(src, staged); err != nil {
		os.RemoveAll(dir)
		return Inspection{}, opError(err)
	}
	in, err := m.inspectFile(staged)
	if err != nil {
		// Leave a file copied by hand where it was.
		if spec.File != "" {
			_ = os.Rename(staged, src)
		}
		os.RemoveAll(dir)
		return Inspection{}, err
	}
	if err := os.Rename(staged, m.h.Path(archivePath(spec.Key, in.Manifest.Name))); err != nil {
		os.RemoveAll(dir)
		return Inspection{}, opError(err)
	}
	in.Size = fileSize(m.h.Path(archivePath(spec.Key, in.Manifest.Name)))
	return in, nil
}

// UploadWrite appends data to an upload at offset (the upload's size so
// far) and returns the new size.
func (m *Manager) UploadWrite(upload string, offset int64, data []byte) (map[string]int64, error) {
	if err := validateKey("upload", upload); err != nil {
		return nil, err
	}
	if err := m.dirs(); err != nil {
		return nil, opError(err)
	}
	m.pruneUploads()
	p := m.h.Path(uploadsDir + "/" + upload + ".part")
	flags := os.O_CREATE | os.O_WRONLY | syscall.O_NOFOLLOW
	if offset == 0 {
		flags |= os.O_TRUNC
	}
	f, err := os.OpenFile(p, flags, 0o600)
	if err != nil {
		return nil, opError(err)
	}
	defer f.Close()
	fi, err := f.Stat()
	if err != nil {
		return nil, opError(err)
	}
	if fi.Size() != offset {
		return nil, invalid("the upload is at %d bytes, not %d", fi.Size(), offset)
	}
	if _, err := f.WriteAt(data, offset); err != nil {
		return nil, opError(err)
	}
	return map[string]int64{"size": offset + int64(len(data))}, opError(f.Close())
}

// pruneUploads removes uploads nobody finished within a day.
func (m *Manager) pruneUploads() {
	entries, err := os.ReadDir(m.h.Path(uploadsDir))
	if err != nil {
		return
	}
	for _, e := range entries {
		if fi, err := e.Info(); err == nil && m.now().Sub(fi.ModTime()) > 24*time.Hour {
			_ = os.Remove(filepath.Join(m.h.Path(uploadsDir), e.Name()))
		}
	}
}

// Status is the local backup storage and what the panel needs to add
// targets.
type Status struct {
	Path      string `json:"path"`
	Used      int64  `json:"used"`
	Total     int64  `json:"total"`
	Free      int64  `json:"free"`
	PublicKey string `json:"public_key"`
	ImportDir string `json:"import_dir"`
	Busy      bool   `json:"busy"`
}

// Status reports the local storage, and the SSH key for SFTP targets.
func (m *Manager) Status() (Status, error) {
	if err := m.dirs(); err != nil {
		return Status{}, opError(err)
	}
	st := Status{Path: Dir, ImportDir: ImportDir, Busy: m.Busy()}
	_ = filepath.WalkDir(m.h.Path(setsDir), func(_ string, e os.DirEntry, err error) error {
		if err == nil && e.Type().IsRegular() {
			if fi, err := e.Info(); err == nil {
				st.Used += fi.Size()
			}
		}
		return nil
	})
	var fs syscall.Statfs_t
	if syscall.Statfs(m.h.Path(Dir), &fs) == nil {
		st.Total = int64(fs.Blocks) * int64(fs.Bsize)
		st.Free = int64(fs.Bavail) * int64(fs.Bsize)
	}
	key, err := m.PublicKey()
	if err != nil {
		return Status{}, opError(err)
	}
	st.PublicKey = key
	return st, nil
}

// PublicKey returns the agent's SSH key for SFTP targets, making it the
// first time.
func (m *Manager) PublicKey() (string, error) {
	priv, pub := m.h.Path(keyFile), m.h.Path(keyFile+".pub")
	if b, err := os.ReadFile(pub); err == nil {
		if _, err := os.Stat(priv); err == nil {
			return strings.TrimSpace(string(b)), nil
		}
	}
	if err := os.MkdirAll(filepath.Dir(priv), 0o700); err != nil {
		return "", err
	}
	pk, sk, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		return "", err
	}
	name, _ := os.Hostname()
	block, err := ssh.MarshalPrivateKey(sk, "servik-backup@"+name)
	if err != nil {
		return "", err
	}
	sp, err := ssh.NewPublicKey(pk)
	if err != nil {
		return "", err
	}
	line := strings.TrimSpace(string(ssh.MarshalAuthorizedKey(sp))) + " servik-backup@" + name
	if err := os.WriteFile(priv, pem.EncodeToMemory(block), 0o600); err != nil {
		return "", err
	}
	if err := os.WriteFile(pub, []byte(line+"\n"), 0o644); err != nil {
		return "", err
	}
	return line, nil
}

// TestResult is a successful target test.
type TestResult struct {
	OK bool `json:"ok"`
	// HostKey is the SFTP server's key, learned when it was not given.
	HostKey     string `json:"host_key,omitempty"`
	Fingerprint string `json:"fingerprint,omitempty"`
}

// TestTarget checks that a target can be reached and written to: a small
// file is written, listed, read back and removed.
func (m *Manager) TestTarget(ctx context.Context, spec TargetSpec) (TestResult, error) {
	if err := spec.validate(); err != nil {
		return TestResult{}, err
	}
	ctx, cancel := context.WithTimeout(ctx, 2*time.Minute)
	defer cancel()
	res := TestResult{OK: true}
	if spec.Type == "sftp" {
		if _, err := m.PublicKey(); err != nil {
			return TestResult{}, opError(err)
		}
		if spec.HostKey == "" && !m.sim {
			port := spec.Port
			if port == 0 {
				port = 22
			}
			key, err := scanHostKey(ctx, m.h, spec.Host, port)
			if err != nil {
				return TestResult{}, opError(err)
			}
			spec.HostKey = key
		}
		res.HostKey = spec.HostKey
		res.Fingerprint = Fingerprint(spec.HostKey)
	}
	if err := m.dirs(); err != nil {
		return TestResult{}, opError(err)
	}
	t, err := m.target(spec)
	if err != nil {
		return TestResult{}, err
	}
	name := ".servik-test-" + randomHex(6)
	probe := m.h.Path(workRoot + "/" + name)
	content := []byte("Servik backup target test " + m.now().UTC().Format(time.RFC3339) + "\n")
	if err := os.WriteFile(probe, content, 0o600); err != nil {
		return TestResult{}, opError(err)
	}
	defer os.Remove(probe)
	if err := t.Put(ctx, name, probe); err != nil {
		return TestResult{}, opError(fmt.Errorf("writing a test file failed: %w", err))
	}
	var back bytes.Buffer
	getErr := t.Get(ctx, name, &back)
	delErr := t.Delete(ctx, name)
	switch {
	case getErr != nil:
		return TestResult{}, opError(fmt.Errorf("reading the test file back failed: %w", getErr))
	case !bytes.Equal(back.Bytes(), content):
		return TestResult{}, opError(errors.New("the test file came back different"))
	case delErr != nil:
		return TestResult{}, opError(fmt.Errorf("deleting the test file failed: %w", delErr))
	}
	if _, err := t.List(ctx, name+"-list"); err != nil {
		return TestResult{}, opError(fmt.Errorf("listing files failed: %w", err))
	}
	return res, nil
}
