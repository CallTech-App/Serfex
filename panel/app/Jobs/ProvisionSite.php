<?php

namespace App\Jobs;

use App\Enums\SiteStatus;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Asks the agent to create or update a site, then records the outcome.
 */
class ProvisionSite implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public Site $site) {}

    public function uniqueId(): string
    {
        return (string) $this->site->id;
    }

    public function handle(AgentClient $agent): void
    {
        $site = $this->site->fresh(['systemUser']);
        if ($site === null || $site->status === SiteStatus::Deleting) {
            return;
        }

        try {
            $result = $agent->call('site.apply', $site->agentSpec(), config('servik.agent.job_timeout'));
        } catch (AgentException $e) {
            $site->update(['status' => SiteStatus::Failed, 'last_error' => $e->getMessage()]);

            return;
        }

        $site->systemUser->update(['uid' => $result['uid'] ?? null, 'home' => $result['home'] ?? null]);
        $site->update([
            'status' => SiteStatus::Active,
            'document_root' => $result['document_root'] ?? null,
            'last_error' => null,
            'provisioned_at' => $site->provisioned_at ?? now(),
        ]);
    }
}
