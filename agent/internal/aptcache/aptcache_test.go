package aptcache

import "testing"

// Taken from Ubuntu 24.04 (2026-10-27).
const policy = `mysql-server:
  Installed: (none)
  Candidate: 8.0.46-0ubuntu0.24.04.4
  Version table:
     8.0.46-0ubuntu0.24.04.4 500
        500 http://archive.ubuntu.com/ubuntu noble-updates/main amd64 Packages
        500 http://security.ubuntu.com/ubuntu noble-security/main amd64 Packages
     8.0.36-2ubuntu3 500
        500 http://archive.ubuntu.com/ubuntu noble/main amd64 Packages
mariadb-server:
  Installed: (none)
  Candidate: 1:10.11.14-0ubuntu0.24.04.1
  Version table:
     1:10.11.14-0ubuntu0.24.04.1 500
        500 http://archive.ubuntu.com/ubuntu noble-updates/universe amd64 Packages
     1:10.11.13-0ubuntu0.24.04.1 500
        500 http://security.ubuntu.com/ubuntu noble-security/universe amd64 Packages
php8.4-fpm:
  Installed: 8.4.13-1+ubuntu24.04.1+deb.sury.org+1
  Candidate: 8.4.14-1+ubuntu24.04.1+deb.sury.org+1
  Version table:
     8.4.14-1+ubuntu24.04.1+deb.sury.org+1 500
        500 https://ppa.launchpadcontent.net/ondrej/php/ubuntu noble/main amd64 Packages
 *** 8.4.13-1+ubuntu24.04.1+deb.sury.org+1 100
        100 /var/lib/dpkg/status
python3-pip:
  Installed: 24.0+dfsg-1ubuntu1.3
  Candidate: 24.0+dfsg-1ubuntu1.3
  Version table:
 *** 24.0+dfsg-1ubuntu1.3 100
        100 /var/lib/dpkg/status
nothing-here:
  Installed: (none)
  Candidate: (none)
  Version table:
`

func TestPolicy(t *testing.T) {
	got := Policy(policy)
	want := map[string]Candidate{
		"mysql-server":   {"8.0.46-0ubuntu0.24.04.4", "archive.ubuntu.com/ubuntu"},
		"mariadb-server": {"1:10.11.14-0ubuntu0.24.04.1", "archive.ubuntu.com/ubuntu"},
		"php8.4-fpm":     {"8.4.14-1+ubuntu24.04.1+deb.sury.org+1", "ppa.launchpadcontent.net/ondrej/php/ubuntu"},
		"python3-pip":    {"24.0+dfsg-1ubuntu1.3", ""},
	}
	if len(got) != len(want) {
		t.Errorf("got %v", got)
	}
	for k, w := range want {
		if got[k] != w {
			t.Errorf("%s: got %+v, want %+v", k, got[k], w)
		}
	}
}

func TestUpstream(t *testing.T) {
	for in, want := range map[string]string{"18.1-1.pgdg24.04+2": "18.1", "1:10.11.14-0ubuntu0.24.04.1": "10.11.14", "2.8.12": "2.8.12"} {
		if got := Upstream(in); got != want {
			t.Errorf("%s: %s", in, got)
		}
	}
}
