package backup

import (
	"bytes"
	"crypto/aes"
	"crypto/cipher"
	"crypto/pbkdf2"
	"crypto/rand"
	"crypto/sha256"
	"encoding/binary"
	"errors"
	"fmt"
	"io"
)

// Copies of backups sent to remote storage are encrypted with AES-256-GCM
// under a key derived from the server's backup passphrase (PBKDF2-SHA256),
// so the storage provider can neither read nor change them. The file is a
// header followed by chunks:
//
//	"SVKENC01" | salt (16) | iterations (uint32) | nonce prefix (4)
//	chunk: length of ciphertext (uint32) | ciphertext (≤ 1 MiB + tag)
//
// Chunk n is sealed with the nonce prefix followed by n (uint64), and the
// header plus a "last chunk" flag as additional data, so chunks cannot be
// reordered, dropped or cut off without the reader noticing.

const (
	encMagic      = "SVKENC01"
	encChunk      = 1 << 20
	encIterations = 600_000
	encHeaderLen  = len(encMagic) + 16 + 4 + 4
)

// ErrWrongPassphrase is returned when an encrypted backup does not open.
var ErrWrongPassphrase = errors.New("the backup does not open with this passphrase, or it was changed")

func deriveKey(passphrase string, salt []byte, iter int) ([]byte, error) {
	return pbkdf2.Key(sha256.New, passphrase, salt, iter, 32)
}

// encryptWriter seals what is written to it into w.
type encryptWriter struct {
	w      io.Writer
	aead   cipher.AEAD
	header []byte
	prefix []byte
	n      uint64
	buf    []byte
	closed bool
}

func newEncryptWriter(w io.Writer, passphrase string) (*encryptWriter, error) {
	header := make([]byte, encHeaderLen)
	copy(header, encMagic)
	salt := header[len(encMagic) : len(encMagic)+16]
	if _, err := rand.Read(salt); err != nil {
		return nil, err
	}
	binary.BigEndian.PutUint32(header[len(encMagic)+16:], encIterations)
	prefix := header[len(encMagic)+20:]
	if _, err := rand.Read(prefix); err != nil {
		return nil, err
	}
	aead, err := newAEAD(passphrase, salt, encIterations)
	if err != nil {
		return nil, err
	}
	if _, err := w.Write(header); err != nil {
		return nil, err
	}
	return &encryptWriter{w: w, aead: aead, header: header, prefix: prefix, buf: make([]byte, 0, encChunk)}, nil
}

func newAEAD(passphrase string, salt []byte, iter int) (cipher.AEAD, error) {
	key, err := deriveKey(passphrase, salt, iter)
	if err != nil {
		return nil, err
	}
	block, err := aes.NewCipher(key)
	if err != nil {
		return nil, err
	}
	return cipher.NewGCM(block)
}

func (e *encryptWriter) nonce() []byte {
	nonce := make([]byte, 12)
	copy(nonce, e.prefix)
	binary.BigEndian.PutUint64(nonce[4:], e.n)
	e.n++
	return nonce
}

func (e *encryptWriter) seal(last bool) error {
	aad := append(append([]byte(nil), e.header...), 0)
	if last {
		aad[len(aad)-1] = 1
	}
	ct := e.aead.Seal(nil, e.nonce(), e.buf, aad)
	var l [4]byte
	binary.BigEndian.PutUint32(l[:], uint32(len(ct)))
	if _, err := e.w.Write(l[:]); err != nil {
		return err
	}
	if _, err := e.w.Write(ct); err != nil {
		return err
	}
	e.buf = e.buf[:0]
	return nil
}

func (e *encryptWriter) Write(p []byte) (int, error) {
	if e.closed {
		return 0, errors.New("write after close")
	}
	n := len(p)
	for len(p) > 0 {
		room := encChunk - len(e.buf)
		if room == 0 {
			if err := e.seal(false); err != nil {
				return n - len(p), err
			}
			continue
		}
		take := min(room, len(p))
		e.buf = append(e.buf, p[:take]...)
		p = p[take:]
	}
	return n, nil
}

// Close writes the last chunk (possibly empty).
func (e *encryptWriter) Close() error {
	if e.closed {
		return nil
	}
	e.closed = true
	return e.seal(true)
}

// decryptReader opens a file written by encryptWriter.
type decryptReader struct {
	r      io.Reader
	aead   cipher.AEAD
	header []byte
	prefix []byte
	n      uint64
	plain  []byte
	done   bool
}

func newDecryptReader(r io.Reader, passphrase string) (*decryptReader, error) {
	header := make([]byte, encHeaderLen)
	if _, err := io.ReadFull(r, header); err != nil {
		return nil, errors.New("the file is not an encrypted Serfex backup")
	}
	if !bytes.Equal(header[:len(encMagic)], []byte(encMagic)) {
		return nil, errors.New("the file is not an encrypted Serfex backup")
	}
	iter := int(binary.BigEndian.Uint32(header[len(encMagic)+16:]))
	if iter < 100_000 || iter > 10_000_000 {
		return nil, errors.New("the encrypted backup's header is damaged")
	}
	aead, err := newAEAD(passphrase, header[len(encMagic):len(encMagic)+16], iter)
	if err != nil {
		return nil, err
	}
	return &decryptReader{r: r, aead: aead, header: header, prefix: header[len(encMagic)+20:]}, nil
}

func (d *decryptReader) next() error {
	var l [4]byte
	if _, err := io.ReadFull(d.r, l[:]); err != nil {
		return fmt.Errorf("the encrypted backup is cut off: %w", ErrWrongPassphrase)
	}
	size := binary.BigEndian.Uint32(l[:])
	if size < uint32(d.aead.Overhead()) || size > encChunk+uint32(d.aead.Overhead()) {
		return errors.New("the encrypted backup is damaged")
	}
	ct := make([]byte, size)
	if _, err := io.ReadFull(d.r, ct); err != nil {
		return fmt.Errorf("the encrypted backup is cut off: %w", ErrWrongPassphrase)
	}
	nonce := make([]byte, 12)
	copy(nonce, d.prefix)
	binary.BigEndian.PutUint64(nonce[4:], d.n)
	d.n++
	aad := append(append([]byte(nil), d.header...), 0)
	plain, err := d.aead.Open(nil, nonce, ct, aad)
	if err != nil {
		aad[len(aad)-1] = 1
		if plain, err = d.aead.Open(nil, nonce, ct, aad); err != nil {
			return ErrWrongPassphrase
		}
		d.done = true
		// Nothing may follow the last chunk.
		var extra [1]byte
		if n, _ := d.r.Read(extra[:]); n > 0 {
			return errors.New("the encrypted backup has data after its end")
		}
	}
	d.plain = plain
	return nil
}

func (d *decryptReader) Read(p []byte) (int, error) {
	for len(d.plain) == 0 {
		if d.done {
			return 0, io.EOF
		}
		if err := d.next(); err != nil {
			return 0, err
		}
	}
	n := copy(p, d.plain)
	d.plain = d.plain[n:]
	return n, nil
}

// isEncrypted reports whether the start of a file is an encryption header.
func isEncrypted(head []byte) bool { return bytes.HasPrefix(head, []byte(encMagic)) }
