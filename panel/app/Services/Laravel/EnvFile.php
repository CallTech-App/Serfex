<?php

namespace App\Services\Laravel;

/**
 * Reads and edits a .env file as text, keeping its comments and order, the
 * way phpdotenv reads it (without variable interpolation).
 */
final class EnvFile
{
    private const KEY = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /**
     * KEY => value, in file order.
     *
     * @return array<string, string>
     */
    public static function values(string $content): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $content) as $line) {
            $parsed = self::line($line);
            if ($parsed !== null) {
                $out[$parsed[0]] = self::unquote($parsed[1]);
            }
        }

        return $out;
    }

    /**
     * Writes $values into $content: changed keys are rewritten in place,
     * keys not in $values are removed and new keys are appended.
     *
     * @param  array<string, string>  $values
     */
    public static function apply(string $content, array $values): string
    {
        $lines = $content === '' ? [] : preg_split('/\r?\n/', rtrim($content, "\r\n"));
        $current = self::values($content);
        $out = [];
        $seen = [];
        foreach ($lines as $line) {
            $parsed = self::line($line);
            if ($parsed === null) {
                $out[] = $line;

                continue;
            }
            [$key] = $parsed;
            if (! array_key_exists($key, $values) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $values[$key] === ($current[$key] ?? null) ? $line : $key.'='.self::quote($values[$key]);
        }
        $new = array_diff_key($values, $seen);
        if ($new !== [] && $out !== [] && end($out) !== '') {
            $out[] = '';
        }
        foreach ($new as $key => $value) {
            $out[] = $key.'='.self::quote($value);
        }

        return $out === [] ? '' : implode("\n", $out)."\n";
    }

    public static function isValidKey(string $key): bool
    {
        return (bool) preg_match(self::KEY, $key);
    }

    /**
     * Values worth hiding on screen: keys, passwords, tokens and DSNs.
     */
    public static function isSecret(string $key): bool
    {
        return (bool) preg_match('/(KEY|SECRET|PASSWORD|PASS|TOKEN|DSN|PRIVATE|CREDENTIAL)/', strtoupper($key));
    }

    /**
     * Keys .env.example has that .env lacks, and the other way round.
     *
     * @return array{missing: list<string>, extra: list<string>}
     */
    public static function compare(string $env, ?string $example): array
    {
        if ($example === null) {
            return ['missing' => [], 'extra' => []];
        }
        $have = array_keys(self::values($env));
        $want = array_keys(self::values($example));

        return [
            'missing' => array_values(array_diff($want, $have)),
            'extra' => array_values(array_diff($have, $want)),
        ];
    }

    /**
     * A short description of what changed between two versions, for the
     * history list.
     */
    public static function summary(string $before, string $after): string
    {
        $old = self::values($before);
        $new = self::values($after);
        $parts = [];
        foreach (['eklendi' => array_diff_key($new, $old), 'silindi' => array_diff_key($old, $new)] as $verb => $keys) {
            if ($keys !== []) {
                $parts[] = self::names(array_keys($keys)).' '.$verb;
            }
        }
        $changed = array_keys(array_filter(array_intersect_key($new, $old), fn ($v, $k) => $old[$k] !== $v, ARRAY_FILTER_USE_BOTH));
        if ($changed !== []) {
            $parts[] = __(':changed değişti', ['changed' => self::names($changed)]);
        }

        return $parts === [] ? ($before === $after ? __('Değişiklik yok') : __('Yorumlar veya düzen değişti')) : implode(', ', $parts);
    }

    /**
     * @param  list<string>  $keys
     */
    private static function names(array $keys): string
    {
        return count($keys) > 3 ? __(':keys ve :keys2 değişken daha', ['keys' => implode(', ', array_slice($keys, 0, 3)), 'keys2' => (count($keys) - 3)]) : implode(', ', $keys);
    }

    /**
     * @return array{string, string}|null
     */
    private static function line(string $line): ?array
    {
        $line = trim($line);
        if (str_starts_with($line, 'export ')) {
            $line = ltrim(substr($line, 7));
        }
        if ($line === '' || $line[0] === '#' || ! str_contains($line, '=')) {
            return null;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);

        return self::isValidKey($key) ? [$key, trim($value)] : null;
    }

    public static function unquote(string $v): string
    {
        if (strlen($v) >= 2 && $v[0] === "'" && ($end = strpos($v, "'", 1)) !== false) {
            return substr($v, 1, $end - 1);
        }
        if (strlen($v) >= 2 && $v[0] === '"') {
            $out = '';
            for ($i = 1, $n = strlen($v); $i < $n; $i++) {
                if ($v[$i] === '\\' && $i + 1 < $n) {
                    $next = $v[++$i];
                    $out .= $next === 'n' ? "\n" : $next;
                } elseif ($v[$i] === '"') {
                    return $out;
                } else {
                    $out .= $v[$i];
                }
            }
        }
        if (($hash = strpos($v, ' #')) !== false) {
            $v = rtrim(substr($v, 0, $hash));
        }

        return $v;
    }

    public static function quote(string $v): string
    {
        if ($v === '' || preg_match('~^[A-Za-z0-9_./:@+,=-]+$~', $v)) {
            return $v;
        }
        if (! str_contains($v, "'") && ! str_contains($v, "\n")) {
            return "'".$v."'";
        }

        return '"'.strtr($v, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n', '$' => '\\$']).'"';
    }
}
