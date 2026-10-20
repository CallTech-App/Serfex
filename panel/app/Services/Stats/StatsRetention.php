<?php

namespace App\Services\Stats;

use Illuminate\Support\Facades\DB;

/**
 * Keeps the statistics tables small: hourly rows for a month, daily rows
 * and mail days for two years, top lists for thirteen months, and only
 * the top of each site's day in the top lists.
 */
class StatsRetention
{
    public const HOURLY_DAYS = 35;

    public const DAILY_MONTHS = 25;

    public const ITEM_MONTHS = 13;

    /** Values kept per site, day and kind once a day is over. */
    public const TOP = 100;

    /**
     * Folds every (site, day, kind) since $since that holds more than
     * $threshold values into its $keep largest, adding the rest to the
     * "" row. Pages and referrers rank by requests, countries by
     * visitors, bots by bytes. Returns how many rows were folded.
     */
    public function fold(string $since, int $keep, int $threshold): int
    {
        return (int) DB::selectOne(<<<'SQL'
            WITH ranked AS (
                SELECT id, count(*) OVER w AS n,
                    row_number() OVER (PARTITION BY site_id, day, kind
                        ORDER BY CASE kind WHEN 'country' THEN visitors WHEN 'bot' THEN bytes ELSE requests END DESC, requests DESC, value) AS rn
                FROM site_stats_items
                WHERE day >= ? AND value <> ''
                WINDOW w AS (PARTITION BY site_id, day, kind)
            ),
            moved AS (
                DELETE FROM site_stats_items i USING ranked r
                WHERE i.id = r.id AND r.n > ? AND r.rn > ?
                RETURNING i.site_id, i.day, i.kind, i.requests, i.bytes, i.visitors
            ),
            added AS (
                INSERT INTO site_stats_items (site_id, day, kind, value, requests, bytes, visitors)
                SELECT site_id, day, kind, '', sum(requests), sum(bytes), sum(visitors) FROM moved GROUP BY site_id, day, kind
                ON CONFLICT (site_id, day, kind, value) DO UPDATE SET
                    requests = site_stats_items.requests + excluded.requests,
                    bytes = site_stats_items.bytes + excluded.bytes,
                    visitors = site_stats_items.visitors + excluded.visitors
                RETURNING 1
            )
            SELECT (SELECT count(*) FROM moved) AS n
            SQL, [$since, $threshold, $keep])->n;
    }

    /**
     * Drops rows past their keeping time and folds finished days to their
     * top values. Returns how many rows were removed or folded.
     */
    public function prune(): int
    {
        $tz = config('servik.timezone');
        $today = now($tz)->startOfDay();
        $n = DB::table('site_stats_hourly')->where('hour', '<', now()->subDays(self::HOURLY_DAYS))->delete();
        $n += DB::table('site_stats_daily')->where('day', '<', $today->copy()->subMonthsNoOverflow(self::DAILY_MONTHS)->toDateString())->delete();
        $n += DB::table('mail_stats_daily')->where('day', '<', $today->copy()->subMonthsNoOverflow(self::DAILY_MONTHS)->toDateString())->delete();
        $n += DB::table('site_traffic')->where('month', '<', $today->copy()->subMonthsNoOverflow(self::DAILY_MONTHS)->startOfMonth()->toDateString())->delete();
        $n += DB::table('site_stats_items')->where('day', '<', $today->copy()->subMonthsNoOverflow(self::ITEM_MONTHS)->toDateString())->delete();
        // Yesterday and the day before are final (late hours land there).
        $n += $this->fold($today->copy()->subDays(3)->toDateString(), self::TOP, self::TOP);

        return $n;
    }
}
