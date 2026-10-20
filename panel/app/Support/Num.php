<?php

namespace App\Support;

/**
 * Numbers for people in the reader's language: 1.234,5 in Turkish,
 * 1,234.5 in English.
 */
final class Num
{
    public static function format(int|float $number, int $decimals = 0): string
    {
        [$point, $thousands] = app()->getLocale() === 'en' ? ['.', ','] : [',', '.'];

        return number_format($number, $decimals, $point, $thousands);
    }

    /**
     * A share as a percentage: %85 in Turkish, 85% in English.
     */
    public static function percent(int|float $share, int $decimals = 0): string
    {
        $number = self::format($share, $decimals);

        return app()->getLocale() === 'en' ? $number.'%' : '%'.$number;
    }
}
