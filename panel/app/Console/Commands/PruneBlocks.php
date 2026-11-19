<?php

namespace App\Console\Commands;

use App\Models\IpBlock;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs every night: Güvenlik > IP engelleri > Geçmiş keeps a block for
 * 90 days after it ended (was lifted, or ran out).
 */
#[Signature('serfex:blocks-prune')]
#[Description('Drop IP blocks that ended more than 90 days ago')]
class PruneBlocks extends Command
{
    public function handle(): int
    {
        $before = now()->subDays(IpBlock::KEEP_DAYS);
        $count = IpBlock::query()
            ->where(fn ($q) => $q->where('removed_at', '<', $before)
                ->orWhere(fn ($q) => $q->whereNull('removed_at')->where('expires_at', '<', $before)))
            ->delete();
        $this->info(__(':count eski engel kaydı silindi.', ['count' => $count]));

        return self::SUCCESS;
    }
}
