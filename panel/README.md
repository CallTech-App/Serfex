# Servik panel

The Laravel application behind the Servik web UI. See the
[repository README](../README.md) for setup, and [CONTRIBUTING.md](../CONTRIBUTING.md)
for the checks to run before opening a pull request.

Key pieces:

- `app/Services/Agent/AgentClient.php`: JSON-RPC 2.0 client for `servik-agent`.
- `config/servik.php`: agent socket path and timeout (`SERVIK_AGENT_SOCKET`, `SERVIK_AGENT_TIMEOUT`).
- `resources/views/components/`: Blade layout and Livewire components.
