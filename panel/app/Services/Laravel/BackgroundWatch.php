<?php

namespace App\Services\Laravel;

use App\Enums\LaravelAppStatus;
use App\Models\LaravelApp;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;

/**
 * serfex:laravel-watch: once a minute, asks the agent how each Laravel
 * application's queue workers, processes and scheduled tasks are doing,
 * with how often each crashed in the last ten minutes, so a worker that
 * keeps crashing becomes a problem (Bildirimler → Kuyruklar ve
 * süreçler, Zamanlanmış görevler).
 */
class BackgroundWatch
{
    /** Crashes within WINDOW minutes (the agent counts them) that make a crash loop. */
    public const CRASHES = 5;

    public const WINDOW = 10;

    /** Scheduled task runs in a row that failed. */
    public const TASK_FAILURES = 3;

    public function __construct(private readonly AgentClient $agent) {}

    /**
     * Returns how many applications were read.
     */
    public function check(): int
    {
        $apps = LaravelApp::with('site')->where('status', LaravelAppStatus::Active)->get()->filter(fn (LaravelApp $a) => $a->site !== null)->keyBy(fn (LaravelApp $a) => $a->site->domain);
        if ($apps->isEmpty()) {
            return 0;
        }
        try {
            $result = (array) $this->agent->call('laravel.watch', ['domains' => $apps->keys()->values()->all()]);
        } catch (AgentException) {
            return 0;
        }
        $now = now()->getTimestamp();
        $count = 0;
        foreach ($result as $w) {
            $w = (array) $w;
            $app = $apps->get($w['domain'] ?? '');
            if ($app === null) {
                continue;
            }
            $app->forceFill(['watch' => $this->merge($w, $now)])->saveQuietly();
            $count++;
        }

        return $count;
    }

    /**
     * The new state of an application's watch: each unit's state and
     * crashes over the last WINDOW minutes, and the scheduler's failing
     * tasks.
     *
     * @param  array<string, mixed>  $read
     * @return array<string, mixed>
     */
    public function merge(array $read, int $now): array
    {
        $units = [];
        foreach ((array) ($read['units'] ?? []) as $u) {
            $u = (array) $u;
            $units[] = ['kind' => (string) ($u['kind'] ?? 'worker'), 'name' => (string) ($u['name'] ?? ''), 'state' => (string) ($u['state'] ?? 'unknown'), 'crashes' => (int) ($u['crashes'] ?? 0)];
        }
        $tasks = [];
        foreach ((array) ($read['tasks'] ?? []) as $t) {
            $t = (array) $t;
            if ((int) ($t['failures'] ?? 0) > 0) {
                $tasks[] = ['command' => (string) $t['command'], 'failures' => (int) $t['failures'], 'at' => (string) ($t['at'] ?? '')];
            }
        }

        return ['checked_at' => $now, 'units' => $units, 'tasks' => $tasks];
    }

    /**
     * The units of an application that keep crashing or stopped:
     * list of {kind, name, state, crashes}.
     *
     * @return list<array{kind: string, name: string, state: string, crashes: int}>
     */
    public static function crashing(LaravelApp $app): array
    {
        $out = [];
        foreach ((array) ($app->watch['units'] ?? []) as $u) {
            if (($u['state'] ?? '') === 'failed' || (int) ($u['crashes'] ?? 0) >= self::CRASHES) {
                $out[] = ['kind' => $u['kind'], 'name' => $u['name'], 'state' => $u['state'], 'crashes' => (int) $u['crashes']];
            }
        }

        return $out;
    }

    /**
     * Scheduled tasks that failed TASK_FAILURES times in a row.
     *
     * @return list<array{command: string, failures: int, at: string}>
     */
    public static function failingTasks(LaravelApp $app): array
    {
        if (! $app->scheduler) {
            return [];
        }

        return array_values(array_filter((array) ($app->watch['tasks'] ?? []), fn ($t) => (int) $t['failures'] >= self::TASK_FAILURES));
    }
}
