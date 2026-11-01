<?php

namespace App\Jobs;

use App\Services\Agent\AgentException;
use App\Services\Mail\MailSenders;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Sends which domains each site's system user may send mail as (see
 * MailSenders) after sites, their names or mail domains change. It reads
 * them when it runs, so quick edits end in one call. serfex:mail-senders
 * repeats it every hour in case a call failed.
 */
class SyncMailSenders implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * A lock whose job never ran (a queue cleared during an update) stops
     * blocking new syncs after ten minutes, not a day.
     */
    public int $uniqueFor = 600;

    public function handle(MailSenders $senders): void
    {
        try {
            $senders->sync();
        } catch (AgentException $e) {
            // The hourly run tries again.
            Log::warning('Mail sender limits not applied: '.$e->getMessage());
        }
    }
}
