<?php

namespace App\Console\Commands;

use App\Services\Updates\Updates;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs from the scheduler: looks for newer Servik releases on the chosen
 * channel and records how finished updates ended. Nothing is installed;
 * the administrator starts an update from Güncellemeler.
 */
#[Signature('servik:update-check')]
#[Description('Check for newer Servik releases')]
class CheckServikUpdates extends Command
{
    public function handle(Updates $updates): int
    {
        $updates->reconcile();
        $status = $updates->check();
        if (is_string($status)) {
            $this->warn($status);

            return self::FAILURE;
        }
        $release = $updates->available($status);
        $this->info($release ? 'Servik '.$release['version'].' hazır.' : 'Servik güncel.');

        return self::SUCCESS;
    }
}
