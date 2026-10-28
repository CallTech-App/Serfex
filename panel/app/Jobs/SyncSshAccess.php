<?php

namespace App\Jobs;

use App\Models\SystemUser;
use App\Services\Files\SshAccess;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Applies a Linux account's SSH access again: a suspended customer keeps
 * keys and password but cannot sign in until the customer is brought
 * back.
 */
class SyncSshAccess implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public int $systemUserId) {}

    public function handle(SshAccess $ssh): void
    {
        $account = SystemUser::find($this->systemUserId);
        if ($account !== null && $account->ssh_access !== SshAccess::OFF) {
            $ssh->apply($account);
        }
    }
}
