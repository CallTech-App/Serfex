<?php

namespace App\Jobs;

use App\Enums\MailDomainStatus;
use App\Models\MailDomain;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Mail\MailDomains;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Stops serving a domain's mail. The first run keeps the stored mail and
 * DKIM key and queues a second run (purge) for when Undo has expired; that
 * one deletes the mail and the domain's rows, unless mail was turned back
 * on meanwhile.
 */
class DeleteMailDomain implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public MailDomain $domain, public bool $purge = false) {}

    public function handle(AgentClient $agent): void
    {
        $domain = MailDomain::find($this->domain->id);
        if ($domain === null || $domain->status !== MailDomainStatus::Deleting) {
            return;
        }

        try {
            $agent->call('mail.domain.delete', ['domain' => $domain->name, 'remove_files' => $this->purge], config('serfex.agent.job_timeout'));
        } catch (AgentException $e) {
            // Without a mail server there is nothing to stop or delete.
            if ($e->getCode() !== SyncMailDomain::NOT_SET_UP) {
                $domain->update(['last_error' => $e->reason]);
                throw $e;
            }
        }

        if ($this->purge) {
            $domain->delete();

            return;
        }
        self::dispatch($domain, purge: true)->delay(now()->addSeconds(MailDomains::UNDO_SECONDS));
    }
}
