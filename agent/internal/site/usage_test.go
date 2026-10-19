package site

import (
	"context"
	"errors"
	"os"
	"strings"
	"testing"
)

func TestDiskUsage(t *testing.T) {
	m, sim := newTest(t)
	home := sim.Path("/home/kahve")
	if err := os.MkdirAll(home+"/web/kahveatolyesi.com.tr/public_html", 0o755); err != nil {
		t.Fatal(err)
	}
	big := home + "/web/kahveatolyesi.com.tr/public_html/big.bin"
	if err := os.WriteFile(big, []byte(strings.Repeat("x", 200_000)), 0o644); err != nil {
		t.Fatal(err)
	}
	// A second name for the same file counts once; a link is not followed.
	if err := os.Link(big, home+"/copy.bin"); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink("/etc", home+"/etc"); err != nil {
		t.Fatal(err)
	}

	got, err := m.DiskUsage(context.Background(), DiskSpec{Users: []string{"kahve", "bos"}})
	if err != nil {
		t.Fatal(err)
	}
	if b := got["kahve"].Bytes; b < 200_000 || b > 400_000 {
		t.Errorf("kahve uses %d bytes", b)
	}
	if f := got["kahve"].Files; f != 2 { // big.bin and the symlink
		t.Errorf("kahve has %d files", f)
	}
	if got["bos"] != (DiskUsage{}) {
		t.Errorf("an account without a home uses %+v", got["bos"])
	}

	var v *ValidationError
	if _, err := m.DiskUsage(context.Background(), DiskSpec{Users: []string{"../etc"}}); !errors.As(err, &v) {
		t.Errorf("bad name: %v", err)
	}
}
