package database

import (
	"context"
	"io"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/calltech-app/serfex/agent/internal/host"
)

// Load converts a MariaDB dump on MySQL, and leaves it as it is on
// MariaDB.
func TestLoadConvertsMariaDBDumpsOnMySQL(t *testing.T) {
	dump := "CREATE TABLE `t` (`a` text) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;\n"
	for _, c := range []struct{ marker, want string }{
		{"/usr/sbin/mysqld", "COLLATE=utf8mb4_0900_ai_ci"},
		{"/usr/sbin/mariadbd", "COLLATE=utf8mb4_uca1400_ai_ci"},
	} {
		sim, err := host.NewSimulator(t.TempDir())
		if err != nil {
			t.Fatal(err)
		}
		if err := os.MkdirAll(filepath.Dir(sim.Path(c.marker)), 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(sim.Path(c.marker), nil, 0o755); err != nil {
			t.Fatal(err)
		}
		r := &recorder{Simulator: sim, answer: func(string) string { return "" }}
		m := NewManager(r)
		m.engines[MySQL] = &mysqlEngine{h: r}
		if err := m.Load(context.Background(), MySQL, "shop", "", strings.NewReader(dump)); err != nil {
			t.Fatal(err)
		}
		if got := r.scripts[len(r.scripts)-1]; !strings.Contains(got, c.want) {
			t.Fatalf("%s: loaded %q", c.marker, got)
		}
	}
}

func TestMariaDBDumpLoadsIntoMySQL(t *testing.T) {
	in := "/*M!999999\\- enable the sandbox mode */ \n" +
		"-- MariaDB dump 10.19-11.4.3-MariaDB, for Linux (x86_64)\n" +
		"/*!40101 SET NAMES utf8mb4 */;\n" +
		"/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;\n" +
		"CREATE TABLE `users` (\n" +
		"  `name` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_uca1400_ai_ci NOT NULL,\n" +
		"  `code` varchar(8) COLLATE utf8mb4_uca1400_as_cs DEFAULT NULL,\n" +
		"  `slug` varchar(8) COLLATE utf8mb4_uca1400_nopad_ai_ci DEFAULT NULL,\n" +
		"  `note` text COLLATE utf8mb4_general1400_as_ci,\n" +
		"  `old` text COLLATE utf8_uca1400_ai_ci\n" +
		") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;\n" +
		"INSERT INTO `users` VALUES ('utf8mb4_uca1400_ai_ci','a','b','c','d');\n" +
		"/*!50003 SET collation_connection  = utf8mb4_uca1400_ai_ci */ ;\n" +
		"-- Dump completed on 2026-10-30 10:00:00"
	b, err := io.ReadAll(mariaDBToMySQL(strings.NewReader(in)))
	if err != nil {
		t.Fatal(err)
	}
	got := string(b)
	want := "-- MariaDB dump 10.19-11.4.3-MariaDB, for Linux (x86_64)\n" +
		"/*!40101 SET NAMES utf8mb4 */;\n" +
		"/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;\n" +
		"CREATE TABLE `users` (\n" +
		"  `name` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_520_ci NOT NULL,\n" +
		"  `code` varchar(8) COLLATE utf8mb4_0900_as_cs DEFAULT NULL,\n" +
		"  `slug` varchar(8) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,\n" +
		"  `note` text COLLATE utf8mb4_general_ci,\n" +
		"  `old` text COLLATE utf8mb3_unicode_520_ci\n" +
		") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;\n" +
		"INSERT INTO `users` VALUES ('utf8mb4_uca1400_ai_ci','a','b','c','d');\n" +
		"/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;\n" +
		"-- Dump completed on 2026-10-30 10:00:00"
	if got != want {
		t.Fatalf("got\n%s\nwant\n%s", got, want)
	}

	// A line longer than the buffer comes through whole.
	long := "INSERT INTO `t` VALUES ('" + strings.Repeat("x", 3<<20) + "');\n"
	b, err = io.ReadAll(mariaDBToMySQL(strings.NewReader(long)))
	if err != nil || string(b) != long {
		t.Fatalf("long line: %d bytes, %v", len(b), err)
	}
}
