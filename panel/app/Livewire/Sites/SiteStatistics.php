<?php

namespace App\Livewire\Sites;

use App\Livewire\Attributes\ForCustomers;
use App\Models\Site;
use App\Services\Stats\SiteStats;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * İstatistikler: a site's visitors, page views, requests and bandwidth
 * over a period, a chart of them, and its top pages, referrers,
 * countries and bots. Counted by Serfex from the site's access log; no
 * outside service sees the visitors.
 */
#[Layout('components.layouts.app')]
#[ForCustomers]
class SiteStatistics extends Component
{
    public int $siteId;

    #[Url(as: 'donem', except: '30g')]
    public string $period = '30g';

    #[Url(as: 'sekme', except: 'page')]
    public string $tab = 'page';

    /** What the chart shows: visitors, pages, requests or bytes. */
    #[Url(as: 'grafik', except: 'visitors')]
    public string $metric = 'visitors';

    public const METRICS = ['visitors' => 'Ziyaretçi', 'pages' => 'Sayfa görüntüleme', 'requests' => 'İstek', 'bytes' => 'Bant genişliği'];

    public function mount(int $site): void
    {
        $this->siteId = Site::visibleTo(auth()->user())->findOrFail($site)->id;
    }

    public function switchTab(string $tab): void
    {
        $this->tab = $tab;
    }

    public function render(SiteStats $stats): View
    {
        $site = Site::visibleTo(auth()->user())->findOrFail($this->siteId);
        if (! isset(SiteStats::PERIODS[$this->period])) {
            $this->period = '30g';
        }
        if (! isset(SiteStats::TABS[$this->tab])) {
            $this->tab = 'page';
        }
        if (! isset(self::METRICS[$this->metric])) {
            $this->metric = 'visitors';
        }

        return view('livewire.sites.statistics', [
            'site' => $site,
            'totals' => $stats->totals([$site->id], $this->period),
            'series' => $stats->series([$site->id], $this->period),
            'items' => $stats->items([$site->id], $this->period, $this->tab),
            'countriesReady' => SiteStats::countriesReady(),
        ])->title(__('İstatistikler · :domain · Serfex', ['domain' => $site->domain]));
    }
}
