<?php

namespace App\Jobs;

use App\Enums\ZoneStatus;
use App\Models\DnsZone;
use App\Models\Setting;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Installs and starts PowerDNS through the agent, then publishes every
 * zone that could not be published without it.
 *
 * Progress is kept in the "dns.server" setting: state installing, ready or
 * failed, with the error or the PowerDNS version.
 */
class SetupDnsServer implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Installing packages can take a while on a slow mirror. */
    public int $timeout = 900;

    public function handle(AgentClient $agent): void
    {
        Setting::put('dns.server', ['state' => 'installing', 'at' => now()->toIso8601String()]);

        try {
            $result = $agent->call('dns.setup', [], 600);
        } catch (AgentException $e) {
            Setting::put('dns.server', ['state' => 'failed', 'error' => $e->reason, 'at' => now()->toIso8601String()]);

            return;
        }

        Setting::put('dns.server', ['state' => 'ready', 'version' => $result['version'] ?? null, 'at' => now()->toIso8601String()]);

        DnsZone::whereIn('status', [ZoneStatus::Failed, ZoneStatus::Syncing])->each(function (DnsZone $zone) {
            if ($zone->status === ZoneStatus::Failed) {
                $zone->update(['status' => ZoneStatus::Syncing, 'last_error' => null]);
            }
            SyncDnsZone::dispatch($zone);
        });
    }
}
