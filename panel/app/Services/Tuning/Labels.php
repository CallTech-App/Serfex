<?php

namespace App\Services\Tuning;

use App\Support\Bytes;
use App\Support\Num;

/**
 * Values of optimization changes are stored in a form that reads in every
 * language ("mb:4096", "num:2000", "off", "on", "on:1", "text:ondemand")
 * and shown in the reader's.
 */
final class Labels
{
    public static function read(?string $raw): string
    {
        return match (true) {
            $raw === null => '',
            $raw === 'off' => __('kapalı'),
            $raw === 'on' => __('açık'),
            str_starts_with($raw, 'text:') => substr($raw, 5),
            (bool) preg_match('/^(mb|num|on):(\d+)$/', $raw, $m) => match ($m[1]) {
                'mb' => Bytes::format((int) $m[2] << 20),
                'num' => Num::format((int) $m[2]),
                default => __('açık, :seconds sn', ['seconds' => (int) $m[2]]),
            },
            default => $raw,
        };
    }
}
