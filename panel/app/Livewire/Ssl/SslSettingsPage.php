<?php

namespace App\Livewire\Ssl;

use App\Enums\CertificateStatus;
use App\Models\Certificate;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Services\Ssl\Certificates;
use App\Support\Hostnames;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Server-wide SSL settings: the Let's Encrypt account address, automatic
 * certificates for new sites, and the panel's own address and certificate.
 */
#[Layout('components.layouts.app')]
#[Title('SSL ayarları · Servik')]
class SslSettingsPage extends Component
{
    public string $email = '';

    public bool $auto = true;

    public string $hostname = '';

    public function mount(): void
    {
        $this->email = (string) Setting::get('ssl.email', '');
        $this->auto = (bool) Setting::get('ssl.auto', true);
        $this->hostname = (string) Setting::get('panel.hostname', '');
    }

    public function save(Certificates $certificates): void
    {
        $this->email = trim($this->email);
        $this->hostname = Hostnames::normalize($this->hostname);
        $this->validate([
            'email' => ['nullable', 'email:rfc', 'max:254'],
            'hostname' => [
                'nullable',
                fn ($attr, $value, $fail) => Hostnames::isValidDomain($value) ? null : $fail('Geçerli bir ad girin, örneğin panel.ornek.com.tr.'),
                fn ($attr, $value, $fail) => $this->usedBySite($value) ? $fail('Bu ad bir sitede kullanılıyor; panel için ayrı bir ad seçin, örneğin panel.'.$value.'.') : null,
            ],
        ], [], ['email' => 'e-posta', 'hostname' => 'panel adresi']);

        $previous = $this->current();
        Setting::put('ssl.email', $this->email ?: null);
        Setting::put('ssl.auto', $this->auto);
        Setting::put('panel.hostname', $this->hostname ?: null);

        // A new panel address gets its certificate right away; the DNS check
        // keeps Let's Encrypt out of it until the address points here.
        $issuing = $this->hostname !== '' && $this->hostname !== ($previous['panel.hostname'] ?? '') && ! $this->panelBusy();
        if ($issuing) {
            $certificates->issuePanel($this->hostname);
        }

        $this->dispatch('toast',
            message: 'SSL ayarları kaydedildi.'.($issuing ? ' Panel sertifikası alınıyor.' : ''),
            source: $this->getId(),
            undo: ['method' => 'undoSave', 'args' => [$previous]],
        );
    }

    /**
     * @param  array<string, mixed>  $previous
     */
    public function undoSave(array $previous): void
    {
        foreach (['ssl.email', 'ssl.auto', 'panel.hostname'] as $key) {
            Setting::put($key, $previous[$key] ?? null);
        }
        $this->mount();
        $this->resetValidation();
        $this->dispatch('toast', message: 'Değişiklik geri alındı.');
    }

    public function issuePanel(Certificates $certificates): void
    {
        $hostname = (string) Setting::get('panel.hostname', '');
        if ($hostname === '' || $this->panelBusy()) {
            return;
        }
        $certificates->issuePanel($hostname);
        $this->dispatch('toast', message: 'Panel sertifikası alınıyor.');
    }

    public function render(): View
    {
        return view('livewire.ssl.settings', [
            'panel' => Certificate::where('kind', 'panel')->first(),
            'savedHostname' => (string) Setting::get('panel.hostname', ''),
            'defaultEmail' => (string) User::query()->orderBy('id')->value('email'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function current(): array
    {
        return [
            'ssl.email' => Setting::get('ssl.email'),
            'ssl.auto' => Setting::get('ssl.auto', true),
            'panel.hostname' => Setting::get('panel.hostname'),
        ];
    }

    private function panelBusy(): bool
    {
        return Certificate::where('kind', 'panel')->where('status', CertificateStatus::Issuing)->exists();
    }

    private function usedBySite(string $hostname): bool
    {
        return Site::query()->get(['domain', 'aliases'])->contains(fn (Site $s) => in_array($hostname, $s->hostnames(), true));
    }
}
