<?php

namespace App\Jobs;

use App\Enums\DeploymentStatus;
use App\Enums\LaravelAppStatus;
use App\Enums\SiteStatus;
use App\Models\LaravelApp;
use App\Models\LaravelDeployment;
use App\Models\LaravelPreview;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Dns\Zones;
use App\Services\Laravel\EnvFile;
use App\Services\Laravel\Previews;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sets up a preview environment: a site <name>.<domain> under the same
 * account and PHP version, its DNS records when the domain's zone is
 * hosted here (and so its certificate), then a Laravel application on it
 * installed from the preview's branch with the application's deploy key
 * and a .env made from the application's.
 */
class CreateLaravelPreview implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public LaravelPreview $preview) {}

    public function handle(AgentClient $agent, Zones $zones): void
    {
        $preview = $this->preview->fresh(['app.site.systemUser', 'app.site.certificate']);
        if ($preview === null || $preview->status !== 'creating') {
            return;
        }
        $parent = $preview->app;
        try {
            $files = $agent->call('laravel.env.get', $parent->agentApp(), config('servik.agent.job_timeout'));
        } catch (AgentException $e) {
            $this->fail($preview, 'Uygulamanın .env dosyası okunamadı: '.($e->reason ?: $e->getMessage()));

            return;
        }

        $domain = $preview->domain();
        // A retry uses the site made the first time.
        $site = Site::find($preview->site_id) ?? DB::transaction(fn () => $parent->site->systemUser->sites()->create([
            'domain' => $domain,
            'aliases' => [],
            'php_version' => $parent->site->php_version,
            'status' => SiteStatus::Provisioning,
        ]));
        $preview->update(['site_id' => $site->id]);
        // Only a label in the application's zone; a domain whose DNS is
        // elsewhere needs a wildcard record there.
        if ($zones->parentZone($domain) !== null) {
            $zones->forSite($site);
        }
        ProvisionSite::dispatchSync($site);
        $site->refresh()->load('systemUser');
        if ($site->status !== SiteStatus::Active) {
            $this->fail($preview, 'Önizleme sitesi oluşturulamadı: '.$site->last_error);

            return;
        }

        $env = Previews::env($parent, $preview, $site, EnvFile::values((string) ($files['env'] ?? '')));
        $app = LaravelApp::create([
            'site_id' => $site->id,
            'preview_of_id' => $parent->id,
            'path' => LaravelApp::MANAGED_PATH,
            'status' => LaravelAppStatus::Installing,
            'source' => 'git',
            'repository' => $parent->repository,
            'branch' => $preview->branch,
            'frontend' => $parent->frontend,
            'package_manager' => $parent->package_manager,
            'migrate' => true,
            'hooks' => $parent->hooks,
            'health_path' => $parent->health_path,
            'auto_rollback' => false,
            'keep_releases' => 2,
            'scheduler' => false,
            'install_env' => $env,
        ]);
        $preview->update(['preview_app_id' => $app->id, 'status' => 'active']);
        $deployment = LaravelDeployment::create([
            'laravel_app_id' => $app->id,
            'number' => 1,
            'kind' => 'install',
            'status' => DeploymentStatus::Queued,
            'ref' => $preview->branch,
            'triggered_by' => $preview->created_by,
            'run' => 'install-'.Str::lower(Str::random(12)),
            'migrate' => true,
            'frontend' => $parent->frontend,
        ]);
        DeployLaravelApp::dispatch($deployment);
    }

    private function fail(LaravelPreview $preview, string $error): void
    {
        $preview->update(['status' => 'failed', 'error' => $error]);
    }

    public function failed(?Throwable $e): void
    {
        $preview = $this->preview->fresh();
        if ($preview !== null && $preview->status === 'creating') {
            $this->fail($preview, $e?->getMessage() ?? 'Önizleme ortamı kurulamadı.');
        }
    }
}
