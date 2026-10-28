package backup

import (
	"crypto/rand"
	"crypto/sha512"
)

// SHA-512 crypt ($6$), as glibc and Dovecot's SHA512-CRYPT read it: a
// mailbox password read from Plesk in clear is stored here only as this
// hash (Ulrich Drepper's "Unix crypt using SHA-256 and SHA-512").

const cryptChars = "./0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz"

// sha512Crypt hashes password with a new 16 character salt.
func sha512Crypt(password string) string {
	b := make([]byte, 16)
	_, _ = rand.Read(b)
	salt := make([]byte, 16)
	for i := range b {
		salt[i] = cryptChars[int(b[i])%len(cryptChars)]
	}
	return sha512CryptSalt(password, string(salt))
}

// sha512CryptSalt hashes password with salt (at most 16 characters are
// used) and the default 5000 rounds.
func sha512CryptSalt(password, salt string) string {
	const rounds = 5000
	if len(salt) > 16 {
		salt = salt[:16]
	}
	p, s := []byte(password), []byte(salt)

	alt := sha512.New()
	alt.Write(p)
	alt.Write(s)
	alt.Write(p)
	b := alt.Sum(nil)

	a := sha512.New()
	a.Write(p)
	a.Write(s)
	n := len(p)
	for ; n > 64; n -= 64 {
		a.Write(b)
	}
	a.Write(b[:n])
	for n = len(p); n > 0; n >>= 1 {
		if n&1 != 0 {
			a.Write(b)
		} else {
			a.Write(p)
		}
	}
	sum := a.Sum(nil)

	dp := sha512.New()
	for range len(p) {
		dp.Write(p)
	}
	pBytes := repeatTo(dp.Sum(nil), len(p))

	ds := sha512.New()
	for range 16 + int(sum[0]) {
		ds.Write(s)
	}
	sBytes := repeatTo(ds.Sum(nil), len(s))

	for i := range rounds {
		c := sha512.New()
		if i&1 != 0 {
			c.Write(pBytes)
		} else {
			c.Write(sum)
		}
		if i%3 != 0 {
			c.Write(sBytes)
		}
		if i%7 != 0 {
			c.Write(pBytes)
		}
		if i&1 != 0 {
			c.Write(sum)
		} else {
			c.Write(pBytes)
		}
		sum = c.Sum(nil)
	}

	out := []byte("$6$" + salt + "$")
	enc := func(b2, b1, b0 byte, n int) {
		w := uint(b2)<<16 | uint(b1)<<8 | uint(b0)
		for range n {
			out = append(out, cryptChars[w&0x3f])
			w >>= 6
		}
	}
	order := [21][3]int{
		{0, 21, 42}, {22, 43, 1}, {44, 2, 23}, {3, 24, 45}, {25, 46, 4}, {47, 5, 26}, {6, 27, 48},
		{28, 49, 7}, {50, 8, 29}, {9, 30, 51}, {31, 52, 10}, {53, 11, 32}, {12, 33, 54}, {34, 55, 13},
		{56, 14, 35}, {15, 36, 57}, {37, 58, 16}, {59, 17, 38}, {18, 39, 60}, {40, 61, 19}, {62, 20, 41},
	}
	for _, o := range order {
		enc(sum[o[0]], sum[o[1]], sum[o[2]], 4)
	}
	enc(0, 0, sum[63], 2)
	return string(out)
}

func repeatTo(b []byte, n int) []byte {
	out := make([]byte, 0, n)
	for len(out) < n {
		out = append(out, b[:min(len(b), n-len(out))]...)
	}
	return out
}
