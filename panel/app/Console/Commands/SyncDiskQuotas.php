<?php

namespace App\Console\Commands;

use App\Services\Agent\AgentException;
use App\Services\Customers\DiskQuotas;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs from the scheduler every five minutes: sets each account's disk
 * limit from its customer's usage and limit, and refuses or takes mail
 * again as disks fill up and empty. Quotas that were on and went off
 * (a restart into a kernel without the quota module) are turned on again.
 */
#[Signature('serfex:disk-quota')]
#[Description('Apply customers\' disk limits as file system quotas')]
class SyncDiskQuotas extends Command
{
    public function handle(DiskQuotas $quotas): int
    {
        try {
            if ($quotas->broken() && ! $quotas->enabling() && ($quotas->refreshStatus()['state'] ?? null) === 'off') {
                $this->warn('Disk quotas went off; turning them on again.');
                $quotas->enable();
            } else {
                $quotas->sync();
            }
        } catch (AgentException $e) {
            $this->warn('Agent unavailable: '.($e->reason ?: $e->getMessage()));

            return self::FAILURE;
        }
        $state = $quotas->status()['state'] ?? 'unknown';
        $this->info($quotas->enforcing() ? __('Disk kotaları uygulandı.') : __('Disk kotaları uygulanmıyor (:state).', ['state' => $state]));

        return self::SUCCESS;
    }
}
