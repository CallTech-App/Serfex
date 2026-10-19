<?php

namespace App\Jobs;

use App\Models\Backup;
use App\Services\Backups\Backups;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Removes a deleted backup from the server and its target once its Undo
 * window is over.
 */
class PurgeBackup implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public Backup $backup) {}

    public function handle(Backups $backups): void
    {
        $backup = $this->backup->fresh('target');
        if ($backup !== null && $backup->status === 'deleted') {
            $backups->purge($backup);
        }
    }
}
