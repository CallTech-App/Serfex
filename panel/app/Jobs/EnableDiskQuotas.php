<?php

namespace App\Jobs;

use App\Services\Agent\AgentException;
use App\Services\Customers\DiskQuotas;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Turns disk quotas on for the server (Araçlar ve Ayarlar → Sunucu).
 * Counting every file can take minutes on a full disk, so it runs here
 * and the page follows DiskQuotas::ENABLING.
 */
class EnableDiskQuotas implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function handle(DiskQuotas $quotas): void
    {
        try {
            $quotas->enable();
        } catch (AgentException) {
            // The reason is kept in DiskQuotas::ERROR for the page.
        }
    }
}
