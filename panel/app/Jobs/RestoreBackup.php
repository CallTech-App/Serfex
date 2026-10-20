<?php

namespace App\Jobs;

use App\Models\BackupRestore;
use App\Services\Backups\Restores;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Restores a site from a backup archive (after backing up its current
 * state), on the "backups" queue.
 */
class RestoreBackup implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 43400;

    public function __construct(public BackupRestore $restore)
    {
        $this->onConnection('backups')->onQueue('backups');
    }

    public function handle(Restores $restores): void
    {
        $restore = $this->restore->fresh();
        if ($restore === null || $restore->status !== 'queued') {
            return;
        }
        $restores->run($restore);
    }

    public function failed(?Throwable $e): void
    {
        $restore = $this->restore->fresh();
        if ($restore !== null && $restore->isRunning()) {
            $restore->update(['status' => 'failed', 'error' => $e?->getMessage() ?? __('Geri yükleme yarıda kaldı.'), 'finished_at' => now()]);
        }
    }
}
