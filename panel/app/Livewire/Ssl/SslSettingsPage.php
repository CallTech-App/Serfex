<?php

namespace App\Livewire\Ssl;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Server-wide SSL settings: the Let's Encrypt account address and
 * automatic certificates for new sites. The panel's own address and
 * certificate are in Araçlar ve Ayarlar → Genel.
 */
#[Layout('components.layouts.app')]
#[Title('SSL ayarları · Servik')]
class SslSettingsPage extends Component
{
    public string $email = '';

    public bool $auto = true;

    public function mount(): void
    {
        $this->email = (string) Setting::get('ssl.email', '');
        $this->auto = (bool) Setting::get('ssl.auto', true);
    }

    public function save(): void
    {
        $this->email = trim($this->email);
        $this->validate(['email' => ['nullable', 'email:rfc', 'max:254']], [], ['email' => 'e-posta']);

        $previous = $this->current();
        Setting::put('ssl.email', $this->email ?: null);
        Setting::put('ssl.auto', $this->auto);

        $this->dispatch('toast', message: 'SSL ayarları kaydedildi.', source: $this->getId(), undo: ['method' => 'undoSave', 'args' => [$previous]]);
    }

    /**
     * @param  array<string, mixed>  $previous
     */
    public function undoSave(array $previous): void
    {
        $email = $previous['ssl.email'] ?? null;
        Setting::put('ssl.email', is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null);
        Setting::put('ssl.auto', (bool) ($previous['ssl.auto'] ?? true));
        $this->mount();
        $this->resetValidation();
        $this->dispatch('toast', message: 'Değişiklik geri alındı.');
    }

    public function render(): View
    {
        return view('livewire.ssl.settings', [
            'panelHostname' => (string) Setting::get('panel.hostname', ''),
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
        ];
    }
}
