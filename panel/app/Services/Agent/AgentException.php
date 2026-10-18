<?php

namespace App\Services\Agent;

use RuntimeException;

class AgentException extends RuntimeException
{
    public static function unavailable(string $socket, string $reason): self
    {
        return new self("Servik agent is not reachable at [{$socket}]: {$reason}");
    }

    public static function rpcError(string $method, int $code, string $message): self
    {
        return new self("Agent call [{$method}] failed: {$message}", $code);
    }
}
