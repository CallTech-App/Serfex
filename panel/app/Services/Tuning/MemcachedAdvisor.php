<?php

namespace App\Services\Tuning;

use App\Models\TuningSample;
use App\Support\Bytes;
use App\Support\Num;

/**
 * Suggestions for Memcached from two measurements: more memory when it
 * is full and throws out entries to make room, more connections when it
 * stopped accepting them. Memcached restarts and starts with an empty
 * cache, so these are best left for the maintenance window.
 */
class MemcachedAdvisor extends ServiceAdvisor
{
    public const FIELDS = ['memcached_memory' => 'memory_mb', 'memcached_connections' => 'max_connections'];

    public const MEMORY_STEPS = [128, 256, 512, 1024, 2048, 4096, 8192];

    public function analyze(TuningSample $latest, ?TuningSample $since, MemoryBudget $budget, array $ai = []): array
    {
        $d = $latest->data;
        $then = $since?->data;
        $mb = (int) ($d['memcached_mb'] ?? 64);
        $limit = (int) ($d['stats']['limit_maxbytes'] ?? ($mb << 20));
        $bytes = (int) ($d['stats']['bytes'] ?? 0);
        $full = $limit > 0 ? 100 * $bytes / $limit : 0.0;
        $evicted = self::delta($d, $then, 'stats.evictions');
        $refused = self::delta($d, $then, 'stats.listen_disabled_num');
        $hits = self::delta($d, $then, 'stats.get_hits');
        $misses = self::delta($d, $then, 'stats.get_misses');
        $ratio = $hits + $misses >= 1000 ? 100 * $hits / ($hits + $misses) : null;
        $conns = (int) ($d['max_connections'] ?? 1024);

        $out = ['measured' => true, 'hours' => self::hours($latest, $since), 'suggestions' => [], 'fine' => [], 'notice' => null, 'cells' => [
            [__('Doluluk'), Num::percent($full), Bytes::format($bytes).' / '.Bytes::format($limit), $full >= 90 ? 'warn' : ''],
            [__('Yer açmak için silinen'), Num::format($evicted), __('Dolu olduğu için atılan kayıt'), $evicted > 0 ? 'warn' : ''],
            [__('İsabet'), $ratio === null ? '—' : Num::percent($ratio, 1), __('Bulunan / istenen'), ''],
            [__('Açık bağlantı'), Num::format((int) ($d['stats']['curr_connections'] ?? 0)).' / '.Num::format($conns), $refused > 0 ? __(':n kez yeni bağlantı reddedildi', ['n' => Num::format($refused)]) : __('Şu an açık / en fazla'), $refused > 0 ? 'warn' : ''],
        ]];
        $found = [];

        if ($evicted > 0 && $full >= 90) {
            $target = PhpAdvisor::step(self::MEMORY_STEPS, $mb * 2);
            $found[] = [
                'key' => 'memcached_memory',
                'title' => __('Memcached belleğini büyüt'),
                'title_key' => 'Memcached belleğini büyüt',
                'setting' => '-m',
                'target' => $target,
                'range' => [$mb + 32, max($target, $mb * 4)],
                'value_path' => 'memory_mb', 'memory_per' => 1,
                'values' => ['memory_mb' => $target],
                'from_label' => Bytes::format($mb << 20), 'to_label' => Bytes::format($target << 20), 'from_raw' => 'mb:'.$mb, 'to_raw' => 'mb:'.$target,
                'memory_mb' => $target - $mb,
                'disruption' => 'restart',
                'measure' => __(':share dolu · :n kayıt atıldı', ['share' => Num::percent($full), 'n' => Num::format($evicted)]),
                'why' => __('Memcached dolu ve yeni kayıtlara yer açmak için :n kaydı sildi; siteler o verileri yeniden hesaplıyor. Memcached yeniden başlar ve önbellek boş başlar.', ['n' => Num::format($evicted)]),
            ];
        } else {
            $out['fine'][] = [__('Memcached belleği'), '-m', Bytes::format($mb << 20), __(':share dolu; kayıt atılmadı.', ['share' => Num::percent($full)])];
        }

        if ($refused > 0 && $conns < 65536) {
            $target = min(65536, $conns * 2);
            $found[] = [
                'key' => 'memcached_connections',
                'title' => __('Memcached bağlantı sınırını yükselt'),
                'title_key' => 'Memcached bağlantı sınırını yükselt',
                'setting' => '-c',
                'target' => $target,
                'values' => ['max_connections' => $target],
                'from_label' => Num::format($conns), 'to_label' => Num::format($target), 'from_raw' => 'num:'.$conns, 'to_raw' => 'num:'.$target,
                'memory_mb' => 0,
                'disruption' => 'restart',
                'measure' => __(':n kez bağlantı reddedildi', ['n' => Num::format($refused)]),
                'why' => __('Memcached bağlantı sınırına dayandı ve yeni bağlantıları bir süre almadı; o anda siteler önbelleğe ulaşamadı.'),
            ];
        }

        return $this->finish($out, $found, $budget, $ai);
    }
}
