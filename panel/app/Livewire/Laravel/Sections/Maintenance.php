<?php

namespace App\Livewire\Laravel\Sections;

use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Lazy;

/**
 * php artisan down / up, with a secret link that lets the team in during
 * maintenance.
 */
#[Lazy]
class Maintenance extends Section
{
    public string $secret = '';

    public int $retry = 60;

    public function mount(int $appId): void
    {
        parent::mount($appId);
        $app = $this->app();
        $this->secret = $app->maintenance_secret ?? self::newSecret();
        $this->retry = $app->maintenance_retry;
    }

    public function regenerate(): void
    {
        $this->secret = self::newSecret();
    }

    public function down(AgentClient $agent): void
    {
        $this->validate([
            'secret' => ['required', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'retry' => ['required', 'integer', 'between:0,86400'],
        ], ['secret.regex' => 'Yalnızca harf, rakam, - ve _ kullanın.'], ['secret' => 'gizli anahtar', 'retry' => 'tekrar deneme süresi']);
        $this->set($agent, true);
    }

    public function up(AgentClient $agent): void
    {
        $this->set($agent, false);
    }

    private function set(AgentClient $agent, bool $down): void
    {
        $app = $this->app();
        try {
            $result = $agent->call('laravel.maintenance', $app->agentApp() + ['down' => $down, 'secret' => $this->secret, 'retry' => $this->retry], config('servik.agent.job_timeout'));
        } catch (AgentException $e) {
            $this->agentError = self::agentMessage($e);

            return;
        }
        if (($result['exit_code'] ?? 1) !== 0) {
            $this->agentError = 'Komut başarısız oldu: '.trim((string) ($result['output'] ?? ''));

            return;
        }
        $this->agentError = null;
        $app->update(['maintenance_secret' => $this->secret, 'maintenance_retry' => $this->retry]);
        $this->dispatch('toast',
            message: $down ? 'Uygulama bakım modunda.' : 'Uygulama yeniden açıldı.',
            source: $this->getId(),
            undo: ['method' => $down ? 'up' : 'down', 'args' => []],
        );
    }

    public function render(): View
    {
        $app = $this->app();
        $status = $this->agent('laravel.status', $app->agentApp(), []);
        $site = $app->site;
        $down = $status['maintenance'] ?? null;
        // While down, the link is the one the application was put down with.
        $secret = $down['secret'] ?? $this->secret;

        return view('livewire.laravel.sections.maintenance', [
            'app' => $app,
            'down' => $down,
            'link' => $secret === '' ? null : ($site->hasHttps() ? 'https://' : 'http://').$site->domain.'/'.$secret,
        ]);
    }

    private static function newSecret(): string
    {
        return Str::lower(Str::random(24));
    }
}
