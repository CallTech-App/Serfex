<?php

namespace App\Console\Commands;

use App\Services\Sites\CronJobs;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('servik:cron-history')]
#[Description("Read each scheduled task's last run from the server")]
class CronHistory extends Command
{
    public function handle(CronJobs $jobs): int
    {
        $this->info($jobs->refreshHistory().' görev güncellendi.');

        return self::SUCCESS;
    }
}
