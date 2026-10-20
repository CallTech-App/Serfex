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

// turkish finds Turkish letters, the sign of a message for people.
var turkish = regexp.MustCompile(`[çğıöşüÇĞİÖŞÜ]`)

// notForPeople lists Turkish literals that are not messages: sample
// values in examples are inside T already; nothing else so far.
var notForPeople = map[string]bool{}

type found struct {
	keys     map[string]string // message => where
	literals map[string]string // Turkish literal => where
}

// scan finds every T("…") message and every Turkish string literal in
// the command line's code.
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
					if x, ok := fn.X.(*ast.Ident); ok && x.Name == "i18n" {
						name = fn.Sel.Name
					}
				case *ast.Ident:
					name = fn.Name
				}
				if name == "T" && len(n.Args) > 0 {
					if lit, ok := n.Args[0].(*ast.BasicLit); ok && lit.Kind == token.STRING {
						s, _ := strconv.Unquote(lit.Value)
						f.keys[s] = fset.Position(lit.Pos()).String()
					}
					if bin, ok := n.Args[0].(*ast.BinaryExpr); ok {
						// T("…" + "…"): one message split over lines.
						if s, ok := concat(bin); ok {
							f.keys[s] = fset.Position(bin.Pos()).String()
						}
					}
				}
			case *ast.BinaryExpr:
				if s, ok := concat(n); ok && turkish.MatchString(s) {
					f.literals[s] = fset.Position(n.Pos()).String()
					return false
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

// concat joins "a" + "b" of string literals.
func concat(e ast.Expr) (string, bool) {
	switch e := e.(type) {
	case *ast.BasicLit:
		if e.Kind != token.STRING {
			return "", false
		}
		s, err := strconv.Unquote(e.Value)
		return s, err == nil
	case *ast.BinaryExpr:
		if e.Op != token.ADD {
			return "", false
		}
		a, ok := concat(e.X)
		if !ok {
			return "", false
		}
		b, ok := concat(e.Y)
		return a + b, ok
	}
	return "", false
}

// TestEveryMessageIsTranslated fails on a message without English, on
// Turkish text outside T (a forgotten T), and on unused translations.
func TestEveryMessageIsTranslated(t *testing.T) {
	f := scan(t)
	var problems []string
	for k, where := range f.keys {
		if _, ok := english[k]; !ok {
			problems = append(problems, where+": no English for "+strconv.Quote(k))
		}
	}
	for s, where := range f.literals {
		if _, ok := f.keys[s]; !ok && !notForPeople[s] {
			problems = append(problems, where+": Turkish text outside T: "+strconv.Quote(s))
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

func TestLanguageComesFromTheFlagThenTheLocale(t *testing.T) {
	env := func(m map[string]string) func(string) string { return func(k string) string { return m[k] } }
	cases := []struct {
		choice string
		env    map[string]string
		want   string
	}{
		{"", map[string]string{"LANG": "tr_TR.UTF-8"}, "tr"},
		{"", map[string]string{"LANG": "en_US.UTF-8"}, "en"},
		{"", map[string]string{"LANG": "C.UTF-8"}, "en"},
		{"", map[string]string{"LC_ALL": "C", "LANG": "tr_TR.UTF-8"}, "tr"},
		{"", map[string]string{"LC_ALL": "de_DE.UTF-8", "LANG": "tr_TR.UTF-8"}, "en"},
		{"tr", map[string]string{"LANG": "en_US.UTF-8"}, "tr"},
		{"", map[string]string{"SERVIK_LANG": "tr", "LANG": "en_US.UTF-8"}, "tr"},
		{"", map[string]string{}, "en"},
	}
	for _, tc := range cases {
		if got := Detect(tc.choice, env(tc.env)); got != tc.want {
			t.Errorf("Detect(%q, %v) = %s, want %s", tc.choice, tc.env, got, tc.want)
		}
	}
}
