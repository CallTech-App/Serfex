<?php

namespace App\Jobs;

use App\Enums\DeploymentStatus;
use App\Models\LaravelDeployment;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Puts an earlier release of a Laravel application live again. Database
 * migrations are not reverted.
 */
class RollbackLaravelApp implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public LaravelDeployment $deployment) {}

    public function handle(AgentClient $agent): void
    {
        $deployment = $this->deployment->fresh(['app.site.systemUser']);
        if ($deployment === null || $deployment->status !== DeploymentStatus::Queued) {
            return;
        }
        $app = $deployment->app;
        $deployment->update(['status' => DeploymentStatus::Running, 'started_at' => now()]);
        try {
            $result = $agent->call('laravel.rollback', $app->target() + ['release' => (string) $deployment->release], config('servik.agent.job_timeout'));
        } catch (AgentException $e) {
            $deployment->update(['status' => DeploymentStatus::Failed, 'error' => $e->reason ?: $e->getMessage(), 'finished_at' => now()]);

            return;
        }
        $app->update(['current_release' => $result['current'] ?? $deployment->release]);
        $deployment->update(['status' => DeploymentStatus::Succeeded, 'finished_at' => now()]);
    }
}
