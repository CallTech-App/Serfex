<?php

namespace App\Console\Commands;

use App\Services\Queue\RunningJobs;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs from the scheduler every minute: fails the queued jobs whose worker
 * stopped while running them, so what they were doing shows as failed and
 * can be started again (see RunningJobs).
 */
#[Signature('serfex:jobs-recover')]
#[Description('Fail the jobs a stopped queue worker left behind')]
class RecoverJobs extends Command
{
    public function handle(RunningJobs $jobs): int
    {
        $n = $jobs->recover();
        $this->info("{$n} interrupted jobs failed.");

        return self::SUCCESS;
    }
}
