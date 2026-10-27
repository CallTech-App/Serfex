package host

import (
	"context"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"slices"
	"strconv"
	"strings"
	"sync"
)

// Simulator is a Host that keeps every file under Root and fakes commands.
//
// Account commands (useradd, userdel) edit Root/etc/passwd and
// Root/etc/group. "apt-get install" creates the files Serfex checks for
// in the packages it knows (simPackages) and the accounts they add
// (simAccounts); "sqlite3 FILE" creates FILE. File commands run through runuser (install -d, rm -rf,
// tee) are applied under Root. Everything else (nginx -t, systemctl reload)
// succeeds without doing anything. Every command is recorded.
type Simulator struct {
	Root string
	// Fail, when set, is consulted before each command; a non-nil error
	// makes the command fail with it.
	Fail func(Command) error

	mu       sync.Mutex
	commands []Command
}

// NewSimulator returns a simulator rooted at root, with a minimal system
// (root, www-data and pdns accounts, Nginx and PowerDNS directories and
// PHP-FPM pool directories for phpVersions) created where missing.
func NewSimulator(root string, phpVersions ...string) (*Simulator, error) {
	s := &Simulator{Root: root}
	dirs := []string{"/etc/nginx/sites-available", "/etc/nginx/sites-enabled", "/etc/powerdns", "/etc/systemd/system", "/home", "/run/php", "/var/lib/powerdns", "/var/log"}
	for _, v := range phpVersions {
		dirs = append(dirs, "/etc/php/"+v+"/fpm/pool.d")
	}
	for _, d := range dirs {
		if err := os.MkdirAll(s.Path(d), 0o755); err != nil {
			return nil, err
		}
	}
	for file, seed := range map[string]string{
		"/etc/passwd": "root:x:0:0:root:/root:/bin/bash\nwww-data:x:33:33:www-data:/var/www:/usr/sbin/nologin\npdns:x:103:105:PowerDNS:/var/spool/powerdns:/usr/sbin/nologin\n",
		"/etc/group":  "root:x:0:\nwww-data:x:33:\npdns:x:105:\n",
		// The release; PostgreSQL's archive is listed by its codename.
		"/etc/os-release": "PRETTY_NAME=\"Ubuntu 24.04.3 LTS\"\nNAME=\"Ubuntu\"\nVERSION_ID=\"24.04\"\nVERSION_CODENAME=noble\nID=ubuntu\n",
	} {
		if _, err := os.Stat(s.Path(file)); errors.Is(err, os.ErrNotExist) {
			if err := os.WriteFile(s.Path(file), []byte(seed), 0o644); err != nil {
				return nil, err
			}
		}
	}
	return s, nil
}

// Path maps abs under Root.
func (s *Simulator) Path(abs string) string { return joinRoot(s.Root, abs) }

// Chown is a no-op: the simulator runs unprivileged.
func (s *Simulator) Chown(string, int, int) error { return nil }

// LookupUser reads Root/etc/passwd.
func (s *Simulator) LookupUser(name string) (User, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	return lookupPasswd(s.Path("/etc/passwd"), name)
}

// Commands returns the commands run so far.
func (s *Simulator) Commands() []Command {
	s.mu.Lock()
	defer s.mu.Unlock()
	return append([]Command(nil), s.commands...)
}

// Ran reports whether a command whose rendering starts with prefix was run.
func (s *Simulator) Ran(prefix string) bool {
	for _, c := range s.Commands() {
		if strings.HasPrefix(c.String(), prefix) {
			return true
		}
	}
	return false
}

// Run records the command and simulates its effect.
func (s *Simulator) Run(_ context.Context, c Command) (string, error) {
	s.mu.Lock()
	s.commands = append(s.commands, c)
	s.mu.Unlock()

	if s.Fail != nil {
		if err := s.Fail(c); err != nil {
			if c.Stream != nil {
				_, _ = io.WriteString(c.Stream, err.Error()+"\n")
			}
			return err.Error(), commandError(c, err.Error(), &ExitStatus{Code: 1})
		}
	}

	// Low-priority wrappers (nice -n N, ionice -c N, systemd-run ... --)
	// run the command they wrap.
unwrap:
	for {
		switch {
		case c.Name == "nice" && len(c.Args) > 2 && c.Args[0] == "-n":
			c = Command{Name: c.Args[2], Args: c.Args[3:], Stdin: c.Stdin, Env: c.Env, Stream: c.Stream, Stdout: c.Stdout, Input: c.Input}
			continue
		case c.Name == "ionice" && len(c.Args) > 2 && c.Args[0] == "-c":
			c = Command{Name: c.Args[2], Args: c.Args[3:], Stdin: c.Stdin, Env: c.Env, Stream: c.Stream, Stdout: c.Stdout, Input: c.Input}
			continue
		case c.Name == "systemd-run" && slices.Contains(c.Args, "--"):
			i := slices.Index(c.Args, "--")
			if i+1 < len(c.Args) {
				c = Command{Name: c.Args[i+1], Args: c.Args[i+2:], Stdin: c.Stdin, Env: c.Env, Stream: c.Stream, Stdout: c.Stdout, Input: c.Input}
				continue
			}
		}
		break unwrap
	}

	inner := c
	if c.Name == "runuser" {
		// runuser -u USER -- NAME ARGS...
		if len(c.Args) < 4 || c.Args[0] != "-u" || c.Args[2] != "--" {
			return "", fmt.Errorf("simulator: malformed runuser: %s", c)
		}
		inner = Command{Name: c.Args[3], Args: c.Args[4:], Stdin: c.Stdin, Stdout: c.Stdout, Input: c.Input}
	}
	// env [-C DIR] [NAME=VALUE...] NAME ARGS...
	cwd, env := "/", map[string]string{}
	for _, kv := range c.Env {
		if k, v, ok := strings.Cut(kv, "="); ok {
			env[k] = v
		}
	}
	if inner.Name == "env" {
		args := inner.Args
		for len(args) > 0 {
			if args[0] == "-C" && len(args) > 1 {
				cwd, args = args[1], args[2:]
				continue
			}
			k, v, ok := strings.Cut(args[0], "=")
			if !ok || strings.HasPrefix(args[0], "-") {
				break
			}
			env[k] = v
			args = args[1:]
		}
		if len(args) == 0 {
			return "", fmt.Errorf("simulator: env without a command: %s", c)
		}
		inner = Command{Name: args[0], Args: args[1:], Stdin: inner.Stdin, Stdout: inner.Stdout, Input: inner.Input}
	}

	var (
		out string
		err error
	)
	switch inner.Name {
	case "useradd":
		err = s.useradd(inner.Args)
	case "userdel":
		err = s.userdel(inner.Args)
	case "install":
		err = s.install(inner.Args)
	case "rm":
		err = s.rm(inner.Args)
	case "tee":
		err = s.tee(s.abs(cwd, inner.Args), inner.Stdin)
	case "apt-get":
		if len(inner.Args) > 0 && inner.Args[0] == "-s" {
			out = s.aptDryRun(inner.Args[1:])
		} else {
			err = s.aptGet(inner.Args)
		}
	case "sqlite3":
		// sqlite3 DB < schema: the database file appears.
		if len(inner.Args) == 1 {
			err = os.WriteFile(s.Path(inner.Args[0]), inner.Stdin, 0o600)
		}
	default:
		out, err = s.runTool(inner, cwd, env)
	}
	if c.Stream != nil && out != "" {
		_, _ = io.WriteString(c.Stream, out)
	}
	if err != nil {
		return out + err.Error(), commandError(c, out+err.Error(), &ExitStatus{Code: 1})
	}
	return out, nil
}

// abs resolves relative arguments against the working directory.
func (s *Simulator) abs(cwd string, args []string) []string {
	out := make([]string, len(args))
	for i, a := range args {
		if !strings.HasPrefix(a, "-") && !filepath.IsAbs(a) {
			a = filepath.Join(cwd, a)
		}
		out[i] = a
	}
	return out
}

func (s *Simulator) useradd(args []string) error {
	opts := map[string]string{}
	var name string
	for i := 0; i < len(args); i++ {
		a := args[i]
		switch a {
		case "--home-dir", "--shell", "--comment", "--uid", "--gid", "--groups":
			if i+1 >= len(args) {
				return fmt.Errorf("useradd: %s needs a value", a)
			}
			opts[a] = args[i+1]
			i++
		case "--user-group", "--no-create-home", "--system", "--non-unique":
		default:
			if strings.HasPrefix(a, "-") {
				return fmt.Errorf("useradd: unsupported option %s", a)
			}
			name = a
		}
	}

	s.mu.Lock()
	defer s.mu.Unlock()
	passwd, err := os.ReadFile(s.Path("/etc/passwd"))
	if err != nil {
		return err
	}
	uid := 1000
	for _, line := range strings.Split(string(passwd), "\n") {
		f := strings.Split(line, ":")
		if len(f) != 7 {
			continue
		}
		if f[0] == name {
			return fmt.Errorf("useradd: user '%s' already exists", name)
		}
		if n, _ := strconv.Atoi(f[2]); n >= uid && n < 60000 {
			uid = n + 1
		}
	}
	gid := uid
	if v, ok := opts["--uid"]; ok {
		// --non-unique --uid UID --gid GID: an alias of an existing
		// account (SFTP accounts), without a group of its own.
		uid, _ = strconv.Atoi(v)
		gid, _ = strconv.Atoi(opts["--gid"])
	}
	entry := fmt.Sprintf("%s:x:%d:%d:%s:%s:%s\n", name, uid, gid, opts["--comment"], opts["--home-dir"], opts["--shell"])
	if err := appendFile(s.Path("/etc/passwd"), entry); err != nil {
		return err
	}
	if _, ok := opts["--uid"]; ok {
		return nil
	}
	return appendFile(s.Path("/etc/group"), fmt.Sprintf("%s:x:%d:\n", name, uid))
}

func (s *Simulator) userdel(args []string) error {
	if len(args) == 2 && args[0] == "-f" {
		args = args[1:]
	}
	if len(args) != 1 {
		return fmt.Errorf("userdel: expected one user, got %v", args)
	}
	name := args[0]
	s.mu.Lock()
	defer s.mu.Unlock()
	found := false
	for _, file := range []string{"/etc/passwd", "/etc/group"} {
		data, err := os.ReadFile(s.Path(file))
		if err != nil {
			return err
		}
		var keep []string
		for _, line := range strings.Split(strings.TrimRight(string(data), "\n"), "\n") {
			if strings.HasPrefix(line, name+":") {
				found = true
				continue
			}
			keep = append(keep, line)
		}
		if err := os.WriteFile(s.Path(file), []byte(strings.Join(keep, "\n")+"\n"), 0o644); err != nil {
			return err
		}
	}
	if !found {
		return fmt.Errorf("userdel: user '%s' does not exist", name)
	}
	return nil
}

// install -d -m MODE DIR...
func (s *Simulator) install(args []string) error {
	if len(args) < 4 || args[0] != "-d" || args[1] != "-m" {
		return fmt.Errorf("install: unsupported arguments %v", args)
	}
	mode, err := strconv.ParseUint(args[2], 8, 32)
	if err != nil {
		return fmt.Errorf("install: bad mode %q", args[2])
	}
	for _, d := range args[3:] {
		if err := os.MkdirAll(s.Path(d), 0o755); err != nil {
			return err
		}
		if err := os.Chmod(s.Path(d), os.FileMode(mode)); err != nil {
			return err
		}
	}
	return nil
}

// rm -rf --one-file-system PATH...
func (s *Simulator) rm(args []string) error {
	if len(args) > 1 && args[0] == "-f" {
		for _, p := range args[1:] {
			if err := os.Remove(s.Path(p)); err != nil && !errors.Is(err, os.ErrNotExist) {
				return err
			}
		}
		return nil
	}
	if len(args) < 3 || args[0] != "-rf" || args[1] != "--one-file-system" {
		return fmt.Errorf("rm: unsupported arguments %v", args)
	}
	for _, p := range args[2:] {
		if !filepath.IsAbs(p) {
			return fmt.Errorf("rm: relative path %q", p)
		}
		if err := os.RemoveAll(s.Path(p)); err != nil {
			return err
		}
	}
	return nil
}

// ExitStatus is the error of a simulated command that failed.
type ExitStatus struct{ Code int }

func (e *ExitStatus) Error() string { return fmt.Sprintf("exit status %d", e.Code) }

// tee PATH (stdin is written to PATH)
func (s *Simulator) tee(args []string, stdin []byte) error {
	if len(args) != 1 {
		return fmt.Errorf("tee: expected one path, got %v", args)
	}
	return os.WriteFile(s.Path(args[0]), stdin, 0o644)
}

func appendFile(path, data string) error {
	f, err := os.OpenFile(path, os.O_APPEND|os.O_WRONLY, 0o644)
	if err != nil {
		return err
	}
	if _, err := f.WriteString(data); err != nil {
		f.Close()
		return err
	}
	return f.Close()
}

// simPackages are the files that mark a package as installed.
var simPackages = map[string][]string{
	"pdns-server":                   {"/usr/sbin/pdns_server"},
	"pdns-backend-lmdb":             {"/usr/lib/x86_64-linux-gnu/pdns/liblmdbbackend.so"},
	"postfix":                       {"/usr/sbin/postfix", "/etc/postfix/master.cf"},
	"dovecot-core":                  {"/usr/sbin/dovecot", "/etc/dovecot/dovecot.conf"},
	"dovecot-imapd":                 {"/usr/lib/dovecot/imap"},
	"dovecot-pop3d":                 {"/usr/lib/dovecot/pop3"},
	"dovecot-lmtpd":                 {"/usr/lib/dovecot/lmtp"},
	"dovecot-sieve":                 {"/usr/bin/sievec"},
	"dovecot-managesieved":          {"/usr/lib/dovecot/managesieve"},
	"rspamd":                        {"/usr/bin/rspamd", "/etc/rspamd/rspamd.conf"},
	"redis-server":                  {"/usr/bin/redis-server", "/etc/redis/redis.conf"},
	"roundcube-core":                {"/usr/share/roundcube/index.php", "/usr/share/roundcube/SQL/sqlite.initial.sql", "/etc/roundcube/defaults.inc.php", "/etc/roundcube/mimetypes.php", "/var/lib/roundcube/plugins/.keep"},
	"roundcube-sqlite3":             {"/etc/php/8.3/mods-available/pdo_sqlite.ini"},
	"roundcube-plugins":             {"/usr/share/roundcube/plugins/managesieve/managesieve.php"},
	"sqlite3":                       {"/usr/bin/sqlite3"},
	"git":                           {"/usr/bin/git"},
	"unzip":                         {"/usr/bin/unzip"},
	"mysql-server":                  {"/usr/sbin/mysqld", "/etc/mysql/mysql.conf.d/mysqld.cnf"},
	"mariadb-server":                {"/usr/sbin/mariadbd", "/usr/sbin/mysqld", "/etc/mysql/mariadb.conf.d/50-server.cnf"},
	"postgresql":                    {"/usr/lib/postgresql/16/bin/postgres", "/etc/postgresql/16/main/postgresql.conf", "/etc/postgresql/16/main/conf.d/.keep"},
	"postgresql-common":             {"/usr/bin/pg_createcluster", "/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc"},
	"nginx":                         {"/usr/sbin/nginx"},
	"php-fpm":                       {"/usr/sbin/php-fpm8.3"},
	"php-cgi":                       {"/usr/bin/php-cgi8.3", "/etc/php/8.3/cgi/php.ini"},
	"php-mbstring":                  {"/etc/php/8.3/mods-available/mbstring.ini"},
	"php-xml":                       {"/etc/php/8.3/mods-available/xml.ini"},
	"php-mysql":                     {"/etc/php/8.3/mods-available/mysqli.ini"},
	"php-pgsql":                     {"/etc/php/8.3/mods-available/pgsql.ini"},
	"phpmyadmin":                    {"/usr/share/phpmyadmin/index.php", "/etc/phpmyadmin/conf.d/.keep"},
	"adminer":                       {"/usr/share/adminer/adminer.php"},
	"apache2":                       {"/usr/sbin/apache2", "/etc/apache2/apache2.conf", "/etc/apache2/sites-available/000-default.conf", "/etc/apache2/sites-enabled/.keep", "/etc/apache2/conf-available/.keep"},
	"memcached":                     {"/usr/bin/memcached", "/etc/memcached.conf"},
	"supervisor":                    {"/usr/bin/supervisord", "/etc/supervisor/supervisord.conf"},
	"fail2ban":                      {"/usr/bin/fail2ban-server", "/etc/fail2ban/jail.conf", "/etc/fail2ban/jail.d/defaults-debian.conf"},
	"clamav-daemon":                 {"/usr/sbin/clamd", "/etc/clamav/clamd.conf"},
	"clamav":                        {"/usr/bin/clamscan"},
	"clamav-freshclam":              {"/usr/bin/freshclam"},
	"python3-pip":                   {"/usr/bin/pip3"},
	"python3-venv":                  {"/usr/lib/python3/dist-packages/venv.keep"},
	"modsecurity-crs":               {"/usr/share/modsecurity-crs/rules/REQUEST-901-INITIALIZATION.conf", "/etc/modsecurity/crs/crs-setup.conf"},
	"libnginx-mod-http-modsecurity": {"/usr/lib/nginx/modules/ngx_http_modsecurity_module.so", "/etc/nginx/modsecurity.conf", "/etc/nginx/modules-enabled/50-mod-http-modsecurity.conf"},
}

// simAccounts are the system accounts packages create, as passwd lines.
var simAccounts = map[string][]string{
	"postfix":        {"postfix:x:104:110::/var/spool/postfix:/usr/sbin/nologin"},
	"dovecot-core":   {"dovecot:x:105:111:Dovecot mail server:/usr/lib/dovecot:/usr/sbin/nologin", "dovenull:x:106:112:Dovecot login user:/nonexistent:/usr/sbin/nologin"},
	"rspamd":         {"_rspamd:x:107:113:rspamd:/var/lib/rspamd:/usr/sbin/nologin"},
	"redis-server":   {"redis:x:108:114::/var/lib/redis:/usr/sbin/nologin"},
	"mysql-server":   {"mysql:x:109:115:MySQL Server:/nonexistent:/bin/false"},
	"mariadb-server": {"mysql:x:109:115:MariaDB Server:/nonexistent:/bin/false"},
	"postgresql":     {"postgres:x:110:116:PostgreSQL administrator:/var/lib/postgresql:/bin/bash"},
}

// apt-get install [-OPTIONS] PACKAGE...; apt-get purge|remove PATTERN...
// deletes the marker files of the matching packages.
func (s *Simulator) aptGet(args []string) error {
	if len(args) > 0 && (args[0] == "purge" || args[0] == "remove") {
		for _, a := range args[1:] {
			if strings.HasPrefix(a, "-") {
				continue
			}
			if m := simPGServer.FindStringSubmatch(a); m != nil {
				_ = os.Remove(s.Path("/usr/lib/postgresql/" + m[1] + "/bin/postgres"))
				continue
			}
			if v, ok := strings.CutSuffix(a, "-*"); ok && phpBinary.MatchString(v) {
				ver := strings.TrimPrefix(v, "php")
				_ = os.Remove(s.Path("/usr/sbin/php-fpm" + ver))
				_ = os.Remove(s.Path("/usr/bin/" + v))
				_ = os.RemoveAll(s.Path("/etc/php/" + ver))
				continue
			}
			for name, files := range simPackages {
				if ok, _ := filepath.Match(a, name); !ok {
					continue
				}
				for _, f := range files {
					_ = os.Remove(s.Path(f))
				}
			}
		}
		return nil
	}
	if len(args) == 0 || args[0] != "install" {
		return nil
	}
	for _, a := range args[1:] {
		if err := s.addAccounts(simAccounts[a]); err != nil {
			return err
		}
		files := simPackages[a]
		if m := simPGServer.FindStringSubmatch(a); m != nil {
			// postgresql-N: the binaries; clusters are made apart.
			files = []string{"/usr/lib/postgresql/" + m[1] + "/bin/postgres", "/usr/bin/pg_createcluster"}
			if err := s.addAccounts(simAccounts["postgresql"]); err != nil {
				return err
			}
		}
		if v, ok := strings.CutSuffix(a, "-cli"); ok && phpBinary.MatchString(v) {
			files = []string{"/usr/bin/" + v}
		}
		if v, ok := strings.CutSuffix(a, "-fpm"); ok && phpBinary.MatchString(v) {
			ver := strings.TrimPrefix(v, "php")
			files = []string{"/usr/sbin/php-fpm" + ver, "/etc/php/" + ver + "/fpm/pool.d/www.conf"}
		}
		if v, ext, ok := strings.Cut(a, "-"); ok && phpBinary.MatchString(v) && ext != "cli" && ext != "fpm" {
			if ext == "mysql" {
				ext = "mysqli"
			}
			files = []string{"/etc/php/" + strings.TrimPrefix(v, "php") + "/mods-available/" + ext + ".ini"}
			if ext == "sqlite3" {
				files = append(files, "/etc/php/"+strings.TrimPrefix(v, "php")+"/mods-available/pdo_sqlite.ini")
			}
		}
		for _, f := range files {
			if err := os.MkdirAll(filepath.Dir(s.Path(f)), 0o755); err != nil {
				return err
			}
			if err := os.WriteFile(s.Path(f), nil, 0o755); err != nil {
				return err
			}
		}
	}
	return nil
}

// simDefaultPHP is the PHP version Ubuntu's php packages (php-fpm,
// php-cgi, the ones Roundcube and phpMyAdmin depend on) point at.
const simDefaultPHP = "8.3"

// apt-get -s purge|remove PATTERN... lists ("Purg NAME") the installed
// packages a purge would remove; purging the default PHP version takes
// the software that depends on Ubuntu's php packages along.
func (s *Simulator) aptDryRun(args []string) string {
	if len(args) == 0 || (args[0] != "purge" && args[0] != "remove") {
		return ""
	}
	var b strings.Builder
	for _, a := range args[1:] {
		if v, ok := strings.CutSuffix(a, "-*"); ok && phpBinary.MatchString(v) {
			ver := strings.TrimPrefix(v, "php")
			if _, err := os.Stat(s.Path("/usr/sbin/php-fpm" + ver)); err == nil {
				b.WriteString("Purg " + v + "-fpm\n")
			}
			if ver == simDefaultPHP {
				for _, dep := range []string{"roundcube-core", "phpmyadmin", "adminer"} {
					if s.PackageInstalled(dep) {
						b.WriteString("Purg " + dep + "\n")
					}
				}
			}
			continue
		}
		for name := range simPackages {
			if ok, _ := filepath.Match(a, name); ok && s.PackageInstalled(name) {
				b.WriteString("Purg " + name + "\n")
			}
		}
	}
	return b.String()
}

// addAccounts adds passwd lines (and a group of the same name and id)
// for accounts that do not exist yet.
func (s *Simulator) addAccounts(lines []string) error {
	s.mu.Lock()
	defer s.mu.Unlock()
	for _, line := range lines {
		f := strings.Split(line, ":")
		if _, err := lookupPasswd(s.Path("/etc/passwd"), f[0]); err == nil {
			continue
		}
		if err := appendFile(s.Path("/etc/passwd"), line+"\n"); err != nil {
			return err
		}
		if err := appendFile(s.Path("/etc/group"), fmt.Sprintf("%s:x:%s:\n", f[0], f[3])); err != nil {
			return err
		}
	}
	return nil
}

// PackageInstalled reports whether a simulated package's files are there.
// Packages the simulator does not know count as installed once
// "apt-get install" named them.
func (s *Simulator) PackageInstalled(name string) bool {
	files := simPackages[name]
	if v, ok := strings.CutSuffix(name, "-fpm"); ok && phpBinary.MatchString(v) {
		files = []string{"/usr/sbin/php-fpm" + strings.TrimPrefix(v, "php")}
	}
	if len(files) == 0 {
		for _, c := range s.Commands() {
			if c.Name == "apt-get" && len(c.Args) > 0 && c.Args[0] == "install" {
				for _, a := range c.Args[1:] {
					if a == name {
						return true
					}
				}
			}
		}
		return false
	}
	for _, f := range files {
		if _, err := os.Stat(s.Path(f)); err != nil {
			return false
		}
	}
	return true
}
