<?php

namespace App\Jobs;

use App\Enums\ZoneStatus;
use App\Models\DnsZone;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Sends a zone's records to the agent so PowerDNS serves exactly them.
 *
 * The job reads the zone when it runs, so several quick edits end in one
 * call with the latest state. A change made while a sync is running queues
 * another one; two syncs of the same zone never run at the same time.
 */
class SyncDnsZone implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /** Releases while another sync of the zone runs count as attempts. */
    public int $tries = 20;

    /** Agent error code: the DNS server has not been set up yet. */
    public const NOT_SET_UP = -32001;

    public function __construct(public DnsZone $zone) {}

    public function uniqueId(): string
    {
        return (string) $this->zone->id;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('dns-zone-'.$this->zone->id))->releaseAfter(2)->expireAfter(300)];
    }

    public function handle(AgentClient $agent): void
    {
        $zone = DnsZone::with('records')->find($this->zone->id);
        if ($zone === null || $zone->status === ZoneStatus::Deleting) {
            return;
        }
        $updatedAt = $zone->updated_at;

        try {
            $result = $agent->call('dns.zone.apply', $zone->agentSpec(), config('serfex.agent.job_timeout'));
        } catch (AgentException $e) {
            $zone->update([
                'status' => ZoneStatus::Failed,
                'last_error' => $e->getCode() === self::NOT_SET_UP
                    ? __('DNS sunucusu henüz kurulmadı. Alan Adları ve DNS sayfasından kurun; bölge kurulumdan sonra otomatik yayınlanır.')
                    : $e->reason,
            ]);

            return;
        }

        // A newer change queued its own sync; leave the status to it.
        $fresh = DnsZone::find($zone->id);
        if ($fresh === null || ! $fresh->updated_at->eq($updatedAt)) {
            $fresh?->update(['serial' => $result['serial'] ?? $fresh->serial]);

            return;
        }
        $zone->update([
            'status' => ZoneStatus::Active,
            'serial' => $result['serial'] ?? null,
            'last_error' => null,
            'synced_at' => now(),
        ]);
    }
}
