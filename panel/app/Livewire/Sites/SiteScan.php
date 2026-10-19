<?php

namespace App\Livewire\Sites;

use App\Livewire\Attributes\ForCustomers;
use App\Livewire\Security\HandlesFindings;
use App\Models\SecurityFinding;
use App\Models\SecurityScan;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Services\Security\Scans;
use App\Services\Security\WebAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A site's security scan: start one, see and fix what it found (each fix
 * with Undo), and the paths visitors may not fetch. Customers use it on
 * their own sites when their package has site security.
 */
#[Layout('components.layouts.app')]
#[ForCustomers(feature: 'security')]
class SiteScan extends Component
{
    use HandlesFindings, WithPagination;

    public const TABS = ['bulgular' => 'Bulgular', 'engelli' => 'Engellenen yollar'];

    public int $siteId;

    #[Url(as: 'sekme', except: 'bulgular')]
    public string $tab = 'bulgular';

    #[Url(as: 'durum', except: 'open')]
    public string $findingStatus = 'open';

    public bool $denying = false;

    public string $denyPath = '';

    public function mount(int $site): void
    {
        $this->siteId = Site::visibleTo(auth()->user())->findOrFail($site)->id;
    }

    private function site(): Site
    {
        return Site::visibleTo(auth()->user())->with('systemUser.customer')->findOrFail($this->siteId);
    }

    protected function findingScope(): Builder
    {
        return SecurityFinding::visibleTo(auth()->user())->where('site_id', $this->siteId);
    }

    public function updatedFindingStatus(): void
    {
        $this->resetPage();
    }

    public function scan(Scans $scans): void
    {
        $scan = $scans->start($this->site(), 'manual', auth()->user());
        $this->dispatch('toast', message: is_string($scan) ? $scan : 'Tarama başladı.');
    }

    public function openDeny(): void
    {
        $this->resetValidation();
        $this->denyPath = '';
        $this->denying = true;
    }

    public function saveDeny(WebAccess $access): void
    {
        $this->resetValidation();
        $site = $this->site();
        $path = '/'.ltrim(trim($this->denyPath), '/');
        if ($path === '/' || ! WebAccess::validPath($path)) {
            $this->addError('denyPath', 'Yolu /yedek.sql ya da /eski/ gibi yazın; harf, rakam ve ._~%@+- içerebilir.');

            return;
        }
        if (in_array($path, $site->denied_paths ?? [], true)) {
            $this->addError('denyPath', 'Bu yol zaten engelli.');

            return;
        }
        if (count($site->denied_paths ?? []) >= WebAccess::MAX_DENIED) {
            $this->addError('denyPath', 'Bir sitede en fazla '.WebAccess::MAX_DENIED.' yol engellenebilir.');

            return;
        }
        try {
            $access->deny($site, [$path]);
        } catch (AgentException $e) {
            $this->addError('denyPath', WebAccess::message($e));

            return;
        }
        $this->denying = false;
        $this->dispatch('toast', message: $path.' artık indirilemiyor.', source: $this->getId(), undo: ['method' => 'allow', 'args' => [$path]]);
    }

    public function allow(string $path, WebAccess $access): void
    {
        $site = $this->site();
        if (! in_array($path, $site->denied_paths ?? [], true)) {
            return;
        }
        try {
            $access->allow($site, [$path]);
        } catch (AgentException $e) {
            $this->dispatch('toast', message: WebAccess::message($e));

            return;
        }
        $this->dispatch('toast', message: $path.' yeniden erişilebilir.', source: $this->getId(), undo: ['method' => 'deny', 'args' => [$path]]);
    }

    public function deny(string $path, WebAccess $access): void
    {
        $site = $this->site();
        if (! WebAccess::validPath($path) || $path === '/') {
            return;
        }
        try {
            $access->deny($site, [$path]);
        } catch (AgentException $e) {
            $this->dispatch('toast', message: WebAccess::message($e));
        }
    }

    public function render(): View
    {
        $site = $this->site();
        $running = SecurityScan::running()->where(fn ($q) => $q->where('site_id', $site->id)->orWhere('scope', 'server'))->latest('id')->first();
        $last = SecurityScan::whereNotIn('status', ['queued', 'running'])->where('trigger', '!=', 'upload')
            ->where(fn ($q) => $q->where('site_id', $site->id)->orWhere('scope', 'server'))->latest('id')->first();
        $status = in_array($this->findingStatus, ['open', 'fixed', 'ignored'], true) ? $this->findingStatus : 'open';

        return view('livewire.sites.scan', [
            'site' => $site,
            'running' => $running,
            'last' => $last,
            'findings' => $this->findingScope()->with('resolver')->where('status', $status)->worstFirst()->paginate(50),
            'statusCounts' => $this->findingScope()->whereIn('status', ['open', 'fixed', 'ignored'])->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all(),
            'denied' => $site->denied_paths ?? [],
        ])->title('Güvenlik taraması · '.$site->domain.' · Servik');
    }
}
