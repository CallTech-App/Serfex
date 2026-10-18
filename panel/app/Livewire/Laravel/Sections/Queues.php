<?php

namespace App\Livewire\Laravel\Sections;

use App\Models\LaravelWorker;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Laravel\LaravelApps;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Lazy;

/**
 * Queue workers as systemd services: how many processes work which queues,
 * their limits, and the failed jobs.
 */
#[Lazy]
class Queues extends Section
{
    public const MAX_PROCESSES = 16;

    public bool $editing = false;

    public ?int $workerId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public bool $showingFailed = false;

    /** @var list<array<string, string>>|null */
    public ?array $failed = null;

    public function scale(int $id, int $by, LaravelApps $apps): void
    {
        $worker = $this->worker($id);
        if ($worker === null) {
            return;
        }
        $worker->update(['processes' => max(0, min(self::MAX_PROCESSES, $worker->processes + $by))]);
        $apps->syncProcesses($this->app());
    }

    public function openWorker(?int $id = null): void
    {
        $worker = $id ? $this->worker($id) : new LaravelWorker;
        $this->workerId = $worker?->id;
        $this->form = [
            'name' => $worker->name ?? ($this->app()->workers()->exists() ? '' : 'default'),
            'connection' => (string) ($worker->connection ?? ''),
            'queue' => $worker->queue ?? 'default',
            'processes' => $worker->processes ?? 1,
            'timeout' => $worker->timeout ?? 60,
            'tries' => $worker->tries ?? 1,
            'backoff' => $worker->backoff ?? 0,
            'sleep' => $worker->sleep ?? 3,
            'max_jobs' => $worker->max_jobs ?? 1000,
            'max_time' => $worker->max_time ?? 3600,
            'memory' => $worker->memory ?? 128,
        ];
        $this->resetValidation();
        $this->editing = true;
    }

    public function saveWorker(LaravelApps $apps): void
    {
        $this->form['name'] = strtolower(trim((string) $this->form['name']));
        $this->form['queue'] = preg_replace('/\s*,\s*/', ',', trim((string) $this->form['queue']));
        $app = $this->app();
        $this->validate([
            'form.name' => ['required', 'max:32', 'regex:/^[a-z0-9][a-z0-9-]*$/', Rule::unique('laravel_workers', 'name')->where('laravel_app_id', $app->id)->ignore($this->workerId)],
            'form.connection' => ['nullable', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/'],
            'form.queue' => ['required', 'max:200', 'regex:/^[A-Za-z0-9_.:-]+(,[A-Za-z0-9_.:-]+)*$/'],
            'form.processes' => ['required', 'integer', 'between:0,'.self::MAX_PROCESSES],
            'form.timeout' => ['required', 'integer', 'between:1,86400'],
            'form.tries' => ['required', 'integer', 'between:0,100'],
            'form.backoff' => ['required', 'integer', 'between:0,86400'],
            'form.sleep' => ['required', 'integer', 'between:0,60'],
            'form.max_jobs' => ['required', 'integer', 'min:0'],
            'form.max_time' => ['required', 'integer', 'min:0'],
            'form.memory' => ['required', 'integer', 'between:16,4096'],
        ], [
            'form.name.regex' => 'Küçük harf, rakam ve - kullanın.',
            'form.name.unique' => 'Bu adda bir işçi var.',
            'form.queue.regex' => 'Kuyruk adlarını virgülle ayırın.',
        ], [
            'form.name' => 'ad', 'form.queue' => 'kuyruk', 'form.processes' => 'süreç sayısı', 'form.timeout' => 'zaman aşımı',
            'form.tries' => 'deneme', 'form.memory' => 'bellek', 'form.connection' => 'bağlantı',
        ]);
        $data = $this->form + ['laravel_app_id' => $app->id];
        $data['connection'] = $data['connection'] === '' ? null : $data['connection'];
        $this->workerId ? $this->worker($this->workerId)?->update($data) : LaravelWorker::create($data);
        $this->editing = false;
        $apps->syncProcesses($app);
        $this->dispatch('toast', message: 'İşçi ayarları kaydedildi; sunucuya uygulanıyor.');
    }

    public function deleteWorker(int $id, LaravelApps $apps): void
    {
        $worker = $this->worker($id);
        if ($worker === null) {
            return;
        }
        $copy = $worker->only($worker->getFillable());
        $worker->delete();
        $apps->syncProcesses($this->app());
        $this->dispatch('toast',
            message: "{$copy['name']} işçisi silindi.",
            source: $this->getId(),
            undo: ['method' => 'restoreWorker', 'args' => [$copy]],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function restoreWorker(array $data, LaravelApps $apps): void
    {
        $data['laravel_app_id'] = $this->appId;
        if (LaravelWorker::where('laravel_app_id', $this->appId)->where('name', $data['name'] ?? '')->doesntExist()) {
            LaravelWorker::create(array_intersect_key($data, array_flip((new LaravelWorker)->getFillable())));
            $apps->syncProcesses($this->app());
        }
    }

    public function restart(AgentClient $agent): void
    {
        try {
            $agent->call('laravel.queue.restart', $this->app()->target(), config('servik.agent.job_timeout'));
        } catch (AgentException $e) {
            $this->agentError = self::agentMessage($e);

            return;
        }
        $this->dispatch('toast', message: 'İşçiler yeniden başlatıldı.');
    }

    public function retrySync(LaravelApps $apps): void
    {
        $apps->syncProcesses($this->app());
    }

    public function openFailed(): void
    {
        $this->failed = $this->agent('laravel.probe', $this->app()->agentApp() + ['kind' => 'failed'], []);
        $this->showingFailed = true;
    }

    /**
     * queue:retry, queue:forget or queue:flush, run right away.
     */
    public function failedAction(string $action, string $id = ''): void
    {
        $args = match ($action) {
            'retry' => ['queue:retry', $id === '' ? 'all' : $id],
            'forget' => ['queue:forget', $id],
            'flush' => ['queue:flush'],
            default => null,
        };
        if ($args === null || ($action !== 'flush' && $action !== 'retry' && $id === '') || ! preg_match('/^[A-Za-z0-9-]*$/', $id)) {
            return;
        }
        $result = $this->agent('laravel.run', $this->app()->agentApp() + ['tool' => 'artisan', 'args' => $args]);
        if ($result !== null && ($result['exit_code'] ?? 1) !== 0) {
            $this->agentError = trim((string) ($result['output'] ?? '')) ?: 'Komut başarısız oldu.';
        }
        $this->openFailed();
        $this->dispatch('toast', message: match ($action) {
            'retry' => $id === '' ? 'Başarısız işler yeniden kuyruğa alındı.' : 'İş yeniden kuyruğa alındı.',
            'forget' => 'İş silindi.',
            default => 'Başarısız işler temizlendi.',
        });
    }

    public function render(): View
    {
        $app = $this->app();
        $workers = $app->workers()->get();
        $status = $this->agent('laravel.status', $app->agentApp(), []);
        $sizes = $workers->isEmpty() ? null : $this->agent('laravel.probe', $app->agentApp() + [
            'kind' => 'queues',
            'queues' => $workers->flatMap(fn ($w) => collect($w->queues())->map(fn ($q) => [(string) $w->connection, $q]))->unique()->values()->all(),
        ]);
        $pending = [];
        foreach ($sizes['sizes'] ?? [] as $s) {
            $pending[$s['connection'].'|'.$s['queue']] = $s['size'];
        }

        return view('livewire.laravel.sections.queues', [
            'app' => $app,
            'workers' => $workers,
            'states' => collect($status['workers'] ?? [])->groupBy('worker'),
            'pending' => $pending,
            'failedCount' => $sizes['failed'] ?? null,
            'syncing' => $app->processes_state === 'syncing',
        ]);
    }

    private function worker(int $id): ?LaravelWorker
    {
        return LaravelWorker::where('laravel_app_id', $this->appId)->find($id);
    }
}
