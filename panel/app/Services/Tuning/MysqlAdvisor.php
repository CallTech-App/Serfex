<?php

namespace App\Services\Tuning;

use App\Models\TuningSample;
use App\Support\Bytes;
use App\Support\Num;

/**
 * Works out suggestions for a MySQL or MariaDB server from two of its
 * measurements: well-known rules, the same every time, each with the
 * range of values that is still safe. Serfex AI may only pick a value in
 * that range and explain it; it never writes a setting itself.
 *
 * Every suggestion is a change of the agent's database settings
 * (values), the memory it takes (memory_mb) and why. A suggestion that
 * does not fit in the memory budget is kept, blocked, with what it needs.
 */
class MysqlAdvisor
{
    /** Suggestions in the order they are offered. */
    public const KEYS = ['buffer_pool', 'tmp_table', 'max_connections', 'table_open_cache', 'io_capacity', 'slow_log'];

    /** The settings field (Ayarlar) each suggestion changes. */
    public const FIELDS = [
        'buffer_pool' => 'innodb_buffer_pool_mb',
        'max_connections' => 'max_connections',
    ];

    /** Below this share of reads served from memory the pool is too small. */
    public const HIT_TARGET = 99.0;

    /** Share of temporary tables written to disk worth acting on. */
    public const TMP_DISK_LIMIT = 25.0;

    /**
     * @param  array<string, array{text?: string, value?: int}>  $ai  Serfex AI's notes by suggestion key
     * @return array{measured: bool, hours: float, cells: list<array{0: string, 1: string, 2: string, 3: string}>,
     *     suggestions: list<array<string, mixed>>, fine: list<array{0: string, 1: string, 2: string, 3: string}>, budget: array<string, mixed>}
     */
    public function analyze(TuningSample $latest, ?TuningSample $since, MemoryBudget $budget, array $ai = []): array
    {
        $m = new Measured($latest, $since);
        $out = ['measured' => true, 'hours' => $m->hours, 'cells' => $this->cells($m), 'suggestions' => [], 'fine' => []];
        foreach (self::KEYS as $key) {
            $s = $this->{'suggest'.str_replace('_', '', ucwords($key, '_'))}($m, $out['fine']);
            if ($s === null) {
                continue;
            }
            $s['key'] = $key;
            // Serfex AI may move the value within the safe range.
            $value = $ai[$key]['value'] ?? null;
            if (is_int($value) && isset($s['range']) && $value >= $s['range'][0] && $value <= $s['range'][1] && $value !== $s['target']) {
                $s = $this->retarget($m, $s, $value);
            }
            $s['why_ai'] = isset($ai[$key]['text']) ? (string) $ai[$key]['text'] : null;
            $s['blocked'] = $budget->reserve($s['memory_mb']);
            $out['suggestions'][] = $s;
        }
        $out['fine'][] = [__('Veri güvenliği'), 'innodb_flush_log_at_trx_commit', $m->var('innodb_flush_log_at_trx_commit', '1'),
            __('Serfex bunu değiştirmez: performans için veri güvenliğinden ödün verilmez.')];
        $out['budget'] = $budget->summary();

        return $out;
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string}> label, value, note, tone
     */
    private function cells(Measured $m): array
    {
        $hit = $m->hitRatio();
        $tmp = $m->tmpDiskShare();
        $pool = $m->bytes('innodb_buffer_pool_size');

        return [
            [__('Okumaların bellekten gelen kısmı'), $hit === null ? '—' : Num::percent($hit, 1), __('Hedef %:target üstü', ['target' => (int) self::HIT_TARGET]), $hit !== null && $hit < self::HIT_TARGET ? 'warn' : ''],
            [__('Veri ve index boyutu'), Bytes::format($m->dataBytes()), __('InnoDB önbelleği :size', ['size' => Bytes::format($pool)]), $m->dataBytes() > $pool ? 'warn' : ''],
            [__('Diske düşen geçici tablo'), $tmp === null ? '—' : Num::percent($tmp), __('Günde :count tablo', ['count' => Num::format($m->perDay('Created_tmp_disk_tables'))]), $tmp !== null && $tmp > self::TMP_DISK_LIMIT ? 'warn' : ''],
            [__('En çok eşzamanlı bağlantı'), Num::format($m->int('Max_used_connections')).' / '.Num::format($m->varInt('max_connections')),
                $m->maxConnectionsTight() ? __('Sınıra yakın') : __('Sunucu başladığından beri; yeterli'), $m->maxConnectionsTight() ? 'warn' : ''],
        ];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $fine
     * @return array<string, mixed>|null
     */
    private function suggestBufferPool(Measured $m, array &$fine): ?array
    {
        $current = $m->bytes('innodb_buffer_pool_size') >> 20;
        $data = $m->dataBytes() >> 20;
        $target = max(128, self::roundUp((int) ceil($data * 1.3), 128));
        $hit = $m->hitRatio();
        if ($target <= $current * 1.1 || ($hit !== null && $hit >= 99.9 && $data <= $current)) {
            $fine[] = [__('InnoDB önbelleği'), 'innodb_buffer_pool_size', Bytes::format($current << 20),
                __('Verinin tamamı (:data) önbelleğe sığıyor.', ['data' => Bytes::format($data << 20)])];

            return null;
        }

        return [
            'title' => __('InnoDB önbelleğini büyüt'),
            'title_key' => 'InnoDB önbelleğini büyüt',
            'setting' => 'innodb_buffer_pool_size',
            'target' => $target,
            'range' => [max($current + 128, self::roundUp((int) ($target * 0.75), 128)), self::roundUp((int) ($target * 1.5), 128)],
            'values' => ['innodb_buffer_pool_mb' => $target],
            'from_label' => Bytes::format($current << 20),
            'to_label' => Bytes::format($target << 20),
            'from_raw' => 'mb:'.$current,
            'to_raw' => 'mb:'.$target,
            'memory_mb' => $target - $current,
            'measure' => __('okuma isabeti :hit · veri :data', ['hit' => $hit === null ? '—' : Num::percent($hit, 1), 'data' => Bytes::format($data << 20)]),
            'why' => __('Veri ve index boyutu (:data) InnoDB önbelleğinden (:pool) büyük; okumaların :miss kadarı diskten geliyor. Öneri: veri boyutu × 1,3, bellek bütçesi içinde.', [
                'data' => Bytes::format($data << 20), 'pool' => Bytes::format($current << 20), 'miss' => $hit === null ? '—' : Num::percent(100 - $hit, 1)]),
        ];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $fine
     * @return array<string, mixed>|null
     */
    private function suggestTmpTable(Measured $m, array &$fine): ?array
    {
        $current = min($m->bytes('tmp_table_size'), $m->bytes('max_heap_table_size')) >> 20;
        $share = $m->tmpDiskShare();
        if ($share === null || $m->delta('Created_tmp_tables') < 100) {
            return null;
        }
        if ($share <= self::TMP_DISK_LIMIT || $current >= 256) {
            $fine[] = [__('Geçici tablolar'), 'tmp_table_size', Bytes::format($current << 20),
                __('Geçici tabloların :share kadarı diske yazılıyor.', ['share' => Num::percent($share)])];

            return null;
        }
        $target = $current < 64 ? 64 : min(256, $current * 2);

        return [
            'title' => __('Geçici tablolar için daha fazla bellek ayır'),
            'title_key' => 'Geçici tablolar için daha fazla bellek ayır',
            'setting' => 'tmp_table_size, max_heap_table_size',
            'target' => $target,
            'range' => [min(256, $current + 16), 256],
            'values' => ['tmp_table_mb' => $target],
            'from_label' => Bytes::format($current << 20),
            'to_label' => Bytes::format($target << 20),
            'from_raw' => 'mb:'.$current,
            'to_raw' => 'mb:'.$target,
            // Room for two such tables at once.
            'memory_mb' => 2 * ($target - $current),
            'measure' => __(':disk / :all geçici tablo diske düştü', ['disk' => Num::format($m->delta('Created_tmp_disk_tables')), 'all' => Num::format($m->delta('Created_tmp_tables'))]),
            'why' => __('Geçici tabloların :share kadarı diske yazılıyor (eşik %25). Öneri: :size, iki ayar birlikte.', ['share' => Num::percent($share), 'size' => Bytes::format($target << 20)]),
        ];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $fine
     * @return array<string, mixed>|null
     */
    private function suggestMaxConnections(Measured $m, array &$fine): ?array
    {
        $current = $m->varInt('max_connections');
        $used = $m->int('Max_used_connections');
        if (! $m->maxConnectionsTight()) {
            $fine[] = [__('Bağlantı sınırı'), 'max_connections', Num::format($current),
                __('Sunucu başladığından beri en çok :used bağlantı kullanıldı, "Too many connections" hatası yok.', ['used' => Num::format($used)])];

            return null;
        }
        $target = min(2000, self::roundUp((int) ceil(max($used, $current) * 1.5), 50));

        return [
            'title' => __('Bağlantı sınırını artır'),
            'title_key' => 'Bağlantı sınırını artır',
            'setting' => 'max_connections',
            'target' => $target,
            'range' => [$current + 10, min(2000, $current * 3)],
            'values' => ['max_connections' => $target],
            'from_label' => Num::format($current),
            'to_label' => Num::format($target),
            'from_raw' => 'num:'.$current,
            'to_raw' => 'num:'.$target,
            'memory_mb' => (int) ceil(($target - $current) * $m->perConnectionMB()),
            'measure' => __('en çok :used bağlantı · :errors kez reddedildi', ['used' => Num::format($used), 'errors' => Num::format($m->delta('Connection_errors_max_connections'))]),
            'why' => __('Kullanılan bağlantı sayısı sınıra yaklaştı ya da sınır yüzünden bağlantılar reddedildi. Her bağlantı bellek bütçesinden pay alır.'),
        ];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $fine
     * @return array<string, mixed>|null
     */
    private function suggestTableOpenCache(Measured $m, array &$fine): ?array
    {
        $current = $m->varInt('table_open_cache');
        $perHour = $m->hours > 0 ? $m->delta('Opened_tables') / $m->hours : 0;
        if ($perHour <= 100 || $current >= 100000) {
            $fine[] = [__('Tablo önbelleği'), 'table_open_cache', Num::format($current),
                __('Saatte :count tablo açılıyor; yeterli.', ['count' => Num::format((int) round($perHour))])];

            return null;
        }
        $target = min(100000, max($current * 2, self::roundUp($m->int('tables') * 2, 1000)));

        return [
            'title' => __('Tablo önbelleğini büyüt'),
            'title_key' => 'Tablo önbelleğini büyüt',
            'setting' => 'table_open_cache',
            'target' => $target,
            'range' => [$current + 1000, min(100000, $target * 2)],
            'values' => ['table_open_cache' => $target],
            'from_label' => Num::format($current),
            'to_label' => Num::format($target),
            'from_raw' => 'num:'.$current,
            'to_raw' => 'num:'.$target,
            'memory_mb' => (int) ceil(($target - $current) / 1000 * 4),
            'measure' => __('saatte :count tablo açılıyor', ['count' => Num::format((int) round($perHour))]),
            'why' => __('Önbellek dolduğu için tablolar saatte :count kez yeniden açılıyor. Öneri: tablo sayısının iki katı.', ['count' => Num::format((int) round($perHour))]),
        ];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $fine
     * @return array<string, mixed>|null
     */
    private function suggestIoCapacity(Measured $m, array &$fine): ?array
    {
        $current = $m->varInt('innodb_io_capacity');
        $disk = (string) ($m->latest['disk'] ?? '');
        $target = ['nvme' => 2000, 'ssd' => 1000][$disk] ?? null;
        $name = ['nvme' => 'NVMe SSD', 'ssd' => 'SSD', 'hdd' => __('döner disk')][$disk] ?? null;
        if ($target === null || $current >= $target / 2) {
            if ($name !== null) {
                $fine[] = [__('Disk yazma kapasitesi'), 'innodb_io_capacity', Num::format($current), __('Disk: :disk; uygun.', ['disk' => $name])];
            }

            return null;
        }

        return [
            'title' => __('Disk hızına göre yazma kapasitesini ayarla'),
            'title_key' => 'Disk hızına göre yazma kapasitesini ayarla',
            'setting' => 'innodb_io_capacity',
            'target' => $target,
            'range' => [(int) ($target / 2), $target * 2],
            'values' => ['innodb_io_capacity' => $target],
            'from_label' => Num::format($current),
            'to_label' => Num::format($target),
            'from_raw' => 'num:'.$current,
            'to_raw' => 'num:'.$target,
            'memory_mb' => 0,
            'measure' => __('disk: :disk', ['disk' => $name]),
            'why' => __('Disk türü :disk; :current döner diskler içindir. :disk için önerilen :target.', ['disk' => $name, 'current' => Num::format($current), 'target' => Num::format($target)]),
        ];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $fine
     * @return array<string, mixed>|null
     */
    private function suggestSlowLog(Measured $m, array &$fine): ?array
    {
        if (strtoupper($m->var('slow_query_log', 'OFF')) === 'ON' || $m->var('slow_query_log') === '1') {
            $fine[] = [__('Yavaş sorgu kaydı'), 'slow_query_log', __('açık'), __(':seconds saniyeden uzun sorgular günlüğe yazılıyor.', ['seconds' => Num::format((float) $m->var('long_query_time', '10'))])];

            return null;
        }

        return [
            'title' => __('Yavaş sorgu kaydını aç'),
            'title_key' => 'Yavaş sorgu kaydını aç',
            'setting' => 'slow_query_log, long_query_time',
            'target' => 1,
            'range' => [1, 10],
            'values' => ['slow_query_log' => true, 'long_query_time' => 1],
            'from_label' => __('kapalı'),
            'to_label' => __('açık, :seconds sn', ['seconds' => 1]),
            'from_raw' => 'off',
            'to_raw' => 'on:1',
            'memory_mb' => 0,
            'measure' => __('şu an yalnızca sorgu özetleri görülebiliyor'),
            'why' => __('1 saniyeden uzun sorgular günlüğe yazılır; günlük her gün döndürülür, 7 gün saklanır.'),
        ];
    }

    /**
     * The suggestion with another value in its range.
     *
     * @param  array<string, mixed>  $s
     * @return array<string, mixed>
     */
    private function retarget(Measured $m, array $s, int $value): array
    {
        $old = $s['target'];
        $s['target'] = $value;
        switch ($s['key']) {
            case 'buffer_pool':
                $value = self::roundUp($value, 128);
                $s['target'] = $value;
                $s['values'] = ['innodb_buffer_pool_mb' => $value];
                $s['to_label'] = Bytes::format($value << 20);
                $s['to_raw'] = 'mb:'.$value;
                $s['memory_mb'] += $value - $old;
                break;
            case 'tmp_table':
                $s['values'] = ['tmp_table_mb' => $value];
                $s['to_label'] = Bytes::format($value << 20);
                $s['to_raw'] = 'mb:'.$value;
                $s['memory_mb'] += 2 * ($value - $old);
                break;
            case 'max_connections':
                $s['values'] = ['max_connections' => $value];
                $s['to_label'] = Num::format($value);
                $s['to_raw'] = 'num:'.$value;
                $s['memory_mb'] += (int) ceil(($value - $old) * $m->perConnectionMB());
                break;
            case 'table_open_cache':
                $s['values'] = ['table_open_cache' => $value];
                $s['to_label'] = Num::format($value);
                $s['to_raw'] = 'num:'.$value;
                break;
            case 'io_capacity':
                $s['values'] = ['innodb_io_capacity' => $value];
                $s['to_label'] = Num::format($value);
                $s['to_raw'] = 'num:'.$value;
                break;
            case 'slow_log':
                $s['values'] = ['slow_query_log' => true, 'long_query_time' => $value];
                $s['to_label'] = __('açık, :seconds sn', ['seconds' => $value]);
                $s['to_raw'] = 'on:'.$value;
                break;
        }
        $s['memory_mb'] = max(0, $s['memory_mb']);

        return $s;
    }

    /**
     * A value as people read it, from its stored form ("mb:4096",
     * "num:2000", "off", "on:1"), in the reader's language.
     */
    public static function label(?string $raw): string
    {
        return match (true) {
            $raw === null => '',
            $raw === 'off' => __('kapalı'),
            (bool) preg_match('/^(mb|num|on):(\d+)$/', $raw, $m) => match ($m[1]) {
                'mb' => Bytes::format((int) $m[2] << 20),
                'num' => Num::format((int) $m[2]),
                default => __('açık, :seconds sn', ['seconds' => (int) $m[2]]),
            },
            default => $raw,
        };
    }

    public static function roundUp(int $value, int $step): int
    {
        return (int) (ceil($value / $step) * $step);
    }
}
