<?php

namespace App\Jobs;

use App\Models\LaravelBackup;
use App\Services\Laravel\Backups;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Takes a backup of a Laravel application with the agent, which writes
 * its progress to the backup's run log.
 */
class BackupLaravelApp implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 7350;

    public function __construct(public LaravelBackup $backup) {}

    public function handle(Backups $backups): void
    {
        $backup = $this->backup->fresh('app.site.systemUser');
        if ($backup === null || $backup->status !== 'running' || $backup->finished_at !== null) {
            return;
        }
        $backups->take($backup);
    }

    public function failed(?Throwable $e): void
    {
        $backup = $this->backup->fresh();
        if ($backup !== null && $backup->status === 'running') {
            $backup->update(['status' => 'failed', 'error' => $e?->getMessage() ?? 'Yedekleme yarıda kaldı.', 'finished_at' => now()]);
        }
    }
}
