<?php

namespace App\Services\Ai;

/**
 * The Claude models Serfex AI offers and their list prices in US dollars
 * per million tokens, used to estimate what the assistant costs.
 */
final class Models
{
    public const DEFAULT = 'claude-opus-5-5';

    /** id => [label, note, input, output, cache read, cache write] */
    public const ALL = [
        'claude-opus-5-5' => ['Claude Opus 5.5', 'En yetenekli; zor sorunlar için önerilir', 4.00, 20.00, 0.20, 5.00],
        'claude-sonnet-5-5' => ['Claude Sonnet 5.5', 'Hızlı ve daha ucuz', 2.00, 10.00, 0.20, 2.50],
        'claude-haiku-5-5' => ['Claude Haiku 5.5', 'En hızlı ve en ucuz; basit sorular için', 0.10, 0.50, 0.01, 0.125],
    ];

    public static function exists(string $id): bool
    {
        return isset(self::ALL[$id]);
    }

    public static function label(string $id): string
    {
        return self::ALL[$id][0] ?? $id;
    }

    /**
     * Whether a request on the model may fall back to another model when
     * Claude's safeguards decline it (the API's "default" fallbacks).
     */
    public static function hasFallbacks(string $id): bool
    {
        return in_array($id, ['claude-opus-5-5', 'claude-sonnet-5-5'], true);
    }

    /**
     * Estimated cost of a request in dollars.
     */
    public static function cost(string $id, int $input, int $output, int $cacheRead = 0, int $cacheWrite = 0): float
    {
        [, , $in, $out, $read, $write] = self::ALL[$id] ?? self::ALL[self::DEFAULT];

        return ($input * $in + $output * $out + $cacheRead * $read + $cacheWrite * $write) / 1_000_000;
    }
}
