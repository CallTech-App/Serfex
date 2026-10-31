<?php

namespace App\Services\Tuning;

use App\Models\TuningSample;

/**
 * Reads two measurements of the same server run: values as they are now
 * and what the counters did in between. Without an earlier measurement
 * the period is the server's whole run.
 */
final class Measured
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
        $this->hours = $since !== null
            ? max(1 / 60, $since->created_at->diffInSeconds($latest->created_at) / 3600)
            : max(1 / 60, (int) ($latest->data['status']['Uptime'] ?? 0) / 3600);
    }

    public function var(string $name, string $default = ''): string
    {
        return (string) ($this->latest['variables'][$name] ?? $default);
    }

    public function varInt(string $name): int
    {
        return (int) $this->var($name, '0');
    }

    /** A size variable, in bytes. */
    public function bytes(string $name): int
    {
        return (int) $this->var($name, '0');
    }

    /** A status counter or a top-level number as it is now. */
    public function int(string $name): int
    {
        return (int) ($this->latest['status'][$name] ?? $this->latest[$name] ?? 0);
    }

    /** What a status counter did in the period. */
    public function delta(string $name): int
    {
        $now = (int) ($this->latest['status'][$name] ?? 0);
        $then = (int) ($this->since['status'][$name] ?? 0);

        return $this->since !== null && $now >= $then ? $now - $then : $now;
    }

    public function perDay(string $name): int
    {
        return (int) round($this->delta($name) * 24 / $this->hours);
    }

    public function dataBytes(): int
    {
        return (int) ($this->latest['data_bytes'] ?? 0);
    }

    /** Share of InnoDB reads served from memory, in percent. */
    public function hitRatio(): ?float
    {
        $requests = $this->delta('Innodb_buffer_pool_read_requests');
        if ($requests < 1000) {
            return null;
        }

        return max(0.0, 100 * (1 - $this->delta('Innodb_buffer_pool_reads') / $requests));
    }

    /** Share of internal temporary tables written to disk, in percent. */
    public function tmpDiskShare(): ?float
    {
        $all = $this->delta('Created_tmp_tables');

        return $all > 0 ? 100 * $this->delta('Created_tmp_disk_tables') / $all : null;
    }

    public function maxConnectionsTight(): bool
    {
        $max = $this->varInt('max_connections');

        return $this->delta('Connection_errors_max_connections') > 0 || ($max > 0 && $this->int('Max_used_connections') >= 0.85 * $max);
    }

    /** Memory one connection can take for its buffers, in MB. */
    public function perConnectionMB(): float
    {
        $bytes = 0;
        foreach (['sort_buffer_size', 'join_buffer_size', 'read_buffer_size', 'read_rnd_buffer_size', 'thread_stack', 'binlog_cache_size'] as $v) {
            $bytes += $this->bytes($v);
        }

        return max(1.0, $bytes / 1048576);
    }
}
