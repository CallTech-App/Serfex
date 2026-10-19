<?php

namespace App\Console\Commands;

use App\Models\Backup;
use App\Services\Backups\Backups;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs every five minutes from the scheduler: starts the whole-server
 * backup when it is due, applies retention and removes copies fetched
 * from targets that are no longer needed.
 */
#[Signature('servik:backups')]
#[Description('Take the scheduled whole-server backup and apply retention')]
class ScheduledBackups extends Command
{
    public function handle(Backups $backups): int
    {
        if ($backups->due(now())) {
            $result = $backups->startServer(null, 'scheduled');
            $this->info($result instanceof Backup ? "Backup {$result->key} started." : $result);
        }
        if (! $backups->busy()) {
            $backups->prune();
            $backups->dropCaches();
        }

        return self::SUCCESS;
    }
}
