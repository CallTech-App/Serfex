package monitor

import (
	"bufio"
	"bytes"
	"context"
	"encoding/binary"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"time"
)

// StatusPath is the pool's pm.status_path (see package site).
const StatusPath = "/serfex-fpm-status"

// FastCGI record types (FastCGI 1.0 specification).
const (
	fcgiBeginRequest = 1
	fcgiEndRequest   = 3
	fcgiParams       = 4
	fcgiStdin        = 5
	fcgiStdout       = 6
	fcgiStderr       = 7
	fcgiResponder    = 1
)

// fetchPoolStatus asks a PHP-FPM status socket for the JSON status page,
// speaking just enough FastCGI for one request.
func fetchPoolStatus(ctx context.Context, socket string) (poolStatus, error) {
	var d net.Dialer
	conn, err := d.DialContext(ctx, "unix", socket)
	if err != nil {
		return poolStatus{}, err
	}
	defer conn.Close()
	deadline, ok := ctx.Deadline()
	if !ok {
		deadline = time.Now().Add(time.Second)
	}
	_ = conn.SetDeadline(deadline)

	var req bytes.Buffer
	writeRecord(&req, fcgiBeginRequest, []byte{0, fcgiResponder, 0, 0, 0, 0, 0, 0})
	var params bytes.Buffer
	for _, kv := range [][2]string{
		{"GATEWAY_INTERFACE", "CGI/1.1"},
		{"REQUEST_METHOD", "GET"},
		{"SCRIPT_NAME", StatusPath},
		{"SCRIPT_FILENAME", StatusPath},
		{"REQUEST_URI", StatusPath + "?json"},
		{"QUERY_STRING", "json"},
		{"SERVER_PROTOCOL", "HTTP/1.1"},
	} {
		writeParam(&params, kv[0], kv[1])
	}
	writeRecord(&req, fcgiParams, params.Bytes())
	writeRecord(&req, fcgiParams, nil)
	writeRecord(&req, fcgiStdin, nil)
	if _, err := conn.Write(req.Bytes()); err != nil {
		return poolStatus{}, err
	}

	r := bufio.NewReader(conn)
	var out bytes.Buffer
	for {
		var h [8]byte
		if _, err := io.ReadFull(r, h[:]); err != nil {
			return poolStatus{}, fmt.Errorf("reading FastCGI response: %w", err)
		}
		n := int(binary.BigEndian.Uint16(h[4:6]))
		pad := int(h[6])
		body := make([]byte, n+pad)
		if _, err := io.ReadFull(r, body); err != nil {
			return poolStatus{}, err
		}
		switch h[1] {
		case fcgiStdout:
			out.Write(body[:n])
		case fcgiEndRequest:
			return parseStatusResponse(out.Bytes())
		}
	}
}

func writeRecord(w *bytes.Buffer, typ byte, content []byte) {
	h := [8]byte{1, typ, 0, 1}
	binary.BigEndian.PutUint16(h[4:6], uint16(len(content)))
	w.Write(h[:])
	w.Write(content)
}

func writeParam(w *bytes.Buffer, k, v string) {
	for _, n := range []int{len(k), len(v)} {
		if n < 128 {
			w.WriteByte(byte(n))
		} else {
			var b [4]byte
			binary.BigEndian.PutUint32(b[:], uint32(n)|1<<31)
			w.Write(b[:])
		}
	}
	w.WriteString(k)
	w.WriteString(v)
}

// parseStatusResponse reads the CGI response: headers, a blank line, JSON.
func parseStatusResponse(b []byte) (poolStatus, error) {
	if i := bytes.Index(b, []byte("\r\n\r\n")); i >= 0 {
		b = b[i+4:]
	} else if i := bytes.Index(b, []byte("\n\n")); i >= 0 {
		b = b[i+2:]
	}
	var st poolStatus
	if err := json.Unmarshal(b, &st); err != nil {
		return poolStatus{}, errors.New("PHP-FPM did not return a status page")
	}
	return st, nil
}
