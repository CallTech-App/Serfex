<?php

namespace App\Livewire\Sites;

use App\Enums\SiteStatus;
use App\Jobs\DeleteSite;
use App\Livewire\Attributes\ForCustomers;
use App\Livewire\Concerns\InstallsAppTemplate;
use App\Models\BackupItem;
use App\Models\Customer;
use App\Models\DnsZone;
use App\Models\Site;
use App\Models\SiteTraffic;
use App\Models\SystemUser;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentUnavailableException;
use App\Services\Backups\Restores;
use App\Services\Customers\Quotas;
use App\Services\Php\PhpVersions;
use App\Services\Sites\SiteCreator;
use App\Support\Hostnames;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
#[Title('Siteler · Serfex')]
#[ForCustomers]
class SiteIndex extends Component
{
    use InstallsAppTemplate;
    use WithFileUploads;
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public bool $creating = false;

    public string $domain = '';

    public string $systemUser = '';

    /** Administrators may create the site for a customer ("" for none). */
    #[Url(as: 'customer', except: '')]
    public string $customerId = '';

    /** "" sorts by domain, "traffic" by this month's traffic. */
    #[Url(as: 'sort', except: '')]
    public string $sort = '';

    public string $phpVersion = '';

    /**
     * The address visitors end up on: a Site::PREFER_* value, or "only"
     * for a site without the www. name.
     */
    public string $address = Site::PREFER_BARE;

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
        $this->reset('domain', 'systemUser', 'phpVersion', 'address', 'agentError', 'userEdited', 'templateId', 'answers', 'uploads', 'adminName', 'adminEmail');
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
                ? __('Sunucuda PHP-FPM kurulu değil. Önce bir PHP sürümü kurun.')
                : __('Paketin izin verdiği PHP sürümlerinin hiçbiri sunucuda kurulu değil.');

            return;
        }
        $this->phpVersion = (string) app(PhpVersions::class)->default($this->phpVersions);
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
        if ($this->templateId !== '' && $customer !== null) {
            $this->adminName = $customer->displayName();
            $this->adminEmail = (string) $customer->email;
        }
        if ($customer !== null) {
            $this->systemUser = $customer->username;
            $this->userEdited = true;
        } elseif (auth()->user()->is_admin && $this->userEdited && $this->systemUser !== '' && Customer::where('username', $this->systemUser)->exists()) {
            $this->systemUser = Hostnames::suggestUser(Hostnames::normalize($this->domain));
            $this->userEdited = false;
        }
    }

    /**
     * "Uygulama": the template's questions, with the customer's name and
     * e-mail, and its PHP version when the server has it.
     */
    public function updatedTemplateId(): void
    {
        $this->resetValidation();
        $customer = $this->customer();
        $this->prepareTemplateForm(null, $customer?->displayName(), $customer?->email, Hostnames::normalize($this->domain));
        $php = $this->pickedTemplate()?->php_version;
        if ($php && in_array($php, $this->phpVersions, true)) {
            $this->phpVersion = $php;
        }
    }

    public function create(AgentClient $agent, Quotas $quotas, SiteCreator $creator): void
    {
        $this->domain = Hostnames::normalize($this->domain);
        $customer = $this->customer();
        if ($customer !== null) {
            $this->systemUser = $customer->username;
        }
        $this->systemUser = trim($this->systemUser);
        $template = $this->pickedTemplate();

        $this->validate([
            'domain' => [
                'required',
                fn ($attr, $value, $fail) => Hostnames::isValidDomain($value) ? null : $fail(__('Geçerli bir alan adı girin, örneğin ornek.com.tr.')),
                Rule::unique('sites', 'domain'),
            ],
            'systemUser' => [
                'required',
                'regex:'.Hostnames::SYSTEM_USER_PATTERN,
                Rule::notIn(Hostnames::RESERVED_USERS),
            ],
            'phpVersion' => ['required', Rule::in($this->phpVersions)],
            'address' => ['required', Rule::in([...Site::PREFERRED, 'only'])],
            'templateId' => ['nullable', Rule::in($this->installableTemplates()->pluck('id')->map(fn ($id) => (string) $id)->all())],
        ] + ($template !== null ? $this->templateRules($template) : []), [
            'domain.unique' => __('Bu alan adı zaten bir siteye ait.'),
            'systemUser.regex' => __('Küçük harfle başlamalı; yalnızca a-z, 0-9, - ve _ içerebilir (en fazla 32).'),
            'systemUser.not_in' => __('Bu ad sisteme ayrılmış, başka bir ad seçin.'),
        ], [
            'domain' => __('alan adı'),
            'systemUser' => __('sistem kullanıcısı'),
            'phpVersion' => __('PHP sürümü'),
        ] + ($template ? $this->templateAttributes($template) : []));

        // An account that belongs to a customer takes the site to that
        // customer, within their package.
        $account = SystemUser::where('name', $this->systemUser)->first();
        if ($account?->customer_id !== null && $customer !== null && $account->customer_id !== $customer->id) {
            $this->addError('systemUser', __('Bu sistem kullanıcısı başka bir müşteriye ait.'));

            return;
        }
        $owner = $customer ?? $account?->customer;
        if (auth()->user()->isCustomer() && $this->takenByOthers($owner)) {
            $this->addError('domain', __('Bu alan adı ya da üst alan adı başka bir hesaba ait.'));

            return;
        }
        if ($refusal = $quotas->refusal($owner, 'sites')) {
            $this->agentError = $refusal;

            return;
        }
        if ($template !== null && ($refusal = $quotas->refusal($owner, 'laravel_apps')
            ?? (in_array($template->database, ['mysql', 'postgresql'], true) ? $quotas->refusal($owner, 'databases') : null))) {
            $this->agentError = $refusal;

            return;
        }

        if (! $agent->ping()) {
            $this->agentError = __('Sunucu ajanına ulaşılamıyor. Sunucuda "systemctl status serfex-agent" ile kontrol edin.');

            return;
        }

        $only = $this->address === 'only';
        $site = $creator->create($this->domain, $this->systemUser, $this->phpVersion, ! $only, $customer, $owner, $only ? Site::PREFER_BARE : $this->address);

        // With an application: it is installed once the site is ready, and
        // its page shows that live.
        if ($template !== null) {
            $app = $this->startTemplateInstall($template, $site->load('systemUser.customer'));
            session()->flash('toast', __(':domain oluşturuluyor; ardından :template kurulacak.', ['domain' => $site->domain, 'template' => $template->name]));
            $this->redirectRoute('laravel.show', $app, navigate: true);

            return;
        }

        $this->creating = false;
        $this->dispatch('toast',
            message: __(':domain oluşturuluyor.', ['domain' => $site->domain]),
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
        return self::zoneTakenByOthers($this->domain, $customer);
    }

    /**
     * The same, for $domain (the panel API asks it too).
     */
    public static function zoneTakenByOthers(string $domain, Customer $customer): bool
    {
        $labels = explode('.', $domain);
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
        $this->dispatch('toast', message: __(':domain geri alınıyor.', ['domain' => $site->domain]));
    }

    /**
     * Undo for "Siteyi sil": restores everything in the backup taken just
     * before the deletion, creating the site again.
     */
    public function restoreDeleted(int $itemId, Restores $restores): void
    {
        $item = BackupItem::visibleTo(auth()->user())->with('backup')->where('status', 'succeeded')
            ->whereHas('backup', fn ($q) => $q->where('trigger', 'delete'))->find($itemId);
        if ($item === null) {
            return;
        }
        $result = $restores->start($item, $item->restorable(), false, auth()->user());
        $this->dispatch('toast', message: is_string($result) ? $result : __(':domain yedekten geri yükleniyor; ilerlemesi Yedekler sayfasında.', ['domain' => $item->name]));
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
            ->when($this->sort === 'traffic', fn ($q) => $q->orderByRaw('coalesce((select bytes from site_traffic where site_traffic.site_id = sites.id and month = ?), 0) desc',
                [now(config('serfex.timezone'))->startOfMonth()->toDateString()]))
            ->orderBy('domain')
            ->paginate(25);
        $traffic = SiteTraffic::whereIn('site_id', $sites->pluck('id'))->where('month', now(config('serfex.timezone'))->startOfMonth()->toDateString())->pluck('bytes', 'site_id');

        return view('livewire.sites.index', [
            'sites' => $sites,
            'traffic' => $traffic,
            'customers' => $user->is_admin && $this->creating ? Customer::orderByRaw('lower(coalesce(company, name))')->get(['id', 'company', 'name']) : collect(),
            'templates' => $this->creating ? $this->installableTemplates() : collect(),
            'picked' => $picked = ($this->creating ? $this->pickedTemplate() : null),
            'summary' => $picked ? $this->templateSummary($picked, null) : '',
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
            ? __('Sunucu ajanına ulaşılamıyor. Sunucuda "systemctl status serfex-agent" ile kontrol edin.')
            : __('Sunucu ajanı hata verdi: :reason', ['reason' => $e->reason]);
    }
}
