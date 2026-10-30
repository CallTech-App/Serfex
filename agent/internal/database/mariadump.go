package database

import (
	"bufio"
	"bytes"
	"io"
	"regexp"
)

// MariaDB 10.10 and later name collations MySQL does not have
// (utf8mb4_uca1400_ai_ci, its default since 11.5, and utf8mb3_* ones),
// and 11.x dumps start with a line MySQL's client refuses. mariaDBToMySQL
// turns a MariaDB dump into one MySQL loads: each such collation becomes
// the closest MySQL has, and the sandbox line goes. Data rows are left
// alone, so a value that happens to read like a collation is kept. The
// caller closes it, which also stops the reading.
func mariaDBToMySQL(r io.Reader) io.ReadCloser {
	pr, pw := io.Pipe()
	go func() {
		br := bufio.NewReaderSize(r, 1<<20)
		bw := bufio.NewWriterSize(pw, 1<<20)
		for {
			line, err := br.ReadBytes('\n')
			if len(line) > 0 {
				if _, werr := bw.Write(mysqlLine(line)); werr != nil {
					pw.CloseWithError(werr)
					return
				}
			}
			if err == io.EOF {
				pw.CloseWithError(bw.Flush())
				return
			}
			if err != nil {
				pw.CloseWithError(err)
				return
			}
		}
	}()
	return pr
}

var (
	// utf8mb4_uca1400_ai_ci, utf8mb3_uca1400_nopad_as_cs, utf8mb4_general1400_as_ci…
	mariaCollationRe = regexp.MustCompile(`\b(utf8mb4|utf8mb3|utf8|ucs2|utf16|utf16le|utf32)_(uca1400|general1400)(_nopad)?(_[a-z]{2})?(_[a-z]{2})?\b`)
	mariaSandboxRe   = regexp.MustCompile(`^/\*M?!999999\\- enable the sandbox mode \*/\s*$`)
)

func mysqlLine(line []byte) []byte {
	if bytes.HasPrefix(line, []byte("INSERT INTO ")) || bytes.HasPrefix(line, []byte("REPLACE INTO ")) {
		return line
	}
	if bytes.HasPrefix(line, []byte("/*M!999999")) && mariaSandboxRe.Match(line) {
		return nil
	}
	if !bytes.Contains(line, []byte("1400")) {
		return line
	}
	return mariaCollationRe.ReplaceAllFunc(line, func(m []byte) []byte {
		sub := mariaCollationRe.FindSubmatch(m)
		return []byte(mysqlCollation(string(sub[1]), string(sub[2]), string(sub[4])+string(sub[5])))
	})
}

// mysqlCollation is the MySQL collation closest to a MariaDB one of
// charset cs.
func mysqlCollation(cs, kind, sens string) string {
	if cs == "utf8" {
		cs = "utf8mb3"
	}
	if kind == "general1400" {
		return cs + "_general_ci"
	}
	if cs == "utf8mb4" {
		switch sens {
		case "_as_cs":
			return "utf8mb4_0900_as_cs"
		case "_as_ci":
			return "utf8mb4_0900_as_ci"
		}
		return "utf8mb4_0900_ai_ci"
	}
	return cs + "_unicode_520_ci"
}
