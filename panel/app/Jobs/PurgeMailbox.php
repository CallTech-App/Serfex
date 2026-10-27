<?php

namespace App\Jobs;

use App\Models\Mailbox;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Deletes the stored mail of a removed mailbox once its Undo has expired,
 * then the mailbox itself.
 */
class PurgeMailbox implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public Mailbox $mailbox) {}

    public function handle(AgentClient $agent): void
    {
        $mailbox = Mailbox::onlyTrashed()->with('domain')->find($this->mailbox->id);
        if ($mailbox === null) {
            return;
        }

        try {
            $agent->call('mail.mailbox.purge', ['domain' => $mailbox->domain->name, 'name' => $mailbox->name], config('serfex.agent.job_timeout'));
        } catch (AgentException $e) {
            if ($e->getCode() !== SyncMailDomain::NOT_SET_UP) {
                throw $e;
            }
        }
        $mailbox->forceDelete();
    }

    /**
     * After the last attempt the mailbox goes anyway, so its name can be
     * used again; leftover mail stays on disk where only root can read it.
     */
    public function failed(?Throwable $e): void
    {
        Mailbox::onlyTrashed()->whereKey($this->mailbox->id)->forceDelete();
    }
}
