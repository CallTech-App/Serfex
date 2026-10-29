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
     * What a failed send in the log means on this server, in plain words;
     * null when the entry is not one. Postfix's own answers are short and
     * cryptic ("451 4.3.0 Error: internal error 512" when sendmail refuses
     * the sender).
     */
    public static function mailHint(string $message, string $detail = ''): ?string
    {
        $text = $message."\n".substr($detail, 0, 4000);

        return match (true) {
            str_contains($text, 'not authorized to use sender'),
            str_contains($text, '451 4.3.0 Error: internal error') => __('E-posta reddedildi: gönderen adresi bu sitenin alan adlarından biri değil. Bu sunucu her siteden yalnızca kendi alan adlarıyla gönderime izin verir; MAIL_FROM_ADDRESS değerini sitenin alan adıyla yazın.'),
            str_contains($text, 'Mail from this server may use') => __('E-posta reddedildi: uygulama bu sunucunun 25 numaralı portuna giriş yapmadan bağlanıyor. Bu yol barındırılan alan adlarına kapalı; MAIL_MAILER=sendmail kullanın ya da bir posta kutusuyla 587 numaralı porttan giriş yapın.'),
            (bool) preg_match('/Connection could not be established with host "?(127\.0\.0\.1|localhost|\[?::1\]?|mailpit|mailhog)[:"]|expected name `(127\.0\.0\.1|localhost)\'/', $text) => __('E-posta gönderilemedi: uygulama bu sunucuda giriş yapmadan SMTP ile göndermeye çalışıyor. MAIL_MAILER=sendmail kullanın.'),
            str_contains($text, 'proc_open()') && str_contains($text, 'Mailer') => __('E-posta gönderilemedi: sendmail için gereken proc_open bu sitenin PHP ayarlarında kapalı.'),
            default => null,
        };
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
