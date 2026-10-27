package site

import (
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"syscall"
)

// LogsSpec asks for the end of one of a site's logs.
type LogsSpec struct {
	Domain string `json:"domain"`
	User   string `json:"user"`
	// Kind is "access" or "error" (Nginx), "apache" (Apache's error log)
	// or "php" (PHP-FPM's error log).
	Kind  string `json:"kind"`
	Bytes int    `json:"bytes"`
	// Offset, when set, reads Bytes from there instead of the end (for
	// downloading a whole log in parts).
	Offset *int64 `json:"offset"`
}

// Log is the end of a log file.
type Log struct {
	Kind    string `json:"kind"`
	Exists  bool   `json:"exists"`
	Size    int64  `json:"size"`
	Content string `json:"content"`
	// Truncated is true when Content starts in the middle of the file.
	Truncated bool `json:"truncated"`
	// Next is where the part read ends, for reading on from an offset.
	Next int64 `json:"next"`
}

// MaxLogBytes is the most of a log that one call returns.
const MaxLogBytes = 1 << 20

// Logs returns the last bytes of a site's Nginx access or error log or its
// PHP error log. The PHP log sits in the customer's own directory, so no
// part of its path may be a symbolic link: a customer must not be able to
// point it at a file only root may read.
func (m *Manager) Logs(spec LogsSpec) (Log, error) {
	if err := ValidateDomain(spec.Domain); err != nil {
		return Log{}, err
	}
	if err := ValidateUser(spec.User); err != nil {
		return Log{}, err
	}
	if spec.Bytes <= 0 || spec.Bytes > MaxLogBytes {
		spec.Bytes = 64 << 10
	}
	p := layout(spec.Domain, spec.User, "", "")
	var file string
	var checked []string
	switch spec.Kind {
	case "access":
		file = p.logDir + "/access.log"
	case "error":
		file = p.logDir + "/error.log"
	case "apache":
		file = p.logDir + "/apache-error.log"
	case "php":
		file = p.siteDir + "/logs/php-error.log"
		checked = []string{p.home + "/web", p.siteDir, p.siteDir + "/logs", file}
	default:
		return Log{}, invalid("kind %q must be access, error, apache or php", spec.Kind)
	}
	out := Log{Kind: spec.Kind}
	for _, c := range checked {
		fi, err := os.Lstat(m.h.Path(c))
		if errors.Is(err, fs.ErrNotExist) {
			return out, nil
		}
		if err != nil {
			return Log{}, &OperationError{fmt.Errorf("reading %s failed", file)}
		}
		if fi.Mode()&fs.ModeSymlink != 0 {
			return Log{}, invalid("%s is a symbolic link; Serfex does not follow it", c)
		}
	}
	f, err := os.OpenFile(m.h.Path(file), os.O_RDONLY|syscall.O_NOFOLLOW, 0)
	if errors.Is(err, fs.ErrNotExist) {
		return out, nil
	}
	if err != nil {
		return Log{}, &OperationError{fmt.Errorf("reading %s failed", file)}
	}
	defer f.Close()
	fi, err := f.Stat()
	if err != nil || !fi.Mode().IsRegular() {
		return Log{}, invalid("%s is not a regular file", file)
	}
	out.Exists, out.Size = true, fi.Size()
	start, end := max(fi.Size()-int64(spec.Bytes), 0), fi.Size()
	if spec.Offset != nil {
		if *spec.Offset < 0 {
			return Log{}, invalid("offset must not be negative")
		}
		start = min(*spec.Offset, fi.Size())
		end = min(start+int64(spec.Bytes), fi.Size())
	}
	b, err := io.ReadAll(io.NewSectionReader(f, start, end-start))
	if err != nil {
		return Log{}, &OperationError{fmt.Errorf("reading %s failed", file)}
	}
	out.Content, out.Truncated, out.Next = string(b), start > 0, start+int64(len(b))
	return out, nil
}
