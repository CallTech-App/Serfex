<?php

namespace App\Livewire\Sites;

use App\Livewire\Attributes\ForCustomers;
use App\Livewire\Mail\ChecksMailServer;
use App\Models\MailDomain;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Mail\MailDomains;
use App\Services\Mail\MailSettings;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * E-posta inside a site: turn mail on for the site's domain and manage its
 * mailboxes, forwarders and settings without leaving the site. The E-posta
 * menu stays the list of every mail domain on the server.
 */
#[Layout('components.layouts.app')]
#[ForCustomers(feature: 'mail', except: ['setupServer'])]
class SiteMail extends Component
{
    use ChecksMailServer;

    public int $siteId;

    public function mount(int $site, AgentClient $agent, MailSettings $settings): void
    {
        $this->siteId = Site::visibleTo(auth()->user())->findOrFail($site)->id;
        $this->refreshServer($agent, $settings);
    }

    public function enable(MailDomains $domains, MailSettings $settings): void
    {
        $site = $this->site();
        if ($this->domain($site) !== null) {
            return;
        }
        if ($site->domain === $settings->hostname()) {
            $this->dispatch('toast', message: __('Bu ad posta sunucusunun kendi adı; e-postayı alan adının kendisi için açın.'));

            return;
        }
        $existing = MailDomain::where('name', $site->domain)->exists();
        $domain = $domains->enable($site->domain, $site);
        if ($existing) {
            $this->dispatch('toast', message: __(':name için e-posta yeniden açıldı; posta kutuları geri geldi.', ['name' => $domain->name]));

            return;
        }
        $this->dispatch('toast',
            message: __(':name için e-posta açıldı.', ['name' => $domain->name]),
            source: $this->getId(),
            undo: ['method' => 'undoEnable', 'args' => [$domain->id]],
        );
    }

    public function undoEnable(int $id, MailDomains $domains): void
    {
        $domain = MailDomain::visibleTo(auth()->user())->where('site_id', $this->siteId)->find($id);
        if ($domain !== null) {
            $domains->disable($domain, purgeNow: $domain->mailboxes()->withTrashed()->doesntExist());
        }
    }

    public function render(): View
    {
        $site = $this->site();
        $domain = $this->domain($site);

        // Additional names of the site that could have mail of their own.
        $aliases = collect($site->aliases ?? [])->reject(fn ($name) => str_starts_with($name, 'www.'))->values();
        $aliasMail = MailDomain::visibleTo(auth()->user())->whereIn('name', $aliases)->pluck('id', 'name');

        return view('livewire.sites.mail', [
            'site' => $site,
            'domain' => $domain,
            'aliases' => $aliases,
            'aliasMail' => $aliasMail,
        ])->title(__(':domain · E-posta · Serfex', ['domain' => $site->domain]));
    }

    /**
     * The site's mail domain, also while its mail is being turned off (its
     * page offers Undo then).
     */
    private function domain(Site $site): ?MailDomain
    {
        return MailDomain::visibleTo(auth()->user())->where('name', $site->domain)->first();
    }

    private function site(): Site
    {
        return Site::visibleTo(auth()->user())->with('systemUser.customer.package', 'certificate')->findOrFail($this->siteId);
    }
}
