<?php

namespace App\Livewire\Sites;

use App\Livewire\Attributes\ForCustomers;
use App\Models\Site;
use App\Services\Sites\Uptime;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Site → Genel bakış → Erişilebilirlik: the last 24 hours of serfex:uptime's
 * checks, the share of good ones over 24 hours and 30 days, the response
 * time, the outages, and Ayarla (on or off, the path checked).
 */
#[ForCustomers]
class SiteUptime extends Component
{
    #[Locked]
    public int $siteId;

    public bool $editing = false;

    public bool $enabled = true;

    public string $path = '';

    public function mount(int $siteId): void
    {
        $this->siteId = $siteId;
        abort_if($this->site() === null, 404);
    }

    public function openSettings(): void
    {
        $site = $this->site() ?? abort(404);
        $this->enabled = $site->uptime_enabled;
        $this->path = (string) $site->uptime_path;
        $this->resetErrorBag();
        $this->editing = true;
    }

    public function save(): void
    {
        $site = $this->site() ?? abort(404);
        $this->path = trim($this->path);
        $this->validate(['path' => ['nullable', 'string', 'max:200', 'regex:#^/[^\s]*$#']], ['path.regex' => __('Yol / ile başlamalı ve boşluk içermemeli.')]);
        $changed = $site->uptime_path !== ($this->path ?: null);
        $site->forceFill([
            'uptime_enabled' => $this->enabled,
            'uptime_path' => $this->path ?: null,
        ]);
        if (! $this->enabled || $changed) {
            // A new address (or no checks) starts over: an open outage ends
            // and the site waits for its first answer again.
            $site->outages()->whereNull('ended_at')->update(['ended_at' => now()]);
            $site->forceFill(['uptime_state' => null, 'uptime_failures' => 0, 'uptime_error' => null, 'uptime_cause' => null, 'uptime_since' => null]);
        }
        $site->save();
        $this->editing = false;
        $this->dispatch('toast', message: $this->enabled ? __('Erişilebilirlik izleme ayarları kaydedildi.') : __('Erişilebilirlik izleme kapatıldı.'));
    }

    private function site(): ?Site
    {
        return Site::visibleTo(auth()->user())->with('laravelApp', 'certificate', 'systemUser.customer')->find($this->siteId);
    }

    public function render(Uptime $uptime): View
    {
        $site = $this->site() ?? abort(404);

        return view('livewire.sites.uptime', [
            'site' => $site,
            'summary' => $uptime->summary($site),
            'defaultPath' => $site->laravelApp?->health_path ?: '/',
        ]);
    }
}
