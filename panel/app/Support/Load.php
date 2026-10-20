<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Numbers of the live monitoring page in the reader's language: "%97",
 * "1.318", "0,4", "3,2 MB/sn", "18 dk kaldı" in Turkish; "97%", "1,318",
 * "0.4", "3.2 MB/s", "18 min left" in English.
 */
final class Load
{
    /** CPU in percent of one core. */
    public static function cpu(float $percent): string
    {
        return Num::percent($percent, $percent > 0 && $percent < 10 ? 1 : 0);
    }

    /** A rate such as requests per second. */
    public static function rate(float $value): string
    {
        if ($value <= 0) {
            return '0';
        }

        return self::number($value, $value < 10 ? 1 : 0);
    }

    /** Bytes per second; a dash when idle. */
    public static function io(float $bytes): string
    {
        return $bytes < 1024 ? '—' : __(':size/sn', ['size' => Bytes::format((int) $bytes)]);
    }

    public static function number(float $value, int $decimals = 0): string
    {
        $s = Num::format($value, $decimals);

        return $decimals > 0 ? rtrim(rtrim($s, '0'), Num::format(0.5, 1)[1]) : $s;
    }

    /** Time left until $until: "18 dk kaldı", "2 sa 5 dk kaldı", "3 gün kaldı". */
    public static function remaining(?CarbonInterface $until): string
    {
        if ($until === null) {
            return __('Kalıcı');
        }
        $min = max(1, (int) ceil(now()->diffInSeconds($until, false) / 60));

        return match (true) {
            $min < 60 => __(':m dk kaldı', ['m' => $min]),
            $min < 1440 && $min % 60 > 0 => __(':h sa :m dk kaldı', ['h' => intdiv($min, 60), 'm' => $min % 60]),
            $min < 1440 => __(':h sa kaldı', ['h' => intdiv($min, 60)]),
            default => __(':d gün kaldı', ['d' => intdiv($min, 1440)]),
        };
    }

    /** Uptime: "1 gün 12 sa", "5 sa 3 dk". */
    public static function uptime(int $seconds): string
    {
        $d = intdiv($seconds, 86400);
        $h = intdiv($seconds % 86400, 3600);
        $m = intdiv($seconds % 3600, 60);

        return $d > 0 ? __(':d gün :h sa', ['d' => $d, 'h' => $h]) : __(':h sa :m dk', ['h' => $h, 'm' => $m]);
    }
}
