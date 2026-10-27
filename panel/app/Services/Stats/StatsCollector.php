<?php

namespace App\Services\Stats;

use App\Models\Setting;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Collects what the agent counted from the access and mail logs
 * (stats.collect) and adds it to the hourly and daily tables, the top
 * pages, referrers, countries and bots, the mail domains' days and the
 * sites' monthly traffic that package quotas are measured against.
 *
 * The agent hands out a batch until the next call names it; the name of
 * the batch stored last is saved in the same transaction as its numbers,
 * so a batch is stored exactly once.
 */
class StatsCollector
{
    /** The batch stored last. */
    public const ACK_KEY = 'stats.ack';

    /** Whether the agent has its country table, for the pages. */
    public const COUNTRIES_KEY = 'serfex.stats.countries';

    private const COUNTS = ['requests', 'bytes', 'visitors', 'pages', 'bot_requests', 'bot_bytes', 's2xx', 's3xx', 's4xx', 's5xx'];

    private const MAIL = ['sent', 'received', 'rejected', 'bounced'];

    public function __construct(private readonly AgentClient $agent) {}

    /**
     * One collection. Returns the batch's name and how many site hours it
     * held ("" and 0 when there was nothing new).
     *
     * @return array{id: string, hours: int}
     */
    public function collect(): array
    {
        $ack = (string) Setting::get(self::ACK_KEY, '');
        $batch = (array) $this->agent->call('stats.collect', ['ack' => $ack]);
        Cache::forever(self::COUNTRIES_KEY, (bool) ($batch['countries'] ?? false));
        $id = (string) ($batch['id'] ?? '');
        if ($id === '' || $id === $ack) {
            return ['id' => '', 'hours' => 0];
        }
        $hours = 0;
        DB::transaction(function () use ($batch, $id, &$hours) {
            $hours = $this->store($batch);
            Setting::put(self::ACK_KEY, $id);
        });

        return ['id' => $id, 'hours' => $hours];
    }

    /**
     * Adds a batch to the tables. Returns how many site hours it held.
     *
     * @param  array<string, mixed>  $batch
     */
    public function store(array $batch): int
    {
        $tz = config('serfex.timezone');
        $ids = Site::pluck('id', 'domain');
        $hourly = $daily = $items = $traffic = [];
        foreach ($batch['sites'] ?? [] as $site) {
            $siteId = $ids[$site['domain']] ?? null;
            if ($siteId === null) {
                continue;
            }
            foreach ($site['hours'] ?? [] as $h) {
                $at = CarbonImmutable::parse($h['hour'])->utc();
                $day = $at->timezone($tz)->toDateString();
                $month = $at->timezone($tz)->startOfMonth()->toDateString();
                $counts = [];
                foreach (self::COUNTS as $c) {
                    $counts[$c] = (int) ($h[$c] ?? 0);
                }
                self::add($hourly, $siteId.'|'.$at->toDateTimeString(), ['site_id' => $siteId, 'hour' => $at->toDateTimeString()], $counts);
                self::add($daily, $siteId.'|'.$day, ['site_id' => $siteId, 'day' => $day], $counts);
                self::add($traffic, $siteId.'|'.$month, ['site_id' => $siteId, 'month' => $month], ['bytes' => $counts['bytes']]);
                foreach ((array) ($h['items'] ?? []) as $kind => $values) {
                    if (! in_array($kind, SiteStats::KINDS, true)) {
                        continue;
                    }
                    foreach ((array) $values as $value => $n) {
                        $value = mb_substr((string) $value, 0, 255);
                        self::add($items, $siteId.'|'.$day.'|'.$kind.'|'.$value, ['site_id' => $siteId, 'day' => $day, 'kind' => $kind, 'value' => $value],
                            ['requests' => (int) ($n['r'] ?? 0), 'bytes' => (int) ($n['b'] ?? 0), 'visitors' => (int) ($n['v'] ?? 0)]);
                    }
                }
            }
        }
        $mail = [];
        foreach ($batch['mail'] ?? [] as $m) {
            foreach ($m['hours'] ?? [] as $h) {
                $day = CarbonImmutable::parse($h['hour'])->timezone($tz)->toDateString();
                $counts = [];
                foreach (self::MAIL as $c) {
                    $counts[$c] = (int) ($h[$c] ?? 0);
                }
                self::add($mail, strtolower($m['domain']).'|'.$day, ['domain' => strtolower($m['domain']), 'day' => $day], $counts);
            }
        }

        self::upsert('site_stats_hourly', $hourly, ['site_id', 'hour'], self::COUNTS);
        self::upsert('site_stats_daily', $daily, ['site_id', 'day'], self::COUNTS);
        self::upsert('site_stats_items', $items, ['site_id', 'day', 'kind', 'value'], ['requests', 'bytes', 'visitors']);
        self::upsert('mail_stats_daily', $mail, ['domain', 'day'], self::MAIL);
        $now = now();
        self::upsert('site_traffic', array_map(fn ($r) => $r + ['created_at' => $now, 'updated_at' => $now], $traffic), ['site_id', 'month'], ['bytes'], ['updated_at']);

        return count($hourly);
    }

    /**
     * Adds $counts to the row under $key.
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $keys
     * @param  array<string, int>  $counts
     */
    private static function add(array &$rows, string $key, array $keys, array $counts): void
    {
        if (! isset($rows[$key])) {
            $rows[$key] = $keys + $counts;

            return;
        }
        foreach ($counts as $c => $n) {
            $rows[$key][$c] += $n;
        }
    }

    /**
     * Inserts rows, adding their counts to rows that exist.
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @param  list<string>  $unique
     * @param  list<string>  $sum
     * @param  list<string>  $replace
     */
    private static function upsert(string $table, array $rows, array $unique, array $sum, array $replace = []): void
    {
        $update = [];
        foreach ($sum as $c) {
            $update[$c] = DB::raw("{$table}.{$c} + excluded.{$c}");
        }
        foreach ($replace as $c) {
            $update[$c] = DB::raw("excluded.{$c}");
        }
        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            DB::table($table)->upsert($chunk, $unique, $update);
        }
    }
}
