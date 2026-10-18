<?php

namespace App\Services\Mail;

use App\Models\MailDomain;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;

/**
 * Asks the agent how much mail each mailbox of a domain stores.
 */
class MailUsage
{
    /** Usage younger than this is not asked for again, in seconds. */
    public const FRESH_SECONDS = 300;

    public function __construct(private readonly AgentClient $agent) {}

    /**
     * Refresh the domain's mailbox usage; false when the agent could not
     * answer.
     */
    public function refresh(MailDomain $domain, bool $onlyStale = false): bool
    {
        $mailboxes = $domain->mailboxes()->get();
        if ($mailboxes->isEmpty()) {
            return true;
        }
        if ($onlyStale && $mailboxes->every(fn ($m) => $m->usage_at?->gt(now()->subSeconds(self::FRESH_SECONDS)))) {
            return true;
        }
        try {
            $result = $this->agent->call('mail.usage', ['domain' => $domain->name]);
        } catch (AgentException) {
            return false;
        }
        foreach ($mailboxes as $mailbox) {
            $mailbox->forceFill([
                'usage_bytes' => (int) ($result['mailboxes'][$mailbox->name] ?? 0),
                'usage_at' => now(),
            ])->saveQuietly();
        }

        return true;
    }
}
