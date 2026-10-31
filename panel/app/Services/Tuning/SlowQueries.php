<?php

namespace App\Services\Tuning;

use App\Models\Database;
use App\Services\Databases\DatabaseServers;

/**
 * The statements that cost the database server the most time, from its
 * statement summary (performance_schema): normalized, so values never
 * show, and tied to sites through the database they ran in. What
 * happened in the last 24 hours is the difference between two
 * measurements; without an older one, the server's whole run counts.
 */
class SlowQueries
{
    /** At most this many statements are shown. */
    public const LIMIT = 5;

    /** A statement is slow from this average, in milliseconds... */
    public const SLOW_MS = 100;

    /** ...or when it reads this many rows for every row it returns. */
    public const SCAN_RATIO = 1000;

    public function __construct(private readonly Samples $samples) {}

    /**
     * @param  list<int>|null  $siteIds  only statements of these sites' databases
     * @return array{rows: list<array<string, mixed>>, hours: float, available: bool}
     */
    public function list(string $unit, ?array $siteIds = null): array
    {
        $latest = $this->samples->latest($unit);
        if ($latest === null) {
            return ['rows' => [], 'hours' => 0, 'available' => false];
        }
        $since = $this->samples->since($latest, 1);
        $hours = $since !== null ? max(1, $since->created_at->diffInSeconds($latest->created_at) / 3600) : max(1, (int) ($latest->data['status']['Uptime'] ?? 0) / 3600);
        $before = [];
        foreach ((array) ($since?->data['digests'] ?? []) as $d) {
            $before[$d['schema'].'/'.$d['digest']] = $d;
        }
        $databases = Database::where('server', DatabaseServers::MYSQL)->with('site.laravelApp', 'site.wordpress')->get()->keyBy('name');
        $rows = [];
        foreach ((array) ($latest->data['digests'] ?? []) as $d) {
            $db = $databases[$d['schema']] ?? null;
            if ($siteIds !== null && ($db === null || ! in_array($db->site_id, $siteIds, true))) {
                continue;
            }
            $b = $before[$d['schema'].'/'.$d['digest']] ?? null;
            $count = (int) $d['count'] - (int) ($b['count'] ?? 0);
            $time = (float) $d['time_ms'] - (float) ($b['time_ms'] ?? 0);
            if ($count <= 0 || $time <= 0) {
                continue;
            }
            $examined = (int) $d['rows_examined'] - (int) ($b['rows_examined'] ?? 0);
            $sent = (int) $d['rows_sent'] - (int) ($b['rows_sent'] ?? 0);
            $avg = $time / $count;
            if ($avg < self::SLOW_MS && ! ($count >= 100 && $examined / max(1, $sent) >= self::SCAN_RATIO)) {
                continue;
            }
            $site = $db?->site;
            $rows[] = [
                'digest' => (string) $d['digest'],
                'schema' => (string) $d['schema'],
                'text' => (string) $d['text'],
                'site' => $site?->domain,
                'site_id' => $site?->id,
                'app' => match (true) {
                    $site?->laravelApp !== null => 'Laravel',
                    $site?->wordpress !== null => 'WordPress',
                    default => null,
                },
                'per_day' => (int) round($count * 24 / $hours),
                'avg_ms' => $avg,
                'total_ms_per_day' => $time * 24 / $hours,
                'examined' => (int) round($examined / $count),
                'sent' => (int) round($sent / $count),
            ];
        }
        usort($rows, fn ($a, $b) => $b['total_ms_per_day'] <=> $a['total_ms_per_day']);

        return ['rows' => array_slice($rows, 0, self::LIMIT), 'hours' => $hours, 'available' => true];
    }
}
