<?php

namespace App\Console\Commands;

use App\Services\Security\Scans;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs from the scheduler every Sunday night: scans every site and the
 * server's settings. --now runs the scan here instead of queueing it.
 */
#[Signature('servik:security-scan {--now : Run the scan in this process}')]
#[Description('Scan every site and the server for security problems')]
class ScheduledSecurityScan extends Command
{
    public function handle(Scans $scans): int
    {
        $scan = $scans->start(null, 'schedule');
        if (is_string($scan)) {
            $this->warn($scan);

            return self::SUCCESS;
        }
        if ($this->option('now')) {
            $scans->run($scan->fresh());
            $scan->refresh();
            $this->info("Tarama bitti: {$scan->files} dosya, ".$scan->findings()->count().' bulgu.');

            return $scan->status === 'failed' ? self::FAILURE : self::SUCCESS;
        }
        $this->info('Tarama kuyruğa alındı.');

        return self::SUCCESS;
    }
}
