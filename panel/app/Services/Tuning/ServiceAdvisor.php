<?php

namespace App\Services\Tuning;

use App\Models\TuningSample;
use App\Support\Bytes;
use App\Support\Num;

/**
 * What the advisors of Nginx, PostgreSQL, Memcached and the sites' Redis
 * share: the period between two measurements, counters that start again
 * when the service restarts, and the last steps every suggestion goes
 * through (Serfex AI's value from the safe range, the memory budget).
 *
 * A suggestion is an array like MysqlAdvisor's; value_path names the
 * value Serfex AI may change and memory_per the memory each unit of it
 * takes (1 for a size in MB).
 */
abstract class ServiceAdvisor
{
    /** The part of the memory budget the service's suggestions take from. */
    protected string $part = 'cache';

    /**
     * @param  array<string, array{text?: string, value?: int}>  $ai
     * @return array<string, mixed>
     */
    abstract public function analyze(TuningSample $latest, ?TuningSample $since, MemoryBudget $budget, array $ai = []): array;

    protected static function hours(TuningSample $latest, ?TuningSample $since): float
    {
        if ($since !== null) {
            return max(1 / 60, $since->created_at->diffInSeconds($latest->created_at) / 3600);
        }
        $start = (int) ($latest->data['start_time'] ?? 0);

        return $start > 0 ? max(1 / 60, ($latest->created_at->timestamp - $start) / 3600) : 1 / 60;
    }

    /**
     * What a counter did between two readings of the same run: the run
     * that started later counts from its own start.
     *
     * @param  array<string, mixed>  $now
     * @param  array<string, mixed>|null  $then
     */
    protected static function delta(array $now, ?array $then, string $path, string $start = 'start_time'): int
    {
        $value = (int) data_get($now, $path, 0);
        if ($then === null || (int) data_get($then, $start, 0) !== (int) data_get($now, $start, 0)) {
            return $value;
        }
        $old = (int) data_get($then, $path, 0);

        return $value >= $old ? $value - $old : $value;
    }

    /**
     * @param  array<string, mixed>  $out
     * @param  list<array<string, mixed>|null>  $found
     * @param  array<string, array{text?: string, value?: int}>  $ai
     * @return array<string, mixed>
     */
    protected function finish(array $out, array $found, MemoryBudget $budget, array $ai): array
    {
        foreach (array_filter($found) as $s) {
            $value = $ai[$s['key']]['value'] ?? null;
            if (is_int($value) && isset($s['range'], $s['value_path']) && $value >= $s['range'][0] && $value <= $s['range'][1] && $value !== $s['target']) {
                $s = self::retarget($s, $value);
            }
            $s['why_ai'] = isset($ai[$s['key']]['text']) ? (string) $ai[$s['key']]['text'] : null;
            $s['disruption'] ??= 'reload';
            $s['blocked'] = $budget->reserve($s['memory_mb'], $this->part);
            $out['suggestions'][] = $s;
        }
        $out['budget'] = $budget->summary();

        return $out;
    }

    /**
     * @param  array<string, mixed>  $s
     * @return array<string, mixed>
     */
    private static function retarget(array $s, int $value): array
    {
        $from = (int) substr((string) $s['from_raw'], 4);
        $s['target'] = $value;
        data_set($s['values'], $s['value_path'], $value);
        $mb = str_starts_with((string) $s['to_raw'], 'mb:');
        $s['to_label'] = $mb ? Bytes::format($value << 20) : Num::format($value);
        $s['to_raw'] = ($mb ? 'mb:' : 'num:').$value;
        $s['memory_mb'] = max(0, (int) ceil(($value - $from) * ($s['memory_per'] ?? 0)));

        return $s;
    }

    /**
     * The largest of $steps at most $value, or null when the smallest is
     * larger.
     *
     * @param  list<int>  $steps
     */
    protected static function stepDown(array $steps, int $value): ?int
    {
        $out = null;
        foreach ($steps as $s) {
            if ($s <= $value) {
                $out = $s;
            }
        }

        return $out;
    }
}
