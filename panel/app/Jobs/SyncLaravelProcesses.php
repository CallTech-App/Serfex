<?php

namespace App\Jobs;

use App\Models\LaravelApp;
use App\Services\Agent\AgentException;
use App\Services\Laravel\Processes;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Applies a Laravel application's queue workers, scheduler and processes on
 * the server and records the outcome. With $web, the site's Nginx
 * configuration follows too (Octane's proxy, Reverb's WebSockets).
 */
class SyncLaravelProcesses implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public LaravelApp $app, public bool $web = false) {}

    public function handle(Processes $processes): void
    {
        $app = $this->app->fresh(['site.systemUser', 'site.certificate', 'workers', 'processes']);
        if ($app === null || $app->status->isBusy()) {
            return;
        }
        try {
            $processes->apply($app);
            if ($this->web && $app->current_release !== null) {
                $processes->applyWeb($app);
            }
        } catch (AgentException $e) {
            $app->update(['processes_state' => 'failed', 'processes_error' => $e->reason ?: $e->getMessage()]);

            return;
        }
        $app->update(['processes_state' => 'ok', 'processes_error' => null]);
    }
}
