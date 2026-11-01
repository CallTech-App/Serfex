<?php

namespace App\Jobs;

use App\Enums\SiteStatus;
use App\Models\Site;
use App\Models\SiteRename;
use App\Services\Sites\SiteRenames;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Changes a site's domain (Alan adını değiştir). With a backup first it
 * runs on the "backups" queue, after the backup or restore in progress.
 */
class RenameSite implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 43400;

    public function __construct(public SiteRename $rename, bool $backup = false)
    {
        if ($backup) {
            $this->onConnection('backups')->onQueue('backups');
        }
    }

    public function handle(SiteRenames $renames): void
    {
        $rename = $this->rename->fresh();
        if ($rename !== null && $rename->status === 'queued') {
            $renames->run($rename);
        }
    }

    /**
     * The worker stopped before the rename finished (a panel update): the
     * agent put the site back if it was moving, so it keeps its old name.
     */
    public function failed(?Throwable $e): void
    {
        $rename = $this->rename->fresh();
        if ($rename === null || ! $rename->isRunning()) {
            return;
        }
        $rename->update(['status' => 'failed', 'error' => __('Alan adı değiştirme yarıda kaldı: :error', ['error' => $e?->getMessage() ?: __('bilinmeyen hata')]), 'finished_at' => now()]);
        $site = Site::find($rename->site_id);
        if ($site !== null && $site->status === SiteStatus::Renaming) {
            $site->update(['status' => SiteStatus::Active]);
        }
    }
}
