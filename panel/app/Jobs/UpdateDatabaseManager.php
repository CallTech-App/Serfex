<?php

namespace App\Jobs;

use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Databases\DatabaseServers;
use App\Services\Settings\PanelSettings;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Moves the web database tools (phpMyAdmin, Adminer) to the releases the
 * agent pins: db.manager.setup downloads them, checks that Serfex's web
 * server serves them and keeps the ones before when they do not work. A
 * Serfex release that pins newer ones dispatches it once after the update
 * (a migration); the reason a tool stayed behind shows on Database
 * servers.
 */
class UpdateDatabaseManager implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    /** Downloading and checking the tools takes a few minutes at most. */
    public int $timeout = 900;

    /** A lock whose job never ran stops blocking after half an hour. */
    public int $uniqueFor = 1800;

    public function handle(AgentClient $agent, DatabaseServers $servers): void
    {
        $status = $servers->status();
        if ($status === null) {
            // The agent is restarting.
            $this->release($this->backoff);

            return;
        }
        if (! ($status['manager']['configured'] ?? false)) {
            return;
        }
        try {
            $result = (array) $agent->call('db.manager.setup', app(PanelSettings::class)->managerSetupParams(), 840);
        } catch (AgentException $e) {
            Log::warning('Database tools not moved to their new releases: '.$e->getMessage());
            $this->release($this->backoff);

            return;
        }
        if (($result['note'] ?? '') !== '') {
            Log::warning('Database tools: '.$result['note']);
        }
    }
}
