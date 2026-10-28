<?php

namespace App\Jobs;

use App\Enums\SiteStatus;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Customers\DiskQuotas;
use App\Services\Ssl\Certificates;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Asks the agent to create or update a site (with HTTPS when it has a
 * certificate), then records the outcome.
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

    public function handle(AgentClient $agent, Certificates $certificates): void
    {
        $site = $this->site->fresh(['systemUser', 'certificate']);
        if ($site === null || $site->status === SiteStatus::Deleting) {
            return;
        }

        try {
            $result = $agent->call('site.apply', $site->agentSpec(), config('serfex.agent.job_timeout'));
        } catch (AgentException $e) {
            $site->update(['status' => SiteStatus::Failed, 'last_error' => $e->getMessage()]);

            return;
        }

        $firstTime = $site->provisioned_at === null;
        $site->systemUser->update(['uid' => $result['uid'] ?? null, 'home' => $result['home'] ?? null]);
        $site->update([
            'status' => SiteStatus::Active,
            'document_root' => $result['document_root'] ?? null,
            'last_error' => null,
            'provisioned_at' => $site->provisioned_at ?? now(),
        ]);

        // Scheduled tasks follow the site's PHP version and its owner's
        // suspension.
        if ($site->cronJobs()->exists()) {
            SyncCronJobs::dispatch($site->id);
        }

        // A customer's new account gets its disk limit at once rather than
        // at the next serfex:disk-quota.
        $customer = $site->systemUser->customer;
        if ($firstTime && $customer !== null) {
            try {
                app(DiskQuotas::class)->sync($customer);
            } catch (AgentException) {
                // serfex:disk-quota tries again within five minutes.
            }
        }

        if ($firstTime) {
            // Like AutoSSL: every new site gets a certificate once its DNS
            // points here.
            $certificates->autoIssue($site);
        }
    }
}
