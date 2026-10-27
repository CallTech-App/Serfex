//go:build realhost

// This test deploys a site from a real Git repository as a real account.
// It needs root and changes the machine (a user, files in its home), so it
// only builds with -tags realhost:
//
//	sudo go test -tags realhost -run TestGitRealHost ./internal/laravel
package laravel

import (
	"context"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"

	"github.com/calltech-app/serfex/agent/internal/host"
)

func TestGitRealHost(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("needs root")
	}
	const user, domain = "serfexg", "serfex-git.example"
	sh := func(dir string, args ...string) string {
		t.Helper()
		cmd := exec.Command(args[0], args[1:]...)
		cmd.Dir = dir
		out, err := cmd.CombinedOutput()
		if err != nil {
			t.Fatalf("%v: %v\n%s", args, err, out)
		}
		return strings.TrimSpace(string(out))
	}
	if _, err := os.Stat("/home/" + user); err != nil {
		sh("/", "useradd", "-m", "-s", "/bin/bash", user)
	}
	t.Cleanup(func() { _ = exec.Command("userdel", "-r", user).Run() })

	// The repository: two commits on main.
	src, err := os.MkdirTemp("", "serfex-git-src")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = os.RemoveAll(src) })
	write := func(dir, name, body string) {
		t.Helper()
		p := filepath.Join(dir, name)
		if err := os.MkdirAll(filepath.Dir(p), 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(p, []byte(body), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	git := func(args ...string) string {
		return sh(src, append([]string{"git", "-c", "user.name=Serfex", "-c", "user.email=test@serfex.example", "-c", "init.defaultBranch=main"}, args...)...)
	}
	git("init", "-q")
	write(src, "index.php", "<?php echo 'v1';")
	write(src, "about.html", "about v1")
	write(src, "css/site.css", "body{}")
	git("add", "-A")
	git("commit", "-qm", "First version")
	c1 := git("rev-parse", "HEAD")
	write(src, "index.php", "<?php echo 'v2';")
	write(src, "new.txt", "new")
	git("rm", "-q", "-r", "css")
	git("add", "-A")
	git("commit", "-qm", "Second version")
	c2 := git("rev-parse", "HEAD")
	// Git refuses a repository another account owns.
	sh("/", "chown", "-R", user, src)

	// The site: Serfex's default page, an about page the repository
	// overwrites and an upload it does not track.
	web := "/home/" + user + "/web/" + domain + "/public_html"
	sh("/", "runuser", "-u", user, "--", "mkdir", "-p", web)
	for name, body := range map[string]string{"index.html": "<!-- " + placeholderMark + " -->", "about.html": "old about", "upload.jpg": "jpg"} {
		sh("/", "runuser", "-u", user, "--", "sh", "-c", "printf '%s' '"+body+"' > "+web+"/"+name)
	}
	t.Cleanup(func() { _ = os.RemoveAll(gitStateDir + "/" + domain) })

	ctx := context.Background()
	m := NewManager(host.System{})
	m.localRepos = true
	tg := Target{Domain: domain, User: user}
	spec := GitSpec{Target: tg, Run: "d1", Repository: src, Branch: "main", Path: "public_html"}
	read := func(name string) string {
		b, _ := os.ReadFile(web + "/" + name)
		return string(b)
	}
	present := func(name string) bool {
		_, err := os.Lstat(web + "/" + name)
		return err == nil
	}

	// 1. The first deploy saves what it overwrites.
	res, err := m.GitDeploy(ctx, spec)
	if err != nil {
		log, _ := m.ReadGitRun(domain, "d1", 0)
		t.Fatalf("%v\n%s", err, log.Data)
	}
	if res.Commit != c2 || res.Previous != "" || res.Saved != 2 || res.Message != "Second version" || res.Author != "Serfex" {
		t.Fatalf("first deploy: %+v", res)
	}
	if read("about.html") != "about v1" || present("index.html") || read("upload.jpg") != "jpg" || present("css") {
		t.Fatalf("after the first deploy: about=%q index=%v upload=%q", read("about.html"), present("index.html"), read("upload.jpg"))
	}
	if present(".git") {
		t.Fatal("the folder must not get a .git directory")
	}
	assertOwner(t, web+"/new.txt", user)

	if l, _ := m.ReadGitRun(domain, "d1", 0); testing.Verbose() {
		t.Log(l.Data)
	}

	// 2. Back to the first commit: deleted files return, new ones go.
	spec.Run, spec.Commit = "d2", c1
	if res, err = m.GitDeploy(ctx, spec); err != nil {
		t.Fatal(err)
	}
	if res.Previous != c2 || res.Commit != c1 || res.Changed != 3 || !present("css/site.css") || present("new.txt") || read("index.php") != "<?php echo 'v1';" {
		t.Fatalf("return: %+v", res)
	}

	// 3. Commands run as the site's account in the folder; a failure
	// leaves the files live.
	spec.Run, spec.Commit, spec.Commands = "d3", "", "echo built > built.txt\nwhoami >> built.txt"
	if res, err = m.GitDeploy(ctx, spec); err != nil || res.Problem != "" {
		t.Fatalf("commands: %+v %v", res, err)
	}
	if read("built.txt") != "built\n"+user+"\n" {
		t.Fatalf("built.txt: %q", read("built.txt"))
	}
	spec.Run, spec.Commands = "d4", "exit 3"
	if res, err = m.GitDeploy(ctx, spec); err != nil || !strings.Contains(res.Problem, "exit code 3") || res.Changed != 0 {
		t.Fatalf("failing commands: %+v %v", res, err)
	}
	chunk, err := m.ReadGitRun(domain, "d4", 0)
	if err != nil || !strings.Contains(chunk.Data, "exit 3") {
		t.Fatalf("log: %+v %v", chunk, err)
	}

	// 4. Undoing the first deploy brings the folder back.
	if res, err = m.GitDeploy(ctx, GitSpec{Target: tg, Run: "u1", Path: "public_html", Undo: true}); err != nil {
		t.Fatal(err)
	}
	if read("about.html") != "old about" || !strings.Contains(read("index.html"), placeholderMark) || read("upload.jpg") != "jpg" ||
		present("index.php") || present("new.txt") || present("css") || read("built.txt") == "" {
		t.Fatalf("after undo: about=%q index=%v php=%v", read("about.html"), present("index.html"), present("index.php"))
	}

	// 5. The deploy key belongs to the account; removing forgets it all.
	key, err := m.GitKey(ctx, tg)
	if err != nil || !strings.HasPrefix(key, "ssh-ed25519 ") {
		t.Fatalf("key: %q %v", key, err)
	}
	assertOwner(t, gitDir(tg)+"/.ssh/id_ed25519", user)
	if _, err := m.GitRemove(ctx, tg); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(gitDir(tg)); !os.IsNotExist(err) {
		t.Fatalf("%s is still there: %v", gitDir(tg), err)
	}
}
