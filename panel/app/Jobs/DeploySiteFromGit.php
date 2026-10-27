<?php

namespace App\Jobs;

use App\Enums\GitDeploymentStatus;
use App\Models\GitDeployment;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs a Git deploy of a site's folder with the agent, which writes its
 * progress to the deployment's run log, then records the outcome.
 */
class DeploySiteFromGit implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1950;

    public function __construct(public GitDeployment $deployment) {}

    public function handle(AgentClient $agent): void
    {
        $deployment = $this->deployment->fresh(['repository.site.systemUser']);
        if ($deployment === null || $deployment->status !== GitDeploymentStatus::Queued) {
            return;
        }
        $repo = $deployment->repository;
        $deployment->update(['status' => GitDeploymentStatus::Running, 'started_at' => now()]);

        try {
            $result = $agent->call('site.git.deploy', $repo->target() + [
                'run' => $deployment->run,
                'repository' => $repo->repository,
                'branch' => (string) ($deployment->branch ?? $repo->branch),
                'path' => $repo->path,
                'token' => $deployment->kind === 'undo' ? '' : (string) $repo->token,
                'commit' => $deployment->kind === 'rollback' ? (string) $deployment->commit : '',
                'undo' => $deployment->kind === 'undo',
                'commands' => $deployment->kind === 'undo' ? '' : (string) $repo->commands,
            ], config('servik.agent.deploy_timeout'));
        } catch (AgentException $e) {
            $deployment->update(['status' => GitDeploymentStatus::Failed, 'error' => $e->reason ?: $e->getMessage(), 'finished_at' => now()]);

            return;
        }

        $problem = (string) ($result['problem'] ?? '');
        $deployment->update([
            'commit' => ($result['commit'] ?? '') ?: $deployment->commit,
            'previous_commit' => ($result['previous'] ?? '') ?: null,
            'message' => ($result['message'] ?? '') ?: $deployment->message,
            'author' => ($result['author'] ?? '') ?: $deployment->author,
            'changed_files' => $result['changed'] ?? null,
            'saved_files' => $deployment->kind === 'deploy' && ($result['previous'] ?? '') === '' ? (int) ($result['saved'] ?? 0) : null,
            'status' => $problem !== '' ? GitDeploymentStatus::CommandsFailed : GitDeploymentStatus::Succeeded,
            'error' => $problem !== '' ? $problem : null,
            'finished_at' => now(),
        ]);
        $repo->update(['live_commit' => $deployment->kind === 'undo' ? null : ($result['commit'] ?? $repo->live_commit)]);
    }

    public function failed(?Throwable $e): void
    {
        $deployment = $this->deployment->fresh();
        if ($deployment !== null && $deployment->status->isBusy()) {
            $deployment->update(['status' => GitDeploymentStatus::Failed, 'error' => $e?->getMessage() ?? __('Dağıtım yarıda kaldı.'), 'finished_at' => now()]);
        }
    }
}
