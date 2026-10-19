package files

import (
	"archive/tar"
	"archive/zip"
	"compress/bzip2"
	"compress/gzip"
	"errors"
	"io"
	"io/fs"
	"os"
	"path"
	"path/filepath"
	"strings"
	"time"
)

// archiveKind names the format of an archive file by its name.
func archiveKind(name string) string {
	n := strings.ToLower(name)
	switch {
	case strings.HasSuffix(n, ".zip"):
		return "zip"
	case strings.HasSuffix(n, ".tar.gz"), strings.HasSuffix(n, ".tgz"):
		return "tar.gz"
	case strings.HasSuffix(n, ".tar.bz2"), strings.HasSuffix(n, ".tbz2"):
		return "tar.bz2"
	case strings.HasSuffix(n, ".tar"):
		return "tar"
	}
	return ""
}

// compress packs entries of one folder into a new zip or tar.gz there.
func (s *session) compress() (any, error) {
	dirRel, dirAbs, err := s.folder(s.req.Path)
	if err != nil {
		return nil, err
	}
	format := s.req.Format
	if format != "zip" && format != "tar.gz" {
		return nil, fail(CodeInvalid, "", "archives are zip or tar.gz")
	}
	name := s.req.Name
	if archiveKind(name) != format {
		name += map[string]string{"zip": ".zip", "tar.gz": ".tar.gz"}[format]
	}
	rel, abs, err := child(dirRel, dirAbs, name)
	if err != nil {
		return nil, err
	}
	var sources []string
	for _, p := range s.req.Paths {
		_, src, err := s.entry(p)
		if err != nil {
			return nil, err
		}
		sources = append(sources, src)
	}
	if len(sources) == 0 {
		return nil, fail(CodeInvalid, "", "nothing to compress")
	}
	f, err := os.OpenFile(abs, os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0o644)
	if err != nil {
		return nil, err
	}
	if format == "zip" {
		err = writeZip(f, sources, abs)
	} else {
		err = writeTarGz(f, sources, abs)
	}
	if cerr := f.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		_ = os.Remove(abs)
		return nil, err
	}
	fi, _ := os.Stat(abs)
	var size int64
	if fi != nil {
		size = fi.Size()
	}
	return map[string]any{"path": rel, "size": size}, nil
}

// walkSources visits every source and what is below it, with the name it
// gets in the archive. The archive being written (self) is skipped.
func walkSources(sources []string, self string, visit func(abs, name string, fi fs.FileInfo) error) error {
	for _, src := range sources {
		base := filepath.Dir(src)
		err := filepath.Walk(src, func(p string, fi fs.FileInfo, err error) error {
			if err != nil {
				return err
			}
			if p == self {
				return nil
			}
			name, _ := filepath.Rel(base, p)
			return visit(p, filepath.ToSlash(name), fi)
		})
		if err != nil {
			return err
		}
	}
	return nil
}

func writeZip(w io.Writer, sources []string, self string) error {
	zw := zip.NewWriter(w)
	err := walkSources(sources, self, func(abs, name string, fi fs.FileInfo) error {
		hdr, err := zip.FileInfoHeader(fi)
		if err != nil {
			return err
		}
		hdr.Name = name
		switch {
		case fi.IsDir():
			hdr.Name += "/"
			_, err = zw.CreateHeader(hdr)
			return err
		case fi.Mode()&fs.ModeSymlink != 0:
			t, err := os.Readlink(abs)
			if err != nil {
				return err
			}
			hw, err := zw.CreateHeader(hdr)
			if err != nil {
				return err
			}
			_, err = io.WriteString(hw, t)
			return err
		case fi.Mode().IsRegular():
			hdr.Method = zip.Deflate
			hw, err := zw.CreateHeader(hdr)
			if err != nil {
				return err
			}
			f, err := os.Open(abs)
			if err != nil {
				return err
			}
			defer f.Close()
			_, err = io.Copy(hw, f)
			return err
		}
		return nil
	})
	if err != nil {
		return err
	}
	return zw.Close()
}

func writeTarGz(w io.Writer, sources []string, self string) error {
	gz := gzip.NewWriter(w)
	tw := tar.NewWriter(gz)
	err := walkSources(sources, self, func(abs, name string, fi fs.FileInfo) error {
		link := ""
		if fi.Mode()&fs.ModeSymlink != 0 {
			t, err := os.Readlink(abs)
			if err != nil {
				return err
			}
			link = t
		}
		hdr, err := tar.FileInfoHeader(fi, link)
		if err != nil {
			return err
		}
		hdr.Name = name
		if fi.IsDir() {
			hdr.Name += "/"
		}
		hdr.Uname, hdr.Gname, hdr.Uid, hdr.Gid = "", "", 0, 0
		if !fi.IsDir() && !fi.Mode().IsRegular() && link == "" {
			return nil
		}
		if err := tw.WriteHeader(hdr); err != nil {
			return err
		}
		if fi.Mode().IsRegular() {
			f, err := os.Open(abs)
			if err != nil {
				return err
			}
			defer f.Close()
			_, err = io.Copy(tw, f)
			return err
		}
		return nil
	})
	if err != nil {
		return err
	}
	if err := tw.Close(); err != nil {
		return err
	}
	return gz.Close()
}

// unpacked is one entry read from an archive.
type unpacked struct {
	name string
	mode fs.FileMode
	kind byte // '0' file, '5' folder, '2' link
	link string
	time time.Time
	open func() (io.ReadCloser, error)
}

// extract unpacks an archive into a folder (To).
// Entries may not climb out; existing files are only overwritten with
// Overwrite (their old versions go to the trash), otherwise the archive
// is refused before anything is written.
func (s *session) extract() (any, error) {
	rel, abs, err := s.follow(s.req.Path)
	if err != nil {
		return nil, err
	}
	kind := archiveKind(rel)
	if kind == "" {
		return nil, fail(CodeArchive, rel, "only zip, tar, tar.gz and tar.bz2 archives can be extracted")
	}
	toRel, toAbs, err := s.folder(s.req.To)
	if err != nil {
		return nil, err
	}

	entries, closer, err := readArchive(abs, kind)
	if err != nil {
		return nil, fail(CodeArchive, rel, "cannot read the archive: %v", err)
	}
	defer closer()
	if len(entries) > MaxExtractEntries {
		return nil, fail(CodeTooMany, rel, "the archive has more than %d entries", MaxExtractEntries)
	}

	// Check every name first, so a refused archive leaves nothing behind.
	conflicts := []string{}
	for i := range entries {
		n, err := clean(entries[i].name)
		if err != nil || n == "" || path.IsAbs(entries[i].name) || strings.HasPrefix(path.Clean(entries[i].name), "../") {
			return nil, fail(CodeOutside, rel, "the archive has an entry outside its folder: %s", entries[i].name)
		}
		entries[i].name = n
		target := filepath.Join(toAbs, filepath.FromSlash(n))
		if fi, err := os.Lstat(target); err == nil && !(fi.IsDir() && entries[i].kind == '5') {
			conflicts = append(conflicts, path.Join(toRel, n))
		}
	}
	if len(conflicts) > 0 && !s.req.Overwrite {
		if len(conflicts) > 20 {
			conflicts = conflicts[:20]
		}
		return map[string]any{"conflicts": conflicts}, fail(CodeExists, conflicts[0], "%d entries already exist", len(conflicts))
	}

	var written int64
	count := 0
	for _, e := range entries {
		target := filepath.Join(toAbs, filepath.FromSlash(e.name))
		// The folders on the way must resolve inside the target folder.
		parentRel := path.Dir(path.Join(toRel, e.name))
		if parentRel == "." {
			parentRel = ""
		}
		parent, err := s.ensureFolder(parentRel)
		if err != nil {
			return nil, err
		}
		target = filepath.Join(parent, path.Base(e.name))
		if fi, err := os.Lstat(target); err == nil {
			if e.kind == '5' && fi.IsDir() {
				continue
			}
			if _, err := s.toTrash(path.Join(toRel, e.name), target, "replaced"); err != nil {
				return nil, err
			}
		}
		switch e.kind {
		case '5':
			if err := os.Mkdir(target, 0o755); err != nil {
				return nil, err
			}
		case '2':
			if err := os.Symlink(e.link, target); err != nil {
				return nil, err
			}
		default:
			rc, err := e.open()
			if err != nil {
				return nil, fail(CodeArchive, rel, "%s: %v", e.name, err)
			}
			mode := e.mode.Perm()
			if mode == 0 {
				mode = 0o644
			}
			out, err := os.OpenFile(target, os.O_WRONLY|os.O_CREATE|os.O_EXCL, mode&0o777)
			if err != nil {
				rc.Close()
				return nil, err
			}
			n, err := io.Copy(out, io.LimitReader(rc, MaxExtractBytes-written+1))
			rc.Close()
			if cerr := out.Close(); err == nil {
				err = cerr
			}
			if err != nil {
				return nil, err
			}
			if written += n; written > MaxExtractBytes {
				_ = os.Remove(target)
				return nil, fail(CodeTooBig, rel, "the archive unpacks to more than %d bytes", int64(MaxExtractBytes))
			}
			if !e.time.IsZero() {
				_ = os.Chtimes(target, e.time, e.time)
			}
		}
		count++
	}
	return map[string]any{"path": toRel, "entries": count, "bytes": written}, nil
}

// readArchive lists an archive. Tar entries are read in one pass, so
// their content is buffered to temporary files only when needed: the
// returned entries of a tar open their data from a fresh reader.
func readArchive(abs, kind string) ([]unpacked, func(), error) {
	if kind == "zip" {
		zr, err := zip.OpenReader(abs)
		if err != nil {
			return nil, nil, err
		}
		var out []unpacked
		for _, f := range zr.File {
			e := unpacked{name: strings.TrimSuffix(f.Name, "/"), mode: f.Mode(), time: f.Modified, kind: '0'}
			switch {
			case f.FileInfo().IsDir() || strings.HasSuffix(f.Name, "/"):
				e.kind = '5'
			case f.Mode()&fs.ModeSymlink != 0:
				rc, err := f.Open()
				if err != nil {
					zr.Close()
					return nil, nil, err
				}
				t, _ := io.ReadAll(io.LimitReader(rc, 4096))
				rc.Close()
				e.kind, e.link = '2', string(t)
			default:
				f := f
				e.open = func() (io.ReadCloser, error) { return f.Open() }
			}
			out = append(out, e)
		}
		return out, func() { zr.Close() }, nil
	}

	// Tar: list in a first pass, read data in a second pass in order.
	open := func() (*tar.Reader, func(), error) {
		f, err := os.Open(abs)
		if err != nil {
			return nil, nil, err
		}
		var r io.Reader = f
		switch kind {
		case "tar.gz":
			gz, err := gzip.NewReader(f)
			if err != nil {
				f.Close()
				return nil, nil, err
			}
			r = gz
		case "tar.bz2":
			r = bzip2.NewReader(f)
		}
		return tar.NewReader(r), func() { f.Close() }, nil
	}
	tr, done, err := open()
	if err != nil {
		return nil, nil, err
	}
	var out []unpacked
	for {
		hdr, err := tr.Next()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			done()
			return nil, nil, err
		}
		e := unpacked{name: strings.TrimSuffix(hdr.Name, "/"), mode: fs.FileMode(hdr.Mode), time: hdr.ModTime}
		switch hdr.Typeflag {
		case tar.TypeDir:
			e.kind = '5'
		case tar.TypeSymlink:
			e.kind, e.link = '2', hdr.Linkname
		case tar.TypeReg, tar.TypeRegA:
			e.kind = '0'
		default:
			continue // hard links, devices and the like are skipped
		}
		if e.name == "" || e.name == "." {
			continue
		}
		out = append(out, e)
	}
	done()

	// The data pass: entries are extracted in archive order, so one
	// reader moving forward serves them all.
	var cur *tar.Reader
	var curDone func()
	for i := range out {
		if out[i].kind != '0' {
			continue
		}
		want := out[i].name
		out[i].open = func() (io.ReadCloser, error) {
			if cur == nil {
				r, d, err := open()
				if err != nil {
					return nil, err
				}
				cur, curDone = r, d
			}
			for {
				hdr, err := cur.Next()
				if err != nil {
					return nil, err
				}
				if strings.TrimSuffix(hdr.Name, "/") == want && (hdr.Typeflag == tar.TypeReg || hdr.Typeflag == tar.TypeRegA) {
					return io.NopCloser(cur), nil
				}
			}
		}
	}
	return out, func() {
		if curDone != nil {
			curDone()
		}
	}, nil
}
