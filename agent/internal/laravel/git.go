package laravel

import (
	"context"
	"fmt"
	"net/url"
	"os"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/i18n"
	"github.com/winchester37/servik/agent/internal/site"
)

// Git deploys of plain sites (Git dağıtımı): the code of a site that is
// not a Laravel application comes from a Git repository and is checked
// out straight into a folder of the site, usually public_html. They share
// this package's account helpers and per-site lock with Laravel deploys.
//
// Layout, in the site account's home (user kahve, site kahve.com):
//
//	/home/kahve/.servik/git/kahve.com/repo.git     bare clone; its work tree is the target folder
//	/home/kahve/.servik/git/kahve.com/.ssh/        deploy key and pinned host keys
//	/home/kahve/.servik/git/kahve.com/before.tar   the files the first deploy overwrote
//
// A deploy fetches the branch and checks its commit out into the folder:
// changed files are written, files the repository deleted are removed,
// and files the repository does not track (uploads, a local settings
// file) stay. refs/servik/live is the commit the folder holds, so going
// back to an earlier deploy is a checkout of that commit, and undoing the
// first deploy removes its files and unpacks before.tar.
//
// Run logs: /var/lib/servik/git/<domain>/runs/<run>.log, root-owned.

const gitStateDir = "/var/lib/servik/git"

// placeholderMark is in the default page a new site starts with
// (site/placeholder.html); the first deploy moves that page aside with
// the files it overwrites.
const placeholderMark = `<a class="btn btn-ghost" href="#servik">`

var commitRe = regexp.MustCompile(`^[0-9a-f]{40}([0-9a-f]{24})?$`)

func gitDir(t Target) string         { return "/home/" + t.User + "/.servik/git/" + t.Domain }
func gitRunDir(domain string) string { return gitStateDir + "/" + domain + "/runs" }

// GitSpec is one deploy of a site from Git, a return to an earlier
// deploy (Commit) or the undo of the first one (Undo).
type GitSpec struct {
	Target
	// Run names the log the deploy writes to while it runs.
	Run        string `json:"run"`
	Repository string `json:"repository"`
	Branch     string `json:"branch"`
	// Path is the folder the code goes to, relative to the site directory.
	Path string `json:"path"`
	// Token signs in to an https:// repository: a token, or user:token.
	// It is written to a file for the fetch only and removed after it.
	Token string `json:"token"`
	// Commit checks out this earlier commit instead of fetching the branch.
	Commit string `json:"commit"`
	// Undo puts the folder back as it was before the first deploy.
	Undo bool `json:"undo"`
	// Commands is a script run with bash -e as the site's account in the
	// folder after the checkout (composer install, a build).
	Commands string `json:"commands"`
}

// GitResult describes a finished deploy.
type GitResult struct {
	Commit   string `json:"commit"`
	Message  string `json:"message"`
	Author   string `json:"author"`
	Previous string `json:"previous"`
	// Changed is how many files the deploy changed.
	Changed int `json:"changed"`
	// Saved is how many existing files the first deploy overwrote; they
	// are in before.tar until the deploy is undone or the site disconnected.
	Saved int `json:"saved"`
	// Problem is set when the commands failed; the files are live anyway.
	Problem string `json:"problem,omitempty"`
	Millis  int64  `json:"ms"`
}

func (s GitSpec) validate(local bool) error {
	if err := s.Target.validate(); err != nil {
		return err
	}
	if err := validateID("run", s.Run); err != nil {
		return err
	}
	if err := site.ValidateRelPath(s.Path); err != nil {
		return invalid("path: %s", err.Error())
	}
	if s.Undo {
		return nil
	}
	if !(repoRe.MatchString(s.Repository) || local && strings.HasPrefix(s.Repository, "/")) || strings.Contains(s.Repository, "..") {
		return invalid("repository %q must be an https:// or SSH address such as git@github.com:owner/site.git", s.Repository)
	}
	if !refRe.MatchString(s.Branch) || strings.Contains(s.Branch, "..") {
		return invalid("branch %q must be a branch name", s.Branch)
	}
	if s.Commit != "" && !commitRe.MatchString(s.Commit) {
		return invalid("commit %q must be a full commit hash", s.Commit)
	}
	if s.Token != "" && (!strings.HasPrefix(s.Repository, "https://") || len(s.Token) > 500 || strings.ContainsAny(s.Token, " \t\r\n")) {
		return invalid("token is only for https:// repositories and must be one line")
	}
	if len(s.Commands) > 16<<10 {
		return invalid("commands must be shorter than 16 KB")
	}
	return nil
}

// siteGit runs the steps of one Git deploy with deploy's helpers; dir
// is the target folder.
type siteGit struct {
	*deploy
	spec GitSpec
	repo string
}

// git runs a quiet Git command against the site's repository.
func (g *siteGit) git(args ...string) (string, error) {
	c := g.m.cmdEnv(g.spec.Target, g.base, nil, g.env, "git", append([]string{"--git-dir=" + g.repo}, args...)...)
	out, err := g.m.h.Run(g.ctx, c)
	if err != nil {
		return out, fmt.Errorf("git %s failed: %s", args[0], firstLine(out))
	}
	return out, nil
}

// GitDeploy puts a commit of the site's repository into its folder.
func (m *Manager) GitDeploy(ctx context.Context, spec GitSpec) (res GitResult, err error) {
	if err := spec.validate(m.localRepos); err != nil {
		return GitResult{}, err
	}
	if !m.isDir(siteDir(spec.Target)) {
		return GitResult{}, invalid("site %s does not exist", spec.Domain)
	}
	unlock := m.lock(spec.Domain)
	defer unlock()

	log, err := m.openLog(gitRunDir(spec.Domain), spec.Run)
	if err != nil {
		return GitResult{}, &OperationError{err}
	}
	defer log.Close()
	ctx, cancel := context.WithTimeout(ctx, deployTimeout)
	defer cancel()

	start := time.Now()
	base := gitDir(spec.Target)
	g := &siteGit{
		deploy: &deploy{m: m, spec: DeploySpec{Target: spec.Target}, log: log, ctx: ctx, base: base, dir: siteDir(spec.Target) + "/" + spec.Path},
		spec:   spec,
		repo:   base + "/repo.git",
	}
	defer func() {
		if err != nil {
			fmt.Fprintf(log, "✗ %s\n", err)
			err = &OperationError{err}
		}
	}()

	if spec.Undo {
		res, err = g.undo()
	} else {
		res, err = g.run()
	}
	if err != nil {
		return GitResult{}, err
	}
	res.Millis = time.Since(start).Milliseconds()
	if res.Problem == "" {
		fmt.Fprintf(log, "✓ %s\n", i18n.T(ctx, "Tamamlandı. Toplam %s.", humanDuration(ctx, time.Since(start))))
	}
	return res, nil
}

func (g *siteGit) run() (res GitResult, err error) {
	m, s := g.m, g.spec
	if s.Commit != "" {
		g.step("Önceki sürüme dönülüyor · %s · %s", s.Commit[:7], s.Path)
	} else {
		g.step("Dağıtım başladı · %s · %s → %s", s.Branch, s.Repository, s.Path)
	}
	if !m.exists("/usr/bin/git") {
		g.step("Git kuruluyor")
		inst := host.Command{Name: "apt-get", Args: []string{"install", "-y", "-q", "--no-install-recommends", "git"}, Env: []string{"DEBIAN_FRONTEND=noninteractive"}}
		if _, err := m.h.Run(g.ctx, inst); err != nil {
			return res, fmt.Errorf("installing git failed: %w", err)
		}
	}
	if err := g.user("mkdir", "-p", g.base, g.dir); err != nil {
		return res, err
	}
	if !m.exists(g.repo + "/HEAD") {
		if err := g.user("git", "init", "--bare", "--quiet", g.repo); err != nil {
			return res, err
		}
	}
	g.env = gitEnv(g.base)

	commit := s.Commit
	if commit == "" {
		if commit, err = g.fetch(); err != nil {
			return res, err
		}
	} else if _, err := g.git("cat-file", "-e", commit+"^{commit}"); err != nil {
		return res, fmt.Errorf("commit %s is no longer on the server", commit[:7])
	}

	// A folder other than the one deployed to before starts afresh.
	previous := ""
	if out, err := g.git("config", "--get", "servik.path"); err == nil && strings.TrimSpace(out) == s.Path {
		if out, err := g.git("rev-parse", "--verify", "-q", "refs/servik/live"); err == nil {
			previous = firstLine(out)
		}
	}
	if previous == "" {
		_, _ = m.userRun(g.ctx, s.Target, "/", "rm", "-f", g.repo+"/index")
		if res.Saved, err = g.saveExisting(commit); err != nil {
			return res, err
		}
	}

	g.step("Dosyalar yazılıyor: %s → %s", commit[:7], s.Path)
	if err := g.exec(g.base, "git checkout -f "+commit[:7], "git", "--git-dir="+g.repo, "--work-tree="+g.dir, "checkout", "-f", "-q", commit); err != nil {
		return res, err
	}
	if _, err := g.git("update-ref", "refs/servik/live", commit); err != nil {
		return res, err
	}
	if _, err := g.git("config", "servik.path", s.Path); err != nil {
		return res, err
	}

	res.Commit, res.Previous = commit, previous
	if out, err := g.git("log", "-1", "--format=%s%n%an", commit); err == nil {
		lines := strings.SplitN(strings.TrimSpace(out), "\n", 2)
		res.Message = strings.TrimSpace(lines[0])
		if len(lines) == 2 {
			res.Author = strings.TrimSpace(lines[1])
		}
	}
	res.Changed = g.changed(previous, commit)
	switch {
	case previous == commit:
		g.step("Değişiklik yok: %s zaten yayında", commit[:7])
	case previous == "":
		g.step("%s %s yayında · %d dosya", commit[:7], res.Message, res.Changed)
	default:
		g.step("%s → %s %s · %d dosya değişti", previous[:7], commit[:7], res.Message, res.Changed)
	}

	if strings.TrimSpace(s.Commands) != "" {
		if err := g.commands(); err != nil {
			res.Problem = err.Error()
			fmt.Fprintf(g.log, "⚠ %s\n", i18n.T(g.ctx, "Dosyalar yayında, ama dağıtım sonrası komutlar başarısız oldu."))
		}
	}
	return res, nil
}

// fetch brings the branch up to date and returns its newest commit.
func (g *siteGit) fetch() (string, error) {
	s := g.spec
	if _, err := g.git("config", "remote.origin.url", s.Repository); err != nil {
		return "", err
	}
	args := []string{"--git-dir=" + g.repo}
	if s.Token != "" {
		file := g.base + "/.git-credentials"
		if err := g.m.writeFile(g.ctx, s.Target, file, []byte(credentialLine(s.Repository, s.Token)+"\n"), "600"); err != nil {
			return "", err
		}
		defer func() { _, _ = g.m.userRun(context.WithoutCancel(g.ctx), s.Target, "/", "rm", "-f", file) }()
		args = append(args, "-c", "credential.helper=", "-c", "credential.helper=store --file="+file)
	}
	args = append(args, "fetch", "--no-tags", "--prune", "origin", "+refs/heads/"+s.Branch+":refs/remotes/origin/"+s.Branch)
	if err := g.exec(g.base, "git fetch origin "+s.Branch, "git", args...); err != nil {
		return "", err
	}
	out, err := g.git("rev-parse", "--verify", "refs/remotes/origin/"+s.Branch+"^{commit}")
	if err != nil {
		return "", fmt.Errorf("branch %s was not found in the repository", s.Branch)
	}
	commit := firstLine(out)
	if !commitRe.MatchString(commit) {
		return "", fmt.Errorf("branch %s was not found in the repository", s.Branch)
	}
	return commit, nil
}

// credentialLine is Git's stored-credential form of token for repository:
// https://user:token@host. A token alone gets the user name the host
// expects with access tokens.
func credentialLine(repository, token string) string {
	u, err := url.Parse(repository)
	if err != nil {
		return ""
	}
	name, secret, ok := strings.Cut(token, ":")
	if !ok {
		secret = token
		switch {
		case strings.Contains(u.Host, "gitlab"):
			name = "oauth2"
		case strings.Contains(u.Host, "bitbucket"):
			name = "x-token-auth"
		default:
			name = "x-access-token"
		}
	}
	return "https://" + url.UserPassword(name, secret).String() + "@" + u.Host
}

// treeFiles lists the files of commit.
func (g *siteGit) treeFiles(commit string) ([]string, error) {
	out, err := g.git("ls-tree", "-r", "-z", "--name-only", commit)
	if err != nil {
		return nil, err
	}
	var files []string
	for _, f := range strings.Split(out, "\x00") {
		if f != "" {
			files = append(files, f)
		}
	}
	return files, nil
}

// saveExisting packs the files of the folder that the first checkout of
// commit would overwrite into before.tar, and moves Servik's default page
// aside with them. It returns how many files it saved.
func (g *siteGit) saveExisting(commit string) (int, error) {
	files, err := g.treeFiles(commit)
	if err != nil {
		return 0, err
	}
	var existing []string
	inTree := map[string]bool{}
	for _, f := range files {
		inTree[f] = true
		if g.m.exists(g.dir + "/" + f) {
			existing = append(existing, f)
		}
	}
	placeholder := false
	if !inTree["index.html"] && g.m.exists(g.dir+"/index.html") {
		head, _ := g.m.userRun(g.ctx, g.spec.Target, "/", "head", "-c", "65536", g.dir+"/index.html")
		if strings.Contains(head, placeholderMark) {
			existing, placeholder = append(existing, "index.html"), true
		}
	}
	backup := g.base + "/before.tar"
	_, _ = g.m.userRun(g.ctx, g.spec.Target, "/", "rm", "-f", backup)
	if len(existing) == 0 {
		return 0, nil
	}
	c := g.m.cmd(g.spec.Target, "/", nil, "tar", "-C", g.dir, "-cf", backup, "--null", "-T", "-")
	c.Stdin = []byte(strings.Join(existing, "\x00") + "\x00")
	if _, err := g.m.h.Run(g.ctx, c); err != nil {
		return 0, fmt.Errorf("saving the files the deploy overwrites failed")
	}
	if placeholder {
		if err := g.user("rm", "-f", g.dir+"/index.html"); err != nil {
			return 0, err
		}
	}
	g.step("Üzerine yazılacak %d dosya saklandı; ilk dağıtım geri alınırsa geri gelir", len(existing))
	return len(existing), nil
}

// changed counts the files that differ between two commits.
func (g *siteGit) changed(from, to string) int {
	if from == "" {
		files, _ := g.treeFiles(to)
		return len(files)
	}
	if from == to {
		return 0
	}
	out, err := g.git("diff", "--shortstat", from, to)
	if err != nil {
		return 0
	}
	n, _ := strconv.Atoi(strings.Fields(strings.TrimSpace(out) + " 0")[0])
	return n
}

// commands runs the site's post-deploy script in the folder. Composer
// and the site's PHP are on its PATH when Composer is installed.
func (g *siteGit) commands() error {
	if v := g.spec.PHPVersion; v != "" && g.m.exists(composerPhar()) {
		_ = g.m.writeShims(v)
	}
	g.step("Dağıtım sonrası komutlar")
	fmt.Fprintln(g.log, indent(strings.TrimSpace(g.spec.Commands), "  "))
	if _, err := g.m.h.Run(g.ctx, g.m.cmd(g.spec.Target, g.dir, g.log, "bash", "-e", "-o", "pipefail", "-c", g.spec.Commands)); err != nil {
		if g.ctx.Err() != nil {
			return fmt.Errorf("the commands stopped: the deploy took longer than %d minutes", int(deployTimeout.Minutes()))
		}
		return fmt.Errorf("the commands failed with exit code %d", host0(err))
	}
	return nil
}

// undo removes the files of the live commit from the folder and unpacks
// the files the first deploy overwrote.
func (g *siteGit) undo() (res GitResult, err error) {
	out, err := g.git("rev-parse", "--verify", "-q", "refs/servik/live")
	live := firstLine(out)
	if err != nil || live == "" {
		return res, invalid("nothing is deployed to %s", g.spec.Path)
	}
	g.step("İlk dağıtım geri alınıyor · %s", g.spec.Path)
	files, err := g.treeFiles(live)
	if err != nil {
		return res, err
	}
	if len(files) > 0 {
		c := g.m.cmd(g.spec.Target, g.dir, nil, "xargs", "-0", "rm", "-f", "--")
		c.Stdin = []byte(strings.Join(files, "\x00") + "\x00")
		if _, err := g.m.h.Run(g.ctx, c); err != nil {
			return res, fmt.Errorf("removing the deployed files failed")
		}
		// Folders the deploy made go too, when nothing else is in them.
		dirs := map[string]bool{}
		for _, f := range files {
			for d := dirOf("/" + f); d != "/"; d = dirOf(d) {
				dirs[d[1:]] = true
			}
		}
		if len(dirs) > 0 {
			list := make([]string, 0, len(dirs))
			for d := range dirs {
				list = append(list, d)
			}
			sort.Slice(list, func(i, j int) bool { return strings.Count(list[i], "/") > strings.Count(list[j], "/") })
			c := g.m.cmd(g.spec.Target, g.dir, nil, "xargs", "-0", "rmdir", "--ignore-fail-on-non-empty", "--")
			c.Stdin = []byte(strings.Join(list, "\x00") + "\x00")
			_, _ = g.m.h.Run(g.ctx, c)
		}
		g.step("%d dağıtılan dosya kaldırıldı", len(files))
	}
	backup := g.base + "/before.tar"
	if g.m.exists(backup) {
		if _, err := g.m.h.Run(g.ctx, g.m.cmd(g.spec.Target, "/", nil, "tar", "-C", g.dir, "-xf", backup)); err != nil {
			return res, fmt.Errorf("restoring the saved files failed")
		}
		_ = g.user("rm", "-f", backup)
		g.step("Dağıtımdan önceki dosyalar geri yüklendi")
	}
	if _, err := g.git("update-ref", "-d", "refs/servik/live"); err != nil {
		return res, err
	}
	_ = g.user("rm", "-f", g.repo+"/index")
	res.Previous, res.Changed = live, len(files)
	return res, nil
}

// GitKey returns the site's SSH deploy key for Git deploys, creating it
// first.
func (m *Manager) GitKey(ctx context.Context, t Target) (string, error) {
	if err := t.validate(); err != nil {
		return "", err
	}
	if !m.isDir(siteDir(t)) {
		return "", invalid("site %s does not exist", t.Domain)
	}
	return m.deployKey(ctx, t, gitDir(t)+"/.ssh")
}

// GitRemove forgets the site's repository, deploy key and deploy logs.
// The deployed files stay in the folder.
func (m *Manager) GitRemove(ctx context.Context, t Target) (map[string]bool, error) {
	if err := t.validate(); err != nil {
		return nil, err
	}
	unlock := m.lock(t.Domain)
	defer unlock()
	if m.exists(gitDir(t)) {
		if _, err := m.userRun(ctx, t, "/", "rm", "-rf", "--one-file-system", gitDir(t)); err != nil {
			return nil, failed("removing %s failed", gitDir(t))
		}
	}
	_ = os.RemoveAll(m.h.Path(gitStateDir + "/" + t.Domain))
	return map[string]bool{"removed": true}, nil
}

// ReadGitRun returns the log of a Git deploy from offset on.
func (m *Manager) ReadGitRun(domain, run string, offset int64) (RunChunk, error) {
	if err := (Target{Domain: domain, User: "x"}).validate(); err != nil {
		return RunChunk{}, err
	}
	if err := validateID("run", run); err != nil {
		return RunChunk{}, err
	}
	return m.readChunk(gitRunDir(domain)+"/"+run+".log", offset, false)
}
