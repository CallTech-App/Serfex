<?php

namespace App\Livewire\Dns;

use App\Jobs\SyncDnsZone;
use App\Models\CloudflareAccount;
use App\Models\Setting;
use App\Services\Cloudflare\CloudflareException;
use App\Services\Cloudflare\CloudflareSettings;
use App\Services\Cloudflare\CloudflareZones;
use App\Services\Dns\Zones;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Cloudflare accounts (API tokens) and the choices Serfex makes for zones
 * whose DNS is at Cloudflare.
 */
#[Layout('components.layouts.app')]
#[Title('Cloudflare · Serfex')]
class CloudflarePage extends Component
{
    public bool $editing = false;

    /** The account being edited; null while connecting a new one. */
    public ?int $accountId = null;

    public string $name = '';

    public string $token = '';

    /**
     * What a check of the token found, shown before it is saved.
     *
     * @var array{account: ?string, zones: int, checks: array<string, bool>}|null
     */
    public ?array $checked = null;

    public bool $proxySites = true;

    public bool $strictSsl = true;

    public bool $purgeAfterDeploy = false;

    public function mount(CloudflareSettings $settings): void
    {
        $this->proxySites = $settings->proxySites();
        $this->strictSsl = $settings->strictSsl();
        $this->purgeAfterDeploy = $settings->purgeAfterDeploy();
    }

    public function connect(): void
    {
        $this->reset('accountId', 'name', 'token', 'checked');
        $this->resetValidation();
        $this->editing = true;
    }

    public function edit(int $id): void
    {
        $account = CloudflareAccount::findOrFail($id);
        $this->reset('token', 'checked');
        $this->resetValidation();
        $this->accountId = $account->id;
        $this->name = $account->name;
        $this->editing = true;
    }

    public function updatedToken(): void
    {
        $this->checked = null;
    }

    /**
     * Try the token and show what it can do.
     */
    public function test(CloudflareZones $zones): bool
    {
        $this->validate(['token' => ['required', 'string', 'min:20', 'max:200', 'regex:/^[A-Za-z0-9_-]+$/']], [
            'token.regex' => __('Anahtar yalnızca harf, rakam, - ve _ içerir; Cloudflare\'den olduğu gibi kopyalayın.'),
            'token.min' => __('Anahtar eksik görünüyor; Cloudflare\'den olduğu gibi kopyalayın.'),
        ], ['token' => __('API anahtarı')]);
        $probe = new CloudflareAccount(['name' => 'probe', 'token' => trim($this->token)]);
        try {
            $zones->check($probe);
        } catch (CloudflareException $e) {
            $this->checked = null;
            $this->addError('token', $e->getMessage());

            return false;
        }
        $this->checked = ['account' => $probe->account_name, 'zones' => $probe->zones_count, 'checks' => $probe->checks ?? []];

        return true;
    }

    public function save(CloudflareZones $zones): void
    {
        $this->name = trim($this->name);
        $this->validate(['name' => ['required', 'string', 'max:60']], [], ['name' => __('ad')]);
        $account = $this->accountId !== null ? CloudflareAccount::findOrFail($this->accountId) : new CloudflareAccount;
        if ($account->exists && trim($this->token) === '') {
            $account->update(['name' => $this->name]);
            $this->editing = false;
            $this->dispatch('toast', message: __('Hesap kaydedildi.'));

            return;
        }
        if (! $this->test($zones)) {
            return;
        }
        $account->fill(['name' => $this->name, 'token' => trim($this->token)]);
        $zones->check($account);
        $account->save();
        $this->editing = false;
        $this->reset('token', 'checked');

        // A new token for an account whose zones failed: sync them again.
        foreach ($account->zones as $zone) {
            SyncDnsZone::dispatch($zone);
        }
        $this->dispatch('toast', message: __(':name bağlandı; :count alan adı görünüyor.', ['name' => $account->name, 'count' => $account->zones_count]));
    }

    public function refresh(int $id, CloudflareZones $zones): void
    {
        $account = CloudflareAccount::findOrFail($id);
        try {
            $zones->check($account);
        } catch (CloudflareException $e) {
            $this->dispatch('toast', message: $e->getMessage());

            return;
        }
        $this->dispatch('toast', message: __(':name denetlendi; :count alan adı görünüyor.', ['name' => $account->name, 'count' => $account->zones_count]));
    }

    public function remove(int $id): void
    {
        $account = CloudflareAccount::withCount('zones as panel_zones')->findOrFail($id);
        if ($account->panel_zones > 0) {
            $this->dispatch('toast', message: trans_choice(':count bölge bu hesabı kullanıyor; önce bu sunucuya geri alın.', $account->panel_zones, ['count' => $account->panel_zones]));

            return;
        }
        $token = Str::random(24);
        $saved = $account->only(['name', 'account_id', 'account_name', 'zones_count', 'checks', 'status', 'last_error']);
        $saved['token'] = Crypt::encryptString($account->token);
        Cache::put('cloudflare-account-undo:'.$token, $saved, Zones::UNDO_SECONDS);
        $account->delete();
        $this->dispatch('toast', message: __(':name kaldırıldı.', ['name' => $saved['name']]), source: $this->getId(), undo: ['method' => 'undoRemove', 'args' => [$token]]);
    }

    public function undoRemove(string $token): void
    {
        $saved = Cache::pull('cloudflare-account-undo:'.$token);
        if (! is_array($saved)) {
            return;
        }
        $saved['token'] = Crypt::decryptString($saved['token']);
        CloudflareAccount::create($saved);
        $this->dispatch('toast', message: __(':name geri getirildi.', ['name' => $saved['name']]));
    }

    public function saveDefaults(CloudflareSettings $settings): void
    {
        $settings->save(['proxy_sites' => $this->proxySites, 'strict_ssl' => $this->strictSsl, 'purge_after_deploy' => $this->purgeAfterDeploy]);
        $this->dispatch('toast', message: __('Cloudflare ayarları kaydedildi.'));
    }

    public function render(): View
    {
        return view('livewire.dns.cloudflare', [
            'accounts' => CloudflareAccount::withCount('zones as panel_zones')->orderBy('name')->get(),
            'ipsUpdated' => Setting::get('cloudflare.ips_updated_at'),
        ]);
    }
}
