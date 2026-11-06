<?php

namespace App\Services\Tuning;

use App\Models\LaravelApp;
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
 * server, a PHP-FPM version, Nginx, the customers' PostgreSQL, Memcached
 * or the sites' Redis instances together): the measurement summary, the suggestions
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

    /** The "unit" of the sites' own Redis instances, measured together. */
    public const REDIS = 'redis-sites';

    /** Order of the kinds on the overview. */
    public const ORDER = ['database', 'php', 'postgresql', 'nginx', 'memcached', 'redis'];

    public function __construct(
        private readonly Samples $samples,
        private readonly MysqlAdvisor $advisor,
        private readonly PhpAdvisor $php,
        private readonly SlowQueries $slow,
        private readonly TuningSettings $settings,
        private readonly AiNotes $ai,
        private readonly SystemServices $services,
        private readonly Tuner $tuner,
        private readonly NginxAdvisor $nginx,
        private readonly PostgresAdvisor $postgres,
        private readonly MemcachedAdvisor $memcached,
        private readonly RedisAdvisor $redis,
    ) {}

    /**
     * What kind of service a unit is for optimization: database, php,
     * nginx, postgresql, memcached, redis or null when optimization does
     * not look at it.
     */
    public static function kind(string $unit): ?string
    {
        return match (true) {
            in_array($unit, self::UNITS, true) => 'database',
            (bool) preg_match('/^php\d\.\d-fpm$/', $unit) => 'php',
            $unit === 'nginx' => 'nginx',
            (bool) preg_match('/^postgresql@\d+-main$/', $unit) => 'postgresql',
            $unit === 'memcached' => 'memcached',
            $unit === self::REDIS => 'redis',
            default => null,
        };
    }

    /**
     * The settings (of Hizmetler → Ayarlar) each suggestion of a unit
     * changes, for locks: suggestion key => setting.
     *
     * @return array<string, string>
     */
    public static function fields(string $unit): array
    {
        return match (self::kind($unit)) {
            'database' => MysqlAdvisor::FIELDS,
            'nginx' => NginxAdvisor::FIELDS,
            'postgresql' => PostgresAdvisor::FIELDS,
            'memcached' => MemcachedAdvisor::FIELDS,
            default => [],
        };
    }

    /**
     * What applying a suggestion does to the service: none, reload (open
     * requests finish) or restart (a few seconds without it).
     */
    public static function disruption(string $unit, string $key): string
    {
        return match (self::kind($unit)) {
            'php', 'nginx' => 'reload',
            'postgresql' => $key === 'pg_shared_buffers' ? 'restart' : 'reload',
            'memcached' => 'restart',
            default => 'none',
        };
    }

    /**
     * The disruption in words: short (on the card) or long (in the Apply
     * dialog).
     */
    public static function disruptionText(string $unit, string $disruption, bool $long = false): string
    {
        $kind = self::kind($unit);

        return match (true) {
            $disruption === 'reload' && $kind === 'php' => $long ? __('Yok. PHP-FPM yeniden yüklenir: süren istekler tamamlanır, OPcache baştan dolar.') : __('PHP-FPM yeniden yüklenir, istekler kesilmez'),
            $disruption === 'reload' && $kind === 'nginx' => $long ? __('Yok. Nginx yeniden yüklenir: açık bağlantılar tamamlanır.') : __('Nginx yeniden yüklenir, bağlantılar kesilmez'),
            $disruption === 'reload' => $long ? __('Yok. PostgreSQL ayarlarını yeniden okur; açık bağlantılar sürer.') : __('PostgreSQL ayarlarını yeniden okur, bağlantılar kesilmez'),
            $disruption === 'restart' && $kind === 'memcached' => $long ? __('Birkaç saniye. Memcached yeniden başlar ve önbellek boş başlar; siteler verileri yeniden hesaplar. Bakım penceresi önerilir.') : __('Memcached yeniden başlar, önbellek boşalır'),
            $disruption === 'restart' => $long ? __('Birkaç saniye. PostgreSQL yeniden başlar; açık bağlantılar kesilir, uygulamalar yeniden bağlanır. Bakım penceresi önerilir.') : __('PostgreSQL yeniden başlar, birkaç saniye bağlantı kesilir'),
            default => $long ? __('Yok. Çalışan sunucuya yeniden başlatmadan uygulanır.') : __('Kesintisiz uygulanır'),
        };
    }

    /** The line under the suggestions: where changes go, how they are watched. */
    public static function footer(string $unit): string
    {
        $watch = ['minutes' => Tuner::WATCH_MINUTES, 'days' => Tuner::UNDO_DAYS];

        return match (self::kind($unit)) {
            'php' => __('OPcache ayarları bu PHP sürümünün php.ini varsayılanlarına, işçi ayarları sitenin PHP ayarlarına yazılır; PHP-FPM yeniden yüklenir, süren istekler tamamlanır. Sonraki :minutes dakika gözlenir; hizmet durursa ya da sunucu takas kullanmaya başlarsa eski değere kendiliğinden döner. Uygulananlar :days gün Geri al ile geri alınabilir.', $watch),
            'nginx' => __('Değişiklikler Nginx ayarlarına yazılır, yapılandırma denenir ve Nginx yeniden yüklenir. Sonraki :minutes dakika gözlenir; hizmet durursa eski değere kendiliğinden döner. Uygulananlar :days gün Geri al ile geri alınabilir.', $watch),
            'postgresql' => __('Değişiklikler PostgreSQL\'in Serfex ayar dosyasına (conf.d/zz-serfex-tuning.conf) yazılır ve PostgreSQL\'e denetletilir; önbellek değişikliği yeniden başlatma ister. Sonraki :minutes dakika gözlenir; hizmet durursa ya da sunucu takas kullanmaya başlarsa eski değere kendiliğinden döner. Uygulananlar :days gün Geri al ile geri alınabilir.', $watch),
            'memcached' => __('Değişiklikler /etc/memcached.conf dosyasına yazılır, Memcached yeniden başlar ve önbellek boş başlar. Sonraki :minutes dakika gözlenir; hizmet durursa ya da sunucu takas kullanmaya başlarsa eski değere kendiliğinden döner. Uygulananlar :days gün Geri al ile geri alınabilir.', $watch),
            'redis' => __('Her Laravel uygulamasının kendi Redis\'i vardır. Yeni bellek sınırı çalışan Redis\'e yeniden başlatmadan verilir ve ayar dosyasına yazılır. Sonraki :minutes dakika gözlenir; sunucu takas kullanmaya başlarsa eski değere kendiliğinden döner. Uygulananlar :days gün Geri al ile geri alınabilir.', $watch),
            default => __('Her değişiklik önce ayar dosyasına yazılır ve çalışan sunucuya yeniden başlatmadan uygulanır. Sonraki :minutes dakika gözlenir; hizmet durursa ya da sunucu takas kullanmaya başlarsa eski değere kendiliğinden döner. Uygulananlar :days gün Geri al ile geri alınabilir.', $watch),
        };
    }

    /** Where a unit's suggestions are shown. */
    public static function url(string $unit): string
    {
        return $unit === self::REDIS ? route('services.redis') : route('services.show', ['unit' => $unit, 'tab' => 'recs']);
    }

    public static function supports(string $unit): bool
    {
        return self::kind($unit) !== null;
    }

    /**
     * The running services optimization looks at, in ORDER: the database
     * server, the PHP-FPM versions, PostgreSQL, Nginx, Memcached and the
     * sites' Redis instances (while a Laravel application has one).
     *
     * @return list<array<string, mixed>>
     */
    public function servers(): array
    {
        $list = array_values(array_filter($this->services->list() ?? [], fn ($s) => self::supports($s['unit'])
            && in_array($s['state'] ?? '', ['active', 'reloading'], true)));
        if (LaravelApp::whereNotNull('redis_port')->exists()) {
            $list[] = ['unit' => self::REDIS, 'name' => __('Redis (site önbellekleri)'), 'state' => 'active'];
        }
        $rank = fn ($s) => [array_search(self::kind($s['unit']), self::ORDER, true), $s['unit']];
        usort($list, fn ($a, $b) => $rank($a) <=> $rank($b));

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
     * need no reload are made now, reloads in the maintenance window;
     * changes that restart a service are left to people. A suggestion a person undid, or the server rolled back, in the last
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
            // A restart is never made by itself.
            if ($card['state'] !== 'new' || $card['blocked'] !== null || ! self::inBand($card) || ($card['disruption'] ?? 'none') === 'restart') {
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
        $advisor = match (self::kind($unit)) {
            'php' => $this->php,
            'nginx' => $this->nginx,
            'postgresql' => $this->postgres,
            'memcached' => $this->memcached,
            'redis' => $this->redis,
            default => $this->advisor,
        };
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
            return ['measured' => false, 'cards' => [], 'recent' => $this->recent($unit), 'slow' => $this->slowRows($unit), 'count' => 0, 'kind' => self::kind($unit), 'footer' => self::footer($unit)];
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
                    'disruption' => self::disruption($unit, $c->key)]);
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
            'footer' => self::footer($unit),
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
        if (preg_match('/^redis_memory:(\d+)$/', $key, $m)) {
            return 'maxmemory · '.(LaravelApp::with('site')->find((int) $m[1])?->site?->domain ?? '—');
        }

        return [
            'buffer_pool' => 'innodb_buffer_pool_size', 'tmp_table' => 'tmp_table_size, max_heap_table_size', 'max_connections' => 'max_connections',
            'table_open_cache' => 'table_open_cache', 'io_capacity' => 'innodb_io_capacity', 'slow_log' => 'slow_query_log, long_query_time',
            'opcache_enable' => 'opcache.enable', 'opcache_memory' => 'opcache.memory_consumption', 'opcache_strings' => 'opcache.interned_strings_buffer',
            'opcache_files' => 'opcache.max_accelerated_files',
            'nginx_connections' => 'worker_connections', 'nginx_nofile' => 'worker_rlimit_nofile', 'nginx_workers' => 'worker_processes',
            'pg_shared_buffers' => 'shared_buffers', 'pg_work_mem' => 'work_mem', 'pg_effective_cache' => 'effective_cache_size',
            'pg_io' => 'random_page_cost, effective_io_concurrency', 'memcached_memory' => '-m', 'memcached_connections' => '-c',
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
