<?php

namespace App\Livewire\Customers;

use App\Livewire\Attributes\ForCustomers;
use App\Models\Customer;
use App\Models\Site;
use App\Models\SiteTraffic;
use App\Services\Customers\Quotas;
use App\Services\Stats\DiskBreakdown;
use App\Services\Stats\SiteStats;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Kullanım: a customer's usage against their package (the quota rows),
 * what their disk space is made of, and their traffic month by month and
 * site by site. Customers open their own at /usage; administrators
 * open any customer's from the customer page.
 */
#[Layout('components.layouts.app')]
#[ForCustomers]
class UsageReport extends Component
{
    public const TABS = ['limits' => 'Paket sınırları', 'disk' => 'Disk', 'traffic' => 'Trafik'];

    public int $customerId;

    #[Url(except: 'limits')]
    public string $tab = 'limits';

    public function mount(?int $customer = null): void
    {
        $user = auth()->user();
        if ($user->is_admin) {
            abort_if($customer === null, 404);
            $this->customerId = Customer::findOrFail($customer)->id;
        } else {
            // A customer sees only their own report.
            abort_if($customer !== null && $customer !== $user->customer_id, 404);
            $this->customerId = (int) $user->customer_id;
        }
    }

    public function switchTab(string $tab): void
    {
        $this->tab = $tab;
    }

    public function render(Quotas $quotas, DiskBreakdown $disk, SiteStats $stats): View
    {
        $customer = Customer::with('package')->findOrFail($this->customerId);
        if (! isset(self::TABS[$this->tab])) {
            $this->tab = 'limits';
        }
        $data = ['customer' => $customer, 'report' => $quotas->report($customer)];
        if ($this->tab === 'disk') {
            $data['disk'] = $disk->customer($customer);
        }
        if ($this->tab === 'traffic') {
            $siteIds = Site::whereIn('system_user_id', $customer->systemUsers()->select('id'))->pluck('id')->all();
            $tz = config('serfex.timezone');
            $from = CarbonImmutable::now($tz)->startOfMonth()->subMonthsNoOverflow(11);
            $byMonth = SiteTraffic::whereIn('site_id', $siteIds)->where('month', '>=', $from->toDateString())
                ->selectRaw("to_char(month, 'YYYY-MM') as m, sum(bytes) as bytes")->groupByRaw("to_char(month, 'YYYY-MM')")->pluck('bytes', 'm');
            $months = [];
            for ($m = $from; $m->lte(CarbonImmutable::now($tz)); $m = $m->addMonthNoOverflow()) {
                $months[] = ['at' => $m, 'bytes' => (int) ($byMonth[$m->format('Y-m')] ?? 0)];
            }
            $month = $stats->thisMonth();
            $traffic = SiteTraffic::whereIn('site_id', $siteIds)->where('month', CarbonImmutable::now($tz)->startOfMonth()->toDateString())->pluck('bytes', 'site_id');
            $data['months'] = $months;
            $data['sites'] = Site::whereIn('id', $siteIds)->whereDoesntHave('preview')->orderBy('domain')->get()
                ->map(fn (Site $s) => ['site' => $s, 'bytes' => (int) ($traffic[$s->id] ?? 0), 'visitors' => $month[$s->id]['visitors'] ?? 0])
                ->sortByDesc('bytes')->values()->all();
        }

        return view('livewire.customers.report', $data)->title(__('Kullanım · :name · Serfex', ['name' => $customer->displayName()]));
    }
}
