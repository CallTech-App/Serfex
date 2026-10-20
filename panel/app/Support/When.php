<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Short Turkish times for lists: "Bugün 23:38", "Dün 17:02", "14 Eki",
 * "14 Eki 2025".
 */
final class When
{
    private const MONTHS = ['Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'];

    public static function short(?CarbonInterface $at): string
    {
        if ($at === null) {
            return '—';
        }
        $at = $at->copy()->timezone(config('servik.timezone'));
        $now = now(config('servik.timezone'));

        return match (true) {
            $at->isSameDay($now) => 'Bugün '.$at->format('H:i'),
            $at->isSameDay($now->copy()->subDay()) => 'Dün '.$at->format('H:i'),
            $at->year === $now->year => $at->day.' '.self::MONTHS[$at->month - 1],
            default => $at->day.' '.self::MONTHS[$at->month - 1].' '.$at->year,
        };
    }

    /**
     * Like short(), with the time also on other days: "14 Eki 22:30".
     */
    public static function withTime(?CarbonInterface $at): string
    {
        if ($at === null) {
            return '—';
        }
        $short = self::short($at);

        return str_contains($short, ':') ? $short : $short.' '.$at->copy()->timezone(config('servik.timezone'))->format('H:i');
    }
}
