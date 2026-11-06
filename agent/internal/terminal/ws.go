package terminal

import (
	"bufio"
	"crypto/sha1"
	"encoding/base64"
	"encoding/binary"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"strings"
	"sync"
	"time"
)

// The small part of WebSocket (RFC 6455) the terminal needs: the server
// side of one connection, text and binary messages, ping and close. The
// agent has no third-party modules but x/crypto and x/sys, and this is
// less code than a dependency.

const (
	opContinuation = 0x0
	opText         = 0x1
	opBinary       = 0x2
	opClose        = 0x8
	opPing         = 0x9
	opPong         = 0xA

	// maxMessage bounds one message from the browser; the page sends a
	// large paste in pieces well below it.
	maxMessage = 1 << 20

	wsGUID = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11"
)

var errTooLarge = errors.New("websocket message too large")

// wsConn is an accepted WebSocket connection. Reads come from one
// goroutine; writes may come from several.
type wsConn struct {
	conn net.Conn
	r    *bufio.Reader
	wmu  sync.Mutex
}

// sameOrigin reports whether a browser's Origin header names the address
// the request was sent to. Requests without Origin (not from a browser)
// pass; the one-time token protects those.
func sameOrigin(r *http.Request) bool {
	origin := r.Header.Get("Origin")
	if origin == "" {
		return true
	}
	u, err := url.Parse(origin)
	if err != nil {
		return false
	}
	return strings.EqualFold(u.Host, r.Host)
}

func headerHas(h http.Header, name, token string) bool {
	for _, v := range h.Values(name) {
		for _, part := range strings.Split(v, ",") {
			if strings.EqualFold(strings.TrimSpace(part), token) {
				return true
			}
		}
	}
	return false
}

// isUpgrade reports whether r asks for a WebSocket.
func isUpgrade(r *http.Request) bool {
	return r.Method == http.MethodGet && headerHas(r.Header, "Connection", "upgrade") &&
		headerHas(r.Header, "Upgrade", "websocket") && r.Header.Get("Sec-WebSocket-Version") == "13" &&
		r.Header.Get("Sec-WebSocket-Key") != ""
}

func acceptKey(key string) string {
	h := sha1.New()
	h.Write([]byte(key + wsGUID))
	return base64.StdEncoding.EncodeToString(h.Sum(nil))
}

// upgrade answers a WebSocket request and takes over its connection.
func upgrade(w http.ResponseWriter, r *http.Request) (*wsConn, error) {
	if !isUpgrade(r) {
		http.Error(w, "websocket expected", http.StatusBadRequest)
		return nil, errors.New("not a websocket request")
	}
	hj, ok := w.(http.Hijacker)
	if !ok {
		http.Error(w, "websocket not supported", http.StatusInternalServerError)
		return nil, errors.New("connection cannot be taken over")
	}
	conn, rw, err := hj.Hijack()
	if err != nil {
		return nil, err
	}
	resp := "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: " +
		acceptKey(r.Header.Get("Sec-WebSocket-Key")) + "\r\n\r\n"
	if _, err := rw.WriteString(resp); err != nil {
		conn.Close()
		return nil, err
	}
	if err := rw.Flush(); err != nil {
		conn.Close()
		return nil, err
	}
	return &wsConn{conn: conn, r: rw.Reader}, nil
}

// readMessage returns the next text or binary message, answering pings
// on the way. A close from the browser returns io.EOF.
func (c *wsConn) readMessage() (op byte, data []byte, err error) {
	var msgOp byte
	var msg []byte
	for {
		fin, op, payload, err := c.readFrame()
		if err != nil {
			return 0, nil, err
		}
		switch op {
		case opPing:
			if err := c.write(opPong, payload); err != nil {
				return 0, nil, err
			}
			continue
		case opPong:
			continue
		case opClose:
			_ = c.write(opClose, closePayload(1000, ""))
			return 0, nil, io.EOF
		case opText, opBinary:
			if msg != nil {
				return 0, nil, errors.New("websocket: new message inside a fragmented one")
			}
			msgOp, msg = op, payload
		case opContinuation:
			if msg == nil {
				return 0, nil, errors.New("websocket: continuation without a message")
			}
			if len(msg)+len(payload) > maxMessage {
				return 0, nil, errTooLarge
			}
			msg = append(msg, payload...)
		default:
			return 0, nil, fmt.Errorf("websocket: unknown opcode %d", op)
		}
		if fin {
			if msg == nil {
				msg = []byte{}
			}
			return msgOp, msg, nil
		}
	}
}

// readFrame reads one frame. Frames from a browser are always masked.
func (c *wsConn) readFrame() (fin bool, op byte, payload []byte, err error) {
	var head [2]byte
	if _, err = io.ReadFull(c.r, head[:]); err != nil {
		return
	}
	fin = head[0]&0x80 != 0
	if head[0]&0x70 != 0 {
		return false, 0, nil, errors.New("websocket: reserved bits set")
	}
	op = head[0] & 0x0F
	masked := head[1]&0x80 != 0
	if !masked {
		return false, 0, nil, errors.New("websocket: unmasked client frame")
	}
	n := uint64(head[1] & 0x7F)
	switch n {
	case 126:
		var ext [2]byte
		if _, err = io.ReadFull(c.r, ext[:]); err != nil {
			return
		}
		n = uint64(binary.BigEndian.Uint16(ext[:]))
	case 127:
		var ext [8]byte
		if _, err = io.ReadFull(c.r, ext[:]); err != nil {
			return
		}
		n = binary.BigEndian.Uint64(ext[:])
	}
	if op >= opClose && (n > 125 || !fin) {
		return false, 0, nil, errors.New("websocket: bad control frame")
	}
	if n > maxMessage {
		return false, 0, nil, errTooLarge
	}
	var mask [4]byte
	if _, err = io.ReadFull(c.r, mask[:]); err != nil {
		return
	}
	payload = make([]byte, n)
	if _, err = io.ReadFull(c.r, payload); err != nil {
		return
	}
	for i := range payload {
		payload[i] ^= mask[i%4]
	}
	return fin, op, payload, nil
}

// write sends one unfragmented frame.
func (c *wsConn) write(op byte, data []byte) error {
	c.wmu.Lock()
	defer c.wmu.Unlock()
	head := make([]byte, 2, 10)
	head[0] = 0x80 | op
	switch n := len(data); {
	case n <= 125:
		head[1] = byte(n)
	case n <= 0xFFFF:
		head[1] = 126
		head = binary.BigEndian.AppendUint16(head, uint16(n))
	default:
		head[1] = 127
		head = binary.BigEndian.AppendUint64(head, uint64(n))
	}
	_ = c.conn.SetWriteDeadline(time.Now().Add(30 * time.Second))
	if _, err := c.conn.Write(append(head, data...)); err != nil {
		return err
	}
	return nil
}

// close sends a close frame with code and reason and drops the
// connection.
func (c *wsConn) close(code uint16, reason string) {
	_ = c.write(opClose, closePayload(code, reason))
	c.conn.Close()
}

func closePayload(code uint16, reason string) []byte {
	if len(reason) > 120 {
		reason = reason[:120]
	}
	return append(binary.BigEndian.AppendUint16(nil, code), reason...)
}
