<?php

namespace App\Jobs;

use App\Enums\LaravelAppStatus;
use App\Models\LaravelApp;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Git\GitAccounts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Removes a Laravel application from its site: its queue workers,
 * scheduler and Redis instance stop, the site serves public_html again,
 * and with $removeFiles the releases, shared .env and storage under
 * laravel/ are deleted. An application found on the site keeps its files
 * where they are. A deploy key and webhook Serfex added to the
 * repository through a connected account are removed from it.
 */
class RemoveLaravelApp implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public LaravelApp $app, public bool $removeFiles = false) {}

    public function handle(AgentClient $agent): void
    {
        $app = $this->app->fresh(['site.systemUser', 'site.certificate']);
        if ($app === null) {
            return;
        }
        $site = $app->site;
        // Preview environments go first: each is a site of its own.
        foreach ($app->previews()->with('site', 'app.site')->get() as $preview) {
            $preview->update(['status' => 'deleting']);
            DeleteLaravelPreview::dispatchSync($preview);
        }
        if ($app->previews()->exists()) {
            $app->update(['status' => LaravelAppStatus::Failed, 'last_error' => __('Önizleme ortamları silinemedi; önce onları Önizleme ortamları bölümünden silin.')]);

            return;
        }
        try {
            $agent->call('laravel.remove', $app->target() + ['remove_files' => $this->removeFiles && $app->isManaged()], config('serfex.agent.job_timeout'));
            if ($app->redis_port !== null) {
                $agent->call('redis.remove', ['domain' => $site->domain], config('serfex.agent.job_timeout'));
            }
            if ($site->web_root !== null) {
                $site->update(['web_root' => null]);
                $result = $agent->call('site.apply', $site->agentSpec(), config('serfex.agent.job_timeout'));
                $site->update(['document_root' => $result['document_root'] ?? $site->document_root]);
            }
        } catch (AgentException $e) {
            $app->update(['status' => LaravelAppStatus::Failed, 'last_error' => $e->reason ?: $e->getMessage()]);

            return;
        }
        app(GitAccounts::class)->unlink($app);
        $app->delete();
        // Without the application the site no longer keeps proc_open.
        if ($site->customer() !== null) {
            ApplySitePhp::dispatch([$site->id]);
        }
    }
}
