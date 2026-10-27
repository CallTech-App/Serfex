package rpc

import (
	"bufio"
	"context"
	"encoding/json"
	"errors"
	"net"
)

// Call sends one request to the agent listening on socket and decodes the
// result into out (when not nil). An error response comes back as *Error.
// The command line (serfex-agent call) and the updater, which outlives
// the agent it restarts, use it; the panel has its own client.
func Call(ctx context.Context, socket, method string, params, out any) error {
	var d net.Dialer
	conn, err := d.DialContext(ctx, "unix", socket)
	if err != nil {
		return err
	}
	defer conn.Close()
	if dl, ok := ctx.Deadline(); ok {
		_ = conn.SetDeadline(dl)
	}
	go func() {
		<-ctx.Done()
		conn.Close()
	}()
	if params == nil {
		params = struct{}{}
	}
	raw, err := json.Marshal(params)
	if err != nil {
		return err
	}
	req, err := json.Marshal(Request{JSONRPC: "2.0", ID: json.RawMessage("1"), Method: method, Params: raw})
	if err != nil {
		return err
	}
	if _, err := conn.Write(append(req, '\n')); err != nil {
		return err
	}
	sc := bufio.NewScanner(conn)
	sc.Buffer(make([]byte, 0, 64*1024), 64<<20)
	if !sc.Scan() {
		if err := sc.Err(); err != nil {
			return err
		}
		return errors.New("the agent closed the connection without answering")
	}
	var resp struct {
		Result json.RawMessage `json:"result"`
		Error  *Error          `json:"error"`
	}
	if err := json.Unmarshal(sc.Bytes(), &resp); err != nil {
		return err
	}
	if resp.Error != nil {
		return resp.Error
	}
	if out != nil && len(resp.Result) > 0 {
		return json.Unmarshal(resp.Result, out)
	}
	return nil
}
