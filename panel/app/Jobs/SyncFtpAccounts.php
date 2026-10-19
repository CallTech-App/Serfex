<?php

namespace App\Jobs;

use App\Models\SystemUser;
use App\Services\Files\FtpAccounts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Applies the SFTP accounts of a Linux account again: a suspended
 * customer's accounts keep their passwords but cannot sign in until the
 * customer is brought back.
 */
class SyncFtpAccounts implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public int $systemUserId) {}

    public function handle(FtpAccounts $accounts): void
    {
        $account = SystemUser::find($this->systemUserId);
        if ($account !== null) {
            $accounts->applyAccount($account);
        }
    }
}
