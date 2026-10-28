<?php

namespace App\Livewire\Laravel\Sections;

use App\Services\Laravel\LaravelApps;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Lazy;

/**
 * The application's scheduled tasks, read from the application, and the
 * systemd timer that runs schedule:run every minute. Each task can be
 * turned off on its own.
 */
#[Lazy]
class Scheduler extends Section
{
    /** Minutes without a run before the scheduler counts as stuck. */
    public const LATE_MINUTES = 3;

    public function toggle(LaravelApps $apps): void
    {
        $app = $this->app();
        $app->update(['scheduler' => ! $app->scheduler]);
        $apps->syncProcesses($app);
        $this->dispatch('toast',
            message: $app->scheduler ? __('Zamanlayıcı açılıyor.') : __('Zamanlayıcı kapatılıyor.'),
            source: $this->getId(),
            undo: ['method' => 'toggle', 'args' => []],
        );
    }

    /**
     * Turn one scheduled task off or back on; the scheduler skips the
     * tasks that are off. Keys of tasks the application no longer has are
     * dropped on the way.
     */
    public function toggleTask(string $key, LaravelApps $apps): void
    {
        if (preg_match('/^[0-9a-f]{16}$/', $key) !== 1) {
            return;
        }
        $app = $this->app();
        $tasks = collect($this->agent('laravel.probe', $app->agentApp() + ['kind' => 'schedule'], []) ?? []);
        $task = $tasks->firstWhere('key', $key);
        if ($task === null) {
            return;
        }
        $skipped = collect($app->skipped_tasks ?? [])->intersect($tasks->pluck('key'));
        $off = ! $skipped->contains($key);
        $skipped = $off ? $skipped->push($key) : $skipped->reject(fn ($k) => $k === $key);
        $app->update(['skipped_tasks' => $skipped->values()->all()]);
        $apps->syncProcesses($app);
        $this->dispatch('toast',
            message: $off ? __(':command artık çalışmayacak.', ['command' => $task['command']]) : __(':command yeniden zamanında çalışacak.', ['command' => $task['command']]),
            source: $this->getId(),
            undo: ['method' => 'toggleTask', 'args' => [$key]],
        );
    }

    /**
     * Run one task's command now, in the Commands section.
     */
    public function runNow(string $command, LaravelApps $apps): void
    {
        $apps->runCommand($this->app(), 'artisan', $command, auth()->user());
        $this->dispatch('show-section', section: 'commands');
    }

    public function render(): View
    {
        $app = $this->app();
        $tasks = $this->agent('laravel.probe', $app->agentApp() + ['kind' => 'schedule'], []);
        $status = $this->agent('laravel.status', $app->agentApp(), []);
        $state = $status['schedule'] ?? [];
        $lastRun = isset($state['last_run']) ? Carbon::parse($state['last_run']) : null;
        // The newest log line of each command.
        $runs = collect($state['tasks'] ?? [])->keyBy('command');

        $skipped = $app->skipped_tasks ?? [];

        return view('livewire.laravel.sections.scheduler', [
            'app' => $app,
            'tasks' => collect($tasks ?? [])->map(function (array $t) use ($runs, $skipped) {
                $t['run'] = $runs->first(fn ($r, $cmd) => $cmd === $t['command'] || str_starts_with($cmd, $t['command'].' ') || str_starts_with($t['command'], $cmd));
                $t['off'] = isset($t['key']) && in_array($t['key'], $skipped, true);
                $t['artisan'] = ! str_contains($t['command'], ' ') || preg_match('/^[a-z][\w:-]*(\s|$)/', $t['command']) === 1;

                return $t;
            }),
            'enabled' => (bool) ($state['enabled'] ?? false),
            'syncing' => $app->processes_state === 'syncing',
            'lastRun' => $lastRun,
            'late' => $app->scheduler && ($state['enabled'] ?? false) && ($lastRun === null || $lastRun->lt(now()->subMinutes(self::LATE_MINUTES))),
        ]);
    }
}
