<?php

namespace App\Livewire\Sites;

use App\Livewire\Attributes\ForCustomers;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Services\Customers\Usage;
use App\Services\Stats\DiskBreakdown;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Disk kullanımı: what a site's space is made of (web files, mail,
 * databases, application backups), measured every 30 minutes or on
 * request.
 */
#[Layout('components.layouts.app')]
#[ForCustomers]
class SiteDisk extends Component
{
    public int $siteId;

    public ?string $agentError = null;

    public function mount(int $site): void
    {
        $this->siteId = Site::visibleTo(auth()->user())->findOrFail($site)->id;
    }

    private function site(): Site
    {
        return Site::visibleTo(auth()->user())->with('systemUser')->findOrFail($this->siteId);
    }

    public function measure(Usage $usage): void
    {
        $this->agentError = null;
        try {
            $usage->measureDisk(collect([$this->site()->systemUser]));
        } catch (AgentException $e) {
            $this->agentError = 'Disk ölçülemedi: '.$e->forPeople();

            return;
        }
        $this->dispatch('toast', message: 'Disk kullanımı ölçüldü.');
    }

    public function render(DiskBreakdown $disk): View
    {
        $site = $this->site();

        return view('livewire.sites.disk', [
            'site' => $site,
            'disk' => $disk->site($site),
            'customer' => $site->customer(),
        ])->title('Disk kullanımı · '.$site->domain.' · Servik');
    }
}
