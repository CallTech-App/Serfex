<?php

namespace App\Jobs;

use App\Models\SiteMigration;
use App\Services\Migrations\SiteMigrations;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Copies a Plesk subscription from the old server over SSH on the
 * "backups" queue, so it never runs beside a backup, a restore or a move.
 */
class PullSiteMigration implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 43400;

    public function __construct(public SiteMigration $migration)
    {
        $this->onConnection('backups')->onQueue('backups');
    }

    public function handle(SiteMigrations $migrations): void
    {
        $migration = $this->migration->fresh();
        if ($migration === null || $migration->status !== 'pulling') {
            return;
        }
        $migrations->pull($migration);
    }

    public function failed(?Throwable $e): void
    {
        $migration = $this->migration->fresh();
        if ($migration !== null) {
            app(SiteMigrations::class)->failRunning($migration, $e?->getMessage() ?? __('Aktarım yarıda kaldı.'));
        }
    }
}
