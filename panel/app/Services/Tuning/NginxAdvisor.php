<?php

namespace App\Services\Tuning;

use App\Models\TuningSample;
use App\Support\Num;

/**
 * Suggestions for Nginx from its error log and limits: more connections
 * per worker when the log says "worker_connections are not enough" (or a
 * worker holds most of them), a higher open files limit when it says "Too
 * many open files", and a worker per CPU core. The values go into Nginx's
 * settings (as Hizmetler → Nginx → Ayarlar saves them); Nginx reloads,
 * open connections finish.
 */
class NginxAdvisor extends ServiceAdvisor
{
    public const FIELDS = ['nginx_connections' => 'worker_connections', 'nginx_nofile' => 'worker_rlimit_nofile', 'nginx_workers' => 'worker_processes'];

    public const CONNECTION_STEPS = [1024, 2048, 4096, 8192, 16384, 32768, 65535];

    public const FILE_STEPS = [8192, 16384, 32768, 65536, 131072, 262144];

    protected string $part = 'system';

    public function analyze(TuningSample $latest, ?TuningSample $since, MemoryBudget $budget, array $ai = []): array
    {
        $d = $latest->data;
        $wc = (int) ($d['worker_connections'] ?? 768);
        $workers = max(1, (int) ($d['workers'] ?? 1));
        $cpus = (int) ($d['cpus'] ?? 0);
        $files = (int) ($d['worker_rlimit_nofile'] ?? 0) ?: (int) ($d['file_limit'] ?? 0);
        $notEnough = (int) ($d['not_enough'] ?? 0);
        $tooMany = (int) ($d['too_many_files'] ?? 0);
        $sockets = (int) ($d['sockets'] ?? 0);
        $out = ['measured' => true, 'hours' => 7 * 24, 'suggestions' => [], 'fine' => [], 'notice' => null, 'cells' => [
            [__('İşçi süreç'), Num::format($workers), __(':cpus çekirdek · :value', ['cpus' => $cpus ?: '—', 'value' => (string) ($d['worker_processes'] ?? 'auto')]), ''],
            [__('İşçi başına bağlantı'), Num::format($wc), __('Toplam :total bağlantı', ['total' => Num::format($wc * $workers)]), ''],
            [__('Bağlantı yetmedi'), Num::format($notEnough), __('Son 7 günde, son 24 saatte :n', ['n' => Num::format((int) ($d['not_enough_day'] ?? 0))]), $notEnough > 0 ? 'warn' : ''],
            [__('Açık dosya sınırı'), $files > 0 ? Num::format($files) : '—', $tooMany > 0 ? __(':n kez "çok fazla açık dosya"', ['n' => Num::format($tooMany)]) : __('İşçi başına'), $tooMany > 0 ? 'warn' : ''],
        ]];
        $found = [];

        if ($notEnough > 0 || ($wc > 0 && $sockets >= 0.8 * $wc)) {
            $target = PhpAdvisor::step(self::CONNECTION_STEPS, max($wc + 1, $wc * 2));
            $values = ['worker_connections' => $target];
            $setting = 'worker_connections';
            if ($files > 0 && $files < 2 * $target) {
                $values['worker_rlimit_nofile'] = PhpAdvisor::step(self::FILE_STEPS, 2 * $target);
                $setting .= ', worker_rlimit_nofile';
            }
            $found[] = [
                'key' => 'nginx_connections',
                'title' => __('Nginx bağlantı sınırını yükselt'),
                'title_key' => 'Nginx bağlantı sınırını yükselt',
                'setting' => $setting,
                'target' => $target,
                'values' => $values,
                'from_label' => Num::format($wc), 'to_label' => Num::format($target), 'from_raw' => 'num:'.$wc, 'to_raw' => 'num:'.$target,
                'memory_mb' => 0,
                'measure' => $notEnough > 0
                    ? __('7 günde :n kez "worker_connections are not enough"', ['n' => Num::format($notEnough)])
                    : __('bir işçide :n açık bağlantı', ['n' => Num::format($sockets)]),
                'why' => ($notEnough > 0
                    ? __('Nginx\'in bağlantı sınırı son 7 günde :n kez doldu; o anlarda yeni ziyaretçiler bağlanamadı. Sınır iki katına çıkar; bağlantı başına birkaç KB bellek yeter.', ['n' => Num::format($notEnough)])
                    : __('Bir Nginx işçisi sınırının çoğunu kullanıyor; yoğun bir anda yeni ziyaretçiler bağlanamaz. Sınır iki katına çıkar.'))
                    .(isset($values['worker_rlimit_nofile']) ? ' '.__('İşçilerin açık dosya sınırı da :files olur.', ['files' => Num::format($values['worker_rlimit_nofile'])]) : ''),
            ];
        } else {
            $out['fine'][] = [__('İşçi başına bağlantı'), 'worker_connections', Num::format($wc), __('Son 7 günde bağlantı sınırı dolmadı.')];
        }

        if ($tooMany > 0 && ! isset(($found[0]['values'] ?? [])['worker_rlimit_nofile'])) {
            $target = PhpAdvisor::step(self::FILE_STEPS, max(2 * $wc, 2 * $files, 8192));
            $found[] = [
                'key' => 'nginx_nofile',
                'title' => __('Nginx\'in açık dosya sınırını yükselt'),
                'title_key' => 'Nginx\'in açık dosya sınırını yükselt',
                'setting' => 'worker_rlimit_nofile',
                'target' => $target,
                'values' => ['worker_rlimit_nofile' => $target],
                'from_label' => $files > 0 ? Num::format($files) : '—', 'to_label' => Num::format($target), 'from_raw' => 'num:'.$files, 'to_raw' => 'num:'.$target,
                'memory_mb' => 0,
                'measure' => __('7 günde :n kez "Too many open files"', ['n' => Num::format($tooMany)]),
                'why' => __('Nginx işçileri açık dosya sınırına dayandı (her bağlantı ve günlük dosyası bir dosyadır); o anlarda istekler reddedildi.'),
            ];
        }

        $configured = (string) ($d['worker_processes'] ?? 'auto');
        if (ctype_digit($configured) && $cpus > (int) $configured) {
            $found[] = [
                'key' => 'nginx_workers',
                'title' => __('Her çekirdeğe bir Nginx işçisi ver'),
                'title_key' => 'Her çekirdeğe bir Nginx işçisi ver',
                'setting' => 'worker_processes',
                'target' => 0,
                'values' => ['worker_processes' => 'auto'],
                'from_label' => $configured, 'to_label' => 'auto', 'from_raw' => 'text:'.$configured, 'to_raw' => 'text:auto',
                'memory_mb' => 0,
                'measure' => __(':workers işçi, :cpus çekirdek', ['workers' => $configured, 'cpus' => $cpus]),
                'why' => __('Sunucunun :cpus çekirdeği var ama Nginx :workers işçiyle çalışıyor; "auto" her çekirdeğe bir işçi verir.', ['cpus' => $cpus, 'workers' => $configured]),
            ];
        } else {
            $out['fine'][] = [__('İşçi süreç sayısı'), 'worker_processes', $configured, __('Her çekirdeğe bir işçi düşüyor.')];
        }

        return $this->finish($out, $found, $budget, $ai);
    }
}
