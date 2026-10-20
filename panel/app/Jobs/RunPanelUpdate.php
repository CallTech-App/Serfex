<?php

namespace App\Jobs;

use App\Models\PanelUpdate;
use App\Services\Updates\Updates;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Takes the panel backup and hands the update (or rollback) to the agent,
 * which goes on by itself: it restarts this worker on the way.
 */
class RunPanelUpdate implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 7300;

    public function __construct(public PanelUpdate $update) {}

    public function handle(Updates $updates): void
    {
        $update = $this->update->fresh('user');
        if ($update === null || $update->status !== 'queued') {
            return;
        }
        $updates->begin($update);
    }

    public function failed(?Throwable $e): void
    {
        $update = $this->update->fresh();
        if ($update !== null && in_array($update->status, ['queued', 'backing_up'], true)) {
            $update->update(['status' => 'failed', 'error' => $e?->getMessage() ?: __('Güncelleme başlatılamadı.'), 'finished_at' => now()]);
        }
    }
}
