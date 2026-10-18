# servik-agent

The privileged half of Servik. It runs as root under systemd and serves
JSON-RPC 2.0 on a Unix socket (default `/run/servik-agent/agent.sock`, mode
`0660`). Each request and response is one line of JSON.

```sh
go test ./...
CGO_ENABLED=0 go build -ldflags "-X github.com/winchester37/servik/agent/internal/version.Version=dev" \
  -o bin/servik-agent ./cmd/servik-agent
```

Methods so far:

| Method | Result |
|--------|--------|
| `system.ping` | `"pong"` |
| `agent.info` | version, Go version, OS and architecture |

Example:

```sh
echo '{"jsonrpc":"2.0","id":1,"method":"agent.info"}' | sudo socat - UNIX-CONNECT:/run/servik-agent/agent.sock
```
