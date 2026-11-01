<?php

namespace App\Console\Commands;

use App\Services\Tuning\Tuner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs every minute from the scheduler: applies optimization changes
 * waiting for the maintenance window and watches the ones just applied,
 * rolling them back when the server suffers.
 */
#[Signature('serfex:tuning')]
#[Description('Apply scheduled optimization changes and watch applied ones')]
class WatchTuningChanges extends Command
{
    public function handle(Tuner $tuner): int
    {
        $tuner->tick();

        return self::SUCCESS;
    }
}
