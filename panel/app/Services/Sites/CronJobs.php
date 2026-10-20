<?php

namespace App\Services\Sites;

use App\Models\CronJob;
use App\Models\Site;
use App\Models\SystemUser;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Customers\Quotas;
use Cron\CronExpression;
use Illuminate\Support\Carbon;

/**
 * Zamanlanmış görevler: a site's cron tasks. The panel keeps the tasks;
 * the agent writes them to /etc/cron.d as the site's user, wrapped so a
 * run is time-boxed, never overlaps itself and leaves its output.
 *
 * A suspended customer's tasks stay but are sent paused.
 */
class CronJobs
{
    /** Time limits a task can have: seconds => label. */
    public const TIMEOUTS = [300 => '5 dakika', 900 => '15 dakika', 3600 => '1 saat', 21600 => '6 saat', 86400 => '24 saat'];

    public const MAX_COMMAND = 2000;

    /** Run statuses: key => [label, tone]. */
    public const STATUSES = [
        'ok' => ['Başarılı', 'ok'],
        'failed' => ['Hata verdi', 'crit'],
        'timeout' => ['Süre aşıldı', 'crit'],
        'skipped' => ['Atlandı', 'warn'],
        'running' => ['Çalışıyor', 'info'],
    ];

    public function __construct(private readonly AgentClient $agent) {}

    public function refusal(SystemUser $account): ?string
    {
        return app(Quotas::class)->refusal($account->customer, 'cron_jobs');
    }

    /**
     * "*\/5 * * * *" with single spaces and lowercase names, or null when
     * it is not a five-field cron schedule.
     */
    public static function normalizeSchedule(string $schedule): ?string
    {
        $parts = preg_split('/\s+/', mb_strtolower(trim($schedule))) ?: [];
        if (count($parts) !== 5 || str_contains($schedule, '@')) {
            return null;
        }
        $normalized = implode(' ', $parts);

        return CronExpression::isValidExpression($normalized) ? $normalized : null;
    }

    /**
     * Why $command cannot be a task, or null.
     */
    public static function commandProblem(string $command): ?string
    {
        return match (true) {
            trim($command) === '' => __('Komutu yazın.'),
            strlen($command) > self::MAX_COMMAND => __('Komut en fazla :max_command karakter olabilir.', ['max_command' => self::MAX_COMMAND]),
            (bool) preg_match('/[\r\n\x00]/', $command) => __('Komut tek satır olmalı; birden fazla komut için && ya da bir betik kullanın.'),
            default => null,
        };
    }

    /**
     * Writes the site's tasks to the server.
     *
     * @throws AgentException
     */
    public function apply(Site $site): void
    {
        $site->loadMissing('systemUser.customer');
        $tasks = CronJob::where('site_id', $site->id)->orderBy('id')->get();
        if ($tasks->isEmpty()) {
            $this->agent->call('cron.remove', ['domain' => $site->domain]);

            return;
        }
        $paused = $site->isSuspended();
        $this->agent->call('cron.apply', [
            'domain' => $site->domain,
            'user' => $site->systemUser->name,
            'php' => (string) $site->php_version,
            'tasks' => $tasks->map(fn (CronJob $t) => [
                'id' => $t->id,
                'schedule' => $t->schedule,
                'command' => $t->command,
                'enabled' => $t->enabled && ! $paused,
                'timeout' => $t->timeout,
            ])->values()->all(),
        ]);
    }

    /**
     * The site goes: its crontab and run history with it.
     *
     * @throws AgentException
     */
    public function removeForSite(Site $site): void
    {
        if (CronJob::withTrashed()->where('site_id', $site->id)->exists()) {
            $this->agent->call('cron.remove', ['domain' => $site->domain]);
        }
    }

    /**
     * Starts the task now, outside its schedule. False while it runs.
     *
     * @throws AgentException
     */
    public function run(CronJob $task): bool
    {
        return (bool) ($this->agent->call('cron.run', ['domain' => $task->site->domain, 'id' => $task->id])['started'] ?? false);
    }

    /**
     * The task's last runs, newest first.
     *
     * @return array{runs: list<array<string, mixed>>, running: bool, running_since: ?Carbon}
     *
     * @throws AgentException
     */
    public function runs(CronJob $task, int $limit = 20): array
    {
        $result = (array) $this->agent->call('cron.runs', ['domain' => $task->site->domain, 'id' => $task->id, 'limit' => $limit]);

        return [
            'runs' => array_map(fn ($r) => [...(array) $r, 'status' => self::status((array) $r)], array_values((array) ($result['runs'] ?? []))),
            'running' => (bool) ($result['running'] ?? false),
            'running_since' => isset($result['running_since']) ? Carbon::parse($result['running_since']) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $run
     */
    public static function status(array $run): string
    {
        return match (true) {
            (bool) ($run['timed_out'] ?? false) => 'timeout',
            (bool) ($run['skipped'] ?? false) => 'skipped',
            (int) ($run['exit_code'] ?? -1) === 0 => 'ok',
            default => 'failed',
        };
    }

    /**
     * servik:cron-history: reads each task's last run so lists and the
     * site page show it without asking the agent.
     */
    public function refreshHistory(): int
    {
        $updated = 0;
        $sites = Site::whereHas('cronJobs')->with('cronJobs')->get();
        foreach ($sites as $site) {
            foreach ($site->cronJobs as $task) {
                try {
                    $runs = $this->runs($task->setRelation('site', $site), 1);
                } catch (AgentException) {
                    continue;
                }
                $last = $runs['runs'][0] ?? null;
                if ($last === null) {
                    continue;
                }
                $at = Carbon::parse($last['started_at']);
                if ($task->last_run_at === null || ! $task->last_run_at->eq($at) || $task->last_status !== $last['status']) {
                    $task->update(['last_run_at' => $at, 'last_status' => $last['status']]);
                    $updated++;
                }
            }
        }

        return $updated;
    }
}
