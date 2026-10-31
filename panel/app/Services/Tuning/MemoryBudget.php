<?php

namespace App\Services\Tuning;

/**
 * The memory the services sharing this machine may take together: the
 * operating system and Serfex, the database server, PHP-FPM at its
 * busiest in the last 7 days, Redis and Memcached. Their total stays under
 * a share of RAM (80% unless changed); the rest is the file cache. A
 * suggestion that would go over it is not offered for applying.
 */
class MemoryBudget
{
    private int $reserved = 0;

    /**
     * @param  array<string, int>  $parts  MB by part: system, mysql, php-fpm, cache
     */
    public function __construct(public readonly int $totalMB, public readonly int $percent, public readonly array $parts) {}

    /**
     * @param  array<string, mixed>  $data  a measurement
     */
    public static function from(array $data, int $percent, int $phpPeakMB): self
    {
        $p = (array) ($data['processes_mb'] ?? []);
        $total = (int) ($data['memory_mb'] ?? 0);
        $used = $total - (int) ($data['available_mb'] ?? $total);
        $mysql = (int) ($p['mysql'] ?? 0);
        $php = (int) ($p['php-fpm'] ?? 0);
        $cache = (int) ($p['redis'] ?? 0) + (int) ($p['memcached'] ?? 0);

        return new self($total, $percent, [
            'system' => max(0, $used - $mysql - $php - $cache),
            'mysql' => $mysql,
            'php-fpm' => max($php, $phpPeakMB),
            'cache' => $cache,
        ]);
    }

    public function limitMB(): int
    {
        return intdiv($this->totalMB * $this->percent, 100);
    }

    public function usedMB(): int
    {
        return array_sum($this->parts);
    }

    /** Room left for suggestions, after the ones reserved so far. */
    public function roomMB(): int
    {
        return $this->limitMB() - $this->usedMB() - $this->reserved;
    }

    /**
     * Reserves room for a suggestion; returns null when it fits, or the
     * MB missing.
     */
    public function reserve(int $mb): ?int
    {
        if ($mb <= 0) {
            return null;
        }
        if ($mb > $this->roomMB()) {
            return $mb - max(0, $this->roomMB());
        }
        $this->reserved += $mb;

        return null;
    }

    /**
     * @return array{total: int, limit: int, percent: int, now: array<string, int>, after: array<string, int>, used: int, used_after: int}
     */
    public function summary(): array
    {
        $after = $this->parts;
        $after['mysql'] += $this->reserved;

        return [
            'total' => $this->totalMB, 'limit' => $this->limitMB(), 'percent' => $this->percent,
            'now' => $this->parts, 'after' => $after, 'used' => $this->usedMB(), 'used_after' => $this->usedMB() + $this->reserved,
        ];
    }
}
