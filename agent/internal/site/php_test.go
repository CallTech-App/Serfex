package site

import (
	"context"
	"strings"
	"testing"
)

func TestSitePHPSettings(t *testing.T) {
	m, sim := newTest(t)
	s := spec
	s.PHP = &PHPSpec{
		Values:           map[string]string{"max_input_vars": "5000", "display_errors": "On"},
		AdminValues:      map[string]string{"memory_limit": "512M"},
		DisableFunctions: []string{"exec", "shell_exec"},
		PM:               PMDynamic, MaxChildren: 16, MaxRequests: 1000, RequestTimeout: 120, BodyMB: 128,
	}
	if _, err := m.Apply(context.Background(), s); err != nil {
		t.Fatal(err)
	}
	pool := read(t, sim, "/etc/php/8.4/fpm/pool.d/kahveatolyesi.com.tr.conf")
	for _, want := range []string{
		"pm = dynamic\npm.max_children = 16\npm.start_servers = 4\npm.min_spare_servers = 4\npm.max_spare_servers = 8\n",
		"pm.max_requests = 1000",
		"; servik-max-children = 16",
		"request_terminate_timeout = 120s",
		"php_admin_value[disable_functions] = exec,shell_exec",
		"php_admin_value[memory_limit] = 512M",
		"php_value[display_errors] = On\nphp_value[max_input_vars] = 5000\n",
	} {
		if !strings.Contains(pool, want) {
			t.Errorf("pool lacks %q:\n%s", want, pool)
		}
	}
	vhost := read(t, sim, "/etc/nginx/sites-available/kahveatolyesi.com.tr.conf")
	if !strings.Contains(vhost, "client_max_body_size 128m; # PHP post_max_size") {
		t.Errorf("vhost has no body size of its own:\n%s", vhost)
	}
	if conf := read(t, sim, "/etc/nginx/conf.d/servik-tuning.conf"); !strings.Contains(conf, "client_max_body_size 64m;") {
		t.Errorf("server-wide upload limit missing:\n%s", conf)
	}

	// A temporary limit keeps the spare counts consistent and goes back
	// to the site's own number when lifted.
	if err := m.SetMaxChildren(context.Background(), s.Domain, 2); err != nil {
		t.Fatal(err)
	}
	pool = read(t, sim, "/etc/php/8.4/fpm/pool.d/kahveatolyesi.com.tr.conf")
	if !strings.Contains(pool, "pm.max_children = 2\npm.start_servers = 1\npm.min_spare_servers = 1\npm.max_spare_servers = 1\n") {
		t.Errorf("limited pool:\n%s", pool)
	}
	if err := m.SetMaxChildren(context.Background(), s.Domain, 0); err != nil {
		t.Fatal(err)
	}
	if pool = read(t, sim, "/etc/php/8.4/fpm/pool.d/kahveatolyesi.com.tr.conf"); !strings.Contains(pool, "pm.max_children = 16\n") {
		t.Errorf("limit not lifted:\n%s", pool)
	}

	// Back to the defaults: nothing of the site's own stays.
	if _, err := m.Apply(context.Background(), spec); err != nil {
		t.Fatal(err)
	}
	pool = read(t, sim, "/etc/php/8.4/fpm/pool.d/kahveatolyesi.com.tr.conf")
	if !strings.Contains(pool, "pm = ondemand\npm.max_children = 10\npm.process_idle_timeout") || strings.Contains(pool, "php_value") || strings.Contains(pool, "disable_functions") {
		t.Errorf("default pool:\n%s", pool)
	}
	if vhost := read(t, sim, "/etc/nginx/sites-available/kahveatolyesi.com.tr.conf"); strings.Contains(vhost, "client_max_body_size") {
		t.Errorf("default vhost sets its own body size:\n%s", vhost)
	}
}

func TestSitePHPSettingsAreValidated(t *testing.T) {
	m, _ := newTest(t)
	for _, p := range []*PHPSpec{
		{Values: map[string]string{"open_basedir": "/"}},
		{AdminValues: map[string]string{"disable_functions": ""}},
		{Values: map[string]string{"memory_limit": "1G\nphp_admin_value[open_basedir] = /"}},
		{Values: map[string]string{"auto_prepend_file": "x;y"}},
		{Values: map[string]string{"Bad Key": "1"}},
		{DisableFunctions: []string{"exec;"}},
		{PM: "always"},
		{MaxChildren: 5000},
		{Values: map[string]string{"memory_limit": "1G"}, AdminValues: map[string]string{"memory_limit": "1G"}},
	} {
		s := spec
		s.PHP = p
		var v *ValidationError
		if _, err := m.Apply(context.Background(), s); err == nil || !asValidation(err, &v) {
			t.Errorf("%+v: got %v, want a validation error", p, err)
		}
	}
}

func asValidation(err error, v **ValidationError) bool {
	for err != nil {
		if e, ok := err.(*ValidationError); ok {
			*v = e
			return true
		}
		u, ok := err.(interface{ Unwrap() error })
		if !ok {
			return false
		}
		err = u.Unwrap()
	}
	return false
}
