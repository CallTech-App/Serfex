package laravel

import (
	"context"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/winchester37/servik/agent/internal/i18n"
)

// Output of deploys and long commands goes to a run log the panel reads
// while it grows, so it can show the output live.

// openRun creates the log of a run, root-owned and readable only by root.
func (m *Manager) openRun(domain, run string) (*os.File, error) {
	if err := validateID("run", run); err != nil {
		return nil, err
	}
	dir := m.h.Path(runDir(domain))
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return nil, err
	}
	m.pruneRuns(dir)
	return os.OpenFile(filepath.Join(dir, run+".log"), os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o600)
}

// runKeep is how long run logs are kept.
const runKeep = 90 * 24 * time.Hour

func (m *Manager) pruneRuns(dir string) {
	entries, err := os.ReadDir(dir)
	if err != nil {
		return
	}
	for _, e := range entries {
		if fi, err := e.Info(); err == nil && time.Since(fi.ModTime()) > runKeep {
			_ = os.Remove(filepath.Join(dir, e.Name()))
		}
	}
}

// RunChunk is part of a run log.
type RunChunk struct {
	Data   string `json:"data"`
	Offset int64  `json:"offset"`
	Size   int64  `json:"size"`
}

const maxChunk = 256 << 10

// ReadRun returns the log of a run from offset on. The chunk ends at a
// line break when it is cut short, so a character is never split.
func (m *Manager) ReadRun(domain, run string, offset int64) (RunChunk, error) {
	if err := (Target{Domain: domain, User: "x"}).validate(); err != nil {
		return RunChunk{}, err
	}
	if err := validateID("run", run); err != nil {
		return RunChunk{}, err
	}
	return m.readChunk(runDir(domain)+"/"+run+".log", offset, false)
}

// tailStart is how much of a long log a negative offset starts with.
const tailStart = 64 << 10

// readChunk reads a root-owned log from offset on. With tail, a negative
// offset starts near the end, at a line break.
func (m *Manager) readChunk(abs string, offset int64, tail bool) (RunChunk, error) {
	f, err := os.Open(m.h.Path(abs))
	if errors.Is(err, os.ErrNotExist) {
		return RunChunk{Offset: 0}, nil
	}
	if err != nil {
		return RunChunk{}, err
	}
	defer f.Close()
	fi, err := f.Stat()
	if err != nil {
		return RunChunk{}, err
	}
	skipLine := false
	switch {
	case offset < 0 && tail && fi.Size() > tailStart:
		offset, skipLine = fi.Size()-tailStart, true
	case offset < 0 || offset > fi.Size():
		// A log that was rotated (copytruncate) starts again.
		offset = 0
	}
	buf := make([]byte, maxChunk)
	n, err := f.ReadAt(buf, offset)
	if err != nil && !errors.Is(err, io.EOF) {
		return RunChunk{}, err
	}
	buf = buf[:n]
	start := offset
	if skipLine {
		if i := strings.IndexByte(string(buf), '\n'); i >= 0 {
			buf, start = buf[i+1:], offset+int64(i+1)
		}
	}
	if n == maxChunk {
		if i := strings.LastIndexByte(string(buf), '\n'); i >= 0 {
			buf = buf[:i+1]
		}
	}
	// The command may be in the middle of writing a character.
	for i := len(buf) - 1; i >= 0 && i >= len(buf)-utf8.UTFMax; i-- {
		if utf8.RuneStart(buf[i]) {
			if !utf8.FullRune(buf[i:]) {
				buf = buf[:i]
			}
			break
		}
	}
	return RunChunk{Data: string(buf), Offset: start + int64(len(buf)), Size: fi.Size()}, nil
}

// RunSpec is a command to run in an application.
type RunSpec struct {
	App
	// Run names the log the output streams to; empty returns the output
	// in the result instead (for short commands).
	Run string `json:"run"`
	// Tool is artisan, composer, npm, pnpm, yarn or bun.
	Tool string   `json:"tool"`
	Args []string `json:"args"`
}

// RunResult is the outcome of a command. A non-zero exit code is a result,
// not an error.
type RunResult struct {
	ExitCode int    `json:"exit_code"`
	Output   string `json:"output,omitempty"`
	Millis   int64  `json:"ms"`
}

const (
	runTimeout   = 30 * time.Minute
	shortTimeout = 2 * time.Minute
	maxOutput    = 64 << 10
)

// argv returns the program and arguments for tool.
func argv(t Target, tool string, args []string) (string, []string, error) {
	switch tool {
	case "artisan":
		return phpBin(t.PHPVersion), append([]string{"artisan"}, args...), nil
	case "composer":
		return phpBin(t.PHPVersion), append([]string{composerPhar()}, args...), nil
	case "npm":
		return "npm", args, nil
	case "pnpm", "yarn":
		return "corepack", append([]string{tool}, args...), nil
	case "bun":
		return "npx", append([]string{"--yes", "bun"}, args...), nil
	}
	return "", nil, invalid("tool must be artisan, composer, npm, pnpm, yarn or bun")
}

// Run runs a command as the site's account in the application directory.
func (m *Manager) Run(ctx context.Context, spec RunSpec) (RunResult, error) {
	if err := spec.App.validate(); err != nil {
		return RunResult{}, err
	}
	if len(spec.Args) > 64 {
		return RunResult{}, invalid("at most 64 arguments are allowed")
	}
	for _, a := range spec.Args {
		if len(a) > 4096 || strings.ContainsRune(a, 0) {
			return RunResult{}, invalid("arguments must be shorter than 4096 bytes")
		}
	}
	name, args, err := argv(spec.Target, spec.Tool, spec.Args)
	if err != nil {
		return RunResult{}, err
	}
	if err := m.requireApp(spec.App); err != nil {
		return RunResult{}, err
	}
	if spec.Tool == "composer" && !m.exists(composerPhar()) {
		return RunResult{}, invalid("Composer is not installed yet; deploy the application once")
	}
	if spec.Tool != "artisan" && spec.Tool != "composer" && !m.exists(nodeBin()+"/node") {
		return RunResult{}, invalid("Node.js is not installed yet; deploy the application once with a front-end build")
	}
	if err := m.writeShims(spec.PHPVersion); err != nil {
		return RunResult{}, &OperationError{err}
	}

	start := time.Now()
	if spec.Run == "" {
		ctx, cancel := context.WithTimeout(ctx, shortTimeout)
		defer cancel()
		out, err := m.h.Run(ctx, m.cmd(spec.Target, spec.dir(), nil, name, args...))
		if len(out) > maxOutput {
			out = "…\n" + out[len(out)-maxOutput:]
		}
		return RunResult{ExitCode: host0(err), Output: out, Millis: time.Since(start).Milliseconds()}, nil
	}
	log, err := m.openRun(spec.Domain, spec.Run)
	if err != nil {
		return RunResult{}, &OperationError{err}
	}
	defer log.Close()
	ctx, cancel := context.WithTimeout(ctx, runTimeout)
	defer cancel()
	fmt.Fprintf(log, "$ %s %s\n", display(spec.Tool), strings.Join(quoteArgs(spec.Args), " "))
	_, err = m.h.Run(ctx, m.cmd(spec.Target, spec.dir(), log, name, args...))
	code := host0(err)
	if errors.Is(ctx.Err(), context.DeadlineExceeded) {
		fmt.Fprintf(log, "\n✗ %s\n", i18n.T(ctx, "%d dakika sınırı aşıldı, komut durduruldu.", int(runTimeout.Minutes())))
	} else if code != 0 {
		fmt.Fprintf(log, "\n✗ %s\n", i18n.T(ctx, "Çıkış kodu %d", code))
	}
	return RunResult{ExitCode: code, Millis: time.Since(start).Milliseconds()}, nil
}

// display is how a tool is shown in logs.
func display(tool string) string {
	switch tool {
	case "artisan":
		return "php artisan"
	case "bun":
		return "bun"
	}
	return tool
}

// quoteArgs quotes arguments with spaces for display.
func quoteArgs(args []string) []string {
	out := make([]string, len(args))
	for i, a := range args {
		if a == "" || strings.ContainsAny(a, " \t\"'$\\") {
			a = "'" + strings.ReplaceAll(a, "'", `'\''`) + "'"
		}
		out[i] = a
	}
	return out
}
