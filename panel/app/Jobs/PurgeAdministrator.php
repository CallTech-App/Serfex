<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Settings\Administrators;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Deletes a removed administrator's account once its Undo has expired.
 * What they did stays in İşlem günlüğü under their name.
 */
class PurgeAdministrator implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $userId) {}

    public function handle(): void
    {
        $user = User::where('is_admin', true)->whereNotNull('removed_at')->find($this->userId);
        if ($user !== null && $user->removed_at->lte(now()->subMinutes(Administrators::UNDO_MINUTES)->addSeconds(30))) {
            $user->delete();
        }
    }
}
