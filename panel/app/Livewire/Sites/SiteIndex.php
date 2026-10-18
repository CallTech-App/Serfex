<?php

namespace App\Livewire\Sites;

use App\Enums\SiteStatus;
use App\Jobs\DeleteSite;
use App\Jobs\ProvisionSite;
use App\Models\Site;
use App\Models\SystemUser;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentUnavailableException;
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
class SiteIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public bool $creating = false;

    public string $domain = '';

    public string $systemUser = '';

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

    public function openCreate(AgentClient $agent): void
    {
        $this->reset('domain', 'systemUser', 'phpVersion', 'wwwAlias', 'agentError', 'userEdited');
        $this->resetValidation();
        $this->creating = true;

        try {
            $this->phpVersions = array_values($agent->call('php.versions') ?? []);
        } catch (AgentException $e) {
            $this->phpVersions = [];
            $this->agentError = $this->agentMessage($e);

            return;
        }

        if ($this->phpVersions === []) {
            $this->agentError = 'Sunucuda PHP-FPM kurulu değil. Önce bir PHP sürümü kurun.';

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

    public function create(AgentClient $agent, Zones $zones, MailDomains $mail): void
    {
        $this->domain = Hostnames::normalize($this->domain);
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

        if (! $agent->ping()) {
            $this->agentError = 'Sunucu ajanına ulaşılamıyor. Sunucuda "systemctl status servik-agent" ile kontrol edin.';

            return;
        }

        $site = DB::transaction(function () {
            $user = SystemUser::firstOrCreate(['name' => $this->systemUser]);

            return $user->sites()->create([
                'domain' => $this->domain,
                'aliases' => $this->wwwAlias && ! str_starts_with($this->domain, 'www.') ? ['www.'.$this->domain] : [],
                'php_version' => $this->phpVersion,
                'status' => SiteStatus::Provisioning,
            ]);
        });

        ProvisionSite::dispatch($site);
        // DNS does not wait for the web server: the zone is published even
        // if provisioning fails and is retried.
        $zones->forSite($site);
        $mail->forSite($site);

        $this->creating = false;
        $this->dispatch('toast',
            message: "{$site->domain} oluşturuluyor.",
            source: $this->getId(),
            undo: ['method' => 'undoCreate', 'args' => [$site->id]],
        );
    }

    /**
     * Undo for "Site ekle": removes the site again, its account when the
     * site was the only one using it, and the DNS zone created with it.
     */
    public function undoCreate(int $id): void
    {
        $site = Site::find($id);
        if ($site === null || $site->status === SiteStatus::Deleting) {
            return;
        }
        $site->update(['status' => SiteStatus::Deleting]);
        DeleteSite::dispatch($site, removeUser: true, removeZone: true, removeMail: true);
        $this->dispatch('toast', message: "{$site->domain} geri alınıyor.");
    }

    public function render(): View
    {
        $sites = Site::query()
            ->with('systemUser')
            ->when($this->search !== '', fn ($q) => $q->where(function ($q) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($this->search)).'%';
                $q->where('domain', 'like', $term)
                    ->orWhereHas('systemUser', fn ($u) => $u->where('name', 'like', $term));
            }))
            ->orderBy('domain')
            ->paginate(25);

        return view('livewire.sites.index', [
            'sites' => $sites,
            'busy' => $sites->getCollection()->contains(fn (Site $s) => $s->status->isBusy()),
        ]);
    }

    private function agentMessage(AgentException $e): string
    {
        return $e instanceof AgentUnavailableException
            ? 'Sunucu ajanına ulaşılamıyor. Sunucuda "systemctl status servik-agent" ile kontrol edin.'
            : 'Sunucu ajanı hata verdi: '.$e->reason;
    }
}
