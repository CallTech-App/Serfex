<?php

namespace App\Jobs;

use App\Enums\WordPressOperationStatus;
use App\Models\WordPressInstall;
use App\Models\WordPressOperation;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Databases\Databases;
use App\Services\Sites\WordPressSites;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs a WordPress install, update or return to a restore point with the
 * agent, which writes its progress to the operation's run log, then
 * records the outcome. An install's passwords are in $secrets; the job is
 * stored encrypted.
 */
class RunWordPressOperation implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1950;

    /**
     * @param  array<string, mixed>  $secrets  an install's settings and passwords
     */
    public function __construct(public WordPressOperation $operation, public array $secrets = []) {}

    public function handle(AgentClient $agent, WordPressSites $wordpress): void
    {
        $operation = $this->operation->fresh(['install.site.systemUser']);
        if ($operation === null || $operation->status !== WordPressOperationStatus::Queued) {
            return;
        }
        $install = $operation->install;
        $operation->update(['status' => WordPressOperationStatus::Running, 'started_at' => now()]);
        $spec = $install->target() + ['run' => $operation->run];

        try {
            $result = match ($operation->kind) {
                'install' => $agent->call('wordpress.install', $spec + $this->secrets, config('serfex.agent.deploy_timeout')),
                'update' => $agent->call('wordpress.update', $spec + [
                    'core' => (bool) ($operation->request['core'] ?? false),
                    'plugins' => array_values((array) ($operation->request['plugins'] ?? [])),
                    'themes' => array_values((array) ($operation->request['themes'] ?? [])),
                    'check' => $install->webPath() ?? '/',
                    'host' => $install->site->primaryHost(),
                ], config('serfex.agent.deploy_timeout')),
                'restore' => $agent->call('wordpress.restore', $spec + ['point' => (string) $operation->point], config('serfex.agent.deploy_timeout')),
            };
        } catch (AgentException $e) {
            $this->fail($operation, $e->reason ?: $e->getMessage());

            return;
        }

        $result = (array) $result;
        $problem = (string) ($result['problem'] ?? '');
        $operation->update([
            'status' => match (true) {
                (bool) ($result['rolled_back'] ?? false) => WordPressOperationStatus::RolledBack,
                $problem !== '' => WordPressOperationStatus::Partial,
                default => WordPressOperationStatus::Succeeded,
            },
            'changes' => $result['changes'] ?? null,
            'point' => $operation->kind === 'update' ? (($result['point'] ?? '') ?: null) : $operation->point,
            'error' => $problem !== '' ? $problem : null,
            'finished_at' => now(),
        ]);
        if ($operation->kind === 'install') {
            $install->update(['status' => WordPressInstall::ACTIVE, 'version' => ($result['version'] ?? '') ?: null]);
        }
        if ($operation->kind === 'restore') {
            WordPressOperation::whereKey($operation->request['operation'] ?? 0)->update(['restored_at' => now()]);
        }
        $wordpress->refresh($install->fresh('site.systemUser'));
    }

    public function failed(?Throwable $e): void
    {
        $operation = $this->operation->fresh('install');
        if ($operation !== null && $operation->status->isBusy()) {
            $this->fail($operation, $e?->getMessage() ?? __('İşlem yarıda kaldı.'));
        }
    }

    /**
     * Records a failure. A failed install gives back the database made for
     * it; the agent has already emptied the folder.
     */
    private function fail(WordPressOperation $operation, string $error): void
    {
        $operation->update(['status' => WordPressOperationStatus::Failed, 'error' => $error, 'finished_at' => now()]);
        $install = $operation->install;
        if ($operation->kind !== 'install' || $install === null) {
            return;
        }
        $database = $install->database;
        $install->update(['status' => WordPressInstall::FAILED, 'database_id' => null]);
        if ($database !== null) {
            try {
                // Both leave the server once Undo has expired, as when
                // deleted by hand.
                $user = $database->users()->first();
                app(Databases::class)->delete($database, backup: false);
                if ($user !== null) {
                    app(Databases::class)->deleteUser($user);
                }
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
