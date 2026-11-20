package stats

import (
	"context"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"testing"
)

// A registry that answers with nothing would take its part of the world
// out of the table: a country blocked there would be open again until
// the next download, a month later. The old table stays instead.
func TestARegistryThatListsNothingKeepsTheOldTable(t *testing.T) {
	reg := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/ripe" {
			io.WriteString(w, "demo|RU|ipv4|5.0.0.0|256|20110414|allocated\n")
		}
	}))
	defer reg.Close()
	file := filepath.Join(t.TempDir(), "countries.bin")
	if err := os.WriteFile(file, []byte("the old table"), 0o644); err != nil {
		t.Fatal(err)
	}
	g, err := DownloadGeo(context.Background(), reg.Client(), []string{reg.URL + "/ripe", reg.URL + "/apnic"}, file)
	if err == nil || g != nil {
		t.Fatalf("a table without a registry was taken: %v", err)
	}
	if data, _ := os.ReadFile(file); string(data) != "the old table" {
		t.Fatalf("the old table was replaced: %q", data)
	}
	g, err = DownloadGeo(context.Background(), reg.Client(), []string{reg.URL + "/ripe"}, file)
	if err != nil || g.Country("5.0.0.9") != "RU" {
		t.Fatalf("a full download: %v", err)
	}
}
