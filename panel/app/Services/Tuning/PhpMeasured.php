<?php

namespace App\Services\Tuning;

use App\Models\TuningSample;

/**
 * Reads two measurements of a PHP-FPM version: OPcache and the sites'
 * pools as they are now and what their counters did in between. PHP-FPM
 * starts its counters again when it reloads, so a counter that started
 * later than the earlier measurement counts from its own start.
 */
final class PhpMeasured
{
    /** @var array<string, mixed> */
    public readonly array $latest;

    /** @var array<string, mixed>|null */
    public readonly ?array $since;

    /** Length of the period, in hours. */
    public readonly float $hours;

    public function __construct(TuningSample $latest, ?TuningSample $since)
    {
        $this->latest = $latest->data;
        $this->since = $since?->data;
        $start = (int) ($latest->data['opcache']['start_time'] ?? 0);
        $this->hours = $since !== null
            ? max(1 / 60, $since->created_at->diffInSeconds($latest->created_at) / 3600)
            : ($start > 0 ? max(1 / 60, ($latest->created_at->timestamp - $start) / 3600) : 1 / 60);
    }

    /** @return array<string, mixed>|null */
    public function opcache(): ?array
    {
        $o = $this->latest['opcache'] ?? null;

        return is_array($o) ? $o : null;
    }

    /** What an OPcache counter did in the period. */
    public function opcacheDelta(string $name): int
    {
        $now = (int) ($this->latest['opcache'][$name] ?? 0);
        $then = $this->since['opcache'] ?? null;
        if (! is_array($then) || (int) ($then['start_time'] ?? 0) !== (int) ($this->latest['opcache']['start_time'] ?? 0)) {
            return $now;
        }

        return max(0, $now - (int) ($then[$name] ?? 0));
    }

    /** Share of scripts served from OPcache, in percent. */
    public function hitRatio(): ?float
    {
        $hits = $this->opcacheDelta('hits');
        $all = $hits + $this->opcacheDelta('misses');

        return $all >= 1000 ? 100 * $hits / $all : null;
    }

    /** Share of OPcache's memory taken (used and wasted), in percent. */
    public function memoryFull(): ?float
    {
        $o = $this->opcache();
        if ($o === null || (int) ($o['memory_mb'] ?? 0) <= 0 || ! ($o['enabled'] ?? false)) {
            return null;
        }

        return min(100.0, 100 * ((float) $o['used_mb'] + (float) $o['wasted_mb']) / (int) $o['memory_mb']);
    }

    /**
     * The sites' pools.
     *
     * @return list<array<string, mixed>>
     */
    public function pools(): array
    {
        return array_values(array_filter((array) ($this->latest['pools'] ?? []), fn ($p) => is_array($p) && ($p['domain'] ?? '') !== ''));
    }

    /** What a pool's counter did in the period. */
    public function poolDelta(array $pool, string $name): int
    {
        $now = (int) ($pool[$name] ?? 0);
        foreach ((array) ($this->since['pools'] ?? []) as $then) {
            if (($then['domain'] ?? null) === $pool['domain'] && ($then['read'] ?? false) && (int) ($then['start_time'] ?? 0) === (int) ($pool['start_time'] ?? 0)) {
                return max(0, $now - (int) ($then[$name] ?? 0));
            }
        }

        return $now;
    }

    /** Memory one of a pool's workers takes, in MB. */
    public static function workerMB(array $pool): float
    {
        $workers = (int) ($pool['workers'] ?? 0);

        return $workers > 0 ? max(8.0, (float) $pool['memory_mb'] / $workers) : 64.0;
    }
}
