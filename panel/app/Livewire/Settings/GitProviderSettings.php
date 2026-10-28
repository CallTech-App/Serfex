<?php

namespace App\Livewire\Settings;

use App\Models\GitAccount;
use App\Models\Setting;
use App\Services\Git\GitProviders;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Araçlar ve Ayarlar → Git sağlayıcıları: the OAuth applications that let
 * people connect their GitHub, GitLab and Bitbucket accounts and pick
 * repositories from a list. Each host needs an application registered
 * there with this panel's callback address.
 */
#[Layout('components.layouts.app')]
#[Title('Git sağlayıcıları · Serfex')]
class GitProviderSettings extends Component
{
    /** @var array<string, array{client_id: string, client_secret: string, url: string}> */
    public array $form = [];

    /** The host whose form is open, or null. */
    public ?string $editing = null;

    public ?string $turningOff = null;

    public function mount(GitProviders $providers): void
    {
        $this->loadForm($providers);
    }

    private function loadForm(GitProviders $providers): void
    {
        foreach ($providers->settings() as $key => $s) {
            $this->form[$key] = ['client_id' => $s['client_id'], 'client_secret' => '', 'url' => $s['url']];
        }
    }

    public function edit(string $key, GitProviders $providers): void
    {
        if (! isset(GitProviders::CLASSES[$key])) {
            return;
        }
        $this->resetValidation();
        $this->loadForm($providers);
        $this->editing = $key;
    }

    public function save(GitProviders $providers): void
    {
        $key = $this->editing;
        if ($key === null || ! isset(GitProviders::CLASSES[$key])) {
            return;
        }
        $saved = $providers->settings()[$key];
        $this->form[$key] = array_map(fn ($v) => trim((string) $v), $this->form[$key]);
        $this->validate([
            "form.{$key}.client_id" => ['required', 'max:200', 'regex:~^\S+$~'],
            "form.{$key}.client_secret" => [$saved['client_secret'] === null ? 'required' : 'nullable', 'max:500', 'regex:~^\S*$~'],
            "form.{$key}.url" => $key === 'gitlab' ? ['required', 'max:200', 'url:https', 'regex:~^https://[A-Za-z0-9.-]+(:[0-9]+)?/?$~'] : ['nullable'],
        ], [
            "form.{$key}.url.regex" => __('Yalnızca sunucunun adresini yazın, örneğin https://gitlab.ornek.com.tr.'),
        ], [
            "form.{$key}.client_id" => __('uygulama kimliği'),
            "form.{$key}.client_secret" => __('gizli anahtar'),
            "form.{$key}.url" => __('sunucu adresi'),
        ]);
        $providers->save($key, [
            'client_id' => $this->form[$key]['client_id'],
            'client_secret' => $this->form[$key]['client_secret'] !== '' ? $this->form[$key]['client_secret'] : null,
            'url' => $this->form[$key]['url'],
        ]);
        $this->editing = null;
        $this->form[$key]['client_secret'] = '';
        $this->dispatch('toast',
            message: __(':provider bağlantısı kaydedildi; kullanıcılar hesaplarını bağlayabilir.', ['provider' => GitProviders::LABELS[$key]]),
            source: $this->getId(),
            undo: ['method' => 'restore', 'args' => [$key, $saved]],
        );
    }

    /**
     * Turns a host off: its saved application is forgotten. Connected
     * accounts stay, unused until it is turned on again.
     */
    public function turnOff(GitProviders $providers): void
    {
        $key = $this->turningOff;
        $this->turningOff = null;
        if ($key === null || ! isset(GitProviders::CLASSES[$key])) {
            return;
        }
        $saved = $providers->settings()[$key];
        $providers->restore($key, ['client_id' => '', 'client_secret' => null, 'url' => $saved['url']]);
        $this->loadForm($providers);
        $this->dispatch('toast',
            message: __(':provider bağlantısı kapatıldı.', ['provider' => GitProviders::LABELS[$key]]),
            source: $this->getId(),
            undo: ['method' => 'restore', 'args' => [$key, $saved]],
        );
    }

    /**
     * Undo: the host's settings as they were before.
     *
     * @param  array{client_id: string, client_secret: ?string, url: string}  $settings
     */
    public function restore(string $key, array $settings, GitProviders $providers): void
    {
        if (! isset(GitProviders::CLASSES[$key])) {
            return;
        }
        $providers->restore($key, $settings);
        $this->loadForm($providers);
        $this->dispatch('toast', message: __(':provider bağlantısındaki değişiklik geri alındı.', ['provider' => GitProviders::LABELS[$key]]));
    }

    public function render(GitProviders $providers): View
    {
        $settings = $providers->settings();
        $accounts = GitAccount::query()->selectRaw('provider, count(*) as total')->groupBy('provider')->pluck('total', 'provider');

        return view('livewire.settings.git-providers', [
            'rows' => collect(GitProviders::CLASSES)->map(function ($class, $key) use ($providers, $settings, $accounts) {
                $on = $providers->isConfigured($key);

                return [
                    'label' => GitProviders::LABELS[$key],
                    'on' => $on,
                    'client_id' => $settings[$key]['client_id'],
                    'has_secret' => $settings[$key]['client_secret'] !== null,
                    'url' => $settings[$key]['url'],
                    'callback' => $providers->callbackUrl($key),
                    'register' => $providers->make($key, ['client_id' => '', 'client_secret' => '', 'url' => $settings[$key]['url']])->registerUrl(),
                    'accounts' => (int) ($accounts[$key] ?? 0),
                ];
            }),
            'panelBase' => $providers->panelBase(),
            'named' => (bool) Setting::get('panel.hostname'),
        ]);
    }
}
