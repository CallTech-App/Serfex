<?php

namespace App\Support;

/**
 * Sizes for people: "820 MB", "1,2 GB" (Turkish decimal comma).
 */
final class Bytes
{
    public static function format(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) max(0, $bytes);
        $i = 0;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }
        $decimals = $i >= 3 && $value < 100 && round($value, 1) !== round($value) ? 1 : 0;

        return number_format($value, $decimals, ',', '.').' '.$units[$i];
    }
}
