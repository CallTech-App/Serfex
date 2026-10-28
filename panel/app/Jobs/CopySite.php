<?php

namespace App\Jobs;

use App\Models\SiteCopy;
use App\Services\Sites\SiteCopies;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Makes a staging copy of a site, brings it up to date from the live
 * site, or publishes it (SiteCopies). Like backups it runs on the
 * "backups" queue, after the backup or restore in progress, since the
 * agent copies one site at a time.
 */
class CopySite implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 43400;

    public function __construct(public SiteCopy $copy)
    {
        $this->onConnection('backups')->onQueue('backups');
    }

    public function handle(SiteCopies $copies): void
    {
        $copy = $this->copy->fresh();
        if ($copy === null || $copy->status !== 'queued') {
            return;
        }
        $copies->run($copy);
    }

    public function failed(?Throwable $e): void
    {
        $copy = $this->copy->fresh();
        if ($copy !== null && $copy->isBusy()) {
            $copy->update(['status' => 'failed', 'error' => $e?->getMessage(), 'finished_at' => now()]);
        }
    }
}
