<?php

namespace App\Jobs;

use App\Models\LaravelBackup;
use App\Services\Laravel\Backups;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Removes a deleted backup from the server once its Undo window is over.
 */
class PurgeLaravelBackup implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public LaravelBackup $backup) {}

    public function handle(Backups $backups): void
    {
        $backup = $this->backup->fresh('app.site');
        if ($backup !== null && $backup->status === 'deleted') {
            $backups->purge($backup);
        }
    }
}
