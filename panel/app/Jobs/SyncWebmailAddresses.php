<?php

namespace App\Jobs;

use App\Services\Agent\AgentClient;
use App\Services\Mail\WebmailAddresses;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Serves mail domains' own webmail addresses whose certificates are in
 * place (and no others) and requests the missing certificates; queued
 * after anything that changes them.
 */
class SyncWebmailAddresses implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function uniqueId(): string
    {
        return 'webmail-addresses';
    }

    public function handle(WebmailAddresses $addresses, AgentClient $agent): void
    {
        $addresses->sync($agent);
    }
}
