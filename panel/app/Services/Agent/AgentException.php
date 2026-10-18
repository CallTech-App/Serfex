<?php

namespace App\Services\Agent;

use RuntimeException;

class AgentException extends RuntimeException
{
    /** JSON-RPC: the request was rejected as invalid. */
    public const INVALID_PARAMS = -32602;

    /** Servik: the request was valid but the server change failed. */
    public const OPERATION_FAILED = -32000;

    /**
     * The agent's own explanation, without the panel's prefix.
     */
    public string $reason = '';

    public static function unavailable(string $socket, string $reason): self
    {
        $e = new AgentUnavailableException("Servik agent is not reachable at [{$socket}]: {$reason}");
        $e->reason = $reason;

        return $e;
    }

    public static function rpcError(string $method, int $code, string $message): self
    {
        $e = new self("Agent call [{$method}] failed: {$message}", $code);
        $e->reason = $message;

        return $e;
    }
}
