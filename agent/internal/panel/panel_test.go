package panel

import (
	"os"
	"path/filepath"
	"testing"

	"github.com/calltech-app/serfex/agent/internal/host"
)

func TestLoadMovesTheOldRepositoryToTheNewOne(t *testing.T) {
	for source, want := range map[string]string{
		"":                 DefaultSource,
		LegacySource:       DefaultSource,
		LegacySource + "/": DefaultSource,
		"https://api.github.com/repos/winchester37/servik": DefaultSource,
		"https://mirror.example/servik":                    "https://mirror.example/servik",
	} {
		sim, err := host.NewSimulator(t.TempDir(), "8.5")
		if err != nil {
			t.Fatal(err)
		}
		path := sim.Path(ConfigFile)
		if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(path, []byte(`{"php":"8.5","source":"`+source+`"}`), 0o644); err != nil {
			t.Fatal(err)
		}
		c, ok := Load(sim)
		if !ok || c.Source != want {
			t.Errorf("source %q: got %q (ok %v), want %q", source, c.Source, ok, want)
		}
	}
}
