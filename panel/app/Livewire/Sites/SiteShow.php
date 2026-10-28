<?php

namespace App\Livewire\Sites;

use App\Enums\SiteStatus;
use App\Jobs\DeleteSite;
use App\Jobs\ProvisionSite;
use App\Livewire\Attributes\ForCustomers;
use App\Models\BackupItem;
use App\Models\CronJob;
use App\Models\Database;
use App\Models\DnsZone;
use App\Models\FtpAccount;
use App\Models\MailDomain;
use App\Models\ProtectedDirectory;
use App\Models\SecurityFinding;
use App\Models\SecurityScan;
use App\Models\Site;
use App\Services\Backups\Backups;
use App\Services\Backups\BackupSettings;
use App\Services\Components\Components;
use App\Services\Mail\MailSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
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

    /** Back the site up first (administrators may leave it out). */
    public bool $backup = true;

    /** Why the backup before deleting would not fit on the disk, or null. */
    public ?string $noRoom = null;

    /** Kept to find the backup once the site is gone. */
    public string $domain = '';

    /** When this page asked for the deletion with a backup. */
    public ?string $backupSince = null;

    /**
     * Takes the id rather than a bound model: Livewire re-runs the page's
     * route middleware on every update, and implicit binding would turn
     * polling a just-deleted site into a 404 instead of a redirect.
     */
    public function mount(int $site): void
    {
        $found = Site::visibleTo(auth()->user())->findOrFail($site);
        $this->siteId = $found->id;
        $this->domain = $found->domain;
    }

    /**
     * Polled while the agent works on the site. Once a deletion finishes the
     * site is gone and the page returns to the list.
     */
    public function refreshStatus(): void
    {
        if ($this->site() === null) {
            $item = $this->deleteBackup();
            session()->flash('toast', $item === null ? __('Site silindi.') : [
                'message' => __('Site silindi. Yedeği :days gün Yedekler\'de saklanır.', ['days' => app(BackupSettings::class)->schedule()['delete_days']]),
                'undo' => ['name' => 'sites.site-index', 'method' => 'restoreDeleted', 'args' => [$item->id]],
            ]);
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
        $this->reset('confirmDomain', 'removeUser', 'removeZone', 'removeMail', 'removeDatabases', 'backup');
        $site = $this->site();
        $this->noRoom = $site ? app(Backups::class)->noRoomBeforeDelete($site) : null;
        $this->backup = $this->noRoom === null;
        $this->deleting = true;
    }

    public function delete(): void
    {
        $site = $this->site();
        if ($site === null || $site->status === SiteStatus::Deleting) {
            return;
        }
        if ($this->confirmDomain !== $site->domain) {
            $this->addError('confirmDomain', __('Alan adını tam olarak yazın.'));

            return;
        }

        // Customers cannot leave the backup out; only a full disk can.
        $backup = $this->noRoom === null && ($this->backup || ! auth()->user()->is_admin);
        $this->backupSince = $backup ? now()->toIso8601String() : null;
        $site->update(['status' => SiteStatus::Deleting]);
        DeleteSite::dispatch($site, removeUser: $this->removeUser, removeZone: $this->removeZone, removeMail: $this->removeMail, removeDatabases: $this->removeDatabases,
            backup: $backup, userId: auth()->id());
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
            // The site's own zone first; additional domains may have theirs.
            'zone' => DnsZone::withCount('records')->where('site_id', $site->id)->orderByRaw('name = ? desc', [$site->domain])->first(),
            'subdomainCount' => $site->subdomains()->count(),
            'redirectCount' => $site->redirects()->count(),
            'databases' => Database::where('site_id', $site->id)->count(),
            'ftpAccounts' => FtpAccount::where('site_id', $site->id)->count(),
            'cronJobs' => CronJob::where('site_id', $site->id)->count(),
            'mail' => MailDomain::withCount('mailboxes', 'forwarders')->where('name', $site->domain)->first(),
            'webmail' => app(MailSettings::class)->webmailUrl(),
            'wafInstalled' => app(Components::class)->isInstalled('modsecurity'),
            'openFindings' => SecurityFinding::open()->where('site_id', $site->id)->count(),
            'scanned' => SecurityScan::where(fn ($q) => $q->where('site_id', $site->id)->orWhere('scope', 'server'))->whereNotNull('finished_at')->exists(),
            'protectedDirs' => ProtectedDirectory::where('site_id', $site->id)->count(),
            'backingUp' => $site->status === SiteStatus::Deleting && BackupItem::where('site_id', $site->id)
                ->whereHas('backup', fn ($q) => $q->where('trigger', 'delete')->whereIn('status', ['queued', 'running']))->exists(),
            'deleteDays' => app(BackupSettings::class)->schedule()['delete_days'],
        ])->title($site->domain.' · Serfex');
    }

    /**
     * The backup taken just before this site was deleted from this page,
     * if the user may restore it.
     */
    private function deleteBackup(): ?BackupItem
    {
        if ($this->backupSince === null) {
            return null;
        }

        return BackupItem::visibleTo(auth()->user())
            ->where('name', $this->domain)
            ->where('status', 'succeeded')
            ->where('created_at', '>=', Carbon::parse($this->backupSince)->subMinute())
            ->whereHas('backup', fn ($q) => $q->where('trigger', 'delete')->usable())
            ->latest('id')
            ->first();
    }

    private function site(): ?Site
    {
        return Site::visibleTo(auth()->user())->with('systemUser.customer', 'certificate', 'certificates')->find($this->siteId);
    }
}
