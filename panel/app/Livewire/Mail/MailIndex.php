<?php

namespace App\Livewire\Mail;

use App\Enums\MailDomainStatus;
use App\Models\DnsZone;
use App\Models\MailDomain;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Mail\MailDns;
use App\Services\Mail\MailDomains;
use App\Services\Mail\MailSettings;
use App\Support\Hostnames;
use Illuminate\Contracts\View\View;
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
#[Title('E-posta · Servik')]
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
        if ($open !== '' && MailDomain::where('name', $open)->doesntExist()) {
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
                fn ($attr, $value, $fail) => Hostnames::isValidDomain($value) ? null : $fail('Geçerli bir alan adı girin, örneğin ornek.com.tr.'),
                Rule::unique('mail_domains', 'name')->where('status', '!=', MailDomainStatus::Deleting->value),
                fn ($attr, $value, $fail) => $value === app(MailSettings::class)->hostname()
                    ? $fail('Bu ad posta sunucusunun kendi adı; e-postayı alan adının kendisi için açın.') : null,
            ],
        ], [
            'domain.unique' => 'Bu alan adında e-posta zaten açık.',
        ], [
            'domain' => 'alan adı',
        ]);

        $existing = MailDomain::where('name', $this->domain)->first();
        $domain = $domains->enable($this->domain);
        $this->enabling = false;
        if ($existing !== null) {
            $this->dispatch('toast', message: "{$domain->name} için e-posta yeniden açıldı; posta kutuları geri geldi.");

            return;
        }
        $this->dispatch('toast',
            message: "{$domain->name} için e-posta açıldı.",
            source: $this->getId(),
            undo: ['method' => 'undoEnable', 'args' => [$domain->id]],
        );
    }

    public function undoEnable(int $id, MailDomains $domains): void
    {
        $domain = MailDomain::find($id);
        if ($domain !== null) {
            $domains->disable($domain, purgeNow: $domain->mailboxes()->withTrashed()->doesntExist());
        }
    }

    public function render(MailDns $dns): View
    {
        $domains = MailDomain::query()
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

        $taken = MailDomain::pluck('name');

        return view('livewire.mail.index', [
            'domains' => $domains,
            'dnsStates' => $domains->getCollection()->mapWithKeys(fn (MailDomain $d) => [$d->id => $dns->summary($d)]),
            'busy' => $domains->getCollection()->contains(fn (MailDomain $d) => $d->status->isBusy()),
            // Domains on this server without mail yet, offered in the form.
            'candidates' => Site::pluck('domain')->merge(DnsZone::pluck('name'))->unique()->diff($taken)->sort()->values(),
        ]);
    }
}
