<?php

namespace App\Services\Tuning;

use App\Models\TuningSample;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Measurements of the services optimization looks at (database server,
 * PHP-FPM versions), taken every hour (and on "Şimdi ölç"). The counters
 * run from the service's start, so what happened in a period is the
 * difference between two measurements of the same run.
 */
class Samples
{
    /** Measurements are kept this long. */
    public const KEEP_DAYS = 8;

    public function __construct(private readonly AgentClient $agent) {}

    /**
     * Measures the server now and keeps the result.
     *
     * @throws AgentException
     */
    public function record(string $unit): TuningSample
    {
        $data = (array) $this->agent->call('service.measure', ['unit' => $unit], 60);

        return TuningSample::create(['unit' => $unit, 'data' => $data, 'created_at' => now()]);
    }

    public function latest(string $unit): ?TuningSample
    {
        return TuningSample::where('unit', $unit)->latest('created_at')->latest('id')->first();
    }

    /**
     * The oldest measurement of the latest one's run that is at least an
     * hour older and at most $days old: the start of the period the
     * suggestions look at. Null when there is none yet.
     */
    public function since(TuningSample $latest, int $days = 7): ?TuningSample
    {
        $runStart = self::runStart($latest);
        $from = max($runStart, $latest->created_at->copy()->subDays($days));

        return TuningSample::where('unit', $latest->unit)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $latest->created_at->copy()->subHour())
            ->oldest('created_at')->first();
    }

    /**
     * When the measured run started: the database server's start, or
     * PHP-FPM's (OPcache's) last start.
     */
    public static function runStart(TuningSample $sample): CarbonInterface
    {
        $data = $sample->data;
        if (isset($data['status']['Uptime'])) {
            return $sample->created_at->copy()->subSeconds((int) $data['status']['Uptime']);
        }
        $start = (int) ($data['opcache']['start_time'] ?? 0);

        return $start > 0 ? Carbon::createFromTimestamp($start) : $sample->created_at->copy()->subDays(30);
    }

    /**
     * The highest PHP-FPM memory seen in $days by any measurement: what
     * PHP takes at its busiest, which the memory budget keeps room for.
     */
    public function peak(string $unit, string $group, int $days = 7): int
    {
        $max = 0;
        TuningSample::where('created_at', '>=', now()->subDays($days))
            ->select(['id', 'data'])->lazyById(200)
            ->each(function (TuningSample $s) use (&$max, $group) {
                $max = max($max, (int) ($s->data['processes_mb'][$group] ?? 0));
            });

        return $max;
    }

    public function prune(): int
    {
        return TuningSample::where('created_at', '<', now()->subDays(self::KEEP_DAYS))->delete();
    }
}
