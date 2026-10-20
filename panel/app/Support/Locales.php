<?php

namespace App\Support;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * The panel's languages: which one a request, a person or a message is
 * in, and sentences with markup inside them.
 *
 * Turkish is the source language. Views and code call __() with the
 * Turkish text; lang/en.json holds the English. A person's own choice
 * wins, then the server's default; before signing in, the browser's
 * Accept-Language picks.
 */
final class Locales
{
    /**
     * code => name in its own language.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return (array) config('servik.locales');
    }

    public static function supports(?string $locale): bool
    {
        return $locale !== null && isset(self::all()[$locale]);
    }

    /**
     * The server's default language (Araçlar ve Ayarlar → Genel).
     */
    public static function serverDefault(): string
    {
        $locale = (string) config('servik.locale');

        return self::supports($locale) ? $locale : 'tr';
    }

    /**
     * The language a person reads the panel and their mail in.
     */
    public static function forUser(?User $user): string
    {
        return self::supports($user?->locale) ? $user->locale : self::serverDefault();
    }

    /**
     * The best of the panel's languages for the browser's Accept-Language,
     * or null when it asks for none of them.
     */
    public static function fromBrowser(Request $request): ?string
    {
        foreach ($request->getLanguages() as $language) {
            $code = strtolower(substr(str_replace('-', '_', $language), 0, 2));
            if (self::supports($code)) {
                return $code;
            }
        }

        return null;
    }

    /**
     * Switches this request, job or command to $locale, dates included.
     */
    public static function apply(string $locale): void
    {
        $locale = self::supports($locale) ? $locale : self::serverDefault();
        app()->setLocale($locale);
        Carbon::setLocale($locale);
    }

    /**
     * Runs $callback in $locale and switches back: for mail and other
     * text written for someone else.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function using(?string $locale, Closure $callback): mixed
    {
        $previous = app()->getLocale();
        self::apply($locale ?? self::serverDefault());
        try {
            return $callback();
        } finally {
            self::apply($previous);
        }
    }

    /**
     * The reader's word for a fixed label that is not wrapped in __() where
     * it is shown: Blade echoes ({{ }}) pass through here, so the labels in
     * constants, badges and the agent's lists (tabs, roles, service names)
     * come out in English too, and so do placeholder values (Translator,
     * html()). Only exact keys of lang/<locale>.json are
     * swapped; everything else is shown as it is.
     */
    public static function label(mixed $value): mixed
    {
        static $lines = [];
        if (! is_string($value) || $value === '') {
            return $value;
        }
        $locale = app()->getLocale();
        if ($locale === 'tr') {
            return $value;
        }
        $lines[$locale] ??= app('translator')->getLoader()->load($locale, '*', '*');

        return $lines[$locale][$value] ?? $value;
    }

    /**
     * A translated sentence with markup in its placeholders, for <x-t>:
     * the sentence and plain values are escaped, Htmlable values (slots)
     * are kept as they are. $text comes from an HTML attribute, so its
     * entities (&quot;) are decoded before it is looked up. With $count
     * the line is a plural (trans_choice).
     *
     * @param  array<string, mixed>  $values
     */
    public static function html(string $text, array $values = [], ?int $count = null): HtmlString
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
        $count ??= str_contains(__($text), '|') ? (Translator::countIn($text, $values) ?? 2) : null;
        $line = e($count === null ? __($text) : trans_choice($text, $count, ['count' => $count]));
        $replace = [];
        foreach ($values as $key => $value) {
            $replace[':'.$key] = $value instanceof Htmlable ? trim($value->toHtml()) : e((string) self::label($value));
        }
        // Longer names first, so :site does not eat :site_name.
        uksort($replace, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return new HtmlString(strtr($line, $replace));
    }
}
