<?php

namespace App\Services\Monitor;

use App\Support\Badge;

/**
 * Turns a site's numbers into status badges, worst first. The thresholds
 * are deliberately simple; a site that crosses one is worth a look, not
 * necessarily in trouble.
 */
final class Health
{
    /** Percent of one CPU core. */
    public const CPU_WARN = 80;

    public const MEMORY_WARN = 2 * 1024 * 1024 * 1024;

    /** 5xx answers per second. */
    public const ERRORS_WARN = 1;

    /** Seconds a database query has been running. */
    public const SLOW_QUERY = 30;

    public const MAIL_WARN = 300;

    /**
     * The site's badges and a rank for sorting (0 is the worst).
     *
     * @param  array<string, mixed>  $u  a site from monitor.live
     * @return array{0: list<Badge>, 1: int}
     */
    public static function site(array $u, int $attackers, bool $limited): array
    {
        $badges = [];
        $rank = 9;
        $add = function (string $label, string $tone, int $r) use (&$badges, &$rank) {
            $badges[] = new Badge($label, $tone);
            $rank = min($rank, $r);
        };
        if ($attackers > 0) {
            $add('Saldırı', 'crit', 0);
        }
        if (($u['php']['max_reached'] ?? false) || ($u['php']['queue'] ?? 0) > 0) {
            $add('PHP havuzu dolu', 'crit', 1);
        }
        if (($u['server_errors'] ?? 0) >= self::ERRORS_WARN) {
            $add('5xx hataları', 'crit', 1);
        }
        if (($u['cpu'] ?? 0) >= self::CPU_WARN) {
            $add('Yüksek CPU', 'warn', 2);
        }
        if (($u['memory'] ?? 0) >= self::MEMORY_WARN) {
            $add('Yüksek bellek', 'warn', 2);
        }
        if (($u['db']['longest'] ?? 0) >= self::SLOW_QUERY) {
            $add('Yavaş sorgu', 'warn', 2);
        }
        if (($u['mail_per_hour'] ?? 0) >= self::MAIL_WARN) {
            $add('Yoğun posta', 'warn', 2);
        }
        // Only the worst problem and the limit, so the column stays short.
        $badges = array_slice($badges, 0, 1);
        if ($limited) {
            $badges[] = new Badge('Sınırlı', 'info');
            $rank = min($rank, 3);
        }
        if ($badges === []) {
            $badges[] = new Badge('Normal', 'ok');
        }

        return [$badges, $rank];
    }
}
