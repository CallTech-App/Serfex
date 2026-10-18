<?php

namespace App\Jobs;

use App\Enums\DeploymentStatus;
use App\Enums\LaravelAppStatus;
use App\Models\LaravelApp;
use App\Models\LaravelDeployment;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Laravel\Processes;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Deploys a release of a Laravel application with the agent, which writes
 * its progress to the deployment's run log, then records the outcome.
 *
 * The first release of an application (its install, or an application
 * found on the site moving to releases) also points the site at
 * laravel/current/public, starts its queue workers and scheduler, and only
 * then checks its health, since the site served something else before.
 */
class DeployLaravelApp implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1950;

    public function __construct(public LaravelDeployment $deployment) {}

    public function handle(AgentClient $agent, Processes $processes): void
    {
        $deployment = $this->deployment->fresh(['app.site.systemUser', 'app.site.certificate', 'app.workers']);
        if ($deployment === null || $deployment->status !== DeploymentStatus::Queued) {
            return;
        }
        $app = $deployment->app;
        if ($app->status === LaravelAppStatus::Removing) {
            $deployment->update(['status' => DeploymentStatus::Failed, 'error' => 'Uygulama kaldırılıyor.', 'finished_at' => now()]);

            return;
        }
        $first = ! $app->isManaged() || $app->current_release === null;
        $deployment->update(['status' => DeploymentStatus::Running, 'started_at' => now()]);

        try {
            $result = $agent->call('laravel.deploy', $this->spec($app, $deployment, $first), config('servik.agent.deploy_timeout'));
        } catch (AgentException $e) {
            $this->fail($app, $deployment, $e->reason ?: $e->getMessage());

            return;
        }

        $health = $result['health'] ?? null;
        $problem = (string) ($result['problem'] ?? '');
        $deployment->fill([
            'commit' => ($result['commit'] ?? '') ?: $deployment->commit,
            'message' => ($result['message'] ?? '') ?: ($app->repository === null ? 'Yeni proje: '.$app->package : null),
            'release' => $result['release'] ?? (string) $deployment->number,
        ]);
        $app->fill([
            'laravel_version' => ($result['laravel'] ?? '') ?: $app->laravel_version,
            'php_requirement' => ($result['php_requirement'] ?? '') ?: $app->php_requirement,
            'package_manager' => ($result['package_manager'] ?? '') ?: $app->package_manager,
            'current_release' => ($result['current'] ?? '') ?: $app->current_release,
        ]);

        if ($first) {
            $problem = $this->goLive($agent, $processes, $app, $health);
        }

        $deployment->fill([
            'health_status' => $health['status'] ?? null,
            'health_ms' => $health['ms'] ?? null,
            'status' => match (true) {
                (bool) ($result['rolled_back'] ?? false) => DeploymentStatus::RolledBack,
                $problem !== '' => DeploymentStatus::Unhealthy,
                default => DeploymentStatus::Succeeded,
            },
            'error' => $problem !== '' ? $problem : null,
            'finished_at' => now(),
        ])->save();
        $app->fill(['last_error' => null, 'install_env' => null])->save();
    }

    /**
     * After the first release: the site serves it, workers and scheduler
     * start, and the health check runs. Returns the problem found, if any.
     *
     * @param  array<string, mixed>|null  $health  set to the check's result
     */
    private function goLive(AgentClient $agent, Processes $processes, LaravelApp $app, ?array &$health): string
    {
        $site = $app->site;
        $app->fill([
            'path' => LaravelApp::MANAGED_PATH,
            'status' => LaravelAppStatus::Active,
            'installed_at' => $app->installed_at ?? now(),
        ])->save();
        $problems = [];
        try {
            $site->update(['web_root' => $app->webRoot()]);
            $result = $agent->call('site.apply', $site->agentSpec(), config('servik.agent.job_timeout'));
            $site->update(['document_root' => $result['document_root'] ?? $site->document_root]);
        } catch (AgentException $e) {
            $problems[] = 'Site yeni sürüme yönlendirilemedi: '.($e->reason ?: $e->getMessage());
        }
        try {
            $processes->apply($app);
            $app->update(['processes_state' => 'ok', 'processes_error' => null]);
        } catch (AgentException $e) {
            $app->update(['processes_state' => 'failed', 'processes_error' => $e->reason ?: $e->getMessage()]);
            $problems[] = 'Kuyruk işçileri veya zamanlayıcı başlatılamadı.';
        }
        if ($problems === []) {
            try {
                $health = $agent->call('laravel.health', ['domain' => $site->domain, 'path' => $app->health_path], config('servik.agent.job_timeout'));
                if (! ($health['ok'] ?? false)) {
                    $problems[] = 'Sağlık kontrolü başarısız: '.(($health['status'] ?? 0) ? 'HTTP '.$health['status'] : ($health['error'] ?? 'yanıt yok'));
                }
            } catch (AgentException $e) {
                $problems[] = 'Sağlık kontrolü yapılamadı: '.($e->reason ?: $e->getMessage());
            }
        }

        return implode('; ', $problems);
    }

    /**
     * @return array<string, mixed>
     */
    private function spec(LaravelApp $app, LaravelDeployment $deployment, bool $first): array
    {
        return $app->target() + [
            'run' => $deployment->run,
            'release' => (string) $deployment->number,
            'source' => $app->repository !== null
                ? ['type' => 'git', 'repository' => $app->repository, 'ref' => (string) ($deployment->ref ?? '')]
                : ['type' => 'create', 'package' => $app->package ?? 'laravel/laravel'],
            'build' => [
                'frontend' => $deployment->frontend,
                'package_manager' => (string) $app->package_manager,
                'migrate' => $deployment->migrate,
            ],
            'hooks' => $app->hookScripts(),
            'env' => (object) ($first ? ($app->install_env ?? []) : []),
            // An application found on the site brings its .env and storage.
            'seed_from' => $app->isManaged() ? '' : $app->path,
            // The first release is checked once the site serves it.
            'health' => $first ? null : ['path' => $app->health_path],
            'rollback' => $app->auto_rollback,
            'keep' => $app->keep_releases,
        ];
    }

    private function fail(LaravelApp $app, LaravelDeployment $deployment, string $error): void
    {
        $deployment->update(['status' => DeploymentStatus::Failed, 'error' => $error, 'finished_at' => now()]);
        if ($deployment->kind === 'install') {
            $app->update(['status' => LaravelAppStatus::Failed, 'last_error' => $error]);
        }
    }

    public function failed(?Throwable $e): void
    {
        $deployment = $this->deployment->fresh('app');
        if ($deployment !== null && $deployment->status->isBusy()) {
            $this->fail($deployment->app, $deployment, $e?->getMessage() ?? 'Dağıtım yarıda kaldı.');
        }
    }
}
