<?php

namespace App\Jobs;

use App\Enums\ZoneStatus;
use App\Models\DnsZone;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Removes a zone from PowerDNS, then from the panel.
 */
class DeleteDnsZone implements ShouldQueue
{
    use Queueable;

    public int $tries = 20;

    public function __construct(public DnsZone $zone) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('dns-zone-'.$this->zone->id))->releaseAfter(2)->expireAfter(300)];
    }

    public function handle(AgentClient $agent): void
    {
        $zone = DnsZone::find($this->zone->id);
        // Gone already, or the deletion was undone.
        if ($zone === null || $zone->status !== ZoneStatus::Deleting) {
            return;
        }

        try {
            $agent->call('dns.zone.delete', ['zone' => $zone->name], config('serfex.agent.job_timeout'));
        } catch (AgentException $e) {
            // Without a DNS server there is nothing to remove there.
            if ($e->getCode() !== SyncDnsZone::NOT_SET_UP) {
                $zone->update(['status' => ZoneStatus::Failed, 'last_error' => $e->reason]);

                return;
            }
        }

        $zone->delete();
    }
}
