<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Times for people, in the panel's time zone and the reader's language.
 * Lists use short(): "Bugün 23:38", "Dün 17:02", "14 Eki", "14 Eki 2025"
 * in Turkish; "Today 11:38 PM", "Yesterday 5:02 PM", "Oct 14",
 * "Oct 14, 2025" in English. Elsewhere: date(), time(), dateTime().
 */
final class When
{
    private const MONTHS = ['Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'];

    /** Formats per language: time, time with seconds, date, day and month. */
    private const FORMATS = [
        'tr' => ['time' => 'H:i', 'seconds' => 'H:i:s', 'date' => 'd.m.Y', 'day' => 'd.m'],
        'en' => ['time' => 'g:i A', 'seconds' => 'g:i:s A', 'date' => 'M j, Y', 'day' => 'M j'],
    ];

    public static function short(?CarbonInterface $at): string
    {
        if ($at === null) {
            return '—';
        }
        $at = self::local($at);
        $now = now(config('servik.timezone'));

        return match (true) {
            $at->isSameDay($now) => __('Bugün :time', ['time' => self::time($at)]),
            $at->isSameDay($now->copy()->subDay()) => __('Dün :time', ['time' => self::time($at)]),
            $at->year === $now->year => self::dayMonth($at),
            default => self::english() ? $at->format('M j, Y') : $at->day.' '.self::MONTHS[$at->month - 1].' '.$at->year,
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

        return str_contains($short, ':') ? $short : $short.' '.self::time($at);
    }

    /**
     * 14:05 / 2:05 PM.
     */
    public static function time(?CarbonInterface $at, bool $seconds = false): string
    {
        return $at === null ? '—' : self::local($at)->format(self::formats()[$seconds ? 'seconds' : 'time']);
    }

    /**
     * 20.10.2026 / Oct 20, 2026.
     */
    public static function date(?CarbonInterface $at): string
    {
        return $at === null ? '—' : self::local($at)->format(self::formats()['date']);
    }

    /**
     * 20.10.2026 14:05 / Oct 20, 2026 2:05 PM.
     */
    public static function dateTime(?CarbonInterface $at, bool $seconds = false): string
    {
        return $at === null ? '—' : self::date($at).' '.self::time($at, $seconds);
    }

    /**
     * 20.10 / Oct 20, for chart labels.
     */
    public static function day(?CarbonInterface $at): string
    {
        return $at === null ? '—' : self::local($at)->format(self::formats()['day']);
    }

    /**
     * 14 Eki / Oct 14.
     */
    public static function dayMonth(CarbonInterface $at): string
    {
        $at = self::local($at);

        return self::english() ? $at->format('M j') : $at->day.' '.self::MONTHS[$at->month - 1];
    }

    private static function local(CarbonInterface $at): CarbonInterface
    {
        return $at->copy()->timezone(config('servik.timezone'));
    }

    private static function english(): bool
    {
        return app()->getLocale() === 'en';
    }

    /**
     * @return array{time: string, seconds: string, date: string, day: string}
     */
    private static function formats(): array
    {
        return self::FORMATS[app()->getLocale()] ?? self::FORMATS['tr'];
    }
}
