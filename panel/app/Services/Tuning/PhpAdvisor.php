<?php

namespace App\Services\Tuning;

use App\Models\Site;
use App\Models\TuningSample;
use App\Services\Php\PhpVersions;
use App\Support\Bytes;
use App\Support\Num;

/**
 * Works out suggestions for one PHP-FPM version from two of its
 * measurements: OPcache (memory, interned strings, file table; shared by
 * every site of the version) and each site's PHP-FPM pool (workers that
 * ran out, workers idling). Like MysqlAdvisor: well-known rules, each with
 * the range that is still safe, from which Serfex AI may pick.
 *
 * OPcache values go into the version's php.ini defaults (values: {ini:
 * {...}}); pool values into the site's PHP settings (values: {site, ...}).
 * Either way PHP-FPM reloads: running requests finish, OPcache fills
 * again.
 */
class PhpAdvisor
{
    /** OPcache memory taken above this share is worth growing. */
    public const OPCACHE_FULL = 90.0;

    /** OPcache memory sizes offered, in MB. */
    public const OPCACHE_STEPS = [128, 192, 256, 384, 512, 768, 1024];

    /** File table sizes offered. */
    public const FILE_STEPS = [10000, 20000, 30000, 50000, 100000, 200000, 500000, 1000000];

    /** The suggestion each php.ini setting belongs to (for locks). */
    public const INI_KEYS = [
        'opcache.enable' => 'opcache_enable', 'opcache.memory_consumption' => 'opcache_memory',
        'opcache.interned_strings_buffer' => 'opcache_strings', 'opcache.max_accelerated_files' => 'opcache_files',
    ];

    /** Workers a pool may get at most from a suggestion. */
    public const MAX_CHILDREN = 200;

    /**
     * @param  array<string, array{text?: string, value?: int}>  $ai  Serfex AI's notes by suggestion key
     * @return array<string, mixed> like MysqlAdvisor::analyze()
     */
    public function analyze(TuningSample $latest, ?TuningSample $since, MemoryBudget $budget, array $ai = []): array
    {
        $m = new PhpMeasured($latest, $since);
        $out = ['measured' => true, 'hours' => $m->hours, 'cells' => $this->cells($m), 'suggestions' => [], 'fine' => [], 'notice' => null];
        $o = $m->opcache();
        if ($o === null) {
            $out['notice'] = __('OPcache\'in durumu okunamadı: :error', ['error' => (string) ($latest->data['opcache_error'] ?? '—')]);
        }
        $found = [];
        if ($o !== null && ($o['loaded'] ?? false)) {
            if (! ($o['enabled'] ?? false)) {
                $found[] = $this->suggestEnable($o);
            } else {
                $found[] = $this->suggestMemory($m, $o, $out['fine']);
                $found[] = $this->suggestStrings($o, $out['fine']);
                $found[] = $this->suggestFiles($m, $o, $out['fine']);
            }
        }
        $sites = Site::whereIn('domain', array_column($m->pools(), 'domain'))->get()->keyBy('domain');
        $calm = 0;
        foreach ($m->pools() as $pool) {
            $site = $sites[$pool['domain']] ?? null;
            if ($site === null || ! ($pool['read'] ?? false)) {
                continue;
            }
            $children = $this->suggestChildren($m, $pool, $site);
            $pm = $this->suggestOndemand($pool, $site);
            if ($children === null && $pm === null) {
                $calm++;
            }
            array_push($found, $children, $pm);
        }
        foreach (array_filter($found) as $s) {
            $value = $ai[$s['key']]['value'] ?? null;
            if (is_int($value) && isset($s['range']) && $value >= $s['range'][0] && $value <= $s['range'][1] && $value !== $s['target']) {
                $s = $this->retarget($s, $value);
            }
            $s['why_ai'] = isset($ai[$s['key']]['text']) ? (string) $ai[$s['key']]['text'] : null;
            $s['disruption'] = 'reload';
            $s['blocked'] = $budget->reserve($s['memory_mb'], 'php-fpm');
            $out['suggestions'][] = $s;
        }
        if ($calm > 0) {
            $out['fine'][] = [__('Site havuzları'), 'pm.max_children', __(':count site', ['count' => $calm]), __('Bu sitelerin PHP işçileri sınıra dayanmadı.')];
        }
        if ($o !== null && ($o['enabled'] ?? false)) {
            $out['fine'][] = [__('Değişen dosyaları denetle'), 'opcache.validate_timestamps', ($o['validate_timestamps'] ?? false) ? __('açık') : __('kapalı'),
                __('Serfex bunu kapatmaz: kapalıyken yüklenen yeni kod, PHP-FPM yeniden başlayana kadar çalışmaz.')];
        }
        $out['budget'] = $budget->summary();

        return $out;
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private function cells(PhpMeasured $m): array
    {
        $o = $m->opcache();
        $full = $m->memoryFull();
        $hit = $m->hitRatio();
        $restarts = $m->opcacheDelta('oom_restarts');
        $pools = array_filter($m->pools(), fn ($p) => $p['read'] ?? false);
        $tight = count(array_filter($pools, fn ($p) => $m->poolDelta($p, 'max_reached') > 0));

        return [
            [__('OPcache belleği'), $full === null ? '—' : __(':share dolu', ['share' => Num::percent($full)]),
                $o === null ? __('okunamadı') : ($restarts > 0 ? __(':count kez doldu, boşaltıldı', ['count' => Num::format($restarts)]) : Bytes::format(((int) ($o['memory_mb'] ?? 0)) << 20)),
                $full !== null && ($full >= self::OPCACHE_FULL || $restarts > 0) ? 'warn' : ''],
            [__('OPcache isabeti'), $hit === null ? '—' : Num::percent($hit, 1), __('Hedef %:target üstü', ['target' => 99]), $hit !== null && $hit < 99 ? 'warn' : ''],
            [__('Önbellekteki dosya'), $o === null ? '—' : Num::format((int) ($o['cached_keys'] ?? 0)).' / '.Num::format((int) ($o['max_cached_keys'] ?? 0)),
                __('opcache.max_accelerated_files :count', ['count' => Num::format((int) ($o['max_files'] ?? 0))]),
                $o !== null && (int) ($o['max_cached_keys'] ?? 0) > 0 && (int) $o['cached_keys'] >= 0.85 * (int) $o['max_cached_keys'] ? 'warn' : ''],
            [__('İşçisi yetmeyen site'), Num::format($tight).' / '.Num::format(count($pools)), __('İşçi sınırına dayanan site havuzları'), $tight > 0 ? 'warn' : ''],
        ];
    }

    /**
     * @param  array<string, mixed>  $o
     * @return array<string, mixed>
     */
    private function suggestEnable(array $o): array
    {
        $mb = max(64, (int) ($o['memory_mb'] ?? 128));

        return [
            'key' => 'opcache_enable',
            'title' => __('OPcache\'i aç'),
            'title_key' => 'OPcache\'i aç',
            'setting' => 'opcache.enable',
            'target' => 1,
            'values' => ['ini' => ['opcache.enable' => '1']],
            'from_label' => __('kapalı'), 'to_label' => __('açık'), 'from_raw' => 'off', 'to_raw' => 'on',
            'memory_mb' => $mb,
            'measure' => __('OPcache kapalı'),
            'why' => __('OPcache kapalıyken PHP her istekte kodu yeniden derler. Açmak siteleri belirgin hızlandırır ve :size bellek alır.', ['size' => Bytes::format($mb << 20)]),
        ];
    }

    /**
     * @param  array<string, mixed>  $o
     * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $fine
     * @return array<string, mixed>|null
     */
    private function suggestMemory(PhpMeasured $m, array $o, array &$fine): ?array
    {
        $current = (int) ($o['memory_mb'] ?? 0);
        $full = $m->memoryFull() ?? 0.0;
        $restarts = $m->opcacheDelta('oom_restarts');
        if ($current <= 0 || $current >= 1024 || ($full < self::OPCACHE_FULL && $restarts === 0)) {
            $fine[] = [__('OPcache belleği'), 'opcache.memory_consumption', Bytes::format($current << 20),
                __(':share kadarı kullanılıyor; yer var.', ['share' => Num::percent($full)])];

            return null;
        }
        $need = (int) ceil(((float) $o['used_mb'] + (float) $o['wasted_mb']) * 1.3);
        $target = self::step(self::OPCACHE_STEPS, max($current + 1, $need));

        return [
            'key' => 'opcache_memory',
            'title' => __('OPcache belleğini büyüt'),
            'title_key' => 'OPcache belleğini büyüt',
            'setting' => 'opcache.memory_consumption',
            'target' => $target,
            'range' => [$current + 32, min(2048, $target * 2)],
            'values' => ['ini' => ['opcache.memory_consumption' => (string) $target]],
            'from_label' => Bytes::format($current << 20), 'to_label' => Bytes::format($target << 20),
            'from_raw' => 'mb:'.$current, 'to_raw' => 'mb:'.$target,
            'memory_mb' => $target - $current,
            'measure' => $restarts > 0
                ? __(':share dolu · :count kez boşaltıldı', ['share' => Num::percent($full), 'count' => Num::format($restarts)])
                : __(':share dolu', ['share' => Num::percent($full)]),
            'why' => $restarts > 0
                ? __('OPcache belleği doldu ve PHP önbelleği :count kez baştan kurdu; o sırada bütün siteler kodu yeniden derledi. Öneri: kullanılan × 1,3.', ['count' => Num::format($restarts)])
                : __('OPcache belleğinin :share kadarı dolu; dolunca PHP önbelleği boşaltıp baştan kurar. Öneri: kullanılan × 1,3.', ['share' => Num::percent($full)]),
        ];
    }

    /**
     * @param  array<string, mixed>  $o
     * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $fine
     * @return array<string, mixed>|null
     */
    private function suggestStrings(array $o, array &$fine): ?array
    {
        $current = (int) ($o['strings_mb'] ?? 0);
        $free = (float) ($o['strings_free_mb'] ?? 0);
        if ($current <= 0 || $current >= 256 || $free >= 0.1 * $current) {
            return null;
        }
        $target = min(256, MysqlAdvisor::roundUp((int) ceil($current * 1.5), 8));

        return [
            'key' => 'opcache_strings',
            'title' => __('OPcache metin belleğini büyüt'),
            'title_key' => 'OPcache metin belleğini büyüt',
            'setting' => 'opcache.interned_strings_buffer',
            'target' => $target,
            'range' => [$current + 4, 256],
            'values' => ['ini' => ['opcache.interned_strings_buffer' => (string) $target]],
            'from_label' => Bytes::format($current << 20), 'to_label' => Bytes::format($target << 20),
            'from_raw' => 'mb:'.$current, 'to_raw' => 'mb:'.$target,
            'memory_mb' => $target - $current,
            'measure' => __(':free boş', ['free' => Bytes::format((int) ($free * 1048576))]),
            'why' => __('Sınıf ve fonksiyon adları gibi tekrar eden metinlerin belleği dolmak üzere; dolunca her işçi bunları kendi belleğinde tutar. Öneri: × 1,5.'),
        ];
    }

    /**
     * @param  array<string, mixed>  $o
     * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $fine
     * @return array<string, mixed>|null
     */
    private function suggestFiles(PhpMeasured $m, array $o, array &$fine): ?array
    {
        $current = (int) ($o['max_files'] ?? 0);
        $keys = (int) ($o['cached_keys'] ?? 0);
        $max = (int) ($o['max_cached_keys'] ?? 0);
        $restarts = $m->opcacheDelta('hash_restarts');
        if ($current <= 0 || $max <= 0 || $current >= 1000000 || ($keys < 0.85 * $max && $restarts === 0)) {
            if ($max > 0) {
                $fine[] = [__('OPcache dosya sayısı'), 'opcache.max_accelerated_files', Num::format($current),
                    __(':count dosya önbellekte, :max yer var.', ['count' => Num::format($keys), 'max' => Num::format($max)])];
            }

            return null;
        }
        $target = self::step(self::FILE_STEPS, max($current + 1, $keys * 2));

        return [
            'key' => 'opcache_files',
            'title' => __('OPcache dosya sayısını artır'),
            'title_key' => 'OPcache dosya sayısını artır',
            'setting' => 'opcache.max_accelerated_files',
            'target' => $target,
            'range' => [$current + 1000, 1000000],
            'values' => ['ini' => ['opcache.max_accelerated_files' => (string) $target]],
            'from_label' => Num::format($current), 'to_label' => Num::format($target),
            'from_raw' => 'num:'.$current, 'to_raw' => 'num:'.$target,
            'memory_mb' => 0,
            'measure' => __(':count / :max dosya', ['count' => Num::format($keys), 'max' => Num::format($max)]),
            'why' => __('Önbellekteki dosya sayısı sınıra yaklaştı; sınır dolunca yeni dosyalar her istekte yeniden derlenir. Öneri: şu anki dosya sayısının iki katı.'),
        ];
    }

    /**
     * More workers for a pool that ran out of them.
     *
     * @param  array<string, mixed>  $pool
     * @return array<string, mixed>|null
     */
    private function suggestChildren(PhpMeasured $m, array $pool, Site $site): ?array
    {
        $current = (int) (($site->php_settings ?? [])['max_children'] ?? 0) ?: PhpVersions::DEFAULT_MAX_CHILDREN;
        $reached = $m->poolDelta($pool, 'max_reached');
        // A temporary limit (Canlı İzleme) holds the pool lower for now.
        if ($reached === 0 || (int) ($pool['max_children'] ?? 0) !== $current || $current >= self::MAX_CHILDREN) {
            return null;
        }
        $target = min(self::MAX_CHILDREN, max($current + 2, (int) ceil($current * 1.5)));
        $worker = PhpMeasured::workerMB($pool);

        return [
            'key' => 'fpm_children:'.$site->id,
            'site' => $site->domain,
            'title' => __('PHP işçi sayısını artır'),
            'title_key' => 'PHP işçi sayısını artır',
            'setting' => 'pm.max_children · '.$site->domain,
            'target' => $target,
            'range' => [$current + 1, min(self::MAX_CHILDREN, $current * 3)],
            'values' => ['site' => $site->id, 'max_children' => $target],
            'from_label' => Num::format($current), 'to_label' => Num::format($target),
            'from_raw' => 'num:'.$current, 'to_raw' => 'num:'.$target,
            'memory_mb' => (int) ceil(($target - $current) * $worker),
            'worker_mb' => $worker,
            'measure' => __(':count kez sınıra dayandı · en çok :queue istek bekledi', ['count' => Num::format($reached), 'queue' => Num::format((int) ($pool['max_queue'] ?? 0))]),
            'why' => __(':domain için bütün PHP işçileri meşgulken yeni istekler sırada bekledi. Öneri: × 1,5; bir işçi ortalama :size bellek kullanıyor.', [
                'domain' => $site->domain, 'size' => Bytes::format((int) ($worker * 1048576))]),
        ];
    }

    /**
     * Workers that wait without work: a quiet site does not need them.
     *
     * @param  array<string, mixed>  $pool
     * @return array<string, mixed>|null
     */
    private function suggestOndemand(array $pool, Site $site): ?array
    {
        $pm = (string) ($pool['pm'] ?? 'ondemand');
        $children = (int) ($pool['max_children'] ?? 0);
        $busiest = (int) ($pool['max_active'] ?? 0);
        $started = (int) ($pool['start_time'] ?? 0);
        $idle = (int) ($pool['idle'] ?? 0);
        if (! in_array($pm, ['dynamic', 'static'], true) || $children < 4 || $busiest > max(1, intdiv($children, 4)) || $idle < 2
            || $started <= 0 || $started > now()->subDay()->timestamp) {
            return null;
        }
        $freed = (int) floor($idle * PhpMeasured::workerMB($pool));

        return [
            'key' => 'fpm_pm:'.$site->id,
            'site' => $site->domain,
            'title' => __('Boşta bekleyen PHP işçilerini kapat'),
            'title_key' => 'Boşta bekleyen PHP işçilerini kapat',
            'setting' => 'pm · '.$site->domain,
            'target' => 0,
            'values' => ['site' => $site->id, 'pm' => 'ondemand'],
            'from_label' => $pm, 'to_label' => 'ondemand', 'from_raw' => 'text:'.$pm, 'to_raw' => 'text:ondemand',
            'memory_mb' => -$freed,
            'measure' => __('en çok :busy işçi aynı anda çalıştı · :idle işçi boşta', ['busy' => Num::format($busiest), 'idle' => Num::format($idle)]),
            'why' => __(':domain sakin: :children işçiden en çok :busy tanesi aynı anda çalıştı. "İstek geldikçe" ile işçiler istekle başlar, boşta kalınca kapanır; :size bellek boşalır.', [
                'domain' => $site->domain, 'children' => Num::format($children), 'busy' => Num::format($busiest), 'size' => Bytes::format($freed << 20)]),
        ];
    }

    /**
     * The suggestion with another value in its range.
     *
     * @param  array<string, mixed>  $s
     * @return array<string, mixed>
     */
    private function retarget(array $s, int $value): array
    {
        $old = $s['target'];
        $s['target'] = $value;
        if (isset($s['values']['ini'])) {
            $s['values']['ini'] = [array_key_first($s['values']['ini']) => (string) $value];
        } else {
            $s['values']['max_children'] = $value;
        }
        if (str_starts_with($s['to_raw'], 'mb:')) {
            $s['to_label'] = Bytes::format($value << 20);
            $s['to_raw'] = 'mb:'.$value;
            $s['memory_mb'] += $value - $old;
        } else {
            $s['to_label'] = Num::format($value);
            $s['to_raw'] = 'num:'.$value;
            if (isset($s['worker_mb'])) {
                $s['memory_mb'] = (int) ceil(($value - (int) substr($s['from_raw'], 4)) * $s['worker_mb']);
            }
        }
        $s['memory_mb'] = max(0, $s['memory_mb']);

        return $s;
    }

    /**
     * The smallest of $steps at least $value (the largest when none is).
     *
     * @param  list<int>  $steps
     */
    public static function step(array $steps, int $value): int
    {
        foreach ($steps as $s) {
            if ($s >= $value) {
                return $s;
            }
        }

        return end($steps);
    }
}
