<?php

namespace App\Livewire\Laravel\Sections;

use App\Services\Laravel\LaravelApps;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Lazy;

/**
 * The application's scheduled tasks, read from the application, and the
 * systemd timer that runs schedule:run every minute.
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
            message: $app->scheduler ? 'Zamanlayıcı açılıyor.' : 'Zamanlayıcı kapatılıyor.',
            source: $this->getId(),
            undo: ['method' => 'toggle', 'args' => []],
        );
    }

    /**
     * Run one task's command now, in the Commands section.
     */
    public function runNow(string $command, LaravelApps $apps): void
    {
        $apps->runCommand($this->app(), 'artisan', $command, auth()->user());
        $this->dispatch('show-section', section: 'komutlar');
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

        return view('livewire.laravel.sections.scheduler', [
            'app' => $app,
            'tasks' => collect($tasks ?? [])->map(function (array $t) use ($runs) {
                $t['run'] = $runs->first(fn ($r, $cmd) => $cmd === $t['command'] || str_starts_with($cmd, $t['command'].' ') || str_starts_with($t['command'], $cmd));
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
