<?php

namespace App\Support;

use Illuminate\Translation\Translator as BaseTranslator;

/**
 * Laravel's translator with three changes for the panel's languages:
 *
 * - placeholder values that are fixed labels (a role, a duration, a tab
 *   name from a constant) are translated like the sentence around them;
 * - a line with plural forms (":sites site|:sites sites") read through __()
 *   chooses its form by the first number among the placeholders, in the
 *   order they appear in the key, so "__(':sites site', [...])" needs no
 *   trans_choice() in Turkish, which has no plural;
 * - :Attribute capitalizes the Turkish way (işçi → İşçi).
 */
class Translator extends BaseTranslator
{
    public static function from(BaseTranslator $translator): self
    {
        $self = new self($translator->getLoader(), $translator->getLocale());
        $self->setFallback($translator->getFallback());

        return $self;
    }

    /**
     * @param  string  $key
     * @param  array<string, mixed>  $replace
     * @param  string|null  $locale
     * @param  bool  $fallback
     * @return string|array<mixed>
     */
    public function get($key, array $replace = [], $locale = null, $fallback = true)
    {
        if ($replace === [] || ! is_string($key)) {
            return parent::get($key, $replace, $locale, $fallback);
        }
        $line = parent::get($key, [], $locale, $fallback);
        if (! is_string($line)) {
            return parent::get($key, $replace, $locale, $fallback);
        }
        if (str_contains($line, '|')) {
            // Without a number the plural reads best ("sites").
            $line = $this->getSelector()->choose($line, self::countIn($key, $replace) ?? 2, $locale ?: $this->locale);
        }

        return $this->makeReplacements($line, $replace);
    }

    /**
     * The first numeric placeholder value, in the order the key uses them.
     *
     * @param  array<string, mixed>  $replace
     */
    public static function countIn(string $key, array $replace): ?int
    {
        $best = null;
        foreach ($replace as $name => $value) {
            $at = strpos($key, ':'.$name);
            // Formatted numbers count too: "1.234" is many, "1" is one.
            if ($at === false || ! (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^\d[\d.,]*$/', $value)))) {
                continue;
            }
            if ($best === null || $at < $best[0]) {
                $best = [$at, is_string($value) ? (int) preg_replace('/\D/', '', $value) : (int) $value];
            }
        }

        return $best[1] ?? null;
    }

    /**
     * @param  string  $line
     * @param  array<string, mixed>  $replace
     * @return string
     */
    protected function makeReplacements($line, array $replace)
    {
        foreach ($replace as $key => $value) {
            if (is_string($value)) {
                $replace[$key] = Locales::label($value);
            }
        }
        if ($this->locale !== 'tr') {
            return parent::makeReplacements($line, $replace);
        }
        foreach ($replace as $key => $value) {
            if (is_string($value) && str_contains($line, ':'.ucfirst($key)) && ($value[0] ?? '') === 'i') {
                $line = str_replace(':'.ucfirst($key), 'İ'.substr($value, 1), $line);
            }
        }

        return parent::makeReplacements($line, $replace);
    }
}
