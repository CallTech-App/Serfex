<?php

namespace App\Services\Tuning;

use App\Models\TuningSample;
use App\Support\Bytes;
use App\Support\Num;

/**
 * Suggestions for the customers' PostgreSQL from two measurements: a
 * bigger shared_buffers when the data does not fit and reads go to disk
 * (needs a restart, a few seconds without connections), more work_mem
 * when sorts spill into temporary files, effective_cache_size from the
 * machine's memory, and SSD costs for the planner. They go into Serfex's
 * tuning file of the cluster; PostgreSQL reloads.
 */
class PostgresAdvisor extends ServiceAdvisor
{
    public const FIELDS = [
        'pg_shared_buffers' => 'shared_buffers_mb', 'pg_work_mem' => 'work_mem_mb', 'pg_effective_cache' => 'effective_cache_size_mb', 'pg_io' => 'random_page_cost',
    ];

    public const BUFFER_STEPS = [256, 384, 512, 768, 1024, 1536, 2048, 3072, 4096, 6144, 8192, 12288, 16384, 24576, 32768];

    /** Below this share of blocks read from memory the cache is too small. */
    public const HIT_TARGET = 99.0;

    /** work_mem is not raised above this (MB): every sort of every connection may take it. */
    public const MAX_WORK_MEM = 64;

    protected string $part = 'postgresql';

    public function analyze(TuningSample $latest, ?TuningSample $since, MemoryBudget $budget, array $ai = []): array
    {
        $d = $latest->data;
        $then = $since?->data;
        $set = (array) ($d['settings'] ?? []);
        $ram = (int) ($d['memory_mb'] ?? 0);
        $buffers = (int) ($set['shared_buffers_mb'] ?? 128);
        $dataMB = (int) ((int) ($d['data_bytes'] ?? 0) / 1048576);
        $hit = self::delta($d, $then, 'blocks_hit');
        $read = self::delta($d, $then, 'blocks_read');
        $ratio = $hit + $read >= 10000 ? 100 * $hit / ($hit + $read) : null;
        $tempFiles = self::delta($d, $then, 'temp_files');
        $tempBytes = self::delta($d, $then, 'temp_bytes');
        $backends = (int) ($d['backends'] ?? 0);
        $maxConn = (int) ($set['max_connections'] ?? 100);

        $out = ['measured' => true, 'hours' => self::hours($latest, $since), 'suggestions' => [], 'fine' => [], 'notice' => null, 'cells' => [
            [__('Önbellek isabeti'), $ratio === null ? '—' : Num::percent($ratio, 1), __('Hedef %:target üstü', ['target' => 99]), $ratio !== null && $ratio < self::HIT_TARGET ? 'warn' : ''],
            [__('Veri / önbellek'), Bytes::format($dataMB << 20), __('shared_buffers :size', ['size' => Bytes::format($buffers << 20)]), $dataMB > $buffers ? 'warn' : ''],
            [__('Geçici dosya'), Num::format($tempFiles), $tempFiles > 0 ? Bytes::format($tempBytes) : __('Sıralamalar bellekte'), $tempFiles > 0 ? 'warn' : ''],
            [__('Açık bağlantı'), Num::format($backends).' / '.Num::format($maxConn), __('Şu an açık / en fazla'), ''],
        ]];
        $found = [];

        // shared_buffers: up to a quarter of RAM, enough for the data.
        $want = min(intdiv($ram, 4), max(256, (int) ceil($dataMB * 1.25)));
        $target = self::stepDown(self::BUFFER_STEPS, $want);
        if ($target !== null && $target >= $buffers * 1.5 && ($dataMB > $buffers || ($ratio !== null && $ratio < self::HIT_TARGET))) {
            $found[] = [
                'key' => 'pg_shared_buffers',
                'title' => __('PostgreSQL önbelleğini büyüt'),
                'title_key' => 'PostgreSQL önbelleğini büyüt',
                'setting' => 'shared_buffers',
                'target' => $target,
                'range' => [$buffers + 128, max($target, intdiv($ram, 4))],
                'value_path' => 'shared_buffers_mb', 'memory_per' => 1,
                'values' => ['shared_buffers_mb' => $target],
                'from_label' => Bytes::format($buffers << 20), 'to_label' => Bytes::format($target << 20),
                'from_raw' => 'mb:'.$buffers, 'to_raw' => 'mb:'.$target,
                'memory_mb' => $target - $buffers,
                'disruption' => 'restart',
                'measure' => $ratio === null ? __(':data veri', ['data' => Bytes::format($dataMB << 20)]) : __('isabet :ratio · :data veri', ['ratio' => Num::percent($ratio, 1), 'data' => Bytes::format($dataMB << 20)]),
                'why' => __('Veritabanları :data, PostgreSQL\'in önbelleği :size; sık okunan veri bellekte kalamıyor, okumalar diske gidiyor. Öneri: verinin 1,25 katı, en fazla belleğin dörtte biri. PostgreSQL yeniden başlar: birkaç saniye bağlantı kesilir.', [
                    'data' => Bytes::format($dataMB << 20), 'size' => Bytes::format($buffers << 20)]),
            ];
        } else {
            $out['fine'][] = [__('PostgreSQL önbelleği'), 'shared_buffers', Bytes::format($buffers << 20), $ratio === null
                ? __('Karar için yeterli okuma yok.') : __('Okumaların :ratio kadarı bellekten.', ['ratio' => Num::percent($ratio, 1)])];
        }

        $work = (int) ($set['work_mem_mb'] ?? 4);
        if ($tempFiles > 0 && $work < self::MAX_WORK_MEM) {
            $to = min(self::MAX_WORK_MEM, max(8, $work * 2));
            $per = max(4, $backends);
            $found[] = [
                'key' => 'pg_work_mem',
                'title' => __('Sıralama belleğini büyüt'),
                'title_key' => 'Sıralama belleğini büyüt',
                'setting' => 'work_mem',
                'target' => $to,
                'range' => [$work + 1, self::MAX_WORK_MEM],
                'value_path' => 'work_mem_mb', 'memory_per' => $per,
                'values' => ['work_mem_mb' => $to],
                'from_label' => Bytes::format($work << 20), 'to_label' => Bytes::format($to << 20),
                'from_raw' => 'mb:'.$work, 'to_raw' => 'mb:'.$to,
                'memory_mb' => ($to - $work) * $per,
                'measure' => __(':n geçici dosya · :size', ['n' => Num::format($tempFiles), 'size' => Bytes::format($tempBytes)]),
                'why' => __('Sorgular sıralama ve birleştirme için :n kez diske geçici dosya yazdı (:size). work_mem büyüyünce bunlar bellekte kalır; bellek etkisi şu anki :count bağlantıyla hesaplandı.', [
                    'n' => Num::format($tempFiles), 'size' => Bytes::format($tempBytes), 'count' => Num::format($per)]),
            ];
        } else {
            $out['fine'][] = [__('Sıralama belleği'), 'work_mem', Bytes::format($work << 20), $tempFiles > 0 ? __('Üst sınırda.') : __('Sorgular geçici dosya yazmadı.')];
        }

        $cache = (int) ($set['effective_cache_size_mb'] ?? 4096);
        $half = (int) (round($ram / 2 / 256) * 256);
        if ($half >= 512 && abs($cache - $half) > 0.25 * $half) {
            $found[] = [
                'key' => 'pg_effective_cache',
                'title' => __('Sorgu planlayıcısına belleği bildir'),
                'title_key' => 'Sorgu planlayıcısına belleği bildir',
                'setting' => 'effective_cache_size',
                'target' => $half,
                'values' => ['effective_cache_size_mb' => $half],
                'from_label' => Bytes::format($cache << 20), 'to_label' => Bytes::format($half << 20),
                'from_raw' => 'mb:'.$cache, 'to_raw' => 'mb:'.$half,
                'memory_mb' => 0,
                'measure' => __('sunucu :ram RAM', ['ram' => Bytes::format($ram << 20)]),
                'why' => __('effective_cache_size bellek ayırmaz; planlayıcıya dosya önbelleğinin ne kadar büyük olduğunu söyler. Sunucu belleğinin yarısı, index kullanan planların seçilmesini sağlar.'),
            ];
        }

        $cost = (string) ($set['random_page_cost'] ?? '4');
        if (in_array($d['disk'] ?? '', ['ssd', 'nvme'], true) && (float) $cost >= 2) {
            $io = (string) ($set['effective_io_concurrency'] ?? '1');
            $found[] = [
                'key' => 'pg_io',
                'title' => __('Planlayıcıyı SSD\'ye göre ayarla'),
                'title_key' => 'Planlayıcıyı SSD\'ye göre ayarla',
                'setting' => 'random_page_cost, effective_io_concurrency',
                'target' => 0,
                'values' => ['random_page_cost' => '1.1', 'effective_io_concurrency' => 200],
                'from_label' => $cost.' · '.$io, 'to_label' => '1.1 · 200', 'from_raw' => 'text:'.$cost.' · '.$io, 'to_raw' => 'text:1.1 · 200',
                'memory_mb' => 0,
                'measure' => __('veri :disk üzerinde', ['disk' => $d['disk'] === 'nvme' ? 'NVMe' : 'SSD']),
                'why' => __('PostgreSQL varsayılan olarak döner disk varsayar ve rastgele okumayı 4 kat pahalı sayar; bu yüzden index yerine tablonun tamamını okumayı seçebilir. SSD\'de 1.1 doğru değerdir.'),
            ];
        }

        return $this->finish($out, $found, $budget, $ai);
    }
}
