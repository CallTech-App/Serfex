<?php

namespace App\Jobs;

use App\Services\Agent\AgentException;
use App\Services\Security\Firewall;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Writes the port firewall's rules again from the saved settings. The mail
 * and DNS ready rules follow whether those servers are set up, so the
 * rules must be written again when one of them is: a firewall turned on
 * before the mail server kept ports 25, 465, 587, 110, 995, 143 and 993
 * closed, and mail from other servers never arrived.
 */
class ReapplyFirewall implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    /** A lock whose job never ran stops blocking after ten minutes. */
    public int $uniqueFor = 600;

    public function handle(Firewall $firewall): void
    {
        try {
            $firewall->reapply();
        } catch (AgentException $e) {
            Log::warning('Firewall rules not applied again: '.$e->getMessage());
            $this->release($this->backoff);
        }
    }
}
