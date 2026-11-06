<?php

namespace App\Services\Tuning;

use App\Models\Site;
use App\Models\TuningChange;
use App\Models\TuningSample;
use App\Services\Agent\AgentException;
use App\Services\System\SystemServices;
use App\Support\When;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Everything the optimization screens show for one service (a database
 * server or a PHP-FPM version): the measurement summary, the suggestions
 * with their state (new, scheduled, being watched, rolled back), the
 * settings left alone and why, the memory budget, the changes of the last
 * week and, for the database server, the slow statements, with Serfex
 * AI's notes where it wrote some. At the automatic level it also applies
 * small changes by itself.
 */
class Optimization
{
    /** Database servers optimization looks at. */
    public const UNITS = ['mysql', 'mariadb'];

    /** Lock "unit" of the sites' PHP-FPM pools, whichever version they run. */
    public const SITE_LOCKS = 'php-sites';

    public function __construct(
        private readonly Samples $samples,
        private readonly MysqlAdvisor $advisor,
        private readonly PhpAdvisor $php,
        private readonly SlowQueries $slow,
        private readonly TuningSettings $settings,
        private readonly AiNotes $ai,
        private readonly SystemServices $services,
        private readonly Tuner $tuner,
    ) {}

    /**
     * What kind of service a unit is for optimization: database, php or
     * null when optimization does not look at it.
     */
    public static function kind(string $unit): ?string
    {
        return match (true) {
            in_array($unit, self::UNITS, true) => 'database',
            (bool) preg_match('/^php\d\.\d-fpm$/', $unit) => 'php',
            default => null,
        };
    }

    public static function supports(string $unit): bool
    {
        return self::kind($unit) !== null;
    }

    /**
     * The running services optimization looks at: the database server
     * first, then the PHP-FPM versions.
     *
     * @return list<array<string, mixed>>
     */
    public function servers(): array
    {
        $list = array_values(array_filter($this->services->list() ?? [], fn ($s) => self::supports($s['unit'])
            && in_array($s['state'] ?? '', ['active', 'reloading'], true)));
        usort($list, fn ($a, $b) => [self::kind($a['unit']) === 'php', $a['unit']] <=> [self::kind($b['unit']) === 'php', $b['unit']]);

        return $list;
    }

    /**
     * Measures every running service (hourly), renews Serfex AI's notes
     * when needed, applies small changes at the automatic level and drops
     * old measurements.
     *
     * @return array<string, string> unit => result
     */
    public function measureAll(): array
    {
        $out = [];
        if ($this->settings->enabled()) {
            foreach ($this->servers() as $s) {
                try {
                    $this->samples->record($s['unit']);
                    $out[$s['unit']] = 'measured';
                } catch (AgentException $e) {
                    $out[$s['unit']] = $e->reason;

                    continue;
                }
                $this->refreshAi($s['unit']);
                if ($change = $this->autoApply($s['unit'])) {
                    $out[$s['unit']] .= ', '.$change->key.' '.$change->status;
                }
            }
        }
        $this->samples->prune();

        return $out;
    }

    /**
     * At the automatic level: applies one suggestion that stays in the
     * narrow band (a number changed by at most AUTO_BAND either way, in
     * the memory budget), at most one per service a day. Changes that
     * need no reload are made now, the others in the maintenance window.
     * A suggestion a person undid, or the server rolled back, in the last
     * week is left to people.
     */
    public function autoApply(string $unit): ?TuningChange
    {
        if ($this->settings->level() !== TuningSettings::AUTO) {
            return null;
        }
        if (TuningChange::where('unit', $unit)->whereNull('user_id')->where('status', '!=', 'dismissed')->where('created_at', '>=', now()->subDay())->exists()) {
            return null;
        }
        foreach ($this->report($unit)['cards'] as $card) {
            if ($card['state'] !== 'new' || $card['blocked'] !== null || ! self::inBand($card)) {
                continue;
            }
            if (TuningChange::where('unit', $unit)->where('key', $card['key'])->whereIn('status', ['undone', 'rolled_back'])
                ->where('updated_at', '>=', now()->subDays(Tuner::UNDO_DAYS))->exists()) {
                continue;
            }
            $result = $this->tuner->apply($unit, $card, ($card['disruption'] ?? 'none') === 'none', null);
            self::forgetCount($unit);

            return is_string($result) ? null : $result;
        }

        return null;
    }

    /**
     * Whether a suggestion stays in the automatic level's band: a number
     * that changes by at most TuningSettings::AUTO_BAND of itself.
     *
     * @param  array<string, mixed>  $s
     */
    public static function inBand(array $s): bool
    {
        if (! preg_match('/^(mb|num):(\d+)$/', (string) ($s['from_raw'] ?? ''), $from) || ! preg_match('/^(mb|num):(\d+)$/', (string) ($s['to_raw'] ?? ''), $to)) {
            return false;
        }
        $a = (int) $from[2];
        $b = (int) $to[2];

        return $a > 0 && abs($b - $a) <= TuningSettings::AUTO_BAND * $a;
    }

    public function refreshAi(string $unit, bool $force = false): bool
    {
        $report = $this->analyze($unit, false);
        if ($report === null) {
            return false;
        }

        return $this->ai->refresh($unit, $report, $this->slow->list($unit)['rows'], $force);
    }

    /**
     * The rules' suggestions for a server, from its latest measurement.
     *
     * @return array<string, mixed>|null null before the first measurement
     */
    public function analyze(string $unit, bool $withAi = true): ?array
    {
        $latest = $this->samples->latest($unit);
        if ($latest === null) {
            return null;
        }
        $budget = MemoryBudget::from($latest->data, $this->settings->memoryPercent(), $this->samples->peak($unit, 'php-fpm'));
        $ai = $withAi && $this->ai->available() ? $this->ai->for($unit)['suggestions'] : [];
        $advisor = self::kind($unit) === 'php' ? $this->php : $this->advisor;
        $report = $advisor->analyze($latest, $this->samples->since($latest), $budget, $ai);
        $report['sample'] = $latest;
        $report['service'] = $unit;

        return $report;
    }

    /**
     * The Öneriler tab.
     *
     * @return array<string, mixed>
     */
    public function report(string $unit): array
    {
        $report = $this->analyze($unit);
        if ($report === null) {
            return ['measured' => false, 'cards' => [], 'recent' => $this->recent($unit), 'slow' => $this->slowRows($unit), 'count' => 0, 'kind' => self::kind($unit)];
        }
        /** @var TuningSample $sample */
        $sample = $report['sample'];
        $changes = TuningChange::where('unit', $unit)->where('updated_at', '>=', now()->subDays(Tuner::UNDO_DAYS))->latest('id')->get();
        $locked = $this->settings->locked($unit) + $this->settings->locked(self::SITE_LOCKS);
        $cards = [];
        foreach ($report['suggestions'] as $s) {
            $key = $s['key'];
            if (isset($locked[$key])) {
                $report['fine'][] = [$s['title'], $s['setting'], $s['from_label'], __('Kilitli (:date, :name): optimizasyon bu ayara öneri getirmez.', [
                    'date' => When::date(Carbon::parse($locked[$key][1])), 'name' => $locked[$key][0]])];

                continue;
            }
            $mine = $changes->where('key', $key);
            $pending = $mine->firstWhere('status', 'scheduled');
            if ($pending === null && $mine->contains(fn ($c) => $c->status === 'watching'
                || ($c->status === 'dismissed' || $c->status === 'rolled_back') && $c->updated_at->gt(now()->subDays(Tuner::UNDO_DAYS)))) {
                continue;
            }
            $cards[] = ['state' => $pending ? 'scheduled' : 'new', 'change' => $pending, ...$s];
        }
        // Changes being watched, and ones rolled back today, stay in view.
        foreach ($changes as $c) {
            if ($c->status === 'watching' || ($c->status === 'rolled_back' && $c->finished_at?->gt(now()->subDay()))) {
                array_unshift($cards, ['state' => $c->status === 'watching' ? 'watching' : 'rolled', 'change' => $c, 'key' => $c->key,
                    'title' => $c->title, 'from_label' => $c->from_label, 'to_label' => $c->to_label, 'setting' => self::setting($c->key),
                    'disruption' => self::kind($unit) === 'php' ? 'reload' : 'none']);
            }
        }

        return [
            ...$report,
            'cards' => $cards,
            'count' => count(array_filter($cards, fn ($c) => $c['state'] === 'new')),
            'recent' => $this->recent($unit),
            'slow' => $this->slowRows($unit),
            'measured_at' => $sample->created_at,
            'machine' => $sample->data,
            'kind' => self::kind($unit),
        ];
    }

    /**
     * Changes in force from the last week, newest first.
     *
     * @return Collection<int, TuningChange>
     */
    public function recent(string $unit): Collection
    {
        return TuningChange::where('unit', $unit)->whereIn('status', TuningChange::IN_FORCE)
            ->where('applied_at', '>=', now()->subDays(Tuner::UNDO_DAYS))->with('user')->latest('applied_at')->get();
    }

    /**
     * Slow statements with Serfex AI's notes.
     *
     * @param  list<int>|null  $siteIds
     * @return array{rows: list<array<string, mixed>>, hours: float, available: bool}
     */
    public function slowRows(string $unit, ?array $siteIds = null): array
    {
        if (self::kind($unit) !== 'database') {
            return ['rows' => [], 'hours' => 0, 'available' => false];
        }
        $list = $this->slow->list($unit, $siteIds);
        $notes = $this->ai->available() ? $this->ai->for($unit)['queries'] : [];
        foreach ($list['rows'] as &$row) {
            $row['ai'] = $notes[$row['digest']] ?? null;
        }

        return $list;
    }

    /**
     * Number of new suggestions, for the tab badges.
     */
    public function count(string $unit): int
    {
        if (! $this->settings->enabled()) {
            return 0;
        }

        return (int) Cache::remember('tuning.count.'.$unit, 60, fn () => (int) ($this->report($unit)['count'] ?? 0));
    }

    public static function forgetCount(string $unit): void
    {
        Cache::forget('tuning.count.'.$unit);
    }

    public static function setting(string $key): string
    {
        if (preg_match('/^fpm_(children|pm):(\d+)$/', $key, $m)) {
            return ($m[1] === 'children' ? 'pm.max_children' : 'pm').' · '.(Site::whereKey((int) $m[2])->value('domain') ?? '—');
        }

        return [
            'buffer_pool' => 'innodb_buffer_pool_size', 'tmp_table' => 'tmp_table_size, max_heap_table_size', 'max_connections' => 'max_connections',
            'table_open_cache' => 'table_open_cache', 'io_capacity' => 'innodb_io_capacity', 'slow_log' => 'slow_query_log, long_query_time',
            'opcache_enable' => 'opcache.enable', 'opcache_memory' => 'opcache.memory_consumption', 'opcache_strings' => 'opcache.interned_strings_buffer',
            'opcache_files' => 'opcache.max_accelerated_files',
        ][$key] ?? $key;
    }

    /**
     * The suggestion with $key as the rules make it now (for applying).
     *
     * @return array<string, mixed>|null
     */
    public function suggestion(string $unit, string $key): ?array
    {
        foreach ($this->analyze($unit)['suggestions'] ?? [] as $s) {
            if ($s['key'] === $key) {
                return $s;
            }
        }

        return null;
    }
}
