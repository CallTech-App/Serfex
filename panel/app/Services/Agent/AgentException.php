<?php

namespace App\Services\Agent;

use RuntimeException;

class AgentException extends RuntimeException
{
    /** JSON-RPC: the request was rejected as invalid. */
    public const INVALID_PARAMS = -32602;

    /** Servik: the request was valid but the server change failed. */
    public const OPERATION_FAILED = -32000;

    /** Servik: Nginx or Apache refused the configuration when tested. */
    public const CONFIG_REJECTED = -32010;

    /** Servik: the certificate authority could not be reached; nothing was asked of it. */
    public const CA_UNREACHABLE = -32011;

    /**
     * The agent's own explanation, without the panel's prefix.
     */
    public string $reason = '';

    /**
     * The reason in Turkish for the person who made the change: a web
     * server's refusal with its own words, otherwise the agent's.
     */
    public function forPeople(): string
    {
        if ($this instanceof AgentUnavailableException) {
            return __('Sunucu ajanına ulaşılamıyor. Sunucuda "systemctl status servik-agent" ile kontrol edin.');
        }
        if ($this->getCode() === self::CONFIG_REJECTED) {
            $server = str_starts_with($this->reason, 'apache') ? 'Apache' : 'Nginx';
            $output = preg_replace('/^\w+ rejected the configuration: /', '', $this->reason);

            return __(':server yapılandırmayı kabul etmedi, hiçbir şey değişmedi: :output', ['server' => $server, 'output' => $output]);
        }

        return $this->reason;
    }

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
