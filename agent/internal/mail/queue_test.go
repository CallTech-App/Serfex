package mail

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"testing"

	"github.com/winchester37/servik/agent/internal/host"
)

const queueFixture = `{"queue_name":"deferred","queue_id":"A1","arrival_time":1760000000,"message_size":900,"sender":"bilgi@kahve.com","recipients":[{"address":"x@dead.example","delay_reason":"host mx.dead.example said: 550 User unknown"}]}
{"queue_name":"deferred","queue_id":"A2","arrival_time":1760000100,"message_size":900,"sender":"bilgi@kahve.com","recipients":[{"address":"y@dead.example","delay_reason":"host mx.dead.example said: 550 User unknown"}]}
{"queue_name":"active","queue_id":"B1","arrival_time":1760000200,"message_size":300,"sender":"satis@magaza.com.tr","recipients":[{"address":"z@gmail.com"}]}
{"queue_name":"hold","queue_id":"C1","arrival_time":1759990000,"message_size":300,"sender":"","recipients":[{"address":"bounce@kahve.com","delay_reason":"held"}]}
`

func TestQueue(t *testing.T) {
	m, sim := newTest(t)
	ctx := context.Background()
	if q, err := m.Queue(ctx); err != nil || q.Installed {
		t.Fatalf("queue without postfix = %+v, %v", q, err)
	}
	if _, err := m.HoldDomain(ctx, "kahve.com"); !errors.Is(err, ErrNotSetUp) {
		t.Fatalf("hold without postfix: %v", err)
	}
	setUp(t, m)
	if err := os.MkdirAll(filepath.Dir(sim.Path(host.SimMailQueue)), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(sim.Path(host.SimMailQueue), []byte(queueFixture), 0o644); err != nil {
		t.Fatal(err)
	}

	q, err := m.Queue(ctx)
	if err != nil {
		t.Fatal(err)
	}
	if !q.Installed || q.Active != 1 || q.Deferred != 2 || q.Hold != 1 || len(q.Messages) != 4 {
		t.Fatalf("queue = %+v", q)
	}
	if q.Messages[0].ID != "C1" {
		t.Errorf("oldest first: %+v", q.Messages[0])
	}
	if d := q.Domains[0]; d.Domain != "kahve.com" || d.Deferred != 2 || d.Reason != "host mx.dead.example said: 550 User unknown" || d.Oldest.Unix() != 1760000000 {
		t.Errorf("first domain = %+v", d)
	}

	if n, err := m.HoldDomain(ctx, "kahve.com"); err != nil || n != 2 {
		t.Fatalf("hold = %d, %v", n, err)
	}
	if !sim.Ran("postsuper -h -") {
		t.Error("postsuper not run")
	}
	q, _ = m.Queue(ctx)
	if q.Hold != 3 || q.Deferred != 0 {
		t.Fatalf("after hold = %+v", q)
	}
	if n, err := m.ReleaseDomain(ctx, "kahve.com"); err != nil || n != 2 {
		t.Fatalf("release = %d, %v", n, err)
	}
	// Bounces (no sender) are addressed by the empty domain.
	if n, err := m.ReleaseDomain(ctx, ""); err != nil || n != 1 {
		t.Fatalf("release bounces = %d, %v", n, err)
	}
	if _, err := m.HoldDomain(ctx, "not a domain"); err == nil {
		t.Error("bad domain accepted")
	}
	if err := m.FlushQueue(ctx); err != nil || !sim.Ran("postqueue -f") {
		t.Fatalf("flush: %v", err)
	}
}
