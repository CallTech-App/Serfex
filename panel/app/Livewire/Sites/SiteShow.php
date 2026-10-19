<?php

namespace App\Livewire\Sites;

use App\Enums\SiteStatus;
use App\Jobs\DeleteSite;
use App\Jobs\ProvisionSite;
use App\Livewire\Attributes\ForCustomers;
use App\Models\Database;
use App\Models\DnsZone;
use App\Models\FtpAccount;
use App\Models\MailDomain;
use App\Models\ProtectedDirectory;
use App\Models\SecurityFinding;
use App\Models\SecurityScan;
use App\Models\Site;
use App\Services\Components\Components;
use App\Services\Mail\MailSettings;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[ForCustomers]
class SiteShow extends Component
{
    public int $siteId;

    public bool $deleting = false;

    public string $confirmDomain = '';

    public bool $removeUser = true;

    public bool $removeZone = true;

    public bool $removeMail = true;

    public bool $removeDatabases = true;

    /**
     * Takes the id rather than a bound model: Livewire re-runs the page's
     * route middleware on every update, and implicit binding would turn
     * polling a just-deleted site into a 404 instead of a redirect.
     */
    public function mount(int $site): void
    {
        $this->siteId = Site::visibleTo(auth()->user())->findOrFail($site)->id;
    }

    /**
     * Polled while the agent works on the site. Once a deletion finishes the
     * site is gone and the page returns to the list.
     */
    public function refreshStatus(): void
    {
        if ($this->site() === null) {
            session()->flash('toast', 'Site silindi.');
            $this->redirectRoute('sites.index', navigate: true);
        }
    }

    public function retry(): void
    {
        $site = $this->site();
        if ($site === null || $site->status !== SiteStatus::Failed) {
            return;
        }
        $site->update(['status' => SiteStatus::Provisioning, 'last_error' => null]);
        ProvisionSite::dispatch($site);
    }

    public function openDelete(): void
    {
        $this->reset('confirmDomain', 'removeUser', 'removeZone', 'removeMail', 'removeDatabases');
        $this->deleting = true;
    }

    public function delete(): void
    {
        $site = $this->site();
        if ($site === null || $site->status === SiteStatus::Deleting) {
            return;
        }
        if ($this->confirmDomain !== $site->domain) {
            $this->addError('confirmDomain', 'Alan adını tam olarak yazın.');

            return;
        }

        $site->update(['status' => SiteStatus::Deleting]);
        DeleteSite::dispatch($site, removeUser: $this->removeUser, removeZone: $this->removeZone, removeMail: $this->removeMail, removeDatabases: $this->removeDatabases);
        $this->deleting = false;
    }

    public function render(): View
    {
        $site = $this->site();

        if ($site === null) {
            // Deleted meanwhile; refreshStatus() sends the browser back to the list.
            return view('livewire.sites.gone');
        }

        return view('livewire.sites.show', [
            'site' => $site,
            'otherSites' => $site->systemUser->sites()->whereKeyNot($site->id)->count(),
            'zone' => DnsZone::withCount('records')->where('site_id', $site->id)->first(),
            'databases' => Database::where('site_id', $site->id)->count(),
            'ftpAccounts' => FtpAccount::where('site_id', $site->id)->count(),
            'mail' => MailDomain::withCount('mailboxes', 'forwarders')->where('name', $site->domain)->first(),
            'webmail' => app(MailSettings::class)->webmailUrl(),
            'wafInstalled' => app(Components::class)->isInstalled('modsecurity'),
            'openFindings' => SecurityFinding::open()->where('site_id', $site->id)->count(),
            'scanned' => SecurityScan::where(fn ($q) => $q->where('site_id', $site->id)->orWhere('scope', 'server'))->whereNotNull('finished_at')->exists(),
            'protectedDirs' => ProtectedDirectory::where('site_id', $site->id)->count(),
        ])->title($site->domain.' · Servik');
    }

    private function site(): ?Site
    {
        return Site::visibleTo(auth()->user())->with('systemUser.customer', 'certificate', 'certificates')->find($this->siteId);
    }
}
