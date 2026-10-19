<?php

namespace App\Livewire\Laravel\Sections;

use App\Livewire\Laravel\GuardsAppCalls;
use App\Models\LaravelApp;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentUnavailableException;
use Livewire\Component;

/**
 * One section of a Laravel application's page, below its left menu.
 */
abstract class Section extends Component
{
    use GuardsAppCalls;

    /** What the account needs on the application to open the section. */
    protected const ABILITY = 'view';

    protected const VIEW_METHODS = [];

    protected const MANAGE_METHODS = [];

    public int $appId;

    /** Shown at the top of the section when the agent could not be used. */
    public ?string $agentError = null;

    public function mount(int $appId): void
    {
        $app = LaravelApp::findOrFail($appId);
        abort_unless(auth()->user()->canOnApp($app, static::ABILITY), 403);
        $this->appId = $appId;
    }

    /**
     * True when the current account may do $ability on the application.
     */
    protected function can(string $ability): bool
    {
        $app = LaravelApp::find($this->appId);

        return $app !== null && auth()->user()->canOnApp($app, $ability);
    }

    protected function app(): ?LaravelApp
    {
        return LaravelApp::with('site.systemUser', 'site.certificate')->find($this->appId);
    }

    /**
     * Calls the agent; on failure records why and returns $default.
     *
     * @param  array<string, mixed>  $params
     */
    protected function agent(string $method, array $params, mixed $default = null, ?float $timeout = null): mixed
    {
        try {
            return app(AgentClient::class)->call($method, $params, $timeout ?? config('servik.agent.job_timeout'));
        } catch (AgentException $e) {
            $this->agentError = self::agentMessage($e);

            return $default;
        }
    }

    public static function agentMessage(AgentException $e): string
    {
        return $e instanceof AgentUnavailableException
            ? 'Sunucu ajanına ulaşılamıyor. Sunucuda "systemctl status servik-agent" ile kontrol edin.'
            : $e->reason;
    }

    public function placeholder(): string
    {
        return <<<'HTML'
            <div class="flex items-center gap-2 px-5 py-6 text-[13.5px] text-muted"><svg class="size-4 animate-spin" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>Yükleniyor…</div>
            HTML;
    }
}
