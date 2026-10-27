package laravel

import (
	"context"
	"errors"
	"strings"
	"testing"
)

const webDir = "/home/hizlikargo/web/api.hizlikargo.app/public_html"

func gitSpec(run string) GitSpec {
	return GitSpec{Target: target, Run: run, Repository: "https://github.com/hizlikargo/site.git", Branch: "main", Path: "public_html"}
}

func TestGitDeployChecksOutIntoTheFolder(t *testing.T) {
	m, sim := newTest(t)
	ctx := context.Background()

	res, err := m.GitDeploy(ctx, gitSpec("g1"))
	if err != nil {
		t.Fatal(err)
	}
	if len(res.Commit) != 40 || res.Previous != "" || res.Message != "Update the home page" || res.Author != "Serfex" || res.Changed != 2 {
		t.Fatalf("first deploy: %+v", res)
	}
	// The default page was saved aside, then replaced.
	if res.Saved != 1 || !exists(sim, "/home/hizlikargo/.serfex/git/api.hizlikargo.app/before.tar") {
		t.Fatalf("saved %d", res.Saved)
	}
	if page := read(t, sim, webDir+"/index.html"); !strings.Contains(page, "Update the home page") {
		t.Fatalf("index.html: %s", page)
	}
	all := commands(sim)
	for _, want := range []string{
		"git init --bare --quiet /home/hizlikargo/.serfex/git/api.hizlikargo.app/repo.git",
		"fetch --no-tags --prune origin +refs/heads/main:refs/remotes/origin/main",
		"--work-tree=" + webDir + " checkout -f -q " + res.Commit,
		"update-ref refs/serfex/live " + res.Commit,
	} {
		if !strings.Contains(all, want) {
			t.Errorf("no command with %q in:\n%s", want, all)
		}
	}

	// The next deploy knows what was live.
	first := res.Commit
	if res, err = m.GitDeploy(ctx, gitSpec("g2")); err != nil {
		t.Fatal(err)
	}
	if res.Previous != first || res.Commit == first || res.Changed != 2 || res.Saved != 0 {
		t.Fatalf("second deploy: %+v", res)
	}

	// Back to the first commit, without fetching.
	back := gitSpec("g3")
	back.Commit = first
	if res, err = m.GitDeploy(ctx, back); err != nil || res.Commit != first {
		t.Fatalf("return: %+v %v", res, err)
	}
	log, err := m.ReadGitRun(target.Domain, "g3", 0)
	if err != nil || !strings.Contains(log.Data, "Önceki sürüme dönülüyor") || strings.Contains(log.Data, "git fetch") {
		t.Fatalf("log: %q %v", log.Data, err)
	}

	// Undo of the first deploy restores the default page.
	if _, err := m.GitDeploy(ctx, GitSpec{Target: target, Run: "u1", Path: "public_html", Undo: true}); err != nil {
		t.Fatal(err)
	}
	if page := read(t, sim, webDir+"/index.html"); !strings.Contains(page, placeholderMark) || exists(sim, webDir+"/assets") {
		t.Fatalf("after undo: %.200s", page)
	}
	if _, err := m.GitDeploy(ctx, GitSpec{Target: target, Run: "u2", Path: "public_html", Undo: true}); err == nil {
		t.Fatal("a second undo must fail")
	}

	if _, err := m.GitRemove(ctx, target); err != nil || exists(sim, "/home/hizlikargo/.serfex/git/api.hizlikargo.app") || exists(sim, gitStateDir+"/"+target.Domain) {
		t.Fatalf("remove: %v", err)
	}
}

func TestGitDeployTokenNeverReachesACommandLine(t *testing.T) {
	m, sim := newTest(t)
	spec := gitSpec("g1")
	spec.Token = "ghp_s3cret"
	if _, err := m.GitDeploy(context.Background(), spec); err != nil {
		t.Fatal(err)
	}
	all := commands(sim)
	if strings.Contains(all, "s3cret") {
		t.Fatalf("the token is on a command line:\n%s", all)
	}
	if !strings.Contains(all, "credential.helper=store --file=/home/hizlikargo/.serfex/git/api.hizlikargo.app/.git-credentials") {
		t.Fatalf("no credential helper:\n%s", all)
	}
	// Written for the fetch only.
	if exists(sim, "/home/hizlikargo/.serfex/git/api.hizlikargo.app/.git-credentials") {
		t.Fatal("the credentials file was left behind")
	}
}

func TestGitDeployRunsCommandsAsTheSiteAccount(t *testing.T) {
	m, sim := newTest(t)
	spec := gitSpec("g1")
	spec.Commands = "composer install --no-dev"
	if _, err := m.GitDeploy(context.Background(), spec); err != nil {
		t.Fatal(err)
	}
	if want := "runuser -u hizlikargo -- env -C " + webDir; !strings.Contains(commands(sim), want) {
		t.Fatalf("no command with %q", want)
	}
}

func TestCredentialLine(t *testing.T) {
	for _, c := range []struct{ repo, token, want string }{
		{"https://github.com/o/r.git", "ghp_x", "https://x-access-token:ghp_x@github.com"},
		{"https://gitlab.com/o/r.git", "glpat-x", "https://oauth2:glpat-x@gitlab.com"},
		{"https://bitbucket.org/o/r.git", "ATCT", "https://x-token-auth:ATCT@bitbucket.org"},
		{"https://git.example.com:8443/o/r.git", "ali:p@ss/w", "https://ali:p%40ss%2Fw@git.example.com:8443"},
	} {
		if got := credentialLine(c.repo, c.token); got != c.want {
			t.Errorf("%s %s: %s, want %s", c.repo, c.token, got, c.want)
		}
	}
}

func TestGitSpecValidation(t *testing.T) {
	for name, mutate := range map[string]func(*GitSpec){
		"path outside the site":   func(s *GitSpec) { s.Path = "../other" },
		"hidden path":             func(s *GitSpec) { s.Path = ".serfex" },
		"bad branch":              func(s *GitSpec) { s.Branch = "-x" },
		"local repository":        func(s *GitSpec) { s.Repository = "/srv/repo" },
		"credentials in address":  func(s *GitSpec) { s.Repository = "https://u:p@github.com/o/r.git" },
		"token over ssh":          func(s *GitSpec) { s.Repository, s.Token = "git@github.com:o/r.git", "x" },
		"short commit":            func(s *GitSpec) { s.Commit = "abc1234" },
		"token with a line break": func(s *GitSpec) { s.Token = "a\nb" },
	} {
		s := gitSpec("g1")
		mutate(&s)
		var verr *ValidationError
		if err := s.validate(false); !errors.As(err, &verr) {
			t.Errorf("%s: %v", name, err)
		}
	}
}
