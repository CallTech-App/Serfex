<?php

namespace App\Jobs;

use App\Enums\CertificateStatus;
use App\Enums\DeploymentStatus;
use App\Enums\LaravelAppStatus;
use App\Enums\SiteStatus;
use App\Models\LaravelApp;
use App\Models\LaravelDeployment;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Git\GitAccounts;
use App\Services\Git\GitProviderException;
use App\Services\Laravel\AppTemplates;
use App\Services\Laravel\Processes;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Installs an application template on a site once the site is ready: a
 * new site is created first and gets its certificate (so APP_URL starts
 * with https://).
 *
 * A Git template becomes a deploy of its repository (the setup commands
 * run after the first release, see DeployLaravelApp). A package template
 * is downloaded and unpacked into app/, its setup commands run, and the
 * site starts serving app/public with its workers and scheduler.
 */
class InstallFromTemplate implements ShouldQueue
{
    use Queueable;

    /** Waiting for the site counts as attempts: 15 seconds each. */
    public int $tries = 60;

    public int $timeout = 2400;

    /** Attempts spent waiting for a certificate being issued, at most. */
    private const CERTIFICATE_WAIT = 12;

    public function __construct(public LaravelDeployment $deployment) {}

    public function handle(AgentClient $agent, AppTemplates $templates, GitAccounts $accounts, Processes $processes): void
    {
        $deployment = $this->deployment->fresh(['app.site.systemUser', 'app.site.certificate', 'app.site.certificates', 'app.template.gitAccount']);
        if ($deployment === null || $deployment->status !== DeploymentStatus::Queued) {
            return;
        }
        $app = $deployment->app;
        $site = $app->site;
        if ($app->status !== LaravelAppStatus::Installing) {
            return;
        }
        if ($site->status === SiteStatus::Failed || $site->status === SiteStatus::Deleting) {
            $this->fail($app, $deployment, __('Site oluşturulamadı; uygulama kurulmadı.'));

            return;
        }
        if ($site->status !== SiteStatus::Active
            || ($site->pendingCertificate()?->status === CertificateStatus::Issuing && $this->attempts() <= self::CERTIFICATE_WAIT)) {
            $this->release(15);

            return;
        }
        $template = $app->template;
        if ($template === null) {
            $this->fail($app, $deployment, __('Şablon silinmiş; uygulama kurulamadı.'));

            return;
        }

        try {
            $db = $templates->database($app, $template);
        } catch (AgentException $e) {
            $this->fail($app, $deployment, __('Veritabanı oluşturulamadı: :reason', ['reason' => $e->reason ?: $e->getMessage()]));

            return;
        }
        $app->refresh();
        $values = $templates->values($app, $db);

        if (! $template->isPackage()) {
            $this->deployFromGit($agent, $templates, $accounts, $app, $deployment, $db, $values);

            return;
        }
        $this->installPackage($agent, $templates, $processes, $app, $deployment, $values);
    }

    /**
     * @param  array<string, string>|null  $db
     * @param  array<string, string>  $values
     */
    private function deployFromGit(AgentClient $agent, AppTemplates $templates, GitAccounts $accounts, LaravelApp $app, LaravelDeployment $deployment, ?array $db, array $values): void
    {
        $template = $app->template;
        $site = $app->site;
        if ($template->private && $app->deploy_key === null) {
            try {
                $key = (string) ($agent->call('laravel.key', $app->target(), config('serfex.agent.job_timeout'))['public_key'] ?? '');
            } catch (AgentException $e) {
                $this->fail($app, $deployment, __('Dağıtım anahtarı oluşturulamadı: :reason', ['reason' => $e->reason ?: $e->getMessage()]));

                return;
            }
            $app->update(['deploy_key' => $key]);
            if ($template->gitAccount !== null && $template->remote_repository !== null) {
                try {
                    $id = $accounts->addDeployKey($template->gitAccount, $template->remote_repository, $accounts->keyTitle($site->domain), $key);
                    $app->update(['git_account_id' => $template->git_account_id, 'remote_repository' => $template->remote_repository, 'remote_key_id' => $id]);
                } catch (GitProviderException $e) {
                    $this->fail($app, $deployment, __('Dağıtım anahtarı depoya eklenemedi: :reason', ['reason' => $e->getMessage()]));

                    return;
                }
            }
        }
        $app->update(['install_env' => $templates->env($app, $template, $db, $values)]);
        // The deploy runs here, in this job: DeployLaravelApp is the same
        // work as an install from the Laravel page.
        app()->call([new DeployLaravelApp($deployment), 'handle']);
    }

    /**
     * @param  array<string, string>  $values
     */
    private function installPackage(AgentClient $agent, AppTemplates $templates, Processes $processes, LaravelApp $app, LaravelDeployment $deployment, array $values): void
    {
        $template = $app->template;
        $site = $app->site;
        $deployment->update(['status' => DeploymentStatus::Running, 'started_at' => now()]);
        try {
            $download = $templates->packageDownload($template);
        } catch (GitProviderException $e) {
            $this->fail($app, $deployment, __('Sürüm paketi bulunamadı: :reason', ['reason' => $e->getMessage()]));

            return;
        }
        $env = filled($template->env_template) ? $templates->env($app, $template, ($app->template_setup ?? [])['db'] ?? null, $values) : [];
        try {
            $result = $agent->call('laravel.package.install', $app->agentApp() + [
                'run' => $deployment->run,
                'url' => $download['url'],
                'headers' => (object) $download['headers'],
                'sha256' => $download['sha256'],
                'env' => (object) $env,
                'files' => $templates->files($app),
                'version_file' => $template->version_file ?: 'VERSION',
                // A retry replaces what the failed attempt unpacked.
                'replace' => $deployment->number > 1,
            ], config('serfex.agent.deploy_timeout'));
        } catch (AgentException $e) {
            $this->fail($app, $deployment, $e->reason ?: $e->getMessage());

            return;
        }
        $version = ($result['version'] ?? '') ?: $template->latest_version;
        $deployment->update(['ref' => $version]);

        // The setup commands keep the answers until they succeed, so
        // "Tekrar dene" can run them again.
        $setup = $app->template_setup;
        $problem = $templates->runSetup($app, $deployment->run);
        if ($problem !== null) {
            $app->update(['template_setup' => $setup]);
            $this->fail($app, $deployment, $problem);

            return;
        }

        $app->fill([
            'status' => LaravelAppStatus::Active,
            'installed_at' => now(),
            'template_version' => $version,
            'last_error' => null,
        ])->save();
        $problems = [];
        try {
            $site->update(['web_root' => $app->webRoot()]);
            $applied = $agent->call('site.apply', $site->agentSpec(), config('serfex.agent.job_timeout'));
            $site->update(['document_root' => $applied['document_root'] ?? $site->document_root]);
        } catch (AgentException $e) {
            $problems[] = __('Site uygulamaya yönlendirilemedi: :reason', ['reason' => $e->reason ?: $e->getMessage()]);
        }
        try {
            $processes->apply($app->fresh(['site.systemUser', 'workers']));
            $app->update(['processes_state' => 'ok', 'processes_error' => null]);
        } catch (AgentException $e) {
            $app->update(['processes_state' => 'failed', 'processes_error' => $e->reason ?: $e->getMessage()]);
            $problems[] = __('Kuyruk işçileri veya zamanlayıcı başlatılamadı.');
        }
        $health = null;
        if ($problems === []) {
            try {
                $health = $agent->call('laravel.health', ['domain' => $site->domain, 'path' => $app->health_path], config('serfex.agent.job_timeout'));
                if (! ($health['ok'] ?? false)) {
                    $problems[] = __('Sağlık kontrolü başarısız: :reason', ['reason' => ($health['status'] ?? 0) ? 'HTTP '.$health['status'] : ($health['error'] ?? __('yanıt yok'))]);
                }
            } catch (AgentException $e) {
                $problems[] = __('Sağlık kontrolü yapılamadı: :reason', ['reason' => $e->reason ?: $e->getMessage()]);
            }
        }
        $deployment->update([
            'status' => $problems === [] ? DeploymentStatus::Succeeded : DeploymentStatus::Unhealthy,
            'error' => $problems === [] ? null : implode('; ', $problems),
            'health_status' => $health['status'] ?? null,
            'health_ms' => $health['ms'] ?? null,
            'finished_at' => now(),
        ]);
    }

    private function fail(LaravelApp $app, LaravelDeployment $deployment, string $error): void
    {
        $deployment->update(['status' => DeploymentStatus::Failed, 'error' => $error, 'finished_at' => now(), 'started_at' => $deployment->started_at ?? now()]);
        $app->update(['status' => LaravelAppStatus::Failed, 'last_error' => $error]);
    }

    public function failed(?Throwable $e): void
    {
        $deployment = $this->deployment->fresh('app');
        if ($deployment !== null && $deployment->status->isBusy()) {
            $this->fail($deployment->app, $deployment, $e?->getMessage() ?? __('Kurulum yarıda kaldı.'));
        }
    }
}
