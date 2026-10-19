<?php

namespace App\Console\Commands;

use App\Services\Agent\AgentException;
use App\Services\Customers\Usage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs from the scheduler: records how much disk each account's home
 * directory takes, for customers' disk quotas.
 */
#[Signature('servik:disk-usage')]
#[Description('Record the disk usage of every account')]
class MeasureDiskUsage extends Command
{
    public function handle(Usage $usage): int
    {
        try {
            $count = $usage->measureDisk();
        } catch (AgentException $e) {
            $this->warn('Agent unavailable: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->info("{$count} hesabın disk kullanımı güncellendi.");

        return self::SUCCESS;
    }
}
