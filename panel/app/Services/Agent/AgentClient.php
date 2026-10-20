<?php

namespace App\Services\Agent;

use App\Support\ReadOnlyGuard;
use Illuminate\Foundation\Application;

/**
 * JSON-RPC 2.0 client for servik-agent.
 *
 * One request per connection, newline-delimited JSON on a Unix socket.
 */
class AgentClient
{
    private int $nextId = 1;

    public function __construct(
        private readonly string $socket,
        private readonly float $timeout = 5.0,
    ) {}

    /**
     * Call a method on the agent and return its result.
     *
     * Server changes such as site.apply validate and reload services, so
     * callers pass a longer $timeout than the default.
     *
     * @param  array<string, mixed>  $params
     *
     * @throws AgentException
     */
    public function call(string $method, array $params = [], ?float $timeout = null): mixed
    {
        ReadOnlyGuard::agent($method);
        $id = $this->nextId++;

        // locale: the language of the agent's progress lines and messages.
        // Without the application (the agent integration tests) the agent
        // uses its own default.
        $request = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method];
        if (app() instanceof Application && app()->bound('config')) {
            $request['locale'] = app()->getLocale();
        }
        if ($params !== []) {
            $request['params'] = $params;
        }

        $response = $this->send(json_encode($request, JSON_THROW_ON_ERROR), $timeout ?? $this->timeout);

        if (($response['id'] ?? null) !== $id) {
            throw AgentException::rpcError($method, -32603, 'response id mismatch');
        }

        if (isset($response['error'])) {
            throw AgentException::rpcError(
                $method,
                (int) ($response['error']['code'] ?? -32603),
                (string) ($response['error']['message'] ?? 'unknown error'),
            );
        }

        return $response['result'] ?? null;
    }

    /**
     * Return true when the agent answers a ping.
     */
    public function ping(): bool
    {
        try {
            return $this->call('system.ping') === 'pong';
        } catch (AgentException) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function send(string $payload, float $timeout): array
    {
        $stream = @stream_socket_client(
            'unix://'.$this->socket,
            $errno,
            $errstr,
            $this->timeout,
        );

        if ($stream === false) {
            throw AgentException::unavailable($this->socket, $errstr ?: 'connection failed');
        }

        try {
            stream_set_timeout($stream, (int) ceil($timeout));
            fwrite($stream, $payload."\n");
            $line = fgets($stream);
        } finally {
            fclose($stream);
        }

        if ($line === false) {
            throw AgentException::unavailable($this->socket, 'no response');
        }

        try {
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw AgentException::unavailable($this->socket, 'invalid response');
        }

        return is_array($decoded) ? $decoded : [];
    }
}
