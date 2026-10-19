<?php

namespace App\Jobs;

use App\Models\FtpAccount;
use App\Services\Files\FtpAccounts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Removes a deleted SFTP account from the server once its Undo has
 * expired.
 */
class PurgeFtpAccount implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public int $accountId) {}

    public function handle(FtpAccounts $accounts): void
    {
        $account = FtpAccount::onlyTrashed()->find($this->accountId);
        if ($account !== null) {
            $accounts->purge($account);
        }
    }
}
