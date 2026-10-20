<?php

namespace App\Console\Commands;

use App\Services\Stats\ServerHistory;
use App\Services\Stats\StatsRetention;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs every hour: turns the server's minute samples into an hourly row
 * and keeps today's top lists from growing without end (a site with
 * many distinct addresses).
 */
#[Signature('servik:stats-rollup')]
#[Description('Roll the server\'s last hour up and trim today\'s top lists')]
class RollupStats extends Command
{
    public function handle(ServerHistory $history, StatsRetention $retention): int
    {
        $hours = $history->rollup();
        $folded = $retention->fold(now(config('servik.timezone'))->subDay()->toDateString(), 200, 1000);
        $this->info("{$hours} saat eklendi, {$folded} satır birleştirildi.");

        return self::SUCCESS;
    }
}
