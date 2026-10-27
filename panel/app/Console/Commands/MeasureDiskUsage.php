<?php

namespace App\Console\Commands;

use App\Services\Agent\AgentException;
use App\Services\Customers\Usage;
use App\Services\Databases\Databases;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs from the scheduler: records how much disk each account's home
 * directory and each database takes, for customers' disk quotas.
 */
#[Signature('serfex:disk-usage')]
#[Description('Record the disk usage of every account and database')]
class MeasureDiskUsage extends Command
{
    public function handle(Usage $usage, Databases $databases): int
    {
        try {
            $count = $usage->measureDisk();
            $dbs = $databases->measure();
        } catch (AgentException $e) {
            $this->warn('Agent unavailable: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->info(__(':count hesabın ve :dbs veritabanının disk kullanımı güncellendi.', ['count' => $count, 'dbs' => $dbs]));

        return self::SUCCESS;
    }
}
