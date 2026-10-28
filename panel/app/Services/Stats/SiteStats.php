<?php

namespace App\Services\Stats;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Locale;

/**
 * Reads a site's (or several sites') statistics for a period: totals, a
 * series for the chart and the top pages, referrers, countries and bots.
 *
 * Days are the panel's time zone. A period's visitors add up each day's
 * distinct visitors, so someone who came on three days counts three
 * times, as in most hosting statistics.
 */
class SiteStats
{
    public const KINDS = ['page', 'referrer', 'country', 'bot'];

    /** Periods: key => label. */
    public const PERIODS = [
        'today' => 'Bugün',
        '7d' => 'Son 7 gün',
        '30d' => 'Son 30 gün',
        'this-month' => 'Bu ay',
        'last-month' => 'Geçen ay',
        '12m' => 'Son 12 ay',
    ];

    /** Tabs of the item table: kind => [label, column heading]. */
    public const TABS = [
        'page' => ['Sayfalar', 'Sayfa'],
        'referrer' => ['Yönlendirenler', 'Site'],
        'country' => ['Ülkeler', 'Ülke'],
        'bot' => ['Botlar', 'Bot'],
    ];

    private const COUNTS = ['requests', 'bytes', 'visitors', 'pages', 'bot_requests', 'bot_bytes', 's2xx', 's3xx', 's4xx', 's5xx'];

    /**
     * The days a period covers, and how its chart is grouped (hour, day
     * or month).
     *
     * @return array{from: CarbonImmutable, to: CarbonImmutable, step: string}
     */
    public static function range(string $period): array
    {
        $today = CarbonImmutable::now(config('serfex.timezone'))->startOfDay();

        return match ($period) {
            'today' => ['from' => $today, 'to' => $today, 'step' => 'hour'],
            '7d' => ['from' => $today->subDays(6), 'to' => $today, 'step' => 'day'],
            'this-month' => ['from' => $today->startOfMonth(), 'to' => $today, 'step' => 'day'],
            'last-month' => ['from' => $today->subMonthNoOverflow()->startOfMonth(), 'to' => $today->subMonthNoOverflow()->endOfMonth()->startOfDay(), 'step' => 'day'],
            '12m' => ['from' => $today->subMonthsNoOverflow(11)->startOfMonth(), 'to' => $today, 'step' => 'month'],
            default => ['from' => $today->subDays(29), 'to' => $today, 'step' => 'day'],
        };
    }

    /**
     * Totals of the sites over the period.
     *
     * @param  list<int>  $sites
     * @return array<string, int>
     */
    public function totals(array $sites, string $period): array
    {
        ['from' => $from, 'to' => $to] = self::range($period);
        $row = (array) DB::table('site_stats_daily')->whereIn('site_id', $sites)
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->selectRaw(implode(', ', array_map(fn ($c) => "coalesce(sum({$c}), 0) as {$c}", self::COUNTS)))
            ->first();

        return array_map('intval', $row);
    }

    /**
     * One point per hour, day or month of the period, with no gaps.
     *
     * @param  list<int>  $sites
     * @return list<array{at: CarbonImmutable, label: string, visitors: int, requests: int, bytes: int, pages: int, bot_requests: int}>
     */
    public function series(array $sites, string $period): array
    {
        ['from' => $from, 'to' => $to, 'step' => $step] = self::range($period);
        $tz = config('serfex.timezone');
        $cols = 'sum(visitors) as visitors, sum(requests) as requests, sum(bytes) as bytes, sum(pages) as pages, sum(bot_requests) as bot_requests';
        $points = [];
        if ($step === 'hour') {
            $rows = DB::table('site_stats_hourly')->whereIn('site_id', $sites)
                ->whereBetween('hour', [$from->utc()->toDateTimeString(), $from->addDay()->utc()->subSecond()->toDateTimeString()])
                ->groupBy('hour')->selectRaw("hour, {$cols}")->get()
                ->keyBy(fn ($r) => CarbonImmutable::parse($r->hour, 'UTC')->timezone($tz)->format('H'));
            for ($at = $from; $at->lt($from->addDay()); $at = $at->addHour()) {
                $points[] = self::point($at, $at->format('H:00'), $rows[$at->format('H')] ?? null);
            }

            return $points;
        }
        $query = DB::table('site_stats_daily')->whereIn('site_id', $sites)->whereBetween('day', [$from->toDateString(), $to->toDateString()]);
        if ($step === 'month') {
            $rows = $query->groupByRaw("to_char(day, 'YYYY-MM')")->selectRaw("to_char(day, 'YYYY-MM') as k, {$cols}")->get()->keyBy('k');
            for ($at = $from; $at->lte($to); $at = $at->addMonthNoOverflow()) {
                $points[] = self::point($at, self::monthName($at), $rows[$at->format('Y-m')] ?? null);
            }

            return $points;
        }
        $rows = $query->groupBy('day')->selectRaw("day::text as k, {$cols}")->get()->keyBy('k');
        for ($at = $from; $at->lte($to); $at = $at->addDay()) {
            $points[] = self::point($at, $at->format('d.m'), $rows[$at->toDateString()] ?? null);
        }

        return $points;
    }

    private static function point(CarbonImmutable $at, string $label, ?object $row): array
    {
        return [
            'at' => $at, 'label' => $label,
            'visitors' => (int) ($row->visitors ?? 0), 'requests' => (int) ($row->requests ?? 0),
            'bytes' => (int) ($row->bytes ?? 0), 'pages' => (int) ($row->pages ?? 0), 'bot_requests' => (int) ($row->bot_requests ?? 0),
        ];
    }

    /**
     * The top pages, referrers, countries or bots of the period. The
     * share is of the kind's total requests (bytes for bots).
     *
     * @param  list<int>  $sites
     * @return list<array{value: string, label: string, requests: int, bytes: int, visitors: int, share: float}>
     */
    public function items(array $sites, string $period, string $kind, int $limit = 50): array
    {
        ['from' => $from, 'to' => $to] = self::range($period);
        $base = DB::table('site_stats_items')->whereIn('site_id', $sites)->where('kind', $kind)
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()]);
        $total = (int) (clone $base)->sum($kind === 'bot' ? 'bytes' : 'requests');
        $rows = (clone $base)->where('value', '!=', '')->groupBy('value')
            ->selectRaw('value, sum(requests) as requests, sum(bytes) as bytes, sum(visitors) as visitors')
            ->orderByDesc($kind === 'country' ? 'visitors' : ($kind === 'bot' ? 'bytes' : 'requests'))->orderByDesc('requests')->orderBy('value')
            ->limit($limit)->get();

        return $rows->map(fn ($r) => [
            'value' => $r->value,
            'label' => self::label($kind, $r->value),
            'requests' => (int) $r->requests,
            'bytes' => (int) $r->bytes,
            'visitors' => (int) $r->visitors,
            'share' => $total > 0 ? ((int) ($kind === 'bot' ? $r->bytes : $r->requests)) / $total : 0.0,
        ])->all();
    }

    /**
     * How a page, referrer, country or bot is shown.
     */
    public static function label(string $kind, string $value): string
    {
        return match ($kind) {
            'country' => self::country($value),
            'bot' => match ($value) {
                '-' => __('Tarayıcı bilgisi göndermeyenler'),
                'other-tool' => __('Diğer araçlar ve kütüphaneler'),
                'other-bot' => __('Diğer botlar'),
                default => $value,
            },
            default => $value,
        };
    }

    /**
     * "Türkiye" for TR; "Bilinmiyor" for unknown addresses.
     */
    public static function country(string $code): string
    {
        if ($code === '-' || strlen($code) !== 2) {
            return __('Bilinmiyor');
        }
        $name = class_exists(Locale::class) ? Locale::getDisplayRegion('-'.$code, app()->getLocale()) : '';

        return $name !== '' && $name !== $code ? $name : $code;
    }

    public static function monthName(CarbonImmutable $at): string
    {
        // "Eki 26" / "Oct 26": Carbon's short month names in the reader's language.
        return $at->locale(app()->getLocale())->isoFormat('MMM').' '.$at->format('y');
    }

    /**
     * Whether the agent has its country table yet.
     */
    public static function countriesReady(): bool
    {
        return (bool) Cache::get(StatsCollector::COUNTRIES_KEY, false);
    }

    /**
     * This month's traffic and visitors of each site, for the site list
     * and the dashboard: site_id => [bytes, visitors].
     *
     * @return array<int, array{bytes: int, visitors: int}>
     */
    public function thisMonth(): array
    {
        ['from' => $from, 'to' => $to] = self::range('this-month');

        return DB::table('site_stats_daily')->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->groupBy('site_id')->selectRaw('site_id, sum(bytes) as bytes, sum(visitors) as visitors')->get()
            ->mapWithKeys(fn ($r) => [(int) $r->site_id => ['bytes' => (int) $r->bytes, 'visitors' => (int) $r->visitors]])->all();
    }
}
