<?php

namespace App\Services\Stats;

use App\Models\MonitorSample;
use App\Models\ServerStat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The server's CPU, memory, disk and network over time: the minute
 * samples of Canlı İzleme for the last day, hourly rows (rolled up from
 * them) for up to 400 days.
 */
class ServerHistory
{
    /** Ranges of the Sistem tab: key => [label, bucket seconds]. */
    public const RANGES = [
        '24s' => ['24 saat', 900],
        '7g' => ['7 gün', 3600],
        '30g' => ['30 gün', 4 * 3600],
        '12a' => ['12 ay', 86400],
    ];

    /** How long hourly rows are kept. */
    public const KEEP_DAYS = 400;

    /**
     * Used and total bytes of the server's file systems (each counted
     * once), from the panel's own view of them.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function disk(): ?array
    {
        $seen = [];
        $used = $total = 0;
        foreach ((array) config('servik.disk_paths', ['/', '/home', '/var']) as $path) {
            $t = @disk_total_space($path);
            $f = @disk_free_space($path);
            if (! $t || $f === false || isset($seen[$t.':'.$f])) {
                continue;
            }
            $seen[$t.':'.$f] = true;
            $used += (int) ($t - $f);
            $total += (int) $t;
        }

        return $total > 0 ? [$used, $total] : null;
    }

    /**
     * Rolls the minute samples of every finished hour not rolled up yet
     * into hourly rows, and drops rows older than KEEP_DAYS. Returns how
     * many hours were added.
     */
    public function rollup(): int
    {
        $last = ServerStat::max('hour');
        $since = $last ? CarbonImmutable::parse($last, 'UTC')->addHour() : CarbonImmutable::parse(MonitorSample::whereNull('site_id')->min('recorded_at') ?? now(), 'UTC')->startOfHour();
        $until = now()->utc()->startOfHour();
        $rows = DB::table('monitor_samples')->whereNull('site_id')
            ->where('recorded_at', '>=', $since->toDateTimeString())->where('recorded_at', '<', $until->toDateTimeString())
            ->groupByRaw("date_trunc('hour', recorded_at)")
            ->selectRaw("date_trunc('hour', recorded_at) as hour, avg(cpu) as cpu, max(cpu) as cpu_max, avg(memory) as memory, max(memory) as memory_max,
                max(memory_total) as memory_total, avg(disk_used) as disk_used, max(disk_total) as disk_total,
                coalesce(avg(net_rx), 0) * 3600 as net_rx, coalesce(avg(net_tx), 0) * 3600 as net_tx, coalesce(avg(load), 0) as load")
            ->get();
        foreach ($rows as $r) {
            ServerStat::updateOrCreate(['hour' => $r->hour], [
                'cpu' => round((float) $r->cpu, 2), 'cpu_max' => round((float) $r->cpu_max, 2),
                'memory' => (int) $r->memory, 'memory_max' => (int) $r->memory_max, 'memory_total' => (int) $r->memory_total,
                'disk_used' => $r->disk_used === null ? null : (int) $r->disk_used, 'disk_total' => $r->disk_total === null ? null : (int) $r->disk_total,
                'net_rx' => (int) $r->net_rx, 'net_tx' => (int) $r->net_tx, 'load' => round((float) $r->load, 2),
            ]);
        }
        ServerStat::where('hour', '<', now()->subDays(self::KEEP_DAYS))->delete();

        return $rows->count();
    }

    /**
     * Points of a range, oldest first: CPU (average and peak, percent of
     * all cores), memory, disk (used, total) and network (bytes received
     * and sent in each point).
     *
     * @return list<array{at: CarbonImmutable, cpu: float, cpu_max: float, memory: int, memory_total: int, disk_used: ?int, disk_total: ?int, net_rx: int, net_tx: int}>
     */
    public function series(string $range): array
    {
        [, $bucket] = self::RANGES[$range] ?? self::RANGES['24s'];
        $tz = config('servik.timezone');
        if ($range === '24s') {
            $since = now()->utc()->subDay();
            $rows = DB::table('monitor_samples')->whereNull('site_id')->where('recorded_at', '>=', $since)
                ->groupByRaw('b')
                ->selectRaw("floor(extract(epoch from recorded_at) / {$bucket}) as b, avg(cpu) as cpu, max(cpu) as cpu_max, avg(memory) as memory, max(memory_total) as memory_total,
                    avg(disk_used) as disk_used, max(disk_total) as disk_total, coalesce(avg(net_rx), 0) * {$bucket} as net_rx, coalesce(avg(net_tx), 0) * {$bucket} as net_tx")
                ->orderBy('b')->get();
        } elseif ($range === '12a') {
            // Whole days of the panel's time zone.
            $rows = DB::table('server_stats')->where('hour', '>=', now($tz)->subYear()->startOfDay()->utc())
                ->groupByRaw('b')
                ->selectRaw("to_char((hour at time zone 'UTC') at time zone ?, 'YYYY-MM-DD') as b, avg(cpu) as cpu, max(cpu_max) as cpu_max, avg(memory) as memory,
                    max(memory_total) as memory_total, avg(disk_used) as disk_used, max(disk_total) as disk_total, sum(net_rx) as net_rx, sum(net_tx) as net_tx", [$tz])
                ->orderBy('b')->get();
        } else {
            $days = $range === '7g' ? 7 : 30;
            $rows = DB::table('server_stats')->where('hour', '>=', now()->utc()->subDays($days))
                ->groupByRaw('b')
                ->selectRaw("floor(extract(epoch from hour) / {$bucket}) as b, avg(cpu) as cpu, max(cpu_max) as cpu_max, avg(memory) as memory, max(memory_total) as memory_total,
                    avg(disk_used) as disk_used, max(disk_total) as disk_total, sum(net_rx) as net_rx, sum(net_tx) as net_tx")
                ->orderBy('b')->get();
        }

        return $rows->map(fn ($r) => [
            'at' => $range === '12a' ? CarbonImmutable::parse($r->b, $tz) : CarbonImmutable::createFromTimestampUTC((int) $r->b * $bucket)->timezone($tz),
            'cpu' => round((float) $r->cpu, 1), 'cpu_max' => round((float) $r->cpu_max, 1),
            'memory' => (int) $r->memory, 'memory_total' => (int) $r->memory_total,
            'disk_used' => $r->disk_used === null ? null : (int) $r->disk_used, 'disk_total' => $r->disk_total === null ? null : (int) $r->disk_total,
            'net_rx' => (int) $r->net_rx, 'net_tx' => (int) $r->net_tx,
        ])->all();
    }

    /**
     * Average and peak of a range, for the text beside each chart.
     *
     * @param  list<array<string, mixed>>  $points
     * @return array{cpu: float, cpu_max: float, memory: int, memory_max: int, memory_total: int, disk_used: ?int, disk_total: ?int, net_rx: int, net_tx: int}
     */
    public static function summary(array $points): array
    {
        $n = max(count($points), 1);
        $last = $points === [] ? [] : end($points);
        $disk = array_values(array_filter(array_column($points, 'disk_used'), fn ($v) => $v !== null));

        return [
            'cpu' => round(array_sum(array_column($points, 'cpu')) / $n, 1),
            'cpu_max' => $points === [] ? 0.0 : max(array_column($points, 'cpu_max')),
            'memory' => (int) (array_sum(array_column($points, 'memory')) / $n),
            'memory_max' => $points === [] ? 0 : max(array_column($points, 'memory')),
            'memory_total' => (int) ($last['memory_total'] ?? 0),
            'disk_used' => $disk === [] ? null : (int) end($disk),
            'disk_total' => $last['disk_total'] ?? null,
            'net_rx' => array_sum(array_column($points, 'net_rx')),
            'net_tx' => array_sum(array_column($points, 'net_tx')),
        ];
    }
}
