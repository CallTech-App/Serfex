<?php

namespace App\Jobs;

use App\Enums\SiteStatus;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Removes a site from the server (configuration and files) and then from
 * the panel. The system account goes too when asked and no site uses it.
 */
class DeleteSite implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public Site $site, public bool $removeUser = true) {}

    public function handle(AgentClient $agent): void
    {
        $site = $this->site->fresh(['systemUser']);
        if ($site === null) {
            return;
        }
        $user = $site->systemUser;
        $lastSite = $user->sites()->whereKeyNot($site->id)->doesntExist();

        try {
            $result = $agent->call('site.delete', [
                'domain' => $site->domain,
                'user' => $user->name,
                'remove_files' => true,
                'remove_user' => $this->removeUser && $lastSite,
            ], config('servik.agent.job_timeout'));
        } catch (AgentException $e) {
            $site->update(['status' => SiteStatus::Failed, 'last_error' => $e->getMessage()]);

            return;
        }

        DB::transaction(function () use ($site, $user, $result) {
            $site->delete();
            if ($result['user_removed'] ?? false) {
                $user->delete();
            }
        });
    }
}
