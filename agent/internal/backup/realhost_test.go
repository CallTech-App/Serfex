//go:build realhost

// This test backs a real site up (its files, a MySQL database and a
// mailbox) to local disk, to S3-compatible storage and to an SFTP server,
// destroys the site, and restores it from each of them. It needs root
// and changes the machine, so it only builds with -tags realhost:
//
//	sudo SERVIK_S3_ENDPOINT=http://127.0.0.1:9000 SERVIK_S3_ACCESS_KEY=... SERVIK_S3_SECRET_KEY=... \
//	    go test -tags realhost -run TestRealHost ./internal/backup
//
// Without SERVIK_S3_ENDPOINT the S3 copy goes to an in-process fake; CI
// runs moto's S3 server. The SFTP target is this machine's own sshd, signed in to
// as a local "servikbak" account.
package backup

import (
	"context"
	"errors"
	"fmt"
	"net/http/httptest"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/calltech-app/serfex/agent/internal/database"
	"github.com/calltech-app/serfex/agent/internal/host"
	"github.com/calltech-app/serfex/agent/internal/laravel"
	"github.com/calltech-app/serfex/agent/internal/site"
)

const (
	realDomain   = "yedek-test.example"
	realUser     = "yedekt"
	realDB       = "yedekt_shop"
	realDBUser   = "yedekt_app"
	realPassword = "Gizli-parola-42"
	sftpAccount  = "servikbak"
)

func hasSystemd() bool {
	b, err := os.ReadFile("/proc/1/comm")
	return err == nil && strings.TrimSpace(string(b)) == "systemd"
}

// serviceHost is the real system. Where systemd is not PID 1
// (containers) it starts and reloads Nginx, PHP-FPM and MySQL itself.
type serviceHost struct{ host.System }

func (h serviceHost) sh(ctx context.Context, script string) (string, error) {
	return h.System.Run(ctx, host.Command{Name: "sh", Args: []string{"-c", script}})
}

func (h serviceHost) Run(ctx context.Context, c host.Command) (string, error) {
	if c.Name != "systemctl" || hasSystemd() {
		return h.System.Run(ctx, c)
	}
	if len(c.Args) < 2 {
		return "", nil
	}
	verb, unit := c.Args[0], c.Args[len(c.Args)-1]
	switch {
	case unit == "nginx":
		if verb == "is-active" || verb == "start" {
			return h.sh(ctx, "test -f /run/nginx.pid && kill -0 $(cat /run/nginx.pid) || nginx")
		}
		return h.sh(ctx, "nginx -s reload")
	case strings.HasPrefix(unit, "php") && strings.HasSuffix(unit, "-fpm"):
		ver := strings.TrimSuffix(strings.TrimPrefix(unit, "php"), "-fpm")
		pid := "/run/php/php" + ver + "-fpm.pid"
		return h.sh(ctx, "if test -f "+pid+" && kill -0 $(cat "+pid+"); then kill -USR2 $(cat "+pid+"); else php-fpm"+ver+"; fi")
	case unit == "mysql" || unit == "mariadb":
		switch verb {
		case "is-active":
			verb = "status"
		case "reload-or-restart":
			verb = "restart"
		case "start", "restart", "reload", "stop":
		default:
			return "", nil
		}
		return h.sh(ctx, "service "+unit+" "+verb)
	}
	return "", nil
}

func run(t *testing.T, name string, args ...string) string {
	t.Helper()
	out, err := exec.Command(name, args...).CombinedOutput()
	if err != nil {
		t.Fatalf("%s %s: %v\n%s", name, strings.Join(args, " "), err, out)
	}
	return strings.TrimSpace(string(out))
}

// asUser writes a file as an account, as the account's own processes do.
func asUser(t *testing.T, user, file, content string) {
	t.Helper()
	cmd := exec.Command("runuser", "-u", user, "--", "sh", "-c", `mkdir -p "$(dirname "$1")" && cat > "$1"`, "sh", file)
	cmd.Stdin = strings.NewReader(content)
	if out, err := cmd.CombinedOutput(); err != nil {
		t.Fatalf("writing %s as %s: %v\n%s", file, user, err, out)
	}
}

func sqlAs(user, password, query string) (string, error) {
	cmd := exec.Command("mysql", "--batch", "--skip-column-names", "-h", "127.0.0.1", "-u", user, realDB, "-e", query)
	cmd.Env = append(os.Environ(), "MYSQL_PWD="+password)
	out, err := cmd.CombinedOutput()
	if err != nil {
		return "", errors.New(strings.TrimSpace(string(out)))
	}
	return strings.TrimSpace(string(out)), nil
}

// s3Spec is the server at SERVIK_S3_ENDPOINT when set, else a fake.
func s3Spec(t *testing.T) TargetSpec {
	t.Helper()
	spec := TargetSpec{Type: "s3", Prefix: "servik/realhost", Bucket: "servik-yedek", Region: "us-east-1", PathStyle: true,
		Endpoint: os.Getenv("SERVIK_S3_ENDPOINT"), AccessKey: os.Getenv("SERVIK_S3_ACCESS_KEY"), SecretKey: os.Getenv("SERVIK_S3_SECRET_KEY")}
	if spec.Endpoint == "" {
		t.Log("SERVIK_S3_ENDPOINT is not set; using an in-process S3")
		srv := httptest.NewServer(&fakeS3{objects: map[string][]byte{}, parts: map[string]map[string][]byte{}})
		t.Cleanup(srv.Close)
		spec.Endpoint, spec.Bucket, spec.AccessKey, spec.SecretKey = srv.URL, "yedek", "AK", "SK"
		return spec
	}
	// The server starts empty: make the bucket.
	s, err := newS3(spec, nil)
	if err != nil {
		t.Fatal(err)
	}
	resp, err := s.do(context.Background(), "PUT", "", nil, nil, 0, "", nil)
	var se *s3Error
	if err != nil && !(errors.As(err, &se) && se.Status == 409) {
		t.Fatalf("creating the bucket: %v", err)
	}
	if resp != nil {
		resp.Body.Close()
	}
	return spec
}

// sftpSpec signs in to this machine's sshd as a local account that has
// the agent's key.
func sftpSpec(t *testing.T, m *Manager) TargetSpec {
	t.Helper()
	if _, err := os.Stat("/usr/sbin/sshd"); err != nil {
		t.Fatal("openssh-server is not installed")
	}
	if err := exec.Command("id", sftpAccount).Run(); err != nil {
		run(t, "useradd", "--create-home", "--shell", "/bin/sh", sftpAccount)
		// A locked account (no password) cannot sign in with a key.
		run(t, "usermod", "-p", "*", sftpAccount)
	}
	if exec.Command("sh", "-c", "ss -ltn | grep -q ':22 '").Run() != nil {
		run(t, "sh", "-c", "mkdir -p /run/sshd && ssh-keygen -A >/dev/null && /usr/sbin/sshd")
		time.Sleep(time.Second)
	}
	key, err := m.PublicKey()
	if err != nil {
		t.Fatal(err)
	}
	home := "/home/" + sftpAccount
	asUser(t, sftpAccount, home+"/.ssh/authorized_keys", key+"\n")
	run(t, "chmod", "700", home+"/.ssh")
	run(t, "chmod", "600", home+"/.ssh/authorized_keys")
	return TargetSpec{Type: "sftp", Prefix: "yedekler/realhost", Host: "127.0.0.1", Port: 22, User: sftpAccount}
}

// destroy removes the site, its account, database, database user and
// mailboxes, as deleting it in the panel does.
func destroy(t *testing.T, sites *site.Manager, db *database.Manager) {
	t.Helper()
	ctx := context.Background()
	if _, err := sites.Delete(ctx, site.DeleteSpec{Domain: realDomain, User: realUser, RemoveFiles: true, RemoveUser: true}); err != nil {
		t.Fatal(err)
	}
	if _, err := db.DeleteUser(ctx, database.UserRef{Server: database.MySQL, Name: realDBUser}); err != nil {
		t.Fatal(err)
	}
	if _, err := db.DeleteDatabase(ctx, database.DeleteSpec{Server: database.MySQL, Name: realDB}); err != nil {
		t.Fatal(err)
	}
	if err := os.RemoveAll(vmailDir + "/" + realDomain); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat("/home/" + realUser); !errors.Is(err, os.ErrNotExist) {
		t.Fatal("the account's home survived deleting the site")
	}
	if _, err := sqlAs(realDBUser, realPassword, "SELECT 1"); err == nil {
		t.Fatal("the database user survived")
	}
}

// verify checks every part came back with its content and owners.
func verify(t *testing.T, web string) {
	t.Helper()
	b, err := os.ReadFile(web + "/public_html/index.php")
	if err != nil || string(b) != "<?php echo 'yedek v1';" {
		t.Fatalf("index.php = %q, %v", b, err)
	}
	if _, err := os.Stat(web + "/public_html/after-backup.php"); !errors.Is(err, os.ErrNotExist) {
		t.Fatal("a file added after the backup survived the restore")
	}
	if owner := run(t, "stat", "-c", "%U", web+"/public_html/uploads/logo.png"); owner != realUser {
		t.Fatalf("logo.png is owned by %s", owner)
	}
	if b, err := os.ReadFile("/home/" + realUser + "/notlar.txt"); err != nil || string(b) != "hesabın kendi dosyası" {
		t.Fatalf("home file = %q, %v", b, err)
	}
	// The user signs in with its old password and sees the rows.
	out, err := sqlAs(realDBUser, realPassword, "SELECT ad FROM urunler ORDER BY id")
	if err != nil || out != "Kalem\nDefter" {
		t.Fatalf("database rows = %q, %v", out, err)
	}
	mail := vmailDir + "/" + realDomain + "/info/Maildir/cur/1700000000.M1.eml"
	if b, err := os.ReadFile(mail); err != nil || !strings.Contains(string(b), "Subject: Merhaba") {
		t.Fatalf("mail = %q, %v", b, err)
	}
	if owner := run(t, "stat", "-c", "%U", mail); owner != vmailUser {
		t.Fatalf("the message is owned by %s", owner)
	}
}

func TestRealHost(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("needs root")
	}
	h := serviceHost{}
	ctx := context.Background()
	sites := site.NewManager(h, "www-data")
	versions, err := sites.PHPVersions()
	if err != nil || len(versions) == 0 {
		t.Fatalf("no PHP-FPM installed: %v", err)
	}
	php := versions[len(versions)-1]
	db := database.NewManager(h)
	if _, err := db.Install(ctx, database.InstallSpec{Product: database.MySQL}); err != nil {
		t.Fatal(err)
	}
	m := NewManager(h, db, laravel.NewManager(h))

	// A mail server keeps mailboxes under /var/vmail, owned by vmail.
	if err := exec.Command("id", vmailUser).Run(); err != nil {
		run(t, "useradd", "--system", "--home-dir", vmailDir, "--shell", "/usr/sbin/nologin", vmailUser)
	}
	run(t, "install", "-d", "-o", vmailUser, "-g", vmailUser, "-m", "0750", vmailDir)

	setup := func() string {
		res, err := sites.Apply(ctx, site.Spec{Domain: realDomain, User: realUser, PHPVersion: php})
		if err != nil {
			t.Fatal(err)
		}
		return filepath.Dir(res.DocumentRoot)
	}
	t.Cleanup(func() {
		_, _ = sites.Delete(context.Background(), site.DeleteSpec{Domain: realDomain, User: realUser, RemoveFiles: true, RemoveUser: true})
		_, _ = db.DeleteUser(context.Background(), database.UserRef{Server: database.MySQL, Name: realDBUser})
		_, _ = db.DeleteDatabase(context.Background(), database.DeleteSpec{Server: database.MySQL, Name: realDB})
		_ = os.RemoveAll(vmailDir + "/" + realDomain)
	})
	web := setup()
	asUser(t, realUser, web+"/public_html/index.php", "<?php echo 'yedek v1';")
	asUser(t, realUser, web+"/public_html/uploads/logo.png", "PNG-veri")
	asUser(t, realUser, "/home/"+realUser+"/notlar.txt", "hesabın kendi dosyası")
	if _, err := db.CreateDatabase(ctx, database.DatabaseSpec{Server: database.MySQL, Name: realDB, Site: realDomain}); err != nil {
		t.Fatal(err)
	}
	if _, err := db.ApplyUser(ctx, database.UserSpec{Server: database.MySQL, Name: realDBUser, Password: realPassword, Grants: []database.Grant{{Database: realDB, Access: database.AccessAll}}}); err != nil {
		t.Fatal(err)
	}
	if _, err := sqlAs(realDBUser, realPassword, "CREATE TABLE urunler (id INT PRIMARY KEY, ad VARCHAR(40)); INSERT INTO urunler VALUES (1, 'Kalem'), (2, 'Defter')"); err != nil {
		t.Fatal(err)
	}
	maildir := vmailDir + "/" + realDomain + "/info/Maildir"
	run(t, "install", "-d", "-o", vmailUser, "-g", vmailUser, "-m", "0700", vmailDir+"/"+realDomain, vmailDir+"/"+realDomain+"/info", maildir, maildir+"/cur", maildir+"/new", maildir+"/tmp")
	asUser(t, vmailUser, maildir+"/cur/1700000000.M1.eml", "From: a@example.com\nSubject: Merhaba\n\nYedekten geri gelmeli.\n")

	targets := map[string]*TargetSpec{"local": nil}
	s3 := s3Spec(t)
	targets["s3"] = &s3
	sftp := sftpSpec(t, m)
	targets["sftp"] = &sftp
	for name, tg := range targets {
		if tg == nil {
			continue
		}
		res, err := m.TestTarget(ctx, *tg)
		if err != nil || !res.OK {
			t.Fatalf("testing the %s target: %+v %v", name, res, err)
		}
		if tg.Type == "sftp" {
			if !strings.HasPrefix(res.HostKey, "ssh-") {
				t.Fatalf("the SFTP host key was not learned: %+v", res)
			}
			tg.HostKey = res.HostKey
		}
	}

	item := ItemSpec{
		Name:          realDomain,
		Site:          &SiteRef{Domain: realDomain, User: realUser},
		Home:          true,
		Databases:     []DatabaseRef{{Server: database.MySQL, Name: realDB}},
		DatabaseUsers: []database.UserRef{{Server: database.MySQL, Name: realDBUser}},
		Mail:          true,
		Meta:          []byte(`{"version":1}`),
	}
	const passphrase = "realhost-yedek-parolasi"
	for _, name := range []string{"local", "s3", "sftp"} {
		t.Run(name, func(t *testing.T) {
			key := "real-" + name
			spec := CreateSpec{Key: key, Run: "run-" + key, Scope: "site", Server: "realhost", Items: []ItemSpec{item}, Target: targets[name]}
			if spec.Target != nil {
				spec.Passphrase = passphrase
			}
			res, err := m.Create(ctx, spec)
			if err != nil {
				t.Fatal(err)
			}
			if ir := res.Items[0]; ir.Error != "" || res.RemoteError != "" || ir.Parts["files"] == 0 || ir.Parts["home"] == 0 || ir.Parts["databases"] == 0 || ir.Parts["mail"] == 0 {
				t.Fatalf("backup to %s: %+v", name, res)
			}
			if spec.Target != nil {
				if res.Local || !res.Remote || !res.Encrypted {
					t.Fatalf("the %s backup should be remote and encrypted only: %+v", name, res)
				}
				if m.exists(setDir(key)) {
					t.Fatal("the local copy was kept")
				}
				// What is on the target is not readable without the passphrase.
				found, err := m.Scan(ctx, spec.Target)
				if err != nil || !slices.ContainsFunc(found, func(f Found) bool { return f.Descriptor != nil && f.Descriptor.Key == key && f.Encrypted }) {
					t.Fatalf("scan of %s: %+v %v", name, found, err)
				}
			}

			// Changes after the backup, then the site is gone.
			asUser(t, realUser, web+"/public_html/after-backup.php", "<?php evil();")
			if _, err := sqlAs(realDBUser, realPassword, "DELETE FROM urunler"); err != nil {
				t.Fatal(err)
			}
			destroy(t, sites, db)

			// As the panel does: the site is set up again, then restored.
			if got := setup(); got != web {
				t.Fatalf("web dir %s, want %s", got, web)
			}
			rr, err := m.Restore(ctx, RestoreSpec{
				Run:           "restore-" + key,
				Chain:         []ChainRef{{Key: key, Name: realDomain, Target: spec.Target}},
				Passphrase:    spec.Passphrase,
				Parts:         []string{"files", "home", "databases", "mail"},
				Site:          SiteRef{Domain: realDomain, User: realUser},
				Databases:     item.Databases,
				DatabaseUsers: []database.UserSpec{{Server: database.MySQL, Name: realDBUser, Grants: []database.Grant{{Database: realDB, Access: database.AccessAll}}}},
			})
			if err != nil {
				log, _ := m.ReadRun("restore-"+key, 0)
				t.Fatalf("restore from %s: %v\n%s", name, err, log.Data)
			}
			if !slices.Equal(rr.Restored, []string{"files", "home", "databases", "mail"}) {
				t.Fatalf("restored %v, skipped %v", rr.Restored, rr.Skipped)
			}
			verify(t, web)

			// The restored site is served again.
			out, err := exec.Command("curl", "-sS", "-H", "Host: "+realDomain, "http://127.0.0.1/").CombinedOutput()
			if err != nil || !regexp.MustCompile(`yedek v1`).Match(out) {
				t.Fatalf("site after restore: %s %v", out, err)
			}

			// A wrong passphrase is refused, not restored as garbage.
			if spec.Target != nil {
				if err := os.RemoveAll(setDir(key)); err != nil {
					t.Fatal(err)
				}
				_, err := m.Restore(ctx, RestoreSpec{Chain: []ChainRef{{Key: key, Name: realDomain, Target: spec.Target}}, Passphrase: "yanlis-parola-123", Parts: []string{"files"}, Site: SiteRef{Domain: realDomain, User: realUser}})
				if err == nil {
					t.Fatal("a wrong passphrase restored the backup")
				}
				verify(t, web)
			}
			if _, err := m.Delete(ctx, DeleteSpec{Key: key, Local: true, Target: spec.Target}); err != nil {
				t.Fatal(err)
			}
			if spec.Target != nil {
				found, _ := m.Scan(ctx, spec.Target)
				if slices.ContainsFunc(found, func(f Found) bool { return f.Descriptor != nil && f.Descriptor.Key == key }) {
					t.Fatalf("deleting left %s on the target", key)
				}
			}
			fmt.Fprintf(os.Stderr, "✓ %s: backed up, destroyed and restored\n", name)
		})
	}
}
