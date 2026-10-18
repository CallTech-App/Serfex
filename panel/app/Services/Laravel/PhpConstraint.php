<?php

namespace App\Services\Laravel;

/**
 * Checks a PHP version against a Composer constraint such as "^8.3",
 * ">=8.2 <8.5" or "^8.2|^8.3", which is what composer.json asks for.
 */
final class PhpConstraint
{
    /**
     * True when $version (like 8.4) satisfies $constraint; null when the
     * constraint cannot be read, so the caller shows no verdict.
     */
    public static function allows(string $constraint, string $version): ?bool
    {
        $constraint = trim($constraint);
        if ($constraint === '' || $constraint === '*') {
            return true;
        }
        $v = self::parse($version);
        if ($v === null) {
            return null;
        }
        $any = false;
        foreach (preg_split('/\s*\|\|?\s*/', $constraint) as $alternative) {
            $all = true;
            foreach (preg_split('/\s*,\s*|\s+/', trim($alternative)) as $part) {
                $ok = self::matches($part, $v);
                if ($ok === null) {
                    return null;
                }
                $all = $all && $ok;
            }
            $any = $any || $all;
        }

        return $any;
    }

    /**
     * @param  array{int, int, int}  $v
     */
    private static function matches(string $part, array $v): ?bool
    {
        if ($part === '' || $part === '*') {
            return true;
        }
        if (! preg_match('/^(\^|~|>=|<=|>|<|==|=|!=)?\s*v?(\d+(?:\.(?:\d+|\*|x))*)$/i', $part, $m)) {
            return null;
        }
        $op = $m[1] ?? '';
        $raw = $m[2];
        $min = self::parse(str_replace(['*', 'x', 'X'], '0', $raw));
        if ($min === null) {
            return null;
        }
        $digits = count(explode('.', $raw));
        $wildcard = str_contains($raw, '*') || stripos($raw, 'x') !== false;

        return match (true) {
            $op === '^' => self::cmp($v, $min) >= 0 && $v[0] === $min[0],
            $op === '~' => self::cmp($v, $min) >= 0 && ($digits <= 2 ? $v[0] === $min[0] : $v[0] === $min[0] && $v[1] === $min[1]),
            $op === '>=' => self::cmp($v, $min) >= 0,
            $op === '>' => self::cmp($v, $min) > 0,
            $op === '<=' => self::cmp($v, $min) <= 0,
            $op === '<' => self::cmp($v, $min) < 0,
            $op === '!=' => self::cmp($v, $min) !== 0,
            // "8.3" or "8.3.*" means that minor line.
            default => $digits <= 2 || $wildcard
                ? array_slice($v, 0, $digits - ($wildcard ? 1 : 0)) === array_slice($min, 0, $digits - ($wildcard ? 1 : 0))
                : self::cmp($v, $min) === 0,
        };
    }

    /**
     * @return array{int, int, int}|null
     */
    private static function parse(string $version): ?array
    {
        if (! preg_match('/^(\d+)(?:\.(\d+))?(?:\.(\d+))?/', $version, $m)) {
            return null;
        }

        return [(int) $m[1], (int) ($m[2] ?? 0), (int) ($m[3] ?? 0)];
    }

    /**
     * @param  array{int, int, int}  $a
     * @param  array{int, int, int}  $b
     */
    private static function cmp(array $a, array $b): int
    {
        return $a <=> $b;
    }
}
