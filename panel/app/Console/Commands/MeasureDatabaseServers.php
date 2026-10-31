<?php

namespace App\Console\Commands;

use App\Services\Tuning\Optimization;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs every hour from the scheduler: measures the database servers for
 * the optimization suggestions (Hizmetler → Optimizasyon) and renews
 * Serfex AI's notes when they changed.
 */
#[Signature('serfex:tuning-measure')]
#[Description('Measure the database servers for optimization suggestions')]
class MeasureDatabaseServers extends Command
{
    public function handle(Optimization $optimization): int
    {
        foreach ($optimization->measureAll() as $unit => $result) {
            $this->line("{$unit}: {$result}");
        }

        return self::SUCCESS;
    }
}
