<?php

namespace App\Console\Commands;

use App\Models\LaravelApp;
use App\Models\LaravelBackup;
use App\Services\Laravel\Backups;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs every five minutes from the scheduler: starts the daily or weekly
 * backup of each Laravel application that is due.
 */
#[Signature('servik:laravel-backups')]
#[Description('Take scheduled backups of Laravel applications')]
class ScheduledLaravelBackups extends Command
{
    public function handle(Backups $backups): int
    {
        $started = 0;
        LaravelApp::with('site.systemUser')->where('backup_schedule', '!=', 'off')->whereNull('preview_of_id')
            ->each(function (LaravelApp $app) use ($backups, &$started) {
                if ($backups->due($app, now())) {
                    $backups->create($app, array_keys(LaravelBackup::PARTS), 'scheduled');
                    $started++;
                }
            });
        $this->info("{$started} backup(s) started.");

        return self::SUCCESS;
    }
}
