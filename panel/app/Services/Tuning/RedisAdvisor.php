<?php

namespace App\Services\Tuning;

use App\Models\LaravelApp;
use App\Models\TuningSample;
use App\Support\Bytes;
use App\Support\Num;

/**
 * Suggestions for the sites' own Redis instances (one per Laravel
 * application, maxmemory-policy noeviction): when one comes close to its
 * memory limit, or refused writes because it was full, its limit grows.
 * The running instance takes the new limit at once; nothing restarts.
 */
class RedisAdvisor extends ServiceAdvisor
{
    public const STEPS = [64, 96, 128, 192, 256, 384, 512, 768, 1024, 1536, 2048, 3072, 4096, 6144, 8192];

    /** Use above this share of the limit is worth growing. */
    public const FULL = 80.0;

    public function analyze(TuningSample $latest, ?TuningSample $since, MemoryBudget $budget, array $ai = []): array
    {
        $instances = (array) ($latest->data['instances'] ?? []);
        $before = collect((array) ($since?->data['instances'] ?? []))->keyBy('domain');
        $apps = LaravelApp::with('site')->whereNotNull('redis_port')->get()->keyBy(fn ($a) => $a->site?->domain);
        $read = array_filter($instances, fn ($i) => $i['read'] ?? false);
        $fullest = null;
        $refused = 0;
        foreach ($read as $i) {
            $share = ($i['max_memory_mb'] ?? 0) > 0 ? 100 * (float) $i['used_mb'] / (int) $i['max_memory_mb'] : 0;
            $fullest = max($fullest ?? 0, $share);
            $refused += self::delta($i, $before[$i['domain']] ?? null, 'oom_errors');
        }
        $out = ['measured' => true, 'hours' => self::hours($latest, $since), 'suggestions' => [], 'fine' => [], 'notice' => null, 'cells' => [
            [__('Site Redis\'i'), Num::format(count($read)).' / '.Num::format(count($instances)), __('Okunabilen / toplam'), count($read) < count($instances) ? 'warn' : ''],
            [__('En dolu'), $fullest === null ? '—' : Num::percent($fullest), __('Bellek sınırına göre'), $fullest !== null && $fullest >= self::FULL ? 'warn' : ''],
            [__('Reddedilen yazma'), Num::format($refused), __('Dolu olduğu için'), $refused > 0 ? 'warn' : ''],
            [__('Toplam sınır'), Bytes::format(array_sum(array_map(fn ($i) => (int) ($i['max_memory_mb'] ?? 0), $instances)) << 20), __('Tüm sitelerin Redis\'leri'), ''],
        ]];
        $unread = array_values(array_filter($instances, fn ($i) => ! ($i['read'] ?? false)));
        if ($unread !== []) {
            $out['notice'] = __(':domain Redis\'i okunamadı: :error', ['domain' => $unread[0]['domain'], 'error' => $unread[0]['error'] ?? '—']);
        }
        $found = [];
        $calm = 0;
        foreach ($read as $i) {
            $app = $apps[$i['domain']] ?? null;
            $cur = (int) ($i['max_memory_mb'] ?? 0);
            if ($app === null || $cur <= 0) {
                continue;
            }
            $used = (float) $i['used_mb'];
            $peak = max($used, (float) ($i['peak_mb'] ?? 0));
            $oom = self::delta($i, $before[$i['domain']] ?? null, 'oom_errors');
            if ($oom === 0 && $used < self::FULL / 100 * $cur && $peak < 0.9 * $cur) {
                $calm++;

                continue;
            }
            if ($cur >= 8192) {
                continue;
            }
            $target = PhpAdvisor::step(self::STEPS, max($cur + 1, (int) ceil($peak * 1.5)));
            $found[] = [
                'key' => 'redis_memory:'.$app->id,
                'site' => $i['domain'],
                'title' => __(':domain Redis belleğini büyüt', ['domain' => $i['domain']]),
                'title_key' => 'Redis belleğini büyüt',
                'setting' => 'maxmemory · '.$i['domain'],
                'target' => $target,
                'range' => [$cur + 16, min(8192, max($target, $cur * 4))],
                'value_path' => 'max_memory_mb', 'memory_per' => 1,
                'values' => ['app' => $app->id, 'max_memory_mb' => $target],
                'from_label' => Bytes::format($cur << 20), 'to_label' => Bytes::format($target << 20), 'from_raw' => 'mb:'.$cur, 'to_raw' => 'mb:'.$target,
                'memory_mb' => $target - $cur,
                'disruption' => 'none',
                'measure' => $oom > 0
                    ? __(':n yazma reddedildi', ['n' => Num::format($oom)])
                    : __(':used kullanılıyor · en çok :peak', ['used' => Bytes::format((int) ($used * 1048576)), 'peak' => Bytes::format((int) ($peak * 1048576))]),
                'why' => $oom > 0
                    ? __(':domain Redis\'i doldu ve :n yazmayı reddetti: o anlarda önbellek, oturum ya da kuyruk işi yazılamadı. Öneri: en yüksek kullanımın 1,5 katı.', ['domain' => $i['domain'], 'n' => Num::format($oom)])
                    : __(':domain Redis\'i sınırına yaklaştı (:peak / :limit). Dolunca yazmaları reddeder: önbellek, oturum ve kuyruk işleri yazılamaz. Öneri: en yüksek kullanımın 1,5 katı.', [
                        'domain' => $i['domain'], 'peak' => Bytes::format((int) ($peak * 1048576)), 'limit' => Bytes::format($cur << 20)]),
            ];
        }
        if ($calm > 0) {
            $out['fine'][] = [__('Site Redis\'leri'), 'maxmemory', __(':count site', ['count' => $calm]), __('Bu sitelerin Redis\'lerinde yer var.')];
        }

        return $this->finish($out, $found, $budget, $ai);
    }
}
