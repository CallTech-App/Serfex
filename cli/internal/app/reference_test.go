package app

import (
	goflag "flag"
	"os"
	"path/filepath"
	"testing"

	"github.com/calltech-app/serfex/cli/internal/i18n"
)

var update = goflag.Bool("update", false, "rewrite docs/cli.md and docs/tr/cli.md")

// TestReferenceIsCurrent fails when a command, option or help text
// changed without the reference in docs/; -update rewrites it.
func TestReferenceIsCurrent(t *testing.T) {
	defer i18n.SetLocale(i18n.Locale())
	for locale, file := range map[string]string{"en": "cli.md", "tr": filepath.Join("tr", "cli.md")} {
		i18n.SetLocale(locale)
		want := Reference()
		path := filepath.Join("..", "..", "..", "docs", file)
		if *update {
			if err := os.WriteFile(path, []byte(want), 0o644); err != nil {
				t.Fatal(err)
			}
			continue
		}
		got, err := os.ReadFile(path)
		if err != nil {
			t.Fatalf("%v (run: go test ./internal/app -run TestReferenceIsCurrent -update)", err)
		}
		if string(got) != want {
			t.Errorf("docs/%s is out of date: run go test ./internal/app -run TestReferenceIsCurrent -update", file)
		}
	}
}
