<?php

namespace App\Jobs;

use App\Enums\MailDomainStatus;
use App\Models\MailDomain;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Mail\MailDns;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Sends a mail domain (mailboxes, forwarders, autoreplies) to the agent so
 * the mail server serves exactly it, then writes the domain's DNS records.
 *
 * Like SyncDnsZone, the job reads the domain when it runs, so quick edits
 * end in one call with the latest state.
 */
class SyncMailDomain implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /** Releases while another sync of the domain runs count as attempts. */
    public int $tries = 20;

    /** Agent error code: the mail server has not been set up yet. */
    public const NOT_SET_UP = -32002;

    public function __construct(public MailDomain $domain) {}

    public function uniqueId(): string
    {
        return (string) $this->domain->id;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('mail-domain-'.$this->domain->id))->releaseAfter(2)->expireAfter(300)];
    }

    public function handle(AgentClient $agent, MailDns $dns): void
    {
        $domain = MailDomain::with('mailboxes', 'forwarders')->find($this->domain->id);
        if ($domain === null || $domain->status === MailDomainStatus::Deleting) {
            return;
        }
        $updatedAt = $domain->updated_at;

        try {
            $result = $agent->call('mail.domain.apply', $domain->agentSpec(), config('serfex.agent.job_timeout'));
        } catch (AgentException $e) {
            $domain->update([
                'status' => MailDomainStatus::Failed,
                'last_error' => $e->getCode() === self::NOT_SET_UP
                    ? __('Posta sunucusu henüz kurulmadı. E-posta sayfasından kurun; alan adı kurulumdan sonra otomatik uygulanır.')
                    : $e->reason,
            ]);

            return;
        }

        $dkim = ['dkim_name' => $result['dkim']['name'] ?? $domain->dkim_name, 'dkim_record' => $result['dkim']['record'] ?? $domain->dkim_record];
        $fresh = MailDomain::find($domain->id);
        if ($fresh === null || $fresh->status === MailDomainStatus::Deleting) {
            return;
        }
        // A newer change queued its own sync; leave the status to it.
        if (! $fresh->updated_at->eq($updatedAt)) {
            $fresh->forceFill($dkim)->saveQuietly();
        } else {
            $fresh->update([...$dkim, 'status' => MailDomainStatus::Active, 'last_error' => null, 'synced_at' => now()]);
        }
        if ($fresh->manage_dns) {
            $dns->apply($fresh);
        }
    }
}
