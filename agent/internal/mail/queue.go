package mail

import (
	"context"
	"encoding/json"
	"sort"
	"strings"
	"time"

	"github.com/winchester37/servik/agent/internal/host"
	"github.com/winchester37/servik/agent/internal/hostname"
)

// Queue is Postfix's mail queue: messages waiting to be delivered.
type Queue struct {
	Installed bool `json:"installed"`
	// Active are being delivered, Deferred wait for a retry after a
	// failed attempt, Hold were put on hold and wait for a release.
	Active   int `json:"active"`
	Deferred int `json:"deferred"`
	Hold     int `json:"hold"`
	// Domains groups the messages by the sender's domain, biggest first.
	Domains []QueueDomain `json:"domains"`
	// Messages are the oldest messages (at most 200).
	Messages []QueueMessage `json:"messages"`
}

// QueueDomain is the part of the queue one sender domain sent.
type QueueDomain struct {
	Domain   string    `json:"domain"`
	Active   int       `json:"active"`
	Deferred int       `json:"deferred"`
	Hold     int       `json:"hold"`
	Oldest   time.Time `json:"oldest"`
	// Reason is the most common delivery problem.
	Reason string `json:"reason"`
}

// QueueMessage is one queued message.
type QueueMessage struct {
	ID         string    `json:"id"`
	Queue      string    `json:"queue"`
	Sender     string    `json:"sender"`
	Recipients []string  `json:"recipients"`
	Arrived    time.Time `json:"arrived"`
	Size       int       `json:"size"`
	Reason     string    `json:"reason"`
}

type postqueueEntry struct {
	Queue      string `json:"queue_name"`
	ID         string `json:"queue_id"`
	Arrival    int64  `json:"arrival_time"`
	Size       int    `json:"message_size"`
	Sender     string `json:"sender"`
	Recipients []struct {
		Address string `json:"address"`
		Reason  string `json:"delay_reason"`
	} `json:"recipients"`
}

func (m *Manager) postfixInstalled() bool { return m.exists("/usr/sbin/postfix") }

// readQueue lists the queue with "postqueue -j".
func (m *Manager) readQueue(ctx context.Context) ([]postqueueEntry, error) {
	out, err := m.h.Run(ctx, host.Command{Name: "postqueue", Args: []string{"-j"}})
	if err != nil {
		return nil, &OperationError{err}
	}
	var list []postqueueEntry
	for _, line := range strings.Split(out, "\n") {
		line = strings.TrimSpace(line)
		if !strings.HasPrefix(line, "{") {
			continue
		}
		var e postqueueEntry
		if json.Unmarshal([]byte(line), &e) == nil && e.ID != "" {
			list = append(list, e)
		}
	}
	return list, nil
}

func senderDomain(sender string) string {
	_, d, ok := strings.Cut(sender, "@")
	if !ok || sender == "" {
		// Bounces have an empty sender.
		return ""
	}
	return strings.ToLower(d)
}

// Queue returns the mail queue.
func (m *Manager) Queue(ctx context.Context) (Queue, error) {
	q := Queue{Domains: []QueueDomain{}, Messages: []QueueMessage{}}
	if !m.postfixInstalled() {
		return q, nil
	}
	q.Installed = true
	list, err := m.readQueue(ctx)
	if err != nil {
		return q, err
	}
	sort.Slice(list, func(i, j int) bool { return list[i].Arrival < list[j].Arrival })
	domains := map[string]*QueueDomain{}
	reasons := map[string]map[string]int{}
	for _, e := range list {
		d := senderDomain(e.Sender)
		qd := domains[d]
		if qd == nil {
			qd = &QueueDomain{Domain: d, Oldest: time.Unix(e.Arrival, 0).UTC()}
			domains[d] = qd
			reasons[d] = map[string]int{}
		}
		msg := QueueMessage{ID: e.ID, Queue: e.Queue, Sender: e.Sender, Recipients: []string{}, Arrived: time.Unix(e.Arrival, 0).UTC(), Size: e.Size}
		for _, r := range e.Recipients {
			msg.Recipients = append(msg.Recipients, r.Address)
			if r.Reason != "" && msg.Reason == "" {
				msg.Reason = r.Reason
			}
		}
		if msg.Reason != "" {
			reasons[d][shortReason(msg.Reason)]++
		}
		switch e.Queue {
		case "active", "incoming", "maildrop":
			q.Active++
			qd.Active++
		case "hold":
			q.Hold++
			qd.Hold++
		default:
			q.Deferred++
			qd.Deferred++
		}
		if len(q.Messages) < 200 {
			q.Messages = append(q.Messages, msg)
		}
	}
	for d, qd := range domains {
		best := 0
		for r, n := range reasons[d] {
			if n > best || (n == best && r < qd.Reason) {
				qd.Reason, best = r, n
			}
		}
		q.Domains = append(q.Domains, *qd)
	}
	sort.Slice(q.Domains, func(i, j int) bool {
		a, b := q.Domains[i], q.Domains[j]
		if ta, tb := a.Active+a.Deferred+a.Hold, b.Active+b.Deferred+b.Hold; ta != tb {
			return ta > tb
		}
		return a.Domain < b.Domain
	})
	return q, nil
}

// shortReason keeps the first sentence of a delivery problem.
func shortReason(r string) string {
	r = strings.TrimSpace(r)
	if len(r) > 160 {
		r = r[:160] + "…"
	}
	return r
}

// FlushQueue asks Postfix to try every deferred message again now.
func (m *Manager) FlushQueue(ctx context.Context) error {
	if !m.postfixInstalled() {
		return ErrNotSetUp
	}
	if _, err := m.h.Run(ctx, host.Command{Name: "postqueue", Args: []string{"-f"}}); err != nil {
		return &OperationError{err}
	}
	return nil
}

// HoldDomain puts the queued messages of a sender domain on hold, so a
// site that sends spam stops sending; ReleaseDomain lets them go. A
// message Postfix is delivering right now is left to finish its attempt.
// An empty domain means bounces (no sender). Both return how many
// messages changed.
func (m *Manager) HoldDomain(ctx context.Context, domain string) (int, error) {
	return m.moveDomain(ctx, domain, true)
}

// ReleaseDomain releases a sender domain's held messages.
func (m *Manager) ReleaseDomain(ctx context.Context, domain string) (int, error) {
	return m.moveDomain(ctx, domain, false)
}

func (m *Manager) moveDomain(ctx context.Context, domain string, hold bool) (int, error) {
	domain = strings.ToLower(strings.TrimSpace(domain))
	if domain != "" {
		if err := hostname.Validate(domain); err != nil {
			return 0, invalid("%s", err.Error())
		}
	}
	if !m.postfixInstalled() {
		return 0, ErrNotSetUp
	}
	list, err := m.readQueue(ctx)
	if err != nil {
		return 0, err
	}
	var ids []string
	for _, e := range list {
		if senderDomain(e.Sender) != domain || (e.Queue == "hold") != !hold || (hold && e.Queue == "active") {
			continue
		}
		ids = append(ids, e.ID)
	}
	if len(ids) == 0 {
		return 0, nil
	}
	flag := "-H"
	if hold {
		flag = "-h"
	}
	// "-" reads the queue IDs from standard input.
	if _, err := m.h.Run(ctx, host.Command{Name: "postsuper", Args: []string{flag, "-"}, Stdin: []byte(strings.Join(ids, "\n") + "\n")}); err != nil {
		return 0, &OperationError{err}
	}
	return len(ids), nil
}
