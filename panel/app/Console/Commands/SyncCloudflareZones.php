<?php

namespace App\Console\Commands;

use App\Enums\ZoneStatus;
use App\Jobs\SyncDnsZone;
use App\Models\DnsZone;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs from the scheduler every five minutes: syncs every zone whose DNS
 * is at Cloudflare, so changes made in Cloudflare's dashboard reach the
 * panel and Cloudflare's state (name servers, SSL mode) stays current.
 */
#[Signature('serfex:cloudflare-sync')]
#[Description('Sync the zones whose DNS is at Cloudflare')]
class SyncCloudflareZones extends Command
{
    public function handle(): int
    {
        $zones = DnsZone::whereNotNull('cloudflare_account_id')->whereNotNull('cloudflare_zone_id')
            ->where('status', '!=', ZoneStatus::Deleting)->get();
        foreach ($zones as $zone) {
            SyncDnsZone::dispatch($zone);
        }
        $this->info($zones->count().' Cloudflare zones queued.');

        return self::SUCCESS;
    }
}
