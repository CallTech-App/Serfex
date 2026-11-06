package edge

import (
	"context"
	"errors"
	"os"
	"strings"
	"testing"
	"time"

	"github.com/calltech-app/serfex/agent/internal/cloudflare"
	"github.com/calltech-app/serfex/agent/internal/firewall"
	"github.com/calltech-app/serfex/agent/internal/host"
)

func newTest(t *testing.T) (*Manager, *firewall.Manager, *host.Simulator) {
	t.Helper()
	sim, err := host.NewSimulator(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	fw := firewall.NewManager(sim)
	m := NewManager(sim, fw)
	fw.Changed = func(ctx context.Context) {
		if err := m.Sync(ctx); err != nil {
			t.Errorf("sync: %v", err)
		}
	}
	return m, fw, sim
}

func read(t *testing.T, sim *host.Simulator, abs string) string {
	t.Helper()
	b, err := os.ReadFile(sim.Path(abs))
	if err != nil {
		t.Fatal(err)
	}
	return string(b)
}

func reloads(sim *host.Simulator) int {
	n := 0
	for _, c := range sim.Commands() {
		if c.Name == "systemctl" && len(c.Args) == 2 && c.Args[0] == "reload" && c.Args[1] == "nginx.service" {
			n++
		}
	}
	return n
}

func TestApplyWritesBuiltinRangesAndReloadsOnce(t *testing.T) {
	m, _, sim := newTest(t)
	ctx := context.Background()
	st, err := m.Apply(ctx, Spec{})
	if err != nil {
		t.Fatal(err)
	}
	if !st.Nginx || !st.Changed || st.Ranges != len(cloudflare.Builtin) {
		t.Fatalf("status %+v", st)
	}
	conf := read(t, sim, RealIPConf)
	for _, want := range []string{"set_real_ip_from 173.245.48.0/20;", "set_real_ip_from 2606:4700::/32;", "real_ip_header CF-Connecting-IP;"} {
		if !strings.Contains(conf, want) {
			t.Fatalf("missing %q:\n%s", want, conf)
		}
	}
	if reloads(sim) != 1 {
		t.Fatalf("reloads %d", reloads(sim))
	}
	// Nothing changed: no second reload.
	if st, err := m.Apply(ctx, Spec{}); err != nil || st.Changed {
		t.Fatalf("second apply %+v %v", st, err)
	}
	if reloads(sim) != 1 {
		t.Fatalf("reloads %d", reloads(sim))
	}
}

func TestApplySavesNewRanges(t *testing.T) {
	m, _, sim := newTest(t)
	ctx := context.Background()
	if _, err := m.Apply(ctx, Spec{Ranges: []string{"198.51.100.0/24", "2001:db8::/32", "198.51.100.0/24"}}); err != nil {
		t.Fatal(err)
	}
	conf := read(t, sim, RealIPConf)
	if strings.Count(conf, "set_real_ip_from") != 2 || strings.Contains(conf, "173.245.48.0") {
		t.Fatalf("conf:\n%s", conf)
	}
	if got := cloudflare.Ranges(sim); len(got) != 2 {
		t.Fatalf("ranges in force %v", got)
	}
	for _, bad := range [][]string{{"10.0.0.0/4"}, {"not-a-range"}, {"::/8"}} {
		var v *ValidationError
		if _, err := m.Apply(ctx, Spec{Ranges: bad}); !errors.As(err, &v) {
			t.Fatalf("%v accepted: %v", bad, err)
		}
	}
}

func TestBlocksAreMirroredAndExpire(t *testing.T) {
	m, fw, sim := newTest(t)
	ctx := context.Background()
	now := time.Now().UTC().Truncate(time.Second)
	fw.Now = func() time.Time { return now }
	m.Now = func() time.Time { return now }
	if _, err := fw.Block(ctx, firewall.Spec{Address: "45.155.205.233", Seconds: 600, Reason: "wp-login"}); err != nil {
		t.Fatal(err)
	}
	if _, err := fw.Block(ctx, firewall.Spec{Address: "2001:db8:1::/48"}); err != nil {
		t.Fatal(err)
	}
	conf := read(t, sim, BlockedConf)
	if !strings.Contains(conf, "deny 45.155.205.233;") || !strings.Contains(conf, "deny 2001:db8:1::/48;") {
		t.Fatalf("blocked:\n%s", conf)
	}
	if _, err := fw.Unblock(ctx, "2001:db8:1::/48"); err != nil {
		t.Fatal(err)
	}
	if conf := read(t, sim, BlockedConf); strings.Contains(conf, "2001:db8") {
		t.Fatalf("unblocked still denied:\n%s", conf)
	}
	// Ten minutes later the timed block has run out.
	now = now.Add(11 * time.Minute)
	if err := m.Sync(ctx); err != nil {
		t.Fatal(err)
	}
	if conf := read(t, sim, BlockedConf); strings.Contains(conf, "deny") && strings.Contains(conf, "45.155") {
		t.Fatalf("expired block still denied:\n%s", conf)
	}
}

func TestCloudflareRangesCannotBeBlocked(t *testing.T) {
	_, fw, _ := newTest(t)
	for _, a := range []string{"104.16.1.1", "2606:4700::6810:1", "172.64.0.0/16"} {
		if _, err := fw.Block(context.Background(), firewall.Spec{Address: a}); err == nil || !strings.Contains(err.Error(), "Cloudflare") {
			t.Fatalf("%s: %v", a, err)
		}
	}
}

func TestNginxRefusalRestoresFiles(t *testing.T) {
	m, _, sim := newTest(t)
	ctx := context.Background()
	if _, err := m.Apply(ctx, Spec{}); err != nil {
		t.Fatal(err)
	}
	before := read(t, sim, RealIPConf)
	sim.Fail = func(c host.Command) error {
		if c.Name == "nginx" {
			return errors.New("unknown directive \"real_ip_header\"")
		}
		return nil
	}
	_, err := m.Apply(ctx, Spec{Ranges: []string{"198.51.100.0/24"}})
	var v *ValidationError
	if !errors.As(err, &v) || !strings.Contains(err.Error(), "real_ip_header") {
		t.Fatalf("err = %v", err)
	}
	if read(t, sim, RealIPConf) != before {
		t.Fatal("configuration not restored")
	}
	if len(cloudflare.Ranges(sim)) != len(cloudflare.Builtin) {
		t.Fatal("ranges file not restored")
	}
}
