<?php

namespace App\Jobs;

use App\Models\DnsZone;
use App\Models\LaravelPreview;
use App\Services\Dns\Zones;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Removes a preview environment: its site goes with its application,
 * files, SQLite database and certificates, then its records in the
 * application's DNS zone.
 */
class DeleteLaravelPreview implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public LaravelPreview $preview) {}

    public function handle(Zones $zones): void
    {
        $preview = $this->preview->fresh(['app.site', 'site']);
        if ($preview === null) {
            return;
        }
        $domain = $preview->domain();
        if ($preview->site !== null) {
            DeleteSite::dispatchSync($preview->site, removeUser: false);
            if ($preview->site->fresh() !== null) {
                $preview->update(['status' => 'failed', 'error' => __('Önizleme sitesi silinemedi: :last_error', ['last_error' => $preview->site->fresh()->last_error])]);

                return;
            }
        }
        $parent = $zones->parentZone($domain);
        if ($parent instanceof DnsZone) {
            $label = substr($domain, 0, -strlen('.'.$parent->name));
            if ($parent->records()->whereIn('name', [$label, 'www.'.$label])->exists()) {
                $zones->change($parent, fn (DnsZone $zone) => $zone->records()->whereIn('name', [$label, 'www.'.$label])->whereIn('type', ['A', 'AAAA', 'CNAME'])->delete());
            }
        }
        $preview->delete();
    }
}
