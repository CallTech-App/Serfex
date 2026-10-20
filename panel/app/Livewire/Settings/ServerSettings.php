<?php

namespace App\Livewire\Settings;

use App\Services\Agent\AgentException;
use App\Services\Settings\PanelSettings;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Araçlar ve Ayarlar → Sunucu: the server's name (hostnamectl), its
 * addresses, operating system and hardware.
 */
#[Layout('components.layouts.app')]
#[Title('Sunucu · Servik')]
class ServerSettings extends Component
{
    public string $hostname = '';

    public ?string $agentError = null;

    public function mount(PanelSettings $settings): void
    {
        try {
            $this->hostname = (string) ($settings->server()['hostname'] ?? '');
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
            if ($previous === $this->hostname) {
                $this->dispatch('toast', message: __('Sunucu adı zaten :hostname.', ['hostname' => $this->hostname]));

                return;
            }
            $settings->setHostname($this->hostname);
        } catch (AgentException $e) {
            $this->addError('hostname', __('Sunucu adı değiştirilemedi: :reason', ['reason' => $e->reason]));

            return;
        }
        $this->dispatch('toast', message: __('Sunucunun adı :hostname oldu.', ['hostname' => $this->hostname]), source: $this->getId(), undo: ['method' => 'undoHostname', 'args' => [$previous]]);
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

    public function render(PanelSettings $settings): View
    {
        try {
            $server = $settings->server();
        } catch (AgentException) {
            $server = null;
        }

        return view('livewire.settings.server', ['server' => $server]);
    }
}
