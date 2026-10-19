<?php

namespace App\Livewire\Laravel\Sections;

use App\Models\LaravelProcess;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Laravel\LaravelApps;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Json;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Renderless;

/**
 * Long-running processes as systemd services: Horizon, Octane behind
 * Nginx, Reverb's WebSockets and the customer's own commands. Each one
 * restarts after a deploy.
 */
#[Lazy]
class Processes extends Section
{
    protected const VIEW_METHODS = ['openLog', 'closeLog', 'readProcessLog'];

    /** The packages each built-in process needs. */
    public const PACKAGES = ['horizon' => 'laravel/horizon', 'octane' => 'laravel/octane', 'reverb' => 'laravel/reverb'];

    public bool $editing = false;

    public ?int $processId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public bool $viewingLog = false;

    public ?string $logName = null;

    /**
     * Turn Horizon, Octane or Reverb on with its default settings, or open
     * the settings when it has some to choose (Octane).
     */
    public function enable(string $kind, LaravelApps $apps): void
    {
        if (! in_array($kind, ['horizon', 'octane', 'reverb'], true)) {
            return;
        }
        $app = $this->app();
        $process = $app->processes()->where('kind', $kind)->first();
        if ($process === null && $kind === 'octane') {
            $this->edit(null, 'octane');

            return;
        }
        $process ??= $app->processes()->create(['name' => $kind, 'kind' => $kind, 'port' => $kind === 'reverb' ? LaravelProcess::freePort() : null]);
        $this->toggleTo($process, true, $apps);
    }

    public function disable(int $id, LaravelApps $apps): void
    {
        $process = $this->process($id);
        if ($process !== null) {
            $this->toggleTo($process, false, $apps);
        }
    }

    /**
     * Opens the settings of a process; a new custom process when $id is null.
     */
    public function edit(?int $id = null, string $kind = 'custom'): void
    {
        $process = $id ? $this->process($id) : null;
        $kind = $process->kind ?? $kind;
        $this->processId = $process?->id;
        $this->form = [
            'kind' => $kind,
            'name' => $process->name ?? ($kind === 'custom' ? '' : $kind),
            'server' => $process->server ?? 'frankenphp',
            'workers' => $process->workers ?? 0,
            'max_requests' => $process->max_requests ?? 500,
            'command' => (string) ($process->command ?? ''),
        ];
        $this->resetValidation();
        $this->editing = true;
    }

    public function save(LaravelApps $apps): void
    {
        $app = $this->app();
        $kind = $this->form['kind'] ?? 'custom';
        if (! in_array($kind, ['octane', 'custom'], true)) {
            return;
        }
        $this->form['name'] = strtolower(trim((string) ($this->form['name'] ?? '')));
        $this->form['command'] = trim((string) ($this->form['command'] ?? ''));
        $this->validate($kind === 'octane' ? [
            'form.server' => ['required', Rule::in(array_keys(LaravelProcess::OCTANE_SERVERS))],
            'form.workers' => ['required', 'integer', 'between:0,64'],
            'form.max_requests' => ['required', 'integer', 'between:0,100000'],
        ] : [
            'form.name' => ['required', 'max:32', 'regex:/^[a-z0-9][a-z0-9-]*$/', Rule::notIn(['horizon', 'octane', 'reverb']),
                Rule::unique('laravel_processes', 'name')->where('laravel_app_id', $app->id)->ignore($this->processId)],
            'form.command' => ['required', 'max:1000', 'regex:~^(php|artisan|/[\w./+-]+|[\w.+-]+)(\s|$)~'],
        ], [
            'form.name.regex' => 'Küçük harf, rakam ve - kullanın.',
            'form.name.unique' => 'Bu adda bir süreç var.',
            'form.name.not_in' => 'Bu ad Horizon, Octane ve Reverb için ayrılmış.',
            'form.command.regex' => 'Komut bir program adıyla başlamalı, örneğin php artisan mqtt:listen.',
        ], ['form.name' => 'ad', 'form.command' => 'komut', 'form.workers' => 'işçi sayısı', 'form.max_requests' => 'istek sınırı', 'form.server' => 'sunucu']);

        $data = $kind === 'octane'
            ? ['server' => $this->form['server'], 'workers' => (int) $this->form['workers'], 'max_requests' => (int) $this->form['max_requests']]
            : ['name' => $this->form['name'], 'command' => $this->form['command']];
        $process = $this->processId ? $this->process($this->processId) : null;
        if ($process === null) {
            $process = $app->processes()->create($data + [
                'kind' => $kind,
                'name' => $kind === 'octane' ? 'octane' : $data['name'],
                'port' => $kind === 'octane' ? LaravelProcess::freePort() : null,
                'enabled' => true,
            ]);
        } else {
            $process->update($data);
        }
        $this->editing = false;
        $apps->syncProcesses($app, web: $kind === 'octane');
        $this->dispatch('toast', message: $process->label().' kaydedildi; sunucuya uygulanıyor.');
    }

    public function restart(int $id, AgentClient $agent): void
    {
        $process = $this->process($id);
        if ($process === null) {
            return;
        }
        try {
            $agent->call('laravel.process.restart', ['domain' => $this->app()->site->domain, 'name' => $process->name], config('servik.agent.job_timeout'));
        } catch (AgentException $e) {
            $this->agentError = self::agentMessage($e);

            return;
        }
        $this->dispatch('toast', message: $process->label().' yeniden başlatıldı.');
    }

    public function delete(int $id, LaravelApps $apps): void
    {
        $process = $this->process($id);
        if ($process === null || $process->kind !== 'custom') {
            return;
        }
        $copy = $process->only(['name', 'kind', 'enabled', 'command']);
        $process->delete();
        $apps->syncProcesses($this->app());
        $this->dispatch('toast',
            message: "{$copy['name']} süreci silindi.",
            source: $this->getId(),
            undo: ['method' => 'restore', 'args' => [$copy]],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function restore(array $data, LaravelApps $apps): void
    {
        $app = $this->app();
        if (($data['kind'] ?? '') === 'custom' && $app->processes()->where('name', $data['name'] ?? '')->doesntExist()) {
            $app->processes()->create(array_intersect_key($data, array_flip(['name', 'kind', 'enabled', 'command'])));
            $apps->syncProcesses($app);
        }
    }

    public function retrySync(LaravelApps $apps): void
    {
        $apps->syncProcesses($this->app(), web: true);
    }

    public function openLog(string $name): void
    {
        if ($this->app()->processes()->where('name', $name)->exists()) {
            $this->logName = $name;
            $this->viewingLog = true;
        }
    }

    public function closeLog(): void
    {
        $this->logName = null;
        $this->viewingLog = false;
    }

    /**
     * The output of a process from $offset on (-1: its last lines).
     *
     * @return array{data: string, offset: int, more: bool, done: bool, error?: string}|null
     */
    #[Json, Renderless]
    public function readProcessLog(string $name, int $offset = -1): ?array
    {
        $app = $this->app();
        if ($app === null || $app->processes()->where('name', $name)->doesntExist()) {
            return null;
        }
        try {
            $chunk = app(AgentClient::class)->call('laravel.process.log', ['domain' => $app->site->domain, 'name' => $name, 'offset' => $offset]);
        } catch (AgentException) {
            return ['data' => '', 'offset' => max(0, $offset), 'more' => false, 'done' => false, 'error' => 'Sunucu ajanına ulaşılamıyor.'];
        }
        $next = (int) ($chunk['offset'] ?? 0);

        return ['data' => (string) ($chunk['data'] ?? ''), 'offset' => $next, 'more' => $next < (int) ($chunk['size'] ?? 0), 'done' => false];
    }

    private function toggleTo(LaravelProcess $process, bool $enabled, LaravelApps $apps): void
    {
        $process->update(['enabled' => $enabled]);
        $app = $this->app();
        $apps->syncProcesses($app, web: in_array($process->kind, ['octane', 'reverb'], true));
        $this->dispatch('toast',
            message: $process->label().($enabled ? ' başlatılıyor.' : ' durduruluyor.'),
            source: $this->getId(),
            undo: ['method' => $enabled ? 'disable' : 'enableAgain', 'args' => [$process->id]],
        );
    }

    /**
     * Undo for "Kapat".
     */
    public function enableAgain(int $id, LaravelApps $apps): void
    {
        $process = $this->process($id);
        if ($process !== null) {
            $this->toggleTo($process, true, $apps);
        }
    }

    private function process(int $id): ?LaravelProcess
    {
        return LaravelProcess::where('laravel_app_id', $this->appId)->find($id);
    }

    public function render(): View
    {
        $app = $this->app()->load('processes');
        $status = $this->agent('laravel.status', $app->agentApp(), []);
        $states = collect($status['processes'] ?? [])->keyBy('name');
        $packages = (array) ($status['info']['packages'] ?? []);
        $builtIn = collect(['horizon', 'octane', 'reverb'])->mapWithKeys(fn ($kind) => [$kind => $app->processes->firstWhere('kind', $kind)]);
        $scheme = $app->site->hasHttps() ? 'https' : 'http';

        return view('livewire.laravel.sections.processes', [
            'app' => $app,
            'builtIn' => $builtIn,
            'custom' => $app->processes->where('kind', 'custom')->values(),
            'states' => $states,
            'packages' => $packages,
            'known' => $status !== [],
            'syncing' => $app->processes_state === 'syncing',
            'scheme' => $scheme,
        ]);
    }
}
