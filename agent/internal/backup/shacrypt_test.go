package backup

import (
	"strings"
	"testing"
)

func TestSHA512Crypt(t *testing.T) {
	// The reference implementation's example, and one checked with glibc's
	// crypt(3) (a salt longer than 16 characters is cut).
	for _, c := range []struct{ password, salt, want string }{
		{"Hello world!", "saltstring", "$6$saltstring$svn8UoSVapNtMuq1ukKS4tPQd8iKwSMHWjl/O817G3uBnIFNjnQJuesI68u4OTLiBFdcbYEdFCoEOfaS35inz1"},
		{"a very much longer text to encrypt.  This one even stretches over morethan one line.", "1234567890123456789", "$6$1234567890123456$qh7.iuCKLACKMso25j6447/GTFsOQiUQ3yVFSyYkedfgC49Q6MjzGu6V2N9MAzKt2xvx4CXbf25qdj0qpVgm.1"},
	} {
		if got := sha512CryptSalt(c.password, c.salt); got != c.want {
			t.Errorf("crypt(%q) = %s", c.password, got)
		}
	}
	h := sha512Crypt("Kahve.2024")
	if !strings.HasPrefix(h, "$6$") || len(h) != 3+16+1+86 {
		t.Fatalf("hash = %s", h)
	}
	if _, ok := mailHash(h); !ok {
		t.Fatal("the hash is not taken as a Dovecot password")
	}
}
