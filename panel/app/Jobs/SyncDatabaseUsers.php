<?php

namespace App\Jobs;

use App\Models\SystemUser;
use App\Services\Databases\Databases;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Applies the database users of an account again: a suspended customer's
 * users keep their passwords but may use no database until the customer
 * is brought back.
 */
class SyncDatabaseUsers implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public int $systemUserId) {}

    public function handle(Databases $databases): void
    {
        $account = SystemUser::find($this->systemUserId);
        if ($account !== null) {
            $databases->applyAccount($account);
        }
    }
}
