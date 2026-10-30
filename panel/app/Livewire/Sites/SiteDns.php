<?php

namespace App\Livewire\Sites;

use App\Livewire\Attributes\ForCustomers;
use App\Models\DnsZone;
use App\Models\Site;
use App\Services\Dns\DnsSettings;
use App\Services\Dns\Zones;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * DNS kayıtları inside a site: the records of the zone that holds the
 * site's domain, or a way to start one here when its DNS is elsewhere.
 * Alan Adları ve DNS in the menu stays the list of every zone.
 */
#[Layout('components.layouts.app')]
#[ForCustomers(feature: 'dns')]
class SiteDns extends Component
{
    public int $siteId;

    public function mount(int $site): void
    {
        $this->siteId = Site::visibleTo(auth()->user())->findOrFail($site)->id;
    }

    public function createZone(Zones $zones): void
    {
        $site = $this->site();
        if ($this->zone($site) !== null) {
            return;
        }
        $zone = $zones->forSite($site);
        if ($zone === null) {
            // The domain's zone or a parent zone appeared meanwhile; forSite() used it.
            return;
        }
        $this->dispatch('toast',
            message: __(':name DNS bölgesi oluşturuldu.', ['name' => $zone->name]),
            source: $this->getId(),
            undo: ['method' => 'undoCreate', 'args' => [$zone->id]],
        );
    }

    public function undoCreate(int $id, Zones $zones): void
    {
        $zone = DnsZone::visibleTo(auth()->user())->where('site_id', $this->siteId)->find($id);
        if ($zone !== null) {
            $zones->delete($zone);
        }
    }

    public function render(DnsSettings $settings): View
    {
        $site = $this->site();
        $zone = $this->zone($site);

        // What the domain's DNS needs wherever it is kept.
        $needed = [];
        $names = $site->wwwPair() !== null ? ['@', 'www'] : ['@'];
        foreach ($names as $name) {
            foreach (['A' => $settings->ipv4(), 'AAAA' => $settings->ipv6()] as $type => $ip) {
                if ($ip !== null) {
                    $needed[] = [$name === '@' ? $site->domain : 'www.'.$site->domain, $type, $ip];
                }
            }
        }

        return view('livewire.sites.dns', [
            'site' => $site,
            'zone' => $zone,
            'needed' => $needed,
        ])->title(__(':domain · DNS · Serfex', ['domain' => $site->domain]));
    }

    /**
     * The zone the site's records live in: its own (also one a site was
     * linked to) or the zone of a parent domain kept here.
     */
    private function zone(Site $site): ?DnsZone
    {
        $zone = DnsZone::visibleTo(auth()->user())
            ->where(fn ($q) => $q->where('name', $site->domain)->orWhere('site_id', $site->id))
            ->orderByRaw('name = ? desc', [$site->domain])
            ->first();
        if ($zone !== null) {
            return $zone;
        }
        $parent = app(Zones::class)->holding($site->domain);

        return $parent === null ? null : DnsZone::visibleTo(auth()->user())->find($parent->id);
    }

    private function site(): Site
    {
        return Site::visibleTo(auth()->user())->with('systemUser.customer.package', 'certificate')->findOrFail($this->siteId);
    }
}
