<?php

namespace App\Console\Commands;

use App\Services\Stats\StatsRetention;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs every night: drops statistics past their keeping time and keeps
 * the top values of finished days.
 */
#[Signature('servik:stats-prune')]
#[Description('Drop old statistics and keep the top values of finished days')]
class PruneStats extends Command
{
    public function handle(StatsRetention $retention): int
    {
        $this->info(__(':prune satır temizlendi.', ['prune' => $retention->prune()]));

        return self::SUCCESS;
    }
}
