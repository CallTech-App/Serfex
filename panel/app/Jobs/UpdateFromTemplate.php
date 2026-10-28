<?php

namespace App\Jobs;

use App\Enums\DeploymentStatus;
use App\Enums\LaravelAppStatus;
use App\Models\LaravelApp;
use App\Services\Laravel\LaravelApps;
use App\Services\Laravel\Maintenance;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * Updates an application installed from a Git template to the version
 * the template names: visitors see the maintenance page, the new release
 * is deployed (with the template's backup, migrations, hooks and return
 * to the earlier release when it does not come up), then the page goes.
 *
 * Several run one after the other in a chain; with $stopOnFailure the
 * first that fails stops the rest, so a broken version reaches one site.
 */
class UpdateFromTemplate implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 2400;

    public function __construct(public LaravelApp $app, public ?string $triggeredBy = null, public bool $stopOnFailure = false) {}

    public function handle(LaravelApps $apps, Maintenance $maintenance): void
    {
        $app = $this->app->fresh(['site.systemUser', 'template']);
        $template = $app?->template;
        if ($app === null || $template === null || $template->isPackage() || $app->status !== LaravelAppStatus::Active || $app->hasRunningDeployment()) {
            return;
        }
        // What the template says now is what this update brings.
        $app->update([
            'branch' => $template->ref,
            'hooks' => $template->hookScripts(),
            'migrate' => $template->migrate,
            'frontend' => $template->frontend,
            'package_manager' => $template->package_manager ?: $app->package_manager,
            'backup_before_deploy' => $template->update_backup,
            'auto_rollback' => $template->update_rollback,
        ]);

        $down = $template->update_maintenance && ! $app->maintenance_on;
        $allow = $app->maintenance_allow;
        if ($down) {
            // The health check comes from the server itself.
            $maintenance->down($app, 'serfex', [
                'maintenance_secret' => $app->maintenance_secret ?: Str::random(24),
                'maintenance_retry' => $app->maintenance_retry ?: 60,
                'maintenance_allow' => trim($allow."\n127.0.0.1\n::1"),
                'maintenance_html' => $app->maintenance_html,
            ]);
        }

        $deployment = $apps->newDeploy($app, $template->ref, $this->triggeredBy ?? __('Şablon :name', ['name' => $template->name]));
        app()->call([new DeployLaravelApp($deployment), 'handle']);

        if ($down) {
            $app->refresh();
            $maintenance->up($app);
            $app->update(['maintenance_allow' => $allow]);
        }
        $status = $deployment->fresh()?->status;
        if ($this->stopOnFailure && $status !== DeploymentStatus::Succeeded) {
            // The rest of the chain is not run.
            $this->chained = [];
        }
    }
}
