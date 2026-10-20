<?php

namespace App\Livewire\Services;

use App\Services\System\SystemServices;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One system service (administrators only): its state, the actions of the
 * list plus reload and the boot setting, its newest journal lines and,
 * for Nginx, the database server and Postfix, the settings worth changing.
 * Every change can be undone from its toast.
 */
#[Layout('components.layouts.app')]
class ServiceShow extends Component
{
    public const TABS = ['gunluk' => 'Günlük', 'ayarlar' => 'Ayarlar'];

    public string $unit;

    #[Url(as: 'sekme', except: 'gunluk')]
    public string $tab = 'gunluk';

    public int $lines = 100;

    /** @var array<string, string> the settings form */
    public array $settings = [];

    /** @var array<string, mixed> the settings as the server has them */
    public array $current = [];

    public ?string $confirming = null;

    public ?string $refused = null;

    public ?string $settingsError = null;

    public function mount(string $unit, SystemServices $services): void
    {
        $this->unit = $unit;
        $error = null;
        $service = $services->find($unit, $error);
        if ($service === null && $services->list() !== null) {
            abort(404);
        }
        if (! array_key_exists($this->tab, self::TABS) || ($this->tab === 'ayarlar' && empty($service['settings']))) {
            $this->tab = 'gunluk';
        }
        if (! empty($service['settings'])) {
            $this->loadSettings($services, $service['settings']);
        }
    }

    /**
     * Start, stop, restart, reload, or the boot setting; a stop or restart
     * that interrupts something customers use asks first.
     */
    public function act(string $action, SystemServices $services): void
    {
        $this->refused = null;
        if (! in_array($action, ['start', 'stop', 'restart', 'reload', 'enable', 'disable'], true)) {
            return;
        }
        if ($action === 'stop' || ($action === 'restart' && $services->affects($this->unit) !== null)) {
            $this->confirming = $action;

            return;
        }
        $this->run($action, $services);
    }

    public function confirm(SystemServices $services): void
    {
        $action = $this->confirming;
        $this->confirming = null;
        if ($action !== null) {
            $this->run($action, $services);
        }
    }

    public function undo(string $action, SystemServices $services): void
    {
        if (in_array($action, ['start', 'stop', 'enable', 'disable'], true)) {
            $this->run($action, $services, false);
        }
    }

    public function moreLines(): void
    {
        $this->lines = min(1000, $this->lines * 2);
    }

    public function saveSettings(SystemServices $services): void
    {
        $service = $services->find($this->unit);
        $section = $service['settings'] ?? null;
        if ($section === null || ! isset(SystemServices::SETTINGS[$section])) {
            return;
        }
        $values = [];
        $rules = [];
        $names = [];
        foreach (SystemServices::SETTINGS[$section] as $key => [$label, $unit, $min, $max]) {
            $rules["settings.{$key}"] = $key === 'worker_processes'
                ? ['required', 'regex:/^(auto|[1-9]\d{0,2})$/']
                : ['required', 'integer', "between:{$min},{$max}"];
            $names["settings.{$key}"] = mb_strtolower($label);
        }
        $this->validate($rules, ['settings.worker_processes.regex' => __('"auto" ya da 1-999 arasında bir sayı girin.')], $names);
        foreach (SystemServices::SETTINGS[$section] as $key => $_) {
            $values[$key] = $key === 'worker_processes' ? trim($this->settings[$key]) : (int) $this->settings[$key];
        }
        $before = $this->current;
        if ($values == $before) {
            $this->dispatch('toast', message: __('Değişiklik yok.'));

            return;
        }
        $result = $services->applySettings($this->unit, $section, $values);
        if (is_string($result)) {
            $this->settingsError = __('Ayarlar uygulanamadı, hizmet önceki ayarlarıyla çalışıyor: :result', ['result' => $result]);

            return;
        }
        $this->settingsError = null;
        $this->useSettings($result);
        $this->dispatch('toast',
            message: __(':name yeni ayarlarla çalışıyor.', ['name' => __($service['name'] ?? $this->unit)]),
            source: $this->getId(),
            undo: ['method' => 'undoSettings', 'args' => [$before]],
        );
    }

    /**
     * @param  array<string, mixed>  $before
     */
    public function undoSettings(array $before, SystemServices $services): void
    {
        $section = $services->find($this->unit)['settings'] ?? null;
        if ($section === null || ! isset(SystemServices::SETTINGS[$section])) {
            return;
        }
        $result = $services->applySettings($this->unit, $section, array_intersect_key($before, SystemServices::SETTINGS[$section]));
        if (is_string($result)) {
            $this->settingsError = __('Önceki ayarlar geri yüklenemedi: :result', ['result' => $result]);

            return;
        }
        $this->useSettings($result);
        $this->dispatch('toast', message: __('Önceki ayarlar geri yüklendi.'));
    }

    public function render(SystemServices $services): View
    {
        $error = null;
        $service = $services->find($this->unit, $error);
        $logs = null;
        if ($service !== null && $this->tab === 'gunluk') {
            $logs = $services->logs($this->unit, $this->lines);
        }

        return view('livewire.services.show', [
            'service' => $service,
            'agentError' => $service === null ? ($error ? __('Sunucu ajanı hata verdi: :error', ['error' => $error]) : __('Sunucu ajanına ulaşılamıyor. Sunucuda "systemctl status servik-agent" ile kontrol edin.')) : null,
            'logs' => $logs,
            'affects' => $services->affects($this->unit),
            'fields' => SystemServices::SETTINGS[$service['settings'] ?? ''] ?? [],
            'confirm' => $this->confirming && $service ? $services->confirmation($service, $this->confirming) : null,
        ])->title(__(':name · Hizmetler · Servik', ['name' => __($service['name'] ?? $this->unit)]));
    }

    private function loadSettings(SystemServices $services, string $section): void
    {
        $values = $services->settings($this->unit, $section);
        if (is_string($values)) {
            $this->settingsError = $values;

            return;
        }
        $this->useSettings($values);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function useSettings(array $values): void
    {
        $this->current = $values;
        $this->settings = array_map(fn ($v) => (string) $v, $values);
    }

    private function run(string $action, SystemServices $services, bool $offerUndo = true): void
    {
        $result = $services->act($this->unit, $action);
        if (is_string($result)) {
            $this->refused = $result;

            return;
        }
        $name = $result['name'] ?? $this->unit;
        $message = match ($action) {
            'start' => __(':name başlatıldı.', ['name' => $name]),
            'stop' => __(':name durduruldu.', ['name' => $name]),
            'reload' => __(':name ayarlarını yeniden yükledi.', ['name' => $name]),
            'enable' => __(':name sunucu açılınca başlayacak.', ['name' => $name]),
            'disable' => __(':name sunucu açılınca başlamayacak.', ['name' => $name]),
            default => __(':name yeniden başlatıldı.', ['name' => $name]),
        };
        if (in_array($action, ['start', 'restart'], true) && ($result['state'] ?? '') !== 'active') {
            $this->refused = __(':name çalışmıyor; ayrıntılar aşağıdaki günlükte.', ['name' => $name]);
        }
        $undo = $offerUndo ? ['start' => 'stop', 'stop' => 'start', 'enable' => 'disable', 'disable' => 'enable'][$action] ?? null : null;
        $this->dispatch('toast',
            message: $message,
            source: $this->getId(),
            undo: $undo ? ['method' => 'undo', 'args' => [$undo]] : null,
        );
    }
}
