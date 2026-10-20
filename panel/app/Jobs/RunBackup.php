<?php

namespace App\Jobs;

use App\Models\Backup;
use App\Services\Backups\Backups;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Takes a whole-server or site backup with the agent, which writes its
 * progress to the backup's run log. Runs on the "backups" queue, so long
 * backups do not hold up the panel's other work.
 */
class RunBackup implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 43400;

    public function __construct(public Backup $backup)
    {
        $this->onConnection('backups')->onQueue('backups');
    }

    public function handle(Backups $backups): void
    {
        $backup = $this->backup->fresh('target');
        if ($backup === null || $backup->status !== 'queued') {
            return;
        }
        $backups->take($backup);
    }

    public function failed(?Throwable $e): void
    {
        $backup = $this->backup->fresh();
        if ($backup !== null && $backup->isRunning()) {
            $backup->items()->where('status', 'pending')->update(['status' => 'failed', 'error' => __('Yedekleme yarıda kaldı.')]);
            $backup->update(['status' => 'failed', 'error' => $e?->getMessage() ?? __('Yedekleme yarıda kaldı.'), 'finished_at' => now()]);
        }
    }
}
