// Package rpc implements a minimal JSON-RPC 2.0 server over a Unix socket.
//
// Each connection carries newline-delimited JSON requests and responses.
// The panel (unprivileged PHP) is the only intended client; access is
// controlled by the socket's file mode and group ownership.
package rpc

import (
	"bufio"
	"context"
	"encoding/json"
	"errors"
	"log/slog"
	"net"
	"sync"

	"github.com/calltech-app/serfex/agent/internal/i18n"
)

// Standard JSON-RPC 2.0 error codes.
const (
	CodeParseError     = -32700
	CodeInvalidRequest = -32600
	CodeMethodNotFound = -32601
	CodeInvalidParams  = -32602
	CodeInternalError  = -32603

	// CodeOperationFailed is Servik's server error: the request was valid
	// but the server change failed. The message says why.
	CodeOperationFailed = -32000
)

// maxRequestBytes bounds a single request line: uploads arrive in
// base64 chunks of a few megabytes.
const maxRequestBytes = 16 << 20

// Request is a JSON-RPC 2.0 request.
type Request struct {
	JSONRPC string          `json:"jsonrpc"`
	ID      json.RawMessage `json:"id,omitempty"`
	Method  string          `json:"method"`
	Params  json.RawMessage `json:"params,omitempty"`
	// Locale is Servik's addition: the language of the panel user the
	// call is for, for messages written for people (see i18n).
	Locale string `json:"locale,omitempty"`
}

// Response is a JSON-RPC 2.0 response.
type Response struct {
	JSONRPC string          `json:"jsonrpc"`
	ID      json.RawMessage `json:"id"`
	Result  any             `json:"result,omitempty"`
	Error   *Error          `json:"error,omitempty"`
}

// Error is a JSON-RPC 2.0 error object.
type Error struct {
	Code    int    `json:"code"`
	Message string `json:"message"`
}

func (e *Error) Error() string { return e.Message }

// HandlerFunc handles one method call. Returning an *Error sets its code;
// any other error is reported as an internal error.
type HandlerFunc func(ctx context.Context, params json.RawMessage) (any, error)

// Server dispatches JSON-RPC requests to registered handlers.
type Server struct {
	mu       sync.RWMutex
	handlers map[string]HandlerFunc
	log      *slog.Logger
}

// NewServer returns a server with no methods registered.
func NewServer(log *slog.Logger) *Server {
	if log == nil {
		log = slog.Default()
	}
	return &Server{handlers: map[string]HandlerFunc{}, log: log}
}

// Handle registers a handler for method.
func (s *Server) Handle(method string, h HandlerFunc) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.handlers[method] = h
}

// Serve accepts connections until ctx is cancelled or the listener fails.
func (s *Server) Serve(ctx context.Context, ln net.Listener) error {
	go func() {
		<-ctx.Done()
		ln.Close()
	}()
	var wg sync.WaitGroup
	defer wg.Wait()
	for {
		conn, err := ln.Accept()
		if err != nil {
			if ctx.Err() != nil || errors.Is(err, net.ErrClosed) {
				return nil
			}
			return err
		}
		wg.Add(1)
		go func() {
			defer wg.Done()
			s.ServeConn(ctx, conn)
		}()
	}
}

// ServeConn handles requests on a single connection until it closes.
func (s *Server) ServeConn(ctx context.Context, conn net.Conn) {
	defer conn.Close()
	go func() {
		<-ctx.Done()
		conn.Close()
	}()

	scanner := bufio.NewScanner(conn)
	scanner.Buffer(make([]byte, 0, 64*1024), maxRequestBytes)
	enc := json.NewEncoder(conn)

	for scanner.Scan() {
		resp, ok := s.dispatch(ctx, scanner.Bytes())
		if !ok {
			continue // notification: no response
		}
		if err := enc.Encode(resp); err != nil {
			s.log.Warn("write response", "err", err)
			return
		}
	}
	if err := scanner.Err(); err != nil {
		_ = enc.Encode(errorResponse(nil, CodeInvalidRequest, "request too large"))
	}
}

// dispatch decodes and runs one request. ok is false for notifications.
func (s *Server) dispatch(ctx context.Context, line []byte) (resp Response, ok bool) {
	var req Request
	if err := json.Unmarshal(line, &req); err != nil {
		return errorResponse(nil, CodeParseError, "parse error"), true
	}
	if req.JSONRPC != "2.0" || req.Method == "" {
		return errorResponse(req.ID, CodeInvalidRequest, "invalid request"), true
	}
	isNotification := len(req.ID) == 0

	s.mu.RLock()
	h, found := s.handlers[req.Method]
	s.mu.RUnlock()
	if !found {
		return errorResponse(req.ID, CodeMethodNotFound, "method not found"), !isNotification
	}

	result, err := h(i18n.WithLocale(ctx, req.Locale), req.Params)
	if err != nil {
		var rpcErr *Error
		if errors.As(err, &rpcErr) {
			return errorResponse(req.ID, rpcErr.Code, rpcErr.Message), !isNotification
		}
		s.log.Error("handler failed", "method", req.Method, "err", err)
		return errorResponse(req.ID, CodeInternalError, "internal error"), !isNotification
	}
	return Response{JSONRPC: "2.0", ID: req.ID, Result: result}, !isNotification
}

func errorResponse(id json.RawMessage, code int, msg string) Response {
	if len(id) == 0 {
		id = json.RawMessage("null")
	}
	return Response{JSONRPC: "2.0", ID: id, Error: &Error{Code: code, Message: msg}}
}
