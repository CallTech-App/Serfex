<?php

namespace App\Livewire\Sites;

use App\Enums\SiteStatus;
use App\Jobs\DeleteSite;
use App\Jobs\ProvisionSite;
use App\Livewire\Attributes\ForCustomers;
use App\Models\Customer;
use App\Models\DnsZone;
use App\Models\Site;
use App\Models\SystemUser;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentUnavailableException;
use App\Services\Components\Components;
use App\Services\Customers\Customers;
use App\Services\Customers\Quotas;
use App\Services\Dns\Zones;
use App\Services\Mail\MailDomains;
use App\Support\Hostnames;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
#[Title('Siteler · Servik')]
#[ForCustomers]
class SiteIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public bool $creating = false;

    public string $domain = '';

    public string $systemUser = '';

    /** Administrators may create the site for a customer ("" for none). */
    #[Url(as: 'musteri', except: '')]
    public string $customerId = '';

    public string $phpVersion = '';

    public bool $wwwAlias = true;

    /** @var list<string> */
    public array $phpVersions = [];

    /** Set when the agent cannot be used, shown above the form. */
    public ?string $agentError = null;

    /** True once the user typed an account name; stops auto-suggesting. */
    public bool $userEdited = false;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function mount(): void
    {
        // "Site ekle" from a customer's page.
        if ($this->customerId !== '' && auth()->user()->is_admin && Customer::whereKey($this->customerId)->exists()) {
            $this->openCreate(app(AgentClient::class), app(Quotas::class));
        } else {
            $this->customerId = '';
        }
    }

    public function openCreate(AgentClient $agent, Quotas $quotas): void
    {
        $this->reset('domain', 'systemUser', 'phpVersion', 'wwwAlias', 'agentError', 'userEdited');
        $this->resetValidation();
        $this->creating = true;
        $this->updatedCustomerId();

        $customer = $this->customer();
        if ($refusal = $quotas->refusal($customer, 'sites')) {
            $this->phpVersions = [];
            $this->agentError = $refusal;

            return;
        }

        try {
            $installed = array_values($agent->call('php.versions') ?? []);
        } catch (AgentException $e) {
            $this->phpVersions = [];
            $this->agentError = $this->agentMessage($e);

            return;
        }
        $this->phpVersions = $customer?->package ? $customer->package->phpVersionsFrom($installed) : $installed;

        if ($this->phpVersions === []) {
            $this->agentError = $installed === []
                ? 'Sunucuda PHP-FPM kurulu değil. Önce bir PHP sürümü kurun.'
                : 'Paketin izin verdiği PHP sürümlerinin hiçbiri sunucuda kurulu değil.';

            return;
        }
        $this->phpVersion = in_array('8.4', $this->phpVersions, true) ? '8.4' : end($this->phpVersions);
    }

    public function updatedDomain(): void
    {
        if (! $this->userEdited) {
            $this->systemUser = Hostnames::suggestUser(Hostnames::normalize($this->domain));
        }
    }

    public function updatedSystemUser(): void
    {
        $this->userEdited = true;
    }

    /**
     * A customer's sites go under their own account.
     */
    public function updatedCustomerId(): void
    {
        $customer = $this->customer();
        if ($customer !== null) {
            $this->systemUser = $customer->username;
            $this->userEdited = true;
        } elseif (auth()->user()->is_admin && $this->userEdited && $this->systemUser !== '' && Customer::where('username', $this->systemUser)->exists()) {
            $this->systemUser = Hostnames::suggestUser(Hostnames::normalize($this->domain));
            $this->userEdited = false;
        }
    }

    public function create(AgentClient $agent, Zones $zones, MailDomains $mail, Quotas $quotas, Customers $customers): void
    {
        $this->domain = Hostnames::normalize($this->domain);
        $customer = $this->customer();
        if ($customer !== null) {
            $this->systemUser = $customer->username;
        }
        $this->systemUser = trim($this->systemUser);

        $this->validate([
            'domain' => [
                'required',
                fn ($attr, $value, $fail) => Hostnames::isValidDomain($value) ? null : $fail('Geçerli bir alan adı girin, örneğin ornek.com.tr.'),
                Rule::unique('sites', 'domain'),
            ],
            'systemUser' => [
                'required',
                'regex:'.Hostnames::SYSTEM_USER_PATTERN,
                Rule::notIn(Hostnames::RESERVED_USERS),
            ],
            'phpVersion' => ['required', Rule::in($this->phpVersions)],
        ], [
            'domain.unique' => 'Bu alan adı zaten bir siteye ait.',
            'systemUser.regex' => 'Küçük harfle başlamalı; yalnızca a-z, 0-9, - ve _ içerebilir (en fazla 32).',
            'systemUser.not_in' => 'Bu ad sisteme ayrılmış, başka bir ad seçin.',
        ], [
            'domain' => 'alan adı',
            'systemUser' => 'sistem kullanıcısı',
            'phpVersion' => 'PHP sürümü',
        ]);

        // An account that belongs to a customer takes the site to that
        // customer, within their package.
        $account = SystemUser::where('name', $this->systemUser)->first();
        if ($account?->customer_id !== null && $customer !== null && $account->customer_id !== $customer->id) {
            $this->addError('systemUser', 'Bu sistem kullanıcısı başka bir müşteriye ait.');

            return;
        }
        $owner = $customer ?? $account?->customer;
        if (auth()->user()->isCustomer() && $this->takenByOthers($owner)) {
            $this->addError('domain', 'Bu alan adı ya da üst alan adı başka bir hesaba ait.');

            return;
        }
        if ($refusal = $quotas->refusal($owner, 'sites')) {
            $this->agentError = $refusal;

            return;
        }

        if (! $agent->ping()) {
            $this->agentError = 'Sunucu ajanına ulaşılamıyor. Sunucuda "systemctl status servik-agent" ile kontrol edin.';

            return;
        }

        $site = DB::transaction(function () use ($customer, $customers) {
            $user = $customer !== null ? $customers->account($customer) : SystemUser::firstOrCreate(['name' => $this->systemUser]);

            return $user->sites()->create([
                'domain' => $this->domain,
                'aliases' => $this->wwwAlias && ! str_starts_with($this->domain, 'www.') ? ['www.'.$this->domain] : [],
                'php_version' => $this->phpVersion,
                'web_server' => app(Components::class)->defaultStack(),
                'status' => SiteStatus::Provisioning,
            ]);
        });

        ProvisionSite::dispatch($site);
        // DNS does not wait for the web server: the zone is published even
        // if provisioning fails and is retried.
        $zones->forSite($site);
        if ($owner === null || $owner->package->allows('mail')) {
            $mail->forSite($site);
        }

        $this->creating = false;
        $this->dispatch('toast',
            message: "{$site->domain} oluşturuluyor.",
            source: $this->getId(),
            undo: ['method' => 'undoCreate', 'args' => [$site->id]],
        );
    }

    /**
     * True when the domain, or a domain above it, has a DNS zone that is
     * not the customer's: their site would then live in someone else's zone.
     */
    private function takenByOthers(Customer $customer): bool
    {
        $labels = explode('.', $this->domain);
        $names = [];
        for ($i = 0; $i < count($labels) - 1; $i++) {
            $names[] = implode('.', array_slice($labels, $i));
        }

        return DnsZone::with('site.systemUser')->whereIn('name', $names)->get()
            ->contains(fn (DnsZone $zone) => $zone->site === null || ! $customer->owns($zone->site));
    }

    /**
     * Undo for "Site ekle": removes the site again, its account when the
     * site was the only one using it, and the DNS zone created with it.
     */
    public function undoCreate(int $id): void
    {
        $site = Site::visibleTo(auth()->user())->find($id);
        if ($site === null || $site->status === SiteStatus::Deleting) {
            return;
        }
        $site->update(['status' => SiteStatus::Deleting]);
        DeleteSite::dispatch($site, removeUser: true, removeZone: true, removeMail: true);
        $this->dispatch('toast', message: "{$site->domain} geri alınıyor.");
    }

    public function render(): View
    {
        $user = auth()->user();
        $sites = Site::visibleTo($user)
            ->with('systemUser.customer', 'preview')
            ->when($this->search !== '', fn ($q) => $q->where(function ($q) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($this->search)).'%';
                $q->where('domain', 'like', $term)
                    ->orWhereHas('systemUser', fn ($u) => $u->where('name', 'like', $term));
            }))
            ->orderBy('domain')
            ->paginate(25);

        return view('livewire.sites.index', [
            'sites' => $sites,
            'customers' => $user->is_admin && $this->creating ? Customer::orderByRaw('lower(coalesce(company, name))')->get(['id', 'company', 'name']) : collect(),
            'busy' => $sites->getCollection()->contains(fn (Site $s) => $s->status->isBusy()),
        ]);
    }

    /**
     * The customer the new site is for: the signed-in customer, or the one
     * an administrator picked.
     */
    private function customer(): ?Customer
    {
        $user = auth()->user();
        if ($user->isCustomer()) {
            return $user->customer()->with('package')->first();
        }

        return $user->is_admin && $this->customerId !== '' ? Customer::with('package')->find((int) $this->customerId) : null;
    }

    private function agentMessage(AgentException $e): string
    {
        return $e instanceof AgentUnavailableException
            ? 'Sunucu ajanına ulaşılamıyor. Sunucuda "systemctl status servik-agent" ile kontrol edin.'
            : 'Sunucu ajanı hata verdi: '.$e->reason;
    }
}
