<?php

namespace App\Livewire\Mail;

use App\Enums\MailDomainStatus;
use App\Livewire\Attributes\ForCustomers;
use App\Models\DnsZone;
use App\Models\MailDomain;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Mail\MailDns;
use App\Services\Mail\MailDomains;
use App\Services\Mail\MailSettings;
use App\Support\Hostnames;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every domain with mail on this server: mailboxes, space used, spam
 * filter and whether its DNS records are in place.
 */
#[Layout('components.layouts.app')]
#[Title('E-posta · Serfex')]
#[ForCustomers(feature: 'mail', except: ['setupServer'])]
class MailIndex extends Component
{
    use ChecksMailServer, WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** "" for all domains, "problem" for the ones that need attention. */
    #[Url(as: 'durum', except: '')]
    public string $filter = '';

    public bool $enabling = false;

    public string $domain = '';

    public function mount(AgentClient $agent, MailSettings $settings): void
    {
        $this->refreshServer($agent, $settings);
        // "E-postayı aç" from a site's page.
        $open = Hostnames::normalize((string) request()->query('ac', ''));
        if ($open !== '' && MailDomain::where('name', $open)->doesntExist() && (auth()->user()->is_admin || $this->candidates()->contains($open))) {
            $this->domain = $open;
            $this->enabling = true;
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function openEnable(): void
    {
        $this->reset('domain');
        $this->resetValidation();
        $this->enabling = true;
    }

    public function enable(MailDomains $domains): void
    {
        $this->domain = Hostnames::normalize($this->domain);
        $this->validate([
            'domain' => [
                'required',
                fn ($attr, $value, $fail) => Hostnames::isValidDomain($value) ? null : $fail(__('Geçerli bir alan adı girin, örneğin ornek.com.tr.')),
                Rule::unique('mail_domains', 'name')->where('status', '!=', MailDomainStatus::Deleting->value),
                fn ($attr, $value, $fail) => auth()->user()->is_admin || $this->candidates(withTaken: true)->contains($value)
                    ? null : $fail(__('E-postayı yalnızca kendi sitelerinizin alan adları için açabilirsiniz.')),
                fn ($attr, $value, $fail) => $value === app(MailSettings::class)->hostname()
                    ? $fail(__('Bu ad posta sunucusunun kendi adı; e-postayı alan adının kendisi için açın.')) : null,
            ],
        ], [
            'domain.unique' => __('Bu alan adında e-posta zaten açık.'),
        ], [
            'domain' => __('alan adı'),
        ]);

        $existing = MailDomain::where('name', $this->domain)->first();
        $domain = $domains->enable($this->domain, Site::visibleTo(auth()->user())->where('domain', $this->domain)->first());
        $this->enabling = false;
        if ($existing !== null) {
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
        $domain = MailDomain::visibleTo(auth()->user())->find($id);
        if ($domain !== null) {
            $domains->disable($domain, purgeNow: $domain->mailboxes()->withTrashed()->doesntExist());
        }
    }

    public function render(MailDns $dns): View
    {
        $domains = MailDomain::visibleTo(auth()->user())
            ->with('site')
            ->withCount('mailboxes')
            ->withSum('mailboxes', 'usage_bytes')
            ->withSum('mailboxes', 'quota_mb')
            ->withCount(['mailboxes as unlimited_count' => fn ($q) => $q->where('quota_mb', 0)])
            ->when($this->search !== '', function ($q) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($this->search)).'%';
                $q->where('name', 'like', $term);
            })
            ->when($this->filter === 'problem', fn ($q) => $q->where('status', MailDomainStatus::Failed))
            ->orderBy('name')
            ->paginate(25);

        return view('livewire.mail.index', [
            'domains' => $domains,
            'dnsStates' => $domains->getCollection()->mapWithKeys(fn (MailDomain $d) => [$d->id => $dns->summary($d)]),
            'busy' => $domains->getCollection()->contains(fn (MailDomain $d) => $d->status->isBusy()),
            // Domains on this server without mail yet, offered in the form.
            'candidates' => $this->candidates(),
        ]);
    }

    /**
     * Domains that may get mail: every site and DNS zone on the server for
     * an administrator, their own sites for a customer.
     *
     * @return Collection<int, string>
     */
    private function candidates(bool $withTaken = false): Collection
    {
        $user = auth()->user();
        $names = $user->is_admin
            ? Site::pluck('domain')->merge(DnsZone::pluck('name'))
            : Site::visibleTo($user)->pluck('domain');
        if (! $withTaken) {
            $names = $names->diff(MailDomain::pluck('name'));
        }

        return $names->unique()->sort()->values();
    }
}
