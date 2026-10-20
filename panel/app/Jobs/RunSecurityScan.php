<?php

namespace App\Jobs;

use App\Models\SecurityScan;
use App\Services\Security\Scans;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs a security scan. A server scan reads every file of every site, so
 * it runs on the long-job queue the backups use.
 */
class RunSecurityScan implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 43400;

    public function __construct(public SecurityScan $scan)
    {
        $this->onConnection('backups')->onQueue('backups');
    }

    public function handle(Scans $scans): void
    {
        $scan = $this->scan->fresh();
        if ($scan === null || $scan->status !== 'queued') {
            return;
        }
        $scans->run($scan);
    }

    public function failed(?Throwable $e): void
    {
        $scan = $this->scan->fresh();
        if ($scan !== null && $scan->isRunning()) {
            $scan->update(['status' => 'failed', 'error' => $e?->getMessage() ?: __('Tarama yarıda kaldı.'), 'finished_at' => now()]);
        }
    }
}
