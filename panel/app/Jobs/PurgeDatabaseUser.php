<?php

namespace App\Jobs;

use App\Models\DatabaseUser;
use App\Services\Databases\Databases;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Removes a deleted database user from the server once its Undo has
 * expired.
 */
class PurgeDatabaseUser implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public int $userId) {}

    public function handle(Databases $databases): void
    {
        $user = DatabaseUser::onlyTrashed()->find($this->userId);
        if ($user !== null) {
            $databases->purgeUser($user);
        }
    }
}
