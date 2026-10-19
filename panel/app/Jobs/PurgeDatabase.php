<?php

namespace App\Jobs;

use App\Models\Database;
use App\Services\Databases\Databases;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Drops a deleted database from the server once its Undo has expired,
 * after a backup when that was asked for.
 */
class PurgeDatabase implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    /** A large database takes a while to dump. */
    public int $timeout = 7300;

    public function __construct(public int $databaseId) {}

    public function handle(Databases $databases): void
    {
        $database = Database::onlyTrashed()->find($this->databaseId);
        if ($database !== null) {
            $databases->purge($database);
        }
    }
}
