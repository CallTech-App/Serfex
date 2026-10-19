<?php

namespace App\Console\Commands;

use App\Enums\SiteStatus;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Services\Files\FileManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs from the scheduler: empties what has been in each site's trash
 * longer than FileManager::TRASH_DAYS, also on sites nobody opens.
 */
#[Signature('servik:files-trash')]
#[Description('Remove old entries from the file manager trash of every site')]
class PurgeFileTrash extends Command
{
    public function handle(FileManager $files): int
    {
        $removed = 0;
        $failed = 0;
        foreach (Site::with('systemUser')->where('status', SiteStatus::Active)->lazyById() as $site) {
            try {
                $removed += (int) ($files->call($site, 'trash.purge', ['days' => FileManager::TRASH_DAYS])['deleted'] ?? 0);
            } catch (AgentException $e) {
                $failed++;
                $this->warn($site->domain.': '.$e->getMessage());
            }
        }
        $this->info("Çöp kutularından {$removed} öğe silindi.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
