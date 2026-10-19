<?php

namespace App\Services\Monitor;

use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;

/**
 * Postfix's queue: what waits to be delivered, by sender domain.
 */
class MailQueue
{
    public function __construct(private readonly AgentClient $agent) {}

    /**
     * @return array<string, mixed>
     *
     * @throws AgentException
     */
    public function get(): array
    {
        return (array) $this->agent->call('mail.queue', [], 10);
    }

    /**
     * Retries every deferred message now.
     *
     * @throws AgentException
     */
    public function flush(): void
    {
        $this->agent->call('mail.queue.flush', [], 30);
    }

    /**
     * Puts a sender domain's queued messages on hold; returns how many.
     *
     * @throws AgentException
     */
    public function hold(string $domain): int
    {
        return (int) ($this->agent->call('mail.queue.hold', ['domain' => $domain], 30)['messages'] ?? 0);
    }

    /**
     * Releases a sender domain's held messages; returns how many.
     *
     * @throws AgentException
     */
    public function release(string $domain): int
    {
        return (int) ($this->agent->call('mail.queue.release', ['domain' => $domain], 30)['messages'] ?? 0);
    }
}
