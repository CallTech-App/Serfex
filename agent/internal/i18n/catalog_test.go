package i18n

import (
	"go/ast"
	"go/parser"
	"go/token"
	"io/fs"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"testing"
)

// translating names the calls whose first string argument (or second,
// after a context) is a Turkish message: i18n.T, i18n.Translate, i18n.In
// and the progress-log helpers that translate their format.
var translating = map[string]int{"T": 1, "Translate": 1, "In": 1, "step": 0, "note": 0}

// turkish finds Turkish letters, the sign of a message for people.
var turkish = regexp.MustCompile(`[çğıöşüÇĞİÖŞÜ]`)

// notForPeople lists Turkish literals that are not panel messages: names
// the panel translates itself (lang/en.json), configuration file
// comments, and sample data.
var notForPeople = map[string]bool{
	// Service and process names, shown through the panel's __().
	"Postfix (posta gönderme ve alma)": true, "ClamAV (virüs tarayıcı)": true, "ClamAV imza güncelleyici": true,
	"Cron (zamanlanmış görevler)": true, "Panel veritabanı (PostgreSQL ": true, "Panel iş kuyruğu": true,
	"Panel zamanlayıcısı": true, "Panel yedekleme kuyruğu": true, "PostgreSQL (panel veritabanı)": true, "Diğer süreçler": true,
	// Written into files on the server.
	"; %s. Change these on the panel's Hizmetler → PHP sürümleri page.\n":          true,
	"SSH hardening (Güvenlik > Giriş güvenliği). Read first, so these values win.": true,
	"ModSecurity is not installed; install it from Yazılım Yönetimi first":         true,
	"Kapalı": true, "Yalnızca izle": true, "sunucu ayarı": true, "WAF of ": true, ": Kapalı": true,
	"Korumalı alan": true, " nobody may fetch (Güvenlik taraması).": true, "Otomatik yanıt": true, " (geri yüklendi)": true,
	`{"key":"5a4b3c2d1e0f9a8b","command":"reports:daily","description":"Günlük rapor","expression":"0 2 * * *","next":%q,"timezone":"UTC"}]`: true,
}

type found struct {
	keys     map[string]string // message => where
	literals map[string]string // Turkish literal => where
}

func scan(t *testing.T) found {
	t.Helper()
	f := found{keys: map[string]string{}, literals: map[string]string{}}
	root := filepath.Join("..", "..")
	fset := token.NewFileSet()
	err := filepath.WalkDir(root, func(path string, d fs.DirEntry, err error) error {
		if err != nil || d.IsDir() || !strings.HasSuffix(path, ".go") || strings.HasSuffix(path, "_test.go") || strings.Contains(path, "internal/i18n/") {
			return err
		}
		file, err := parser.ParseFile(fset, path, nil, 0)
		if err != nil {
			return err
		}
		ast.Inspect(file, func(n ast.Node) bool {
			switch n := n.(type) {
			case *ast.CallExpr:
				name := ""
				switch fn := n.Fun.(type) {
				case *ast.SelectorExpr:
					name = fn.Sel.Name
					if x, ok := fn.X.(*ast.Ident); ok && x.Name != "i18n" && name != "step" && name != "note" {
						name = ""
					}
				case *ast.Ident:
					name = fn.Name
				}
				i, ok := translating[name]
				if ok && len(n.Args) > i {
					if lit, ok := n.Args[i].(*ast.BasicLit); ok && lit.Kind == token.STRING {
						s, _ := strconv.Unquote(lit.Value)
						f.keys[s] = fset.Position(lit.Pos()).String()
					}
					if cl, ok := n.Args[i].(*ast.IndexExpr); ok {
						// i18n.T(ctx, map[...]string{...}[k]): every value is a message.
						if m, ok := cl.X.(*ast.CompositeLit); ok {
							for _, e := range m.Elts {
								if kv, ok := e.(*ast.KeyValueExpr); ok {
									if lit, ok := kv.Value.(*ast.BasicLit); ok && lit.Kind == token.STRING {
										s, _ := strconv.Unquote(lit.Value)
										f.keys[s] = fset.Position(lit.Pos()).String()
									}
								}
							}
						}
					}
				}
			case *ast.BasicLit:
				if n.Kind == token.STRING {
					s, _ := strconv.Unquote(n.Value)
					if turkish.MatchString(s) {
						f.literals[s] = fset.Position(n.Pos()).String()
					}
				}
			}
			return true
		})
		return nil
	})
	if err != nil {
		t.Fatal(err)
	}
	return f
}

// TestEveryMessageIsTranslated fails on a message without an English
// translation, on a Turkish literal that is not a message (forgotten
// i18n.T), and on a translation nothing uses.
func TestEveryMessageIsTranslated(t *testing.T) {
	f := scan(t)
	var problems []string
	for k, where := range f.keys {
		if _, ok := english[k]; !ok {
			problems = append(problems, where+": no English for "+strconv.Quote(k))
		}
	}
	for s, where := range f.literals {
		// Templates of files written on the server: comments and pages.
		template := strings.Contains(s, "\n") && (strings.Contains(s, "{{") || strings.HasPrefix(s, "<?php") || strings.HasPrefix(s, "#"))
		if _, ok := f.keys[s]; !ok && !notForPeople[s] && !template {
			problems = append(problems, where+": Turkish text outside i18n.T: "+strconv.Quote(s))
		}
	}
	for k := range english {
		if _, ok := f.keys[k]; !ok {
			problems = append(problems, "catalog.go: unused translation "+strconv.Quote(k))
		}
	}
	for k, v := range english {
		if strings.Count(k, "%") != strings.Count(v, "%") {
			problems = append(problems, "catalog.go: "+strconv.Quote(k)+" and its English have different verbs")
		}
	}
	sort.Strings(problems)
	for _, p := range problems {
		t.Error(p)
	}
}

func TestLocaleFallsBackToTurkish(t *testing.T) {
	ctx := WithLocale(t.Context(), "de")
	if Locale(ctx) != "tr" {
		t.Fatalf("locale %q, want tr", Locale(ctx))
	}
	if got := T(WithLocale(t.Context(), "en"), "Yedek alındı: %s", "2 MB"); got != "Backup done: 2 MB" {
		t.Fatalf("got %q", got)
	}
	if got := T(t.Context(), "Yedek alındı: %s", "2 MB"); got != "Yedek alındı: 2 MB" {
		t.Fatalf("got %q", got)
	}
}
