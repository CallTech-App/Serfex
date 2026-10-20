package laravel

import (
	"bytes"
	"context"
	"io"
	"os"
	"strings"
	"sync"
	"time"

	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/i18n"
	"github.com/winchester37/servik/agent/internal/txn"
)

// Tinker sessions: php artisan tinker running as the site's account,
// its input fed line by line from the panel and its output appended to a
// run log the panel reads like any other. A session ends when the panel
// stops it, after idleTimeout without input, after tinkerLifetime, or
// when it prints more than tinkerMaxOutput.

const (
	tinkerIdle      = 10 * time.Minute
	tinkerLifetime  = 60 * time.Minute
	tinkerMaxOutput = 8 << 20
	tinkerPerSite   = 5
	maxTinkerInput  = 64 << 10
	psyshConfig     = toolsDir + "/psysh/config.php"
)

// psyshConf makes PsySH a plain REPL on a pipe: interactive even without
// a terminal, no colours, pager, update checks or banner. The prompt is
// a control character the output filter removes.
var psyshConf = []byte(`<?php // ` + txn.Marker + `. Manual changes are overwritten.
return [
    'interactiveMode' => \Psy\Configuration::INTERACTIVE_MODE_FORCED,
    'useReadline' => false,
    'colorMode' => \Psy\Configuration::COLOR_MODE_DISABLED,
    'pager' => false,
    'updateCheck' => 'never',
    'updateManualCheck' => 'never',
    'startupMessage' => '',
    'prompt' => "\x01",
];
`)

// TinkerSpec starts a session.
type TinkerSpec struct {
	App
	Session string `json:"session"`
}

// TinkerInput is code typed in a session.
type TinkerInput struct {
	Domain  string `json:"domain"`
	Session string `json:"session"`
	Code    string `json:"code"`
}

// TinkerState says whether a session still runs.
type TinkerState struct {
	Running bool `json:"running"`
}

type tinkerSession struct {
	proc    host.Process
	out     *tinkerOutput
	mu      sync.Mutex
	last    time.Time
	stopped bool
	done    chan struct{}
	// ctx carries the language of the person who opened it.
	ctx context.Context
}

// tinkerOutput writes the session's output to its run log, without the
// prompt character, and stops the session when it prints too much.
type tinkerOutput struct {
	mu   sync.Mutex
	f    *os.File
	n    int
	over func()
}

func (o *tinkerOutput) Write(p []byte) (int, error) {
	o.mu.Lock()
	defer o.mu.Unlock()
	o.n += len(p)
	if o.n > tinkerMaxOutput {
		if o.over != nil {
			go o.over()
			o.over = nil
		}
		return len(p), nil
	}
	_, err := o.f.Write(bytes.ReplaceAll(p, []byte{1}, nil))
	return len(p), err
}

func (o *tinkerOutput) note(s string) {
	o.mu.Lock()
	defer o.mu.Unlock()
	_, _ = io.WriteString(o.f, s)
}

func tinkerKey(domain, session string) string { return domain + "/" + session }

// StartTinker starts a Tinker session in the application.
func (m *Manager) StartTinker(ctx context.Context, spec TinkerSpec) (TinkerState, error) {
	if err := spec.App.validate(); err != nil {
		return TinkerState{}, err
	}
	if err := validateID("session", spec.Session); err != nil {
		return TinkerState{}, err
	}
	starter, ok := m.h.(host.Starter)
	if !ok {
		return TinkerState{}, invalid("this server cannot run Tinker sessions")
	}
	if err := m.requireApp(spec.App); err != nil {
		return TinkerState{}, err
	}
	if !m.sim && !m.exists(spec.dir()+"/vendor/psy/psysh") {
		return TinkerState{}, invalid("the application has no laravel/tinker; run composer require laravel/tinker")
	}
	m.tmu.Lock()
	defer m.tmu.Unlock()
	if _, ok := m.tinkers[tinkerKey(spec.Domain, spec.Session)]; ok {
		return TinkerState{Running: true}, nil
	}
	n := 0
	for k := range m.tinkers {
		if strings.HasPrefix(k, spec.Domain+"/") {
			n++
		}
	}
	if n >= tinkerPerSite {
		return TinkerState{}, invalid("at most %d Tinker sessions can run at once; close one first", tinkerPerSite)
	}

	t := txn.New(m.h)
	for _, d := range []string{toolsDir, toolsDir + "/psysh"} {
		if err := t.RootDir(d, 0o755, 0, 0); err != nil {
			return TinkerState{}, &OperationError{err}
		}
	}
	if err := t.WriteFile(psyshConfig, psyshConf); err != nil {
		return TinkerState{}, &OperationError{err}
	}
	if err := m.writeShims(spec.PHPVersion); err != nil {
		return TinkerState{}, &OperationError{err}
	}
	log, err := m.openRun(spec.Domain, spec.Session)
	if err != nil {
		return TinkerState{}, &OperationError{err}
	}
	key := tinkerKey(spec.Domain, spec.Session)
	out := &tinkerOutput{f: log}
	out.over = func() { m.endTinker(key, i18n.T(ctx, "çıktı 8 MB sınırını aştı")) }
	out.note(i18n.T(ctx, "Tinker · %s · PHP %s · %s kullanıcısıyla", spec.Domain, spec.PHPVersion, spec.User) + "\n" +
		i18n.T(ctx, "Yazdıklarınız gerçek veritabanında çalışır. Oturum %d dakika işlem yapılmazsa kapanır.", int(tinkerIdle.Minutes())) + "\n")
	c := m.cmdEnv(spec.Target, spec.dir(), nil, []string{"PSYSH_CONFIG=" + psyshConfig}, phpBin(spec.PHPVersion), "artisan", "tinker")
	proc, err := starter.Start(c, out)
	if err != nil {
		log.Close()
		return TinkerState{}, failed("tinker could not be started: %v", err)
	}
	s := &tinkerSession{proc: proc, out: out, last: time.Now(), done: make(chan struct{}), ctx: context.WithoutCancel(ctx)}
	m.tinkers[key] = s

	go func() {
		_ = proc.Wait()
		close(s.done)
		s.mu.Lock()
		stopped := s.stopped
		s.mu.Unlock()
		if !stopped {
			out.note("\n■ " + i18n.T(ctx, "Oturum kapandı.") + "\n")
		}
		m.tmu.Lock()
		if m.tinkers[key] == s {
			delete(m.tinkers, key)
		}
		m.tmu.Unlock()
		log.Close()
	}()
	go func() {
		started := time.Now()
		tick := time.NewTicker(15 * time.Second)
		defer tick.Stop()
		for {
			select {
			case <-s.done:
				return
			case now := <-tick.C:
				s.mu.Lock()
				idle := now.Sub(s.last)
				s.mu.Unlock()
				switch {
				case idle > tinkerIdle:
					m.endTinker(key, i18n.T(ctx, "%d dakika işlem yapılmadı", int(tinkerIdle.Minutes())))
				case now.Sub(started) > tinkerLifetime:
					m.endTinker(key, i18n.T(ctx, "en uzun oturum süresi (%d dakika) doldu", int(tinkerLifetime.Minutes())))
				}
			}
		}
	}()
	return TinkerState{Running: true}, nil
}

// endTinker stops a session and says why in its log.
func (m *Manager) endTinker(key, why string) {
	m.tmu.Lock()
	s, ok := m.tinkers[key]
	delete(m.tinkers, key)
	m.tmu.Unlock()
	if !ok {
		return
	}
	s.mu.Lock()
	s.stopped = true
	s.mu.Unlock()
	if why != "" {
		s.out.note("\n■ " + i18n.T(s.ctx, "Oturum kapatıldı: %s.", why) + "\n")
	} else {
		s.out.note("\n■ " + i18n.T(s.ctx, "Oturum kapatıldı.") + "\n")
	}
	_ = s.proc.Kill()
}

// TinkerSend types code into a running session.
func (m *Manager) TinkerSend(in TinkerInput) (TinkerState, error) {
	if err := (Target{Domain: in.Domain, User: "x"}).validate(); err != nil {
		return TinkerState{}, err
	}
	if err := validateID("session", in.Session); err != nil {
		return TinkerState{}, err
	}
	if len(in.Code) > maxTinkerInput {
		return TinkerState{}, invalid("input must be shorter than 64 KB")
	}
	m.tmu.Lock()
	s, ok := m.tinkers[tinkerKey(in.Domain, in.Session)]
	m.tmu.Unlock()
	if !ok {
		return TinkerState{Running: false}, nil
	}
	code := strings.TrimRight(strings.ReplaceAll(in.Code, "\r\n", "\n"), "\n")
	s.mu.Lock()
	s.last = time.Now()
	s.mu.Unlock()
	s.out.note(">>> " + strings.ReplaceAll(code, "\n", "\n... ") + "\n")
	if _, err := s.proc.Write([]byte(code + "\n")); err != nil {
		return TinkerState{Running: false}, nil
	}
	return TinkerState{Running: true}, nil
}

// StopTinker ends a session.
func (m *Manager) StopTinker(in TinkerInput) (TinkerState, error) {
	if err := (Target{Domain: in.Domain, User: "x"}).validate(); err != nil {
		return TinkerState{}, err
	}
	if err := validateID("session", in.Session); err != nil {
		return TinkerState{}, err
	}
	m.endTinker(tinkerKey(in.Domain, in.Session), "")
	return TinkerState{Running: false}, nil
}

// TinkerRunning reports whether a session still runs.
func (m *Manager) TinkerRunning(domain, session string) bool {
	m.tmu.Lock()
	defer m.tmu.Unlock()
	_, ok := m.tinkers[tinkerKey(domain, session)]
	return ok
}
