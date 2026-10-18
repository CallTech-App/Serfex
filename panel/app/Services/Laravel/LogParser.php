<?php

namespace App\Services\Laravel;

/**
 * Splits a Laravel log into entries: "[date] env.LEVEL: message" lines,
 * each with the stack trace lines that follow it.
 */
final class LogParser
{
    /** Monolog levels, most severe first. */
    public const LEVELS = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    private const HEAD = '/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})(?:\.\d+)?(?:[+-]\d{2}:?\d{2})?\]\s+([\w-]+)\.([A-Z]+):\s?(.*)$/';

    /**
     * Newest entry first. A log that does not start with an entry (cut
     * mid-trace) loses its first partial lines.
     *
     * @return list<array{at: string, environment: string, level: string, message: string, detail: string}>
     */
    public static function parse(string $content): array
    {
        $entries = [];
        $current = null;
        foreach (preg_split('/\r?\n/', $content) as $line) {
            if (preg_match(self::HEAD, $line, $m)) {
                if ($current !== null) {
                    $entries[] = $current;
                }
                $current = ['at' => str_replace('T', ' ', $m[1]), 'environment' => $m[2], 'level' => strtolower($m[3]), 'message' => $m[4], 'detail' => ''];

                continue;
            }
            if ($current !== null && $line !== '') {
                $current['detail'] .= ($current['detail'] === '' ? '' : "\n").$line;
            }
        }
        if ($current !== null) {
            $entries[] = $current;
        }

        return array_reverse($entries);
    }

    /**
     * True when $level is $minimum or more severe.
     */
    public static function atLeast(string $level, string $minimum): bool
    {
        $rank = array_flip(self::LEVELS);

        return ($rank[$level] ?? 99) <= ($rank[$minimum] ?? 99);
    }

    /**
     * Status colour of a level.
     */
    public static function tone(string $level): string
    {
        return match (true) {
            self::atLeast($level, 'error') => 'crit',
            $level === 'warning' || $level === 'notice' => 'warn',
            $level === 'info' => 'info',
            default => 'muted',
        };
    }
}
