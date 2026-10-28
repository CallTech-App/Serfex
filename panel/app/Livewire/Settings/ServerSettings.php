<?php

namespace App\Livewire\Settings;

use App\Jobs\EnableDiskQuotas;
use App\Models\Setting;
use App\Services\Agent\AgentException;
use App\Services\Customers\DiskQuotas;
use App\Services\Settings\PanelSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Araçlar ve Ayarlar → Sunucu: the server's name (hostnamectl), how long
 * rotated logs are kept, whether customers' disk limits are enforced
 * (DiskQuotas), its addresses, operating system and hardware.
 */
#[Layout('components.layouts.app')]
#[Title('Sunucu · Serfex')]
class ServerSettings extends Component
{
    /** Days a rotated log can be kept for. */
    public const LOG_DAYS = [7, 14, 30, 60, 90, 180, 365];

    public string $hostname = '';

    public int $logDays = 14;

    public ?string $agentError = null;

    public bool $confirmingQuota = false;

    public function mount(PanelSettings $settings, DiskQuotas $quotas): void
    {
        try {
            $quotas->refreshStatus();
        } catch (AgentException) {
            // The last known status is shown.
        }
        try {
            $this->hostname = (string) ($settings->server()['hostname'] ?? '');
            $this->logDays = $settings->logKeepDays();
        } catch (AgentException $e) {
            $this->agentError = __('Sunucu ajanına ulaşılamadı: :reason', ['reason' => $e->reason]);
        }
    }

    public function save(PanelSettings $settings): void
    {
        $this->hostname = strtolower(trim($this->hostname));
        $this->validate([
            'hostname' => ['required', 'max:253', 'regex:/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/'],
        ], ['hostname.regex' => __('Geçerli bir sunucu adı girin, örneğin srv-ist-01 ya da srv-ist-01.ornek.com.tr.')], ['hostname' => __('sunucu adı')]);
        try {
            $previous = (string) ($settings->server()['hostname'] ?? '');
            $previousDays = $settings->logKeepDays();
        } catch (AgentException $e) {
            $this->addError('hostname', __('Sunucu ajanına ulaşılamadı: :reason', ['reason' => $e->reason]));

            return;
        }
        // A value set some other way may stay; a new one comes from the list.
        if ($this->logDays !== $previousDays) {
            $this->validate(['logDays' => ['required', 'integer', Rule::in(self::LOG_DAYS)]], [], ['logDays' => __('saklama süresi')]);
        }
        if ($previous === $this->hostname && $previousDays === $this->logDays) {
            $this->dispatch('toast', message: __('Değişiklik yok.'));

            return;
        }
        if ($previous !== $this->hostname) {
            try {
                $settings->setHostname($this->hostname);
            } catch (AgentException $e) {
                $this->addError('hostname', __('Sunucu adı değiştirilemedi: :reason', ['reason' => $e->reason]));

                return;
            }
            $this->dispatch('toast', message: __('Sunucunun adı :hostname oldu.', ['hostname' => $this->hostname]), source: $this->getId(), undo: ['method' => 'undoHostname', 'args' => [$previous]]);
        }
        if ($previousDays !== $this->logDays) {
            try {
                $settings->setLogKeepDays($this->logDays);
            } catch (AgentException $e) {
                $this->addError('logDays', __('Saklama süresi değiştirilemedi: :reason', ['reason' => $e->reason]));

                return;
            }
            $this->dispatch('toast', message: __('Günlükler artık :days gün saklanacak.', ['days' => $this->logDays]), source: $this->getId(), undo: ['method' => 'undoLogDays', 'args' => [$previousDays]]);
        }
    }

    public function undoHostname(PanelSettings $settings, string $previous): void
    {
        $previous = strtolower(trim($previous));
        if ($previous === '' || ! preg_match('/^[a-z0-9.-]{1,253}$/', $previous)) {
            return;
        }
        try {
            $settings->setHostname($previous);
        } catch (AgentException $e) {
            $this->dispatch('toast', message: __('Sunucu adı geri alınamadı: :reason', ['reason' => $e->reason]));

            return;
        }
        $this->hostname = $previous;
        $this->resetValidation();
        $this->dispatch('toast', message: __('Sunucunun adı yeniden :previous oldu.', ['previous' => $previous]));
    }

    public function undoLogDays(PanelSettings $settings, int $previous): void
    {
        if ($previous < 1 || $previous > 365) {
            return;
        }
        try {
            $settings->setLogKeepDays($previous);
        } catch (AgentException $e) {
            $this->dispatch('toast', message: __('Saklama süresi geri alınamadı: :reason', ['reason' => $e->reason]));

            return;
        }
        $this->logDays = $previous;
        $this->resetValidation();
        $this->dispatch('toast', message: __('Günlükler yeniden :days gün saklanacak.', ['days' => $previous]));
    }

    public function openQuota(): void
    {
        $this->confirmingQuota = true;
    }

    /**
     * Turns disk quotas on, in the background (packages, fstab, counting).
     */
    public function enableQuota(DiskQuotas $quotas): void
    {
        $this->confirmingQuota = false;
        if ($quotas->enabling()) {
            return;
        }
        Setting::put(DiskQuotas::ENABLING, now()->toIso8601String());
        Setting::put(DiskQuotas::ERROR, null);
        EnableDiskQuotas::dispatch();
    }

    /**
     * Enforces disk limits (true) or keeps them warnings only.
     */
    public function enforceQuota(DiskQuotas $quotas, bool $enforce): void
    {
        try {
            $quotas->setWanted($enforce);
        } catch (AgentException $e) {
            $this->dispatch('toast', message: __('Disk sınırları değiştirilemedi: :reason', ['reason' => $e->reason]));

            return;
        }
        $this->dispatch('toast', message: $enforce ? __('Disk sınırları artık uygulanıyor.') : __('Disk sınırı artık yalnızca uyarı.'), source: $this->getId(),
            undo: ['method' => 'enforceQuota', 'args' => [! $enforce]]);
    }

    public function checkQuota(DiskQuotas $quotas): void
    {
        try {
            $quotas->refreshStatus();
        } catch (AgentException $e) {
            $this->dispatch('toast', message: __('Sunucu ajanına ulaşılamadı: :reason', ['reason' => $e->reason]));
        }
    }

    public function render(PanelSettings $settings): View
    {
        try {
            $server = $settings->server();
        } catch (AgentException) {
            $server = null;
        }

        // A value set some other way is kept among the choices.
        $options = self::LOG_DAYS;
        if (! in_array($this->logDays, $options, true) && $this->logDays >= 1 && $this->logDays <= 365) {
            $options[] = $this->logDays;
            sort($options);
        }

        $quotas = app(DiskQuotas::class);

        return view('livewire.settings.server', ['server' => $server, 'logDayOptions' => $options, 'quota' => [
            'status' => $quotas->status(),
            'wanted' => $quotas->wanted(),
            'enabling' => $quotas->enabling(),
            'broken' => $quotas->broken(),
            'error' => Setting::get(DiskQuotas::ERROR),
        ]]);
    }
}
