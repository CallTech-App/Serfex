<?php

namespace App\Services\Tuning;

use App\Models\TuningChange;
use App\Models\TuningSample;
use App\Services\Agent\AgentException;
use App\Services\System\SystemServices;
use App\Support\When;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Everything the optimization screens show for one database server: the
 * measurement summary, the suggestions with their state (new, scheduled,
 * being watched, rolled back), the settings left alone and why, the
 * memory budget, the changes of the last week and the slow statements,
 * with Serfex AI's notes where it wrote some.
 */
class Optimization
{
    /** Units optimization looks at in this version. */
    public const UNITS = ['mysql', 'mariadb'];

    public function __construct(
        private readonly Samples $samples,
        private readonly MysqlAdvisor $advisor,
        private readonly SlowQueries $slow,
        private readonly TuningSettings $settings,
        private readonly AiNotes $ai,
        private readonly SystemServices $services,
    ) {}

    /**
     * The running database servers optimization looks at.
     *
     * @return list<array<string, mixed>>
     */
    public function servers(): array
    {
        return array_values(array_filter($this->services->list() ?? [], fn ($s) => in_array($s['unit'], self::UNITS, true)
            && in_array($s['state'] ?? '', ['active', 'reloading'], true)));
    }

    /**
     * Measures every running database server (hourly), renews Serfex AI's
     * notes when needed and drops old measurements.
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
            }
        }
        $this->samples->prune();

        return $out;
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
        $report = $this->advisor->analyze($latest, $this->samples->since($latest), $budget, $ai);
        $report['sample'] = $latest;

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
            return ['measured' => false, 'cards' => [], 'recent' => $this->recent($unit), 'slow' => $this->slowRows($unit), 'count' => 0];
        }
        /** @var TuningSample $sample */
        $sample = $report['sample'];
        $changes = TuningChange::where('unit', $unit)->where('updated_at', '>=', now()->subDays(Tuner::UNDO_DAYS))->latest('id')->get();
        $locked = $this->settings->locked($unit);
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
                    'title' => $c->title, 'from_label' => $c->from_label, 'to_label' => $c->to_label, 'setting' => self::setting($c->key)]);
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
        return [
            'buffer_pool' => 'innodb_buffer_pool_size', 'tmp_table' => 'tmp_table_size, max_heap_table_size', 'max_connections' => 'max_connections',
            'table_open_cache' => 'table_open_cache', 'io_capacity' => 'innodb_io_capacity', 'slow_log' => 'slow_query_log, long_query_time',
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
