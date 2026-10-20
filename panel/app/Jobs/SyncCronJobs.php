<?php

namespace App\Jobs;

use App\Models\CronJob;
use App\Models\Site;
use App\Services\Sites\CronJobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Writes a site's scheduled tasks again: a suspended customer's tasks
 * are paused until the customer is brought back.
 */
class SyncCronJobs implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public int $siteId) {}

    public function handle(CronJobs $jobs): void
    {
        $site = Site::with('systemUser.customer')->find($this->siteId);
        if ($site !== null && CronJob::withTrashed()->where('site_id', $site->id)->exists()) {
            $jobs->apply($site);
        }
    }
}
