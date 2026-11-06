<?php

namespace App\Services\Sites;

use App\Enums\SiteStatus;
use App\Models\Site;
use App\Models\SiteOutage;
use App\Services\Settings\Notifications;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Site → Erişilebilirlik: serfex:uptime opens every site once a minute at
 * the address visitors use (through Cloudflare when it is in front), and
 * tells the administrators when one stops answering.
 *
 * Down is a connection that fails, a timeout, or HTTP 5xx (Cloudflare's 52x
 * included); 4xx is an answer. A failed check is tried again from the
 * server itself, to tell a problem on the server from one in DNS, Cloudflare
 * or the network. Two failed checks in a row open an outage; sites that
 * never answered (DNS not pointing here yet), suspended sites and Laravel
 * applications in maintenance are not checked.
 */
class Uptime
{
    public const TIMEOUT = 10;

    /** Failed checks in a row that make the site down. */
    public const FAILURES = 2;

    public const BUCKET_MINUTES = 15;

    public const KEEP_DAYS = 90;

    private const CLOUDFLARE = [
        520 => 'Cloudflare 520: sunucu beklenmeyen bir yanıt verdi',
        521 => 'Cloudflare 521: sunucuya bağlanamıyor',
        522 => 'Cloudflare 522: sunucuya bağlanırken zaman aşımı',
        523 => 'Cloudflare 523: sunucuya ulaşılamıyor',
        524 => 'Cloudflare 524: sunucu zamanında yanıt vermedi',
        525 => 'Cloudflare 525: sunucuyla TLS bağlantısı kurulamadı',
        526 => 'Cloudflare 526: sunucunun sertifikası geçersiz',
    ];

    /**
     * The address checked: the preferred name over HTTPS when the site
     * has a certificate, and the site's path (the Laravel health path, or
     * "/").
     */
    public function url(Site $site): string
    {
        return ($site->hasHttps() ? 'https' : 'http').'://'.$site->primaryHost().$this->path($site);
    }

    public function path(Site $site): string
    {
        $path = $site->uptime_path ?: ($site->laravelApp?->health_path ?: '/');

        return str_starts_with($path, '/') ? $path : '/'.$path;
    }

    /**
     * Why a site is not checked now, or null.
     */
    public function skipped(Site $site): ?string
    {
        return match (true) {
            ! $site->uptime_enabled => __('İzleme kapalı'),
            $site->status !== SiteStatus::Active => __('Site hazır değil'),
            $site->isSuspended() => __('Müşteri askıda'),
            (bool) $site->laravelApp?->maintenance_on => __('Uygulama bakım modunda'),
            default => null,
        };
    }

    /**
     * One run of serfex:uptime. Returns how many sites were checked and
     * how many went down or came back.
     *
     * @return array{checked: int, down: int, up: int}
     */
    public function check(): array
    {
        $sites = Site::with(['laravelApp', 'certificate', 'systemUser.customer'])->where('status', SiteStatus::Active)->where('uptime_enabled', true)->get()
            ->filter(fn (Site $s) => $this->skipped($s) === null)->values();
        $out = ['checked' => 0, 'down' => 0, 'up' => 0];
        foreach ($sites->chunk(25) as $chunk) {
            foreach ($this->probe($chunk) as $id => $result) {
                $changed = $this->record($chunk->firstWhere('id', $id), $result);
                $out['checked']++;
                if ($changed !== null) {
                    $out[$changed]++;
                }
            }
        }
        if ($out['down'] > 0 || $out['up'] > 0) {
            app(Notifications::class)->check();
        }

        return $out;
    }

    /**
     * Opens each site's address at once: id => {ok, ms, error}.
     *
     * @param  Collection<int, Site>  $sites
     * @return array<int, array{ok: bool, ms: ?int, error: ?string, status: ?int}>
     */
    private function probe(Collection $sites): array
    {
        $responses = Http::pool(fn (Pool $pool) => $sites->map(fn (Site $s) => $this->request($pool->as((string) $s->id))->get($this->url($s)))->all());
        $out = [];
        foreach ($sites as $site) {
            $out[$site->id] = $this->result($responses[(string) $site->id] ?? null);
        }

        return $out;
    }

    /**
     * @param  Pool|PendingRequest  $request
     */
    private function request($request)
    {
        return $request->timeout(self::TIMEOUT)->connectTimeout(5)->withoutVerifying()
            ->withUserAgent('Serfex-Uptime/'.config('serfex.version'))->withOptions(['allow_redirects' => ['max' => 5]]);
    }

    /**
     * @return array{ok: bool, ms: ?int, error: ?string, status: ?int}
     */
    private function result(mixed $response): array
    {
        if ($response instanceof Response) {
            $status = $response->status();
            $seconds = $response->transferStats?->getTransferTime();
            $ms = $seconds !== null ? (int) round($seconds * 1000) : null;
            if ($status < 500) {
                return ['ok' => true, 'ms' => $ms, 'error' => null, 'status' => $status];
            }

            return ['ok' => false, 'ms' => $ms, 'error' => isset(self::CLOUDFLARE[$status]) ? __(self::CLOUDFLARE[$status]) : 'HTTP '.$status, 'status' => $status];
        }

        return ['ok' => false, 'ms' => null, 'error' => $this->connectionError($response), 'status' => null];
    }

    private function connectionError(mixed $e): string
    {
        $message = $e instanceof Throwable ? $e->getMessage() : '';

        return match (true) {
            str_contains($message, 'error 28') || stripos($message, 'timed out') !== false => __('Zaman aşımı (:seconds sn içinde yanıt yok)', ['seconds' => self::TIMEOUT]),
            str_contains($message, 'error 6:') || stripos($message, 'resolve host') !== false => __('Alan adı çözümlenemedi (DNS)'),
            str_contains($message, 'error 7:') || stripos($message, 'refused') !== false => __('Bağlantı reddedildi'),
            str_contains($message, 'error 35') || str_contains($message, 'SSL') => __('TLS bağlantısı kurulamadı'),
            $message !== '' => Str::limit($message, 200),
            default => __('Bağlantı kurulamadı'),
        };
    }

    /**
     * The same address opened from the server itself (the name resolved
     * to 127.0.0.1), to find where the problem is.
     *
     * @param  array{ok: bool, ms: ?int, error: ?string, status: ?int}  $outside
     */
    public function cause(Site $site, array $outside): string
    {
        $url = $this->url($site);
        $host = $site->primaryHost();
        try {
            $response = $this->request(Http::withOptions(['curl' => [CURLOPT_RESOLVE => [$host.':443:127.0.0.1', $host.':80:127.0.0.1']]]))->get($url);
        } catch (Throwable $e) {
            $response = $e;
        }
        $local = $this->result($response);
        if ($local['ok']) {
            return in_array($outside['status'], [521, 522, 523], true)
                ? __('Sunucudan denendiğinde site çalışıyor: Cloudflare sunucuya bağlanamıyor, Cloudflare adresleri güvenlik duvarında engellenmiş olabilir.')
                : __('Sunucudan denendiğinde site çalışıyor: sorun DNS, Cloudflare ya da ağ tarafında.');
        }
        $hint = match ($local['status']) {
            502, 504 => ' '.__('PHP-FPM yanıt vermiyor olabilir.'),
            500 => ' '.__('Uygulama hata veriyor; site günlüklerine bakın.'),
            default => $local['status'] === null ? ' '.__('Web sunucusu yanıt vermiyor olabilir.') : '',
        };

        return __('Sunucudan denendiğinde de hata: :error.', ['error' => $local['error']]).$hint;
    }

    /**
     * Saves a check: the site's state, its 15-minute bucket and its
     * outage. Returns "down" or "up" when the state changed.
     *
     * @param  array{ok: bool, ms: ?int, error: ?string, status: ?int}  $result
     */
    public function record(Site $site, array $result): ?string
    {
        $now = now();
        $this->bucket($site, $now, $result);
        $changed = null;
        if ($result['ok']) {
            if ($site->uptime_state === 'down') {
                $site->outages()->whereNull('ended_at')->update(['ended_at' => $now]);
                $changed = 'up';
            }
            $site->forceFill([
                'uptime_state' => 'up', 'uptime_failures' => 0, 'uptime_ms' => $result['ms'], 'uptime_error' => null, 'uptime_cause' => null,
                'uptime_since' => $site->uptime_state === 'up' ? $site->uptime_since : $now,
            ]);
        } else {
            $failures = $site->uptime_failures + 1;
            $cause = $site->uptime_state === null ? null : $this->cause($site, $result);
            if ($site->uptime_state === 'up' && $failures >= self::FAILURES) {
                // The outage began at the first failed check.
                $started = $failures === 2 && $site->uptime_checked_at ? $site->uptime_checked_at : $now->copy()->subMinutes($failures - 1);
                $site->outages()->create(['started_at' => $started, 'error' => Str::limit((string) $result['error'], 300, ''), 'cause' => $cause ? Str::limit($cause, 300, '') : null]);
                $site->uptime_state = 'down';
                $site->uptime_since = $started;
                $changed = 'down';
            }
            $site->forceFill(['uptime_failures' => min($failures, 60000), 'uptime_error' => Str::limit((string) $result['error'], 300, ''), 'uptime_cause' => $cause ? Str::limit($cause, 300, '') : null]);
        }
        $site->uptime_checked_at = $now;
        $site->saveQuietly();

        return $changed;
    }

    /**
     * @param  array{ok: bool, ms: ?int, error: ?string, status: ?int}  $result
     */
    private function bucket(Site $site, CarbonInterface $at, array $result): void
    {
        DB::statement(
            'insert into site_uptime (site_id, bucket_at, checks, failures, ms_total) values (?, ?, 1, ?, ?)
             on conflict (site_id, bucket_at) do update set checks = site_uptime.checks + 1, failures = site_uptime.failures + excluded.failures, ms_total = site_uptime.ms_total + excluded.ms_total',
            [$site->id, self::bucketStart($at), $result['ok'] ? 0 : 1, $result['ok'] ? (int) $result['ms'] : 0]
        );
    }

    public static function bucketStart(CarbonInterface $at): CarbonInterface
    {
        $at = $at->copy()->startOfMinute();

        return $at->setTime($at->hour, intdiv($at->minute, self::BUCKET_MINUTES) * self::BUCKET_MINUTES);
    }

    /**
     * What Site → Genel bakış shows: the last 24 hours in 96 buckets, the
     * share of good checks over 24 hours and 30 days, the response time
     * and the latest outages.
     *
     * @return array<string, mixed>
     */
    public function summary(Site $site): array
    {
        $now = now();
        $from = self::bucketStart($now->copy()->subHours(24))->addMinutes(self::BUCKET_MINUTES);
        $rows = DB::table('site_uptime')->where('site_id', $site->id)->where('bucket_at', '>=', $now->copy()->subDays(30))->get(['bucket_at', 'checks', 'failures', 'ms_total']);
        $byBucket = $rows->keyBy(fn ($r) => (string) strtotime((string) $r->bucket_at));
        $bars = [];
        for ($at = $from->copy(); $at <= $now; $at->addMinutes(self::BUCKET_MINUTES)) {
            $r = $byBucket->get((string) $at->getTimestamp());
            $bars[] = [
                'at' => $at->copy(),
                'tone' => match (true) {
                    $r === null || $r->checks === 0 => 'none',
                    $r->failures === 0 => 'ok',
                    $r->failures >= $r->checks => 'crit',
                    default => 'warn',
                },
                'failures' => $r?->failures ?? 0,
                'checks' => $r?->checks ?? 0,
            ];
        }
        $day = $rows->filter(fn ($r) => strtotime((string) $r->bucket_at) >= $now->copy()->subDay()->getTimestamp());
        $share = fn (Collection $rs) => $rs->sum('checks') > 0 ? 1 - $rs->sum('failures') / $rs->sum('checks') : null;
        $good = $day->sum('checks') - $day->sum('failures');
        $outages = $site->outages()->where('started_at', '>=', $now->copy()->subDays(30))->latest('started_at')->get();

        return [
            'bars' => $bars,
            'day' => $share($day),
            'month' => $share($rows),
            'ms' => $good > 0 ? (int) round($day->sum('ms_total') / $good) : null,
            'day_minutes' => $outages->filter(fn (SiteOutage $o) => ($o->ended_at ?? $now) >= $now->copy()->subDay())->sum(fn (SiteOutage $o) => $o->minutes()),
            'month_minutes' => $outages->sum(fn (SiteOutage $o) => $o->minutes()),
            'month_count' => $outages->count(),
            'outages' => $outages->take(5),
            'url' => $this->url($site),
            'skipped' => $this->skipped($site),
        ];
    }

    /**
     * Forgets buckets and outages older than KEEP_DAYS.
     */
    public function prune(): int
    {
        $before = now()->subDays(self::KEEP_DAYS);

        return DB::table('site_uptime')->where('bucket_at', '<', $before)->delete()
            + SiteOutage::whereNotNull('ended_at')->where('ended_at', '<', $before)->delete();
    }
}
