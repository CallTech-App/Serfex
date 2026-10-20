<?php

namespace App\Livewire\Settings;

use App\Models\ApiKey;
use App\Services\Settings\ApiKeys;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Araçlar ve Ayarlar → API anahtarları: keys for the panel API, shown
 * once, revocable with undo.
 */
#[Layout('components.layouts.app')]
#[Title('API anahtarları · Servik')]
class ApiKeyIndex extends Component
{
    /** Expiry choices: days => label ("" for never). */
    public const EXPIRY = ['30' => '30 gün', '90' => '90 gün', '365' => '1 yıl', '' => 'Süresiz'];

    public bool $creating = false;

    public string $name = '';

    public string $scope = 'read';

    public string $expires = '365';

    public string $ips = '';

    public ?int $revokingId = null;

    /** @var array{name: string, key: string}|null the new key, shown once */
    public ?array $created = null;

    public function openCreate(): void
    {
        $this->resetValidation();
        $this->name = $this->ips = '';
        $this->scope = 'read';
        $this->expires = '365';
        $this->creating = true;
    }

    public function create(ApiKeys $keys): void
    {
        $this->name = trim($this->name);
        $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'scope' => ['required', Rule::in(array_keys(ApiKey::SCOPES))],
            'expires' => [Rule::in(array_keys(self::EXPIRY))],
        ], [], ['name' => 'ad', 'scope' => 'yetki', 'expires' => __('geçerlilik')]);
        $ips = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,;]+/', $this->ips) ?: []))));
        foreach ($ips as $ip) {
            if (! $this->validRange($ip)) {
                $this->addError('ips', __(':ip geçerli bir IP adresi ya da aralık değil.', ['ip' => $ip]));

                return;
            }
        }
        if (count($ips) > 20) {
            $this->addError('ips', __('En fazla 20 adres girin.'));

            return;
        }
        [$key, $plain] = $keys->create(auth()->user(), $this->name, $this->scope, $ips,
            $this->expires === '' ? null : now()->addDays((int) $this->expires));
        $this->creating = false;
        $this->created = ['name' => $key->name, 'key' => $plain];
        $this->dispatch('toast', message: __(':name API anahtarı oluşturuldu.', ['name' => $key->name]), source: $this->getId(), undo: ['method' => 'revokeNow', 'args' => [$key->id]]);
    }

    /**
     * Undo of "Anahtar oluştur": the key stops working at once.
     */
    public function revokeNow(ApiKeys $keys, int $id): void
    {
        $key = ApiKey::where('kind', ApiKey::PANEL)->whereNull('revoked_at')->find($id);
        if ($key === null) {
            return;
        }
        $keys->revoke($key);
        $this->dispatch('toast', message: __(':name API anahtarı geri alındı.', ['name' => $key->name]));
    }

    public function confirmRevoke(int $id): void
    {
        $this->revokingId = ApiKey::where('kind', ApiKey::PANEL)->whereNull('revoked_at')->find($id)?->id;
    }

    public function revoke(ApiKeys $keys): void
    {
        $key = $this->revokingId ? ApiKey::where('kind', ApiKey::PANEL)->whereNull('revoked_at')->find($this->revokingId) : null;
        $this->revokingId = null;
        if ($key === null) {
            return;
        }
        $keys->revoke($key);
        $this->dispatch('toast', message: __(':name API anahtarı iptal edildi.', ['name' => $key->name]), source: $this->getId(), undo: ['method' => 'restore', 'args' => [$key->id]]);
    }

    public function restore(ApiKeys $keys, int $id): void
    {
        $key = ApiKey::where('kind', ApiKey::PANEL)->find($id);
        if ($key !== null && $keys->restore($key)) {
            $this->dispatch('toast', message: __(':name API anahtarı yeniden geçerli.', ['name' => $key->name]));
        }
    }

    public function render(): View
    {
        return view('livewire.settings.api-keys', [
            // Command line keys are their people's own (Hesap güvenliği).
            'keys' => ApiKey::with('user')->where('kind', ApiKey::PANEL)
                ->where(fn ($q) => $q->whereNull('revoked_at')->orWhere('revoked_at', '>', now()->subDays(30)))
                ->orderByRaw('revoked_at is not null')->latest()->get(),
            'baseUrl' => url('/api/v1'),
            'installUrl' => route('cli.install'),
        ]);
    }

    private function validRange(string $value): bool
    {
        [$ip, $bits] = array_pad(explode('/', $value, 2), 2, null);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if ($bits === null) {
            return true;
        }
        $max = str_contains($ip, ':') ? 128 : 32;

        return ctype_digit($bits) && (int) $bits >= 8 && (int) $bits <= $max && IpUtils::checkIp($ip, $value);
    }
}
