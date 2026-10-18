<?php

namespace App\Jobs;

use App\Models\LaravelApp;
use App\Services\Agent\AgentException;
use App\Services\Laravel\Processes;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Applies a Laravel application's queue workers and scheduler on the server
 * and records the outcome.
 */
class SyncLaravelProcesses implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public LaravelApp $app) {}

    public function handle(Processes $processes): void
    {
        $app = $this->app->fresh(['site.systemUser', 'workers']);
        if ($app === null || $app->status->isBusy()) {
            return;
        }
        try {
            $processes->apply($app);
        } catch (AgentException $e) {
            $app->update(['processes_state' => 'failed', 'processes_error' => $e->reason ?: $e->getMessage()]);

            return;
        }
        $app->update(['processes_state' => 'ok', 'processes_error' => null]);
    }
}
